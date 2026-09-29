<?php
/**
 * Footer include. Expects optional: $pageTitle, $breadcrumb, $pageIcon (same as header).
 */
$flashes = take_flashes();
?>
<div class="app-content pt-3 pt-lg-4 px-3 px-lg-4 pb-5">
  <?php if (!empty($breadcrumb)): ?>
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="/dashboard">Home</a></li>
      <?php foreach ($breadcrumb as $label => $url): ?>
        <?php if ($url): ?>
          <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e(is_int($label) ? $label : $label) ?></a></li>
        <?php else: ?>
          <li class="breadcrumb-item active" aria-current="page"><?= e(is_int($label) ? $label : $label) ?></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </nav>
  <?php endif; ?>
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0 d-flex align-items-center gap-2">
      <span class="page-icon"><i class="fa-solid <?= e($pageIcon ?? 'fa-gauge-high') ?>"></i></span><?= e($pageTitle ?? '') ?>
    </h1>
    <div class="d-flex gap-2 flex-wrap" id="pageActions"></div>
  </div>

  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
      <i class="fa-solid <?= (['success' => 'fa-circle-check', 'danger' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'][$f['type']] ?? 'fa-bell') ?> me-2"></i>
      <?= e($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>
</div>

<footer class="app-footer text-center text-muted small py-3 px-3">
  <?= e(setting('clinic_name', 'Clinic')) ?> — Clinic Management System ·
  <?= date('Y') ?> · Server time <?= date('H:i') ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>window.__APP = { user: <?= json_encode(['id' => (int) current_user()['id'], 'name' => current_user()['full_name']]) ?> };</script>
<script src="/assets/js/app.js"></script>
<script>
/* PWA: service worker + install prompt ------------------------------------ */
(function () {
  'use strict';
  var PWA_BASE = <?= json_encode(base_url()) ?>;

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(PWA_BASE + '/sw.php').catch(function () { /* offline mode unavailable */ });
    });
  }

  var deferredPrompt = null;
  var installBtn = document.getElementById('pwaInstallBtn');

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  window.addEventListener('beforeinstallprompt', function (ev) {
    ev.preventDefault();
    deferredPrompt = ev;
    var btn = document.getElementById('pwaInstallBtn');
    if (btn && !isStandalone()) btn.classList.remove('d-none');
  });

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('#pwaInstallBtn');
    if (!btn || !deferredPrompt) return;
    ev.preventDefault();
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function () { deferredPrompt = null; btn.classList.add('d-none'); });
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    var btn = document.getElementById('pwaInstallBtn');
    if (btn) btn.classList.add('d-none');
    if (window.App && App.toast) App.toast('App installed! Launch it from your home screen or desktop.', 'success');
  });

  if (installBtn && isStandalone()) installBtn.classList.add('d-none');
})();
</script>
</body>
</html>
