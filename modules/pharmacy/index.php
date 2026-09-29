<?php
/**
 * /pharmacy — medicines & stock management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('pharmacy.view');

$q = get('q', '');
$catF = get('category_id', '');
$branchF = get('branch_id', '');
$filter = get('filter', ''); // low | expired | expiring
$where = ' WHERE m.status = 1';
$params = [];
if ($q !== '') { $where .= ' AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.medicine_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($catF !== '') { $where .= ' AND m.category_id = ?'; $params[] = (int) $catF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND m.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND m.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }
if ($filter === 'low') { $where .= ' AND m.stock_quantity <= m.minimum_stock'; }
if ($filter === 'expired') { $where .= ' AND m.expiry_date IS NOT NULL AND m.expiry_date < CURDATE()'; }
if ($filter === 'expiring') { $where .= ' AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . (int) setting('low_stock_alert_days', '30') . ' DAY)'; }

$branchCond = (!sees_all_branches() && current_user()['branch_id'] !== null) ? ' AND branch_id = ' . (int) current_user()['branch_id'] : '';
$stats = [
    'total' => (int) db_fetch_value('SELECT COUNT(*) FROM medicines WHERE status = 1' . $branchCond),
    'low' => (int) db_fetch_value('SELECT COUNT(*) FROM medicines WHERE status = 1 AND stock_quantity <= minimum_stock' . $branchCond),
    'expired' => (int) db_fetch_value('SELECT COUNT(*) FROM medicines WHERE status = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()' . $branchCond),
    'expiring' => (int) db_fetch_value('SELECT COUNT(*) FROM medicines WHERE status = 1 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . (int) setting('low_stock_alert_days', '30') . ' DAY)' . $branchCond),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM medicines m $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT m.*, c.category_name, s.supplier_name, b.branch_name
     FROM medicines m
     LEFT JOIN medicine_categories c ON c.id = m.category_id
     LEFT JOIN suppliers s ON s.id = m.supplier_id
     LEFT JOIN branches b ON b.id = m.branch_id
     $where ORDER BY m.medicine_name LIMIT $perPage OFFSET $offset",
    $params
);
$cats = db_fetch_all('SELECT id, category_name FROM medicine_categories WHERE status = 1 ORDER BY category_name');

ui_page_open(['title' => 'Pharmacy — Medicines & Stock', 'icon' => 'fa-pills', 'breadcrumb' => ['Pharmacy' => null]]);
echo '<div data-crud-url="/ajax/pharmacy" data-crud-title="Medicine">';

echo '<div class="row g-3 mb-4">';
echo stat_card('Total Medicines', $stats['total'], 'fa-pills', 'brand');
echo stat_card('Low Stock', $stats['low'], 'fa-arrow-trend-down', 'danger', '/pharmacy?filter=low');
echo stat_card('Expired', $stats['expired'], 'fa-skull-crossbones', 'danger', '/pharmacy?filter=expired');
echo stat_card('Expiring Soon', $stats['expiring'], 'fa-hourglass-half', 'warning', '/pharmacy?filter=expiring');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="pharmacy">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search medicine..." value="' . e($q) . '" style="width:190px">';
echo '<select name="category_id" class="form-select form-select-sm" style="width:170px"><option value="">All categories</option>';
foreach ($cats as $c) echo '<option value="' . (int) $c['id'] . '" ' . ($catF === (string) $c['id'] ? 'selected' : '') . '>' . e($c['category_name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<button class="btn btn-sm btn-outline-brand">Filter</button>';
foreach (['low' => 'Low stock', 'expired' => 'Expired', 'expiring' => 'Expiring soon'] as $f => $label) {
    if ($filter === $f) echo '<a class="btn btn-sm btn-warning" href="/pharmacy">Clear "' . e($label) . '" filter</a>';
}
echo '</form>';
if (has_permission('pharmacy.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/pharmacy?form=medicine" data-title="Medicine"><i class="fa-solid fa-pills me-1"></i>Add Medicine</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Medicine</th><th>Category</th><th>Stock</th><th>Min</th><th>Price</th><th>Expiry</th><th>Branch</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="9" class="text-center text-muted py-4">No medicines found</td></tr>';
foreach ($rows as $m) {
    $id = (int) $m['id'];
    $stock = (int) $m['stock_quantity'];
    $min = (int) $m['minimum_stock'];
    $isLow = $stock <= $min;
    $isExpired = $m['expiry_date'] && strtotime((string) $m['expiry_date']) < time();
    $isExpiring = !$isExpired && $m['expiry_date'] && strtotime((string) $m['expiry_date']) <= strtotime('+' . (int) setting('low_stock_alert_days', '30') . ' days');
    echo '<tr' . ($isExpired ? ' class="table-danger"' : ($isLow ? ' class="table-warning"' : '')) . '>';
    echo '<td class="small text-nowrap">' . e($m['medicine_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($m['medicine_name']) . '</div><div class="small text-muted">' . e(or_na($m['generic_name'])) . '</div></td>';
    echo '<td class="small">' . e(or_na($m['category_name'])) . '</td>';
    echo '<td><span class="badge ' . ($isLow ? 'text-bg-danger' : 'text-bg-success') . '">' . $stock . ' ' . e(or_na($m['unit'])) . '</span></td>';
    echo '<td class="small text-muted">' . $min . '</td>';
    echo '<td class="small">' . money($m['selling_price']) . '</td>';
    echo '<td class="small text-nowrap">' . fmt_date($m['expiry_date']);
    if ($isExpired) echo ' <span class="badge text-bg-danger">expired</span>';
    elseif ($isExpiring) echo ' <span class="badge text-bg-warning">soon</span>';
    echo '</td>';
    echo '<td class="small">' . e(or_na($m['branch_name'])) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('pharmacy.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/pharmacy?form=medicine" data-title="Medicine"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="stock-add" data-id="' . $id . '" title="Add stock (+10)"><i class="fa-solid fa-plus"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="stock-sub" data-id="' . $id . '" title="Remove stock (-10)"><i class="fa-solid fa-minus"></i></button> ';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';

echo <<<'JS'
<script>
document.addEventListener('click', async function (ev) {
  const add = ev.target.closest('[data-action="stock-add"]');
  const sub = ev.target.closest('[data-action="stock-sub"]');
  const btn = add || sub;
  if (!btn) return;
  const data = await App.postJSON('/ajax/pharmacy', { action: 'adjust', id: btn.dataset.id, delta: add ? 10 : -10 });
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 400); }
  else App.toast(data.message, 'danger');
});
</script>
JS;
ui_page_close();
