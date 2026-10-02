<?php
/**
 * AJAX laboratory endpoint.
 * GET  ?form=order[&patient_id=&visit_id=]   -> order tests form
 * POST action=save_order                     -> create lab_order + items, notify lab
 * GET  ?results={order_id}                   -> results entry form
 * POST action=save_results                   -> save results, complete order, notify doctor
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('laboratory.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

// ---------------------------------------------------------------------
// Order form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') === 'order') {
    if (!has_permission('laboratory.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $patientId = (int) get('patient_id', '0');
    $visitId = (int) get('visit_id', '0');
    $cats = db_fetch_all('SELECT id, category_name FROM lab_test_categories WHERE status = 1 ORDER BY category_name');
    $tests = db_fetch_all('SELECT t.*, c.category_name FROM laboratory_tests t LEFT JOIN lab_test_categories c ON c.id = t.category_id WHERE t.status = 1 ORDER BY t.test_name');

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/lab">';
    echo '<input type="hidden" name="action" value="save_order">';
    echo '<input type="hidden" name="visit_id" value="' . e((string) ($visitId ?: '')) . '">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Patient</label><div class="input-group">';
    echo '<input class="form-control" id="labPatientSearch" placeholder="Search patient...">';
    echo '<select class="form-select" name="patient_id" id="labPatient" required style="max-width:55%"><option value="">— Select —</option>';
    if ($patientId) {
        $p = db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$patientId], 'i');
        if ($p) echo '<option value="' . (int) $p['id'] . '" selected>' . e($p['patient_code'] . ' — ' . $p['full_name']) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="col-md-6"><label class="form-label">Ordering Doctor</label><select class="form-select" name="doctor_id">';
    $b = scope_branch_id();
    foreach (db_fetch_all('SELECT id, full_name FROM doctors WHERE status = 1' . ($b !== null ? ' AND branch_id = ' . (int) $b : '') . ' ORDER BY full_name') as $d) {
        echo '<option value="' . (int) $d['id'] . '">Dr. ' . e($d['full_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-12"><hr class="my-1"></div>';
    foreach ($cats as $cat) {
        $catTests = array_values(array_filter($tests, function ($t) use ($cat) { return (string) $t['category_id'] === (string) $cat['id']; }));
        if (!$catTests) continue;
        echo '<div class="col-md-6"><div class="border rounded p-2 h-100">';
        echo '<div class="small fw-bold text-uppercase text-muted mb-1">' . e($cat['category_name']) . '</div>';
        foreach ($catTests as $t) {
            echo '<div class="form-check"><input class="form-check-input lab-test" type="checkbox" name="tests[]" value="' . (int) $t['id'] . '" data-price="' . e(money_raw($t['price'])) . '" id="t' . (int) $t['id'] . '">';
            echo '<label class="form-check-label small" for="t' . (int) $t['id'] . '">' . e($t['test_code']) . ' — ' . e($t['test_name']) . ' <span class="text-muted">(' . money($t['price']) . ')</span></label></div>';
        }
        echo '</div></div>';
    }
    echo '<div class="col-12 d-flex justify-content-between align-items-center border-top pt-2"><span class="fw-semibold">Estimated total</span><span class="fw-bold fs-5" id="labTotal">0.00</span></div>';
    echo '</div></form>';

    $html = ob_get_clean();
    $html .= <<<'JS'
<script>
(function () {
  const search = document.getElementById('labPatientSearch');
  const select = document.getElementById('labPatient');
  let timer = null;
  async function loadPatients(q) {
    const res = await fetch('/ajax/lookup?type=patients&q=' + encodeURIComponent(q || ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const cur = select.value;
    select.innerHTML = '<option value="">— Select —</option>' + data.items.map(p => `<option value="${p.id}">${App.escapeHtml(p.name)}</option>`).join('');
    if (cur) select.value = cur;
  }
  search?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadPatients(search.value), 300); });

  function recalc() {
    let total = 0;
    document.querySelectorAll('.lab-test:checked').forEach(cb => total += parseFloat(cb.dataset.price || 0));
    document.getElementById('labTotal').textContent = total.toFixed(2);
  }
  document.addEventListener('change', (ev) => { if (ev.target.classList?.contains('lab-test')) recalc(); });
})();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// Results form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('results') !== '') {
    if (!has_permission('laboratory.result')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $orderId = (int) get('results');
    $order = db_fetch_one('SELECT lo.*, p.full_name patient_name, p.patient_code FROM lab_orders lo JOIN patients p ON p.id = lo.patient_id WHERE lo.id = ?', [$orderId], 'i');
    if (!$order) json_response(['ok' => false, 'message' => 'Order not found.'], 404);
    assert_branch_access($order['branch_id'] !== null ? (int) $order['branch_id'] : null);
    $items = db_fetch_all(
        'SELECT loi.*, t.test_name, t.test_code FROM lab_order_items loi JOIN laboratory_tests t ON t.id = loi.test_id WHERE loi.lab_order_id = ?',
        [$orderId], 'i');

    ob_start();
    echo '<div class="alert alert-light border small mb-3"><strong>' . e($order['patient_name']) . '</strong> (' . e($order['patient_code']) . ') · Order ' . e($order['order_code'] ?? '') . '</div>';
    echo '<form id="crudForm" data-url="/ajax/lab">';
    echo '<input type="hidden" name="action" value="save_results">';
    echo '<input type="hidden" name="order_id" value="' . $orderId . '">';
    foreach ($items as $i => $it) {
        echo '<div class="border rounded p-2 mb-2">';
        echo '<input type="hidden" name="res_' . $i . '_flag" value="1">';
        echo '<input type="hidden" name="res_' . $i . '_item_id" value="' . (int) $it['id'] . '">';
        echo '<div class="fw-semibold small mb-1">' . e($it['test_code']) . ' — ' . e($it['test_name']) . '</div>';
        echo '<div class="row g-2">';
        echo '<div class="col-md-4"><label class="form-label small">Result</label><input class="form-control form-control-sm" name="res_' . $i . '_result" value="' . e($it['result'] ?? '') . '"></div>';
        echo '<div class="col-md-3"><label class="form-label small">Normal range</label><input class="form-control form-control-sm" name="res_' . $i . '_range" value="' . e($it['normal_range'] ?? '') . '"></div>';
        echo '<div class="col-md-2"><label class="form-label small">Unit</label><input class="form-control form-control-sm" name="res_' . $i . '_unit" value="' . e($it['unit'] ?? '') . '"></div>';
        echo '<div class="col-md-3"><label class="form-label small">Remarks</label><input class="form-control form-control-sm" name="res_' . $i . '_remarks" value="' . e($it['remarks'] ?? '') . '"></div>';
        echo '</div></div>';
    }
    echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="mark_completed" value="1" id="mcChk" checked>';
    echo '<label class="form-check-label small" for="mcChk">Mark order as completed (notifies the ordering doctor)</label></div>';
    echo '</form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

// ---------------------------------------------------------------------
// Save order
// ---------------------------------------------------------------------
if (post('action') === 'save_order') {
    if (!has_permission('laboratory.create')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $patientId = (int) post('patient_id', '0');
    $testIds = array_map('intval', (array) ($_POST['tests'] ?? []));
    if ($patientId === 0) json_response(['ok' => false, 'message' => 'Patient is required.']);
    if (!$testIds) json_response(['ok' => false, 'message' => 'Select at least one test.']);

    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    if (!$patient) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);
    assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);

    try {
        $orderId = db_transaction(function () use ($patientId, $patient, $testIds) {
            $branchId = $patient['branch_id'] ?? current_user()['branch_id'];
            $orderCode = generate_code('lab_prefix', 'lab_orders', 'order_code');
            $doctorId = post('doctor_id') !== '' ? (int) post('doctor_id') : null;
            $visitId = post('visit_id') !== '' ? (int) post('visit_id') : null;
            $oid = db_execute('INSERT INTO lab_orders (order_code, patient_id, doctor_id, branch_id, visit_id, status, created_by) VALUES (?,?,?,?,?,?,?)',
                [$orderCode, $patientId, $doctorId, $branchId !== null ? (int) $branchId : null, $visitId ?: null, 'pending', (int) current_user()['id']]);
            foreach ($testIds as $tid) {
                $test = db_fetch_one('SELECT test_name, normal_range, unit FROM laboratory_tests WHERE id = ?', [$tid], 'i');
                if (!$test) continue;
                db_execute('INSERT INTO lab_order_items (lab_order_id, test_id, normal_range, unit, status) VALUES (?,?,?,?,?)',
                    [$oid, $tid, $test['normal_range'], $test['unit'], 'pending']);
            }
            audit_log('create', 'Lab Order', $oid, 'Ordered ' . count($testIds) . ' test(s) for ' . $patient['full_name'] . ' (' . $orderCode . ')');
            notify(['permission_key' => 'laboratory.result', 'branch_id' => $branchId !== null ? (int) $branchId : null],
                'New lab order received',
                $orderCode . ' — ' . count($testIds) . ' test(s) for ' . $patient['full_name'] . '.',
                'laboratory', 'lab_order', $oid, '/laboratory/view/' . $oid);
            return $oid;
        });
        json_response(['ok' => true, 'message' => 'Lab order created. Laboratory has been notified.', 'id' => $orderId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

// ---------------------------------------------------------------------
// Save results
// ---------------------------------------------------------------------
if (post('action') === 'save_results') {
    if (!has_permission('laboratory.result')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $orderId = (int) post('order_id', '0');
    $order = db_fetch_one('SELECT * FROM lab_orders WHERE id = ?', [$orderId], 'i');
    if (!$order) json_response(['ok' => false, 'message' => 'Order not found.'], 404);

    $items = [];
    $i = 0;
    while (isset($_POST["res_{$i}_flag"])) {
        $itemId = (int) ($_POST["res_{$i}_item_id"] ?? 0);
        if ($itemId > 0) {
            $items[] = [
                'id' => $itemId,
                'result' => trim((string) ($_POST["res_{$i}_result"] ?? '')) ?: null,
                'range' => trim((string) ($_POST["res_{$i}_range"] ?? '')) ?: null,
                'unit' => trim((string) ($_POST["res_{$i}_unit"] ?? '')) ?: null,
                'remarks' => trim((string) ($_POST["res_{$i}_remarks"] ?? '')) ?: null,
            ];
        }
        $i++;
        if ($i > 100) break;
    }

    try {
        db_transaction(function () use ($items, $orderId, $order) {
            $complete = isset($_POST['mark_completed']);
            foreach ($items as $it) {
                db_execute('UPDATE lab_order_items SET result = ?, normal_range = ?, unit = ?, remarks = ?, status = ?, entered_by = ?, completed_at = ? WHERE id = ? AND lab_order_id = ?',
                    [$it['result'], $it['range'], $it['unit'], $it['remarks'],
                     $complete ? 'completed' : 'pending', (int) current_user()['id'],
                     $complete ? now_dt() : null, $it['id'], $orderId], null);
            }
            if ($complete) {
                db_execute("UPDATE lab_orders SET status = 'completed' WHERE id = ?", [$orderId], 'i');
                // Notify ordering doctor
                if ($order['doctor_id']) {
                    $docUser = db_fetch_value('SELECT user_id FROM doctors WHERE id = ?', [$order['doctor_id']], 'i');
                    if ($docUser) {
                        notify(['user_id' => (int) $docUser], 'Lab result completed',
                            'Results for order ' . ($order['order_code'] ?? '') . ' are ready.', 'laboratory', 'lab_order', $orderId, '/laboratory/view/' . $orderId);
                    }
                }
                // OPD flow: if this order came from an OPD referral, transfer it on
                // to the chosen next stop (default pharmacy) so the patient flows on.
                $opdSend = db_fetch_one("SELECT * FROM opd_sends WHERE lab_order_id = ? AND status IN ('pending','received','transferred') ORDER BY id DESC LIMIT 1", [$orderId], 'i');
                if ($opdSend) {
                    $next = 'pharmacy';
                    // SET clauses evaluate left-to-right: capture the old
                    // destination into transferred_from BEFORE overwriting it.
                    db_execute(
                        "UPDATE opd_sends SET transferred_from = destination, destination = ?, status = 'pending', transferred_at = NOW(), received_by = NULL WHERE id = ?",
                        [$next, (int) $opdSend['id']],
                        'si'
                    );
                    audit_log('update', 'OPD Send', (int) $opdSend['id'], 'Lab done — transferred patient to Pharmacy automatically');
                    notify(
                        ['permission_key' => 'pharmacy.view', 'branch_id' => $opdSend['branch_id'] !== null ? (int) $opdSend['branch_id'] : null],
                        'OPD referral transferred',
                        'Lab results ready — patient transferred from Laboratory to Pharmacy.',
                        'appointment', 'opd_send', (int) $opdSend['id'], '/opd?tab=pharmacy'
                    );
                }
            }
            audit_log('update', 'Lab Order', $orderId, $complete ? 'Completed lab order ' . ($order['order_code'] ?? '') : 'Updated lab results for ' . ($order['order_code'] ?? ''));
        });
        json_response(['ok' => true, 'message' => 'Results saved successfully.']);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong. Please try again.']);
    }
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
