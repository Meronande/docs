<?php
/**
 * /billing/create — full-page invoice builder.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('billing.create');

$prePatient = (int) get('patient_id', '0');
$taxRate = (float) setting('tax_percent', '0');
$services = db_fetch_all('SELECT id, service_name, price FROM services WHERE status = 1 ORDER BY service_name');
$svcJson = json_encode(array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['service_name'], 'price' => (float) $s['price']], $services));
$cur = e(setting('currency_symbol', 'Br'));

ui_page_open(['title' => 'New Invoice', 'icon' => 'fa-file-circle-plus',
    'breadcrumb' => ['Finance' => null, 'Invoices' => '/billing', 'New' => null]]);
?>
<div class="row justify-content-center">
  <div class="col-xl-10">
    <form id="invoiceForm" method="post" action="/ajax/billing">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_invoice">
      <div class="card mb-3">
        <div class="card-header py-2"><i class="fa-solid fa-hospital-user me-2 text-brand"></i>Patient</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label required">Patient</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input class="form-control" id="invPatientSearch" placeholder="Search by name or code..." autocomplete="off">
                <select class="form-select" name="patient_id" id="invPatient" required style="max-width:55%">
                  <option value="">— Select patient —</option>
                  <?php if ($prePatient):
                    $p = db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$prePatient], 'i');
                    if ($p) echo '<option value="' . (int) $p['id'] . '" selected>' . e($p['patient_code'] . ' — ' . $p['full_name']) . '</option>';
                  endif; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center">
          <span><i class="fa-solid fa-list-check me-2 text-brand"></i>Services &amp; Items</span>
          <button type="button" class="btn btn-sm btn-brand ms-auto" id="addInvItem"><i class="fa-solid fa-plus me-1"></i>Add Line</button>
        </div>
        <div class="card-body">
          <div id="invItems"></div>
          <div id="invEmpty" class="text-center text-muted py-4">No items yet — click <strong>Add Line</strong> to start billing.</div>
        </div>
      </div>

      <div class="row">
        <div class="col-md-5 offset-md-7">
          <div class="card">
            <div class="card-body small">
              <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><span id="invSubtotal">0.00</span></div>
              <div class="d-flex justify-content-between align-items-center py-1">
                <span class="text-muted">Discount (<?= $cur ?>)</span>
                <input type="number" step="0.01" min="0" name="discount" id="invDiscount" value="0" class="form-control form-control-sm" style="width:120px">
              </div>
              <div class="d-flex justify-content-between py-1"><span class="text-muted">Tax (<?= e((string) $taxRate) ?>%)</span><span id="invTax">0.00</span></div>
              <div class="d-flex justify-content-between py-2 border-top fw-bold fs-5 mt-1"><span>Total</span><span id="invTotal">0.00</span></div>
              <button type="submit" class="btn btn-brand w-100 mt-2"><i class="fa-solid fa-floppy-disk me-1"></i>Create Invoice</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const services = <?= $svcJson ?>;
  const csrf = document.querySelector('#invoiceForm [name=csrf_token]')?.value || '';
  const search = document.getElementById('invPatientSearch');
  const select = document.getElementById('invPatient');
  let timer = null;

  async function loadPatients(q) {
    const res = await fetch('/ajax/lookup?type=patients&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const cur = select.value;
    select.innerHTML = '<option value="">— Select patient —</option>' +
      data.items.map(p => `<option value="\${p.id}">\${App.escapeHtml(p.name)}</option>`).join('');
    if (cur) select.value = cur;
  }
  search?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadPatients(search.value), 300); });
  select?.addEventListener('change', () => { if (select.value) search.value = select.selectedOptions[0].textContent.replace(/^\\S+\\s—\\s/, ''); });

  let idx = 0;
  function addLine() {
    const i = idx++;
    const opts = services.map(s => `<option value="\${s.id}" data-price="\${s.price}">\${App.escapeHtml(s.name)} — \${s.price}</option>`).join('');
    document.getElementById('invEmpty')?.remove();
    const html = document.createElement('div');
    html.className = 'row g-2 inv-line border rounded p-2 mb-2 align-items-end';
    html.innerHTML = `
      <input type="hidden" name="line_\${i}_flag" value="1">
      <div class="col-md-5"><label class="form-label small">Service</label>
        <select class="form-select form-select-sm inv-svc" name="line_\${i}_service"><option value="">— Custom —</option>\${opts}</select>
        <input class="form-control form-control-sm mt-1" name="line_\${i}_desc" placeholder="Custom description (optional)"></div>
      <div class="col-md-2"><label class="form-label small">Qty</label><input type="number" min="1" value="1" class="form-control form-control-sm inv-qty" name="line_\${i}_qty"></div>
      <div class="col-md-2"><label class="form-label small">Unit Price</label><input type="number" step="0.01" min="0" value="0" class="form-control form-control-sm inv-price" name="line_\${i}_price"></div>
      <div class="col-md-2"><label class="form-label small">Line Total</label><div class="form-control form-control-sm bg-light inv-lt" readonly>0.00</div></div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-light text-danger inv-del" title="Remove"><i class="fa-solid fa-trash"></i></button></div>`;
    document.getElementById('invItems').appendChild(html);
    recalc();
  }
  document.getElementById('addInvItem').addEventListener('click', addLine);
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('.inv-del')) { ev.target.closest('.inv-line').remove(); recalc(); }
  });
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
      const lt = qty * price;
      line.querySelector('.inv-lt').textContent = lt.toFixed(2);
      sub += lt;
    });
    const disc = parseFloat(document.getElementById('invDiscount').value) || 0;
    const taxRate = <?= (float) $taxRate ?>;
    const tax = Math.max(0, sub - disc) * taxRate / 100;
    document.getElementById('invSubtotal').textContent = sub.toFixed(2);
    document.getElementById('invTax').textContent = tax.toFixed(2);
    document.getElementById('invTotal').textContent = (sub - disc + tax).toFixed(2);
  }

  document.getElementById('invoiceForm').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!select.value) return App.toast('Please select a patient.', 'warning');
    if (!document.querySelector('.inv-line')) return App.toast('Add at least one service line.', 'warning');
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const res = await fetch('/ajax/billing', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
        body: new FormData(this)
      });
      const data = await res.json();
      if (data.ok) {
        App.toast(data.message, 'success');
        setTimeout(() => { window.location.href = '/billing/view/' + data.id; }, 500);
      } else {
        App.toast(data.message, 'danger');
        btn.disabled = false;
      }
    } catch (e) {
      App.toast('Network error. Please try again.', 'danger');
      btn.disabled = false;
    }
  });

  addLine();
})();
</script>
<?php
ui_page_close();
