<?php
/**
 * /specializations — doctor specialization management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('specializations.view');

$search = get('q', '');
$statusF = get('status', '');
$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (s.name LIKE ? OR s.description LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
if ($statusF !== '') { $where .= ' AND s.status = ?'; $params[] = (int) $statusF; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM specializations s $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT s.*, (SELECT COUNT(*) FROM doctors d WHERE d.specialization_id = s.id) AS doctor_count
     FROM specializations s $where ORDER BY s.name LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Specializations', 'icon' => 'fa-award', 'breadcrumb' => ['Specializations' => null]]);
echo '<div data-crud-url="/ajax/staffing?type=specializations" data-crud-title="Specialization">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="specializations">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search..." value="' . e($search) . '" style="width:200px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('specializations.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/staffing?type=specializations" data-title="Specialization"><i class="fa-solid fa-plus me-1"></i>Add Specialization</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>#</th><th>Specialization</th><th>Description</th><th>Doctors</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="6" class="text-center text-muted py-4">No specializations found</td></tr>';
foreach ($rows as $s) {
    $id = (int) $s['id'];
    echo '<tr><td class="text-muted">' . $id . '</td>';
    echo '<td class="fw-semibold">' . e($s['name']) . '</td>';
    echo '<td class="small text-muted">' . e(or_na($s['description'])) . '</td>';
    echo '<td><span class="badge text-bg-light border">' . (int) $s['doctor_count'] . '</span></td>';
    echo '<td>' . status_badge($s['status']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('specializations.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/staffing?type=specializations" data-title="Specialization"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/staffing?type=specializations"><i class="fa-solid ' . ((int) $s['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button> ';
    }
    if (has_permission('specializations.delete')) echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/staffing?type=specializations" data-name="' . e($s['name']) . '" data-title="Specialization"><i class="fa-solid fa-trash"></i></button>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
