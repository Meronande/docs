<?php
/**
 * /staff — HR staff management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('staff.view');

$search = get('q', '');
$deptF = get('department_id', '');
$branchF = get('branch_id', '');
$statusF = get('status', '');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (s.full_name LIKE ? OR s.staff_code LIKE ? OR s.position LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
if ($deptF !== '') { $where .= ' AND s.department_id = ?'; $params[] = (int) $deptF; }
if ($statusF !== '') { $where .= ' AND s.status = ?'; $params[] = (int) $statusF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND s.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND s.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM staff s $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT s.*, d.department_name, b.branch_name
     FROM staff s
     LEFT JOIN departments d ON d.id = s.department_id
     LEFT JOIN branches b ON b.id = s.branch_id
     $where ORDER BY s.id DESC LIMIT $perPage OFFSET $offset",
    $params
);
$departments = db_fetch_all('SELECT id, department_name FROM departments WHERE status = 1 ORDER BY department_name');

ui_page_open(['title' => 'Staff (HR)', 'icon' => 'fa-users', 'breadcrumb' => ['Staff' => null]]);
echo '<div data-crud-url="/ajax/staffing?type=staff" data-crud-title="Staff Member">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="staff">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search staff..." value="' . e($search) . '" style="width:200px">';
echo '<select name="department_id" class="form-select form-select-sm" style="width:170px"><option value="">All departments</option>';
foreach ($departments as $d) echo '<option value="' . (int) $d['id'] . '" ' . ($deptF === (string) $d['id'] ? 'selected' : '') . '>' . e($d['department_name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<select name="status" class="form-select form-select-sm" style="width:120px"><option value="">Any status</option><option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('staff.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/staffing?type=staff" data-title="Staff Member"><i class="fa-solid fa-plus me-1"></i>Add Staff</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Department</th><th>Branch</th><th>Phone</th><th>Joined</th><th>Salary</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="10" class="text-center text-muted py-4">No staff found</td></tr>';
foreach ($rows as $s) {
    $id = (int) $s['id'];
    echo '<tr>';
    echo '<td class="text-nowrap">' . e($s['staff_code'] ?? '—') . '</td>';
    echo '<td><div class="d-flex align-items-center gap-2"><span class="avatar-sm">' . e(mb_strtoupper(mb_substr($s['full_name'], 0, 1))) . '</span><span class="fw-semibold">' . e($s['full_name']) . '</span></div></td>';
    echo '<td>' . e(or_na($s['position'])) . '</td>';
    echo '<td>' . e(or_na($s['department_name'])) . '</td>';
    echo '<td>' . e(or_na($s['branch_name'])) . '</td>';
    echo '<td class="small">' . e(or_na($s['phone'])) . '</td>';
    echo '<td class="small">' . fmt_date($s['joining_date']) . '</td>';
    echo '<td>' . ($s['salary'] !== null ? money($s['salary']) : 'N/A') . '</td>';
    echo '<td>' . status_badge($s['status']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('staff.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/staffing?type=staff" data-title="Staff Member"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/staffing?type=staff"><i class="fa-solid ' . ((int) $s['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button> ';
    }
    if (has_permission('staff.delete')) echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/staffing?type=staff" data-name="' . e($s['full_name']) . '" data-title="Staff Member"><i class="fa-solid fa-trash"></i></button>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
