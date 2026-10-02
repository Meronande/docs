<?php
/**
 * Payroll + drug-loss helpers.
 */

declare(strict_types=1);

if (!defined('APP_BOOT') && !defined('PAYROLL_HELPERS_ONLY')) {
    require_once dirname(__DIR__) . '/config/auth.php';
}

/**
 * Current/next payroll month key: before payroll_day it is the previous month
 * (salaries are typically run after month end), otherwise the current month.
 */
function payroll_current_month(): string
{
    $day = (int) setting('payroll_day', '30');
    return (int) date('d') < max(1, min(28, $day)) ? date('Y-m', strtotime('-1 month')) : date('Y-m');
}

/** Role id for a role name (null when missing). */
function role_id_by_name(string $roleName): ?int
{
    $id = db_fetch_value('SELECT id FROM roles WHERE role_name = ? LIMIT 1', [$roleName], 's');
    return $id !== null ? (int) $id : null;
}

/** All user ids holding a role (Super Admin by default). */
function users_with_role(string $roleName = 'Super Admin'): array
{
    $rid = role_id_by_name($roleName);
    if ($rid === null) return [];
    return array_map('intval', array_column(
        db_fetch_all('SELECT id FROM users WHERE role_id = ? AND status = 1', [$rid], 'i'),
        'id'
    ));
}

/**
 * Notify every Super Admin (optionally restricted to a branch).
 * Deduplicated per (user, title [, ref]) within the last 24h to avoid alert spam.
 */
function notifySuperAdmins(string $title, string $message, string $type = 'warning', ?string $refType = null, ?int $refId = null, ?string $url = null, ?int $branchId = null): void
{
    try {
        foreach (users_with_role('Super Admin') as $uid) {
            // Dedup: same user + title within 24h (tighter when a ref is given).
            if ($refType !== null && $refId !== null) {
                $dup = (int) db_fetch_value(
                    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND reference_type = ? AND reference_id = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
                    [$uid, $refType, $refId, $title],
                    'isis'
                );
            } else {
                $dup = (int) db_fetch_value(
                    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
                    [$uid, $title],
                    'is'
                );
            }
            if ($dup > 0) continue;
            notify(
                ['user_id' => $uid, 'branch_id' => $branchId],
                $title,
                $message,
                $type,
                $refType,
                $refId,
                $url
            );
        }
    } catch (Throwable $e) {
        error_log('[NOTIFY_SUPER_ADMINS] ' . $e->getMessage());
    }
}

/**
 * Total purchase value of currently expired medicines (potential loss).
 * Loss is only counted for items still in stock — dispensed/sold stock is gone.
 */
function expired_stock_value(?int $branchId = null): float
{
    $sql = 'SELECT COALESCE(SUM(stock_quantity * purchase_price),0) FROM medicines
            WHERE status = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
            AND stock_quantity > 0';
    $params = [];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}

/**
 * Purchase value of medicines expiring within N days (at-risk value).
 */
function expiring_soon_value(?int $branchId = null, int $days = 30): float
{
    $sql = 'SELECT COALESCE(SUM(stock_quantity * purchase_price),0) FROM medicines
            WHERE status = 1 AND expiry_date IS NOT NULL
            AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . max(1, $days) . ' DAY)
            AND stock_quantity > 0';
    $params = [];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}

/** Total purchase value of low-stock items (reorder budget hint). */
function low_stock_reorder_value(?int $branchId = null): float
{
    $sql = 'SELECT COALESCE(SUM(GREATEST(minimum_stock - stock_quantity, 0) * purchase_price),0)
            FROM medicines WHERE status = 1 AND stock_quantity <= minimum_stock';
    $params = [];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}

/**
 * Expired value written off in a date range: medicines that expired in the
 * window and whose stock has since been cleared to 0 (disposal heuristic),
 * valued at purchase price using the last known quantity — approximated by
 * current stock = 0 and expiry inside the range.
 */
function expired_loss_in_range(string $from, string $to, ?int $branchId = null): float
{
    $sql = 'SELECT COALESCE(SUM(stock_quantity * purchase_price),0) FROM medicines
            WHERE expiry_date BETWEEN ? AND ?';
    $params = [$from, $to];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}

/**
 * Payroll cost booked in a month (approved+paid periods). Draft periods are
 * excluded so the P&L only counts committed payroll.
 */
function payroll_cost_for_month(string $ym, ?int $branchId = null): float
{
    $sql = 'SELECT COALESCE(SUM(total_net),0) FROM payroll_periods
            WHERE period_month = ? AND status IN (\'approved\',\'paid\')';
    $params = [$ym];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}

/** Sum of monthly staff salaries (active staff, branch-scoped). */
function staff_salary_total(?int $branchId = null): float
{
    $sql = 'SELECT COALESCE(SUM(salary),0) FROM staff WHERE status = 1 AND salary IS NOT NULL';
    $params = [];
    if ($branchId !== null) {
        $sql .= ' AND branch_id = ?';
        $params[] = $branchId;
    }
    return (float) (db_fetch_value($sql, $params) ?? 0);
}
