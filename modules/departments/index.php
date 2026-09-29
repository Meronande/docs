<?php
/**
 * /departments — department management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('departments.view');

$search = get('q', '');
$branchF = get('branch_id', '');
$statusF = get('status', '');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (d.department_name LIKE ? OR d.description LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
if ($statusF !== '') { $where .= ' AND d.status = ?'; $params[] = (int) $statusF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND (d.branch_id = ? OR d.branch_id IS NULL)'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND (d.branch_id = ? OR d.branch_id IS NULL)'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM departments d $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT d.*, b.branch_name,
        (SELECT COUNT(*) FROM doctor_departments dd WHERE dd.department_id = d.id) AS doctor_count,
        (SELECT COUNT(*) FROM staff s WHERE s.department_id = d.id) AS staff_count
     FROM departments d LEFT JOIN branches b ON b.id = d.branch_id
     $where ORDER BY d.department_name LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Departments', 'icon' => 'fa-sitemap', 'breadcrumb' => ['Departments' => null]]);
echo '<div data-crud-url="/ajax/staffing?type=departments" data-crud-title="Department">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="departments">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search department..." value="' . e($search) . '" style="width:200px">';
echo '<select name="status" class="form-select form-select-sm" style="width:130px"><option value="">Any status</option><option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('departments.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/staffing?type=departments" data-title="Department"><i class="fa-solid fa-plus me-1"></i>Add Department</button>';
echo '</div>';

echo '<div class="row g-3">';
if (!$rows) echo '<div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">No departments found</div></div></div>';
foreach ($rows as $d) {
    $id = (int) $d['id'];
    echo '<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div><div class="fw-bold">' . e($d['department_name']) . '</div>';
    echo '<div class="small text-muted">' . e(or_na($d['branch_name'], 'All branches')) . '</div></div>';
    echo status_badge($d['status']);
    echo '</div>';
    echo '<p class="small text-muted mt-2 mb-3">' . e(or_na($d['description'])) . '</p>';
    echo '<div class="d-flex gap-3 small text-muted mb-3">';
    echo '<span><i class="fa-solid fa-user-doctor me-1"></i>' . (int) $d['doctor_count'] . ' doctors</span>';
    echo '<span><i class="fa-solid fa-users me-1"></i>' . (int) $d['staff_count'] . ' staff</span>';
    echo '</div>';
    echo '<div class="d-flex gap-2">';
    if (has_permission('departments.edit')) {
        echo '<button class="btn btn-sm btn-outline-brand" data-action="edit" data-id="' . $id . '" data-url="/ajax/staffing?type=departments" data-title="Department"><i class="fa-solid fa-pen me-1"></i>Edit</button>';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/staffing?type=departments">' . ((int) $d['status'] === 1 ? 'Deactivate' : 'Activate') . '</button>';
    }
    if (has_permission('departments.delete')) echo '<button class="btn btn-sm btn-light text-danger ms-auto" data-action="delete" data-id="' . $id . '" data-url="/ajax/staffing?type=departments" data-name="' . e($d['department_name']) . '" data-title="Department"><i class="fa-solid fa-trash"></i></button>';
    echo '</div></div></div></div>';
}
echo '</div></div>';
ui_page_close();
