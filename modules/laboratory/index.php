<?php
/**
 * /laboratory — lab orders list.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('laboratory.view');

$q = get('q', '');
$statusF = get('status', '');
$fromF = get('from', '');
$toF = get('to', '');
$branchId = scope_branch_id();

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') { $where .= ' AND (lo.order_code LIKE ? OR p.full_name LIKE ? OR p.patient_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($statusF !== '') { $where .= ' AND lo.status = ?'; $params[] = $statusF; }
if ($fromF !== '' && valid_date($fromF)) { $where .= ' AND DATE(lo.created_at) >= ?'; $params[] = $fromF; }
if ($toF !== '' && valid_date($toF)) { $where .= ' AND DATE(lo.created_at) <= ?'; $params[] = $toF; }
if ($branchId !== null) { $where .= ' AND lo.branch_id = ?'; $params[] = $branchId; }

$branchCond = $branchId !== null ? ' AND branch_id = ' . (int) $branchId : '';
$stats = [
    'pending' => (int) db_fetch_value("SELECT COUNT(*) FROM lab_orders WHERE status = 'pending'" . $branchCond),
    'progress' => (int) db_fetch_value("SELECT COUNT(*) FROM lab_orders WHERE status = 'in_progress'" . $branchCond),
    'completed' => (int) db_fetch_value("SELECT COUNT(*) FROM lab_orders WHERE status = 'completed'" . $branchCond),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM lab_orders lo JOIN patients p ON p.id = lo.patient_id $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT lo.*, p.full_name patient_name, p.patient_code, d.full_name doctor_name, b.branch_name,
        (SELECT COUNT(*) FROM lab_order_items WHERE lab_order_id = lo.id) AS test_count,
        (SELECT COUNT(*) FROM lab_order_items WHERE lab_order_id = lo.id AND status = 'completed') AS done_count
     FROM lab_orders lo
     JOIN patients p ON p.id = lo.patient_id
     LEFT JOIN doctors d ON d.id = lo.doctor_id
     LEFT JOIN branches b ON b.id = lo.branch_id
     $where ORDER BY lo.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Laboratory', 'icon' => 'fa-flask-vial', 'breadcrumb' => ['Laboratory' => null]]);
echo '<div data-crud-url="/ajax/lab" data-crud-title="Lab Order">';
echo '<div class="row g-3 mb-4">';
echo stat_card('Pending', $stats['pending'], 'fa-hourglass-start', 'warning');
echo stat_card('In Progress', $stats['progress'], 'fa-vial', 'info');
echo stat_card('Completed', $stats['completed'], 'fa-circle-check', 'success');
echo stat_card('Total Orders', $total, 'fa-flask-vial', 'brand');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="laboratory">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Order code / patient" value="' . e($q) . '" style="width:200px">';
echo '<select name="status" class="form-select form-select-sm" style="width:150px"><option value="">Any status</option>';
foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $sv => $sl) {
    echo '<option value="' . $sv . '"' . ($statusF === $sv ? ' selected' : '') . '>' . $sl . '</option>';
}
echo '</select>';
echo '<input type="date" name="from" class="form-control form-control-sm" value="' . e($fromF) . '" style="width:145px">';
echo '<input type="date" name="to" class="form-control form-control-sm" value="' . e($toF) . '" style="width:145px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('laboratory.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/lab?form=order" data-title="Lab Order"><i class="fa-solid fa-flask-vial me-1"></i>Order Tests</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Order Code</th><th>Patient</th><th>Doctor</th><th>Tests</th><th>Status</th><th>Date</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No lab orders found</td></tr>';
foreach ($rows as $lo) {
    $id = (int) $lo['id'];
    $badge = ['pending' => 'text-bg-warning', 'in_progress' => 'text-bg-info', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$lo['status']] ?? 'text-bg-secondary';
    echo '<tr>';
    echo '<td class="fw-semibold">' . e($lo['order_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($lo['patient_name']) . '</div><div class="small text-muted">' . e($lo['patient_code']) . '</div></td>';
    echo '<td class="small">' . e($lo['doctor_name'] ? 'Dr. ' . $lo['doctor_name'] : 'N/A') . '</td>';
    echo '<td class="small"><span class="badge text-bg-light border">' . (int) $lo['done_count'] . '/' . (int) $lo['test_count'] . ' done</span></td>';
    echo '<td><span class="badge ' . $badge . '">' . label_case($lo['status']) . '</span></td>';
    echo '<td class="small">' . fmt_date($lo['created_at'], true) . '</td>';
    echo '<td class="text-end text-nowrap">';
    echo '<a class="btn btn-sm btn-light" href="/laboratory/view/' . $id . '" title="View"><i class="fa-solid fa-eye"></i></a> ';
    if (has_permission('laboratory.result') && $lo['status'] !== 'completed') {
        echo '<button class="btn btn-sm btn-outline-brand" data-action="create" data-url="/ajax/lab?results=' . $id . '" data-title="Enter Results"><i class="fa-solid fa-pen-to-square me-1"></i>Results</button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
