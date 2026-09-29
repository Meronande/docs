<?php
/**
 * Consultation form partial. Inputs: $record (array), $patient (array|null), $appt (array|null)
 * Renders only the <form> + helper script; caller wraps in modal or page.
 */
if (!function_exists('medical_form_render')) {
    function medical_form_render(array $record, ?array $patient, ?array $appt): string
    {
        ob_start();
        echo '<form id="crudForm" data-url="/ajax/medical">';
        echo '<input type="hidden" name="id" value="' . e((string) ($record['id'] ?? '')) . '">';
        echo '<input type="hidden" name="action" value="save">';
        if ($appt) echo '<input type="hidden" name="appointment_id" value="' . (int) $appt['id'] . '">';
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
        return ob_get_clean();
    }
}

if (!function_exists('medical_form_script')) {
    function medical_form_script(): string
    {
        return <<<'JS'
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
    }
}
