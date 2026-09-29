<?php
/**
 * /queue — live patient queue board.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('queue.view');
$canManage = has_permission('queue.manage');

$today = date('Y-m-d');
$branchId = scope_branch_id();
$branchCond = $branchId !== null ? ' AND q.branch_id = ' . (int) $branchId : '';

$checkinAppt = (int) get('checkin', '0');
$walkinPatient = (int) get('walkin_patient', '0');

ui_page_open(['title' => 'Live Queue', 'icon' => 'fa-list-ol', 'breadcrumb' => ['Queue' => null]]);
?>
<?php if ($checkinAppt): ?>
  <?php
  // Auto check-in from an appointment link
  $appt = db_fetch_one('SELECT id, patient_id, doctor_id, branch_id, appointment_code FROM appointments WHERE id = ?', [$checkinAppt], 'i');
  if ($appt && $canManage) {
      $exists = db_fetch_value("SELECT id FROM queue WHERE appointment_id = ? AND queue_date = ?", [$checkinAppt, $today], 'is');
      if (!$exists) {
          $code = generate_code('queue_prefix', 'queue', 'queue_code', 3);
          $qid = db_execute('INSERT INTO queue (queue_code, appointment_id, patient_id, doctor_id, branch_id, queue_date, status, created_by) VALUES (?,?,?,?,?,?,?,?)',
              [$code, (int) $appt['id'], (int) $appt['patient_id'], $appt['doctor_id'] !== null ? (int) $appt['doctor_id'] : null,
               $appt['branch_id'] !== null ? (int) $appt['branch_id'] : null, $today, 'waiting', (int) current_user()['id']]);
          notify(['permission_key' => 'queue.view', 'branch_id' => $appt['branch_id'] !== null ? (int) $appt['branch_id'] : null],
              'Patient checked in', 'Queue number ' . $code . ' for appointment ' . ($appt['appointment_code'] ?? '') . '.', 'appointment', 'queue', $qid, '/queue');
          echo '<div class="alert alert-success">Patient checked in as <strong>' . e($code) . '</strong>.</div>';
      }
  }
  ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card queue-board h-100">
      <div class="card-body text-center d-flex flex-column justify-content-center">
        <div class="small text-muted text-uppercase mb-2">Now Serving</div>
        <?php
        $nowServing = db_fetch_one("SELECT q.*, p.full_name, p.patient_code FROM queue q JOIN patients p ON p.id = q.patient_id
            WHERE q.queue_date = ? AND q.status IN ('called','in_room') $branchCond ORDER BY FIELD(q.status,'in_room','called'), q.called_at DESC LIMIT 1", [$today], 's');
        ?>
        <div class="now-serving"><?= e($nowServing['queue_code'] ?? '—') ?></div>
        <?php if ($nowServing): ?>
          <div class="fw-semibold"><?= e($nowServing['full_name']) ?> <span class="text-muted small">(<?= e($nowServing['patient_code']) ?>)</span></div>
          <div class="small text-muted">Called <?= $nowServing['called_at'] ? fmt_date($nowServing['called_at'], true) : '' ?></div>
        <?php else: ?>
          <div class="text-muted">No patient is being served</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header py-2 d-flex align-items-center">
        <span><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Up Next</span>
        <span class="ms-auto small text-muted">Auto-refreshes every 20s</span>
      </div>
      <div class="card-body">
        <?php
        $upNext = db_fetch_all("SELECT q.*, p.full_name, p.patient_code FROM queue q JOIN patients p ON p.id = q.patient_id
            WHERE q.queue_date = ? AND q.status = 'waiting' $branchCond ORDER BY q.id ASC LIMIT 6", [$today], 's');
        ?>
        <?php if (!$upNext): ?>
          <div class="text-muted text-center py-4">No patients waiting</div>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-2 up-next">
          <?php foreach ($upNext as $q): ?>
            <div class="q-chip">
              <div class="q-num"><?= e($q['queue_code']) ?></div>
              <div class="q-pat"><?= e($q['full_name']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex flex-wrap gap-2 align-items-center">
    <span><i class="fa-solid fa-clipboard-list me-2"></i>Today's Queue</span>
    <?php if ($canManage): ?>
    <button class="btn btn-sm btn-brand ms-auto" data-bs-toggle="modal" data-bs-target="#checkinModal"><i class="fa-solid fa-user-plus me-1"></i>Check In (Walk-in)</button>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Queue</th><th>Patient</th><th>Doctor</th><th>Status</th><th>Checked In</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php
      $all = db_fetch_all("SELECT q.*, p.full_name, p.patient_code, d.full_name doctor_name
          FROM queue q JOIN patients p ON p.id = q.patient_id LEFT JOIN doctors d ON d.id = q.doctor_id
          WHERE q.queue_date = ? $branchCond ORDER BY q.id DESC", [$today], 's');
      if (!$all) echo '<tr><td colspan="6" class="text-center text-muted py-4">No queue entries today</td></tr>';
      $statusBadge = ['waiting' => 'text-bg-warning', 'called' => 'text-bg-primary', 'in_room' => 'text-bg-success',
                      'skipped' => 'text-bg-secondary', 'completed' => 'text-bg-dark'];
      foreach ($all as $q):
      ?>
        <tr>
          <td class="fw-bold"><?= e($q['queue_code']) ?></td>
          <td><?= e($q['full_name']) ?> <span class="text-muted small">(<?= e($q['patient_code']) ?>)</span></td>
          <td class="small"><?= e($q['doctor_name'] ? 'Dr. ' . $q['doctor_name'] : 'N/A') ?></td>
          <td><span class="badge <?= $statusBadge[$q['status']] ?? 'text-bg-secondary' ?>"><?= label_case($q['status']) ?></span></td>
          <td class="small text-muted"><?= fmt_date($q['created_at'], true) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($canManage): ?>
            <button class="btn btn-sm btn-light" data-action="q-call" data-id="<?= (int) $q['id'] ?>" <?= $q['status'] !== 'waiting' ? 'disabled' : '' ?>><i class="fa-solid fa-bullhorn me-1"></i>Call</button>
            <button class="btn btn-sm btn-light" data-action="q-room" data-id="<?= (int) $q['id'] ?>" <?= !in_array($q['status'], ['called', 'skipped'], true) ? 'disabled' : '' ?>><i class="fa-solid fa-door-open me-1"></i>In Room</button>
            <button class="btn btn-sm btn-light" data-action="q-skip" data-id="<?= (int) $q['id'] ?>" <?= $q['status'] !== 'waiting' ? 'disabled' : '' ?>><i class="fa-solid fa-forward me-1"></i>Skip</button>
            <button class="btn btn-sm btn-light" data-action="q-recall" data-id="<?= (int) $q['id'] ?>" <?= !in_array($q['status'], ['skipped', 'completed'], true) ? 'disabled' : '' ?>><i class="fa-solid fa-rotate-left me-1"></i>Recall</button>
            <button class="btn btn-sm btn-light" data-action="q-done" data-id="<?= (int) $q['id'] ?>" <?= in_array($q['status'], ['completed'], true) ? 'disabled' : '' ?>><i class="fa-solid fa-check me-1"></i>Complete</button>
            <?php else: ?>
              <span class="text-muted small">View only</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<?php
// Walk-in check-in (GET form submit)
if ($walkinPatient && $canManage) {
    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$walkinPatient], 'i');
    if ($patient) {
        assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);
        $branchId = $patient['branch_id'] ?? current_user()['branch_id'];
        $code = generate_code('queue_prefix', 'queue', 'queue_code', 3);
        $qid = db_execute('INSERT INTO queue (queue_code, patient_id, branch_id, queue_date, status, created_by) VALUES (?,?,?,?,?,?)',
            [$code, (int) $patient['id'], $branchId !== null ? (int) $branchId : null, $today, 'waiting', (int) current_user()['id']]);
        audit_log('create', 'Queue', $qid, 'Walk-in check-in: ' . $patient['full_name'] . ' as ' . $code);
        notify(['permission_key' => 'queue.view', 'branch_id' => $branchId !== null ? (int) $branchId : null],
            'Patient checked in', $patient['full_name'] . ' checked in as ' . $code . '.', 'appointment', 'queue', $qid, '/queue');
        echo '<div class="alert alert-success">Patient checked in as <strong>' . e($code) . '</strong>.</div>';
    } else {
        echo '<div class="alert alert-danger">Patient not found.</div>';
    }
}
?>

<!-- Walk-in check-in modal -->
<div class="modal fade" id="checkinModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="get" action="/queue">
        <div class="modal-header"><h5 class="modal-title">Check In Patient</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label required">Patient</label>
          <select class="form-select" name="walkin_patient" id="walkinPatient" required><option value="">— Select patient —</option></select>
          <div class="form-text">Type to search registered patients.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><i class="fa-solid fa-list-ol me-1"></i>Generate Queue Number</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', async function () {
  const sel = document.getElementById('walkinPatient');
  if (!sel) return;
  const res = await fetch('/ajax/lookup?type=patients', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const data = await res.json();
  sel.innerHTML = '<option value="">— Select patient —</option>' + data.items.map(p => `<option value="${p.id}">${App.escapeHtml(p.name)}</option>`).join('');
});
</script>
<?php endif; ?>

<script>
document.addEventListener('click', async function (ev) {
  const btn = ev.target.closest('[data-action^="q-"]');
  if (!btn || btn.disabled) return;
  ev.preventDefault();
  const map = { 'q-call': 'call', 'q-room': 'room', 'q-skip': 'skip', 'q-recall': 'recall', 'q-done': 'done' };
  const action = map[btn.dataset.action];
  const data = await App.postJSON('/ajax/queue', { action, id: btn.dataset.id });
  if (data.ok) { App.toast(data.message || 'Updated.', 'success'); setTimeout(() => location.reload(), 400); }
  else App.toast(data.message || 'Failed.', 'danger');
});
</script>
<?php
ui_page_close();
