<?php
/**
 * /patients — patient list with search, filters, pagination, actions.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('patients.view');

$search = get('q', '');
$genderF = get('gender', '');
$branchF = get('branch_id', '');
$statusF = get('status', '');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (p.full_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($genderF !== '') { $where .= ' AND p.gender = ?'; $params[] = $genderF; }
if ($statusF !== '') { $where .= ' AND p.status = ?'; $params[] = (int) $statusF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND p.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND p.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM patients p $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT p.*, b.branch_name,
        (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.patient_id = p.id) AS last_visit,
        (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id) AS visit_count
     FROM patients p LEFT JOIN branches b ON b.id = p.branch_id
     $where ORDER BY p.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Patients', 'icon' => 'fa-hospital-user', 'breadcrumb' => ['Patients' => null]]);

// Auto-open the register modal when arriving via /patients?new=1
if (get('new', '') === '1' && has_permission('patients.create')) {
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.querySelector('[data-action="create"][data-url="/ajax/patients"]');
  btn?.click();
  history.replaceState(null, '', '/patients');
});
</script>
JS;
}
echo '<div data-crud-url="/ajax/patients" data-crud-title="Patient">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="patients">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search name / code / phone" value="' . e($search) . '" style="width:210px">';
echo '<select name="gender" class="form-select form-select-sm" style="width:120px"><option value="">Any gender</option>';
foreach (['Male', 'Female', 'Other'] as $g) echo '<option ' . ($genderF === $g ? 'selected' : '') . '>' . $g . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<select name="status" class="form-select form-select-sm" style="width:120px"><option value="">Any status</option>';
echo '<option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('patients.create')) {
    echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/patients" data-title="Patient"><i class="fa-solid fa-user-plus me-1"></i>Register Patient</button>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Patient ID</th><th>Name</th><th>Gender</th><th>Age</th><th>Phone</th><th>Branch</th><th>Last Visit</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="9" class="text-center text-muted py-4">No patients found</td></tr>';
foreach ($rows as $p) {
    $id = (int) $p['id'];
    $age = $p['age'] ?? age_from_dob($p['date_of_birth']);
    echo '<tr>';
    echo '<td class="fw-semibold text-nowrap">' . e($p['patient_code'] ?? '—') . '</td>';
    echo '<td><div class="d-flex align-items-center gap-2">';
    if ($p['photo']) echo '<img src="/' . e($p['photo']) . '" class="rounded-circle" style="width:32px;height:32px;object-fit:cover">';
    else echo '<span class="avatar-sm">' . e(mb_strtoupper(mb_substr($p['full_name'], 0, 1))) . '</span>';
    echo '<div><div class="fw-semibold">' . e($p['full_name']) . '</div><div class="small text-muted">' . e(or_na($p['blood_group'])) . '</div></div></div></td>';
    echo '<td>' . e(or_na($p['gender'])) . '</td>';
    echo '<td>' . ($age !== null ? $age . ' yrs' : 'N/A') . '</td>';
    echo '<td>' . e(or_na($p['phone'])) . '</td>';
    echo '<td>' . e(or_na($p['branch_name'])) . '</td>';
    echo '<td class="small">' . ($p['last_visit'] ? fmt_date($p['last_visit']) : '<span class="text-muted">No visits</span>') . '</td>';
    echo '<td>' . status_badge($p['status']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    echo '<a class="btn btn-sm btn-light" href="/patients/view/' . $id . '" title="View"><i class="fa-solid fa-eye"></i></a> ';
    if (has_permission('patients.edit')) echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/patients" data-title="Patient" title="Edit"><i class="fa-solid fa-pen"></i></button> ';
    if (has_permission('patients.delete')) echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/patients" data-name="' . e($p['full_name']) . '" data-title="Patient" title="Delete"><i class="fa-solid fa-trash"></i></button>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
