<?php
/**
 * /branches — dynamic branch management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('branches.view');
$canEdit = has_permission('branches.edit');
$canDelete = has_permission('branches.delete');
$canCreate = has_permission('branches.create');

$search = get('q', '');
$statusF = get('status', '');
$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (branch_name LIKE ? OR branch_code LIKE ? OR city LIKE ?)'; $params = ["%$search%", "%$search%", "%$search%"]; }
if ($statusF !== '') { $where .= ' AND status = ?'; $params[] = (int) $statusF; }

// Per-branch counters for the overview cards
$stats = [
    'users' => (int) db_fetch_value('SELECT COUNT(*) FROM users'),
    'patients' => (int) db_fetch_value('SELECT COUNT(*) FROM patients'),
    'doctors' => (int) db_fetch_value('SELECT COUNT(*) FROM doctors'),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM branches $where", $params);
[$offset, $perPage] = paginate($total, 12);
$rows = db_fetch_all("SELECT * FROM branches $where ORDER BY id LIMIT $perPage OFFSET $offset", $params);

$crudUrl = '/ajax/crud?resource=branches';
ui_page_open(['title' => 'Branches', 'icon' => 'fa-building', 'breadcrumb' => ['Branches' => null]]);
echo '<div data-crud-url="' . e($crudUrl) . '" data-crud-title="Branch">';

echo '<div class="row g-3 mb-4">';
echo stat_card('Total Branches', count(db_fetch_all('SELECT id FROM branches')), 'fa-building', 'brand');
echo stat_card('Active', (int) db_fetch_value('SELECT COUNT(*) FROM branches WHERE status = 1'), 'fa-circle-check', 'success');
echo stat_card('Total Users', $stats['users'], 'fa-users-gear', 'info');
echo stat_card('Total Patients', $stats['patients'], 'fa-hospital-user', 'warning');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="branches">';
echo '<input type="text" name="q" class="form-control form-control-sm" placeholder="Search branch..." value="' . e($search) . '" style="width:200px">';
echo '<select name="status" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">';
echo '<option value="">All status</option><option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select></form>';
if ($canCreate) {
    echo '<button class="btn btn-brand" data-action="create" data-url="' . e($crudUrl) . '" data-title="Branch"><i class="fa-solid fa-plus me-1"></i>Add Branch</button>';
}
echo '</div>';

echo '<div class="row g-3">';
if (!$rows) echo '<div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">No branches found</div></div></div>';
foreach ($rows as $b) {
    $id = (int) $b['id'];
    $bUsers = (int) db_fetch_value('SELECT COUNT(*) FROM users WHERE branch_id = ?', [$id], 'i');
    $bPatients = (int) db_fetch_value('SELECT COUNT(*) FROM patients WHERE branch_id = ?', [$id], 'i');
    $bDoctors = (int) db_fetch_value('SELECT COUNT(*) FROM doctors WHERE branch_id = ?', [$id], 'i');
    echo '<div class="col-md-6 col-xl-4"><div class="card h-100">';
    echo '<div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-start mb-2">';
    echo '<div><div class="fw-bold">' . e($b['branch_name']) . '</div><div class="small text-muted">' . e($b['branch_code']) . ' · ' . e(or_na($b['city'])) . '</div></div>';
    echo status_badge($b['status']);
    echo '</div>';
    echo '<div class="small text-muted mb-2"><i class="fa-solid fa-location-dot me-1"></i>' . e(or_na($b['address'])) . '</div>';
    echo '<div class="small text-muted mb-3"><i class="fa-solid fa-phone me-1"></i>' . e(or_na($b['phone'])) . ' · ' . e(or_na($b['email'])) . '</div>';
    echo '<div class="d-flex gap-3 small mb-3">';
    echo '<span><i class="fa-solid fa-user-doctor me-1 text-secondary"></i>' . $bDoctors . ' doctors</span>';
    echo '<span><i class="fa-solid fa-users me-1 text-secondary"></i>' . $bUsers . ' users</span>';
    echo '<span><i class="fa-solid fa-hospital-user me-1 text-secondary"></i>' . $bPatients . ' patients</span>';
    echo '</div>';
    echo '<div class="d-flex gap-2">';
    if ($canEdit) {
        echo '<button class="btn btn-sm btn-outline-brand" data-action="edit" data-id="' . $id . '" data-url="' . e($crudUrl) . '" data-title="Branch"><i class="fa-solid fa-pen me-1"></i>Edit</button>';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="' . e($crudUrl) . '">' . ((int) $b['status'] === 1 ? 'Deactivate' : 'Activate') . '</button>';
    }
    if ($canDelete) {
        echo '<button class="btn btn-sm btn-light text-danger ms-auto" data-action="delete" data-id="' . $id . '" data-url="' . e($crudUrl) . '" data-name="' . e($b['branch_name']) . '" data-title="Branch"><i class="fa-solid fa-trash"></i></button>';
    }
    echo '</div></div></div></div>';
}
echo '</div>';
echo pagination_links();
echo '</div>';
ui_page_close();
