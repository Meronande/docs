<?php
/**
 * GET /ajax/lookup?type=...&parent=...&q=...
 * Powers cascade dropdowns and searchable selects.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
}

$type = get('type');
$parent = get('parent', '');
$q = get('q', '');
$branchId = scope_branch_id();
$like = '%' . $q . '%';
$items = [];

switch ($type) {
    case 'departments':
        $sql = "SELECT d.id, d.department_name AS name FROM departments d WHERE d.status = 1";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND (d.branch_id = ? OR d.branch_id IS NULL)";
            $params[] = $branchId;
        }
        if ($parent !== '') {
            $sql .= " AND (d.branch_id = ? OR d.branch_id IS NULL)";
            $params[] = (int) $parent;
        }
        if ($q !== '') { $sql .= " AND d.department_name LIKE ?"; $params[] = $like; }
        $sql .= " ORDER BY d.department_name";
        $items = db_fetch_all($sql, $params);
        break;

    case 'doctors':
        $sql = "SELECT doc.id, doc.full_name AS name, doc.consultation_fee, doc.specialization_id
                FROM doctors doc WHERE doc.status = 1";
        $params = [];
        if ($branchId !== null) { $sql .= " AND doc.branch_id = ?"; $params[] = $branchId; }
        if ($parent !== '') { $sql .= " AND EXISTS (SELECT 1 FROM doctor_departments dd WHERE dd.doctor_id = doc.id AND dd.department_id = ?)"; $params[] = (int) $parent; }
        if ($q !== '') { $sql .= " AND doc.full_name LIKE ?"; $params[] = $like; }
        $sql .= " ORDER BY doc.full_name LIMIT 50";
        $rows = db_fetch_all($sql, $params);
        $items = array_map(function ($r) { return ['id' => $r['id'], 'name' => $r['name'] . ' (' . money($r['consultation_fee']) . ')']; }, $rows);
        break;

    case 'services':
        $sql = "SELECT s.id, CONCAT(s.service_name, ' — ', s.price) AS name
                FROM services s WHERE s.status = 1";
        $params = [];
        if ($parent !== '') { $sql .= " AND s.department_id = ?"; $params[] = (int) $parent; }
        if ($q !== '') { $sql .= " AND s.service_name LIKE ?"; $params[] = $like; }
        $sql .= " ORDER BY s.service_name LIMIT 50";
        $items = db_fetch_all($sql, $params);
        break;

    case 'patients':
        $sql = "SELECT p.id, CONCAT(p.patient_code, ' — ', p.full_name, IF(p.phone IS NOT NULL AND p.phone <> '', CONCAT(' (', p.phone, ')'), '')) AS name
                FROM patients p WHERE p.status = 1";
        $params = [];
        if ($branchId !== null) { $sql .= " AND p.branch_id = ?"; $params[] = $branchId; }
        if ($q !== '') { $sql .= " AND (p.full_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ?)"; $params[] = $like; $params[] = $like; $params[] = $like; }
        $sql .= " ORDER BY p.full_name LIMIT 50";
        $items = db_fetch_all($sql, $params);
        break;

    case 'medicines':
        $sql = "SELECT m.id, CONCAT(m.medicine_name, ' — stock: ', m.stock_quantity, ' ', COALESCE(m.unit,'')) AS name,
                       m.selling_price, m.stock_quantity, m.minimum_stock, m.expiry_date
                FROM medicines m WHERE m.status = 1";
        $params = [];
        if ($branchId !== null) { $sql .= " AND m.branch_id = ?"; $params[] = $branchId; }
        if ($q !== '') { $sql .= " AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.medicine_code LIKE ?)"; $params[] = $like; $params[] = $like; $params[] = $like; }
        $sql .= " ORDER BY m.medicine_name LIMIT 50";
        $items = db_fetch_all($sql, $params);
        break;

    case 'tests':
        $items = db_fetch_all(
            "SELECT id, CONCAT(test_code, ' — ', test_name, ' (', price, ')') AS name
             FROM laboratory_tests WHERE status = 1" . ($q !== '' ? " AND (test_name LIKE ? OR test_code LIKE ?)" : "") . "
             ORDER BY test_name LIMIT 50",
            $q !== '' ? [$like, $like] : []
        );
        break;

    case 'diagnoses':
        $items = db_fetch_all(
            "SELECT id, CONCAT(diagnosis_code, ' — ', diagnosis_name) AS name
             FROM diagnoses WHERE status = 1" . ($q !== '' ? " AND diagnosis_name LIKE ?" : "") . "
             ORDER BY diagnosis_name LIMIT 50",
            $q !== '' ? [$like] : []
        );
        break;

    case 'branches':
        $items = db_fetch_all("SELECT id, branch_name AS name FROM branches WHERE status = 1 ORDER BY branch_name");
        break;

    case 'appointment_types':
        $items = db_fetch_all("SELECT id, type_name AS name FROM appointment_types WHERE status = 1 ORDER BY type_name");
        break;

    case 'payment_methods':
        $items = db_fetch_all("SELECT id, method_name AS name FROM payment_methods WHERE status = 1 ORDER BY id");
        break;

    case 'insurance':
        $items = db_fetch_all("SELECT id, company_name AS name FROM insurance_companies WHERE status = 1 ORDER BY company_name");
        break;

    default:
        json_response(['ok' => false, 'message' => 'Unknown lookup type.'], 400);
}

json_response(['ok' => true, 'items' => $items]);
