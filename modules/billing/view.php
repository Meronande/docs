<?php
/**
 * /billing/view/{id} — invoice detail, print & receipt.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('billing.view');

$id = (int) get('id', '0');
$inv = db_fetch_one(
    'SELECT i.*, p.full_name patient_name, p.patient_code, p.phone patient_phone, p.gender, p.date_of_birth,
            b.branch_name, b.address branch_address, b.phone branch_phone,
            u.full_name created_by_name
     FROM invoices i
     JOIN patients p ON p.id = i.patient_id
     LEFT JOIN branches b ON b.id = i.branch_id
     LEFT JOIN users u ON u.id = i.created_by
     WHERE i.id = ?',
    [$id],
    'i'
);
if (!$inv) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    return true;
}
assert_branch_access($inv['branch_id'] !== null ? (int) $inv['branch_id'] : null);

$items = db_fetch_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id], 'i');
$payments = db_fetch_all(
    'SELECT pay.*, m.method_name, u.full_name recorded_by
     FROM payments pay
     LEFT JOIN payment_methods m ON m.id = pay.payment_method_id
     LEFT JOIN users u ON u.id = pay.created_by
     WHERE pay.invoice_id = ? ORDER BY pay.id DESC',
    [$id],
    'i'
);

ui_page_open(['title' => 'Invoice ' . ($inv['invoice_number'] ?? '#' . $id), 'icon' => 'fa-file-invoice-dollar',
    'breadcrumb' => ['Finance' => null, 'Invoices' => '/billing', 'View' => null]]);
$statusBadge = ['unpaid' => 'text-bg-danger', 'partial' => 'text-bg-warning', 'paid' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card printable-area" id="invoiceCard">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-4">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="brand-badge"><i class="fa-solid fa-heart-pulse"></i></span>
              <span class="fw-bold fs-5"><?= e(setting('clinic_name', 'Clinic')) ?></span>
            </div>
            <div class="small text-muted">
              <?= e(or_na($inv['branch_name'], 'Main Branch')) ?>
              <?php if ($inv['branch_address']): ?><br><?= e($inv['branch_address']) ?><?php endif; ?>
              <?php if ($inv['branch_phone']): ?> · <?= e($inv['branch_phone']) ?><?php endif; ?>
            </div>
          </div>
          <div class="text-lg-end">
            <h4 class="fw-bold mb-0">INVOICE</h4>
            <div class="fw-semibold text-brand"><?= e($inv['invoice_number'] ?? ('#' . $id)) ?></div>
            <div class="small text-muted">Issued <?= fmt_date($inv['created_at']) ?></div>
            <span class="badge <?= $statusBadge[$inv['status']] ?? 'text-bg-secondary' ?> mt-1"><?= label_case($inv['status']) ?></span>
          </div>
        </div>

        <div class="row mb-4 small">
          <div class="col-md-6">
            <div class="text-uppercase text-muted mb-1" style="font-size:.72rem">Billed To</div>
            <div class="fw-semibold"><?= e($inv['patient_name']) ?></div>
            <div class="text-muted"><?= e($inv['patient_code']) ?><?= $inv['patient_phone'] ? ' · ' . e($inv['patient_phone']) : '' ?></div>
            <div class="text-muted"><?= e(or_na($inv['gender'])) ?><?= $inv['date_of_birth'] ? ' · ' . (age_from_dob($inv['date_of_birth']) ?? '—') . ' yrs' : '' ?></div>
          </div>
          <div class="col-md-6 text-md-end">
            <div class="text-uppercase text-muted mb-1" style="font-size:.72rem">Created By</div>
            <div class="fw-semibold"><?= e(or_na($inv['created_by_name'])) ?></div>
            <div class="text-muted"><?= fmt_date($inv['created_at'], true) ?></div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr><th style="width:50px">#</th><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr>
            </thead>
            <tbody>
            <?php $i = 1; foreach ($items as $it): ?>
              <tr>
                <td class="text-muted"><?= $i++ ?></td>
                <td><?= e($it['description'] ?? 'Service') ?></td>
                <td class="text-center"><?= (float) $it['quantity'] == floor((float) $it['quantity']) ? (int) $it['quantity'] : e((string) $it['quantity']) ?></td>
                <td class="text-end text-nowrap"><?= money($it['unit_price']) ?></td>
                <td class="text-end text-nowrap fw-semibold"><?= money($it['total']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="5" class="text-center text-muted py-3">No line items</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="row justify-content-end mt-3">
          <div class="col-md-5">
            <div class="small">
              <div class="d-flex justify-content-between py-1"><span>Subtotal</span><span><?= money($inv['subtotal']) ?></span></div>
              <div class="d-flex justify-content-between py-1"><span>Discount</span><span>- <?= money($inv['discount']) ?></span></div>
              <div class="d-flex justify-content-between py-1"><span>Tax (<?= e((string) setting('tax_percent', '0')) ?>%)</span><span><?= money($inv['tax']) ?></span></div>
              <div class="d-flex justify-content-between py-2 border-top border-bottom fw-bold fs-6"><span>Total</span><span><?= money($inv['total']) ?></span></div>
              <div class="d-flex justify-content-between py-1 text-success"><span>Paid</span><span><?= money($inv['paid_amount']) ?></span></div>
              <div class="d-flex justify-content-between py-1 <?= (float) $inv['balance'] > 0 ? 'text-danger fw-bold' : 'fw-bold' ?>"><span>Balance Due</span><span><?= money($inv['balance']) ?></span></div>
            </div>
          </div>
        </div>

        <div class="text-center text-muted small mt-4 pt-3 border-top print-only-soft">
          Thank you for choosing <?= e(setting('clinic_name', 'our clinic')) ?>. Get well soon!
        </div>
      </div>
    </div>

    <div class="card mt-3 no-print">
      <div class="card-header py-2 d-flex align-items-center">
        <span><i class="fa-solid fa-money-bill-wave me-2 text-success"></i>Payments</span>
        <?php if ($inv['status'] !== 'cancelled' && (float) $inv['balance'] > 0 && has_permission('payments.create')): ?>
          <button class="btn btn-sm btn-brand ms-auto" data-action="create" data-url="/ajax/billing?pay=<?= $id ?>" data-title="Record Payment">
            <i class="fa-solid fa-plus me-1"></i>Record Payment</button>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Code</th><th>Date</th><th>Method</th><th>Reference</th><th>Paid By</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php if (!$payments): ?><tr><td colspan="6" class="text-center text-muted py-3">No payments recorded</td></tr><?php endif; ?>
          <?php foreach ($payments as $pay): ?>
            <tr>
              <td class="small text-nowrap"><?= e($pay['payment_code'] ?? '—') ?></td>
              <td class="small text-nowrap"><?= fmt_date($pay['payment_date'], true) ?></td>
              <td class="small"><?= e(or_na($pay['method_name'])) ?></td>
              <td class="small"><?= e(or_na($pay['reference_number'])) ?></td>
              <td class="small"><?= e(or_na($pay['paid_by'])) ?></td>
              <td class="text-end text-nowrap fw-semibold text-success"><?= money($pay['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4 no-print">
    <div class="card mb-3">
      <div class="card-header py-2"><i class="fa-solid fa-circle-info me-2 text-brand"></i>Summary</div>
      <div class="card-body small">
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Status</span>
          <span class="badge <?= $statusBadge[$inv['status']] ?? 'text-bg-secondary' ?>"><?= label_case($inv['status']) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Payments</span><span><?= count($payments) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Branch</span><span><?= e(or_na($inv['branch_name'])) ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Linked Visit</span>
          <span><?= $inv['visit_id'] ? '<a href="/medical/view/' . (int) $inv['visit_id'] . '">Visit #' . (int) $inv['visit_id'] . '</a>' : '—' ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Updated</span><span><?= fmt_date($inv['updated_at'], true) ?></span></div>
        <?php if ($inv['status'] !== 'cancelled' && (float) $inv['balance'] > 0 && has_permission('billing.delete')): ?>
          <hr class="my-2">
          <button class="btn btn-sm btn-outline-danger w-100" data-action="inv-cancel" data-id="<?= $id ?>">
            <i class="fa-solid fa-ban me-1"></i>Cancel Invoice</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2"><i class="fa-solid fa-print me-2 text-brand"></i>Actions</div>
      <div class="card-body d-grid gap-2">
        <button class="btn btn-brand" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print Invoice</button>
        <button class="btn btn-outline-brand" onclick="window.print()"><i class="fa-solid fa-receipt me-1"></i>Print Receipt</button>
        <a href="/patients/view/<?= (int) $inv['patient_id'] ?>" class="btn btn-light border"><i class="fa-solid fa-hospital-user me-1"></i>Open Patient</a>
        <?php if (has_permission('insurance.view')): ?>
          <a href="/insurance/claims?invoice_id=<?= $id ?>" class="btn btn-light border"><i class="fa-solid fa-shield-halved me-1"></i>Insurance Claims</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function (ev) {
  const btn = ev.target.closest('[data-action="inv-cancel"]');
  if (!btn || btn.disabled) return;
  ev.preventDefault();
  AppConfirm('Cancel this invoice?', 'The invoice will be marked as cancelled.', async () => {
    const data = await App.postJSON('/ajax/billing', { action: 'cancel', id: btn.dataset.id });
    if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 400); }
    else App.toast(data.message, 'danger');
  });
});
</script>
<?php
ui_page_close();
