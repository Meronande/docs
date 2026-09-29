<?php
/**
 * /appointments/view/{id} — appointment detail.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('appointments.view');

$id = (int) get('id', '0');
$a = db_fetch_one(
    'SELECT a.*, p.full_name patient_name, p.patient_code, p.phone patient_phone, p.photo patient_photo,
            d.full_name doctor_name, d.consultation_fee, dep.department_name, t.type_name,
            s.status_name, s.badge_class, s.is_final, b.branch_name,
            u.full_name created_by_name
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN departments dep ON dep.id = a.department_id
     LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
     LEFT JOIN appointment_statuses s ON s.id = a.status_id
     LEFT JOIN branches b ON b.id = a.branch_id
     LEFT JOIN users u ON u.id = a.created_by
     WHERE a.id = ?', [$id], 'i');
if (!$a) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
assert_branch_access($a['branch_id'] !== null ? (int) $a['branch_id'] : null);

$statuses = db_fetch_all('SELECT id, status_name, is_final FROM appointment_statuses WHERE status = 1 ORDER BY sort_order');
$queueRow = db_fetch_one('SELECT * FROM queue WHERE appointment_id = ? ORDER BY id DESC LIMIT 1', [$id], 'i');

ui_page_open(['title' => 'Appointment ' . ($a['appointment_code'] ?? '#' . $id), 'icon' => 'fa-calendar-check',
    'breadcrumb' => ['Appointments' => '/appointments', 'Detail' => null]]);
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h5 class="fw-bold mb-1"><?= e($a['patient_name']) ?> <span class="text-muted small">(<?= e($a['patient_code']) ?>)</span></h5>
            <div class="text-muted small mb-2">
              <i class="fa-regular fa-calendar me-1"></i><?= fmt_date($a['appointment_date']) ?>
              <i class="fa-regular fa-clock ms-2 me-1"></i><?= date('H:i', strtotime($a['appointment_time'])) ?>
              <?php if ($a['type_name']): ?> · <?= e($a['type_name']) ?><?php endif; ?>
              <?php if ($a['department_name']): ?> · <?= e($a['department_name']) ?><?php endif; ?>
            </div>
            <?php
            $badgeMap = ['primary' => 'text-bg-primary', 'success' => 'text-bg-success', 'warning' => 'text-bg-warning',
                         'danger' => 'text-bg-danger', 'info' => 'text-bg-info', 'dark' => 'text-bg-dark', 'secondary' => 'text-bg-secondary'];
            ?>
            <span class="badge <?= $badgeMap[$a['badge_class']] ?? 'text-bg-secondary' ?>"><?= e($a['status_name'] ?? 'N/A') ?></span>
            <?php if ((int) $a['is_final'] === 0 && strtotime($a['appointment_date'] . ' ' . $a['appointment_time']) < time()): ?>
              <span class="badge text-bg-warning"><i class="fa-solid fa-clock me-1"></i>Overdue</span>
            <?php endif; ?>
          </div>
          <div class="text-end small text-muted">
            <div><i class="fa-solid fa-user-doctor me-1"></i><?= e($a['doctor_name'] ? 'Dr. ' . $a['doctor_name'] : 'N/A') ?></div>
            <div><i class="fa-solid fa-location-dot me-1"></i><?= e(or_na($a['branch_name'])) ?></div>
            <div><i class="fa-solid fa-coins me-1"></i>Consultation: <?= money($a['consultation_fee']) ?></div>
          </div>
        </div>
        <hr>
        <div class="row small">
          <div class="col-md-6"><span class="text-muted">Reason:</span> <?= e(or_na($a['reason'])) ?></div>
          <div class="col-md-6"><span class="text-muted">Booked by:</span> <?= e(or_na($a['created_by_name'])) ?> · <?= fmt_date($a['created_at'], true) ?></div>
          <div class="col-md-12 mt-1"><span class="text-muted">Notes:</span> <?= e(or_na($a['notes'])) ?></div>
        </div>
      </div>
    </div>

    <?php if ($queueRow): ?>
    <div class="card">
      <div class="card-header py-2"><i class="fa-solid fa-list-ol me-2"></i>Queue Status</div>
      <div class="card-body py-3">
        Queue number <strong><?= e($queueRow['queue_code']) ?></strong> —
        <span class="badge text-bg-light border"><?= label_case($queueRow['status']) ?></span>
        <a class="btn btn-sm btn-outline-brand ms-2" href="/queue">Open queue board</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header py-2">Actions</div>
      <div class="card-body d-grid gap-2">
        <?php if (has_permission('appointments.edit') && (int) $a['is_final'] === 0): ?>
          <div>
            <label class="form-label small fw-semibold mb-1">Change status</label>
            <div class="dropdown">
              <button class="btn btn-light btn-sm border w-100 dropdown-toggle" data-bs-toggle="dropdown"><?= e($a['status_name'] ?? 'Status') ?></button>
              <ul class="dropdown-menu dropdown-menu-end w-100">
                <?php foreach ($statuses as $s): if ((int) $s['id'] === (int) $a['status_id']) continue; ?>
                  <li><button class="dropdown-item small" data-action="appt-status" data-id="<?= $id ?>" data-status="<?= (int) $s['id'] ?>"><?= e($s['status_name']) ?></button></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>
        <?php if (has_permission('queue.manage') && (int) $a['is_final'] === 0 && !$queueRow): ?>
          <a class="btn btn-outline-brand btn-sm" href="/queue?checkin=<?= $id ?>"><i class="fa-solid fa-list-ol me-1"></i>Check in to queue</a>
        <?php endif; ?>
        <?php if (has_permission('medical.create')): ?>
          <a class="btn btn-outline-brand btn-sm" href="/medical/create?patient_id=<?= (int) $a['patient_id'] ?>&appointment_id=<?= $id ?>"><i class="fa-solid fa-file-medical me-1"></i>Start Consultation</a>
        <?php endif; ?>
        <?php if (has_permission('billing.create')): ?>
          <a class="btn btn-outline-brand btn-sm" href="/billing/create?patient_id=<?= (int) $a['patient_id'] ?>"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Create Invoice</a>
        <?php endif; ?>
        <a class="btn btn-light btn-sm" href="/patients/view/<?= (int) $a['patient_id'] ?>"><i class="fa-solid fa-hospital-user me-1"></i>Patient Profile</a>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', async function (ev) {
  const statusBtn = ev.target.closest('[data-action="appt-status"]');
  if (!statusBtn) return;
  const data = await App.postJSON('/ajax/appointments', { action: 'status', id: statusBtn.dataset.id, status_id: statusBtn.dataset.status });
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 500); }
  else App.toast(data.message, 'danger');
});
</script>
<?php
ui_page_close();
