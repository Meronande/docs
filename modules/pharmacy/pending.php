<?php
/**
 * /pharmacy/pending — prescriptions waiting to be dispensed.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('pharmacy.view');

$q = get('q', '');
$where = " WHERE r.status = 'pending'";
$params = [];
if ($q !== '') { $where .= ' AND (r.prescription_code LIKE ? OR p.full_name LIKE ? OR p.patient_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if (!sees_all_branches() && current_user()['branch_id'] !== null) { $where .= ' AND r.branch_id = ?'; $params[] = (int) current_user()['branch_id']; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM prescriptions r JOIN patients p ON p.id = r.patient_id $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT r.*, p.full_name patient_name, p.patient_code, d.full_name doctor_name,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(med.medicine_name, pi.medicine_name_free), ' x', pi.quantity) SEPARATOR ', ')
         FROM prescription_items pi LEFT JOIN medicines med ON med.id = pi.medicine_id WHERE pi.prescription_id = r.id) AS items
     FROM prescriptions r
     JOIN patients p ON p.id = r.patient_id
     LEFT JOIN doctors d ON d.id = r.doctor_id
     $where ORDER BY r.id ASC LIMIT $perPage OFFSET $offset",
    $params
);

ui_page_open(['title' => 'Prescriptions to Dispense', 'icon' => 'fa-clipboard-check', 'breadcrumb' => ['Pharmacy' => '/pharmacy', 'Pending' => null]]);
echo '<div data-crud-url="/ajax/pharmacy" data-crud-title="Dispense">';
echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="pharmacy_pending">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Rx code / patient" value="' . e($q) . '" style="width:220px">';
echo '<button class="btn btn-sm btn-outline-brand">Search</button></form>';
echo '<span class="badge text-bg-warning">' . $total . ' pending</span></div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Rx Code</th><th>Patient</th><th>Prescriber</th><th>Items</th><th>Created</th><th class="text-end">Action</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-solid fa-check-circle me-2 text-success"></i>No pending prescriptions</td></tr>';
foreach ($rows as $r) {
    $id = (int) $r['id'];
    echo '<tr>';
    echo '<td class="fw-semibold">' . e($r['prescription_code'] ?? '—') . '</td>';
    echo '<td><div class="fw-semibold">' . e($r['patient_name']) . '</div><div class="small text-muted">' . e($r['patient_code']) . '</div></td>';
    echo '<td class="small">' . e($r['doctor_name'] ? 'Dr. ' . $r['doctor_name'] : 'N/A') . '</td>';
    echo '<td class="small text-muted" style="max-width:340px">' . e(mb_strimwidth((string) ($r['items'] ?? 'N/A'), 0, 90, '…')) . '</td>';
    echo '<td class="small">' . fmt_date($r['created_at'], true) . '</td>';
    echo '<td class="text-end">';
    if (has_permission('pharmacy.sell')) {
        echo '<button class="btn btn-sm btn-brand" data-action="create" data-url="/ajax/pharmacy?rx=' . $id . '" data-title="Dispense Prescription"><i class="fa-solid fa-bag-shopping me-1"></i>Dispense</button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
