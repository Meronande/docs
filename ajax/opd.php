<?php
/**
 * OPD internal referrals (sends) AJAX engine.
 *
 * POST /ajax/opd  action=send      { patient_id, destination, blood_pressure?, temperature?, weight?, pulse?, complaint?, note?, visit_id? }
 * POST /ajax/opd  action=receive   { id }                 — destination dept marks it received
 * POST /ajax/opd  action=transfer  { id, destination }    — e.g. lab → pharmacy after results
 * POST /ajax/opd  action=complete  { id, note? }
 * POST /ajax/opd  action=cancel    { id }
 *
 * Destination permissions: laboratory → laboratory.view, pharmacy → pharmacy.view,
 * od/doctor → dashboard.view (front desk, admins, doctors).
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/payroll.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

/** Permission that maps to an OPD destination. */
function opd_dest_permission(string $dest): string
{
    return ['laboratory' => 'laboratory.view', 'pharmacy' => 'pharmacy.view', 'od' => 'dashboard.view', 'doctor' => 'dashboard.view'][$dest] ?? 'dashboard.view';
}

/** Can this user work on referrals currently parked at $dest? */
function opd_can_handle(string $dest): bool
{
    return has_permission(opd_dest_permission($dest));
}

function opd_dest_label(string $dest): string
{
    return ['laboratory' => 'Laboratory', 'pharmacy' => 'Pharmacy', 'od' => 'OPD', 'doctor' => 'Doctor'][$dest] ?? $dest;
}

$action = post('action', '');
$id = (int) post('id', '0');

// ---------------------------------------------------------------------
// SEND
// ---------------------------------------------------------------------
if ($action === 'send') {
    if (!has_permission('opd.send')) json_response(['ok' => false, 'message' => 'You do not have permission to send patients.'], 403);
    $patientId = (int) post('patient_id', '0');
    $dest = post('destination', '');
    if ($patientId <= 0) json_response(['ok' => false, 'message' => 'Select a patient first.']);
    if (!in_array($dest, ['laboratory', 'pharmacy', 'od', 'doctor'], true)) json_response(['ok' => false, 'message' => 'Choose a destination (Lab, Pharmacy, OPD or Doctor).']);

    $patient = db_fetch_one('SELECT id, full_name, patient_code, branch_id FROM patients WHERE id = ?', [$patientId], 'i');
    if (!$patient) json_response(['ok' => false, 'message' => 'Patient not found.'], 404);

    $visitId = post('visit_id') !== '' ? (int) post('visit_id') : null;
    $bp = mb_substr(post('blood_pressure'), 0, 20);
    $temp = post('temperature') !== '' ? (float) post('temperature') : null;
    $weight = post('weight') !== '' ? (float) post('weight') : null;
    $pulse = mb_substr(post('pulse'), 0, 20);
    $complaint = mb_substr(post('complaint'), 0, 255);
    $note = mb_substr(post('note'), 0, 255);
    $branchId = $patient['branch_id'] !== null ? (int) $patient['branch_id'] : (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null);

    try {
        $sendId = db_transaction(function () use ($patientId, $visitId, $branchId, $dest, $bp, $temp, $weight, $pulse, $complaint, $note) {
            $sid = db_execute(
                'INSERT INTO opd_sends (patient_id, visit_id, branch_id, destination, vitals_blood_pressure, vitals_temperature, vitals_weight, vitals_pulse, complaint, note, status, sent_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$patientId, $visitId, $branchId, $dest, $bp !== '' ? $bp : null, $temp, $weight, $pulse !== '' ? $pulse : null,
                 $complaint !== '' ? $complaint : null, $note !== '' ? $note : null, 'pending', (int) current_user()['id']]
            );
            // Record vitals on the patient file when provided (linked to visit if any).
            if ($bp !== '' || $temp !== null || $weight !== null || $pulse !== '') {
                db_execute(
                    'INSERT INTO patient_vitals (patient_id, visit_id, temperature, weight, blood_pressure, pulse, recorded_by) VALUES (?,?,?,?,?,?,?)',
                    [$patientId, $visitId, $temp, $weight, $bp !== '' ? $bp : null, $pulse !== '' ? $pulse : null, (int) current_user()['id']]
                );
            }
            return (int) $sid;
        });

        audit_log('create', 'OPD Send', $sendId, 'Sent ' . $patient['full_name'] . ' (' . $patient['patient_code'] . ') to ' . opd_dest_label($dest));
        notify(
            ['permission_key' => opd_dest_permission($dest), 'branch_id' => $branchId],
            'OPD referral: ' . opd_dest_label($dest),
            $patient['full_name'] . ' (' . $patient['patient_code'] . ') sent to ' . opd_dest_label($dest) . ($complaint !== '' ? ' — ' . $complaint : '') . '.',
            'appointment', 'opd_send', $sendId, '/opd'
        );
        json_response(['ok' => true, 'message' => $patient['full_name'] . ' sent to ' . opd_dest_label($dest) . '.', 'id' => $sendId]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Could not send patient.']);
    }
}

