<?php
/**
 * /doctors — doctor management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('doctors.view');

$search = get('q', '');
$specF = get('specialization_id', '');
$branchF = get('branch_id', '');
$statusF = get('status', '');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (d.full_name LIKE ? OR d.doctor_code LIKE ? OR d.phone LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
if ($specF !== '') { $where .= ' AND d.specialization_id = ?'; $params[] = (int) $specF; }
if ($statusF !== '') { $where .= ' AND d.status = ?'; $params[] = (int) $statusF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND d.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND d.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM doctors d $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT d.*, s.name specialization, b.branch_name,
        (SELECT GROUP_CONCAT(dep.department_name SEPARATOR ', ') FROM doctor_departments dd JOIN departments dep ON dep.id = dd.department_id WHERE dd.doctor_id = d.id) AS departments
     FROM doctors d
     LEFT JOIN specializations s ON s.id = d.specialization_id
     LEFT JOIN branches b ON b.id = d.branch_id
     $where ORDER BY d.full_name LIMIT $perPage OFFSET $offset",
    $params
);
$specs = db_fetch_all('SELECT id, name FROM specializations WHERE status = 1 ORDER BY name');

ui_page_open(['title' => 'Doctors', 'icon' => 'fa-user-doctor', 'breadcrumb' => ['Doctors' => null]]);
echo '<div data-crud-url="/ajax/staffing?type=doctors" data-crud-title="Doctor">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="doctors">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search doctor..." value="' . e($search) . '" style="width:200px">';
echo '<select name="specialization_id" class="form-select form-select-sm" style="width:180px"><option value="">All specializations</option><option value="">—</option>';
foreach ($specs as $s) echo '<option value="' . (int) $s['id'] . '" ' . ($specF === (string) $s['id'] ? 'selected' : '') . '>' . e($s['name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<select name="status" class="form-select form-select-sm" style="width:120px"><option value="">Any status</option><option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('doctors.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/staffing?type=doctors" data-title="Doctor"><i class="fa-solid fa-plus me-1"></i>Add Doctor</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Doctor</th><th>Specialization</th><th>Departments</th><th>Fee</th><th>Branch</th><th>Contact</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="9" class="text-center text-muted py-4">No doctors found</td></tr>';
foreach ($rows as $d) {
    $id = (int) $d['id'];
    echo '<tr>';
    echo '<td class="text-nowrap">' . e($d['doctor_code'] ?? '—') . '</td>';
    echo '<td><div class="d-flex align-items-center gap-2">';
    if ($d['photo']) echo '<img src="/' . e($d['photo']) . '" class="rounded-circle" style="width:32px;height:32px;object-fit:cover">';
    else echo '<span class="avatar-sm">' . e(mb_strtoupper(mb_substr($d['full_name'], 0, 1))) . '</span>';
    echo '<div><div class="fw-semibold">Dr. ' . e($d['full_name']) . '</div><div class="small text-muted">' . e(or_na($d['license_number'])) . '</div></div></div></td>';
    echo '<td>' . e(or_na($d['specialization'])) . '</td>';
    echo '<td class="small text-muted" style="max-width:180px">' . e(or_na($d['departments'])) . '</td>';
    echo '<td>' . money($d['consultation_fee']) . '</td>';
    echo '<td>' . e(or_na($d['branch_name'])) . '</td>';
    echo '<td class="small">' . e(or_na($d['phone'])) . '</td>';
    echo '<td>' . status_badge($d['status']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('doctors.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/staffing?type=doctors" data-title="Doctor"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/staffing?type=doctors"><i class="fa-solid ' . ((int) $d['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button> ';
    }
    if (has_permission('doctors.delete')) echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/staffing?type=doctors" data-name="' . e($d['full_name']) . '" data-title="Doctor"><i class="fa-solid fa-trash"></i></button>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
