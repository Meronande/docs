<?php
/**
 * Login page.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    redirect('/dashboard');
}

$error = null;
$info = null;
if (isset($_GET['timeout'])) {
    $info = 'Your session expired due to inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = post('username');
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            $err = attempt_login($username, $password);
            if ($err !== null) {
                $error = $err;
            } else {
                $returnTo = $_POST['returnTo'] ?? '/dashboard';
                if (!is_string($returnTo) || $returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
                    $returnTo = '/dashboard';
                }
                redirect($returnTo);
            }
        }
    }
}

$returnTo = get('returnTo', '/dashboard');
if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
    $returnTo = '/dashboard';
}

$clinicName = setting('clinic_name', 'Clinic Management System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — <?= e($clinicName) ?></title>
<link rel="manifest" href="<?= e(base_url()) ?>/manifest.php">
<meta name="theme-color" content="#0d8a80">
<link rel="icon" href="<?= e(base_url()) ?>/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(base_url()) ?>/assets/icons/icon-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
  body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
  .login-hero {
    background: linear-gradient(150deg, #0b6e6e 0%, #0d8a80 55%, #12b5a2 100%);
    color: #fff; min-height: 100vh;
    display: flex; flex-direction: column; justify-content: center; padding: 3rem;
  }
  .hero-badge {
    width: 74px; height: 74px; border-radius: 20px; background: rgba(255,255,255,.16);
    display: flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 1.5rem;
    backdrop-filter: blur(4px);
  }
  .hero-list li { margin-bottom: .65rem; opacity: .95; }
  .login-card { border: 0; border-radius: 1.1rem; box-shadow: 0 18px 50px rgba(13, 60, 60, .18); }
  .form-control, .input-group-text { border-radius: .6rem; }
  .btn-login { background: #0d8a80; border: 0; border-radius: .6rem; padding: .7rem; font-weight: 600; }
  .btn-login:hover { background: #0b6e6e; }
  .demo-box { background: #eef7f6; border: 1px dashed #9fd0ca; border-radius: .6rem; }
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <div class="col-lg-6 d-none d-lg-flex login-hero">
      <div>
        <div class="hero-badge"><i class="fa-solid fa-heart-pulse"></i></div>
        <h1 class="fw-bold mb-3"><?= e($clinicName) ?></h1>
        <p class="lead mb-4" style="opacity:.92">One platform for patients, appointments, queue, pharmacy, laboratory, billing and reports — across all your branches.</p>
        <ul class="list-unstyled hero-list">
          <li><i class="fa-solid fa-circle-check me-2"></i> Multi-branch, role &amp; permission driven</li>
          <li><i class="fa-solid fa-circle-check me-2"></i> Live queue &amp; automatic notifications</li>
          <li><i class="fa-solid fa-circle-check me-2"></i> EMR, prescriptions, lab &amp; pharmacy workflows</li>
          <li><i class="fa-solid fa-circle-check me-2"></i> Billing, insurance, expenses &amp; reports</li>
        </ul>
      </div>
    </div>
    <div class="col-lg-6 d-flex align-items-center justify-content-center" style="min-height:100vh; background:#f7f9fb;">
      <div class="card login-card p-4 p-md-5" style="width:100%;max-width:430px;">
        <div class="text-center mb-4">
          <div class="d-lg-none mx-auto mb-3" style="width:60px;height:60px;border-radius:16px;background:#0d8a80;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.6rem;">
            <i class="fa-solid fa-heart-pulse"></i>
          </div>
          <h4 class="fw-bold mb-1">Welcome back</h4>
          <p class="text-muted mb-0">Sign in to your account to continue</p>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($info): ?>
          <div class="alert alert-info py-2"><i class="fa-solid fa-circle-info me-2"></i><?= e($info) ?></div>
        <?php endif; ?>

        <form method="post" action="/login" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="returnTo" value="<?= e($returnTo) ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Username or Email</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="fa-solid fa-user text-muted"></i></span>
              <input type="text" class="form-control" name="username" value="<?= e(post('username')) ?>" required autofocus>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label small fw-semibold">Password</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="fa-solid fa-lock text-muted"></i></span>
              <input type="password" class="form-control" name="password" id="password" required>
              <button class="btn btn-outline-secondary" type="button" id="togglePass" tabindex="-1"><i class="fa-solid fa-eye"></i></button>
            </div>
          </div>
          <button type="submit" class="btn btn-login w-100 text-white">
            <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
          </button>
        </form>

        <div class="demo-box p-3 mt-4 small text-muted">
          <i class="fa-solid fa-key me-1 text-success"></i>
          <strong>Default administrator:</strong> username <code>admin</code> &nbsp;•&nbsp; password <code>Admin@123</code>
          <div class="mt-1">Change this password after first login (Profile → Security).</div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('togglePass').addEventListener('click', function () {
  const input = document.getElementById('password');
  const icon = this.querySelector('i');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
  else { input.type = 'password'; icon.className = 'fa-solid fa-eye'; }
});
</script>
</body>
</html>
