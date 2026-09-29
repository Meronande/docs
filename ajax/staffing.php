<?php
/**
 * AJAX staffing endpoints: doctors | staff | departments | specializations
 * GET  /ajax/staffing?type=doctors[&id=]  -> form
 * POST /ajax/staffing  type=doctors action=save|toggle|delete
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
$type = get('type', post('type', ''));
$allowed = ['doctors', 'staff', 'departments', 'specializations'];
if (!in_array($type, $allowed, true)) json_response(['ok' => false, 'message' => 'Unknown type.'], 404);

$permBase = ['doctors' => 'doctors', 'staff' => 'staff', 'departments' => 'departments', 'specializations' => 'specializations'][$type];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission($permBase . '.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

function staffing_branch_opts(): string
{
    $opts = '';
    foreach (visible_branches() as $b) {
        $opts .= '<option value="' . (int) $b['id'] . '">' . e($b['branch_name']) . '</option>';
    }
    return $opts;
}

function staffing_dept_opts(?int $branchId = null): string
{
    $opts = '<option value="">— None —</option>';
    $b = $branchId ?? scope_branch_id();
    $rows = db_fetch_all('SELECT id, department_name FROM departments WHERE status = 1' . ($b !== null ? ' AND (branch_id = ? OR branch_id IS NULL)' : ''), $b !== null ? [$b] : []);
    foreach ($rows as $d) $opts .= '<option value="' . (int) $d['id'] . '">' . e($d['department_name']) . '</option>';
    return $opts;
}

function staffing_dept_opts_list(): array
{
    $b = scope_branch_id();
    return db_fetch_all('SELECT id, department_name FROM departments WHERE status = 1' . ($b !== null ? ' AND (branch_id = ? OR branch_id IS NULL)' : ''), $b !== null ? [$b] : []);
}

$id = get('id', post('id', '')) !== '' ? (int) get('id', post('id', '')) : null;

// ---------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!has_permission($permBase . '.create') && !has_permission($permBase . '.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $row = $id ? (db_fetch_one("SELECT * FROM `$type` WHERE id = ?", [$id], 'i') ?? []) : [];
    ob_start();
    echo '<form id="crudForm" data-url="/ajax/staffing" enctype="multipart/form-data">';
    echo '<input type="hidden" name="type" value="' . e($type) . '">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save">';

    if ($type === 'doctors') {
        echo '<div class="row g-3">';
        echo '<div class="col-md-6"><label class="form-label">Doctor Code</label><input class="form-control" value="' . e($row['doctor_code'] ?? generate_code('doctor_prefix', 'doctors', 'doctor_code')) . '" disabled><div class="form-text">Auto-generated</div></div>';
        echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($row['full_name'] ?? '') . '" required></div>';
        echo '<div class="col-md-6"><label class="form-label">Specialization</label><select class="form-select" name="specialization_id"><option value="">— None —</option>';
        foreach (db_fetch_all('SELECT id, name FROM specializations WHERE status = 1 ORDER BY name') as $s) {
            echo '<option value="' . (int) $s['id'] . '" ' . ((string) ($row['specialization_id'] ?? '') === (string) $s['id'] ? 'selected' : '') . '>' . e($s['name']) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col-md-6"><label class="form-label">License Number</label><input class="form-control" name="license_number" value="' . e($row['license_number'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="' . e($row['phone'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="' . e($row['email'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label required">Consultation Fee</label><input type="number" step="0.01" min="0" class="form-control" name="consultation_fee" value="' . e(money_raw($row['consultation_fee'] ?? 0)) . '" required></div>';
        echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id">' . staffing_branch_opts() . '</select></div>';
        // Departments multi-select
        $linked = $id ? array_map('intval', array_column(db_fetch_all('SELECT department_id FROM doctor_departments WHERE doctor_id = ?', [$id], 'i'), 'department_id')) : [];
        echo '<div class="col-12"><label class="form-label">Departments</label><div class="row">';
        foreach (staffing_dept_opts_list() as $d) {
            echo '<div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="departments[]" value="' . (int) $d['id'] . '" id="dd' . (int) $d['id'] . '" ' . (in_array((int) $d['id'], $linked, true) ? 'checked' : '') . '>';
            echo '<label class="form-check-label small" for="dd' . (int) $d['id'] . '">' . e($d['department_name']) . '</label></div></div>';
        }
        echo '</div></div>';
        echo '<div class="col-md-6"><label class="form-label">Photo</label><input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp"></div>';
        echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($row['status'] ?? 1) === 1 ? 'checked' : '') . '></div></div>';
        echo '</div>';
    }

    if ($type === 'staff') {
        echo '<div class="row g-3">';
        echo '<div class="col-md-6"><label class="form-label">Staff Code</label><input class="form-control" value="' . e($row['staff_code'] ?? generate_code('staff_prefix', 'staff', 'staff_code')) . '" disabled><div class="form-text">Auto-generated</div></div>';
        echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($row['full_name'] ?? '') . '" required></div>';
        echo '<div class="col-md-6"><label class="form-label">Gender</label><select class="form-select" name="gender"><option value="">—</option>';
        foreach (['Male', 'Female', 'Other'] as $g) echo '<option ' . (($row['gender'] ?? '') === $g ? 'selected' : '') . '>' . $g . '</option>';
        echo '</select></div>';
        echo '<div class="col-md-6"><label class="form-label">Position</label><input class="form-control" name="position" value="' . e($row['position'] ?? '') . '" placeholder="e.g. Nurse, Receptionist, Accountant"></div>';
        echo '<div class="col-md-6"><label class="form-label">Department</label><select class="form-select" name="department_id">' . staffing_dept_opts() . '</select></div>';
        echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id">' . staffing_branch_opts() . '</select></div>';
        echo '<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="' . e($row['phone'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="' . e($row['email'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Joining Date</label><input type="date" class="form-control" name="joining_date" value="' . e($row['joining_date'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Salary</label><input type="number" step="0.01" min="0" class="form-control" name="salary" value="' . e(money_raw($row['salary'] ?? '')) . '"></div>';
        echo '<div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" value="' . e($row['address'] ?? '') . '"></div>';
        echo '<div class="col-md-6"><label class="form-label">Photo</label><input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp"></div>';
        echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($row['status'] ?? 1) === 1 ? 'checked' : '') . '></div></div>';
        echo '</div>';
    }

    if ($type === 'departments') {
        echo '<div class="row g-3">';
        echo '<div class="col-md-6"><label class="form-label required">Department Name</label><input class="form-control" name="department_name" value="' . e($row['department_name'] ?? '') . '" required></div>';
        echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">— All branches (shared) —</option>' . staffing_branch_opts() . '</select></div>';
        echo '<div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3">' . e($row['description'] ?? '') . '</textarea></div>';
        echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($row['status'] ?? 1) === 1 ? 'checked' : '') . ' id="dst"><label class="form-check-label" for="dst">Active</label></div></div>';
        echo '</div>';
    }

    if ($type === 'specializations') {
        echo '<div class="row g-3">';
        echo '<div class="col-md-6"><label class="form-label required">Specialization</label><input class="form-control" name="name" value="' . e($row['name'] ?? '') . '" required></div>';
        echo '<div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3">' . e($row['description'] ?? '') . '</textarea></div>';
        echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($row['status'] ?? 1) === 1 ? 'checked' : '') . ' id="sst"><label class="form-check-label" for="sst">Active</label></div></div>';
        echo '</div>';
    }

    echo '</form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if (post('action') === 'save') {
    $needed = $id ? 'edit' : 'create';
    if (!has_permission($permBase . '.' . $needed)) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);

    $branchId = post('branch_id') !== '' ? (int) post('branch_id') : null;
    $status = isset($_POST['status']) ? 1 : 0;

    try {
        $result = db_transaction(function () use ($type, $id, $branchId, $status) {
            if ($type === 'doctors') {
                $fullName = post('full_name');
                if ($fullName === '') throw new RuntimeException('Full name is required.');
                $fee = post('consultation_fee') !== '' ? (float) post('consultation_fee') : 0;
                $data = [
                    'full_name' => $fullName,
                    'specialization_id' => post('specialization_id') !== '' ? (int) post('specialization_id') : null,
                    'license_number' => post('license_number') ?: null,
                    'phone' => post('phone') ?: null,
                    'email' => post('email') ?: null,
                    'consultation_fee' => $fee,
                    'branch_id' => $branchId,
                    'status' => $status,
                ];
                if (!empty($_FILES['photo']['name'])) {
                    $path = upload_image($_FILES['photo'], 'staff', 4194304);
                    if ($path) $data['photo'] = $path;
                }
                if ($id) {
                    $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                    db_execute("UPDATE doctors SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                    $docId = $id;
                    audit_log('update', 'Doctor', $id, 'Updated doctor: ' . $fullName);
                } else {
                    $data['doctor_code'] = generate_code('doctor_prefix', 'doctors', 'doctor_code');
                    $cols = '`' . implode('`, `', array_keys($data)) . '`';
                    $marks = implode(', ', array_fill(0, count($data), '?'));
                    $docId = db_execute("INSERT INTO doctors ($cols) VALUES ($marks)", array_values($data));
                    audit_log('create', 'Doctor', $docId, 'Added doctor: ' . $fullName);
                }
                // Department links
                $deps = array_map('intval', (array) ($_POST['departments'] ?? []));
                db_execute('DELETE FROM doctor_departments WHERE doctor_id = ?', [$docId], 'i');
                foreach ($deps as $depId) {
                    db_execute('INSERT IGNORE INTO doctor_departments (doctor_id, department_id) VALUES (?, ?)', [$docId, $depId], 'ii');
                }
                return ['id' => $docId, 'msg' => 'Doctor saved successfully.'];
            }

            if ($type === 'staff') {
                $fullName = post('full_name');
                if ($fullName === '') throw new RuntimeException('Full name is required.');
                $data = [
                    'full_name' => $fullName,
                    'gender' => post('gender') ?: null,
                    'position' => post('position') ?: null,
                    'department_id' => post('department_id') !== '' ? (int) post('department_id') : null,
                    'branch_id' => $branchId,
                    'phone' => post('phone') ?: null,
                    'email' => post('email') ?: null,
                    'joining_date' => post('joining_date') ?: null,
                    'salary' => post('salary') !== '' ? (float) post('salary') : null,
                    'address' => post('address') ?: null,
                    'status' => $status,
                ];
                if (!empty($_FILES['photo']['name'])) {
                    $path = upload_image($_FILES['photo'], 'staff', 4194304);
                    if ($path) $data['photo'] = $path;
                }
                if ($id) {
                    $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                    db_execute("UPDATE staff SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                    audit_log('update', 'Staff', $id, 'Updated staff: ' . $fullName);
                    return ['id' => $id, 'msg' => 'Staff member updated.'];
                }
                $data['staff_code'] = generate_code('staff_prefix', 'staff', 'staff_code');
                $cols = '`' . implode('`, `', array_keys($data)) . '`';
                $marks = implode(', ', array_fill(0, count($data), '?'));
                $newId = db_execute("INSERT INTO staff ($cols) VALUES ($marks)", array_values($data));
                audit_log('create', 'Staff', $newId, 'Added staff: ' . $fullName);
                return ['id' => $newId, 'msg' => 'Staff member added successfully.'];
            }

            if ($type === 'departments') {
                $name = post('department_name');
                if ($name === '') throw new RuntimeException('Department name is required.');
                if ($id) {
                    db_execute('UPDATE departments SET department_name = ?, description = ?, branch_id = ?, status = ? WHERE id = ?',
                        [$name, post('description') ?: null, $branchId, $status, $id], 'ssiii');
                    audit_log('update', 'Department', $id, 'Updated department: ' . $name);
                    return ['id' => $id, 'msg' => 'Department updated.'];
                }
                $newId = db_execute('INSERT INTO departments (department_name, description, branch_id, status) VALUES (?, ?, ?, ?)',
                    [$name, post('description') ?: null, $branchId, $status], 'ssii');
                audit_log('create', 'Department', $newId, 'Added department: ' . $name);
                return ['id' => $newId, 'msg' => 'Department added successfully.'];
            }

            // specializations
            $name = post('name');
            if ($name === '') throw new RuntimeException('Specialization name is required.');
            if ($id) {
                db_execute('UPDATE specializations SET name = ?, description = ?, status = ? WHERE id = ?',
                    [$name, post('description') ?: null, $status, $id], 'ssii');
                audit_log('update', 'Specialization', $id, 'Updated specialization: ' . $name);
                return ['id' => $id, 'msg' => 'Specialization updated.'];
            }
            $newId = db_execute('INSERT INTO specializations (name, description, status) VALUES (?, ?, ?)',
                [$name, post('description') ?: null, $status], 'ssi');
            audit_log('create', 'Specialization', $newId, 'Added specialization: ' . $name);
            return ['id' => $newId, 'msg' => 'Specialization added successfully.'];
        });
        json_response(['ok' => true, 'message' => $result['msg'], 'id' => $result['id']]);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        $msg = APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.';
        if (str_contains((string) $ex->getMessage(), 'Duplicate entry')) $msg = 'A record with this name already exists.';
        json_response(['ok' => false, 'message' => $msg]);
    }
}

// ---------------------------------------------------------------------
// Toggle / Delete
// ---------------------------------------------------------------------
if (post('action') === 'toggle') {
    if (!has_permission($permBase . '.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $new = ((int) db_fetch_value("SELECT status FROM `$type` WHERE id = ?", [$id], 'i')) === 1 ? 0 : 1;
    db_execute("UPDATE `$type` SET status = ? WHERE id = ?", [$new, $id], 'ii');
    audit_log($new ? 'activate' : 'deactivate', ucfirst($type), $id, ($new ? 'Activated' : 'Deactivated') . " $type #$id");
    json_response(['ok' => true, 'message' => 'Status updated.']);
}

if (post('action') === 'delete') {
    if (!has_permission($permBase . '.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $refMap = [
        'doctors' => [['appointments', 'doctor_id'], ['medical_records', 'doctor_id'], ['prescriptions', 'doctor_id'], ['lab_orders', 'doctor_id']],
        'staff' => [],
        'departments' => [['services', 'department_id'], ['appointments', 'department_id'], ['staff', 'department_id'], ['doctor_departments', 'department_id']],
        'specializations' => [['doctors', 'specialization_id']],
    ];
    foreach ($refMap[$type] as [$t, $c]) {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [$id], 'i');
        if ($n > 0) {
            json_response(['ok' => false, 'message' => "This record is used by $n other record(s) and cannot be deleted. Deactivate it instead."]);
        }
    }
    db_transaction(function () use ($type, $id) {
        if ($type === 'doctors') db_execute('DELETE FROM doctor_departments WHERE doctor_id = ?', [$id], 'i');
        db_execute("DELETE FROM `$type` WHERE id = ?", [$id], 'i');
        audit_log('delete', ucfirst($type), $id, "Deleted $type #$id");
    });
    json_response(['ok' => true, 'message' => 'Deleted successfully.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