// ---------------------------------------------------------------------
// Everything below needs an existing send
// ---------------------------------------------------------------------
if (in_array($action, ['receive', 'transfer', 'complete', 'cancel'], true)) {
    $send = db_fetch_one('SELECT os.*, p.full_name patient_name, p.patient_code FROM opd_sends os JOIN patients p ON p.id = os.patient_id WHERE os.id = ?', [$id], 'i');
    if (!$send) json_response(['ok' => false, 'message' => 'Referral not found.'], 404);
    $dest = (string) $send['destination'];
    if (!opd_can_handle($dest) && !has_permission('opd.send')) {
        json_response(['ok' => false, 'message' => 'This referral belongs to ' . opd_dest_label($dest) . '.'], 403);
    }
}

if ($action === 'receive') {
    if ($send['status'] !== 'pending') json_response(['ok' => false, 'message' => 'Only pending referrals can be received.']);
    db_execute("UPDATE opd_sends SET status = 'received', received_by = ? WHERE id = ?", [(int) current_user()['id'], $id], 'ii');
    audit_log('update', 'OPD Send', $id, 'Received ' . $send['patient_name'] . ' at ' . opd_dest_label($dest));
    json_response(['ok' => true, 'message' => $send['patient_name'] . ' received at ' . opd_dest_label($dest) . '.', 'reload' => true]);
}

if ($action === 'transfer') {
    $newDest = post('destination', '');
    if (!in_array($newDest, ['laboratory', 'pharmacy', 'od', 'doctor'], true)) json_response(['ok' => false, 'message' => 'Choose a valid destination.']);
    if ($newDest === $dest) json_response(['ok' => false, 'message' => 'Already at ' . opd_dest_label($dest) . '.']);
    if (in_array($send['status'], ['completed', 'cancelled'], true)) json_response(['ok' => false, 'message' => 'This referral is closed.']);
    if (!opd_can_handle($newDest)) json_response(['ok' => false, 'message' => 'You cannot send to ' . opd_dest_label($newDest) . '.'], 403);

    // Capture the old destination first (SET evaluates left-to-right).
    db_execute(
        "UPDATE opd_sends SET transferred_from = destination, destination = ?, status = 'pending', transferred_at = NOW(), received_by = NULL WHERE id = ?",
        [$newDest, $id],
        'si'
    );
    audit_log('update', 'OPD Send', $id, 'Transferred ' . $send['patient_name'] . ' from ' . opd_dest_label($dest) . ' to ' . opd_dest_label($newDest));
    notify(
        ['permission_key' => opd_dest_permission($newDest), 'branch_id' => $send['branch_id'] !== null ? (int) $send['branch_id'] : null],
        'OPD referral transferred',
        $send['patient_name'] . ' (' . $send['patient_code'] . ') transferred from ' . opd_dest_label($dest) . ' to ' . opd_dest_label($newDest) . '.',
        'appointment', 'opd_send', $id, '/opd'
    );
    json_response(['ok' => true, 'message' => $send['patient_name'] . ' transferred to ' . opd_dest_label($newDest) . '.', 'reload' => true]);
}

if ($action === 'complete') {
    if (in_array($send['status'], ['completed', 'cancelled'], true)) json_response(['ok' => false, 'message' => 'Already closed.']);
    $note = mb_substr(post('note'), 0, 255);
    db_execute(
        "UPDATE opd_sends SET status = 'completed', completed_at = NOW(), note = CONCAT(COALESCE(note, ''), CASE WHEN note IS NULL OR note = '' THEN '' ELSE ' | ' END, ?) WHERE id = ?",
        ['Completed' . ($note !== '' ? ': ' . $note : ''), $id],
        'si'
    );
    audit_log('update', 'OPD Send', $id, 'Completed OPD referral for ' . $send['patient_name']);
    json_response(['ok' => true, 'message' => 'Referral completed.', 'reload' => true]);
}

if ($action === 'cancel') {
    if (in_array($send['status'], ['completed', 'cancelled'], true)) json_response(['ok' => false, 'message' => 'Already closed.']);
    if (!has_permission('opd.send')) json_response(['ok' => false, 'message' => 'Only the sender side can cancel.'], 403);
    db_execute("UPDATE opd_sends SET status = 'cancelled', completed_at = NOW() WHERE id = ?", [$id], 'i');
    audit_log('update', 'OPD Send', $id, 'Cancelled OPD referral for ' . $send['patient_name']);
    json_response(['ok' => true, 'message' => 'Referral cancelled.', 'reload' => true]);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
