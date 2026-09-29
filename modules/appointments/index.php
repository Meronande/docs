<?php
/**
 * /appointments — list with filters + quick status management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('appointments.view');

$dateF = get('date', '');
$fromF = get('from', '');
$toF = get('to', '');
$doctorF = get('doctor_id', '');
$statusF = get('status_id', '');
$branchF = get('branch_id', '');
$q = get('q', '');
$overdueOnly = get('filter') === 'overdue';

$where = ' WHERE 1=1';
$params = [];
if ($dateF !== '' && valid_date($dateF)) { $where .= ' AND a.appointment_date = ?'; $params[] = $dateF; }
if ($fromF !== '' && valid_date($fromF)) { $where .= ' AND a.appointment_date >= ?'; $params[] = $fromF; }
if ($toF !== '' && valid_date($toF)) { $where .= ' AND a.appointment_date <= ?'; $params[] = $toF; }
if ($doctorF !== '') { $where .= ' AND a.doctor_id = ?'; $params[] = (int) $doctorF; }
if ($statusF !== '') { $where .= ' AND a.status_id = ?'; $params[] = (int) $statusF; }
if ($q !== '') {
    $where .= ' AND (p.full_name LIKE ? OR p.patient_code LIKE ? OR a.appointment_code LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND a.branch_id = ?'; $params[] = (int) $branchF; }
elseif (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND a.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }
if ($overdueOnly) {
    $where .= " AND CONCAT(a.appointment_date, ' ', a.appointment_time) < NOW() AND s.is_final = 0";
}

$total = (int) db_fetch_value(
    "SELECT COUNT(*) FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     JOIN appointment_statuses s ON s.id = a.status_id
     $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT a.*, p.full_name patient_name, p.patient_code, p.phone patient_phone,
            d.full_name doctor_name, dep.department_name, t.type_name,
            s.status_name, s.badge_class, s.is_final, b.branch_name
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     JOIN appointment_statuses s ON s.id = a.status_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN departments dep ON dep.id = a.department_id
     LEFT JOIN appointment_types t ON t.id = a.appointment_type_id
     LEFT JOIN branches b ON b.id = a.branch_id
     $where ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT $perPage OFFSET $offset",
    $params
);
$doctors = db_fetch_all('SELECT id, full_name FROM doctors WHERE status = 1' . (scope_branch_id() !== null ? ' AND branch_id = ' . (int) scope_branch_id() : '') . ' ORDER BY full_name');
$statuses = db_fetch_all('SELECT id, status_name, badge_class FROM appointment_statuses WHERE status = 1 ORDER BY sort_order');

ui_page_open(['title' => 'Appointments', 'icon' => 'fa-calendar-check', 'breadcrumb' => ['Appointments' => null]]);

// Auto-open the booking modal when arriving via /appointments?new=1
if (get('new', '') === '1' && has_permission('appointments.create')) {
    echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.querySelector('[data-action="create"][data-url="/ajax/appointments"]');
  btn?.click();
  history.replaceState(null, '', '/appointments');
});
</script>
JS;
}
echo '<div data-crud-url="/ajax/appointments" data-crud-title="Appointment">';
echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="page" value="appointments">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Patient / code" value="' . e($q) . '" style="width:160px">';
echo '<input type="date" name="date" class="form-control form-control-sm" value="' . e($dateF) . '" style="width:150px" title="Exact date">';
echo '<select name="doctor_id" class="form-select form-select-sm" style="width:160px"><option value="">All doctors</option>';
foreach ($doctors as $d) echo '<option value="' . (int) $d['id'] . '" ' . ($doctorF === (string) $d['id'] ? 'selected' : '') . '>Dr. ' . e($d['full_name']) . '</option>';
echo '</select>';
echo '<select name="status_id" class="form-select form-select-sm" style="width:150px"><option value="">All statuses</option>';
foreach ($statuses as $s) echo '<option value="' . (int) $s['id'] . '" ' . ($statusF === (string) $s['id'] ? 'selected' : '') . '>' . e($s['status_name']) . '</option>';
echo '</select>';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button>';
if ($overdueOnly) echo '<a class="btn btn-sm btn-warning" href="/appointments">Clear overdue filter</a>';
echo '</form>';
echo '<div class="d-flex gap-2">';
echo '<a class="btn btn-light border' . ($overdueOnly ? '' : ' text-warning-emphasis') . '" href="/appointments?filter=overdue" title="Overdue (time passed, not finalized)"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</a>';
if (has_permission('appointments.create')) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/appointments" data-title="Appointment"><i class="fa-solid fa-calendar-plus me-1"></i>New Appointment</button>';
echo '</div></div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Patient</th><th>Doctor</th><th>Date & Time</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No appointments found</td></tr>';
foreach ($rows as $a) {
    $id = (int) $a['id'];
    $isOverdue = (int) $a['is_final'] === 0 && strtotime($a['appointment_date'] . ' ' . $a['appointment_time']) < time();
    $badgeMap = ['primary' => 'text-bg-primary', 'success' => 'text-bg-success', 'warning' => 'text-bg-warning',
                 'danger' => 'text-bg-danger', 'info' => 'text-bg-info', 'dark' => 'text-bg-dark', 'secondary' => 'text-bg-secondary'];
    $badge = $badgeMap[$a['badge_class']] ?? 'text-bg-secondary';
    echo '<tr' . ($isOverdue ? ' class="table-warning"' : '') . '>';
    echo '<td class="text-nowrap">' . e($a['appointment_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($a['patient_name']) . '</div><div class="small text-muted">' . e($a['patient_code']) . ' · ' . e(or_na($a['patient_phone'])) . '</div></td>';
    echo '<td>' . e($a['doctor_name'] ? 'Dr. ' . $a['doctor_name'] : 'N/A') . '</td>';
    echo '<td class="text-nowrap">' . fmt_date($a['appointment_date']) . ' <span class="fw-semibold">' . date('H:i', strtotime($a['appointment_time'])) . '</span></td>';
    echo '<td class="small">' . e(or_na($a['type_name'])) . '</td>';
    echo '<td>';
    echo '<span class="badge ' . $badge . '">' . e($a['status_name'] ?? 'N/A') . '</span>';
    if ($isOverdue) echo ' <i class="fa-solid fa-clock text-warning" title="Overdue"></i>';
    echo '</td>';
    echo '<td class="text-end text-nowrap">';
    echo '<a class="btn btn-sm btn-light" href="/appointments/view/' . $id . '" title="View"><i class="fa-solid fa-eye"></i></a> ';
    if (has_permission('appointments.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/appointments" data-title="Appointment"><i class="fa-solid fa-pen"></i></button> ';
        // Quick status change
        echo '<div class="btn-group"><button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" title="Change status"><i class="fa-solid fa-arrows-rotate"></i></button>';
        echo '<ul class="dropdown-menu dropdown-menu-end">';
        foreach ($statuses as $s) {
            if ((int) $s['id'] === (int) $a['status_id']) continue;
            echo '<li><button class="dropdown-item small" data-action="appt-status" data-id="' . $id . '" data-status="' . (int) $s['id'] . '">' . e($s['status_name']) . '</button></li>';
        }
        echo '</ul></div> ';
    }
    if (has_permission('appointments.delete') && (int) $a['is_final'] === 0) {
        echo '<button class="btn btn-sm btn-light text-danger" data-action="appt-cancel" data-id="' . $id . '" title="Cancel"><i class="fa-solid fa-ban"></i></button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';

// Small inline handler for quick status change / cancel (uses AppConfirm + postJSON)
echo <<<'JS'
<script>
document.addEventListener('click', async function (ev) {
  const statusBtn = ev.target.closest('[data-action="appt-status"]');
  if (statusBtn) {
    const data = await App.postJSON('/ajax/appointments', { action: 'status', id: statusBtn.dataset.id, status_id: statusBtn.dataset.status });
    if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 500); }
    else App.toast(data.message, 'danger');
    return;
  }
  const cancelBtn = ev.target.closest('[data-action="appt-cancel"]');
  if (cancelBtn) {
    AppConfirm('Cancel appointment?', 'The appointment will be marked as <strong>Cancelled</strong>.', async () => {
      const data = await App.postJSON('/ajax/appointments', { action: 'cancel', id: cancelBtn.dataset.id });
      if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 500); }
      else App.toast(data.message, 'danger');
    }, 'Yes, cancel it');
  }
});
</script>
JS;
ui_page_close();
