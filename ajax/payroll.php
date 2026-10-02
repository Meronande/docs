<?php
/**
 * Payroll AJAX engine (Super Admin).
 *
 * GET  /ajax/payroll?id=<period>            -> period detail rows (JSON)
 * POST /ajax/payroll  action=create         { period_month, branch_id?, notes? }
 * POST /ajax/payroll  action=save_items     { period_id, item[<staffId>][bonus|overtime|advance_deduction|other_deduction], notes? }
 * POST /ajax/payroll  action=approve        { period_id }
 * POST /ajax/payroll  action=mark_paid      { period_id }
 * POST /ajax/payroll  action=delete         { period_id }
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/payroll.php';

if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
}
if (!has_permission('payroll.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!has_permission('payroll.manage')) {
        json_response(['ok' => false, 'message' => 'You do not have permission to manage payroll.'], 403);
    }
}

/** Recompute period totals from its items. */
function payroll_recalc(int $periodId): array
{
    $agg = db_fetch_one(
        'SELECT COALESCE(SUM(basic_salary + bonus + overtime),0) gross,
                COALESCE(SUM(advance_deduction + other_deduction),0) ded,
                COALESCE(SUM(net_pay),0) net
         FROM payroll_items WHERE period_id = ?',
        [$periodId],
        'i'
    ) ?? ['gross' => 0, 'ded' => 0, 'net' => 0];
    db_execute(
        'UPDATE payroll_periods SET total_gross = ?, total_deductions = ?, total_net = ? WHERE id = ?',
        [(float) $agg['gross'], (float) $agg['ded'], (float) $agg['net'], $periodId]
    );
    return array_map('floatval', $agg);
}

/** Effective branch filter for creating a period (null = all branches). */
function payroll_scope(): ?int
{
    return sees_all_branches() ? null : (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null);
}

// ---------------------------------------------------------------------
// GET: period detail for editing
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) get('id', '0');
    $period = db_fetch_one(
        'SELECT pp.*, b.branch_name FROM payroll_periods pp LEFT JOIN branches b ON b.id = pp.branch_id WHERE pp.id = ?',
        [$id],
        'i'
    );
    if (!$period) {
        json_response(['ok' => false, 'message' => 'Payroll period not found.'], 404);
    }
    $items = db_fetch_all(
        'SELECT pi.*, s.full_name, s.staff_code, s.position
         FROM payroll_items pi JOIN staff s ON s.id = pi.staff_id
         WHERE pi.period_id = ? ORDER BY s.full_name',
        [$id],
        'i'
    );
    ob_start();
    echo '<div class="small text-muted mb-2">Period <strong>' . e($period['period_month']) . '</strong>'
        . ($period['branch_name'] ? ' · ' . e($period['branch_name']) : ' · All branches')
        . ' · Status: <span class="badge text-bg-' . ($period['status'] === 'draft' ? 'secondary' : ($period['status'] === 'approved' ? 'primary' : 'success')) . '">'
        . label_case($period['status']) . '</span></div>';
    echo '<form id="crudForm" data-url="/ajax/payroll">';
    echo '<input type="hidden" name="action" value="save_items">';
    echo '<input type="hidden" name="period_id" value="' . (int) $period['id'] . '">';
    echo '<div class="table-responsive" style="max-height:420px;overflow-y:auto"><table class="table table-sm align-middle">'
        . '<thead class="table-light"><tr><th>Employee</th><th>Basic</th><th>Bonus</th><th>Overtime</th><th>Advance Ded.</th><th>Other Ded.</th><th class="text-end">Net Pay</th></tr></thead><tbody>';
    $readOnly = $period['status'] !== 'draft';
    foreach ($items as $it) {
        $net = (float) $it['basic_salary'] + (float) $it['bonus'] + (float) $it['overtime'] - (float) $it['advance_deduction'] - (float) $it['other_deduction'];
        echo '<tr>';
        echo '<td><div class="fw-semibold small">' . e($it['full_name']) . '</div><div class="text-muted" style="font-size:.72rem">' . e($it['staff_code'] . ($it['position'] ? ' · ' . $it['position'] : '')) . '</div></td>';
        echo '<td class="small">' . money($it['basic_salary']) . '</td>';
        foreach (['bonus', 'overtime', 'advance_deduction', 'other_deduction'] as $f) {
            if ($readOnly) {
                echo '<td class="small">' . money($it[$f]) . '</td>';
            } else {
                echo '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm py-1 payroll-in" style="width:92px" '
                    . 'data-staff="' . (int) $it['staff_id'] . '" data-field="' . $f . '" data-basic="' . e(money_raw($it['basic_salary'])) . '" '
                    . 'name="item[' . (int) $it['staff_id'] . '][' . $f . ']" value="' . e(money_raw($it[$f])) . '"></td>';
            }
        }
        echo '<td class="text-end fw-semibold small payroll-net" data-staff="' . (int) $it['staff_id'] . '">' . money($net) . '</td>';
        echo '</tr>';
    }
    if (!$items) {
        echo '<tr><td colspan="7" class="text-center text-muted py-3">No employees in this period.</td></tr>';
    }
    echo '</tbody></table></div>';
    if (!$readOnly) {
        echo '<div class="alert alert-info small py-2 mt-2 mb-0"><i class="fa-solid fa-circle-info me-1"></i>'
            . 'Net pay = Basic + Bonus + Overtime − Advance − Other deductions. Adjust the cells, then Save.</div>';
    }
    echo '</form>';
    json_response(['ok' => true, 'html' => ob_get_clean(), 'status' => $period['status']]);
}

