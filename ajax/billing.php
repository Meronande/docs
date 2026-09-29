<?php
/**
 * AJAX billing endpoint.
 * GET  ?form=invoice[&patient_id=]   -> invoice builder form
 * POST action=save_invoice           -> create invoice + items (transaction)
 * GET  ?pay={invoice_id}             -> payment form
 * POST action=pay                    -> record payment (transaction, updates balance/status)
 * POST action=cancel                 -> cancel invoice
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('billing.view') && !has_permission('payments.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

// ---------------------------------------------------------------------
// Invoice form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') === 'invoice') {
    if (!has_permission('billing.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $patientId = (int) get('patient_id', '0');
    $taxRate = (float) setting('tax_percent', '0');

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/billing">';
    echo '<input type="hidden" name="action" value="save_invoice">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Patient</label><div class="input-group">';
    echo '<input class="form-control" id="invPatientSearch" placeholder="Search patient...">';
    echo '<select class="form-select" name="patient_id" id="invPatient" required style="max-width:55%"><option value="">— Select —</option>';
    if ($patientId) {
        $p = db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$patientId], 'i');
        if ($p) echo '<option value="' . (int) $p['id'] . '" selected>' . e($p['patient_code'] . ' — ' . $p['full_name']) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="col-md-6"><label class="form-label">Visit (optional)</label><select class="form-select" name="visit_id"><option value="">— None —</option></select></div>';
    echo '<div class="col-12"><label class="form-label">Items</label><div id="invItems"></div>';
    echo '<button type="button" class="btn btn-sm btn-light border" id="addInvItem"><i class="fa-solid fa-plus me-1"></i>Add service line</button></div>';
    echo '<div class="col-md-4 offset-md-8"><div class="border rounded p-2 small">';
    echo '<div class="d-flex justify-content-between"><span>Subtotal</span><span id="invSubtotal">0.00</span></div>';
    echo '<div class="d-flex justify-content-between align-items-center mt-1"><span>Discount</span><input type="number" step="0.01" min="0" name="discount" id="invDiscount" value="0" class="form-control form-control-sm" style="width:110px"></div>';
    echo '<div class="d-flex justify-content-between mt-1"><span>Tax (' . e((string) $taxRate) . '%)</span><span id="invTax">0.00</span></div>';
    echo '<div class="d-flex justify-content-between fw-bold mt-1"><span>Total</span><span id="invTotal">0.00</span></div>';
    echo '</div></div>';
    echo '</div></form>';

    $html = ob_get_clean();
    $svcJson = json_encode(array_map(function ($s) { return ['id' => (int) $s['id'], 'name' => $s['service_name'], 'price' => (float) $s['price']]; },
        db_fetch_all('SELECT id, service_name, price FROM services WHERE status = 1 ORDER BY service_name')));
    $html .= <<<JS
<script>
(function () {
  const services = $svcJson;
  const search = document.getElementById('invPatientSearch');
  const select = document.getElementById('invPatient');
  let timer = null;
  async function loadPatients(q) {
    const res = await fetch('/ajax/lookup?type=patients&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const cur = select.value;
    select.innerHTML = '<option value="">— Select —</option>' + data.items.map(p => `<option value="\${p.id}">\${App.escapeHtml(p.name)}</option>`).join('');
    if (cur) select.value = cur;
  }
  search?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadPatients(search.value), 300); });

  let idx = 0;
  document.getElementById('addInvItem')?.addEventListener('click', () => {
    const i = idx;
    const opts = services.map(s => `<option value="\${s.id}" data-price="\${s.price}">\${App.escapeHtml(s.name)} — \${s.price}</option>`).join('');
    const html = `<div class="row g-2 inv-line border rounded p-2 mb-2 align-items-end">
      <input type="hidden" name="line_\${i}_flag" value="1">
      <div class="col-md-5"><label class="form-label small">Service</label>
        <select class="form-select form-select-sm inv-svc" name="line_\${i}_service"><option value="">— Custom —</option>\${opts}</select>
        <input class="form-control form-control-sm mt-1" name="line_\${i}_desc" placeholder="Custom description (optional)"></div>
      <div class="col-md-2"><label class="form-label small">Qty</label><input type="number" min="1" value="1" class="form-control form-control-sm inv-qty" name="line_\${i}_qty"></div>
      <div class="col-md-3"><label class="form-label small">Unit price</label><input type="number" step="0.01" min="0" class="form-control form-control-sm inv-price" name="line_\${i}_price" value="0"></div>
      <div class="col-md-2"><button type="button" class="btn btn-sm btn-light text-danger w-100 inv-del"><i class="fa-solid fa-trash"></i></button></div>
    </div>`;
    document.getElementById('invItems').insertAdjacentHTML('beforeend', html);
    idx++;
  });
  document.addEventListener('click', (ev) => { if (ev.target.closest('.inv-del')) { ev.target.closest('.inv-line').remove(); recalc(); } });
  document.addEventListener('change', (ev) => {
    if (ev.target.classList?.contains('inv-svc')) {
      const opt = ev.target.selectedOptions[0];
      const line = ev.target.closest('.inv-line');
      if (opt && opt.dataset.price) line.querySelector('.inv-price').value = opt.dataset.price;
      recalc();
    }
  });
  document.addEventListener('input', (ev) => {
    if (ev.target.classList?.contains('inv-qty') || ev.target.classList?.contains('inv-price') || ev.target.id === 'invDiscount') recalc();
  });
  function recalc() {
    let sub = 0;
    document.querySelectorAll('.inv-line').forEach(line => {
      const qty = parseFloat(line.querySelector('.inv-qty').value) || 0;
      const price = parseFloat(line.querySelector('.inv-price').value) || 0;
      sub += qty * price;
    });
    const disc = parseFloat(document.getElementById('invDiscount')?.value) || 0;
    const taxRate = $taxRate;
    const tax = Math.max(0, (sub - disc)) * taxRate / 100;
    document.getElementById('invSubtotal').textContent = sub.toFixed(2);
    document.getElementById('invTax').textContent = tax.toFixed(2);
    document.getElementById('invTotal').textContent = (sub - disc + tax).toFixed(2);
  }
})();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save invoice
// ---------------------------------------------------------------------
if (post('action') === 'save_invoice') {
    if (!has_permission('billing.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $patientId = (int) post('patient_id', '0');
    if ($patientId === 0) json_response(['ok' => false, 'message' => 'Patient is required.']);
    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    if (!$patient) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);
    assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);

    $lines = [];
    $i = 0;
    while (isset($_POST["line_{$i}_flag"])) {
        $svcId = (int) ($_POST["line_{$i}_service"] ?? 0);
        $desc = trim((string) ($_POST["line_{$i}_desc"] ?? ''));
        $qty = max(1, (int) ($_POST["line_{$i}_qty"] ?? 1));
        $price = (float) ($_POST["line_{$i}_price"] ?? 0);
        if ($price > 0 || $desc !== '' || $svcId > 0) {
            if (!$desc && $svcId) {
                $svc = db_fetch_one('SELECT service_name FROM services WHERE id = ?', [$svcId], 'i');
                $desc = $svc['service_name'] ?? 'Service';
            }
            $lines[] = ['service_id' => $svcId ?: null, 'description' => $desc ?: 'Service', 'qty' => $qty, 'price' => $price, 'total' => $qty * $price];
        }
        $i++;
        if ($i > 50) break;
    }
    if (!$lines) json_response(['ok' => false, 'message' => 'Add at least one invoice line.']);

    $discount = max(0, (float) post('discount', '0'));
    try {
        $invoiceId = db_transaction(function () use ($lines, $patient, $patientId, $discount) {
            $branchId = $patient['branch_id'] ?? current_user()['branch_id'];
            $subtotal = array_sum(array_column($lines, 'total'));
            $taxRate = (float) setting('tax_percent', '0');
            $tax = round(max(0, $subtotal - $discount) * $taxRate / 100, 2);
            $grand = $subtotal - $discount + $tax;
            $invNumber = generate_code('invoice_prefix', 'invoices', 'invoice_number');
            $invId = db_execute('INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$invNumber, $patientId, $branchId !== null ? (int) $branchId : null,
                 post('visit_id') !== '' ? (int) post('visit_id') : null,
                 $subtotal, $discount, $tax, $grand, 0, $grand, 'unpaid', (int) current_user()['id']]);
            foreach ($lines as $ln) {
                db_execute('INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total) VALUES (?,?,?,?,?,?,?)',
                    [$invId, $ln['service_id'], $ln['description'], $ln['qty'], $ln['price'], 0, $ln['total']]);
            }
            audit_log('create', 'Invoice', $invId, 'Created invoice ' . $invNumber . ' for ' . $patient['full_name'] . ' (' . money($grand) . ')');
            notify(['permission_key' => 'payments.view', 'branch_id' => $branchId !== null ? (int) $branchId : null],
                'New invoice created', $invNumber . ' for ' . $patient['full_name'] . ' — ' . money($grand) . ' outstanding.',
                'finance', 'invoice', $invId, '/billing/view/' . $invId);
            return $invId;
        });
        json_response(['ok' => true, 'message' => 'Invoice created successfully.', 'id' => $invoiceId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

// ---------------------------------------------------------------------
// Payment form + action
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('pay') !== '') {
    if (!has_permission('payments.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $invId = (int) get('pay');
    $inv = db_fetch_one('SELECT i.*, p.full_name patient_name, p.patient_code FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.id = ?', [$invId], 'i');
    if (!$inv) json_response(['ok' => false, 'message' => 'Invoice not found.'], 404);

    ob_start();
    echo '<div class="alert alert-light border small mb-3"><strong>' . e($inv['patient_name']) . '</strong> (' . e($inv['patient_code']) . ') · Invoice ' . e($inv['invoice_number'] ?? '') . ' · Total ' . money($inv['total']) . ' · Balance <span class="fw-bold">' . money($inv['balance']) . '</span></div>';
    echo '<form id="crudForm" data-url="/ajax/billing">';
    echo '<input type="hidden" name="action" value="pay">';
    echo '<input type="hidden" name="invoice_id" value="' . $invId . '">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Amount</label><input type="number" step="0.01" min="0.01" max="' . e(money_raw($inv['balance'])) . '" class="form-control" name="amount" value="' . e(money_raw($inv['balance'])) . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label required">Payment Method</label><select class="form-select" name="payment_method_id" required><option value="">— Select —</option>';
    foreach (db_fetch_all('SELECT id, method_name FROM payment_methods WHERE status = 1 ORDER BY id') as $pm) {
        echo '<option value="' . (int) $pm['id'] . '">' . e($pm['method_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Reference Number</label><input class="form-control" name="reference_number" placeholder="Txn / receipt ref"></div>';
    echo '<div class="col-md-6"><label class="form-label">Paid By</label><input class="form-control" name="paid_by" value="' . e($inv['patient_name']) . '"></div>';
    echo '<div class="col-12"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>';
    echo '</div></form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

if (post('action') === 'pay') {
    if (!has_permission('payments.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $invId = (int) post('invoice_id', '0');
    $amount = (float) post('amount', '0');
    $methodId = (int) post('payment_method_id', '0');
    if ($amount <= 0) json_response(['ok' => false, 'message' => 'Amount must be greater than zero.']);
    if ($methodId === 0) json_response(['ok' => false, 'message' => 'Select a payment method.']);

    try {
        $result = db_transaction(function () use ($invId, $amount, $methodId) {
            // Lock invoice row
            $inv = db_fetch_one('SELECT i.*, p.full_name FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.id = ? FOR UPDATE', [$invId], 'i');
            if (!$inv) throw new RuntimeException('Invoice not found.');
            if ($inv['status'] === 'cancelled') throw new RuntimeException('This invoice is cancelled.');
            if ($amount > (float) $inv['balance'] + 0.001) {
                throw new RuntimeException('Amount exceeds the outstanding balance (' . money($inv['balance']) . ').');
            }
            $paid = (float) $inv['paid_amount'] + $amount;
            $balance = max(0, (float) $inv['total'] - $paid);
            $status = $balance <= 0.001 ? 'paid' : 'partial';

            $payCode = generate_code('payment_prefix', 'payments', 'payment_code');
            db_execute('INSERT INTO payments (payment_code, invoice_id, patient_id, branch_id, amount, payment_method_id, reference_number, paid_by, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$payCode, $invId, $inv['patient_id'], $inv['branch_id'], $amount, $methodId,
                 post('reference_number') ?: null, post('paid_by') ?: null, post('notes') ?: null, (int) current_user()['id']]);
            db_execute('UPDATE invoices SET paid_amount = ?, balance = ?, status = ? WHERE id = ?', [$paid, $balance, $status, $invId], 'ddsi');

            audit_log('payment', 'Payments', $invId, 'Received ' . money($amount) . ' for ' . ($inv['invoice_number'] ?? '') . ' (' . ($inv['full_name'] ?? '') . ') — ' . $payCode);
            notify(['permission_key' => 'payments.view', 'branch_id' => $inv['branch_id']],
                'Payment received', money($amount) . ' received for ' . ($inv['invoice_number'] ?? '') . ' (' . ($inv['full_name'] ?? '') . '). ' .
                ($status === 'paid' ? 'Invoice fully settled.' : 'Remaining balance: ' . money($balance)),
                'finance', 'payment', $invId, '/billing/view/' . $invId);
            return ['code' => $payCode, 'balance' => $balance, 'status' => $status];
        });
        $msg = 'Payment recorded (' . $result['code'] . '). ';
        $msg .= $result['status'] === 'paid' ? 'Invoice fully paid.' : 'Remaining balance: ' . money($result['balance']);
        json_response(['ok' => true, 'message' => $msg]);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

if (post('action') === 'cancel') {
    if (!has_permission('billing.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $invId = (int) post('id', '0');
    $inv = db_fetch_one('SELECT invoice_number, paid_amount, status FROM invoices WHERE id = ?', [$invId], 'i');
    if (!$inv) json_response(['ok' => false, 'message' => 'Invoice not found.'], 404);
    if ((float) $inv['paid_amount'] > 0) json_response(['ok' => false, 'message' => 'This invoice has payments recorded and cannot be cancelled.']);
    db_execute("UPDATE invoices SET status = 'cancelled' WHERE id = ?", [$invId], 'i');
    audit_log('cancel', 'Invoice', $invId, 'Cancelled invoice ' . ($inv['invoice_number'] ?? ''));
    json_response(['ok' => true, 'message' => 'Invoice cancelled.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
