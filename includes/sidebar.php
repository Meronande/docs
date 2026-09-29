<?php
/**
 * Dynamic sidebar generated from permissions.
 */
if (!defined('APP_BOOT')) {
    require_once dirname(__DIR__) . '/config/auth.php';
    require_login();
}

$currentUri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

/** Menu definition: [permission, label, url, icon, children?] */
$menu = [
    ['dashboard.view', 'Main', null, null, [
        ['dashboard.view', 'Dashboard', '/dashboard', 'fa-gauge-high'],
    ]],
    ['patients.view', 'Front Desk', null, null, [
        ['patients.view', 'Patients', '/patients', 'fa-hospital-user'],
        ['patients.create', 'Register Patient', '/patients?new=1', 'fa-user-plus'],
        ['appointments.view', 'Appointments', '/appointments', 'fa-calendar-check'],
        ['appointments.create', 'New Appointment', '/appointments?new=1', 'fa-calendar-plus'],
        ['queue.view', 'Live Queue', '/queue', 'fa-list-ol'],
    ]],
    ['medical.view', 'Clinical', null, null, [
        ['medical.view', 'Medical Records', '/medical', 'fa-file-medical'],
        ['prescriptions.view', 'Prescriptions', '/prescriptions', 'fa-prescription'],
        ['laboratory.view', 'Laboratory', '/laboratory', 'fa-flask-vial'],
        ['medical.view', 'Diagnoses', '/diagnoses', 'fa-stethoscope'],
    ]],
    ['pharmacy.view', 'Pharmacy', null, null, [
        ['pharmacy.view', 'Medicines & Stock', '/pharmacy', 'fa-pills'],
        ['pharmacy.sell', 'Dispensing & Sales', '/pharmacy/sales', 'fa-bag-shopping'],
        ['pharmacy.create', 'Prescriptions to Dispense', '/pharmacy/pending', 'fa-clipboard-check'],
    ]],
    ['billing.view', 'Finance', null, null, [
        ['billing.view', 'Invoices', '/billing', 'fa-file-invoice-dollar'],
        ['billing.create', 'New Invoice', '/billing/create', 'fa-file-circle-plus'],
        ['payments.view', 'Payments', '/payments', 'fa-money-bill-wave'],
        ['insurance.view', 'Insurance', '/insurance', 'fa-shield-halved'],
        ['expenses.view', 'Expenses', '/expenses', 'fa-receipt'],
    ]],
    ['doctors.view', 'People', null, null, [
        ['doctors.view', 'Doctors', '/doctors', 'fa-user-doctor'],
        ['staff.view', 'Staff (HR)', '/staff', 'fa-users'],
        ['departments.view', 'Departments', '/departments', 'fa-sitemap'],
        ['specializations.view', 'Specializations', '/specializations', 'fa-award'],
    ]],
    ['users.view', 'Administration', null, null, [
        ['users.view', 'Users', '/users', 'fa-users-gear'],
        ['branches.view', 'Branches', '/branches', 'fa-building'],
        ['roles.view', 'Roles & Permissions', '/roles', 'fa-user-shield'],
        ['masterdata.view', 'Master Data', '/masterdata', 'fa-database'],
        ['reports.view', 'Reports', '/reports', 'fa-chart-line'],
        ['audit.view', 'Audit Log', '/audit', 'fa-clipboard-list'],
        ['settings.view', 'System Settings', '/settings', 'fa-gear'],
    ]],
];
?>
<div class="app-sidebar" id="appSidebar">
  <div class="sidebar-head d-none d-lg-flex align-items-center gap-2 px-3">
    <span class="brand-badge"><i class="fa-solid fa-heart-pulse"></i></span>
    <div class="lh-sm">
      <div class="fw-bold sidebar-title"><?= e(setting('clinic_name', 'Clinic')) ?></div>
      <div class="sidebar-sub">Management System</div>
    </div>
  </div>
  <ul class="sidebar-nav">
    <?php foreach ($menu as $group): ?>
      <?php
        [$groupPerm] = $group;
        if (!has_permission($groupPerm)) continue;
        $children = array_values(array_filter($group[4], fn($c) => has_permission($c[0])));
        if (!$children) continue;
        $groupActive = false;
        foreach ($children as $c) { if (str_starts_with($currentUri, $c[2])) { $groupActive = true; break; } }
      ?>
      <li class="sidebar-group <?= $groupActive ? 'open' : '' ?>">
        <div class="sidebar-group-label"><?= e($group[1]) ?></div>
        <ul>
          <?php foreach ($children as $c): ?>
            <?php [$perm, $label, $url, $icon] = $c;
              $active = $currentUri === $url || str_starts_with($currentUri, $url . '/'); ?>
            <li>
              <a href="<?= e($url) ?>" class="<?= $active ? 'active' : '' ?>">
                <i class="fa-solid <?= e($icon) ?> fa-fw"></i><span><?= e($label) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="sidebar-foot">
    <div class="small opacity-75 px-3 pb-3">
      <i class="fa-solid fa-circle-info me-1"></i>Logged in as<br><strong><?= e(current_user()['full_name']) ?></strong>
    </div>
  </div>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
