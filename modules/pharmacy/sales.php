<?php
/**
 * /pharmacy/sales — dispensing & sales history.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('pharmacy.view');

$q = get('q', '');
$fromF = get('from', '');
$toF = get('to', '');
$branchF = get('branch_id', '');
$where = ' WHERE 1=1';
$params = [];
if ($q !== '') { $where .= ' AND (s.sale_code LIKE ? OR p.full_name LIKE ? OR p.patient_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($fromF !== '' && valid_date($fromF)) { $where .= ' AND DATE(s.created_at) >= ?'; $params[] = $fromF; }
if ($toF !== '' && valid_date($toF)) { $where .= ' AND DATE(s.created_at) <= ?'; $params[] = $toF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND s.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND s.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$totals = db_fetch_one("SELECT COUNT(*) cnt, COALESCE(SUM(s.total_amount),0) total FROM pharmacy_sales s JOIN patients p ON p.id = s.patient_id $where", $params);
$total = (int) $totals['cnt'];
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT s.*, p.full_name patient_name, p.patient_code, b.branch_name,
        (SELECT GROUP_CONCAT(CONCAT(med.medicine_name, ' x', si.quantity) SEPARATOR ', ')
         FROM pharmacy_sale_items si JOIN medicines med ON med.id = si.medicine_id WHERE si.sale_id = s.id) AS items
     FROM pharmacy_sales s
     JOIN patients p ON p.id = s.patient_id
     LEFT JOIN branches b ON b.id = s.branch_id
     $where ORDER BY s.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Pharmacy Sales', 'icon' => 'fa-bag-shopping', 'breadcrumb' => ['Pharmacy' => '/pharmacy', 'Sales' => null]]);
echo '<div class="row g-3 mb-4">';
echo stat_card('Sales (filtered)', number_format($total), 'fa-receipt', 'brand');
echo stat_card('Revenue (filtered)', money($totals['total']), 'fa-sack-dollar', 'success');
echo '</div>';

echo '<div class="card">';
echo '<div class="card-header py-2"><form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="pharmacy_sales">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Sale code / patient" value="' . e($q) . '" style="width:200px">';
echo '<input type="date" name="from" class="form-control form-control-sm" value="' . e($fromF) . '" style="width:145px">';
echo '<input type="date" name="to" class="form-control form-control-sm" value="' . e($toF) . '" style="width:145px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form></div>';
echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Sale Code</th><th>Patient</th><th>Items</th><th>Total</th><th>Date</th><th>Branch</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="6" class="text-center text-muted py-4">No sales found</td></tr>';
foreach ($rows as $s) {
    echo '<tr>';
    echo '<td class="fw-semibold text-nowrap">' . e($s['sale_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($s['patient_name']) . '</div><div class="small text-muted">' . e($s['patient_code']) . '</div></td>';
    echo '<td class="small text-muted" style="max-width:320px">' . e(mb_strimwidth((string) ($s['items'] ?? 'N/A'), 0, 80, '…')) . '</td>';
    echo '<td class="fw-semibold">' . money($s['total_amount']) . '</td>';
    echo '<td class="small">' . fmt_date($s['created_at'], true) . '</td>';
    echo '<td class="small">' . e(or_na($s['branch_name'])) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div>';
ui_page_close();
