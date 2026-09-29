<?php
/**
 * /profile — own profile & password change.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_login();

$me = current_user();
$userId = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'profile') {
        $fullName = post('full_name');
        $email = post('email');
        $phone = post('phone');
        if ($fullName === '') { flash('danger', 'Name is required.'); redirect('/profile'); }
        if (!valid_email($email)) { flash('danger', 'Please enter a valid email address.'); redirect('/profile'); }
        db_execute('UPDATE users SET full_name = ?, email = ?, phone = ?, gender = ?, address = ? WHERE id = ?',
            [$fullName, $email ?: null, $phone ?: null, post('gender') ?: null, post('address') ?: null, $userId], 'sssssi');
        if (!empty($_FILES['profile_image']['name']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $old = db_fetch_value('SELECT profile_image FROM users WHERE id = ?', [$userId], 'i');
                $path = upload_image($_FILES['profile_image'], 'profiles');
                if ($path) {
                    db_execute('UPDATE users SET profile_image = ? WHERE id = ?', [$path, $userId], 'si');
                    if ($old) delete_upload((string) $old);
                }
            } catch (Throwable $ex) {
                flash('warning', 'Photo was not saved: ' . $ex->getMessage());
            }
        }
        audit_log('update', 'Profile', $userId, 'Updated own profile');
        flash('success', 'Profile updated.');
        redirect('/profile');
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) { flash('danger', 'New passwords do not match.'); redirect('/profile'); }
        if (strlen($new) < 8) { flash('danger', 'New password must be at least 8 characters.'); redirect('/profile'); }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            flash('danger', 'New password must include letters and numbers.'); redirect('/profile');
        }
        $row = db_fetch_one('SELECT password FROM users WHERE id = ?', [$userId], 'i');
        if (!$row || !password_verify($current, $row['password'])) {
            flash('danger', 'Current password is incorrect.'); redirect('/profile');
        }
        if (password_verify($new, $row['password'])) {
            flash('warning', 'New password must be different from the current one.'); redirect('/profile');
        }
        db_execute('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId], 'si');
        audit_log('password_change', 'Profile', $userId, 'Changed own password');
        notify(['user_id' => $userId], 'Password changed', 'Your account password was changed successfully.', 'success', 'profile', $userId, '/profile');
        flash('success', 'Password changed successfully.');
        redirect('/profile');
    }
    redirect('/profile');
}

$user = db_fetch_one(
    'SELECT u.*, r.role_name, b.branch_name FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     LEFT JOIN branches b ON b.id = u.branch_id
     WHERE u.id = ?',
    [$userId], 'i'
);
$perms = user_permissions($userId);

ui_page_open(['title' => 'My Profile', 'icon' => 'fa-user', 'breadcrumb' => ['Profile' => null]]);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card text-center">
      <div class="card-body p-4">
        <div class="position-relative d-inline-block">
          <?php if (!empty($user['profile_image'])): ?>
            <img src="/<?= e($user['profile_image']) ?>" class="rounded-circle border shadow-sm" style="width:110px;height:110px;object-fit:cover" alt="avatar">
          <?php else: ?>
            <div class="rounded-circle d-flex align-items-center justify-content-center text-white mx-auto shadow-sm"
                 style="width:110px;height:110px;font-size:2.4rem;background:#0d8a80">
              <?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?>
            </div>
          <?php endif; ?>
        </div>
        <h5 class="fw-bold mt-3 mb-0"><?= e($user['full_name']) ?></h5>
        <div class="text-muted small">@<?= e($user['username']) ?></div>
        <div class="mt-2">
          <span class="badge text-bg-light border"><?= e($user['role_name'] ?? 'N/A') ?></span>
          <?php if ($user['branch_name']): ?><span class="badge text-bg-light border"><?= e($user['branch_name']) ?></span><?php endif; ?>
        </div>
        <hr class="my-3">
        <div class="text-start small">
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Email</span><span><?= e(or_na($user['email'])) ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Phone</span><span><?= e(or_na($user['phone'])) ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Last Login</span><span><?= $user['last_login'] ? fmt_date($user['last_login'], true) : '—' ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Status</span><?= status_badge($user['status']) ?></div>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header py-2"><i class="fa-solid fa-key me-2 text-brand"></i>Permissions <span class="badge text-bg-light border ms-1"><?= count($perms) ?></span></div>
      <div class="card-body py-2" style="max-height:260px;overflow:auto">
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($perms as $p): ?><span class="badge text-bg-light border small fw-normal"><?= e((string) $p) ?></span><?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header py-2"><i class="fa-solid fa-user-pen me-2 text-brand"></i>Profile Information</div>
      <div class="card-body">
        <form method="post" action="/profile" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label required">Full Name</label>
              <input class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" value="<?= e($user['email'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Phone</label>
              <input class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Gender</label>
              <select class="form-select" name="gender">
                <option value="">— Select —</option>
                <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                  <option <?= ($user['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-12"><label class="form-label">Address</label>
              <input class="form-control" name="address" value="<?= e($user['address'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Profile Photo</label>
              <input type="file" class="form-control" name="profile_image" accept="image/png,image/jpeg,image/webp"></div>
          </div>
          <button type="submit" class="btn btn-brand mt-3"><i class="fa-solid fa-floppy-disk me-1"></i>Save Profile</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2"><i class="fa-solid fa-lock me-2 text-brand"></i>Change Password</div>
      <div class="card-body">
        <form method="post" action="/profile">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label required">Current Password</label>
              <input type="password" class="form-control" name="current_password" required autocomplete="current-password"></div>
            <div class="col-md-4"><label class="form-label required">New Password</label>
              <input type="password" class="form-control" name="new_password" required minlength="8" autocomplete="new-password">
              <div class="form-text small">At least 8 chars with letters &amp; numbers.</div></div>
            <div class="col-md-4"><label class="form-label required">Confirm New Password</label>
              <input type="password" class="form-control" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
          </div>
          <button type="submit" class="btn btn-brand mt-3"><i class="fa-solid fa-key me-1"></i>Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
ui_page_close();