// ---------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------
$action = post('action', '');

if ($action === 'create') {
    $ym = post('period_month', payroll_current_month());
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        json_response(['ok' => false, 'message' => 'Invalid period (use YYYY-MM).']);
    }
    $branchId = sees_all_branches() && post('branch_id') !== '' ? (int) post('branch_id') : payroll_scope();
    $notes = mb_substr(post('notes'), 0, 255);

    // MariaDB prepared statements don't support "IS ?" — branch on null instead.
    if ($branchId !== null) {
        $dup = db_fetch_value('SELECT id FROM payroll_periods WHERE period_month = ? AND branch_id = ?', [$ym, $branchId], 'si');
    } else {
        $dup = db_fetch_value('SELECT id FROM payroll_periods WHERE period_month = ? AND branch_id IS NULL', [$ym], 's');
    }
    if ($dup !== null) {
        json_response(['ok' => false, 'message' => 'A payroll for ' . $ym . ' already exists' . ($branchId ? ' for this branch' : '') . '. Open it instead.']);
    }

    $staff = db_fetch_all(
        'SELECT id, COALESCE(salary, 0) salary FROM staff WHERE status = 1 AND salary IS NOT NULL AND salary > 0'
        . ($branchId !== null ? ' AND branch_id = ?' : ''),
        $branchId !== null ? [$branchId] : []
    );
    if (!$staff) {
        json_response(['ok' => false, 'message' => 'No active staff with a salary found' . ($branchId ? ' in this branch' : '') . '.']);
    }

    $periodId = db_transaction(function () use ($ym, $branchId, $notes, $staff) {
        $pid = db_execute(
            'INSERT INTO payroll_periods (period_month, branch_id, notes, created_by) VALUES (?, ?, ?, ?)',
            [$ym, $branchId, $notes !== '' ? $notes : null, (int) current_user()['id']],
            'ssii'
        );
        foreach ($staff as $s) {
            $basic = (float) $s['salary'];
            db_execute(
                'INSERT INTO payroll_items (period_id, staff_id, basic_salary, net_pay) VALUES (?, ?, ?, ?)',
                [$pid, (int) $s['id'], $basic, $basic]
            );
        }
        payroll_recalc((int) $pid);
        return (int) $pid;
    });

    audit_log('create', 'Payroll', $periodId, "Created payroll $ym (" . count($staff) . ' employees)');
    notifySuperAdmins(
        'Payroll created',
        'Payroll for ' . $ym . ' was created with ' . count($staff) . ' employees (draft).',
        'info',
        'payroll',
        $periodId,
        '/payroll/view/' . $periodId
    );
    json_response(['ok' => true, 'message' => 'Payroll ' . $ym . ' created with ' . count($staff) . ' employees.', 'id' => $periodId, 'reload' => true]);
}

if ($action === 'save_items') {
    $periodId = (int) post('period_id', '0');
    $period = db_fetch_one('SELECT * FROM payroll_periods WHERE id = ?', [$periodId], 'i');
    if (!$period) {
        json_response(['ok' => false, 'message' => 'Period not found.'], 404);
    }
    if ($period['status'] !== 'draft') {
        json_response(['ok' => false, 'message' => 'Only draft payroll can be edited.']);
    }
    $items = is_array($_POST['item'] ?? null) ? $_POST['item'] : [];
    db_transaction(function () use ($items, $periodId) {
        foreach ($items as $staffId => $fields) {
            $staffId = (int) $staffId;
            if ($staffId <= 0 || !is_array($fields)) continue;
            $g = function (string $k) use ($fields): float {
                $v = isset($fields[$k]) ? (string) $fields[$k] : '';
                return $v !== '' && is_numeric($v) ? max(0.0, (float) $v) : 0.0;
            };
            $basic = (float) (db_fetch_value('SELECT basic_salary FROM payroll_items WHERE period_id = ? AND staff_id = ?', [$periodId, $staffId], 'ii') ?? 0);
            $bonus = $g('bonus');
            $ot = $g('overtime');
            $adv = $g('advance_deduction');
            $oth = $g('other_deduction');
            $net = $basic + $bonus + $ot - $adv - $oth;
            db_execute(
                'UPDATE payroll_items SET bonus = ?, overtime = ?, advance_deduction = ?, other_deduction = ?, net_pay = ? WHERE period_id = ? AND staff_id = ?',
                [$bonus, $ot, $adv, $oth, $net, $periodId, $staffId]
            );
        }
        payroll_recalc($periodId);
    });
    audit_log('update', 'Payroll', $periodId, 'Adjusted payroll items for ' . $period['period_month']);
    json_response(['ok' => true, 'message' => 'Payroll saved.']);
}

