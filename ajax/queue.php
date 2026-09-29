<?php
/**
 * AJAX queue management.
 * POST action=call|room|skip|recall|done|checkin
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if (!has_permission('queue.manage')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

$action = post('action', '');
$id = (int) post('id', '0');
$today = date('Y-m-d');

if ($action === 'checkin') {
    $patientId = (int) post('patient_id', '0');
    $patient = db_fetch_one('SELECT id, full_name, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    if (!$patient) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);
    assert_branch_access($patient['branch_id'] !== null ? (int) $patient['branch_id'] : null);
    $branchId = $patient['branch_id'] ?? current_user()['branch_id'];
    $code = generate_code('queue_prefix', 'queue', 'queue_code', 3);
    $qid = db_execute('INSERT INTO queue (queue_code, patient_id, branch_id, queue_date, status, created_by) VALUES (?,?,?,?,?,?)',
        [$code, $patientId, $branchId !== null ? (int) $branchId : null, $today, 'waiting', (int) current_user()['id']]);
    audit_log('create', 'Queue', $qid, 'Walk-in check-in: ' . $patient['full_name'] . ' as ' . $code);
    notify(['permission_key' => 'queue.view', 'branch_id' => $branchId !== null ? (int) $branchId : null],
        'Patient checked in', $patient['full_name'] . ' checked in as ' . $code . '.', 'appointment', 'queue', $qid, '/queue');
    json_response(['ok' => true, 'message' => 'Checked in as ' . $code . '.', 'code' => $code]);
}

$q = db_fetch_one('SELECT q.*, p.full_name FROM queue q JOIN patients p ON p.id = q.patient_id WHERE q.id = ?', [$id], 'i');
if (!$q) json_response(['ok' => false, 'message' => 'Queue entry not found.'], 404);
assert_branch_access($q['branch_id'] !== null ? (int) $q['branch_id'] : null);

switch ($action) {
    case 'call':
        if ($q['status'] !== 'waiting') json_response(['ok' => false, 'message' => 'Only waiting patients can be called.']);
        db_execute("UPDATE queue SET status = 'called', called_at = NOW() WHERE id = ?", [$id], 'i');
        audit_log('update', 'Queue', $id, 'Called ' . $q['queue_code'] . ' (' . $q['full_name'] . ')');
        notify(['permission_key' => 'queue.view', 'branch_id' => $q['branch_id']],
            'Now serving', 'Queue ' . $q['queue_code'] . ' — ' . $q['full_name'] . ' has been called.', 'appointment', 'queue', $id, '/queue');
        json_response(['ok' => true, 'message' => 'Now serving ' . $q['queue_code']]);
    case 'room':
        if (!in_array($q['status'], ['called', 'skipped'], true)) json_response(['ok' => false, 'message' => 'Call the patient first.']);
        db_execute("UPDATE queue SET status = 'in_room' WHERE id = ?", [$id], 'i');
        audit_log('update', 'Queue', $id, 'Moved ' . $q['queue_code'] . ' into consultation room');
        json_response(['ok' => true, 'message' => $q['queue_code'] . ' is now in consultation.']);
    case 'skip':
        if ($q['status'] !== 'waiting') json_response(['ok' => false, 'message' => 'Only waiting patients can be skipped.']);
        db_execute("UPDATE queue SET status = 'skipped' WHERE id = ?", [$id], 'i');
        audit_log('update', 'Queue', $id, 'Skipped ' . $q['queue_code']);
        json_response(['ok' => true, 'message' => $q['queue_code'] . ' skipped.']);
    case 'recall':
        db_execute("UPDATE queue SET status = 'waiting' WHERE id = ?", [$id], 'i');
        audit_log('update', 'Queue', $id, 'Recalled ' . $q['queue_code']);
        json_response(['ok' => true, 'message' => $q['queue_code'] . ' back to waiting list.']);
    case 'done':
        db_execute("UPDATE queue SET status = 'completed', completed_at = NOW() WHERE id = ?", [$id], 'i');
        audit_log('update', 'Queue', $id, 'Completed ' . $q['queue_code'] . ' (' . $q['full_name'] . ')');
        json_response(['ok' => true, 'message' => $q['queue_code'] . ' completed.']);
    default:
        json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
}
