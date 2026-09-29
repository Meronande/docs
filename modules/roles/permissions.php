<?php
/**
 * /roles/permissions/{id} — assign functionality to a role.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('roles.permissions');

$id = (int) get('id', '0');
$role = db_fetch_one('SELECT * FROM roles WHERE id = ?', [$id], 'i');
if (!$role) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}

$perms = db_fetch_all('SELECT id, permission_name, permission_key, module, description FROM permissions ORDER BY module, permission_name');
$assigned = array_map('intval', array_column(
    db_fetch_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$id], 'i'),
    'permission_id'
));

// Group by module
$byModule = [];
foreach ($perms as $p) {
    $byModule[$p['module']][] = $p;
}

// ---------------------------------------------------------------------
// Save assignment (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $selected = array_map('intval', (array) ($_POST['permissions'] ?? []));
    db_transaction(function () use ($id, $selected, $role) {
        db_execute('DELETE FROM role_permissions WHERE role_id = ?', [$id], 'i');
        foreach ($selected as $pid) {
            $exists = db_fetch_value('SELECT id FROM permissions WHERE id = ?', [$pid], 'i');
            if ($exists) {
                db_execute('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $pid], 'ii');
            }
        }
        audit_log('permission_change', 'Role', $id, 'Updated permissions for role: ' . $role['role_name'] . ' (' . count($selected) . ' granted)');
    });
    notify(['permission_key' => 'roles.permissions'], 'Role permission updated',
        'Permissions for role "' . $role['role_name'] . '" were updated.', 'system', 'role', $id, '/roles');
    // Refresh assigned list
    $assigned = array_map('intval', array_column(
        db_fetch_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$id], 'i'),
        'permission_id'
    ));
    $flashSaved = true;
} else {
    $flashSaved = false;
}

ui_page_open(['title' => 'Assign Functionality — ' . $role['role_name'], 'icon' => 'fa-list-check',
    'breadcrumb' => ['Roles' => '/roles', 'Assign' => null]]);
?>
<?php if ($flashSaved): ?>
  <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i>Permissions updated — the role now has exactly the selected functionality.</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="avatar-lg" style="width:48px;height:48px;font-size:1.2rem;border-radius:12px;"><?= e(mb_strtoupper(mb_substr($role['role_name'], 0, 1))) ?></span>
    <div>
      <div class="fw-bold"><?= e($role['role_name']) ?></div>
      <div class="small text-muted"><?= e(or_na($role['description'])) ?> · <?= count($assigned) ?> of <?= count($perms) ?> permissions assigned</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <span class="badge text-bg-light border"><i class="fa-solid fa-users me-1"></i><?= (int) db_fetch_value('SELECT COUNT(*) FROM users WHERE role_id = ?', [$id], 'i') ?> users with this role</span>
      <a class="btn btn-light" href="/roles"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
    </div>
  </div>
</div>

<form method="post" action="/roles/permissions/<?= $id ?>" id="permForm">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <button type="button" class="btn btn-sm btn-light" id="checkAll"><i class="fa-solid fa-square-check me-1"></i>Select all</button>
    <button type="button" class="btn btn-sm btn-light" id="uncheckAll"><i class="fa-regular fa-square me-1"></i>Clear all</button>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-light" href="/roles/permissions/<?= $id ?>">Reset</a>
      <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-1"></i>Save Permissions</button>
    </div>
  </div>

  <div class="row g-3">
    <?php foreach ($byModule as $module => $list): ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100">
          <div class="card-header py-2 d-flex align-items-center">
            <span class="small fw-bold text-uppercase text-muted"><?= e($module) ?></span>
            <button type="button" class="btn btn-sm btn-link p-0 ms-auto small module-toggle" data-module="<?= e($module) ?>">toggle all</button>
          </div>
          <div class="card-body py-2">
            <?php foreach ($list as $p): ?>
              <div class="form-check py-1">
                <input class="form-check-input perm-cb" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>"
                       id="p<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $assigned, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="p<?= (int) $p['id'] ?>">
                  <span class="small fw-semibold"><?= e($p['permission_name']) ?></span>
                  <span class="d-block text-muted" style="font-size:.7rem"><?= e($p['permission_key']) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-3">
    <a class="btn btn-light" href="/roles">Cancel</a>
    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-1"></i>Save Permissions</button>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('permForm');
  document.getElementById('checkAll')?.addEventListener('click', () => {
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
  });
  document.getElementById('uncheckAll')?.addEventListener('click', () => {
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
  });
  document.querySelectorAll('.module-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.card');
      const boxes = card.querySelectorAll('.perm-cb');
      const allChecked = Array.from(boxes).every(cb => cb.checked);
      boxes.forEach(cb => cb.checked = !allChecked);
    });
  });
});
</script>
<?php
ui_page_close();
