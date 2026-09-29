<?php
/**
 * AJAX patients endpoint.
 * GET  /ajax/patients[?id=]        -> registration/edit form
 * POST /ajax/patients action=save  -> create/update (multipart for photo)
 * POST /ajax/patients action=delete
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('patients.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission('patients.create') && !has_permission('patients.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $p = $id ? (db_fetch_one('SELECT * FROM patients WHERE id = ?', [$id], 'i') ?? []) : [];
    $branches = visible_branches();
    $insurance = db_fetch_all('SELECT id, company_name FROM insurance_companies WHERE status = 1 ORDER BY company_name');
    $nextCode = generate_code('patient_prefix', 'patients', 'patient_code');
    $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/patients" enctype="multipart/form-data">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label">Patient ID</label><input class="form-control" value="' . e($p['patient_code'] ?? $nextCode) . '" disabled>';
    echo '<div class="form-text">Generated automatically on save</div></div>';
    echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($p['full_name'] ?? '') . '" required></div>';
    echo '<div class="col-md-4"><label class="form-label required">Gender</label><select class="form-select" name="gender" required><option value="">—</option>';
    foreach (['Male', 'Female', 'Other'] as $g) echo '<option ' . (($p['gender'] ?? '') === $g ? 'selected' : '') . '>' . $g . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" id="dobInput" value="' . e($p['date_of_birth'] ?? '') . '" max="' . date('Y-m-d') . '"></div>';
    echo '<div class="col-md-4"><label class="form-label">Age (auto)</label><input type="number" class="form-control" name="age" id="ageInput" value="' . e((string) ($p['age'] ?? '')) . '" min="0" max="130"></div>';
    echo '<div class="col-md-6"><label class="form-label required">Phone</label><input class="form-control" name="phone" value="' . e($p['phone'] ?? '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="' . e($p['email'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Blood Group</label><select class="form-select" name="blood_group"><option value="">—</option>';
    foreach ($bloodGroups as $bg) echo '<option ' . (($p['blood_group'] ?? '') === $bg ? 'selected' : '') . '>' . $bg . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Marital Status</label><select class="form-select" name="marital_status"><option value="">—</option>';
    foreach (['Single', 'Married', 'Divorced', 'Widowed'] as $ms) echo '<option ' . (($p['marital_status'] ?? '') === $ms ? 'selected' : '') . '>' . $ms . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Occupation</label><input class="form-control" name="occupation" value="' . e($p['occupation'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Nationality</label><input class="form-control" name="nationality" value="' . e($p['nationality'] ?? '') . '"></div>';
    echo '<div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" value="' . e($p['address'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Emergency Contact</label><input class="form-control" name="emergency_contact" value="' . e($p['emergency_contact'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Emergency Phone</label><input class="form-control" name="emergency_phone" value="' . e($p['emergency_phone'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id">';
    echo '<option value="">— Auto (your branch) —</option>';
    foreach ($branches as $b) echo '<option value="' . (int) $b['id'] . '" ' . ((string) ($p['branch_id'] ?? '') === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select><div class="form-text">New patients are assigned to your branch unless you select another.</div></div>';
    echo '<div class="col-md-6"><label class="form-label">Insurance Company</label><select class="form-select" name="insurance_company_id"><option value="">— None —</option>';
    foreach ($insurance as $ic) echo '<option value="' . (int) $ic['id'] . '" ' . ((string) ($p['insurance_company_id'] ?? '') === (string) $ic['id'] ? 'selected' : '') . '>' . e($ic['company_name']) . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Insurance Number</label><input class="form-control" name="insurance_number" value="' . e($p['insurance_number'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Photo</label><input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp">';
    echo '<div class="form-text">JPG, PNG or WEBP, max 4 MB</div></div>';
    echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($p['status'] ?? 1) === 1 ? 'checked' : '') . '> <span class="small text-muted">Active</span></div></div>';
    echo '</div></form>';
    $html = ob_get_clean();

    // Small inline script for auto-age; app.js keeps handling submit.
    $html .= '<script>document.getElementById("dobInput")?.addEventListener("change",function(){var d=this.value;if(!d)return;var b=new Date(d),t=new Date();var a=t.getFullYear()-b.getFullYear();var m=t.getMonth()-b.getMonth();if(m<0||(m===0&&t.getDate()<b.getDate()))a--;document.getElementById("ageInput").value=a>=0?a:"";});</script>';
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
$action = post('action', 'save');
$id = post('id', '') !== '' ? (int) post('id') : null;

if ($action === 'save') {
    if ($id && !has_permission('patients.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if (!$id && !has_permission('patients.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);

    $fullName = post('full_name');
    if ($fullName === '') json_response(['ok' => false, 'message' => 'Full name is required.']);
    $dob = post('date_of_birth');
    if (!valid_date($dob)) json_response(['ok' => false, 'message' => 'Invalid date of birth.']);
    $email = post('email');
    if (!valid_email($email)) json_response(['ok' => false, 'message' => 'Invalid email address.']);

    // Age: derive from DOB when provided; else use manual input
    $age = null;
    if ($dob !== '') {
        $age = age_from_dob($dob);
    } elseif (post('age') !== '') {
        $age = (int) post('age');
    }

    $data = [
        'full_name' => $fullName,
        'gender' => post('gender') ?: null,
        'date_of_birth' => $dob !== '' ? $dob : null,
        'age' => $age,
        'phone' => post('phone') ?: null,
        'email' => $email !== '' ? $email : null,
        'address' => post('address') ?: null,
        'emergency_contact' => post('emergency_contact') ?: null,
        'emergency_phone' => post('emergency_phone') ?: null,
        'blood_group' => post('blood_group') ?: null,
        'marital_status' => post('marital_status') ?: null,
        'occupation' => post('occupation') ?: null,
        'nationality' => post('nationality') ?: null,
        'insurance_company_id' => post('insurance_company_id') !== '' ? (int) post('insurance_company_id') : null,
        'insurance_number' => post('insurance_number') ?: null,
        'branch_id' => post('branch_id') !== '' ? (int) post('branch_id') : (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null),
        'status' => isset($_POST['status']) ? 1 : 0,
    ];

    // Photo upload
    $photoErr = null;
    if (!empty($_FILES['photo']['name'])) {
        try {
            $path = upload_image($_FILES['photo'], 'patients', 4194304);
            if ($path) $data['photo'] = $path;
        } catch (RuntimeException $ex) {
            $photoErr = $ex->getMessage();
        }
    }

    try {
        $savedId = db_transaction(function () use ($data, $id, $fullName) {
            if ($id) {
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                db_execute("UPDATE patients SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', 'Patient', $id, 'Updated patient: ' . $fullName);
                return $id;
            }
            $data['patient_code'] = generate_code('patient_prefix', 'patients', 'patient_code');
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $marks = implode(', ', array_fill(0, count($data), '?'));
            $newId = db_execute("INSERT INTO patients ($cols) VALUES ($marks)", array_values($data));
            audit_log('create', 'Patient', $newId, 'Registered patient: ' . $fullName . ' (' . $data['patient_code'] . ')');
            notify(['permission_key' => 'patients.view', 'branch_id' => $data['branch_id']],
                'New patient registered',
                $fullName . ' (' . $data['patient_code'] . ') has been registered.',
                'success', 'patient', $newId, '/patients/view/' . $newId);
            return $newId;
        });
        $msg = 'Patient registered successfully.';
        if ($id) $msg = 'Patient updated successfully.';
        if ($photoErr) $msg .= ' Note: ' . $photoErr;
        json_response(['ok' => true, 'message' => $msg, 'id' => $savedId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

if ($action === 'delete') {
    if (!has_permission('patients.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $row = db_fetch_one('SELECT full_name, patient_code FROM patients WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);
    $refs = [
        'appointments' => ['appointments', 'patient_id'],
        'medical records' => ['medical_records', 'patient_id'],
        'prescriptions' => ['prescriptions', 'patient_id'],
        'lab orders' => ['lab_orders', 'patient_id'],
        'invoices' => ['invoices', 'patient_id'],
    ];
    foreach ($refs as $label => [$t, $c]) {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [$id], 'i');
        if ($n > 0) {
            json_response(['ok' => false, 'message' => "The patient has $n $label. This record is in use — deactivate instead of deleting."]);
        }
    }
    db_transaction(function () use ($id, $row) {
        db_execute('DELETE FROM patients WHERE id = ?', [$id], 'i');
        audit_log('delete', 'Patient', $id, 'Deleted patient: ' . $row['full_name'] . ' (' . ($row['patient_code'] ?? '') . ')');
    });
    json_response(['ok' => true, 'message' => 'Patient deleted successfully.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
