<?php
/**
 * /medical — EMR list.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('medical.view');

$q = get('q', '');
$fromF = get('from', '');
$toF = get('to', '');
$branchId = scope_branch_id();

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (p.full_name LIKE ? OR p.patient_code LIKE ? OR m.chief_complaint LIKE ? OR dx.diagnosis_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($fromF !== '' && valid_date($fromF)) { $where .= ' AND DATE(m.created_at) >= ?'; $params[] = $fromF; }
if ($toF !== '' && valid_date($toF)) { $where .= ' AND DATE(m.created_at) <= ?'; $params[] = $toF; }
if ($branchId !== null) { $where .= ' AND m.branch_id = ?'; $params[] = $branchId; }

$total = (int) db_fetch_value(
    "SELECT COUNT(*) FROM medical_records m
     JOIN patients p ON p.id = m.patient_id
     LEFT JOIN diagnoses dx ON dx.id = m.diagnosis_id
     $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT m.*, p.full_name patient_name, p.patient_code, dx.diagnosis_name, d.full_name doctor_name, b.branch_name
     FROM medical_records m
     JOIN patients p ON p.id = m.patient_id
     LEFT JOIN diagnoses dx ON dx.id = m.diagnosis_id
     LEFT JOIN doctors d ON d.id = m.doctor_id
     LEFT JOIN branches b ON b.id = m.branch_id
     $where ORDER BY m.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Medical Records', 'icon' => 'fa-file-medical', 'breadcrumb' => ['Medical Records' => null]]);
echo '<div data-crud-url="/ajax/medical" data-crud-title="Consultation">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="medical">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Patient / complaint / diagnosis" value="' . e($q) . '" style="width:230px">';
echo '<input type="date" name="from" class="form-control form-control-sm" value="' . e($fromF) . '" style="width:145px">';
echo '<input type="date" name="to" class="form-control form-control-sm" value="' . e($toF) . '" style="width:145px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if (has_permission('medical.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/medical" data-title="Consultation"><i class="fa-solid fa-file-medical me-1"></i>New Consultation</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Date</th><th>Patient</th><th>Chief Complaint</th><th>Diagnosis</th><th>Doctor</th><th>Branch</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No records found</td></tr>';
foreach ($rows as $m) {
    $id = (int) $m['id'];
    echo '<tr>';
    echo '<td class="small text-nowrap">' . fmt_date($m['created_at'], true) . '</td>';
    echo '<td><div class="fw-semibold">' . e($m['patient_name']) . '</div><div class="small text-muted">' . e($m['patient_code']) . '</div></td>';
    echo '<td class="small" style="max-width:260px">' . e(mb_strimwidth((string) $m['chief_complaint'], 0, 90, '…')) . '</td>';
    echo '<td>' . (isset($m['diagnosis_name']) && $m['diagnosis_name'] ? '<span class="badge text-bg-light border">' . e($m['diagnosis_name']) . '</span>' : '<span class="text-muted small">N/A</span>') . '</td>';
    echo '<td class="small">' . e($m['doctor_name'] ? 'Dr. ' . $m['doctor_name'] : 'N/A') . '</td>';
    echo '<td class="small">' . e(or_na($m['branch_name'])) . '</td>';
    echo '<td class="text-end text-nowrap">';
    echo '<a class="btn btn-sm btn-light" href="/medical/view/' . $id . '" title="View"><i class="fa-solid fa-eye"></i></a> ';
    if (has_permission('medical.edit')) echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/medical" data-title="Consultation"><i class="fa-solid fa-pen"></i></button> ';
    if (has_permission('medical.delete')) echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="/ajax/medical" data-name="record for ' . e($m['patient_name']) . '" data-title="Consultation"><i class="fa-solid fa-trash"></i></button>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
