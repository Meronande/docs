<?php
/**
 * Header include. Expects optional: $pageTitle, $breadcrumb (array label=>url|null), $pageIcon
 */
if (!defined('APP_BOOT')) {
    require_once dirname(__DIR__) . '/config/auth.php';
    require_login();
}
$me = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
$breadcrumb = $breadcrumb ?? [];
$pageIcon = $pageIcon ?? 'fa-gauge-high';
$clinicName = setting('clinic_name', 'Clinic');
$unread = unread_count();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> — <?= e($clinicName) ?></title>
<link rel="manifest" href="<?= e(base_url()) ?>/manifest.php">
<meta name="theme-color" content="#0d8a80">
<link rel="icon" href="<?= e(base_url()) ?>/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(base_url()) ?>/assets/icons/icon-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="/assets/css/style.css" rel="stylesheet">
</head>
<body class="app-body">

<nav class="topbar navbar navbar-expand navbar-light bg-white sticky-top shadow-sm">
  <div class="container-fluid px-3 px-lg-4">
    <button class="btn btn-link text-secondary p-0 me-2 me-lg-3" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars fs-5"></i>
    </button>
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2 me-lg-4" href="/dashboard" style="color:var(--brand-dark)">
      <span class="brand-badge"><i class="fa-solid fa-heart-pulse"></i></span>
      <span class="d-none d-md-inline"><?= e($clinicName) ?></span>
    </a>

    <form class="d-none d-xl-flex ms-xl-2 flex-grow-1" action="/search" method="get" role="search" style="max-width:520px">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
        <input type="search" name="q" class="form-control border-start-0" placeholder="Global search — patients, doctors, invoices, medicines..." value="<?= e(get('q')) ?>" <?= !has_permission('search.global') ? 'disabled' : '' ?>>
      </div>
    </form>

    <div class="ms-auto d-flex align-items-center gap-2 gap-lg-3">
      <?php if (sees_all_branches()): ?>
      <div class="d-none d-md-block dropdown">
        <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown" type="button">
          <i class="fa-solid fa-location-dot me-1 text-muted"></i><?= e(setting('clinic_name')) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="px-3 py-1 small text-muted">Use the branch filter on each page &amp; dashboard.</li>
          <li><a class="dropdown-item" href="/branches"><i class="fa-solid fa-building me-2"></i>Manage branches</a></li>
        </ul>
      </div>
      <?php endif; ?>

      <!-- Help -->
      <a class="btn btn-link text-secondary p-1" href="/help" title="Help & PDF guides" aria-label="Help">
        <i class="fa-regular fa-circle-question fs-5"></i>
      </a>

      <!-- Install app (PWA) -->
      <button class="btn btn-sm btn-light border d-none align-middle" id="pwaInstallBtn" type="button" title="Install app on this device">
        <i class="fa-solid fa-download me-1 text-brand"></i><span class="d-none d-md-inline">Install App</span>
      </button>

      <!-- Notification bell -->
      <div class="dropdown" id="notifDropdown">
        <button class="btn btn-link text-secondary position-relative p-1" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="notifBell">
          <i class="fa-regular fa-bell fs-5"></i>
          <span class="notif-badge <?= $unread > 0 ? '' : 'd-none' ?>" id="notifCount"><?= (int) $unread > 99 ? '99+' : (int) $unread ?></span>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-menu shadow border-0 p-0" style="min-width:360px">
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <strong>Notifications</strong>
            <button class="btn btn-sm btn-link text-decoration-none p-0" id="notifMarkAll">Mark all read</button>
          </div>
          <div class="notif-list" id="notifList" style="max-height:360px;overflow-y:auto">
            <div class="text-center text-muted py-4 small"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</div>
          </div>
          <a class="dropdown-item text-center border-top py-2 small" href="#" id="notifRefresh">Refresh</a>
        </div>
      </div>

      <!-- User menu -->
      <div class="dropdown">
        <button class="btn btn-link d-flex align-items-center gap-2 text-decoration-none p-1" data-bs-toggle="dropdown" type="button">
          <span class="avatar-sm"><?= e(mb_strtoupper(mb_substr($me['full_name'] ?? 'U', 0, 1))) ?></span>
          <span class="d-none d-lg-block text-start lh-sm">
            <span class="d-block fw-semibold small"><?= e($me['full_name']) ?></span>
            <span class="d-block text-muted" style="font-size:.72rem"><?= e($me['role_name']) ?></span>
          </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="px-3 py-2 border-bottom">
            <div class="fw-semibold small"><?= e($me['full_name']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= e($me['email'] ?: $me['username']) ?></div>
          </li>
          <li><a class="dropdown-item" href="/profile"><i class="fa-regular fa-user me-2"></i>My Profile</a></li>
          <?php if (has_permission('settings.view')): ?>
          <li><a class="dropdown-item" href="/settings"><i class="fa-solid fa-gear me-2"></i>System Settings</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="/logout"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>
