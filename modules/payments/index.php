<?php
/**
 * /payments — all payments received, with export.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('payments.view');

$q = get('q', '');
$methodF = get('method_id', '');
$branchF = get('branch_id', '');
$from = get('from', '');
$to = get('to', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (pay.payment_code LIKE ? OR i.invoice_number LIKE ? OR p.full_name LIKE ? OR pay.reference_number LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($methodF !== '') { $where .= ' AND pay.payment_method_id = ?'; $params[] = (int) $methodF; }
if ($from !== '' && valid_date($from)) { $where .= ' AND pay.payment_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where .= ' AND pay.payment_date <= ?'; $params[] = $to . ' 23:59:59'; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND pay.branch_id = ?'; $params[] = (int) $branchF; }
else {
    $branchId = scope_branch_id();
    if ($branchId !== null) { $where .= ' AND pay.branch_id = ?'; $params[] = $branchId; }
}

$join = ' FROM payments pay
          JOIN invoices i ON i.id = pay.invoice_id
          LEFT JOIN patients p ON p.id = pay.patient_id
          LEFT JOIN payment_methods m ON m.id = pay.payment_method_id
          LEFT JOIN branches b ON b.id = pay.branch_id';

$branchScope = (fn() => scope_branch_id() !== null ? ' AND branch_id = ' . scope_branch_id() : '')();
$today = (float) db_fetch_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= CURDATE()' . $branchScope);
$month = (float) db_fetch_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")' . $branchScope);
$totalSum = (float) db_fetch_value("SELECT COALESCE(SUM(pay.amount),0) $join $where", $params);
$methods = db_fetch_all('SELECT id, method_name FROM payment_methods WHERE status = 1 ORDER BY id');

$total = (int) db_fetch_value("SELECT COUNT(*) $join $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT pay.*, i.invoice_number, p.full_name patient_name, p.patient_code, m.method_name, b.branch_name
     $join $where ORDER BY pay.payment_date DESC, pay.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Payments', 'icon' => 'fa-money-bill-wave', 'breadcrumb' => ['Finance' => null, 'Payments' => null]]);

echo '<div class="row g-3 mb-4">';
echo stat_card('Today Collected', money($today), 'fa-calendar-day', 'brand');
echo stat_card('This Month', money($month), 'fa-calendar-days', 'success');
echo stat_card('Filtered Total', money($totalSum), 'fa-filter', 'info');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Code, invoice, patient..." value="' . e($q) . '" style="width:190px">';
echo '<select name="method_id" class="form-select form-select-sm" style="width:150px"><option value="">All methods</option>';
foreach ($methods as $m) echo '<option value="' . (int) $m['id'] . '" ' . ($methodF === (string) $m['id'] ? 'selected' : '') . '>' . e($m['method_name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<input type="date" name="from" class="form-control form-control-sm" value="' . e($from) . '" style="width:150px">';
echo '<input type="date" name="to" class="form-control form-control-sm" value="' . e($to) . '" style="width:150px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button>';
echo '</form>';
if (has_permission('reports.export')) {
    $qs = http_build_query(array_filter(['q' => $q, 'method_id' => $methodF, 'branch_id' => $branchF, 'from' => $from, 'to' => $to]));
    echo '<a class="btn btn-outline-brand" href="/payments/export?' . e($qs) . '"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Date</th><th>Invoice</th><th>Patient</th><th>Method</th><th>Reference</th><th>Branch</th><th class="text-end">Amount</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="8" class="text-center text-muted py-4">No payments found</td></tr>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td class="small text-nowrap fw-semibold">' . e($r['payment_code'] ?? '—') . '</td>';
    echo '<td class="small text-nowrap">' . fmt_date($r['payment_date'], true) . '</td>';
    echo '<td class="small text-nowrap"><a href="/billing/view/' . (int) $r['invoice_id'] . '">' . e($r['invoice_number'] ?? '—') . '</a></td>';
    echo '<td class="small">' . e(or_na($r['patient_name'])) . ' <span class="text-muted">' . e($r['patient_code'] ?? '') . '</span></td>';
    echo '<td class="small">' . e(or_na($r['method_name'])) . '</td>';
    echo '<td class="small">' . e(or_na($r['reference_number'])) . '</td>';
    echo '<td class="small">' . e(or_na($r['branch_name'])) . '</td>';
    echo '<td class="text-end text-nowrap fw-semibold text-success">' . money($r['amount']) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
