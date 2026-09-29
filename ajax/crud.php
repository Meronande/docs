<?php
/**
 * Generic CRUD engine for dynamic master data.
 *
 * GET  ?resource=branches[&id=]           -> { ok, html } (form)
 * POST ?resource=branches  action=save    -> create/update
 * POST ?resource=branches  action=delete  -> safe delete (blocks if referenced)
 * POST ?resource=branches  action=toggle  -> activate/deactivate
 *
 * Whitelist defines table, permission, fields, labels and referenced-table checks.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

// ----------------------------------------------------------------------
// Resource whitelist
// ----------------------------------------------------------------------

function crud_resources(): array
{
    return [
        'branches' => [
            'table' => 'branches', 'perm' => 'branches', 'title' => 'Branch',
            'list_url' => '/branches', 'name_col' => 'branch_name',
            'fields' => [
                ['col' => 'branch_code', 'label' => 'Branch Code', 'type' => 'text', 'required' => true],
                ['col' => 'branch_name', 'label' => 'Branch Name', 'type' => 'text', 'required' => true],
                ['col' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                ['col' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['col' => 'address', 'label' => 'Address', 'type' => 'text'],
                ['col' => 'city', 'label' => 'City', 'type' => 'text'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['users', 'patients', 'doctors', 'appointments', 'invoices'],
        ],
        'departments' => [
            'table' => 'departments', 'perm' => 'departments', 'title' => 'Department',
            'list_url' => '/departments', 'name_col' => 'department_name',
            'fields' => [
                ['col' => 'department_name', 'label' => 'Department Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'branch_id', 'label' => 'Branch (blank = all branches)', 'type' => 'select', 'source' => 'branches'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['doctors_via_doctor_departments', 'services', 'appointments', 'staff', 'invoices_via_services'],
        ],
        'specializations' => [
            'table' => 'specializations', 'perm' => 'specializations', 'title' => 'Specialization',
            'list_url' => '/specializations', 'name_col' => 'name',
            'fields' => [
                ['col' => 'name', 'label' => 'Specialization', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['doctors'],
        ],
        'appointment_types' => [
            'table' => 'appointment_types', 'perm' => 'masterdata', 'title' => 'Appointment Type',
            'list_url' => '/masterdata/appointment-types', 'name_col' => 'type_name',
            'fields' => [
                ['col' => 'type_name', 'label' => 'Type Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['appointments'],
        ],
        'appointment_statuses' => [
            'table' => 'appointment_statuses', 'perm' => 'masterdata', 'title' => 'Appointment Status',
            'list_url' => '/masterdata/appointment-statuses', 'name_col' => 'status_name',
            'fields' => [
                ['col' => 'status_name', 'label' => 'Status Name', 'type' => 'text', 'required' => true],
                ['col' => 'badge_class', 'label' => 'Badge Color', 'type' => 'select', 'options' => ['primary' => 'Blue', 'success' => 'Green', 'warning' => 'Yellow', 'danger' => 'Red', 'info' => 'Cyan', 'dark' => 'Dark', 'secondary' => 'Gray']],
                ['col' => 'is_final', 'label' => 'Final status (ends workflow)', 'type' => 'checkbox'],
                ['col' => 'sort_order', 'label' => 'Sort Order', 'type' => 'number'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['appointments'],
        ],
        'payment_methods' => [
            'table' => 'payment_methods', 'perm' => 'masterdata', 'title' => 'Payment Method',
            'list_url' => '/masterdata/payment-methods', 'name_col' => 'method_name',
            'fields' => [
                ['col' => 'method_name', 'label' => 'Method Name', 'type' => 'text', 'required' => true],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['payments', 'expenses'],
        ],
        'suppliers' => [
            'table' => 'suppliers', 'perm' => 'masterdata', 'title' => 'Supplier',
            'list_url' => '/masterdata/suppliers', 'name_col' => 'supplier_name',
            'fields' => [
                ['col' => 'supplier_name', 'label' => 'Supplier Name', 'type' => 'text', 'required' => true],
                ['col' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                ['col' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['col' => 'address', 'label' => 'Address', 'type' => 'text'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['medicines'],
        ],
        'medicine_categories' => [
            'table' => 'medicine_categories', 'perm' => 'masterdata', 'title' => 'Medicine Category',
            'list_url' => '/masterdata/medicine-categories', 'name_col' => 'category_name',
            'fields' => [
                ['col' => 'category_name', 'label' => 'Category Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['medicines'],
        ],
        'lab_test_categories' => [
            'table' => 'lab_test_categories', 'perm' => 'masterdata', 'title' => 'Lab Test Category',
            'list_url' => '/masterdata/lab-test-categories', 'name_col' => 'category_name',
            'fields' => [
                ['col' => 'category_name', 'label' => 'Category Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['laboratory_tests'],
        ],
        'services' => [
            'table' => 'services', 'perm' => 'masterdata', 'title' => 'Service',
            'list_url' => '/masterdata/services', 'name_col' => 'service_name',
            'fields' => [
                ['col' => 'service_code', 'label' => 'Service Code', 'type' => 'text', 'hint' => 'Leave blank to auto-generate'],
                ['col' => 'service_name', 'label' => 'Service Name', 'type' => 'text', 'required' => true],
                ['col' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => 'departments'],
                ['col' => 'price', 'label' => 'Price', 'type' => 'number', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['invoice_items'],
        ],
        'diagnoses' => [
            'table' => 'diagnoses', 'perm' => 'diagnoses', 'title' => 'Diagnosis',
            'list_url' => '/diagnoses', 'name_col' => 'diagnosis_name',
            'fields' => [
                ['col' => 'diagnosis_code', 'label' => 'Code', 'type' => 'text', 'hint' => 'Leave blank to auto-generate'],
                ['col' => 'diagnosis_name', 'label' => 'Diagnosis Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['medical_records'],
        ],
        'insurance_companies' => [
            'table' => 'insurance_companies', 'perm' => 'insurance', 'title' => 'Insurance Company',
            'list_url' => '/insurance/companies', 'name_col' => 'company_name',
            'fields' => [
                ['col' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'required' => true],
                ['col' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                ['col' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['col' => 'address', 'label' => 'Address', 'type' => 'text'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['patients', 'insurance_claims'],
        ],
        'expense_categories' => [
            'table' => 'expense_categories', 'perm' => 'masterdata', 'title' => 'Expense Category',
            'list_url' => '/masterdata/expense-categories', 'name_col' => 'category_name',
            'fields' => [
                ['col' => 'category_name', 'label' => 'Category Name', 'type' => 'text', 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['col' => 'status', 'label' => 'Active', 'type' => 'checkbox'],
            ],
            'ref_checks' => ['expenses'],
        ],
        'users' => [
            'table' => 'users', 'perm' => 'users', 'title' => 'User',
            'list_url' => '/users', 'name_col' => 'full_name',
            'fields' => [], // users handled by dedicated module
        ],
    ];
}

function crud_perm_key(array $res, string $suffix): string
{
    return $res['perm'] . '.' . $suffix;
}

/** Tables that reference the resource's table (for safe delete). */
function crud_ref_table_for(string $check): array
{
    $map = [
        'users' => ['users', 'branch_id'],
        'patients' => ['patients', 'branch_id'],
        'doctors' => ['doctors', 'branch_id'],
        'appointments' => ['appointments', 'branch_id'],
        'invoices' => ['invoices', 'branch_id'],
        'services' => ['services', 'department_id'],
        'appointments_via_type' => ['appointments', 'appointment_type_id'],
        'appointments_via_department' => ['appointments', 'department_id'],
        'appointments_via_status' => ['appointments', 'status_id'],
        'staff' => ['staff', 'department_id'],
        'invoice_items' => ['invoice_items', 'service_id'],
        'medical_records' => ['medical_records', 'diagnosis_id'],
        'medicines' => ['medicines', 'category_id'],
        'medicines_via_supplier' => ['medicines', 'supplier_id'],
        'laboratory_tests' => ['laboratory_tests', 'category_id'],
        'expenses' => ['expenses', 'category_id'],
        'expenses_via_method' => ['expenses', 'payment_method_id'],
        'payments_via_method' => ['payments', 'payment_method_id'],
        'doctors_via_doctor_departments' => ['doctor_departments', 'department_id'],
        'invoices_via_services' => ['invoice_items', 'service_id'],
        'patients_via_insurance' => ['patients', 'insurance_company_id'],
        'insurance_claims' => ['insurance_claims', 'insurance_company_id'],
    ];
    return $map[$check] ?? null;
}

