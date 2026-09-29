<?php
/**
 * /users — user management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('users.view');

$search = get('q', '');
$roleF = get('role_id', '');
$branchF = get('branch_id', '');
$statusF = get('status', '');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') { $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
if ($roleF !== '') { $where .= ' AND u.role_id = ?'; $params[] = (int) $roleF; }
if ($statusF !== '') { $where .= ' AND u.status = ?'; $params[] = (int) $statusF; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND u.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND u.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM users u $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT u.*, r.role_name, b.branch_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN branches b ON b.id = u.branch_id
     $where ORDER BY u.id DESC LIMIT $perPage OFFSET $offset",
    $params
);
$roles = db_fetch_all('SELECT id, role_name FROM roles WHERE status = 1 ORDER BY role_name');

ui_page_open(['title' => 'Users', 'icon' => 'fa-users-gear', 'breadcrumb' => ['Users' => null]]);
echo '<div data-crud-url="/ajax/users" data-crud-title="User">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="users">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search name / username / email" value="' . e($search) . '" style="width:220px">';
echo '<select name="role_id" class="form-select form-select-sm" style="width:160px"><option value="">All roles</option>';
foreach ($roles as $r) echo '<option value="' . (int) $r['id'] . '" ' . ($roleF === (string) $r['id'] ? 'selected' : '') . '>' . e($r['role_name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:160px"><option value="">All branches</option>';
    foreach (db_fetch_all('SELECT id, branch_name FROM branches ORDER BY branch_name') as $b) {
        echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    }
    echo '</select>';
}
echo '<select name="status" class="form-select form-select-sm" style="width:120px"><option value="">Any status</option>';
echo '<option value="1"' . ($statusF === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ($statusF === '0' ? ' selected' : '') . '>Inactive</option></select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('users.create')) {
    echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/users" data-title="User"><i class="fa-solid fa-plus me-1"></i>Add User</button>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>#</th><th>User</th><th>Role</th><th>Branch</th><th>Contact</th><th>Last Login</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="8" class="text-center text-muted py-4">No users found</td></tr>';
foreach ($rows as $u) {
    $id = (int) $u['id'];
    echo '<tr>';
    echo '<td class="text-muted">' . $id . '</td>';
    echo '<td><div class="d-flex align-items-center gap-2"><span class="avatar-sm">' . e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) . '</span>';
    echo '<div><div class="fw-semibold">' . e($u['full_name']) . '</div><div class="small text-muted">@' . e($u['username']) . '</div></div></div></td>';
    echo '<td><span class="badge text-bg-light border">' . e($u['role_name']) . '</span></td>';
    echo '<td>' . e($u['branch_name'] ?? 'All branches') . '</td>';
    echo '<td class="small">' . e(or_na($u['email'])) . '<br><span class="text-muted">' . e(or_na($u['phone'])) . '</span></td>';
    echo '<td class="small text-muted">' . ($u['last_login'] ? fmt_date($u['last_login'], true) : 'Never') . '</td>';
    echo '<td>' . status_badge($u['status']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('users.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/users" data-title="User" title="Edit"><i class="fa-solid fa-pen"></i></button> ';
        if ($id !== (int) current_user()['id']) {
            echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/users" title="' . ((int) $u['status'] === 1 ? 'Deactivate' : 'Activate') . '">';
            echo '<i class="fa-solid ' . ((int) $u['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button> ';
        }
    }
    if (has_permission('users.delete') && $id !== (int) current_user()['id']) {
        echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/users" data-name="' . e($u['full_name']) . '" data-title="User" title="Delete"><i class="fa-solid fa-trash"></i></button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
