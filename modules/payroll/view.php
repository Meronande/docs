<?php
/**
 * /payroll/view/<id> — payroll period detail: per-employee lines, totals, actions.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/config/payroll.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('payroll.view');
$canManage = has_permission('payroll.manage');

$id = (int) get('id', '0');
$period = db_fetch_one(
    'SELECT pp.*, b.branch_name, cb.full_name created_by_name, ab.full_name approved_by_name
     FROM payroll_periods pp
     LEFT JOIN branches b ON b.id = pp.branch_id
     LEFT JOIN users cb ON cb.id = pp.created_by
     LEFT JOIN users ab ON ab.id = pp.approved_by
     WHERE pp.id = ?',
    [$id],
    'i'
);
if (!$period) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
$items = db_fetch_all(
    'SELECT pi.*, s.full_name, s.staff_code, s.position, s.salary staff_salary
     FROM payroll_items pi JOIN staff s ON s.id = pi.staff_id
     WHERE pi.period_id = ? ORDER BY s.full_name',
    [$id],
    'i'
);
$status = (string) $period['status'];
$editable = $status === 'draft' && $canManage;
$badge = $status === 'draft' ? 'text-bg-secondary' : ($status === 'approved' ? 'text-bg-primary' : 'text-bg-success');

ui_page_open(['title' => 'Payroll ' . $period['period_month'], 'icon' => 'fa-money-check-dollar', 'breadcrumb' => ['Administration' => null, 'Payroll' => '/payroll', $period['period_month'] => null]]);
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <span class="badge <?= $badge ?> fs-6"><?= label_case($status) ?></span>
  <span class="text-muted small"><?= e($period['branch_name'] ?? 'All branches') ?> · <?= count($items) ?> employees · created by <?= e($period['created_by_name'] ?? '—') ?></span>
  <div class="ms-auto d-flex gap-2">
    <?php if ($canManage && $status === 'draft'): ?>
      <button class="btn btn-outline-brand" id="btnSaveItems"><i class="fa-solid fa-floppy-disk me-1"></i>Save changes</button>
      <button class="btn btn-brand" id="btnApprove"><i class="fa-solid fa-check me-1"></i>Approve</button>
    <?php elseif ($canManage && $status === 'approved'): ?>
      <button class="btn btn-brand" id="btnPay"><i class="fa-solid fa-money-bill-wave me-1"></i>Mark paid &amp; book expense</button>
    <?php endif; ?>
    <button class="btn btn-light border" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
  </div>
</div>

<div class="row g-3 mb-4">
  <?= stat_card('Gross', money($period['total_gross']), 'fa-sack-dollar', 'brand') ?>
  <?= stat_card('Deductions', money($period['total_deductions']), 'fa-minus', 'danger') ?>
  <?= stat_card('Net Payable', money($period['total_net']), 'fa-money-check-dollar', 'success') ?>
  <?= stat_card('Employees', (string) count($items), 'fa-users', 'info') ?>
</div>

<?php if ($period['notes']): ?>
<div class="alert alert-light border small"><i class="fa-regular fa-note-sticky me-2"></i><?= e($period['notes']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2 d-flex align-items-center">
    <i class="fa-solid fa-users me-2"></i>Employee Payslips — <?= e($period['period_month']) ?>
    <?php if ($editable): ?><span class="ms-auto small text-muted">Adjust values, then Save changes</span><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="payrollGrid">
      <thead class="table-light">
        <tr><th>Employee</th><th>Basic</th><th>Bonus</th><th>Overtime</th><th>Advance Ded.</th><th>Other Ded.</th><th class="text-end">Net Pay</th><th>Paid</th></tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No employees in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($items as $it):
        $net = (float) $it['basic_salary'] + (float) $it['bonus'] + (float) $it['overtime'] - (float) $it['advance_deduction'] - (float) $it['other_deduction'];
      ?>
        <tr>
          <td><div class="fw-semibold small"><?= e($it['full_name']) ?></div>
              <div class="text-muted" style="font-size:.72rem"><?= e($it['staff_code'] . ($it['position'] ? ' · ' . $it['position'] : '')) ?></div></td>
          <td class="small"><?= money($it['basic_salary']) ?>
              <?php if ($it['staff_salary'] !== null && (float) $it['staff_salary'] !== (float) $it['basic_salary']): ?>
                <span class="badge text-bg-warning" title="Salary changed in HR after this payroll was generated">≠ HR</span>
              <?php endif; ?>
          </td>
          <?php foreach (['bonus', 'overtime', 'advance_deduction', 'other_deduction'] as $f): ?>
            <td>
              <?php if ($editable): ?>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm py-1 payroll-in" style="width:96px"
                       data-staff="<?= (int) $it['staff_id'] ?>" data-field="<?= $f ?>" data-basic="<?= e(money_raw($it['basic_salary'])) ?>"
                       value="<?= e(money_raw($it[$f])) ?>">
              <?php else: ?>
                <span class="small"><?= money($it[$f]) ?></span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="text-end fw-semibold small payroll-net" data-staff="<?= (int) $it['staff_id'] ?>"><?= money($net) ?></td>
          <td class="small text-muted"><?= $it['paid_at'] ? fmt_date($it['paid_at']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-semibold">
        <tr><td>Totals</td><td></td><td id="tBonus"></td><td id="tOt"></td><td id="tAdv"></td><td id="tOth"></td><td class="text-end" id="tNet"><?= money($period['total_net']) ?></td><td></td></tr>
      </tfoot>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const grid = document.getElementById('payrollGrid');
  if (!grid) return;

  function fmt(n) { return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  function recalc() {
    let bonus = 0, ot = 0, adv = 0, oth = 0, net = 0;
    grid.querySelectorAll('tbody tr').forEach(tr => {
      const get = f => {
        const inp = tr.querySelector('.payroll-in[data-field="' + f + '"]');
        return inp ? (parseFloat(inp.value) || 0) : (parseFloat((tr.children[
          { bonus: 2, overtime: 3, advance_deduction: 4, other_deduction: 5 }[f]
        ]?.textContent?.replace(/[^\d.-]/g, '')) || 0));
      };
      const basic = parseFloat(tr.querySelector('.payroll-in')?.dataset.basic || tr.children[1]?.textContent?.replace(/[^\d.-]/g, '') || 0) || 0;
      const b = get('bonus'), o = get('overtime'), a = get('advance_deduction'), x = get('other_deduction');
      bonus += b; ot += o; adv += a; oth += x;
      const n = basic + b + o - a - x;
      net += n;
      const netCell = tr.querySelector('.payroll-net');
      if (netCell) netCell.textContent = fmt(n);
    });
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = fmt(v); };
    set('tBonus', bonus); set('tOt', ot); set('tAdv', adv); set('tOth', oth); set('tNet', net);
  }
  grid.addEventListener('input', e => { if (e.target.classList.contains('payroll-in')) recalc(); });
  recalc();

  async function postPayroll(data) { return App.postJSON('/ajax/payroll', data); }

  // Save: serialize bracketed item fields manually (postJSON flattens objects)
  document.getElementById('btnSaveItems')?.addEventListener('click', async function () {
    const parts = new URLSearchParams();
    parts.append('action', 'save_items');
    parts.append('period_id', <?= (int) $id ?>);
    grid.querySelectorAll('.payroll-in').forEach(inp => {
      parts.append('item[' + inp.dataset.staff + '][' + inp.dataset.field + ']', inp.value);
    });
    parts.append('csrf_token', App.csrf);
    const res = await fetch('/ajax/payroll', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': App.csrf },
      body: parts
    });
    const d = await res.json();
    App.toast(d.message || (d.ok ? 'Saved.' : 'Failed.'), d.ok ? 'success' : 'danger');
    if (d.ok) setTimeout(() => location.reload(), 500);
  });

  document.getElementById('btnApprove')?.addEventListener('click', function () {
    AppConfirm('Approve payroll?', 'After approval the period is locked; only "Mark paid" remains.', async () => {
      const d = await postPayroll({ action: 'approve', period_id: <?= (int) $id ?> });
      App.toast(d.message || (d.ok ? 'Approved.' : 'Failed.'), d.ok ? 'success' : 'danger');
      if (d.ok) setTimeout(() => location.reload(), 500);
    }, 'Yes, approve');
  });

  document.getElementById('btnPay')?.addEventListener('click', function () {
    AppConfirm('Mark payroll as paid?', 'This books the net total as a Payroll expense for this month. This cannot be undone.', async () => {
      const d = await postPayroll({ action: 'mark_paid', period_id: <?= (int) $id ?> });
      App.toast(d.message || (d.ok ? 'Paid.' : 'Failed.'), d.ok ? 'success' : 'danger');
      if (d.ok) setTimeout(() => location.reload(), 500);
    }, 'Yes, mark paid');
  });
});
</script>
<?php ui_page_close(); ?>
