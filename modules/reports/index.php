<?php
/**
 * /reports — reporting hub: overview, patients, appointments, revenue, expenses,
 * pharmacy sales, lab tests. Supports ?report= & CSV export & print view.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('reports.view');

$reports = [
    'overview'     => ['label' => 'Overview', 'icon' => 'fa-gauge-high'],
    'patients'     => ['label' => 'Patients', 'icon' => 'fa-hospital-user'],
    'appointments' => ['label' => 'Appointments', 'icon' => 'fa-calendar-check'],
    'revenue'      => ['label' => 'Revenue & Payments', 'icon' => 'fa-sack-dollar'],
    'expenses'     => ['label' => 'Expenses', 'icon' => 'fa-receipt'],
    'pharmacy'     => ['label' => 'Pharmacy Sales', 'icon' => 'fa-pills'],
    'lab'          => ['label' => 'Laboratory', 'icon' => 'fa-flask-vial'],
];

$report = get('report', 'overview');
if (!isset($reports[$report])) $report = 'overview';
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
$branchF = get('branch_id', '');
if ($from === '' || !valid_date($from)) $from = date('Y-m-01');
if ($to === '' || !valid_date($to)) $to = date('Y-m-d');
$dateCond = " BETWEEN '" . $from . " 00:00:00' AND '" . $to . " 23:59:59'";

/** Branch scope helper: returns [sql, params] appended conditions for a given alias. */
$branchCond = function (string $alias = '') use ($branchF): array {
    if ($branchF !== '' && sees_all_branches()) {
        return [' AND ' . ($alias ? $alias . '.' : '') . 'branch_id = ?', [(int) $branchF]];
    }
    $b = scope_branch_id();
    if ($b !== null) return [' AND ' . ($alias ? $alias . '.' : '') . 'branch_id = ?', [$b]];
    return ['', []];
};

