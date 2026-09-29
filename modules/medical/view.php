<?php
/**
 * /medical/view/{id} — full EMR record.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('medical.view');

$id = (int) get('id', '0');
$m = db_fetch_one(
    'SELECT m.*, p.full_name patient_name, p.patient_code, p.gender, p.date_of_birth, p.blood_group,
            dx.diagnosis_code, dx.diagnosis_name, d.full_name doctor_name, u.full_name created_by_name, b.branch_name
     FROM medical_records m
     JOIN patients p ON p.id = m.patient_id
     LEFT JOIN diagnoses dx ON dx.id = m.diagnosis_id
     LEFT JOIN doctors d ON d.id = m.doctor_id
     LEFT JOIN users u ON u.id = m.created_by
     LEFT JOIN branches b ON b.id = m.branch_id
     WHERE m.id = ?', [$id], 'i');
if (!$m) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
assert_branch_access($m['branch_id'] !== null ? (int) $m['branch_id'] : null);

$vitals = db_fetch_one('SELECT * FROM patient_vitals WHERE visit_id = ? ORDER BY id DESC LIMIT 1', [$m['visit_id']], 'i');
$prescription = $m['visit_id'] ? db_fetch_one('SELECT * FROM prescriptions WHERE visit_id = ? ORDER BY id DESC LIMIT 1', [$m['visit_id']], 'i') : null;
$rxItems = $prescription ? db_fetch_all('SELECT pi.*, med.medicine_name, med.unit FROM prescription_items pi LEFT JOIN medicines med ON med.id = pi.medicine_id WHERE pi.prescription_id = ?', [(int) $prescription['id']], 'i') : [];
$labOrder = $m['visit_id'] ? db_fetch_one('SELECT * FROM lab_orders WHERE visit_id = ? ORDER BY id DESC LIMIT 1', [$m['visit_id']], 'i') : null;

ui_page_open(['title' => 'Consultation — ' . $m['patient_name'], 'icon' => 'fa-file-medical',
    'breadcrumb' => ['Medical Records' => '/medical', 'Detail' => null]]);
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3" id="printArea">
      <div class="card-header d-flex align-items-center py-2">
        <i class="fa-solid fa-file-medical me-2 text-secondary"></i>Consultation Record
        <span class="ms-auto small text-muted"><?= fmt_date($m['created_at'], true) ?></span>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-6">
            <div class="fw-bold"><?= e($m['patient_name']) ?> <span class="text-muted small">(<?= e($m['patient_code']) ?>)</span></div>
            <div class="small text-muted"><?= e(or_na($m['gender'])) ?> · <?= e(or_na($m['blood_group'])) ?> · DOB <?= fmt_date($m['date_of_birth']) ?></div>
          </div>
          <div class="col-md-6 text-md-end small text-muted">
            <div><?= e($m['doctor_name'] ? 'Dr. ' . $m['doctor_name'] : 'Doctor: N/A') ?></div>
            <div><?= e(or_na($m['branch_name'])) ?></div>
          </div>
        </div>
        <?php
        $fields = [
            'Chief Complaint' => $m['chief_complaint'],
            'History' => $m['history'],
            'Examination' => $m['examination'],
            'Diagnosis' => trim(($m['diagnosis_code'] ?? '') . ' — ' . ($m['diagnosis_name'] ?? '')) ?: null,
            'Treatment' => $m['treatment'],
            'Notes' => $m['notes'],
        ];
        foreach ($fields as $label => $val): ?>
          <div class="mb-2">
            <div class="small fw-semibold text-uppercase text-muted"><?= e($label) ?></div>
            <div class="small"><?= e(or_na($val)) ?></div>
          </div>
        <?php endforeach; ?>

        <?php if ($vitals): ?>
        <hr>
        <div class="small fw-semibold text-uppercase text-muted mb-1">Vitals</div>
        <div class="d-flex flex-wrap gap-3 small">
          <span><i class="fa-solid fa-temperature-half me-1 text-danger"></i><?= e(or_na($vitals['temperature'])) ?> °C</span>
          <span><i class="fa-solid fa-weight-scale me-1 text-secondary"></i><?= e(or_na($vitals['weight'])) ?> kg</span>
          <span><i class="fa-solid fa-ruler-vertical me-1 text-secondary"></i><?= e(or_na($vitals['height'])) ?> cm</span>
          <span><i class="fa-solid fa-heart-pulse me-1 text-danger"></i><?= e(or_na($vitals['blood_pressure'])) ?> / <?= e(or_na($vitals['pulse'])) ?> bpm</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($prescription): ?>
    <div class="card mb-3">
      <div class="card-header py-2"><i class="fa-solid fa-prescription me-2 text-secondary"></i>Prescription <?= e($prescription['prescription_code'] ?? '') ?>
        <span class="badge <?= ['pending' => 'text-bg-warning', 'dispensed' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$prescription['status']] ?? 'text-bg-secondary' ?> ms-2"><?= label_case($prescription['status']) ?></span>
      </div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Qty</th><th>Instructions</th></tr></thead>
        <tbody>
        <?php foreach ($rxItems as $it): ?>
          <tr>
            <td class="small fw-semibold"><?= e($it['medicine_name'] ?? $it['medicine_name_free'] ?? 'N/A') ?></td>
            <td class="small"><?= e(or_na($it['dosage'])) ?></td>
            <td class="small"><?= e(or_na($it['frequency'])) ?></td>
            <td class="small"><?= e(or_na($it['duration'])) ?></td>
            <td class="small"><?= (int) $it['quantity'] ?> <?= e(or_na($it['unit'])) ?></td>
            <td class="small"><?= e(or_na($it['instructions'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header py-2">Linked Records</div>
      <div class="card-body d-grid gap-2 small">
        <div><span class="text-muted">Visit:</span> <?= e($m['visit_id'] ? 'VIS record #' . $m['visit_id'] : 'N/A') ?></div>
        <?php if ($labOrder): ?>
          <div><span class="text-muted">Lab order:</span> <?= e($labOrder['order_code'] ?? '') ?>
            <?php if (has_permission('laboratory.view')): ?><a class="btn btn-sm btn-link p-0" href="/laboratory/view/<?= (int) $labOrder['id'] ?>">view</a><?php endif; ?>
          </div>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-brand" href="/patients/view/<?= (int) $m['patient_id'] ?>"><i class="fa-solid fa-hospital-user me-1"></i>Patient Timeline</a>
        <?php if (has_permission('laboratory.create')): ?>
          <a class="btn btn-sm btn-outline-brand" href="/laboratory/create?patient_id=<?= (int) $m['patient_id'] ?>&visit_id=<?= (int) ($m['visit_id'] ?? 0) ?>"><i class="fa-solid fa-flask-vial me-1"></i>Order Lab Test</a>
        <?php endif; ?>
        <button class="btn btn-sm btn-light border" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print Record</button>
      </div>
    </div>
  </div>
</div>
<?php
ui_page_close();
