<?php
/**
 * AJAX medical records endpoint (consultations).
 * GET  /ajax/medical[?id=|?patient_id=&appointment_id=] -> form
 * POST action=save -> creates/updates visit + medical record (+ prescription when items given)
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('medical.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission('medical.create') && !has_permission('medical.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $record = $id ? (db_fetch_one('SELECT * FROM medical_records WHERE id = ?', [$id], 'i') ?? []) : [];
    $patientId = (int) get('patient_id', (string) ($record['patient_id'] ?? 0));
    $appointmentId = (int) get('appointment_id', '0');
    $appt = $appointmentId ? db_fetch_one('SELECT a.*, p.full_name, p.patient_code FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [$appointmentId], 'i') : null;

    $patient = null;
    if ($patientId) {
        $patient = db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$patientId], 'i');
    } elseif ($appt) {
        $patient = ['id' => (int) $appt['patient_id'], 'full_name' => $appt['full_name'], 'patient_code' => $appt['patient_code']];
    }

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/medical">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Patient</label><div class="input-group">';
    echo '<input class="form-control" id="mPatientSearch" placeholder="Search patient...">';
    echo '<select class="form-select" name="patient_id" id="mPatient" required style="max-width:55%"><option value="">— Select —</option>';
    if ($patient) echo '<option value="' . (int) $patient['id'] . '" selected>' . e(($patient['patient_code'] ?? '') . ' — ' . $patient['full_name']) . '</option>';
    echo '</select></div></div>';
    echo '<div class="col-md-6"><label class="form-label">Consulting Doctor</label><select class="form-select" name="doctor_id">';
    $b = scope_branch_id();
    $docs = db_fetch_all('SELECT id, full_name FROM doctors WHERE status = 1' . ($b !== null ? ' AND branch_id = ' . (int) $b : '') . ' ORDER BY full_name');
    foreach ($docs as $d) echo '<option value="' . (int) $d['id'] . '" ' . ((string) ($record['doctor_id'] ?? '') === (string) $d['id'] ? 'selected' : '') . '>Dr. ' . e($d['full_name']) . '</option>';
    echo '</select></div>';
    if ($appt) echo '<input type="hidden" name="appointment_id" value="' . (int) $appt['id'] . '">';
    echo '<div class="col-12"><label class="form-label required">Chief Complaint</label><textarea class="form-control" name="chief_complaint" rows="2" required>' . e($record['chief_complaint'] ?? '') . '</textarea></div>';
    echo '<div class="col-md-6"><label class="form-label">History of Present Illness</label><textarea class="form-control" name="history" rows="3">' . e($record['history'] ?? '') . '</textarea></div>';
    echo '<div class="col-md-6"><label class="form-label">Examination Findings</label><textarea class="form-control" name="examination" rows="3">' . e($record['examination'] ?? '') . '</textarea></div>';
    echo '<div class="col-md-6"><label class="form-label">Diagnosis</label><div class="input-group">';
    echo '<input class="form-control" id="mDiagSearch" placeholder="Search diagnosis catalog...">';
    echo '<select class="form-select" name="diagnosis_id" id="mDiagnosis" style="max-width:55%"><option value="">— None —</option>';
    if (!empty($record['diagnosis_id'])) {
        $dx = db_fetch_one('SELECT id, diagnosis_code, diagnosis_name FROM diagnoses WHERE id = ?', [(int) $record['diagnosis_id']], 'i');
        if ($dx) echo '<option value="' . (int) $dx['id'] . '" selected>' . e($dx['diagnosis_code'] . ' — ' . $dx['diagnosis_name']) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="col-md-6"><label class="form-label">Treatment Plan</label><textarea class="form-control" name="treatment" rows="1">' . e($record['treatment'] ?? '') . '</textarea></div>';
    echo '<div class="col-12"><label class="form-label">Additional Notes</label><textarea class="form-control" name="notes" rows="2">' . e($record['notes'] ?? '') . '</textarea></div>';

    echo '<div class="col-12"><hr class="my-1"><h6 class="small text-uppercase text-muted mb-2">Vitals (optional)</h6><div class="row g-2">';
    foreach ([['temperature', 'Temp (°C)'], ['weight', 'Weight (kg)'], ['height', 'Height (cm)'], ['blood_pressure', 'BP (e.g. 120/80)'], ['pulse', 'Pulse']] as $vf) {
        echo '<div class="col-md-2 col-4"><label class="form-label small">' . e($vf[1]) . '</label><input class="form-control form-control-sm" name="vitals_' . $vf[0] . '"></div>';
    }
    echo '</div></div>';

    echo '<div class="col-12"><hr class="my-1"><h6 class="small text-uppercase text-muted mb-2">Prescription (optional — sent to pharmacy)</h6>';
    echo '<div id="rxItems"></div>';
    echo '<button type="button" class="btn btn-sm btn-light border" id="addRxItem"><i class="fa-solid fa-plus me-1"></i>Add medicine row</button></div>';
    echo '</div></form>';

    $html = ob_get_clean();
    $html .= <<<'JS'
<script>
(function () {
  const search = document.getElementById('mPatientSearch');
  const select = document.getElementById('mPatient');
  let timer = null;
  async function loadPatients(q) {
    const res = await fetch('/ajax/lookup?type=patients&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const cur = select.value;
    select.innerHTML = '<option value="">— Select —</option>' + data.items.map(p => `<option value="${p.id}">${App.escapeHtml(p.name)}</option>`).join('');
    if (cur) select.value = cur;
  }
  search?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadPatients(search.value), 300); });

  const dSearch = document.getElementById('mDiagSearch');
  const dSelect = document.getElementById('mDiagnosis');
  let dTimer = null;
  async function loadDiag(q) {
    const res = await fetch('/ajax/lookup?type=diagnoses&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const cur = dSelect.value;
    dSelect.innerHTML = '<option value="">— None —</option>' + data.items.map(d => `<option value="${d.id}">${App.escapeHtml(d.name)}</option>`).join('');
    if (cur) dSelect.value = cur;
  }
  dSearch?.addEventListener('input', () => { clearTimeout(dTimer); dTimer = setTimeout(() => loadDiag(dSearch.value), 300); });

  // Prescription rows
  let medCache = [];
  async function loadMeds() {
    const res = await fetch('/ajax/lookup?type=medicines', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    medCache = data.items;
  }
  loadMeds();
  let rowIdx = 0;
  document.getElementById('addRxItem')?.addEventListener('click', () => {
    const i = rowIdx;
    const opts = medCache.map(m => `<option value="${m.id}">${App.escapeHtml(m.name)}</option>`).join('');
    const html = `<div class="row g-2 rx-row border rounded p-2 mb-2 align-items-end" data-idx="${i}">
      <input type="hidden" name="rx_${i}_flag" value="1">
      <div class="col-md-4"><label class="form-label small">Medicine</label>
        <select class="form-select form-select-sm" name="rx_${i}_med"><option value="">— Stock item —</option>${opts}</select>
        <input class="form-control form-control-sm mt-1" name="rx_${i}_free" placeholder="...or free-text item"></div>
      <div class="col-md-2"><label class="form-label small">Dosage</label><input class="form-control form-control-sm" name="rx_${i}_dosage" placeholder="1 tab"></div>
      <div class="col-md-2"><label class="form-label small">Frequency</label><input class="form-control form-control-sm" name="rx_${i}_frequency" placeholder="TID"></div>
      <div class="col-md-2"><label class="form-label small">Duration</label><input class="form-control form-control-sm" name="rx_${i}_duration" placeholder="5 days"></div>
      <div class="col-md-1"><label class="form-label small">Qty</label><input type="number" min="1" value="1" class="form-control form-control-sm" name="rx_${i}_qty"></div>
      <div class="col-md-1"><button type="button" class="btn btn-sm btn-light text-danger w-100 rx-del"><i class="fa-solid fa-trash"></i></button></div>
      <div class="col-12"><input class="form-control form-control-sm" name="rx_${i}_instructions" placeholder="Instructions (e.g. after meals)"></div>
    </div>`;
    document.getElementById('rxItems').insertAdjacentHTML('beforeend', html);
    rowIdx++;
  });
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('.rx-del')) ev.target.closest('.rx-row').remove();
  });
})();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if (post('action') === 'save') {
    if (!has_permission('medical.create') && !has_permission('medical.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = post('id', '') !== '' ? (int) post('id') : null;
    $patientId = (int) post('patient_id', '0');
    if ($patientId === 0) json_response(['ok' => false, 'message' => 'Patient is required.']);
    if (post('chief_complaint') === '') json_response(['ok' => false, 'message' => 'Chief complaint is required.']);

    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    if (!$patient) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);
    assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);

    // Collect prescription rows from POST (rx_<n>_<field>)
    $rxItems = [];
    $rxIndex = 0;
    while (isset($_POST["rx_{$rxIndex}_flag"])) {
        $medId = (int) ($_POST["rx_{$rxIndex}_med"] ?? 0);
        $freeName = trim((string) ($_POST["rx_{$rxIndex}_free"] ?? ''));
        if ($medId > 0 || $freeName !== '') {
            $rxItems[] = [
                'medicine_id' => $medId ?: null,
                'medicine_name_free' => $freeName ?: null,
                'dosage' => $_POST["rx_{$rxIndex}_dosage"] ?? null,
                'frequency' => $_POST["rx_{$rxIndex}_frequency"] ?? null,
                'duration' => $_POST["rx_{$rxIndex}_duration"] ?? null,
                'quantity' => max(1, (int) ($_POST["rx_{$rxIndex}_qty"] ?? 1)),
                'instructions' => $_POST["rx_{$rxIndex}_instructions"] ?? null,
            ];
        }
        $rxIndex++;
        if ($rxIndex > 50) break;
    }

    try {
        $savedId = db_transaction(function () use ($id, $patientId, $patient, $rxItems) {
            $branchId = $patient['branch_id'] ?? current_user()['branch_id'];
            $doctorId = post('doctor_id') !== '' ? (int) post('doctor_id') : null;
            $appointmentId = post('appointment_id') !== '' ? (int) post('appointment_id') : null;

            if ($id) {
                $data = [
                    'patient_id' => $patientId, 'doctor_id' => $doctorId, 'branch_id' => $branchId,
                    'chief_complaint' => post('chief_complaint'), 'history' => post('history') ?: null,
                    'examination' => post('examination') ?: null,
                    'diagnosis_id' => post('diagnosis_id') !== '' ? (int) post('diagnosis_id') : null,
                    'treatment' => post('treatment') ?: null, 'notes' => post('notes') ?: null,
                ];
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                db_execute("UPDATE medical_records SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', 'Medical Record', $id, 'Updated record for ' . $patient['full_name']);
                return $id;
            }

            // Create visit
            $visitId = null;
            if ($appointmentId) {
                $visitId = db_fetch_value('SELECT id FROM visits WHERE appointment_id = ?', [$appointmentId], 'i');
            }
            if (!$visitId) {
                $visitCode = generate_code('visit_prefix', 'visits', 'visit_code');
                $visitId = db_execute('INSERT INTO visits (visit_code, patient_id, doctor_id, branch_id, appointment_id, created_by) VALUES (?,?,?,?,?,?)',
                    [$visitCode, $patientId, $doctorId, $branchId !== null ? (int) $branchId : null,
                     $appointmentId ?: null, (int) current_user()['id']]);
            }

            $recCode = null;
            $data = [
                'patient_id' => $patientId, 'doctor_id' => $doctorId, 'branch_id' => $branchId !== null ? (int) $branchId : null,
                'visit_id' => (int) $visitId,
                'chief_complaint' => post('chief_complaint'), 'history' => post('history') ?: null,
                'examination' => post('examination') ?: null,
                'diagnosis_id' => post('diagnosis_id') !== '' ? (int) post('diagnosis_id') : null,
                'treatment' => post('treatment') ?: null, 'notes' => post('notes') ?: null,
                'created_by' => (int) current_user()['id'],
            ];
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $recordId = db_execute("INSERT INTO medical_records ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));

            // Vitals
            $vitals = [
                'temperature' => post('vitals_temperature') ?: null,
                'weight' => post('vitals_weight') !== '' ? (float) post('vitals_weight') : null,
                'height' => post('vitals_height') !== '' ? (float) post('vitals_height') : null,
                'blood_pressure' => post('vitals_blood_pressure') ?: null,
                'pulse' => post('vitals_pulse') ?: null,
            ];
            if (array_filter($vitals)) {
                db_execute('INSERT INTO patient_vitals (patient_id, visit_id, temperature, weight, height, blood_pressure, pulse, recorded_by) VALUES (?,?,?,?,?,?,?,?)',
                    [$patientId, (int) $visitId, $vitals['temperature'], $vitals['weight'], $vitals['height'],
                     $vitals['blood_pressure'], $vitals['pulse'], (int) current_user()['id']]);
            }

            // Prescription
            if ($rxItems) {
                $rxCode = generate_code('prescription_prefix', 'prescriptions', 'prescription_code');
                $rxId = db_execute('INSERT INTO prescriptions (prescription_code, patient_id, doctor_id, branch_id, visit_id, notes, status) VALUES (?,?,?,?,?,?,?)',
                    [$rxCode, $patientId, $doctorId, $branchId !== null ? (int) $branchId : null, (int) $visitId,
                     post('rx_notes') ?: null, 'pending']);
                foreach ($rxItems as $item) {
                    db_execute('INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name_free, dosage, frequency, duration, quantity, instructions) VALUES (?,?,?,?,?,?,?,?)',
                        [$rxId, $item['medicine_id'], $item['medicine_name_free'], $item['dosage'], $item['frequency'],
                         $item['duration'], $item['quantity'], $item['instructions']]);
                }
                notify(['permission_key' => 'pharmacy.sell', 'branch_id' => $branchId !== null ? (int) $branchId : null],
                    'New prescription to dispense',
                    $rxCode . ' for ' . $patient['full_name'] . ' (' . count($rxItems) . ' item' . (count($rxItems) === 1 ? '' : 's') . ').',
                    'pharmacy', 'prescription', $rxId, '/pharmacy/pending');
            }

            audit_log('create', 'Medical Record', $recordId, 'New consultation record for ' . $patient['full_name']);
            if ($appointmentId) {
                // Mark appointment completed via the dynamic "Completed" status
                $completedId = db_fetch_value("SELECT id FROM appointment_statuses WHERE status_name = 'Completed'");
                if ($completedId) {
                    db_execute('UPDATE appointments SET status_id = ? WHERE id = ?', [(int) $completedId, $appointmentId], 'ii');
                }
            }
            return $recordId;
        });
        json_response(['ok' => true, 'message' => 'Consultation saved successfully.', 'id' => $savedId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
