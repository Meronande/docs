<?php
/**
 * Supabase management endpoints (System Settings → Cloud Backup card).
 * Permission-guarded: settings.manage for all actions.
 *
 * Actions (POST, AJAX):
 *   test     — validate the configured keys against the Supabase API
 *   backup   — dump the MySQL database (pure PHP) and upload it to Storage
 *   list     — list existing backups in the Storage bucket
 *
 * All-string mysqli binds only (this build mis-binds NULL-int followed by d/s).
 */

declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/supabase.php';

if (!is_ajax()) {
    http_response_code(400);
    die('AJAX only');
}

require_permission('settings.manage');

$action = post('action');
if (!in_array($action, ['test', 'backup', 'list'], true)) {
    json_response(['ok' => false, 'message' => 'Unknown action.']);
}

// ---------------------------------------------------------------------------
// Test connection
// ---------------------------------------------------------------------------
if ($action === 'test') {
    $h = supabase_health();
    audit_log('test', 'Supabase', null, $h['ok'] ? 'Supabase connection test succeeded' : ('Supabase connection test failed: ' . $h['message']));
    json_response($h);
}

// ---------------------------------------------------------------------------
// List backups
// ---------------------------------------------------------------------------
if ($action === 'list') {
    if (!supabase_enabled()) {
        json_response(['ok' => false, 'message' => supabase_health()['message']]);
    }
    $r = supabase_storage_list(supabase_backup_bucket());
    if (empty($r['ok'])) {
        // Bucket may not exist yet — that is fine, it just has no backups.
        json_response(['ok' => false, 'message' => $r['message'], 'files' => []]);
    }
    json_response(['ok' => true, 'files' => $r['files']]);
}

// ---------------------------------------------------------------------------
// Backup: dump + upload
// ---------------------------------------------------------------------------
if ($action === 'backup') {
    if (getenv('SUPABASE_BACKUP_DISABLED') === '1') {
        json_response(['ok' => false, 'message' => 'Backups are disabled on this server (SUPABASE_BACKUP_DISABLED=1).']);
    }

    $dump = supabase_dump_database();
    if ($dump['ok'] !== true) {
        json_response(['ok' => false, 'message' => $dump['message']]);
    }

    $ensure = supabase_storage_ensure_bucket(supabase_backup_bucket());
    if (empty($ensure['ok'])) {
        json_response(['ok' => false, 'message' => 'Could not access the backup bucket: ' . $ensure['message']]);
    }

    $objectPath = 'backups/' . date('Y/m') . '/' . $dump['filename'];
    $up = supabase_storage_upload(supabase_backup_bucket(), $objectPath, $dump['bytes']);
    if (empty($up['ok'])) {
        json_response(['ok' => false, 'message' => 'Upload failed: ' . $up['message']]);
    }

    $sizeMb = strlen($dump['bytes']) / 1048576;
    audit_log('backup', 'Supabase', null, 'Database backup uploaded to Supabase Storage: ' . $up['object'] . ' (' . number_format($sizeMb, 2) . ' MB)');
    notify(
        ['permission_key' => 'settings.manage', 'branch_id' => null],
        'Offsite backup uploaded',
        'A database backup (' . number_format($sizeMb, 2) . ' MB) was uploaded to Supabase as ' . $up['object'] . '.',
        'success',
        'supabase',
        null,
        '/settings'
    );
    json_response([
        'ok' => true,
        'message' => 'Backup uploaded to Supabase Storage.',
        'object' => $up['object'],
        'size_mb' => round($sizeMb, 2),
        'tables' => $dump['tables'],
        'rows' => $dump['rows'],
    ]);
}

// ---------------------------------------------------------------------------
// Pure-PHP SQL dump of the MySQL database (no shell / mysqldump needed).
// ---------------------------------------------------------------------------
function supabase_dump_database(): array
{
    // Core operational tables, topological order (FK-safe restore order).
    $tables = [
        'branches', 'departments', 'specializations', 'roles', 'permissions',
        'settings', 'users', 'role_permissions', 'patients',
        'doctors', 'staff', 'services', 'insurance_providers',
        'appointments', 'queue', 'medical_records', 'diagnoses', 'prescriptions',
        'prescription_items', 'lab_orders', 'lab_results',
        'medicines', 'medicine_batches', 'pharmacy_sales', 'pharmacy_sale_items',
        'invoices', 'invoice_items', 'payments', 'insurance_claims',
        'expenses', 'expense_categories', 'payroll_periods', 'payroll_items',
        'opd_sends', 'notifications', 'notification_reads', 'audit_logs',
    ];
    $res = db_query("SHOW TABLES");
    $existing = [];
    foreach ($res as $row) {
        $existing[] = array_values($row)[0];
    }
    $tables = array_values(array_intersect($tables, $existing));
    if (!$tables) {
        return ['ok' => false, 'message' => 'No known tables were found in the database.'];
    }

    $out = "-- Clinic Management System — SQL backup\n";
    $out .= "-- Generated: " . date('c') . "\n";
    $out .= "-- Database: " . DB_NAME . " (MySQL/MariaDB dump via PHP)\n";
    $out .= "-- Restore on a MySQL/MariaDB server; Supabase stores it as an object.\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $totalRows = 0;
    foreach ($tables as $t) {
        $t = str_replace('`', '', (string) $t); // identifier safety
        $create = db_fetch_value("SHOW CREATE TABLE `$t`");
        if ($create === null) {
            continue;
        }
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
        $rows = 0;
        $res = db_query("SELECT * FROM `$t`");
        if ($res instanceof mysqli_result) {
            $batch = [];
            while ($r = $res->fetch_assoc()) {
                $rows++;
                $vals = [];
                foreach ($r as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = "'" . db()->real_escape_string((string) $v) . "'";
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 100) {
                    $out .= "INSERT INTO `$t` VALUES\n  " . implode(",\n  ", $batch) . ";\n";
                    $batch = [];
                }
                $totalRows++;
            }
            if ($batch) {
                $out .= "INSERT INTO `$t` VALUES\n  " . implode(",\n  ", $batch) . ";\n";
            }
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    return [
        'ok' => true,
        'bytes' => $out,
        'filename' => 'clinic_backup_' . date('Y-m-d_His') . '.sql',
        'tables' => count($tables),
        'rows' => $totalRows,
    ];
}
