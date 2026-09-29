<?php
/**
 * AJAX users endpoint.
 * GET  /ajax/users[?id=]           -> form
 * POST /ajax/users action=save     -> create/update (password_hash)
 * POST /ajax/users action=toggle   -> activate/deactivate
 * POST /ajax/users action=delete   -> safe delete
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('users.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission('users.create') && !has_permission('users.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $user = $id ? (db_fetch_one('SELECT * FROM users WHERE id = ?', [$id], 'i') ?? []) : [];

    $branches = visible_branches();
    $roles = db_fetch_all('SELECT id, role_name FROM roles WHERE status = 1 ORDER BY role_name');

    $selBranch = (string) ($user['branch_id'] ?? '');
    $selRole = (string) ($user['role_id'] ?? '');
    $allBranches = sees_all_branches();

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/users">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($user['full_name'] ?? '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label required">Username</label><input class="form-control" name="username" value="' . e($user['username'] ?? '') . '" ' . ($id ? 'readonly' : 'required') . '></div>';
    echo '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="' . e($user['email'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="' . e($user['phone'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label required">Role</label><select class="form-select" name="role_id" required><option value="">— Select role —</option>';
    foreach ($roles as $r) echo '<option value="' . (int) $r['id'] . '" ' . ($selRole === (string) $r['id'] ? 'selected' : '') . '>' . e($r['role_name']) . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id" ' . ($allBranches ? '' : 'disabled') . '><option value="">— Headquarters (all) —</option>';
    foreach ($branches as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($selBranch === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
    if (!$allBranches) echo '<input type="hidden" name="branch_id" value="' . e($selBranch) . '">';
    echo '<div class="form-text">Leave empty only for super administrators.</div></div>';
    echo '<div class="col-md-6"><label class="form-label">Gender</label><select class="form-select" name="gender"><option value="">—</option>';
    foreach (['Male', 'Female', 'Other'] as $g) echo '<option ' . (($user['gender'] ?? '') === $g ? 'selected' : '') . '>' . $g . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($user['status'] ?? 1) === 1 ? 'checked' : '') . '> <span class="small text-muted">Active</span></div></div>';
    if ($allBranches) {
        echo '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="see_all_branches" value="1" ' . ((int) ($user['see_all_branches'] ?? 0) === 1 ? 'checked' : '') . ' id="sab">';
        echo '<label class="form-check-label" for="sab">Allow access to all branches <span class="text-muted small">(super-admin style visibility)</span></label></div></div>';
    }
    echo '<div class="col-12"><hr class="my-1"></div>';
    echo '<div class="col-md-6"><label class="form-label' . ($id ? '' : ' required') . '">Password</label><input type="password" class="form-control" name="password" minlength="6" ' . ($id ? '' : 'required') . ' placeholder="' . ($id ? 'Leave blank to keep current password' : 'Min 6 characters') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Confirm Password</label><input type="password" class="form-control" name="password_confirm" minlength="6"></div>';
    echo '</div></form>';
    $html = ob_get_clean();
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
$action = post('action', 'save');
$id = post('id', '') !== '' ? (int) post('id') : null;

if ($action === 'save') {
    if ($id && !has_permission('users.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if (!$id && !has_permission('users.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);

    $fullName = post('full_name');
    $username = post('username');
    $email = post('email');
    $roleId = (int) post('role_id', '0');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($fullName === '' || $username === '' || $roleId === 0) {
        json_response(['ok' => false, 'message' => 'Full name, username and role are required.']);
    }
    if (!valid_email($email)) json_response(['ok' => false, 'message' => 'Invalid email address.']);
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,60}$/', $username)) {
        json_response(['ok' => false, 'message' => 'Username may only contain letters, numbers, dots, dashes and underscores.']);
    }

    $dupe = db_fetch_value('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id ?? 0], 'si');
    if ($dupe) json_response(['ok' => false, 'message' => 'Username is already taken.']);
    if ($email !== '') {
        $dupe = db_fetch_value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id ?? 0], 'si');
        if ($dupe) json_response(['ok' => false, 'message' => 'Email is already in use.']);
    }
    if ($password !== '' || !$id) {
        if (strlen($password) < 6) json_response(['ok' => false, 'message' => 'Password must be at least 6 characters.']);
        if ($password !== $confirm) json_response(['ok' => false, 'message' => 'Passwords do not match.']);
    }

    $data = [
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email !== '' ? $email : null,
        'phone' => post('phone') !== '' ? post('phone') : null,
        'gender' => post('gender') !== '' ? post('gender') : null,
        'role_id' => $roleId,
        'status' => isset($_POST['status']) ? 1 : 0,
        'see_all_branches' => (sees_all_branches() && isset($_POST['see_all_branches'])) ? 1 : 0,
    ];
    // Branch assignment: only all-branch admins may set it freely.
    if (sees_all_branches()) {
        $data['branch_id'] = post('branch_id') !== '' ? (int) post('branch_id') : null;
    }

    try {
        $savedId = db_transaction(function () use ($data, $id, $password) {
            if ($id) {
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                $params = array_merge(array_values($data), [$id]);
                if ($password !== '') {
                    $set .= ', `password` = ?';
                    $params[] = password_hash($password, PASSWORD_BCRYPT);
                }
                db_execute("UPDATE users SET $set WHERE id = ?", $params);
                audit_log('update', 'User', $id, 'Updated user: ' . $data['username'] . ($password !== '' ? ' (password changed)' : ''));
                return $id;
            }
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $marks = implode(', ', array_fill(0, count($data), '?'));
            $newId = db_execute("INSERT INTO users ($cols) VALUES ($marks)", array_values($data));
            audit_log('create', 'User', $newId, 'Created user: ' . $data['username']);
            notify(['permission_key' => 'users.view'], 'New user created', $data['full_name'] . ' was added as a new user.', 'system', 'user', $newId, '/users');
            return $newId;
        });
        json_response(['ok' => true, 'message' => 'User saved successfully.', 'id' => $savedId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

if ($action === 'toggle') {
    if (!has_permission('users.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $row = db_fetch_one('SELECT id, username, status FROM users WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'User not found.'], 404);
    if ($row['id'] === (int) current_user()['id']) {
        json_response(['ok' => false, 'message' => 'You cannot deactivate your own account.']);
    }
    $new = ((int) $row['status']) === 1 ? 0 : 1;
    db_execute('UPDATE users SET status = ? WHERE id = ?', [$new, $id], 'ii');
    audit_log($new ? 'activate' : 'deactivate', 'User', $id, ($new ? 'Activated' : 'Deactivated') . ' user: ' . $row['username']);
    json_response(['ok' => true, 'message' => 'Status updated.']);
}

if ($action === 'delete') {
    if (!has_permission('users.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if ($id === (int) current_user()['id']) json_response(['ok' => false, 'message' => 'You cannot delete your own account.']);
    $row = db_fetch_one('SELECT username FROM users WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'User not found.'], 404);

    // Safe delete: referenced anywhere?
    $refs = [
        'appointments created by' => ['appointments', 'created_by'],
        'medical records' => ['medical_records', 'created_by'],
        'prescriptions' => ['prescriptions', 'dispensed_by'],
        'invoices' => ['invoices', 'created_by'],
        'payments' => ['payments', 'created_by'],
        'audit logs' => ['audit_logs', 'user_id'],
    ];
    foreach ($refs as $label => [$t, $c]) {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [$id], 'i');
        if ($n > 0) {
            json_response(['ok' => false, 'message' => "This user is referenced by $n $label and cannot be deleted. Deactivate instead."]);
        }
    }
    db_transaction(function () use ($id, $row) {
        db_execute('DELETE FROM users WHERE id = ?', [$id], 'i');
        audit_log('delete', 'User', $id, 'Deleted user: ' . $row['username']);
    });
    json_response(['ok' => true, 'message' => 'User deleted successfully.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
