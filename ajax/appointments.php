<?php
/**
 * AJAX appointments endpoint.
 * GET  /ajax/appointments[?id=]                  -> form
 * GET  /ajax/appointments&slots=1&doctor_id&date -> booked slots JSON
 * POST action=save|status|cancel
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!isset($_GET['slots'])) {
    if (!has_permission('appointments.view')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

// Slot availability
if (isset($_GET['slots'])) {
    $doctorId = (int) get('doctor_id', '0');
    $date = get('date');
    if ($doctorId === 0 || !valid_date($date)) json_response(['ok' => true, 'slots' => []]);
    $rows = db_fetch_all(
        "SELECT a.appointment_time, s.status_name
         FROM appointments a LEFT JOIN appointment_statuses s ON s.id = a.status_id
         WHERE a.doctor_id = ? AND a.appointment_date = ? AND (s.status_name IS NULL OR s.status_name NOT IN ('Cancelled','No Show'))",
        [$doctorId, $date], 'is'
    );
    json_response(['ok' => true, 'slots' => array_map(fn($r) => substr($r['appointment_time'], 0, 5), $rows)]);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission('appointments.create') && !has_permission('appointments.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $a = $id ? (db_fetch_one('SELECT * FROM appointments WHERE id = ?', [$id], 'i') ?? []) : [];
    $prePatient = (int) get('patient_id', (string) ($a['patient_id'] ?? 0));
    $branches = visible_branches();
    $types = db_fetch_all('SELECT id, type_name FROM appointment_types WHERE status = 1 ORDER BY type_name');

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/appointments">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label">Appointment Code</label><input class="form-control" value="' . e($a['appointment_code'] ?? generate_code('appointment_prefix', 'appointments', 'appointment_code')) . '" disabled><div class="form-text">Auto-generated</div></div>';

    if (sees_all_branches()) {
        echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id" id="apptBranch" data-cascade="departments" data-target="#apptDepartment" data-placeholder="All / shared departments">';
        echo '<option value="">— Your default —</option>';
        foreach ($branches as $b) echo '<option value="' . (int) $b['id'] . '" ' . ((string) ($a['branch_id'] ?? '') === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
        echo '</select></div>';
    }
    echo '<div class="col-md-6"><label class="form-label">Patient</label><div class="input-group">';
    echo '<input class="form-control" id="apptPatientSearch" placeholder="Type name / code / phone to search...">';
    echo '<select class="form-select" name="patient_id" id="apptPatient" required style="max-width:55%"><option value="">— Select patient —</option>';
    if ($prePatient) {
        $pp = db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$prePatient], 'i');
        if ($pp) echo '<option value="' . (int) $pp['id'] . '" selected>' . e($pp['patient_code'] . ' — ' . $pp['full_name']) . '</option>';
    }
    echo '</select></div><div class="form-text">Search finds patients in your branch scope.</div></div>';

    echo '<div class="col-md-6"><label class="form-label">Department</label><select class="form-select" name="department_id" id="apptDepartment" data-cascade="doctors" data-target="#apptDoctor"><option value="">— Select department —</option></select></div>';
    echo '<div class="col-md-6"><label class="form-label required">Doctor</label><select class="form-select" name="doctor_id" id="apptDoctor" required><option value="">— Select doctor —</option></select></div>';
    echo '<div class="col-md-4"><label class="form-label required">Date</label><input type="date" class="form-control" name="appointment_date" id="apptDate" min="' . date('Y-m-d') . '" value="' . e($a['appointment_date'] ?? date('Y-m-d')) . '" required></div>';
    echo '<div class="col-md-4"><label class="form-label required">Time</label><input type="time" class="form-control" name="appointment_time" id="apptTime" value="' . e(substr((string) ($a['appointment_time'] ?? ''), 0, 5) ?: '09:00') . '" required></div>';
    echo '<div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="appointment_type_id"><option value="">—</option>';
    foreach ($types as $t) echo '<option value="' . (int) $t['id'] . '" ' . ((string) ($a['appointment_type_id'] ?? '') === (string) $t['id'] ? 'selected' : '') . '>' . e($t['type_name']) . '</option>';
    echo '</select></div>';
    echo '<div class="col-12"><label class="form-label">Reason</label><input class="form-control" name="reason" value="' . e($a['reason'] ?? '') . '" maxlength="255"></div>';
    echo '<div class="col-12" id="apptSlotsWrap"><label class="form-label small text-muted">Booked slots for this doctor & date</label><div id="apptSlots" class="d-flex flex-wrap gap-1"></div></div>';
    echo '</div></form>';

    $html = ob_get_clean();
    $html .= <<<JS
<script>
(function(){
  const search = document.getElementById('apptPatientSearch');
  const select = document.getElementById('apptPatient');
  let timer = null;
  async function loadPatients(q) {
    const res = await fetch('/ajax/lookup?type=patients&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const current = select.value;
    select.innerHTML = '<option value="">— Select patient —</option>' +
      data.items.map(p => `<option value="\${p.id}">\${App.escapeHtml(p.name)}</option>`).join('');
    if (current) select.value = current;
  }
  search?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadPatients(search.value), 300); });
  loadPatients('');

  async function loadSlots() {
    const doc = document.getElementById('apptDoctor').value;
    const date = document.getElementById('apptDate').value;
    const wrap = document.getElementById('apptSlots');
    if (!doc || !date) { wrap.innerHTML = '<span class="text-muted small">Select a doctor and date</span>'; return; }
    const res = await fetch('/ajax/appointments?slots=1&doctor_id=' + doc + '&date=' + date, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    wrap.innerHTML = data.slots.length
      ? data.slots.map(s => `<span class="badge text-bg-light border">\${s} booked</span>`).join('')
      : '<span class="text-success small">No bookings yet — all slots free</span>';
  }
  document.getElementById('apptDoctor')?.addEventListener('change', loadSlots);
  document.getElementById('apptDate')?.addEventListener('change', loadSlots);
  setTimeout(loadSlots, 600);
})();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
$id = post('id', '') !== '' ? (int) post('id') : null;

if (post('action') === 'save') {
    if ($id && !has_permission('appointments.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if (!$id && !has_permission('appointments.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);

    $patientId = (int) post('patient_id', '0');
    $doctorId = (int) post('doctor_id', '0');
    $date = post('appointment_date');
    $time = post('appointment_time');
    if ($patientId === 0 || $doctorId === 0) json_response(['ok' => false, 'message' => 'Patient and doctor are required.']);
    if (!valid_date($date) || $date === '') json_response(['ok' => false, 'message' => 'A valid appointment date is required.']);
    if ($time === '' || !preg_match('/^\d{2}:\d{2}/', $time)) json_response(['ok' => false, 'message' => 'A valid appointment time is required.']);
    $time = substr($time, 0, 5) . ':00';

    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    $doctor = db_fetch_one('SELECT id, full_name, branch_id FROM doctors WHERE id = ?', [$doctorId], 'i');
    if (!$patient || !$doctor) json_response(['ok' => false, 'message' => 'Patient or doctor not found.']);
    assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);

    $branchId = post('branch_id') !== '' ? (int) post('branch_id')
        : ($patient['branch_id'] ?? $doctor['branch_id'] ?? current_user()['branch_id']);
    $departmentId = post('department_id') !== '' ? (int) post('department_id') : null;

    // Doctor availability: same doctor, same date+time, not cancelled
    $conflict = db_fetch_value(
        "SELECT a.id FROM appointments a LEFT JOIN appointment_statuses s ON s.id = a.status_id
         WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
           AND (s.status_name IS NULL OR s.status_name NOT IN ('Cancelled','No Show')) AND a.id <> ?",
        [$doctorId, $date, $time, $id ?? 0], 'iisi'
    );
    if ($conflict) json_response(['ok' => false, 'message' => 'Dr. ' . $doctor['full_name'] . ' already has an appointment at this time. Pick another slot.']);

    // Default status = "Pending" (first by sort order)
    $statusId = (int) db_fetch_value('SELECT id FROM appointment_statuses ORDER BY sort_order LIMIT 1');

    try {
        $savedId = db_transaction(function () use ($id, $patientId, $doctorId, $departmentId, $branchId, $date, $time, $statusId, $patient) {
            $data = [
                'patient_id' => $patientId, 'doctor_id' => $doctorId, 'department_id' => $departmentId,
                'branch_id' => $branchId, 'appointment_date' => $date, 'appointment_time' => $time,
                'appointment_type_id' => post('appointment_type_id') !== '' ? (int) post('appointment_type_id') : null,
                'reason' => post('reason') ?: null,
                'notes' => post('notes') ?: null,
            ];
            if ($id) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
                db_execute("UPDATE appointments SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', 'Appointment', $id, 'Updated appointment for ' . $patient['full_name']);
                return $id;
            }
            $data['appointment_code'] = generate_code('appointment_prefix', 'appointments', 'appointment_code');
            $data['status_id'] = $statusId;
            $data['created_by'] = (int) current_user()['id'];
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $newId = db_execute("INSERT INTO appointments ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
            audit_log('create', 'Appointment', $newId, 'New appointment ' . $data['appointment_code'] . ' for ' . $patient['full_name']);
            notify(['permission_key' => 'appointments.view', 'branch_id' => $branchId],
                'New appointment created',
                'New appointment for ' . $patient['full_name'] . ' on ' . fmt_date($date) . ' at ' . date('H:i', strtotime($time)) . '.',
                'appointment', 'appointment', $newId, '/appointments/view/' . $newId);
            return $newId;
        });
        json_response(['ok' => true, 'message' => 'Appointment saved successfully.', 'id' => $savedId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

if (post('action') === 'status') {
    if (!has_permission('appointments.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $sid = (int) post('status_id', '0');
    $a = db_fetch_one('SELECT a.id, a.appointment_code, s.status_name old_status, p.full_name patient_name, p.id pid
                       FROM appointments a LEFT JOIN appointment_statuses s ON s.id = a.status_id
                       JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [$id], 'i');
    $newStatus = db_fetch_one('SELECT id, status_name, is_final FROM appointment_statuses WHERE id = ?', [$sid], 'i');
    if (!$a || !$newStatus) json_response(['ok' => false, 'message' => 'Appointment or status not found.'], 404);
    db_execute('UPDATE appointments SET status_id = ? WHERE id = ?', [$sid, $id], 'ii');
    audit_log('update', 'Appointment', $id, 'Status ' . ($a['old_status'] ?? '?') . ' → ' . $newStatus['status_name'] . ' for ' . $a['patient_name']);
    if ((int) $newStatus['is_final'] === 1 && $newStatus['status_name'] === 'Completed') {
        notify(['permission_key' => 'appointments.view', 'branch_id' => current_user()['branch_id']],
            'Appointment completed', $a['patient_name'] . "'s appointment " . ($a['appointment_code'] ?? '') . ' was completed.',
            'appointment', 'appointment', $id, '/appointments/view/' . $id);
    }
    json_response(['ok' => true, 'message' => 'Status updated to ' . $newStatus['status_name'] . '.']);
}

if (post('action') === 'cancel') {
    if (!has_permission('appointments.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $cancelId = db_fetch_value("SELECT id FROM appointment_statuses WHERE status_name = 'Cancelled'");
    if (!$cancelId) json_response(['ok' => false, 'message' => 'No Cancelled status configured.']);
    db_execute('UPDATE appointments SET status_id = ? WHERE id = ?', [(int) $cancelId, $id], 'ii');
    audit_log('cancel', 'Appointment', $id, 'Cancelled appointment #' . $id);
    json_response(['ok' => true, 'message' => 'Appointment cancelled.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