// ---------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------
if (get('export') === 'csv') {
    require_permission('reports.export');
    [$bc, $bp] = $branchCond();
    $headers = [];
    $rows = [];
    switch ($report) {
        case 'patients':
            $headers = ['Code', 'Name', 'Gender', 'Age', 'Phone', 'Branch', 'Registered'];
            $rows = db_fetch_all("SELECT patient_code, full_name, gender, age, phone, b.branch_name, created_at
                FROM patients p LEFT JOIN branches b ON b.id = p.branch_id
                WHERE p.created_at $dateCond $bc ORDER BY p.id DESC LIMIT 5000", $bp);
            break;
        case 'appointments':
            $headers = ['Code', 'Date', 'Patient', 'Doctor', 'Type', 'Status'];
            $rows = db_fetch_all("SELECT a.appointment_code, CONCAT(a.appointment_date, ' ', a.appointment_time) appt_at, p.full_name patient_name,
                    d.full_name doctor_name, at.type_name, COALESCE(aps.status_name, 'Pending') status
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                LEFT JOIN doctors d ON d.id = a.doctor_id
                LEFT JOIN appointment_types at ON at.id = a.appointment_type_id
                LEFT JOIN appointment_statuses aps ON aps.id = a.status_id
                WHERE TIMESTAMP(a.appointment_date, a.appointment_time) $dateCond $bc ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 5000", $bp);
            break;
        case 'revenue':
            $headers = ['Invoice', 'Date', 'Patient', 'Total', 'Paid', 'Balance', 'Status'];
            $rows = db_fetch_all("SELECT i.invoice_number, i.created_at, p.full_name patient_name, i.total, i.paid_amount, i.balance, i.status
                FROM invoices i JOIN patients p ON p.id = i.patient_id
                WHERE i.created_at $dateCond AND i.status != 'cancelled' $bc ORDER BY i.id DESC LIMIT 5000", $bp);
            break;
        case 'expenses':
            $headers = ['Date', 'Category', 'Description', 'Method', 'Branch', 'Amount'];
            $rows = db_fetch_all("SELECT ex.expense_date, c.category_name, ex.description, m.method_name, b.branch_name, ex.amount
                FROM expenses ex
                LEFT JOIN expense_categories c ON c.id = ex.category_id
                LEFT JOIN payment_methods m ON m.id = ex.payment_method_id
                LEFT JOIN branches b ON b.id = ex.branch_id
                WHERE ex.expense_date $dateCond $bc ORDER BY ex.expense_date DESC LIMIT 5000", $bp);
            break;
        case 'pharmacy':
            $headers = ['Sale', 'Date', 'Patient', 'Items', 'Amount'];
            $rows = db_fetch_all("SELECT s.sale_code, s.created_at, p.full_name patient_name,
                    (SELECT COUNT(*) FROM pharmacy_sale_items si WHERE si.sale_id = s.id) items, s.total_amount
                FROM pharmacy_sales s LEFT JOIN patients p ON p.id = s.patient_id
                WHERE s.created_at $dateCond $bc ORDER BY s.id DESC LIMIT 5000", $bp);
            break;
        case 'lab':
            $headers = ['Order', 'Date', 'Patient', 'Tests', 'Status'];
            $rows = db_fetch_all("SELECT lo.order_code, lo.created_at, p.full_name patient_name,
                    COALESCE(GROUP_CONCAT(lt.test_name SEPARATOR ', '), '—') tests, lo.status
                FROM lab_orders lo
                JOIN patients p ON p.id = lo.patient_id
                LEFT JOIN lab_order_items loi ON loi.lab_order_id = lo.id
                LEFT JOIN laboratory_tests lt ON lt.id = loi.test_id
                WHERE lo.created_at $dateCond $bc ORDER BY lo.id DESC LIMIT 5000", $bp);
            break;
        default: // overview
            $headers = ['Metric', 'Value'];
            $rows = [
                ['Patients registered', (int) db_fetch_value("SELECT COUNT(*) FROM patients WHERE created_at $dateCond $bc", $bp)],
                ['Appointments', (int) db_fetch_value("SELECT COUNT(*) FROM appointments WHERE appointment_date $dateCond $bc", $bp)],
                ['Invoices', (int) db_fetch_value("SELECT COUNT(*) FROM invoices WHERE created_at $dateCond AND status != 'cancelled' $bc", $bp)],
                ['Revenue collected', money(db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date $dateCond $bc", $bp))],
                ['Expenses', money(db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date $dateCond $bc", $bp))],
            ];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $report . '_' . $from . '_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$reports[$report]['label'] . ' — ' . $from . ' to ' . $to]);
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? money_raw($v) : $v, array_values((array) $r)));
    fclose($out);
    audit_log('export', 'Reports', null, 'Exported ' . $reports[$report]['label'] . ' report (' . $from . ' to ' . $to . ')');
    exit;
}

ui_page_open(['title' => 'Reports', 'icon' => 'fa-chart-line', 'breadcrumb' => ['Administration' => null, 'Reports' => null]]);

// Filter bar
?>
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="report" value="<?= e($report) ?>">
      <select name="report" class="form-select form-select-sm" style="width:190px" onchange="this.form.submit()">
        <?php foreach ($reports as $k => $r): ?>
          <option value="<?= $k ?>" <?= $report === $k ? 'selected' : '' ?>><?= e($r['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="small text-muted">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" style="width:150px">
      <label class="small text-muted">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" style="width:150px">
      <?php if (sees_all_branches()): ?>
      <select name="branch_id" class="form-select form-select-sm" style="width:150px">
        <option value="">All branches</option>
        <?php foreach (visible_branches() as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= $branchF === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-brand">Apply</button>
      <div class="ms-auto d-flex gap-2">
        <?php if (has_permission('reports.export')): ?>
          <a class="btn btn-sm btn-outline-brand" href="/reports?report=<?= e($report) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&branch_id=<?= e($branchF) ?>&export=csv">
            <i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-brand" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
      </div>
    </form>
  </div>
</div>
<?php

[$bc, $bp] = $branchCond();

// ---------------------------------------------------------------------
// Report bodies
// ---------------------------------------------------------------------
function report_table(array $headers, array $rows): string
{
    $html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr>';
    foreach ($headers as $h) $html .= '<th>' . e((string) $h) . '</th>';
    $html .= '</tr></thead><tbody>';
    if (!$rows) $html .= '<tr><td colspan="' . count($headers) . '" class="text-center text-muted py-4">No data for this period</td></tr>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ((array) $r as $v) $html .= '<td class="small">' . e((string) $v) . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

switch ($report) {

    case 'overview':
        $cards = [
            ['Patients', (int) db_fetch_value("SELECT COUNT(*) FROM patients WHERE created_at $dateCond" . str_replace('branch_id', 'p.branch_id', $bc), $bp), 'fa-hospital-user', 'brand'],
            ['Appointments', (int) db_fetch_value("SELECT COUNT(*) FROM appointments a WHERE a.appointment_date $dateCond" . str_replace('branch_id', 'a.branch_id', $bc), $bp), 'fa-calendar-check', 'info'],
            ['Invoices', (int) db_fetch_value("SELECT COUNT(*) FROM invoices i WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc), $bp), 'fa-file-invoice-dollar', 'warning'],
            ['Collected', money(db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date $dateCond$bc", $bp)), 'fa-sack-dollar', 'success'],
            ['Expenses', money(db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date $dateCond$bc", $bp)), 'fa-receipt', 'danger'],
            ['Pharmacy Sales', money(db_fetch_value("SELECT COALESCE(SUM(total_amount),0) FROM pharmacy_sales WHERE created_at $dateCond$bc", $bp)), 'fa-pills', 'brand'],
        ];
        echo '<div class="row g-3 mb-4">';
        foreach ($cards as [$label, $value, $icon, $tone]) echo stat_card($label, $value, $icon, $tone);
        echo '</div>';

        $topServices = db_fetch_all(
            "SELECT COALESCE(s.service_name, ii.description) name, COUNT(*) cnt, SUM(ii.total) total
             FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id
             LEFT JOIN services s ON s.id = ii.service_id
             WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc) . "
             GROUP BY name ORDER BY total DESC LIMIT 10", $bp);
        $monthly = db_fetch_all(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') ym, SUM(amount) total
             FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)$bc
             GROUP BY ym ORDER BY ym", $bp);
        echo '<div class="row g-3"><div class="col-lg-6"><div class="card h-100"><div class="card-header py-2"><i class="fa-solid fa-ranking-star me-2 text-brand"></i>Top Services</div>'
            . '<div class="card-body p-0">' . report_table(['Service', 'Count', 'Total'], array_map(fn($r) => [$r['name'], $r['cnt'], money($r['total'])], $topServices)) . '</div></div></div>';
        echo '<div class="col-lg-6"><div class="card h-100"><div class="card-header py-2"><i class="fa-solid fa-chart-column me-2 text-brand"></i>Monthly Collections (12 mo)</div><div class="card-body"><canvas id="ovChart" height="130"></canvas></div></div></div></div>';
        echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('ovChart');
  if (!el || !window.Chart) return;
  const labels = <?= json_encode(array_column($monthly, 'ym')) ?>;
  const data = <?= json_encode(array_map(fn($r) => (float) $r['total'], $monthly)) ?>;
  new Chart(el, { type: 'bar', options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
    data: { labels, datasets: [{ data, backgroundColor: 'rgba(13,138,128,.75)', borderRadius: 6 }] } });
});
</script>
JS;
        break;

    case 'patients':
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM patients p WHERE p.created_at $dateCond" . str_replace('branch_id', 'p.branch_id', $bc), $bp);
        [$offset, $perPage] = paginate($total, 20);
        $rows = db_fetch_all(
            "SELECT p.patient_code, p.full_name, p.gender, p.age, p.phone, b.branch_name, p.created_at
             FROM patients p LEFT JOIN branches b ON b.id = p.branch_id
             WHERE p.created_at $dateCond" . str_replace('branch_id', 'p.branch_id', $bc) . "
             ORDER BY p.id DESC LIMIT $perPage OFFSET $offset", $bp);
        $byGender = db_fetch_all("SELECT COALESCE(gender,'Unknown') g, COUNT(*) c FROM patients p WHERE p.created_at $dateCond" . str_replace('branch_id', 'p.branch_id', $bc) . " GROUP BY g", $bp);
        echo stat_card('New Patients This Period', $total, 'fa-hospital-user', 'brand');
        echo '<div class="row g-3 mt-0"><div class="col-lg-8"><div class="card"><div class="card-header py-2">Registered Patients</div><div class="card-body p-0">'
            . report_table(['Code', 'Name', 'Gender', 'Age', 'Phone', 'Branch', 'Registered'], array_map(fn($r) => [
                $r['patient_code'], $r['full_name'], $r['gender'], $r['age'], $r['phone'], $r['branch_name'], fmt_date($r['created_at'])], $rows))
            . '</div><div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div></div></div>';
        echo '<div class="col-lg-4"><div class="card h-100"><div class="card-header py-2">By Gender</div><div class="card-body"><canvas id="patGender" height="200"></canvas></div></div></div></div>';
        echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('patGender');
  if (!el || !window.Chart) return;
  const rows = <?= json_encode($byGender) ?>;
  new Chart(el, { type: 'doughnut',
    data: { labels: rows.map(r => r.g), datasets: [{ data: rows.map(r => +r.c),
      backgroundColor: ['rgba(13,138,128,.8)','rgba(255,193,7,.8)','rgba(108,117,125,.6)'] }] },
    options: { plugins: { legend: { position: 'bottom' } } } });
});
</script>
JS;
        break;

    case 'appointments':
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM appointments a WHERE TIMESTAMP(a.appointment_date, a.appointment_time) $dateCond" . str_replace('branch_id', 'a.branch_id', $bc), $bp);
        [$offset, $perPage] = paginate($total, 20);
        $rows = db_fetch_all(
            "SELECT a.appointment_code, CONCAT(a.appointment_date, ' ', a.appointment_time) appt_at, p.full_name patient_name, d.full_name doctor_name,
                    at.type_name, COALESCE(aps.status_name, 'Pending') status
             FROM appointments a
             JOIN patients p ON p.id = a.patient_id
             LEFT JOIN doctors d ON d.id = a.doctor_id
             LEFT JOIN appointment_types at ON at.id = a.appointment_type_id
             LEFT JOIN appointment_statuses aps ON aps.id = a.status_id
             WHERE TIMESTAMP(a.appointment_date, a.appointment_time) $dateCond" . str_replace('branch_id', 'a.branch_id', $bc) . "
             ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT $perPage OFFSET $offset", $bp);
        $byStatus = db_fetch_all("SELECT COALESCE(aps.status_name, 'Pending') status, COUNT(*) c FROM appointments a
             LEFT JOIN appointment_statuses aps ON aps.id = a.status_id
             WHERE TIMESTAMP(a.appointment_date, a.appointment_time) $dateCond" . str_replace('branch_id', 'a.branch_id', $bc) . " GROUP BY status", $bp);
        echo stat_card('Appointments This Period', $total, 'fa-calendar-check', 'brand');
        echo '<div class="row g-3 mt-0"><div class="col-lg-8"><div class="card"><div class="card-header py-2">Appointment Log</div><div class="card-body p-0">'
            . report_table(['Code', 'Date', 'Patient', 'Doctor', 'Type', 'Status'], array_map(fn($r) => [
                $r['appointment_code'], fmt_date($r['appt_at'], true), $r['patient_name'],
                $r['doctor_name'] ? 'Dr. ' . $r['doctor_name'] : '—', $r['type_name'], $r['status']], $rows))
            . '</div><div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div></div></div>';
        echo '<div class="col-lg-4"><div class="card h-100"><div class="card-header py-2">By Status</div><div class="card-body"><canvas id="aptStatus" height="200"></canvas></div></div></div></div>';
        echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('aptStatus');
  if (!el || !window.Chart) return;
  const rows = <?= json_encode($byStatus) ?>;
  new Chart(el, { type: 'pie',
    data: { labels: rows.map(r => r.status), datasets: [{ data: rows.map(r => +r.c),
      backgroundColor: ['rgba(13,138,128,.8)','rgba(25,135,84,.8)','rgba(255,193,7,.8)','rgba(220,53,69,.8)','rgba(108,117,125,.6)'] }] },
    options: { plugins: { legend: { position: 'bottom' } } } });
});
</script>
JS;
        break;

    case 'revenue':
        $cards = [
            ['Invoiced', money(db_fetch_value("SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc), $bp)), 'fa-file-invoice-dollar', 'brand'],
            ['Collected', money(db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date $dateCond$bc", $bp)), 'fa-sack-dollar', 'success'],
            ['Outstanding', money(db_fetch_value("SELECT COALESCE(SUM(balance),0) FROM invoices i WHERE i.status IN ('unpaid','partial')" . str_replace('branch_id', 'i.branch_id', $bc), $bp)), 'fa-scale-balanced', 'danger'],
            ['Invoice Count', (int) db_fetch_value("SELECT COUNT(*) FROM invoices i WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc), $bp), 'fa-hashtag', 'info'],
        ];
        echo '<div class="row g-3 mb-4">';
        foreach ($cards as [$label, $value, $icon, $tone]) echo stat_card($label, $value, $icon, $tone);
        echo '</div>';
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM invoices i WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc), $bp);
        [$offset, $perPage] = paginate($total, 20);
        $rows = db_fetch_all(
            "SELECT i.invoice_number, i.created_at, p.full_name patient_name, i.subtotal, i.discount, i.tax, i.total, i.paid_amount, i.balance, i.status
             FROM invoices i JOIN patients p ON p.id = i.patient_id
             WHERE i.created_at $dateCond AND i.status != 'cancelled'" . str_replace('branch_id', 'i.branch_id', $bc) . "
             ORDER BY i.id DESC LIMIT $perPage OFFSET $offset", $bp);
        echo '<div class="card"><div class="card-header py-2">Invoices</div><div class="card-body p-0">'
            . report_table(['Invoice', 'Date', 'Patient', 'Subtotal', 'Disc.', 'Tax', 'Total', 'Paid', 'Balance', 'Status'], array_map(fn($r) => [
                $r['invoice_number'], fmt_date($r['created_at']), $r['patient_name'], money($r['subtotal']), money($r['discount']),
                money($r['tax']), money($r['total']), money($r['paid_amount']), money($r['balance']), label_case($r['status'])], $rows))
            . '</div><div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div></div>';
        break;

    case 'expenses':
        $sum = (float) db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM expenses ex WHERE ex.expense_date $dateCond$bc", $bp);
        echo stat_card('Total Expenses', money($sum), 'fa-receipt', 'danger');
        $byCat = db_fetch_all(
            "SELECT COALESCE(c.category_name, 'Uncategorized') name, COUNT(*) cnt, SUM(ex.amount) total
             FROM expenses ex LEFT JOIN expense_categories c ON c.id = ex.category_id
             WHERE ex.expense_date $dateCond$bc GROUP BY name ORDER BY total DESC LIMIT 12", $bp);
        echo '<div class="row g-3 mt-0"><div class="col-lg-7"><div class="card"><div class="card-header py-2">By Category</div><div class="card-body p-0">'
            . report_table(['Category', 'Count', 'Total'], array_map(fn($r) => [$r['name'], $r['cnt'], money($r['total'])], $byCat))
            . '</div></div></div>';
        echo '<div class="col-lg-5"><div class="card h-100"><div class="card-header py-2">Share</div><div class="card-body"><canvas id="expChart" height="220"></canvas></div></div></div></div>';
        echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('expChart');
  if (!el || !window.Chart) return;
  const rows = <?= json_encode($byCat) ?>;
  new Chart(el, { type: 'doughnut',
    data: { labels: rows.map(r => r.name), datasets: [{ data: rows.map(r => +r.total),
      backgroundColor: ['rgba(13,138,128,.8)','rgba(255,193,7,.8)','rgba(220,53,69,.8)','rgba(25,135,84,.8)','rgba(13,110,253,.7)','rgba(108,117,125,.6)','rgba(214,51,132,.7)'] }] },
    options: { plugins: { legend: { position: 'bottom' } } } });
});
</script>
JS;
        break;

    case 'pharmacy':
        $sum = (float) db_fetch_value("SELECT COALESCE(SUM(total_amount),0) FROM pharmacy_sales s WHERE s.created_at $dateCond$bc", $bp);
        $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM pharmacy_sales s WHERE s.created_at $dateCond$bc", $bp);
        echo '<div class="row g-3 mb-4">';
        echo stat_card('Sales Count', $cnt, 'fa-bag-shopping', 'brand');
        echo stat_card('Sales Value', money($sum), 'fa-sack-dollar', 'success');
        echo '</div>';
        $total = $cnt;
        [$offset, $perPage] = paginate($total, 20);
        $rows = db_fetch_all(
            "SELECT s.sale_code, s.created_at, p.full_name patient_name,
                (SELECT COUNT(*) FROM pharmacy_sale_items si WHERE si.sale_id = s.id) items, s.total_amount
             FROM pharmacy_sales s LEFT JOIN patients p ON p.id = s.patient_id
             WHERE s.created_at $dateCond$bc ORDER BY s.id DESC LIMIT $perPage OFFSET $offset", $bp);
        $topMeds = db_fetch_all(
            "SELECT m.medicine_name, SUM(si.quantity) qty, SUM(si.total) total
             FROM pharmacy_sale_items si
             JOIN medicines m ON m.id = si.medicine_id
             JOIN pharmacy_sales s ON s.id = si.sale_id
             WHERE s.created_at $dateCond$bc GROUP BY m.medicine_name ORDER BY total DESC LIMIT 10", $bp);
        echo '<div class="card mb-3"><div class="card-header py-2">Top Medicines</div><div class="card-body p-0">'
            . report_table(['Medicine', 'Qty Sold', 'Revenue'], array_map(fn($r) => [$r['medicine_name'], (int) $r['qty'], money($r['total'])], $topMeds))
            . '</div></div>';
        echo '<div class="card"><div class="card-header py-2">Sales</div><div class="card-body p-0">'
            . report_table(['Sale', 'Date', 'Patient', 'Items', 'Amount'], array_map(fn($r) => [
                $r['sale_code'], fmt_date($r['created_at'], true), $r['patient_name'], $r['items'], money($r['total_amount'])], $rows))
            . '</div><div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div></div>';
        break;

    case 'lab':
        $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM lab_orders lo WHERE lo.created_at $dateCond$bc", $bp);
        echo stat_card('Lab Orders', $cnt, 'fa-flask-vial', 'brand');
        $byStatus = db_fetch_all("SELECT status, COUNT(*) c FROM lab_orders lo WHERE lo.created_at $dateCond$bc GROUP BY status", $bp);
        $total = $cnt;
        [$offset, $perPage] = paginate($total, 20);
        $rows = db_fetch_all(
            "SELECT lo.order_code, lo.created_at, p.full_name patient_name,
                    COALESCE(GROUP_CONCAT(lt.test_name SEPARATOR ', '), '—') tests, lo.status
             FROM lab_orders lo
             JOIN patients p ON p.id = lo.patient_id
             LEFT JOIN lab_order_items loi ON loi.lab_order_id = lo.id
             LEFT JOIN laboratory_tests lt ON lt.id = loi.test_id
             WHERE lo.created_at $dateCond$bc GROUP BY lo.id, lo.order_code, lo.created_at, p.full_name, lo.status
             ORDER BY lo.id DESC LIMIT $perPage OFFSET $offset", $bp);
        $topTests = db_fetch_all(
            "SELECT lt.test_name, COUNT(*) cnt FROM lab_orders lo
             JOIN lab_order_items loi ON loi.lab_order_id = lo.id
             LEFT JOIN laboratory_tests lt ON lt.id = loi.test_id
             WHERE lo.created_at $dateCond$bc GROUP BY lt.test_name ORDER BY cnt DESC LIMIT 10", $bp);
        echo '<div class="row g-3 mt-0"><div class="col-lg-7"><div class="card"><div class="card-header py-2">Top Tests</div><div class="card-body p-0">'
            . report_table(['Test', 'Orders'], array_map(fn($r) => [$r['test_name'] ?? 'N/A', $r['cnt']], $topTests))
            . '</div></div></div>';
        echo '<div class="col-lg-5"><div class="card h-100"><div class="card-header py-2">By Status</div><div class="card-body"><canvas id="labChart" height="200"></canvas></div></div></div></div>';
        echo '<div class="card mt-3"><div class="card-header py-2">Orders</div><div class="card-body p-0">'
            . report_table(['Order', 'Date', 'Patient', 'Tests', 'Status'], array_map(fn($r) => [
                $r['order_code'], fmt_date($r['created_at'], true), $r['patient_name'], $r['tests'], label_case($r['status'])], $rows))
            . '</div><div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div></div>';
        echo <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('labChart');
  if (!el || !window.Chart) return;
  const rows = <?= json_encode($byStatus) ?>;
  new Chart(el, { type: 'pie',
    data: { labels: rows.map(r => r.status), datasets: [{ data: rows.map(r => +r.c),
      backgroundColor: ['rgba(255,193,7,.8)','rgba(25,135,84,.8)','rgba(13,110,253,.7)','rgba(220,53,69,.8)'] }] },
    options: { plugins: { legend: { position: 'bottom' } } } });
});
</script>
JS;
        break;
}

ui_page_close();
