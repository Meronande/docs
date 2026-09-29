<?php
/**
 * AJAX roles endpoint (form, save, toggle, delete).
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('roles.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission('roles.create') && !has_permission('roles.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $role = $id ? (db_fetch_one('SELECT * FROM roles WHERE id = ?', [$id], 'i') ?? []) : [];
    ob_start();
    echo '<form id="crudForm" data-url="/ajax/roles">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<div class="mb-3"><label class="form-label required">Role Name</label><input class="form-control" name="role_name" value="' . e($role['role_name'] ?? '') . '" required></div>';
    echo '<div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3">' . e($role['description'] ?? '') . '</textarea></div>';
    echo '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($role['status'] ?? 1) === 1 ? 'checked' : '') . ' id="rst"><label class="form-check-label" for="rst">Active</label></div>';
    echo '</form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

$action = post('action', 'save');
$id = post('id', '') !== '' ? (int) post('id') : null;

if ($action === 'save') {
    if ($id && !has_permission('roles.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if (!$id && !has_permission('roles.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $name = post('role_name');
    if ($name === '') json_response(['ok' => false, 'message' => 'Role name is required.']);
    $dupe = db_fetch_value('SELECT id FROM roles WHERE role_name = ? AND id <> ?', [$name, $id ?? 0], 'si');
    if ($dupe) json_response(['ok' => false, 'message' => 'A role with this name already exists.']);

    if ($id) {
        $isSystem = (int) db_fetch_value('SELECT is_system FROM roles WHERE id = ?', [$id], 'i') === 1;
        if ($isSystem) json_response(['ok' => false, 'message' => 'System roles cannot be renamed.']);
        db_execute('UPDATE roles SET role_name = ?, description = ?, status = ? WHERE id = ?',
            [$name, post('description') ?: null, isset($_POST['status']) ? 1 : 0, $id], 'ssii');
        audit_log('update', 'Role', $id, 'Updated role: ' . $name);
        json_response(['ok' => true, 'message' => 'Role updated.']);
    }
    $newId = db_execute('INSERT INTO roles (role_name, description, status) VALUES (?, ?, ?)',
        [$name, post('description') ?: null, isset($_POST['status']) ? 1 : 0], 'ssi');
    audit_log('create', 'Role', $newId, 'Created role: ' . $name);
    json_response(['ok' => true, 'message' => 'Role created. It has no permissions yet — assign functionality now.', 'id' => $newId]);
}

if ($action === 'toggle') {
    if (!has_permission('roles.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $row = db_fetch_one('SELECT is_system, status, role_name FROM roles WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Role not found.'], 404);
    if ($row['is_system']) json_response(['ok' => false, 'message' => 'System roles cannot be deactivated.']);
    $new = ((int) $row['status']) === 1 ? 0 : 1;
    db_execute('UPDATE roles SET status = ? WHERE id = ?', [$new, $id], 'ii');
    audit_log($new ? 'activate' : 'deactivate', 'Role', $id, ($new ? 'Activated' : 'Deactivated') . ' role: ' . $row['role_name']);
    json_response(['ok' => true, 'message' => 'Status updated.']);
}

if ($action === 'delete') {
    if (!has_permission('roles.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $row = db_fetch_one('SELECT is_system, role_name FROM roles WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Role not found.'], 404);
    if ($row['is_system']) json_response(['ok' => false, 'message' => 'System roles cannot be deleted.']);
    $users = (int) db_fetch_value('SELECT COUNT(*) FROM users WHERE role_id = ?', [$id], 'i');
    if ($users > 0) {
        json_response(['ok' => false, 'message' => "This role is assigned to $users user" . ($users === 1 ? '' : 's') . ' and cannot be deleted. Deactivate it instead.']);
    }
    db_transaction(function () use ($id, $row) {
        db_execute('DELETE FROM role_permissions WHERE role_id = ?', [$id], 'i');
        db_execute('DELETE FROM roles WHERE id = ?', [$id], 'i');
        audit_log('delete', 'Role', $id, 'Deleted role: ' . $row['role_name']);
    });
    json_response(['ok' => true, 'message' => 'Role deleted.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
