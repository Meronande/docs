<?php
/**
 * Global search (/search?q=) — patients, doctors, appointments, invoices, medicines.
 * Header topbar form posts here.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_permission('search.global');

$q = get('q', '');
$like = '%' . $q . '%';
$found = [];

if ($q !== '') {
    $bPatients = scope_branch_id() !== null ? ' AND branch_id = ' . scope_branch_id() : '';
    $bGeneric = scope_branch_id() !== null ? ' AND branch_id = ' . scope_branch_id() : '';

    // Patients
    $rows = db_fetch_all(
        "SELECT id, patient_code, full_name, phone FROM patients
         WHERE (full_name LIKE ? OR patient_code LIKE ? OR phone LIKE ?)$bPatients LIMIT 8",
        [$like, $like, $like]
    );
    if ($rows) $found['Patients'] = array_map(function ($r) {
        return [
            'icon' => 'fa-hospital-user', 'title' => $r['full_name'], 'sub' => ($r['patient_code'] ?? '') . ($r['phone'] ? ' · ' . $r['phone'] : ''),
            'url' => '/patients/view/' . (int) $r['id'],
        ];
    }, $rows);

    // Doctors
    $rows = db_fetch_all(
        'SELECT d.id, d.full_name, s.name specs
         FROM doctors d LEFT JOIN specializations s ON s.id = d.specialization_id
         WHERE d.full_name LIKE ? GROUP BY d.id, d.full_name, s.name LIMIT 8',
        [$like]
    );
    if ($rows) $found['Doctors'] = array_map(function ($r) {
        return [
            'icon' => 'fa-user-doctor', 'title' => 'Dr. ' . $r['full_name'], 'sub' => $r['specs'] ?: 'General practice',
            'url' => '/doctors',
        ];
    }, $rows); // doctors module has no detail page

    // Appointments
    $rows = db_fetch_all(
        "SELECT a.id, a.appointment_code, a.appointment_date, p.full_name patient_name
         FROM appointments a JOIN patients p ON p.id = a.patient_id
         WHERE (a.appointment_code LIKE ? OR p.full_name LIKE ?)$bGeneric LIMIT 8",
        [$like, $like]
    );
    if ($rows) $found['Appointments'] = array_map(function ($r) {
        return [
            'icon' => 'fa-calendar-check', 'title' => ($r['appointment_code'] ?? '#' . $r['id']) . ' — ' . $r['patient_name'],
            'sub' => fmt_date($r['appointment_date'], true), 'url' => '/appointments/view/' . (int) $r['id'],
        ];
    }, $rows);

    // Invoices
    $rows = db_fetch_all(
        "SELECT i.id, i.invoice_number, i.total, i.status, p.full_name patient_name
         FROM invoices i JOIN patients p ON p.id = i.patient_id
         WHERE (i.invoice_number LIKE ? OR p.full_name LIKE ?)$bGeneric LIMIT 8",
        [$like, $like]
    );
    if ($rows) $found['Invoices'] = array_map(function ($r) {
        return [
            'icon' => 'fa-file-invoice-dollar', 'title' => ($r['invoice_number'] ?? '#' . $r['id']) . ' — ' . $r['patient_name'],
            'sub' => money($r['total']) . ' · ' . label_case($r['status']), 'url' => '/billing/view/' . (int) $r['id'],
        ];
    }, $rows);

    // Medicines
    $rows = db_fetch_all(
        "SELECT id, medicine_name, generic_name, stock_quantity FROM medicines
         WHERE (medicine_name LIKE ? OR generic_name LIKE ?)$bGeneric LIMIT 8",
        [$like, $like]
    );
    if ($rows) $found['Medicines'] = array_map(function ($r) {
        return [
            'icon' => 'fa-pills', 'title' => $r['medicine_name'],
            'sub' => ($r['generic_name'] ? $r['generic_name'] . ' · ' : '') . 'Stock: ' . (int) $r['stock_quantity'],
            'url' => '/pharmacy',
        ];
    }, $rows);
}

ui_page_open(['title' => 'Search', 'icon' => 'fa-magnifying-glass', 'breadcrumb' => ['Search' => null]]);
?>
<div class="row justify-content-center">
  <div class="col-xl-9">
    <form method="get" action="/search" class="d-flex gap-2 mb-4">
      <div class="input-group input-group-lg">
        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
        <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search patients, doctors, appointments, invoices, medicines..." autofocus>
        <button class="btn btn-brand" type="submit">Search</button>
      </div>
    </form>

    <?php if ($q === ''): ?>
      <div class="text-center text-muted py-5">
        <i class="fa-solid fa-keyboard fa-2x mb-3 d-block"></i>
        Type a name, code, phone number or invoice number to search across the system.
      </div>
    <?php elseif (!$found): ?>
      <div class="text-center text-muted py-5">
        <i class="fa-solid fa-face-frown fa-2x mb-3 d-block"></i>
        No results for <strong>“<?= e($q) ?>”</strong>
      </div>
    <?php else: ?>
      <?php foreach ($found as $group => $items): ?>
        <div class="card mb-3">
          <div class="card-header py-2"><i class="fa-solid fa-folder me-2 text-brand"></i><?= e($group) ?>
            <span class="badge text-bg-light border ms-1"><?= count($items) ?></span></div>
          <div class="list-group list-group-flush">
            <?php foreach ($items as $item): ?>
              <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?= e($item['url']) ?>">
                <span class="stat-icon bg-soft-brand" style="width:38px;height:38px;font-size:1rem"><i class="fa-solid <?= e($item['icon']) ?>"></i></span>
                <span class="flex-grow-1">
                  <span class="d-block fw-semibold"><?= e($item['title']) ?></span>
                  <span class="d-block small text-muted"><?= e((string) $item['sub']) ?></span>
                </span>
                <i class="fa-solid fa-chevron-right text-muted small"></i>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php
ui_page_close();
