<?php
/**
 * /patients/view/{id} — patient profile & timeline.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('patients.view');

$id = (int) get('id', '0');
$patient = db_fetch_one(
    'SELECT p.*, b.branch_name, ic.company_name
     FROM patients p
     LEFT JOIN branches b ON b.id = p.branch_id
     LEFT JOIN insurance_companies ic ON ic.id = p.insurance_company_id
     WHERE p.id = ?', [$id], 'i');
if (!$patient) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);

$age = $patient['age'] ?? age_from_dob($patient['date_of_birth']);
$appointments = db_fetch_all(
    'SELECT a.*, s.status_name, s.badge_class, d.full_name doctor_name
     FROM appointments a
     LEFT JOIN appointment_statuses s ON s.id = a.status_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 10', [$id], 'i');
$records = db_fetch_all(
    'SELECT m.*, d.full_name doctor_name, dx.diagnosis_name
     FROM medical_records m
     LEFT JOIN doctors d ON d.id = m.doctor_id
     LEFT JOIN diagnoses dx ON dx.id = m.diagnosis_id
     WHERE m.patient_id = ? ORDER BY m.id DESC LIMIT 10', [$id], 'i');
$prescriptions = db_fetch_all('SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY id DESC LIMIT 10', [$id], 'i');
$labOrders = db_fetch_all('SELECT * FROM lab_orders WHERE patient_id = ? ORDER BY id DESC LIMIT 10', [$id], 'i');
$invoices = db_fetch_all('SELECT * FROM invoices WHERE patient_id = ? ORDER BY id DESC LIMIT 10', [$id], 'i');
$payments = db_fetch_all('SELECT * FROM payments WHERE patient_id = ? ORDER BY payment_date DESC LIMIT 10', [$id], 'i');

ui_page_open(['title' => $patient['full_name'], 'icon' => 'fa-hospital-user',
    'breadcrumb' => ['Patients' => '/patients', $patient['patient_code'] ?? 'Profile' => null]]);
?>
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body text-center">
      <?php if ($patient['photo']): ?>
        <img src="/<?= e($patient['photo']) ?>" class="avatar-lg mb-2" alt="photo">
      <?php else: ?>
        <span class="avatar-lg mb-2"><?= e(mb_strtoupper(mb_substr($patient['full_name'], 0, 1))) ?></span>
      <?php endif; ?>
      <h5 class="fw-bold mb-0"><?= e($patient['full_name']) ?></h5>
      <div class="text-muted small mb-3"><?= e($patient['patient_code'] ?? '') ?></div>
      <?= status_badge($patient['status']) ?>
      <hr>
      <div class="text-start small">
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Gender</span><span><?= e(or_na($patient['gender'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Date of Birth</span><span><?= fmt_date($patient['date_of_birth']) ?><?= $age !== null ? ' (' . $age . ' yrs)' : '' ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Blood Group</span><span><?= e(or_na($patient['blood_group'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Marital Status</span><span><?= e(or_na($patient['marital_status'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Occupation</span><span><?= e(or_na($patient['occupation'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Nationality</span><span><?= e(or_na($patient['nationality'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Phone</span><span><?= e(or_na($patient['phone'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Email</span><span><?= e(or_na($patient['email'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Address</span><span class="text-end"><?= e(or_na($patient['address'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Emergency</span><span><?= e(or_na($patient['emergency_contact'])) ?> <?= e(or_na($patient['emergency_phone'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Branch</span><span><?= e(or_na($patient['branch_name'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Insurance</span><span><?= e(or_na($patient['company_name'])) ?> <?= e(or_na($patient['insurance_number'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Registered</span><span><?= fmt_date($patient['created_at'], true) ?></span></div>
      </div>
      <hr>
      <div class="d-grid gap-2">
        <?php if (has_permission('appointments.create')): ?>
          <a class="btn btn-sm btn-brand" href="/appointments/create?patient_id=<?= $id ?>"><i class="fa-solid fa-calendar-plus me-1"></i>Book Appointment</a>
        <?php endif; ?>
        <?php if (has_permission('medical.create')): ?>
          <a class="btn btn-sm btn-outline-brand" href="/medical/create?patient_id=<?= $id ?>"><i class="fa-solid fa-file-medical me-1"></i>New Consultation</a>
        <?php endif; ?>
        <?php if (has_permission('billing.create')): ?>
          <a class="btn btn-sm btn-outline-brand" href="/billing/create?patient_id=<?= $id ?>"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Create Invoice</a>
        <?php endif; ?>
      </div>
    </div></div>
  </div>

  <div class="col-lg-8">
    <?php
    $sections = [
        ['fa-calendar-check', 'Appointments', $appointments, function ($a) {
            return [
                fmt_date($a['appointment_date']) . ' ' . date('H:i', strtotime($a['appointment_time'])),
                e($a['appointment_code'] ?? ''),
                ($a['doctor_name'] ? 'Dr. ' . $a['doctor_name'] : 'N/A'),
                '<span class="badge" style="background:#eef2f7;color:#475569">' . e($a['status_name'] ?? 'N/A') . '</span>',
                has_permission('appointments.view') ? '<a class="btn btn-sm btn-light" href="/appointments/view/' . (int) $a['id'] . '"><i class="fa-solid fa-eye"></i></a>' : '',
            ];
        }],
        ['fa-file-medical', 'Medical Records', $records, function ($m) {
            return [
                fmt_date($m['created_at'], true),
                e($m['chief_complaint'] ?: 'N/A'),
                e($m['diagnosis_name'] ?? 'N/A'),
                e($m['doctor_name'] ? 'Dr. ' . $m['doctor_name'] : 'N/A'),
                has_permission('medical.view') ? '<a class="btn btn-sm btn-light" href="/medical/view/' . (int) $m['id'] . '"><i class="fa-solid fa-eye"></i></a>' : '',
            ];
        }],
        ['fa-prescription', 'Prescriptions', $prescriptions, function ($rx) {
            $badge = ['pending' => 'text-bg-warning', 'dispensed' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$rx['status']] ?? 'text-bg-secondary';
            return [
                fmt_date($rx['created_at'], true),
                e($rx['prescription_code'] ?? ''),
                ucfirst($rx['status']),
                has_permission('prescriptions.view') ? '<a class="btn btn-sm btn-light" href="/prescriptions/view/' . (int) $rx['id'] . '"><i class="fa-solid fa-eye"></i></a>' : '',
            ];
        }],
        ['fa-flask-vial', 'Laboratory Orders', $labOrders, function ($lo) {
            $badge = ['pending' => 'text-bg-warning', 'in_progress' => 'text-bg-info', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$lo['status']] ?? 'text-bg-secondary';
            return [
                fmt_date($lo['created_at'], true),
                e($lo['order_code'] ?? ''),
                '<span class="badge ' . $badge . '">' . label_case($lo['status']) . '</span>',
                has_permission('laboratory.view') ? '<a class="btn btn-sm btn-light" href="/laboratory/view/' . (int) $lo['id'] . '"><i class="fa-solid fa-eye"></i></a>' : '',
            ];
        }],
        ['fa-file-invoice-dollar', 'Invoices', $invoices, function ($inv) {
            $badge = ['unpaid' => 'text-bg-danger', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$inv['status']] ?? 'text-bg-secondary';
            return [
                e($inv['invoice_number'] ?? ''),
                fmt_date($inv['created_at']),
                money($inv['total']),
                '<span class="badge ' . $badge . '">' . ucfirst($inv['status']) . '</span>',
                has_permission('billing.view') ? '<a class="btn btn-sm btn-light" href="/billing/view/' . (int) $inv['id'] . '"><i class="fa-solid fa-eye"></i></a>' : '',
            ];
        }],
        ['fa-money-bill-wave', 'Payments', $payments, function ($pay) {
            return [
                fmt_date($pay['payment_date'], true),
                e($pay['payment_code'] ?? ''),
                money($pay['amount']),
                or_na($pay['reference_number']),
            ];
        }],
    ];
    foreach ($sections as [$icon, $title, $data, $render]):
    ?>
    <div class="card mb-3">
      <div class="card-header py-2"><i class="fa-solid <?= e($icon) ?> me-2 text-secondary"></i><?= e($title) ?>
        <span class="badge text-bg-light border ms-1"><?= count($data) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if (!$data): ?>
            <tr><td class="text-center text-muted py-3">No data yet</td></tr>
          <?php endif; ?>
          <?php foreach ($data as $row): ?>
            <tr>
              <?php foreach ($render($row) as $cell) echo '<td class="small">' . $cell . '</td>'; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
ui_page_close();