$resources = crud_resources();
$resourceKey = get('resource', post('resource', ''));
if (!isset($resources[$resourceKey])) {
    json_response(['ok' => false, 'message' => 'Unknown resource.'], 404);
}
$res = $resources[$resourceKey];
$table = $res['table'];

// ---------------------------------------------------------------------
// Permission enforcement
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', 'save');
    if ($action === 'save' && post('id', '') === '') {
        $needed = 'create';
    } else {
        $needed = $action === 'delete' ? 'delete' : 'edit';
    }
    $allowed = has_permission(crud_perm_key($res, $needed))
        || ($res['perm'] === 'masterdata' && has_permission('masterdata.manage'))
        || ($action !== 'delete' && has_permission(crud_perm_key($res, 'edit')));
    if (!$allowed) {
        json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }
    require_csrf();
} else {
    if (!has_permission(crud_perm_key($res, 'edit')) && !has_permission(crud_perm_key($res, 'view'))) {
        json_response(['ok' => false, 'message' => 'You do not have permission to view this form.'], 403);
    }
}

// ---------------------------------------------------------------------
// Form rendering (GET)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $row = [];
    if ($id) {
        $row = db_fetch_one("SELECT * FROM `$table` WHERE id = ?", [$id], 'i') ?? [];
        if (!$row) json_response(['ok' => false, 'message' => 'Record not found.'], 404);
    }

    $html = '<form id="crudForm" data-url="' . e('/ajax/crud?resource=' . $resourceKey) . '">';
    $html .= '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    $html .= '<input type="hidden" name="action" value="save">';
    $html .= '<div class="row g-3">';
    foreach ($res['fields'] as $f) {
        $val = $row[$f['col']] ?? ($f['type'] === 'checkbox' ? '1' : '');
        if ($f['type'] === 'checkbox' && !$id) $val = '1';
        $required = !empty($f['required']) ? ' required' : '';
        $width = in_array($f['type'], ['textarea'], true) ? 12 : (in_array($f['type'], ['select', 'email', 'number'], true) ? 6 : 6);
        $html .= '<div class="col-md-' . $width . '">';
        $html .= '<label class="form-label' . ($required ? ' required' : '') . '">' . e($f['label']) . '</label>';
        switch ($f['type']) {
            case 'textarea':
                $html .= '<textarea class="form-control" name="' . e($f['col']) . '" rows="3">' . e((string) $val) . '</textarea>';
                break;
            case 'checkbox':
                $checked = ((string) $val === '1' || $val === 1 || $val === true);
                $html .= '<div class="form-check form-switch mt-1"><input class="form-check-input" type="checkbox" value="1" name="' . e($f['col']) . '" ' . ($checked ? 'checked' : '') . '></div>';
                break;
            case 'select':
                if (!empty($f['options'])) {
                    $html .= '<select class="form-select" name="' . e($f['col']) . '">';
                    foreach ($f['options'] as $ov => $ol) {
                        $html .= '<option value="' . e((string) $ov) . '" ' . ((string) $val === (string) $ov ? 'selected' : '') . '>' . e($ol) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    $source = $f['source'] ?? 'branches';
                    $opts = [];
                    if ($source === 'branches') $opts = visible_branches();
                    elseif ($source === 'departments') {
                        $b = scope_branch_id();
                        $opts = db_fetch_all("SELECT id, department_name AS name FROM departments WHERE status = 1" . ($b !== null ? " AND (branch_id = ? OR branch_id IS NULL)" : ""), $b !== null ? [$b] : []);
                    }
                    $html .= '<select class="form-select" name="' . e($f['col']) . '"><option value="">— None —</option>';
                    foreach ($opts as $o) {
                        $html .= '<option value="' . e((string) $o['id']) . '" ' . ((string) ($row[$f['col']] ?? '') === (string) $o['id'] ? 'selected' : '') . '>' . e($o['name']) . '</option>';
                    }
                    $html .= '</select>';
                }
                break;
            case 'number':
                $html .= '<input type="number" step="0.01" min="0" class="form-control" name="' . e($f['col']) . '" value="' . e((string) $val) . '"' . $required . '>';
                break;
            case 'email':
                $html .= '<input type="email" class="form-control" name="' . e($f['col']) . '" value="' . e((string) $val) . '">';
                break;
            default:
                $html .= '<input type="text" class="form-control" name="' . e($f['col']) . '" value="' . e((string) $val) . '"' . $required . '>';
        }
        if (!empty($f['hint'])) {
            $html .= '<div class="form-text">' . e($f['hint']) . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div></form>';
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save (create/update)
// ---------------------------------------------------------------------
if (post('action') === 'save') {
    $id = post('id', '') !== '' ? (int) post('id') : null;
    $data = [];
    foreach ($res['fields'] as $f) {
        $col = $f['col'];
        if ($f['type'] === 'checkbox') {
            $data[$col] = isset($_POST[$col]) ? 1 : 0;
        } elseif ($f['type'] === 'number') {
            $data[$col] = post($col) === '' ? null : (float) post($col);
        } elseif (str_ends_with($col, '_id')) {
            $data[$col] = post($col) === '' ? null : (int) post($col);
        } else {
            $data[$col] = post($col) !== '' ? post($col) : null;
        }
    }

    try {
        $newId = db_transaction(function () use ($res, $table, $data, $id, $resourceKey) {
            if ($id) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
                db_execute("UPDATE `$table` SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', $res['title'], $id, 'Updated ' . strtolower($res['title']) . ' #' . $id);
                return $id;
            }
            // Auto codes where the table has one
            if ($resourceKey === 'branches' && empty($data['branch_code'])) $data['branch_code'] = generate_code('branch_prefix', 'branches', 'branch_code', 3);
            if ($resourceKey === 'services' && empty($data['service_code'])) $data['service_code'] = generate_code('service_prefix', 'services', 'service_code');
            if ($resourceKey === 'diagnoses' && empty($data['diagnosis_code'])) $data['diagnosis_code'] = generate_code('diagnosis_prefix', 'diagnoses', 'diagnosis_code');
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $marks = implode(', ', array_fill(0, count($data), '?'));
            $newId = db_execute("INSERT INTO `$table` ($cols) VALUES ($marks)", array_values($data));
            audit_log('create', $res['title'], $newId, 'Created ' . strtolower($res['title']) . ': ' . ($data[$res['name_col']] ?? ''));
            return $newId;
        });

        if (!$id && $resourceKey === 'branches') {
            notify_permission('branches.view', 'New branch created', 'Branch "' . ($data['branch_name'] ?? '') . '" was created.', 'system', null, 'branch', $newId, '/branches');
        }
        json_response(['ok' => true, 'message' => ucfirst($res['title']) . ' saved successfully.', 'id' => $newId]);
    } catch (Throwable $ex) {
        $msg = APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.';
        if (str_contains($ex->getMessage(), 'Duplicate entry')) {
            $msg = 'A record with this name/code already exists.';
        }
        json_response(['ok' => false, 'message' => $msg]);
    }
}

// ---------------------------------------------------------------------
// Delete (safe)
// ---------------------------------------------------------------------
if (post('action') === 'delete') {
    $id = (int) post('id', '0');
    $row = db_fetch_one("SELECT * FROM `$table` WHERE id = ?", [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Record not found.'], 404);

    foreach ($res['ref_checks'] as $check) {
        $ref = crud_ref_table_for($check);
        if (!$ref) continue;
        [$refTable, $refCol] = $ref;
        $count = (int) db_fetch_value("SELECT COUNT(*) FROM `$refTable` WHERE `$refCol` = ?", [$id], 'i');
        if ($count > 0) {
            json_response([
                'ok' => false,
                'message' => "This record is already being used ($count related record" . ($count === 1 ? '' : 's') . ") and cannot be deleted. Deactivate it instead.",
            ]);
        }
    }

    try {
        db_transaction(function () use ($table, $id, $res, $row) {
            db_execute("DELETE FROM `$table` WHERE id = ?", [$id], 'i');
            audit_log('delete', $res['title'], $id, 'Deleted ' . strtolower($res['title']) . ': ' . ($row[$res['name_col']] ?? ''));
        });
        json_response(['ok' => true, 'message' => ucfirst($res['title']) . ' deleted successfully.']);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => 'This record is already being used and cannot be deleted. Deactivate it instead.']);
    }
}

// ---------------------------------------------------------------------
// Toggle status
// ---------------------------------------------------------------------
if (post('action') === 'toggle') {
    $id = (int) post('id', '0');
    $row = db_fetch_one("SELECT status FROM `$table` WHERE id = ?", [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Record not found.'], 404);
    $new = ((int) $row['status']) === 1 ? 0 : 1;
    db_execute("UPDATE `$table` SET status = ? WHERE id = ?", [$new, $id], 'ii');
    audit_log($new === 1 ? 'activate' : 'deactivate', $res['title'], $id,
        ($new === 1 ? 'Activated' : 'Deactivated') . ' ' . strtolower($res['title']) . ': ' . ($row[$res['name_col']] ?? ''));
    json_response(['ok' => true, 'message' => 'Status updated.', 'status' => $new]);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
