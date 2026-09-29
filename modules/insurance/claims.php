<?php
/**
 * /insurance/claims — insurance claim lifecycle.
 * GET  ?new={invoice_id} -> create claim form
 * POST ?action=save_claim   (create/update)
 * POST ?action=status       (update status: approved/rejected/paid)
 * POST ?action=delete
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('insurance.view');

$canCreate = has_permission('insurance.create');
$canEdit = has_permission('insurance.edit');
$canDelete = has_permission('insurance.delete');

$me = current_user();

// ---------------------------------------------------------------------
// POST handlers (claim save / status / delete)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me) {
    if (!verify_csrf()) {
        flash('danger', 'Invalid security token. Please try again.');
        redirect('/insurance/claims');
    }
    $action = post('action');

    if ($action === 'save_claim') {
        if (!$canCreate && !( $canEdit && post('id') !== '')) {
            flash('danger', 'You do not have permission for this action.');
            redirect('/insurance/claims');
        }
        $id = (int) post('id', '0');
        $invoiceId = (int) post('invoice_id', '0');
        $companyId = (int) post('insurance_company_id', '0');
        $amount = (float) post('claimed_amount', '0');
        if ($invoiceId === 0 || $companyId === 0 || $amount <= 0) {
            flash('danger', 'Invoice, insurance company and a positive amount are required.');
            redirect('/insurance/claims');
        }
        $inv = db_fetch_one('SELECT i.id, i.invoice_number, i.total, p.full_name FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.id = ?', [$invoiceId], 'i');
        if (!$inv) { flash('danger', 'Invoice not found.'); redirect('/insurance/claims'); }
        if ((float) $inv['total'] > 0 && $amount > (float) $inv['total']) {
            flash('warning', 'Claimed amount cannot exceed the invoice total (' . money($inv['total']) . ').');
            redirect('/insurance/claims');
        }
        if ($id > 0) {
            db_execute('UPDATE insurance_claims SET invoice_id = ?, insurance_company_id = ?, claimed_amount = ?, notes = ? WHERE id = ?',
                [$invoiceId, $companyId, $amount, post('notes') ?: null, $id], 'iidss');
            audit_log('update', 'InsuranceClaim', $id, 'Updated claim for invoice ' . ($inv['invoice_number'] ?? $invoiceId));
            flash('success', 'Claim updated.');
        } else {
            $code = generate_code('claim_prefix', 'insurance_claims', 'claim_code');
            $cid = db_execute('INSERT INTO insurance_claims (claim_code, invoice_id, insurance_company_id, claimed_amount, notes) VALUES (?,?,?,?,?)',
                [$code, $invoiceId, $companyId, $amount, post('notes') ?: null], 'siids');
            audit_log('create', 'InsuranceClaim', $cid, 'Created claim ' . $code . ' for invoice ' . ($inv['invoice_number'] ?? $invoiceId) . ' (' . money($amount) . ')');
            notify(['permission_key' => 'insurance.view', 'branch_id' => null],
                'New insurance claim', $code . ' — ' . money($amount) . ' submitted for invoice ' . ($inv['invoice_number'] ?? '') . '.',
                'finance', 'insurance_claim', $cid, '/insurance/claims');
            flash('success', 'Claim ' . $code . ' created.');
        }
        redirect('/insurance/claims');
    }

    if ($action === 'status') {
        if (!$canEdit) { flash('danger', 'You do not have permission for this action.'); redirect('/insurance/claims'); }
        $id = (int) post('id', '0');
        $status = post('status');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'paid'], true)) {
            flash('danger', 'Invalid status.');
            redirect('/insurance/claims');
        }
        $approved = $status === 'pending' ? null : (post('approved_amount') !== '' ? (float) post('approved_amount') : null);
        if (in_array($status, ['approved', 'paid'], true) && ($approved === null || $approved <= 0)) {
            // default to claimed amount if left blank
            $claimed = db_fetch_value('SELECT claimed_amount FROM insurance_claims WHERE id = ?', [$id], 'i');
            $approved = (float) $claimed;
        }
        db_execute('UPDATE insurance_claims SET status = ?, approved_amount = ? WHERE id = ?', [$status, $approved, $id], 'sdi');
        $claim = db_fetch_one('SELECT claim_code FROM insurance_claims WHERE id = ?', [$id], 'i');
        audit_log('update', 'InsuranceClaim', $id, 'Claim ' . ($claim['claim_code'] ?? $id) . ' marked ' . $status . ($approved !== null ? ' (' . money($approved) . ')' : ''));
        notify(['permission_key' => 'payments.view', 'branch_id' => null],
            'Claim ' . ($claim['claim_code'] ?? '') . ' ' . $status,
            $status === 'approved' ? 'Approved amount: ' . money($approved) : 'Status updated to ' . $status . '.',
            'finance', 'insurance_claim', $id, '/insurance/claims');
        flash('success', 'Claim marked as ' . $status . '.');
        redirect('/insurance/claims');
    }

    if ($action === 'delete') {
        if (!$canDelete) { flash('danger', 'You do not have permission for this action.'); redirect('/insurance/claims'); }
        $id = (int) post('id', '0');
        $claim = db_fetch_one('SELECT claim_code FROM insurance_claims WHERE id = ?', [$id], 'i');
        db_execute('DELETE FROM insurance_claims WHERE id = ?', [$id], 'i');
        audit_log('delete', 'InsuranceClaim', $id, 'Deleted claim ' . ($claim['claim_code'] ?? $id));
        flash('success', 'Claim deleted.');
        if (is_ajax()) json_response(['ok' => true, 'message' => 'Claim deleted.']);
        redirect('/insurance/claims');
    }
    if (is_ajax()) json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
    redirect('/insurance/claims');
}

// ---------------------------------------------------------------------
// Listing + filters
// ---------------------------------------------------------------------
$q = get('q', '');
$statusF = get('status', '');
$companyF = get('company_id', '');
$invoiceF = get('invoice_id', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (c.claim_code LIKE ? OR i.invoice_number LIKE ? OR p.full_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($statusF !== '') { $where .= ' AND c.status = ?'; $params[] = $statusF; }
if ($companyF !== '') { $where .= ' AND c.insurance_company_id = ?'; $params[] = (int) $companyF; }
if ($invoiceF !== '') { $where .= ' AND c.invoice_id = ?'; $params[] = (int) $invoiceF; }

$stats = [
    'pending' => (int) db_fetch_value("SELECT COUNT(*) FROM insurance_claims WHERE status = 'pending'"),
    'approved' => (int) db_fetch_value("SELECT COUNT(*) FROM insurance_claims WHERE status = 'approved'"),
    'paid_amt' => (float) db_fetch_value("SELECT COALESCE(SUM(approved_amount),0) FROM insurance_claims WHERE status = 'paid'"),
    'rejected' => (int) db_fetch_value("SELECT COUNT(*) FROM insurance_claims WHERE status = 'rejected'"),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM insurance_claims c JOIN invoices i ON i.id = c.invoice_id JOIN patients p ON p.id = i.patient_id $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT c.*, i.invoice_number, i.total invoice_total, p.full_name patient_name, p.patient_code, ic.company_name
     FROM insurance_claims c
     JOIN invoices i ON i.id = c.invoice_id
     JOIN patients p ON p.id = i.patient_id
     JOIN insurance_companies ic ON ic.id = c.insurance_company_id
     $where ORDER BY c.id DESC LIMIT $perPage OFFSET $offset",
    $params
);
$companies = db_fetch_all('SELECT id, company_name FROM insurance_companies WHERE status = 1 ORDER BY company_name');
$openInvoices = db_fetch_all(
    "SELECT i.id, i.invoice_number, i.total, p.full_name patient_name
     FROM invoices i JOIN patients p ON p.id = i.patient_id
     WHERE i.status != 'cancelled' AND i.total > 0
       AND NOT EXISTS (SELECT 1 FROM insurance_claims c WHERE c.invoice_id = i.id AND c.status IN ('pending','approved','paid'))
     ORDER BY i.id DESC LIMIT 200"
);

$statusBadge = ['pending' => 'text-bg-warning', 'approved' => 'text-bg-info', 'paid' => 'text-bg-success', 'rejected' => 'text-bg-danger'];

ui_page_open(['title' => 'Insurance Claims', 'icon' => 'fa-folder-open',
    'breadcrumb' => ['Finance' => null, 'Insurance' => '/insurance', 'Claims' => null]]);
?>
<div class="row g-3 mb-4">
  <?= stat_card('Pending', $stats['pending'], 'fa-hourglass-half', 'warning', '/insurance/claims?status=pending') ?>
  <?= stat_card('Approved', $stats['approved'], 'fa-circle-check', 'info', '/insurance/claims?status=approved') ?>
  <?= stat_card('Paid Out', money($stats['paid_amt']), 'fa-sack-dollar', 'success', '/insurance/claims?status=paid') ?>
  <?= stat_card('Rejected', $stats['rejected'], 'fa-circle-xmark', 'danger', '/insurance/claims?status=rejected') ?>
</div>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
  <form method="get" class="d-flex flex-wrap gap-2">
    <input name="q" class="form-control form-control-sm" placeholder="Claim, invoice, patient..." value="<?= e($q) ?>" style="width:190px">
    <select name="status" class="form-select form-select-sm" style="width:130px"><option value="">All statuses</option>
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="company_id" class="form-select form-select-sm" style="width:170px"><option value="">All companies</option>
      <?php foreach ($companies as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $companyF === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['company_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-brand">Filter</button>
  </form>
  <?php if ($canCreate): ?>
  <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#claimModal" id="newClaimBtn"><i class="fa-solid fa-plus me-1"></i>New Claim</button>
  <?php endif; ?>
</div>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Claim</th><th>Invoice</th><th>Patient</th><th>Company</th><th>Claimed</th><th>Approved</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No claims found</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
    <tr>
      <td class="fw-semibold text-nowrap"><?= e($r['claim_code'] ?? '—') ?></td>
      <td class="small text-nowrap"><a href="/billing/view/<?= (int) $r['invoice_id'] ?>"><?= e($r['invoice_number'] ?? '—') ?></a>
        <div class="text-muted small"><?= money($r['invoice_total']) ?></div></td>
      <td class="small"><?= e($r['patient_name']) ?></td>
      <td class="small"><?= e($r['company_name']) ?></td>
      <td class="text-nowrap"><?= money($r['claimed_amount']) ?></td>
      <td class="text-nowrap"><?= $r['approved_amount'] !== null ? money($r['approved_amount']) : '—' ?></td>
      <td><span class="badge <?= $statusBadge[$r['status']] ?? 'text-bg-secondary' ?>"><?= label_case($r['status']) ?></span></td>
      <td class="text-end text-nowrap">
        <?php if ($canEdit): ?>
          <?php if ($r['status'] === 'pending'): ?>
            <button class="btn btn-sm btn-light text-success" data-bs-toggle="modal" data-bs-target="#statusModal"
                    data-id="<?= $id ?>" data-code="<?= e($r['claim_code'] ?? '') ?>" data-status="approved" title="Approve"><i class="fa-solid fa-check"></i></button>
            <button class="btn btn-sm btn-light text-danger" data-bs-toggle="modal" data-bs-target="#statusModal"
                    data-id="<?= $id ?>" data-code="<?= e($r['claim_code'] ?? '') ?>" data-status="rejected" title="Reject"><i class="fa-solid fa-xmark"></i></button>
          <?php elseif ($r['status'] === 'approved'): ?>
            <button class="btn btn-sm btn-light text-success" data-bs-toggle="modal" data-bs-target="#statusModal"
                    data-id="<?= $id ?>" data-code="<?= e($r['claim_code'] ?? '') ?>" data-status="paid" data-amount="<?= e(money_raw($r['approved_amount'])) ?>" title="Mark Paid"><i class="fa-solid fa-money-check-dollar"></i></button>
          <?php endif; ?>
          <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#claimModal" data-edit='<?= e(json_encode([
              'id' => $id, 'invoice_id' => (int) $r['invoice_id'], 'insurance_company_id' => (int) $r['insurance_company_id'],
              'claimed_amount' => money_raw($r['claimed_amount']), 'notes' => $r['notes'] ?? '',
          ])) ?>' title="Edit"><i class="fa-solid fa-pen"></i></button>
        <?php endif; ?>
        <?php if ($canDelete): ?>
          <button class="btn btn-sm btn-light text-danger" data-action="claim-delete" data-id="<?= $id ?>" title="Delete"><i class="fa-solid fa-trash"></i></button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted"><?= result_count_label() ?></span><?= pagination_links() ?></div>
</div>

<?php if ($canCreate): ?>
<!-- New / edit claim modal -->
<div class="modal fade" id="claimModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="/insurance/claims">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_claim">
        <input type="hidden" name="id" value="" id="claimId">
        <div class="modal-header"><h5 class="modal-title" id="claimModalTitle">New Insurance Claim</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label required">Invoice</label>
            <select class="form-select" name="invoice_id" id="claimInvoice" required>
              <option value="">— Select invoice —</option>
              <?php foreach ($openInvoices as $i): ?>
                <option value="<?= (int) $i['id'] ?>"><?= e(($i['invoice_number'] ?? '#' . $i['id']) . ' — ' . $i['patient_name'] . ' (' . money_raw($i['total']) . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only invoices without an active claim are listed.</div>
          </div>
          <div class="mb-3">
            <label class="form-label required">Insurance Company</label>
            <select class="form-select" name="insurance_company_id" required>
              <option value="">— Select company —</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['company_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><label class="form-label required">Claimed Amount</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="claimed_amount" id="claimAmount" required></div>
          <div class="mb-1"><label class="form-label">Notes</label>
            <input class="form-control" name="notes" maxlength="255" id="claimNotes"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><i class="fa-solid fa-paper-plane me-1"></i>Submit Claim</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<!-- Status update modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="/insurance/claims">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" id="statusId">
        <input type="hidden" name="status" id="statusValue">
        <div class="modal-header"><h5 class="modal-title" id="statusModalTitle">Update Claim</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-2">Claim <span id="statusCode" class="fw-semibold"></span></p>
          <label class="form-label">Approved Amount (optional — defaults to claimed)</label>
          <input type="number" step="0.01" min="0" class="form-control" name="approved_amount" id="statusAmount" placeholder="Auto">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand" id="statusSubmit">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Prefill new-claim amount from selected invoice total
  const inv = document.getElementById('claimInvoice');
  const amt = document.getElementById('claimAmount');
  inv?.addEventListener('change', () => {
    const opt = inv.selectedOptions[0];
    const m = opt?.textContent.match(/\\(([-\\d.]+)\\)$/);
    if (m && amt) amt.value = m[1];
  });

  // Edit prefill
  document.addEventListener('click', (ev) => {
    const editBtn = ev.target.closest('[data-edit]');
    if (editBtn) {
      const d = JSON.parse(editBtn.dataset.edit);
      document.getElementById('claimModalTitle').textContent = 'Edit Claim';
      document.getElementById('claimId').value = d.id;
      document.getElementById('claimInvoice').innerHTML = '<option value="' + d.invoice_id + '" selected>Invoice #' + d.invoice_id + '</option>';
      const companySel = document.querySelector('#claimModal [name=insurance_company_id]');
      companySel.value = d.insurance_company_id;
      document.getElementById('claimAmount').value = d.claimed_amount;
      document.getElementById('claimNotes').value = d.notes || '';
    }
    const statusBtn = ev.target.closest('#statusModal [data-bs-dismiss], #statusModal .btn-close');
    if (statusBtn) {
      // reset modal title on close
      document.getElementById('statusModalTitle').textContent = 'Update Claim';
    }
  });

  // Status modal prefill
  document.getElementById('statusModal')?.addEventListener('show.bs.modal', function (ev) {
    const btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('statusId').value = btn.dataset.id || '';
    document.getElementById('statusValue').value = btn.dataset.status || '';
    document.getElementById('statusCode').textContent = btn.dataset.code || '';
    document.getElementById('statusModalTitle').textContent =
      btn.dataset.status === 'approved' ? 'Approve Claim' :
      btn.dataset.status === 'rejected' ? 'Reject Claim' :
      btn.dataset.status === 'paid' ? 'Mark Claim Paid' : 'Update Claim';
    const amount = document.getElementById('statusAmount');
    amount.value = btn.dataset.amount || '';
    amount.parentElement.style.display = btn.dataset.status === 'rejected' ? 'none' : '';
    document.getElementById('statusSubmit').className =
      'btn ' + (btn.dataset.status === 'rejected' ? 'btn-danger' : 'btn-brand');
  });

  // Delete claim
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-action="claim-delete"]');
    if (!btn) return;
    ev.preventDefault();
    AppConfirm('Delete this claim?', 'This cannot be undone.', async () => {
      const fd = new FormData();
      fd.set('action', 'delete');
      fd.set('id', btn.dataset.id);
      fd.set('csrf_token', '<?= e(csrf_token()) ?>');
      const res = await fetch('/insurance/claims', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({ ok: res.ok }));
      if (data.ok) { App.toast('Claim deleted.', 'success'); setTimeout(() => location.reload(), 400); }
      else { App.toast('Could not delete claim.', 'danger'); location.reload(); }
    });
  });
});
</script>
<?php
ui_page_close();
