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
echo '<thead><tr><th>Sale Code</th><th>Patient</th><th>Items</th><th>Total</th><th>Date</th><th>Branch</th><th class="text-end">Details</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No sales found</td></tr>';
foreach ($rows as $s) {
    echo '<tr>';
    echo '<td class="fw-semibold text-nowrap">' . e($s['sale_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($s['patient_name']) . '</div><div class="small text-muted">' . e($s['patient_code']) . '</div></td>';
    echo '<td class="small text-muted" style="max-width:320px">' . e(mb_strimwidth((string) ($s['items'] ?? 'N/A'), 0, 80, '…')) . '</td>';
    echo '<td class="fw-semibold">' . money($s['total_amount']) . '</td>';
    echo '<td class="small">' . fmt_date($s['created_at'], true) . '</td>';
    echo '<td class="small">' . e(or_na($s['branch_name'])) . '</td>';
    echo '<td class="text-end"><button class="btn btn-sm btn-light border" data-action="sale-detail" data-id="' . (int) $s['id'] . '"><i class="fa-solid fa-receipt me-1"></i>Details</button></td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div>';
?>
<div class="modal fade" id="saleDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Sale Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="saleDetailBody"><div class="text-center py-4"><span class="spinner-border text-secondary"></span></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', async function (ev) {
  const btn = ev.target.closest('[data-action="sale-detail"]');
  if (!btn) return;
  const body = document.getElementById('saleDetailBody');
  body.innerHTML = '<div class="text-center py-4"><span class="spinner-border text-secondary"></span></div>';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('saleDetailModal')).show();
  try {
    const data = await App.getJSON('/ajax/pharmacy?sale=' + btn.dataset.id);
    body.innerHTML = data.ok ? data.html : '<div class="alert alert-danger">' + App.escapeHtml(data.message || 'Not found.') + '</div>';
  } catch (e) {
    body.innerHTML = '<div class="alert alert-danger">Could not load details.</div>';
  }
});
</script>
<?php
ui_page_close();
