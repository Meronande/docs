<?php
/**
 * /payroll — payroll periods (Super Admin).
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/config/payroll.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('payroll.view');
$canManage = has_permission('payroll.manage');

$suggestedMonth = payroll_current_month();
$where = ' WHERE 1=1';
$params = [];
if (!sees_all_branches()) {
    $b = current_user()['branch_id'];
    $where .= ' AND (pp.branch_id = ? OR pp.branch_id IS NULL)';
    $params[] = $b !== null ? (int) $b : 0;
}
$total = (int) db_fetch_value("SELECT COUNT(*) FROM payroll_periods pp $where", $params);
[$offset, $perPage] = paginate($total, 12);
$rows = db_fetch_all(
    "SELECT pp.*, b.branch_name,
            (SELECT COUNT(*) FROM payroll_items pi WHERE pi.period_id = pp.id) employees,
            u.full_name created_by_name
     FROM payroll_periods pp
     LEFT JOIN branches b ON b.id = pp.branch_id
     LEFT JOIN users u ON u.id = pp.created_by
     $where ORDER BY pp.period_month DESC, pp.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$monthSalary = staff_salary_total(scope_branch_id());

ui_page_open(['title' => 'Payroll', 'icon' => 'fa-money-check-dollar', 'breadcrumb' => ['Administration' => null, 'Payroll' => null]]);
?>
<div class="row g-3 mb-4">
  <?= stat_card('Monthly Salary Commitment', money($monthSalary), 'fa-users', 'brand') ?>
  <?php if ($canManage): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body d-flex flex-column justify-content-center gap-2">
        <h6 class="fw-bold mb-0"><i class="fa-solid fa-circle-plus me-2 text-brand"></i>New payroll period</h6>
        <p class="text-muted small mb-1">Generates one row per active employee from their saved salary.</p>
        <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#payrollCreateModal">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i>Create <?= e($suggestedMonth) ?> Payroll
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header py-2"><i class="fa-solid fa-list me-2"></i>Payroll Periods</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Period</th><th>Scope</th><th>Employees</th><th>Gross</th><th>Deductions</th><th>Net Total</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No payroll yet — create the first period above.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $p):
        $badge = $p['status'] === 'draft' ? 'text-bg-secondary' : ($p['status'] === 'approved' ? 'text-bg-primary' : 'text-bg-success');
        $paidExp = $p['status'] === 'paid'
            ? (db_fetch_value("SELECT id FROM expenses WHERE description = ? LIMIT 1", ['Payroll ' . $p['period_month']]) !== null)
            : false;
      ?>
        <tr>
          <td class="fw-bold text-nowrap"><?= e($p['period_month']) ?></td>
          <td class="small"><?= e($p['branch_name'] ?? 'All branches') ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary"><?= (int) $p['employees'] ?></span></td>
          <td class="small"><?= money($p['total_gross']) ?></td>
          <td class="small text-danger"><?= money($p['total_deductions']) ?></td>
          <td class="fw-semibold"><?= money($p['total_net']) ?></td>
          <td><span class="badge <?= $badge ?>"><?= label_case($p['status']) ?></span>
              <?php if ($paidExp): ?><span class="badge text-bg-light border">in expenses</span><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light border" href="/payroll/view/<?= (int) $p['id'] ?>"><i class="fa-solid fa-eye me-1"></i>Open</a>
            <?php if ($canManage): ?>
            <div class="dropdown d-inline">
              <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown" type="button"></button>
              <ul class="dropdown-menu dropdown-menu-end shadow">
                <?php if ($p['status'] === 'draft'): ?>
                <li><button class="dropdown-item" data-action="pp-approve" data-id="<?= (int) $p['id'] ?>"><i class="fa-solid fa-check me-2 text-success"></i>Approve</button></li>
                <li><button class="dropdown-item text-danger" data-action="pp-delete" data-id="<?= (int) $p['id'] ?>"><i class="fa-solid fa-trash me-2"></i>Delete draft</button></li>
                <?php elseif ($p['status'] === 'approved'): ?>
                <li><button class="dropdown-item" data-action="pp-pay" data-id="<?= (int) $p['id'] ?>" data-amount="<?= e(money_raw($p['total_net'])) ?>"><i class="fa-solid fa-money-bill-wave me-2 text-success"></i>Mark paid &amp; book expense</button></li>
                <?php else: ?>
                <li><span class="dropdown-item-text small text-muted">Paid — locked</span></li>
                <?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?>
  <div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted"><?= result_count_label() ?></span><?= pagination_links() ?></div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="payrollCreateModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="payrollCreateForm">
        <div class="modal-header"><h5 class="modal-title">Create Payroll Period</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label required">Period (month)</label>
          <input type="month" class="form-control" name="period_month" value="<?= e($suggestedMonth) ?>" required>
          <div class="mt-3"><label class="form-label">Branch</label>
            <select class="form-select" name="branch_id">
              <option value="">All branches (every active employee)</option>
              <?php foreach (visible_branches() as $b): ?>
                <option value="<?= (int) $b['id'] ?>"><?= e($b['branch_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mt-3"><label class="form-label">Notes</label>
            <input class="form-control" name="notes" maxlength="255" placeholder="Optional — e.g. includes Eid bonus">
          </div>
          <div class="alert alert-info small py-2 mt-3 mb-0">
            <i class="fa-solid fa-circle-info me-1"></i>Each employee's basic salary is pulled from HR (People → Staff). You can adjust bonuses and deductions before approving.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><i class="fa-solid fa-gears me-1"></i>Generate</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('payrollCreateForm');
  form?.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const fd = new FormData(form);
    const data = await App.postJSON('/ajax/payroll', {
      action: 'create',
      period_month: fd.get('period_month'),
      branch_id: fd.get('branch_id') || '',
      notes: fd.get('notes') || ''
    });
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('payrollCreateModal'))?.hide();
      App.toast(data.message, 'success');
      setTimeout(() => location.reload(), 500);
    } else {
      App.toast(data.message || 'Could not create payroll.', 'danger');
    }
  });

  document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('[data-action^="pp-"]');
    if (!btn) return;
    ev.preventDefault();
    const id = btn.dataset.id;
    if (btn.dataset.action === 'pp-approve') {
      const res = await AppConfirm('Approve payroll?', 'Approved payroll is locked and ready to pay.', async () => {
        const d = await App.postJSON('/ajax/payroll', { action: 'approve', period_id: id });
        App.toast(d.message || (d.ok ? 'Approved.' : 'Failed.'), d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(() => location.reload(), 500);
      }, 'Yes, approve');
    }
    if (btn.dataset.action === 'pp-pay') {
      const res = await AppConfirm('Mark payroll as paid?', 'This books <strong>' + App.escapeHtml(btn.dataset.amount) + '</strong> as a Payroll expense in the selected period. This cannot be undone.', async () => {
        const d = await App.postJSON('/ajax/payroll', { action: 'mark_paid', period_id: id });
        App.toast(d.message || (d.ok ? 'Paid.' : 'Failed.'), d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(() => location.reload(), 500);
      }, 'Yes, mark paid');
    }
    if (btn.dataset.action === 'pp-delete') {
      const res = await AppConfirm('Delete draft payroll?', 'All employee rows in this period will be removed.', async () => {
        const d = await App.postJSON('/ajax/payroll', { action: 'delete', period_id: id });
        App.toast(d.message || (d.ok ? 'Deleted.' : 'Failed.'), d.ok ? 'success' : 'danger');
        if (d.ok) setTimeout(() => location.reload(), 500);
      }, 'Yes, delete');
    }
  });
});
</script>
<?php endif; ?>
<?php ui_page_close(); ?>