if ($action === 'approve' || $action === 'mark_paid') {
    $periodId = (int) post('period_id', '0');
    $period = db_fetch_one('SELECT * FROM payroll_periods WHERE id = ?', [$periodId], 'i');
    if (!$period) {
        json_response(['ok' => false, 'message' => 'Period not found.'], 404);
    }
    $items = (int) db_fetch_value('SELECT COUNT(*) FROM payroll_items WHERE period_id = ?', [$periodId], 'i');
    if ($items === 0) {
        json_response(['ok' => false, 'message' => 'This period has no employees.']);
    }

    if ($action === 'approve') {
        if ($period['status'] !== 'draft') {
            json_response(['ok' => false, 'message' => 'Only draft payroll can be approved.']);
        }
        db_execute("UPDATE payroll_periods SET status = 'approved', approved_by = ? WHERE id = ?", [(int) current_user()['id'], $periodId], 'ii');
        audit_log('approve', 'Payroll', $periodId, 'Approved payroll ' . $period['period_month'] . ' — net ' . money_raw($period['total_net']));
        json_response(['ok' => true, 'message' => 'Payroll approved. Net total: ' . money($period['total_net']) . '.', 'reload' => true]);
    }

    // mark_paid
    if ($period['status'] !== 'approved') {
        json_response(['ok' => false, 'message' => 'Approve the payroll before marking it paid.']);
    }
    $ym = (string) $period['period_month'];
    $payDate = date('Y-m-d', strtotime($ym . '-01'));
    $payDate = $payDate > date('Y-m-d') ? date('Y-m-d') : $payDate;

    $catId = db_fetch_value("SELECT id FROM expense_categories WHERE category_name = 'Payroll' LIMIT 1");
    db_transaction(function () use ($periodId, $period, $ym, $payDate, $catId) {
        db_execute("UPDATE payroll_periods SET status = 'paid', paid_at = NOW() WHERE id = ?", [$periodId], 'i');
        db_execute('UPDATE payroll_items SET paid_at = NOW() WHERE period_id = ?', [$periodId], 'i');
        if ($catId !== null) {
            // NOTE: all-string binds (app convention). This PHP/MariaDB build
            // mis-binds NULL ints followed by double/string params.
            db_execute(
                'INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$period['branch_id'] !== null ? (int) $period['branch_id'] : null, (int) $catId, (float) $period['total_net'], $payDate, 'Payroll ' . $ym, (int) current_user()['id']]
            );
        }
    });
    audit_log('pay', 'Payroll', $periodId, 'Marked payroll ' . $ym . ' paid — net ' . money_raw($period['total_net']));
    notifySuperAdmins(
        'Payroll paid',
        'Payroll for ' . $ym . ' (' . money($period['total_net']) . ') was marked paid and booked as an expense.',
        'success',
        'payroll',
        $periodId,
        '/payroll/view/' . $periodId
    );
    json_response(['ok' => true, 'message' => 'Payroll marked paid and booked as a Payroll expense (' . money($period['total_net']) . ').', 'reload' => true]);
}

if ($action === 'delete') {
    if (!has_permission('payroll.delete')) {
        json_response(['ok' => false, 'message' => 'You do not have permission to delete payroll.'], 403);
    }
    $periodId = (int) post('period_id', '0');
    $period = db_fetch_one('SELECT * FROM payroll_periods WHERE id = ?', [$periodId], 'i');
    if (!$period) {
        json_response(['ok' => false, 'message' => 'Period not found.'], 404);
    }
    if ($period['status'] === 'paid') {
        json_response(['ok' => false, 'message' => 'Paid payroll cannot be deleted (it is already booked as an expense).']);
    }
    db_transaction(function () use ($periodId, $period) {
        db_execute('DELETE FROM payroll_periods WHERE id = ?', [$periodId], 'i');
        audit_log('delete', 'Payroll', $periodId, 'Deleted draft payroll ' . $period['period_month']);
    });
    json_response(['ok' => true, 'message' => 'Payroll deleted.', 'reload' => true]);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
