<?php
/**
 * /billing — invoice list.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('billing.view');

$q = get('q', '');
$statusF = get('status', '');
$branchF = get('branch_id', '');
$from = get('from', '');
$to = get('to', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (i.invoice_number LIKE ? OR p.full_name LIKE ? OR p.patient_code LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($statusF !== '') { $where .= ' AND i.status = ?'; $params[] = $statusF; }
if ($from !== '' && valid_date($from)) { $where .= ' AND i.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where .= ' AND i.created_at <= ?'; $params[] = $to . ' 23:59:59'; }

// Branch scoping
if ($branchF !== '' && sees_all_branches()) {
    $where .= ' AND i.branch_id = ?'; $params[] = (int) $branchF;
} else {
    $branchId = scope_branch_id();
    if ($branchId !== null) { $where .= ' AND i.branch_id = ?'; $params[] = $branchId; }
}

$branchScope = (fn() => scope_branch_id() !== null ? ' AND branch_id = ' . scope_branch_id() : '')();
$stats = [
    'total' => (int) db_fetch_value('SELECT COUNT(*) FROM invoices WHERE status != "cancelled"' . $branchScope),
    'unpaid' => (int) db_fetch_value('SELECT COUNT(*) FROM invoices WHERE status = "unpaid"' . $branchScope),
    'partial' => (int) db_fetch_value('SELECT COUNT(*) FROM invoices WHERE status = "partial"' . $branchScope),
    'revenue' => (float) db_fetch_value('SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE status != "cancelled"' . $branchScope),
    'outstanding' => (float) db_fetch_value('SELECT COALESCE(SUM(balance),0) FROM invoices WHERE status IN ("unpaid","partial")' . $branchScope),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM invoices i JOIN patients p ON p.id = i.patient_id $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT i.*, p.full_name patient_name, p.patient_code, b.branch_name,
            (SELECT SUM(amount) FROM payments pay WHERE pay.invoice_id = i.id) paid_sum
     FROM invoices i JOIN patients p ON p.id = i.patient_id LEFT JOIN branches b ON b.id = i.branch_id
     $where ORDER BY i.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$statusBadge = ['unpaid' => 'text-bg-danger', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];

ui_page_open(['title' => 'Invoices', 'icon' => 'fa-file-invoice-dollar', 'breadcrumb' => ['Finance' => null, 'Invoices' => null]]);
echo '<div data-crud-url="/ajax/billing" data-crud-title="Invoice">';

echo '<div class="row g-3 mb-4">';
echo stat_card('Invoices', $stats['total'], 'fa-file-invoice-dollar', 'brand');
echo stat_card('Unpaid', $stats['unpaid'], 'fa-hourglass', 'danger', '/billing?status=unpaid');
echo stat_card('Partially Paid', $stats['partial'], 'fa-circle-half-stroke', 'warning', '/billing?status=partial');
echo stat_card('Total Collected', money($stats['revenue']), 'fa-sack-dollar', 'success');
echo stat_card('Outstanding', money($stats['outstanding']), 'fa-scale-balanced', 'warning');
echo '</div>';

// Toolbar
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Invoice #, patient..." value="' . e($q) . '" style="width:190px">';
echo '<select name="status" class="form-select form-select-sm" style="width:140px"><option value="">All statuses</option>';
foreach (['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $v) {
    echo '<option value="' . $k . '" ' . ($statusF === $k ? 'selected' : '') . '>' . $v . '</option>';
}
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
if (has_permission('billing.create')) {
    echo '<a href="/billing/create" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus me-1"></i>New Invoice</a>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Invoice</th><th>Patient</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Branch</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="9" class="text-center text-muted py-4">No invoices found</td></tr>';
foreach ($rows as $r) {
    $id = (int) $r['id'];
    echo '<tr' . ($r['status'] === 'cancelled' ? ' class="opacity-50"' : '') . '>';
    echo '<td class="fw-semibold text-nowrap"><a href="/billing/view/' . $id . '">' . e($r['invoice_number'] ?? ('#' . $id)) . '</a></td>';
    echo '<td>' . e($r['patient_name']) . ' <span class="text-muted small">(' . e($r['patient_code']) . ')</span></td>';
    echo '<td class="small text-nowrap">' . fmt_date($r['created_at']) . '</td>';
    echo '<td class="text-nowrap">' . money($r['total']) . '</td>';
    echo '<td class="text-nowrap text-success">' . money($r['paid_amount']) . '</td>';
    echo '<td class="text-nowrap ' . ((float) $r['balance'] > 0 ? 'text-danger fw-semibold' : 'text-muted') . '">' . money($r['balance']) . '</td>';
    echo '<td><span class="badge ' . ($statusBadge[$r['status']] ?? 'text-bg-secondary') . '">' . label_case($r['status']) . '</span></td>';
    echo '<td class="small">' . e(or_na($r['branch_name'])) . '</td>';
    echo '<td class="text-end text-nowrap">';
    echo '<a class="btn btn-sm btn-light" href="/billing/view/' . $id . '" title="View"><i class="fa-solid fa-eye"></i></a> ';
    if ($r['status'] !== 'cancelled' && (float) $r['balance'] > 0 && has_permission('payments.create')) {
        echo '<button class="btn btn-sm btn-brand" data-action="create" data-url="/ajax/billing?pay=' . $id . '" data-title="Record Payment"><i class="fa-solid fa-money-bill-wave"></i></button> ';
    }
    if ($r['status'] !== 'cancelled' && has_permission('billing.delete')) {
        echo '<button class="btn btn-sm btn-light text-danger" data-action="inv-cancel" data-id="' . $id . '" title="Cancel invoice"><i class="fa-solid fa-ban"></i></button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';

echo <<<'JS'
<script>
document.addEventListener('click', async function (ev) {
  const cancelBtn = ev.target.closest('[data-action="inv-cancel"]');
  if (!cancelBtn || cancelBtn.disabled) return;
  ev.preventDefault();
  AppConfirm('Cancel this invoice?', 'The invoice will be marked as cancelled.', async () => {
    const data = await App.postJSON('/ajax/billing', { action: 'cancel', id: cancelBtn.dataset.id });
    if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 400); }
    else App.toast(data.message, 'danger');
  });
});
</script>
JS;
ui_page_close();
