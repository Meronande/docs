<?php
/**
 * /payments/export — CSV export of filtered payments.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_login();
require_permission('payments.view');
require_permission('reports.export');

$q = get('q', '');
$methodF = get('method_id', '');
$branchF = get('branch_id', '');
$from = get('from', '');
$to = get('to', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (pay.payment_code LIKE ? OR i.invoice_number LIKE ? OR p.full_name LIKE ? OR pay.reference_number LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($methodF !== '') { $where .= ' AND pay.payment_method_id = ?'; $params[] = (int) $methodF; }
if ($from !== '' && valid_date($from)) { $where .= ' AND pay.payment_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where .= ' AND pay.payment_date <= ?'; $params[] = $to . ' 23:59:59'; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND pay.branch_id = ?'; $params[] = (int) $branchF; }
else {
    $branchId = scope_branch_id();
    if ($branchId !== null) { $where .= ' AND pay.branch_id = ?'; $params[] = $branchId; }
}

$rows = db_fetch_all(
    "SELECT pay.payment_code, pay.payment_date, i.invoice_number, p.patient_code, p.full_name patient_name,
            m.method_name, pay.reference_number, b.branch_name, pay.paid_by, pay.amount
     FROM payments pay
     JOIN invoices i ON i.id = pay.invoice_id
     LEFT JOIN patients p ON p.id = pay.patient_id
     LEFT JOIN payment_methods m ON m.id = pay.payment_method_id
     LEFT JOIN branches b ON b.id = pay.branch_id
     $where ORDER BY pay.payment_date DESC LIMIT 5000",
    $params
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=payments_' . date('Ymd_His') . '.csv');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['Payment Code', 'Date', 'Invoice', 'Patient Code', 'Patient', 'Method', 'Reference', 'Branch', 'Paid By', 'Amount']);
$sum = 0.0;
foreach ($rows as $r) {
    $sum += (float) $r['amount'];
    fputcsv($out, [
        $r['payment_code'], $r['payment_date'], $r['invoice_number'], $r['patient_code'], $r['patient_name'],
        $r['method_name'], $r['reference_number'], $r['branch_name'], $r['paid_by'], money_raw($r['amount']),
    ]);
}
fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL', money_raw($sum)]);
fclose($out);
audit_log('export', 'Payments', null, 'Exported ' . count($rows) . ' payments to CSV');
exit;
