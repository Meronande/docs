<?php
/**
 * AJAX pharmacy endpoint.
 * GET  ?form=medicine[&id=]                  -> medicine form
 * POST action=save_medicine                  -> create/update + low-stock/expiry notifications
 * POST action=adjust                         -> stock adjustment
 * GET  ?rx={prescription_id}                 -> dispensing form prefilled from prescription
 * POST action=dispense                       -> transactional sale + stock reduction (+invoice option)
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('pharmacy.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

// ---------------------------------------------------------------------
// Medicine form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') === 'medicine') {
    if (!has_permission('pharmacy.create') && !has_permission('pharmacy.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $m = $id ? (db_fetch_one('SELECT * FROM medicines WHERE id = ?', [$id], 'i') ?? []) : [];

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/pharmacy">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save_medicine">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label">Medicine Code</label><input class="form-control" value="' . e($m['medicine_code'] ?? generate_code('medicine_prefix', 'medicines', 'medicine_code')) . '" disabled><div class="form-text">Auto-generated</div></div>';
    echo '<div class="col-md-6"><label class="form-label required">Medicine Name</label><input class="form-control" name="medicine_name" value="' . e($m['medicine_name'] ?? '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label">Generic Name</label><input class="form-control" name="generic_name" value="' . e($m['generic_name'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="">— None —</option>';
    foreach (db_fetch_all('SELECT id, category_name FROM medicine_categories WHERE status = 1 ORDER BY category_name') as $c) {
        echo '<option value="' . (int) $c['id'] . '" ' . ((string) ($m['category_id'] ?? '') === (string) $c['id'] ? 'selected' : '') . '>' . e($c['category_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-4"><label class="form-label">Unit</label><input class="form-control" name="unit" value="' . e($m['unit'] ?? '') . '" placeholder="Tablet / Bottle / Vial"></div>';
    echo '<div class="col-md-4"><label class="form-label required">Selling Price</label><input type="number" step="0.01" min="0" class="form-control" name="selling_price" value="' . e(money_raw($m['selling_price'] ?? 0)) . '" required></div>';
    echo '<div class="col-md-4"><label class="form-label">Purchase Price</label><input type="number" step="0.01" min="0" class="form-control" name="purchase_price" value="' . e(money_raw($m['purchase_price'] ?? 0)) . '"></div>';
    echo '<div class="col-md-3"><label class="form-label">Stock Qty</label><input type="number" class="form-control" name="stock_quantity" value="' . e((string) ($m['stock_quantity'] ?? 0)) . '"></div>';
    echo '<div class="col-md-3"><label class="form-label">Minimum Stock</label><input type="number" class="form-control" name="minimum_stock" value="' . e((string) ($m['minimum_stock'] ?? 10)) . '"></div>';
    echo '<div class="col-md-3"><label class="form-label">Expiry Date</label><input type="date" class="form-control" name="expiry_date" value="' . e($m['expiry_date'] ?? '') . '"></div>';
    echo '<div class="col-md-3"><label class="form-label">Supplier</label><select class="form-select" name="supplier_id"><option value="">— None —</option>';
    foreach (db_fetch_all('SELECT id, supplier_name FROM suppliers WHERE status = 1 ORDER BY supplier_name') as $s) {
        echo '<option value="' . (int) $s['id'] . '" ' . ((string) ($m['supplier_id'] ?? '') === (string) $s['id'] ? 'selected' : '') . '>' . e($s['supplier_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id">';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ((string) ($m['branch_id'] ?? '') === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($m['status'] ?? 1) === 1 ? 'checked' : '') . '></div></div>';
    echo '</div></form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

// ---------------------------------------------------------------------
// Dispensing form (from prescription)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('rx') !== '') {
    if (!has_permission('pharmacy.sell')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $rxId = (int) get('rx');
    $rx = db_fetch_one(
        'SELECT r.*, p.full_name patient_name, p.patient_code, d.full_name doctor_name
         FROM prescriptions r JOIN patients p ON p.id = r.patient_id LEFT JOIN doctors d ON d.id = r.doctor_id
         WHERE r.id = ?', [$rxId], 'i');
    if (!$rx) json_response(['ok' => false, 'message' => 'Prescription not found.'], 404);
    assert_branch_access($rx['branch_id'] !== null ? (int) $rx['branch_id'] : null);
    $items = db_fetch_all('SELECT pi.*, med.medicine_name, med.stock_quantity, med.selling_price, med.unit
                           FROM prescription_items pi LEFT JOIN medicines med ON med.id = pi.medicine_id
                           WHERE pi.prescription_id = ?', [$rxId], 'i');

    ob_start();
    echo '<div class="alert alert-light border small mb-3">';
    echo '<strong>' . e($rx['patient_name']) . '</strong> (' . e($rx['patient_code']) . ') · Rx ' . e($rx['prescription_code'] ?? '') . ' · Dr. ' . e(or_na($rx['doctor_name'])) . '</div>';
    echo '<form id="crudForm" data-url="/ajax/pharmacy">';
    echo '<input type="hidden" name="action" value="dispense">';
    echo '<input type="hidden" name="prescription_id" value="' . $rxId . '">';
    foreach ($items as $i => $it) {
        $available = (int) ($it['stock_quantity'] ?? 0);
        $shortage = $available < (int) $it['quantity'];
        echo '<div class="border rounded p-2 mb-2">';
        echo '<div class="d-flex justify-content-between align-items-center">';
        echo '<div class="fw-semibold small">' . e($it['medicine_name'] ?? $it['medicine_name_free'] ?? 'Item') . '</div>';
        if ($shortage) echo '<span class="badge text-bg-danger">Only ' . $available . ' in stock</span>';
        else echo '<span class="badge text-bg-success">' . $available . ' in stock</span>';
        echo '</div>';
        echo '<div class="small text-muted mb-2">' . e(or_na($it['dosage'])) . ' · ' . e(or_na($it['frequency'])) . ' · ' . e(or_na($it['duration'])) . '</div>';
        echo '<div class="row g-2 align-items-end">';
        echo '<input type="hidden" name="items_' . $i . '_flag" value="1">';
        echo '<input type="hidden" name="items_' . $i . '_medicine_id" value="' . (int) ($it['medicine_id'] ?? 0) . '">';
        echo '<input type="hidden" name="items_' . $i . '_name" value="' . e($it['medicine_name'] ?? $it['medicine_name_free'] ?? '') . '">';
        echo '<div class="col-4"><label class="form-label small">Qty to dispense</label><input type="number" min="0" max="' . max($available, 0) . '" class="form-control form-control-sm disp-qty" name="items_' . $i . '_qty" value="' . ($it['medicine_id'] ? (int) $it['quantity'] : 0) . '"></div>';
        echo '<div class="col-4"><label class="form-label small">Unit price</label><input type="number" step="0.01" min="0" class="form-control form-control-sm disp-price" name="items_' . $i . '_price" value="' . e(money_raw($it['selling_price'] ?? 0)) . '"></div>';
        echo '<div class="col-4"><label class="form-label small">Line total</label><input class="form-control form-control-sm disp-total" value="0.00" disabled></div>';
        echo '</div></div>';
    }
    echo '<div class="d-flex justify-content-between align-items-center border-top pt-2 mt-2">';
    echo '<span class="fw-semibold">Sale total</span><span class="fw-bold fs-5" id="dispGrand">0.00</span></div>';
    echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="create_invoice" value="1" id="ciChk" checked>';
    echo '<label class="form-check-label small" for="ciChk">Create invoice for this sale (patient billing)</label></div>';
    echo '</form>';

    $html = ob_get_clean();
    $html .= <<<'JS'
<script>
document.addEventListener('input', recalc);
function recalc() {
  let grand = 0;
  document.querySelectorAll('.disp-qty').forEach((qty, idx) => {
    const price = document.querySelectorAll('.disp-price')[idx];
    const total = document.querySelectorAll('.disp-total')[idx];
    const t = (parseFloat(qty.value) || 0) * (parseFloat(price.value) || 0);
    total.value = t.toFixed(2);
    grand += t;
  });
  document.getElementById('dispGrand').textContent = grand.toFixed(2);
}
recalc();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Save medicine
// ---------------------------------------------------------------------
if (post('action') === 'save_medicine') {
    $id = post('id', '') !== '' ? (int) post('id') : null;
    if ($id && !has_permission('pharmacy.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    if (!$id && !has_permission('pharmacy.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);

    $name = post('medicine_name');
    if ($name === '') json_response(['ok' => false, 'message' => 'Medicine name is required.']);
    $expiry = post('expiry_date');
    if (!valid_date($expiry)) json_response(['ok' => false, 'message' => 'Invalid expiry date.']);

    $data = [
        'medicine_name' => $name,
        'generic_name' => post('generic_name') ?: null,
        'category_id' => post('category_id') !== '' ? (int) post('category_id') : null,
        'unit' => post('unit') ?: null,
        'purchase_price' => post('purchase_price') !== '' ? (float) post('purchase_price') : 0,
        'selling_price' => post('selling_price') !== '' ? (float) post('selling_price') : 0,
        'stock_quantity' => (int) post('stock_quantity', '0'),
        'minimum_stock' => (int) post('minimum_stock', '10'),
        'expiry_date' => $expiry !== '' ? $expiry : null,
        'supplier_id' => post('supplier_id') !== '' ? (int) post('supplier_id') : null,
        'branch_id' => post('branch_id') !== '' ? (int) post('branch_id') : (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null),
        'status' => isset($_POST['status']) ? 1 : 0,
    ];

    try {
        $medId = db_transaction(function () use ($data, $id) {
            if ($id) {
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                db_execute("UPDATE medicines SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', 'Medicine', $id, 'Updated medicine: ' . $data['medicine_name']);
                return $id;
            }
            $data['medicine_code'] = generate_code('medicine_prefix', 'medicines', 'medicine_code');
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $newId = db_execute("INSERT INTO medicines ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
            audit_log('create', 'Medicine', $newId, 'Added medicine: ' . $data['medicine_name']);
            return $newId;
        });

        // Automatic alerts
        $med = db_fetch_one('SELECT * FROM medicines WHERE id = ?', [$medId], 'i');
        if ($med) {
            if ((int) $med['stock_quantity'] <= (int) $med['minimum_stock']) {
                notify(['permission_key' => 'pharmacy.view', 'branch_id' => $med['branch_id']],
                    'Low Stock Alert',
                    $med['medicine_name'] . ' has only ' . (int) $med['stock_quantity'] . ' ' . ($med['unit'] ?? 'units') . ' remaining.',
                    'pharmacy', 'medicine', $medId, '/pharmacy?filter=low');
            }
            if ($med['expiry_date']) {
                $days = (int) floor((strtotime((string) $med['expiry_date']) - time()) / 86400);
                $alertDays = (int) setting('low_stock_alert_days', '30');
                if ($days < 0) {
                    notify(['permission_key' => 'pharmacy.view', 'branch_id' => $med['branch_id']],
                        'Expiry Alert', $med['medicine_name'] . ' has expired.', 'danger', 'medicine', $medId, '/pharmacy?filter=expired');
                } elseif ($days <= $alertDays) {
                    notify(['permission_key' => 'pharmacy.view', 'branch_id' => $med['branch_id']],
                        'Expiry Alert', $med['medicine_name'] . ' expires in ' . $days . ' days.', 'warning', 'medicine', $medId, '/pharmacy?filter=expiring');
                }
            }
        }
        json_response(['ok' => true, 'message' => 'Medicine saved successfully.', 'id' => $medId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

// ---------------------------------------------------------------------
// Stock adjust
// ---------------------------------------------------------------------
if (post('action') === 'adjust') {
    if (!has_permission('pharmacy.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $id = (int) post('id', '0');
    $delta = (int) post('delta', '0');
    $med = db_fetch_one('SELECT * FROM medicines WHERE id = ?', [$id], 'i');
    if (!$med) json_response(['ok' => false, 'message' => 'Medicine not found.'], 404);
    $newQty = (int) $med['stock_quantity'] + $delta;
    if ($newQty < 0) json_response(['ok' => false, 'message' => 'Stock cannot go below zero.']);
    db_execute('UPDATE medicines SET stock_quantity = ? WHERE id = ?', [$newQty, $id], 'ii');
    audit_log('update', 'Medicine', $id, 'Stock adjusted by ' . $delta . ' for ' . $med['medicine_name'] . ' (now ' . $newQty . ')');
    if ($newQty <= (int) $med['minimum_stock'] && (int) $med['stock_quantity'] > (int) $med['minimum_stock']) {
        notify(['permission_key' => 'pharmacy.view', 'branch_id' => $med['branch_id']],
            'Low Stock Alert', $med['medicine_name'] . ' has only ' . $newQty . ' units remaining.', 'pharmacy', 'medicine', $id, '/pharmacy?filter=low');
    }
    json_response(['ok' => true, 'message' => 'Stock updated: ' . $newQty]);
}

// ---------------------------------------------------------------------
// Dispense prescription (transaction: stock reduce + sale + optional invoice)
// ---------------------------------------------------------------------
if (post('action') === 'dispense') {
    if (!has_permission('pharmacy.sell')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $rxId = (int) post('prescription_id', '0');
    $rx = db_fetch_one('SELECT * FROM prescriptions WHERE id = ?', [$rxId], 'i');
    if (!$rx) json_response(['ok' => false, 'message' => 'Prescription not found.'], 404);
    if ($rx['status'] !== 'pending') json_response(['ok' => false, 'message' => 'This prescription is not pending.']);

    $items = [];
    $i = 0;
    while (isset($_POST["items_{$i}_flag"])) {
        $qty = (int) ($_POST["items_{$i}_qty"] ?? 0);
        $medId = (int) ($_POST["items_{$i}_medicine_id"] ?? 0);
        $price = (float) ($_POST["items_{$i}_price"] ?? 0);
        if ($qty > 0 && $medId > 0) {
            $items[] = ['medicine_id' => $medId, 'qty' => $qty, 'price' => $price, 'name' => (string) ($_POST["items_{$i}_name"] ?? '')];
        }
        $i++;
        if ($i > 50) break;
    }
    if (!$items) json_response(['ok' => false, 'message' => 'Nothing to dispense — set at least one quantity.']);

    try {
        $result = db_transaction(function () use ($items, $rx, $rxId) {
            $branchId = $rx['branch_id'] !== null ? (int) $rx['branch_id'] : (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null);

            // Validate stock
            foreach ($items as $it) {
                $med = db_fetch_one('SELECT medicine_name, stock_quantity FROM medicines WHERE id = ? FOR UPDATE', [$it['medicine_id']], 'i');
                if (!$med) throw new RuntimeException('Medicine not found: #' . $it['medicine_id']);
                if ((int) $med['stock_quantity'] < $it['qty']) {
                    throw new RuntimeException('Insufficient stock for ' . $med['medicine_name'] . ' (need ' . $it['qty'] . ', have ' . (int) $med['stock_quantity'] . ')');
                }
            }

            // Reduce stock
            foreach ($items as $it) {
                db_execute('UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE id = ?', [$it['qty'], $it['medicine_id']], 'ii');
            }

            // Sale
            $total = array_sum(array_map(function ($it) { return $it['qty'] * $it['price']; }, $items));
            $saleCode = generate_code('sale_prefix', 'pharmacy_sales', 'sale_code');
            $saleId = db_execute('INSERT INTO pharmacy_sales (sale_code, prescription_id, patient_id, branch_id, total_amount, sold_by) VALUES (?,?,?,?,?,?)',
                [$saleCode, $rxId, $rx['patient_id'], $branchId, $total, (int) current_user()['id']]);
            foreach ($items as $it) {
                db_execute('INSERT INTO pharmacy_sale_items (sale_id, medicine_id, quantity, unit_price, total) VALUES (?,?,?,?,?)',
                    [$saleId, $it['medicine_id'], $it['qty'], $it['price'], $it['qty'] * $it['price']]);
            }

            // Optional invoice
            if (isset($_POST['create_invoice'])) {
                $taxRate = (float) setting('tax_percent', '0');
                $subtotal = $total;
                $tax = round($subtotal * $taxRate / 100, 2);
                $grand = $subtotal + $tax;
                $invCode = generate_code('invoice_prefix', 'invoices', 'invoice_number');
                $invoiceId = db_execute('INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$invCode, $rx['patient_id'], $branchId, $rx['visit_id'], $subtotal, 0, $tax, $grand, 0, $grand, 'unpaid', (int) current_user()['id']]);
                foreach ($items as $it) {
                    db_execute('INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total) VALUES (?,?,?,?,?,?,?)',
                        [$invoiceId, null, 'Pharmacy: ' . $it['name'], $it['qty'], $it['price'], 0, $it['qty'] * $it['price']]);
                }
                db_execute('UPDATE pharmacy_sales SET invoice_id = ? WHERE id = ?', [$invoiceId, $saleId], 'ii');
            }

            // Mark prescription dispensed + notify doctor
            db_execute("UPDATE prescriptions SET status = 'dispensed', dispensed_by = ?, dispensed_at = NOW() WHERE id = ?", [(int) current_user()['id'], $rxId], 'ii');
            if ($rx['doctor_id']) {
                $docUser = db_fetch_value('SELECT user_id FROM doctors WHERE id = ?', [$rx['doctor_id']], 'i');
                if ($docUser) {
                    notify(['user_id' => (int) $docUser], 'Prescription dispensed',
                        $saleCode . ' was dispensed for your patient.', 'pharmacy', 'sale', $saleId, '/prescriptions');
                }
            }
            audit_log('pharmacy_sale', 'Pharmacy', $saleId, 'Dispensed ' . $saleCode . ' (total ' . money($total) . ')');
            return ['sale' => $saleCode, 'total' => $total];
        });

        json_response(['ok' => true, 'message' => 'Dispensed ' . $result['sale'] . ' — total ' . money($result['total']) . '. Stock updated.']);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
