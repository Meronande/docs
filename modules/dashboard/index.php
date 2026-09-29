<?php
/**
 * /dashboard — statistics, charts, alerts. All numbers come from the database.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('dashboard.view');

$range = get('range', 'month');       // today | week | month | year | custom
$from = get('from', '');
$to = get('to', '');
$branchId = scope_branch_id();

[$dateFrom, $dateTo] = match ($range) {
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'month' => [date('Y-m-01'), date('Y-m-d')],
    'year' => [date('Y-01-01'), date('Y-m-d')],
    'custom' => [valid_date($from) ? $from : date('Y-m-01'), valid_date($to) ? $to : date('Y-m-d')],
    default => [date('Y-m-01'), date('Y-m-d')],
};

// ---------------------------------------------------------------------
// Helper to run branch-scoped aggregates
// ---------------------------------------------------------------------
function dash_count(string $sql, array $params = []): int
{
    return (int) db_fetch_value($sql, $params);
}

$today = date('Y-m-d');

// ---------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------
$pWhere = ' WHERE 1=1';
$pParams = [];
if ($branchId !== null) { $pWhere .= ' AND branch_id = ?'; $pParams[] = $branchId; }
$totalPatients = dash_count("SELECT COUNT(*) FROM patients $pWhere", $pParams);
$todayPatients = dash_count("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$today, $branchId] : [$today]);

$aWhere = $branchId !== null ? ' AND branch_id = ?' : '';
$aParams = $branchId !== null ? [$branchId] : [];
$todayAppointments = dash_count("SELECT COUNT(*) FROM appointments WHERE appointment_date = ?$aWhere", array_merge([$today], $aParams));
$waitingNow = dash_count("SELECT COUNT(*) FROM queue WHERE queue_date = ? AND status = 'waiting'" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$today, $branchId] : [$today]);
$apptDone = dash_count("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE a.appointment_date = ? AND s.is_final = 1 AND s.status_name = 'Completed'" . $aWhere, array_merge([$today], $aParams));
$apptCancelled = dash_count("SELECT COUNT(*) FROM appointments a JOIN appointment_statuses s ON s.id = a.status_id WHERE a.appointment_date = ? AND s.status_name IN ('Cancelled','No Show')" . $aWhere, array_merge([$today], $aParams));

$doctorsCount = dash_count('SELECT COUNT(*) FROM doctors WHERE status = 1' . ($branchId !== null ? ' AND branch_id = ?' : ''), $aParams);
$staffCount = dash_count('SELECT COUNT(*) FROM staff WHERE status = 1' . ($branchId !== null ? ' AND branch_id = ?' : ''), $aParams);

$pendingLab = dash_count("SELECT COUNT(*) FROM lab_orders WHERE status IN ('pending','in_progress')" . $aWhere, $aParams);
$pendingPayments = (float) (db_fetch_value("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE status IN ('unpaid','partial') AND status <> 'cancelled'" . $aWhere, $aParams) ?? 0);

$lowStock = dash_count('SELECT COUNT(*) FROM medicines WHERE status = 1 AND stock_quantity <= minimum_stock' . ($branchId !== null ? ' AND branch_id = ?' : ''), $aParams);
$expired = dash_count('SELECT COUNT(*) FROM medicines WHERE status = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()' . ($branchId !== null ? ' AND branch_id = ?' : ''), $aParams);
$expiringSoon = dash_count('SELECT COUNT(*) FROM medicines WHERE status = 1 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . (int) setting('low_stock_alert_days', '30') . ' DAY)' . ($branchId !== null ? ' AND branch_id = ?' : ''), $aParams);

$revToday = (float) (db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = ?" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$today, $branchId] : [$today]) ?? 0);
$expToday = (float) (db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = ?" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$today, $branchId] : [$today]) ?? 0);
$revRange = (float) (db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$dateFrom, $dateTo, $branchId] : [$dateFrom, $dateTo]) ?? 0);
$expRange = (float) (db_fetch_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : ''), $branchId !== null ? [$dateFrom, $dateTo, $branchId] : [$dateFrom, $dateTo]) ?? 0);

// ---------------------------------------------------------------------
// Chart data (last 12 months / last 7 days depending on range)
// ---------------------------------------------------------------------
$days = $range === 'today' ? 7 : 12;
if (in_array($range, ['today', 'week'], true)) {
    $bucket = 'DATE';
    $fromDt = date('Y-m-d', strtotime('-6 days'));
    $toDt = $today;
    $labelFmt = 'd M';
    $n = 7;
} else {
    $bucket = "DATE_FORMAT(created_at, '%Y-%m')";
    $fromDt = date('Y-m-01', strtotime('-11 months'));
    $toDt = $today;
    $labelFmt = 'M y';
    $n = 12;
}

$patientsSeries = [];
for ($i = $n - 1; $i >= 0; $i--) {
    $d = in_array($range, ['today', 'week'], true) ? date('Y-m-d', strtotime("-$i days")) : date('Y-m', strtotime("-$i months"));
    $patientsSeries[$d] = 0;
}
if (in_array($range, ['today', 'week'], true)) {
    $rows = db_fetch_all("SELECT DATE(created_at) d, COUNT(*) c FROM patients WHERE DATE(created_at) BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY DATE(created_at)", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
} else {
    $rows = db_fetch_all("SELECT DATE_FORMAT(created_at, '%Y-%m') d, COUNT(*) c FROM patients WHERE created_at BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY d", $branchId !== null ? [$fromDt . ' 00:00:00', $toDt . ' 23:59:59', $branchId] : [$fromDt . ' 00:00:00', $toDt . ' 23:59:59']);
}
foreach ($rows as $r) { if (isset($patientsSeries[$r['d']])) $patientsSeries[$r['d']] = (int) $r['c']; }

$revenueSeries = [];
for ($i = $n - 1; $i >= 0; $i--) {
    $d = in_array($range, ['today', 'week'], true) ? date('Y-m-d', strtotime("-$i days")) : date('Y-m', strtotime("-$i months"));
    $revenueSeries[$d] = 0.0;
}
if (in_array($range, ['today', 'week'], true)) {
    $rows = db_fetch_all("SELECT DATE(payment_date) d, SUM(amount) c FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY DATE(payment_date)", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
} else {
    $rows = db_fetch_all("SELECT DATE_FORMAT(payment_date, '%Y-%m') d, SUM(amount) c FROM payments WHERE payment_date BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY d", $branchId !== null ? [$fromDt . ' 00:00:00', $toDt . ' 23:59:59', $branchId] : [$fromDt . ' 00:00:00', $toDt . ' 23:59:59']);
}
foreach ($rows as $r) { if (isset($revenueSeries[$r['d']])) $revenueSeries[$r['d']] = (float) $r['c']; }

$apptSeries = [];
for ($i = $n - 1; $i >= 0; $i--) {
    $d = in_array($range, ['today', 'week'], true) ? date('Y-m-d', strtotime("-$i days")) : date('Y-m', strtotime("-$i months"));
    $apptSeries[$d] = 0;
}
if (in_array($range, ['today', 'week'], true)) {
    $rows = db_fetch_all("SELECT appointment_date d, COUNT(*) c FROM appointments WHERE appointment_date BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY appointment_date", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
} else {
    $rows = db_fetch_all("SELECT DATE_FORMAT(appointment_date, '%Y-%m') d, COUNT(*) c FROM appointments WHERE appointment_date BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY d", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
}
foreach ($rows as $r) { if (isset($apptSeries[$r['d']])) $apptSeries[$r['d']] = (int) $r['c']; }

$pharmSeries = [];
for ($i = $n - 1; $i >= 0; $i--) {
    $d = in_array($range, ['today', 'week'], true) ? date('Y-m-d', strtotime("-$i days")) : date('Y-m', strtotime("-$i months"));
    $pharmSeries[$d] = 0.0;
}
if (in_array($range, ['today', 'week'], true)) {
    $rows = db_fetch_all("SELECT DATE(created_at) d, SUM(total_amount) c FROM pharmacy_sales WHERE DATE(created_at) BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY DATE(created_at)", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
} else {
    $rows = db_fetch_all("SELECT DATE_FORMAT(created_at, '%Y-%m') d, SUM(total_amount) c FROM pharmacy_sales WHERE created_at BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY d", $branchId !== null ? [$fromDt . ' 00:00:00', $toDt . ' 23:59:59', $branchId] : [$fromDt . ' 00:00:00', $toDt . ' 23:59:59']);
}
foreach ($rows as $r) { if (isset($pharmSeries[$r['d']])) $pharmSeries[$r['d']] = (float) $r['c']; }

$labSeries = [];
for ($i = $n - 1; $i >= 0; $i--) {
    $d = in_array($range, ['today', 'week'], true) ? date('Y-m-d', strtotime("-$i days")) : date('Y-m', strtotime("-$i months"));
    $labSeries[$d] = 0;
}
if (in_array($range, ['today', 'week'], true)) {
    $rows = db_fetch_all("SELECT DATE(created_at) d, COUNT(*) c FROM lab_orders WHERE DATE(created_at) BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY DATE(created_at)", $branchId !== null ? [$fromDt, $toDt, $branchId] : [$fromDt, $toDt]);
} else {
    $rows = db_fetch_all("SELECT DATE_FORMAT(created_at, '%Y-%m') d, COUNT(*) c FROM lab_orders WHERE created_at BETWEEN ? AND ?" . ($branchId !== null ? ' AND branch_id = ?' : '') . " GROUP BY d", $branchId !== null ? [$fromDt . ' 00:00:00', $toDt . ' 23:59:59', $branchId] : [$fromDt . ' 00:00:00', $toDt . ' 23:59:59']);
}
foreach ($rows as $r) { if (isset($labSeries[$r['d']])) $labSeries[$r['d']] = (int) $r['c']; }

// ---------------------------------------------------------------------
// Alerts: overdue appointments (time passed, not final)
// ---------------------------------------------------------------------
$overdue = db_fetch_all(
    "SELECT a.*, p.full_name patient_name, p.patient_code, d.full_name doctor_name
     FROM appointments a
     JOIN appointment_statuses s ON s.id = a.status_id
     JOIN patients p ON p.id = a.patient_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     WHERE a.appointment_date <= CURDATE()
       AND CONCAT(a.appointment_date, ' ', a.appointment_time) < NOW()
       AND s.is_final = 0" . ($branchId !== null ? ' AND a.branch_id = ?' : '') . "
     ORDER BY a.appointment_date, a.appointment_time LIMIT 8",
    $aParams
);

$lowStockList = db_fetch_all(
    'SELECT medicine_name, stock_quantity, minimum_stock, unit FROM medicines WHERE status = 1 AND stock_quantity <= minimum_stock' . ($branchId !== null ? ' AND branch_id = ?' : '') . ' ORDER BY stock_quantity / GREATEST(minimum_stock,1) LIMIT 6',
    $aParams
);

ui_page_open(['title' => 'Dashboard', 'icon' => 'fa-gauge-high']);
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <form method="get" class="d-flex flex-wrap gap-2 align-items-center" id="dashFilter">
    <input type="hidden" name="page" value="dashboard">
    <select name="range" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
      <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This Week</option>
      <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
      <option value="year" <?= $range === 'year' ? 'selected' : '' ?>>This Year</option>
      <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
    </select>
    <?php if ($range === 'custom'): ?>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
      <button class="btn btn-sm btn-brand">Apply</button>
    <?php endif; ?>
    <?php if (sees_all_branches()): ?>
      <select name="branch_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="">All Branches</option>
        <?php foreach (db_fetch_all('SELECT id, branch_name FROM branches WHERE status = 1 ORDER BY branch_name') as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </form>
  <div class="ms-auto text-muted small">
    <i class="fa-regular fa-calendar me-1"></i><?= fmt_date($dateFrom) ?> — <?= fmt_date($dateTo) ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <?= stat_card("Total Patients", number_format($totalPatients), 'fa-hospital-user', 'brand', has_permission('patients.view') ? '/patients' : null) ?>
  <?= stat_card("Today's Patients", number_format($todayPatients), 'fa-user-plus', 'info') ?>
  <?= stat_card("Today's Appointments", number_format($todayAppointments), 'fa-calendar-check', 'brand', has_permission('appointments.view') ? '/appointments?date=' . $today : null) ?>
  <?= stat_card("Waiting Now", number_format($waitingNow), 'fa-hourglass-half', 'warning', has_permission('queue.view') ? '/queue' : null) ?>
  <?= stat_card("Doctors", number_format($doctorsCount), 'fa-user-doctor', 'info', has_permission('doctors.view') ? '/doctors' : null) ?>
  <?= stat_card("Staff", number_format($staffCount), 'fa-users', 'brand', has_permission('staff.view') ? '/staff' : null) ?>
  <?= stat_card("Pending Lab Orders", number_format($pendingLab), 'fa-flask-vial', 'warning', has_permission('laboratory.view') ? '/laboratory' : null) ?>
  <?= stat_card("Pending Payments", money($pendingPayments), 'fa-file-invoice-dollar', 'danger', has_permission('billing.view') ? '/billing?status=unpaid' : null) ?>
  <?= stat_card("Low Stock Items", number_format($lowStock), 'fa-pills', 'danger', has_permission('pharmacy.view') ? '/pharmacy?filter=low' : null) ?>
  <?= stat_card("Today's Revenue", money($revToday), 'fa-sack-dollar', 'success') ?>
  <?= stat_card("Today's Expenses", money($expToday), 'fa-receipt', 'danger') ?>
  <?= stat_card("Net (" . fmt_date($dateFrom) . "+)", money($revRange - $expRange), 'fa-scale-balanced', 'success') ?>
</div>

<?php if ($overdue): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
  <i class="fa-solid fa-triangle-exclamation mt-1"></i>
  <div>
    <strong><?= count($overdue) ?> appointment<?= count($overdue) === 1 ? '' : 's' ?> need attention</strong> — time has passed but not completed.
    <div class="mt-2 d-flex flex-wrap gap-2">
      <?php foreach ($overdue as $o): ?>
        <a class="btn btn-sm btn-light border" href="/appointments/view/<?= (int) $o['id'] ?>">
          <span class="fw-semibold"><?= e($o['patient_name']) ?></span>
          <span class="text-muted small">· <?= e($o['patient_code']) ?> · <?= fmt_date($o['appointment_date']) ?> <?= date('H:i', strtotime($o['appointment_time'])) ?></span>
        </a>
      <?php endforeach; ?>
      <a class="btn btn-sm btn-warning" href="/appointments?filter=overdue">View all</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header">Patient Registrations & Appointments</div><div class="card-body"><canvas id="chPatients" height="110"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header">Revenue</div><div class="card-body"><canvas id="chRevenue" height="230"></canvas></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Pharmacy Sales</div><div class="card-body"><canvas id="chPharmacy" height="120"></canvas></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Laboratory Orders</div><div class="card-body"><canvas id="chLab" height="120"></canvas></div></div>
  </div>
</div>

<?php if (has_permission('pharmacy.view') && ($lowStockList || $expiringSoon > 0)): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center"><i class="fa-solid fa-pills me-2 text-danger"></i>Pharmacy Alerts
    <a class="btn btn-sm btn-light ms-auto" href="/pharmacy?filter=low">Open pharmacy</a></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-lg-7">
        <h6 class="small text-muted text-uppercase">Low stock</h6>
        <?php if (!$lowStockList): ?><div class="text-muted small">No low stock items. Well done!</div><?php endif; ?>
        <?php foreach ($lowStockList as $m): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <span><?= e($m['medicine_name']) ?></span>
            <span class="badge text-bg-danger"><?= (int) $m['stock_quantity'] ?> <?= e($m['unit'] ?: 'units') ?> (min <?= (int) $m['minimum_stock'] ?>)</span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="col-lg-5">
        <h6 class="small text-muted text-uppercase">Expiry</h6>
        <div class="d-flex gap-2 mt-2">
          <div class="flex-fill stat-card"><div class="stat-value soft-danger"><?= (int) $expired ?></div><div class="stat-label">Expired</div></div>
          <div class="flex-fill stat-card"><div class="stat-value soft-warning"><?= (int) $expiringSoon ?></div><div class="stat-label">Expiring soon (<?= (int) setting('low_stock_alert_days', '30') ?>d)</div></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  const labels = <?= json_encode(array_map(fn($d) => in_array($range, ['today','week'], true) ? date('d M', strtotime($d)) : date('M y', strtotime($d . '-01')), array_keys($patientsSeries))) ?>;
  const grid = { color: '#eef1f5' };
  const baseOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { grid, beginAtZero: true } } };

  new Chart($('#chPatients'), { type: 'line', data: { labels, datasets: [
      { label: 'Patients', data: <?= json_encode(array_values($patientsSeries)) ?>, borderColor: '#0d8a80', backgroundColor: 'rgba(13,138,128,.12)', fill: true, tension: .35 },
      { label: 'Appointments', data: <?= json_encode(array_values($apptSeries)) ?>, borderColor: '#e0a539', backgroundColor: 'rgba(224,165,57,.12)', fill: true, tension: .35 }
    ]}, options: { ...baseOpts, plugins: { legend: { display: true, position: 'bottom' } } } });

  new Chart($('#chRevenue'), { type: 'bar', data: { labels, datasets: [
      { label: 'Revenue', data: <?= json_encode(array_values($revenueSeries)) ?>, backgroundColor: 'rgba(13,138,128,.75)', borderRadius: 6 }
    ]}, options: baseOpts });

  new Chart($('#chPharmacy'), { type: 'line', data: { labels, datasets: [
      { label: 'Sales', data: <?= json_encode(array_values($pharmSeries)) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.12)', fill: true, tension: .35 }
    ]}, options: baseOpts });

  new Chart($('#chLab'), { type: 'line', data: { labels, datasets: [
      { label: 'Lab orders', data: <?= json_encode(array_values($labSeries)) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .35 }
    ]}, options: baseOpts });
});
</script>
<?php
ui_page_close();
