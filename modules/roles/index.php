<?php
/**
 * /roles — role management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('roles.view');
$canCreate = has_permission('roles.create');
$canEdit = has_permission('roles.edit');
$canDelete = has_permission('roles.delete');
$canAssign = has_permission('roles.permissions');

$rows = db_fetch_all(
    "SELECT r.*,
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
        (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
     FROM roles r ORDER BY r.id"
);

ui_page_open(['title' => 'Roles & Permissions', 'icon' => 'fa-user-shield', 'breadcrumb' => ['Roles' => null]]);
echo '<div data-crud-url="/ajax/roles" data-crud-title="Role">';
echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '<p class="text-muted small mb-0"><i class="fa-solid fa-circle-info me-1"></i>Roles define what users can do. Assign functionality per role on the permissions page.</p>';
if ($canCreate) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/roles" data-title="Role"><i class="fa-solid fa-plus me-1"></i>Add Role</button>';
echo '</div>';

echo '<div class="row g-3">';
foreach ($rows as $r) {
    $id = (int) $r['id'];
    echo '<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div><div class="fw-bold">' . e($r['role_name']) . ($r['is_system'] ? ' <span class="badge text-bg-light border small">system</span>' : '') . '</div>';
    echo '<div class="small text-muted">' . e(or_na($r['description'])) . '</div></div>';
    echo status_badge($r['status']);
    echo '</div>';
    echo '<div class="d-flex gap-3 small text-muted mt-3 mb-3">';
    echo '<span><i class="fa-solid fa-users me-1"></i>' . (int) $r['user_count'] . ' users</span>';
    echo '<span><i class="fa-solid fa-key me-1"></i>' . (int) $r['perm_count'] . ' permissions</span>';
    echo '</div>';
    echo '<div class="d-flex flex-wrap gap-2">';
    if ($canAssign) echo '<a class="btn btn-sm btn-outline-brand" href="/roles/permissions/' . $id . '"><i class="fa-solid fa-list-check me-1"></i>Assign Functionality</a>';
    if ($canEdit && !$r['is_system']) echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/roles" data-title="Role"><i class="fa-solid fa-pen"></i></button>';
    if ($canEdit && !$r['is_system']) echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="/ajax/roles"><i class="fa-solid ' . ((int) $r['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button>';
    if ($canDelete && !$r['is_system']) echo '<button class="btn btn-sm btn-light text-danger ms-auto" data-action="delete" data-id="' . $id . '" data-url="/ajax/roles" data-name="' . e($r['role_name']) . '" data-title="Role"><i class="fa-solid fa-trash"></i></button>';
    echo '</div></div></div></div>';
}
echo '</div></div>';
ui_page_close();
