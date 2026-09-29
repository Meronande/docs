<?php
/**
 * /laboratory/view/{id} — lab order detail with results.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('laboratory.view');

$id = (int) get('id', '0');
$lo = db_fetch_one(
    'SELECT lo.*, p.full_name patient_name, p.patient_code, p.gender, p.date_of_birth, d.full_name doctor_name, b.branch_name
     FROM lab_orders lo
     JOIN patients p ON p.id = lo.patient_id
     LEFT JOIN doctors d ON d.id = lo.doctor_id
     LEFT JOIN branches b ON b.id = lo.branch_id
     WHERE lo.id = ?', [$id], 'i');
if (!$lo) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
assert_branch_access($lo['branch_id'] !== null ? (int) $lo['branch_id'] : null);
$items = db_fetch_all(
    'SELECT loi.*, t.test_name, t.test_code, c.category_name
     FROM lab_order_items loi
     JOIN laboratory_tests t ON t.id = loi.test_id
     LEFT JOIN lab_test_categories c ON c.id = t.category_id
     WHERE loi.lab_order_id = ?', [$id], 'i');

$badge = ['pending' => 'text-bg-warning', 'in_progress' => 'text-bg-info', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$lo['status']] ?? 'text-bg-secondary';

ui_page_open(['title' => 'Lab Order ' . ($lo['order_code'] ?? '#' . $id), 'icon' => 'fa-flask-vial',
    'breadcrumb' => ['Laboratory' => '/laboratory', 'Detail' => null]]);
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3" id="printArea">
      <div class="card-header d-flex align-items-center py-2">
        <i class="fa-solid fa-flask-vial me-2 text-secondary"></i>Order <?= e($lo['order_code'] ?? '') ?>
        <span class="badge <?= $badge ?> ms-2"><?= label_case($lo['status']) ?></span>
        <span class="ms-auto small text-muted"><?= fmt_date($lo['created_at'], true) ?></span>
      </div>
      <div class="card-body">
        <div class="row small mb-3">
          <div class="col-md-6">
            <div class="fw-bold"><?= e($lo['patient_name']) ?> <span class="text-muted">(<?= e($lo['patient_code']) ?>)</span></div>
            <div class="text-muted"><?= e(or_na($lo['gender'])) ?> · DOB <?= fmt_date($lo['date_of_birth']) ?></div>
          </div>
          <div class="col-md-6 text-md-end text-muted">
            <div><?= e($lo['doctor_name'] ? 'Ordered by Dr. ' . $lo['doctor_name'] : '') ?></div>
            <div><?= e(or_na($lo['branch_name'])) ?></div>
          </div>
        </div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Test</th><th>Result</th><th>Normal Range</th><th>Unit</th><th>Remarks</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td class="small"><span class="fw-semibold"><?= e($it['test_code']) ?></span> — <?= e($it['test_name']) ?>
                <div class="text-muted" style="font-size:.7rem"><?= e(or_na($it['category_name'])) ?></div></td>
              <td class="small fw-semibold"><?= e(or_na($it['result'])) ?></td>
              <td class="small text-muted"><?= e(or_na($it['normal_range'])) ?></td>
              <td class="small"><?= e(or_na($it['unit'])) ?></td>
              <td class="small"><?= e(or_na($it['remarks'])) ?></td>
              <td><span class="badge <?= $it['status'] === 'completed' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= label_case($it['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header py-2">Actions</div>
      <div class="card-body d-grid gap-2">
        <?php if (has_permission('laboratory.result') && $lo['status'] !== 'completed'): ?>
          <button class="btn btn-outline-brand" data-action="create" data-url="/ajax/lab?results=<?= $id ?>" data-title="Enter Results"><i class="fa-solid fa-pen-to-square me-1"></i>Enter Results</button>
        <?php endif; ?>
        <?php if (has_permission('billing.create') && $lo['status'] === 'completed'): ?>
          <a class="btn btn-outline-brand" href="/billing/create?patient_id=<?= (int) $lo['patient_id'] ?>"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Bill Tests</a>
        <?php endif; ?>
        <a class="btn btn-light border" href="/patients/view/<?= (int) $lo['patient_id'] ?>"><i class="fa-solid fa-hospital-user me-1"></i>Patient Timeline</a>
        <button class="btn btn-light border" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print Report</button>
      </div>
    </div>
  </div>
</div>
<?php
ui_page_close();
