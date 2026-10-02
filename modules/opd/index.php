<?php
/**
 * /opd — internal referrals: send patients (with vitals) to OPD/Lab/Pharmacy/Doctor
 * and process the ones addressed to your department.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_once dirname(__DIR__, 2) . '/config/payroll.php';
require_login();

$canSend = has_permission('opd.send');
$destinations = [
    'od' => ['label' => 'OPD', 'icon' => 'fa-bed-pulse', 'perm' => 'dashboard.view'],
    'laboratory' => ['label' => 'Laboratory', 'icon' => 'fa-flask-vial', 'perm' => 'laboratory.view'],
    'pharmacy' => ['label' => 'Pharmacy', 'icon' => 'fa-pills', 'perm' => 'pharmacy.view'],
    'doctor' => ['label' => 'Doctor', 'icon' => 'fa-user-doctor', 'perm' => 'dashboard.view'],
];
// Destinations this user can work on (their inbox tabs)
$myDest = [];
foreach ($destinations as $key => $d) {
    if (has_permission($d['perm'])) $myDest[$key] = $d;
}
$myKeys = array_keys($myDest);
if (!$myKeys) {
    http_response_code(403);
    require dirname(__DIR__, 2) . '/errors/403.php';
    exit;
}

$tab = get('tab', '');
if (!in_array($tab, $myKeys, true)) {
    // Default: first destination that isn't just "od" when possible, so lab/pharmacy staff land on their queue.
    $tab = in_array('laboratory', $myKeys, true) ? 'laboratory' : (in_array('pharmacy', $myKeys, true) ? 'pharmacy' : $myKeys[0]);
}
$statusF = get('status', 'active'); // active | pending | received | completed | all
$branchId = scope_branch_id();

$where = ' WHERE os.destination = ?';
$params = [$tab];
if ($statusF === 'active') {
    $where .= ' AND os.status IN (\'pending\',\'received\',\'transferred\')';
} elseif (in_array($statusF, ['pending', 'received', 'completed'], true)) {
    $where .= ' AND os.status = ?';
    $params[] = $statusF;
} elseif ($statusF === 'all') {
    $where .= ' AND os.status != \'cancelled\'';
}
if ($branchId !== null) {
    $where .= ' AND os.branch_id = ?';
    $params[] = $branchId;
}

$rows = db_fetch_all(
    "SELECT os.*, p.full_name patient_name, p.patient_code, p.phone, p.age,
            b.branch_name,
            su.full_name sent_by_name, ru.full_name received_by_name
     FROM opd_sends os
     JOIN patients p ON p.id = os.patient_id
     LEFT JOIN branches b ON b.id = os.branch_id
     LEFT JOIN users su ON su.id = os.sent_by
     LEFT JOIN users ru ON ru.id = os.received_by
     $where ORDER BY FIELD(os.status, 'pending', 'transferred', 'received', 'completed'), os.id DESC LIMIT 100",
    $params
);

$counts = ['pending' => 0, 'received' => 0, 'completed' => 0];
foreach (db_fetch_all(
    "SELECT status, COUNT(*) c FROM opd_sends os WHERE os.destination = ?" . ($branchId !== null ? ' AND os.branch_id = ?' : '') . " GROUP BY status",
    $branchId !== null ? [$tab, $branchId] : [$tab]
) as $c) {
    if (isset($counts[(string) $c['status']])) $counts[(string) $c['status']] = (int) $c['c'];
}

ui_page_open(['title' => 'OPD Referrals', 'icon' => 'fa-bed-pulse', 'breadcrumb' => ['OPD' => null]]);
?>

<?php if ($canSend): ?>
<div class="card mb-4 border-brand-subtle">
  <div class="card-header py-2"><i class="fa-solid fa-paper-plane me-2 text-brand"></i>Send Patient</div>
  <div class="card-body">
    <form id="opdSendForm" class="row g-3">
      <div class="col-lg-4">
        <label class="form-label required">Patient (new or old — type to search)</label>
        <select class="form-select" id="opdPatient" required><option value="">— Search by name or code —</option></select>
        <div class="form-text">Old patient? Just search and pick — no re-registration needed.</div>
      </div>
      <div class="col-lg-3">
        <label class="form-label required">Send to</label>
        <select class="form-select" name="destination" id="opdDest" required>
          <?php foreach ($destinations as $key => $d): ?>
            <option value="<?= e($key) ?>"><?= e($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-5">
        <label class="form-label">Complaint / reason</label>
        <input class="form-control" name="complaint" maxlength="255" placeholder="e.g. Fever, check BP, follow-up">
      </div>
      <div class="col-md-3"><label class="form-label">Blood Pressure</label><input class="form-control" name="blood_pressure" placeholder="e.g. 120/80"></div>
      <div class="col-md-3"><label class="form-label">Temperature (°C)</label><input type="number" step="0.1" class="form-control" name="temperature" placeholder="37.0"></div>
      <div class="col-md-3"><label class="form-label">Weight (kg)</label><input type="number" step="0.1" class="form-control" name="weight" placeholder="70"></div>
      <div class="col-md-3"><label class="form-label">Pulse</label><input class="form-control" name="pulse" placeholder="e.g. 78 bpm"></div>
      <div class="col-12"><label class="form-label">Note (optional)</label><input class="form-control" name="note" maxlength="255" placeholder="Anything the receiving department should know"></div>
      <div class="col-12">
        <button class="btn btn-brand" type="submit"><i class="fa-solid fa-paper-plane me-1"></i>Send Patient</button>
        <span class="text-muted small ms-2">Vitals are saved to the patient's file automatically.</span>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <?php foreach ($myDest as $key => $d): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="/opd?tab=<?= e($key) ?>&status=<?= e($statusF) ?>">
        <i class="fa-solid <?= e($d['icon']) ?> me-1"></i><?= e($d['label']) ?>
      </a>
    </li>
  <?php endforeach; ?>
  <li class="nav-item ms-auto d-flex align-items-center gap-2 small">
    <span class="badge text-bg-warning">pending <?= $counts['pending'] ?></span>
    <span class="badge text-bg-primary">received <?= $counts['received'] ?></span>
    <span class="badge text-bg-success">completed <?= $counts['completed'] ?></span>
    <select class="form-select form-select-sm" style="width:130px" onchange="location='/opd?tab=<?= e($tab) ?>&status='+this.value">
      <?php foreach (['active' => 'Active', 'pending' => 'Pending', 'received' => 'Received', 'completed' => 'Completed', 'all' => 'All'] as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
  </li>
</ul>

<div class="card">
  <div class="card-header py-2"><i class="fa-solid <?= e($myDest[$tab]['icon']) ?> me-2"></i><?= e($myDest[$tab]['label']) ?> Queue</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Patient</th><th>Vitals</th><th>Complaint / Note</th><th>From → Status</th><th>Sent</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Nothing here right now.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $s):
        $badge = $s['status'] === 'pending' ? 'text-bg-warning' : ($s['status'] === 'received' ? 'text-bg-primary' : ($s['status'] === 'transferred' ? 'text-bg-info' : 'text-bg-success'));
        $isMine = $s['status'] !== 'completed' && $s['status'] !== 'cancelled';
        $vitals = array_filter([$s['vitals_blood_pressure'] ? 'BP ' . $s['vitals_blood_pressure'] : null,
            $s['vitals_temperature'] ? $s['vitals_temperature'] . '°C' : null,
            $s['vitals_weight'] ? $s['vitals_weight'] . ' kg' : null,
            $s['vitals_pulse'] ? 'Pulse ' . $s['vitals_pulse'] : null]);
      ?>
        <tr>
          <td><div class="fw-semibold"><?= e($s['patient_name']) ?> <span class="text-muted small">(<?= e($s['patient_code']) ?>)</span></div>
              <div class="small text-muted"><?= $s['age'] !== null ? (int) $s['age'] . ' yrs · ' : '' ?><?= e(or_na($s['phone'])) ?> · <?= e(or_na($s['branch_name'])) ?></div></td>
          <td class="small"><?= $vitals ? e(implode(' · ', $vitals)) : '<span class="text-muted">—</span>' ?></td>
          <td class="small" style="max-width:220px"><?= e(or_na($s['complaint'])) ?><?php if ($s['note']): ?><div class="text-muted"><?= e($s['note']) ?></div><?php endif; ?></td>
          <td><span class="badge <?= $badge ?>"><?= label_case($s['status']) ?></span>
              <div class="small text-muted">by <?= e(or_na($s['sent_by_name'])) ?></div></td>
          <td class="small text-muted"><?= fmt_date($s['created_at'], true) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($isMine && $s['status'] === 'pending'): ?>
              <button class="btn btn-sm btn-brand" data-action="opd-receive" data-id="<?= (int) $s['id'] ?>"><i class="fa-solid fa-hand-holding-heart me-1"></i>Receive</button>
            <?php elseif ($isMine): ?>
              <span class="badge text-bg-light border">working</span>
            <?php else: ?>
              <span class="small text-muted">closed</span>
            <?php endif; ?>
            <?php if ($isMine): ?>
              <div class="dropdown d-inline">
                <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown"></button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                  <?php foreach ($destinations as $key => $d): if ($key === $tab || !has_permission($d['perm'])) continue; ?>
                    <li><button class="dropdown-item" data-action="opd-transfer" data-id="<?= (int) $s['id'] ?>" data-dest="<?= e($key) ?>">
                      <i class="fa-solid <?= e($d['icon']) ?> me-2"></i>Transfer to <?= e($d['label']) ?></button></li>
                  <?php endforeach; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><button class="dropdown-item text-success" data-action="opd-complete" data-id="<?= (int) $s['id'] ?>"><i class="fa-solid fa-check me-2"></i>Complete</button></li>
                  <?php if ($canSend): ?>
                    <li><button class="dropdown-item text-danger" data-action="opd-cancel" data-id="<?= (int) $s['id'] ?>"><i class="fa-solid fa-xmark me-2"></i>Cancel</button></li>
                  <?php endif; ?>
                </ul>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async function () {
  // Patient search (old patients: just search & pick)
  const sel = document.getElementById('opdPatient');
  if (sel) {
    const res = await fetch('/ajax/lookup?type=patients', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    sel.innerHTML = '<option value="">— Search by name or code —</option>' +
      data.items.map(p => `<option value="${p.id}">${App.escapeHtml(p.name)}</option>`).join('');
    $(sel).addClass('text-brand');
  }

  document.getElementById('opdSendForm')?.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const f = ev.target;
    const data = await App.postJSON('/ajax/opd', {
      action: 'send',
      patient_id: document.getElementById('opdPatient').value,
      destination: f.destination.value,
      complaint: f.complaint.value,
      blood_pressure: f.blood_pressure.value,
      temperature: f.temperature.value,
      weight: f.weight.value,
      pulse: f.pulse.value,
      note: f.note.value
    });
    if (data.ok) {
      App.toast(data.message, 'success');
      setTimeout(() => location.href = '/opd?tab=' + f.destination.value + '&status=active', 600);
    } else App.toast(data.message || 'Could not send.', 'danger');
  });

  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('[data-action^="opd-"]');
    if (!btn) return;
    ev.preventDefault();
    const id = btn.dataset.id;
    const act = btn.dataset.action.replace('opd-', '');
    if (act === 'transfer') {
      const d = await App.postJSON('/ajax/opd', { action: 'transfer', id, destination: btn.dataset.dest });
      App.toast(d.message || (d.ok ? 'Transferred.' : 'Failed.'), d.ok ? 'success' : 'danger');
      if (d.ok) setTimeout(() => location.reload(), 500);
    } else if (act === 'receive') {
      const d = await App.postJSON('/ajax/opd', { action: 'receive', id });
      App.toast(d.message || (d.ok ? 'Received.' : 'Failed.'), d.ok ? 'success' : 'danger');
      if (d.ok) setTimeout(() => location.reload(), 500);
    } else if (act === 'complete') {
      AppConfirm('Complete this referral?', 'The patient is done with ' + document.querySelector('.nav-link.active').textContent.trim() + '.', async () => {
        const d = await App.postJSON('/ajax/opd', { action: 'complete', id });
        App.toast(d.message || (d.ok ? 'Completed.' : 'Failed.'), d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(() => location.reload(), 500);
      }, 'Yes, complete');
    } else if (act === 'cancel') {
      AppConfirm('Cancel this referral?', 'The patient will be removed from every queue.', async () => {
        const d = await App.postJSON('/ajax/opd', { action: 'cancel', id });
        App.toast(d.message || (d.ok ? 'Cancelled.' : 'Failed.'), d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(() => location.reload(), 500);
      }, 'Yes, cancel');
    }
  });
});
</script>
<?php ui_page_close(); ?>
