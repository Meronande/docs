<?php
/**
 * /school/reports — attendance reports per class over a date range.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.view');
$teacher = school_current_teacher();
$isAdmin = has_permission('school.manage');
$homerooms = $teacher ? school_teacher_homerooms($teacher['id']) : [];

$selCls = get('cls', '');
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-d'));
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = date('Y-m-d');

$gradeId = null; $sectionId = null;
if ($selCls !== '' && preg_match('/^(\d+):(\d+)$/', $selCls, $m)) { $gradeId = (int) $m[1]; $sectionId = (int) $m[2]; }
$allowed = false;
if ($isAdmin) $allowed = true;
elseif ($gradeId !== null) { foreach ($homerooms as $h) if ((int) $h['grade_id'] === $gradeId && (int) $h['section_id'] === $sectionId) $allowed = true; }

ui_page_open(['title' => 'School — Attendance Reports', 'icon' => 'fa-chart-column', 'breadcrumb' => ['School' => '/school', 'Attendance Reports' => null]]);

echo '<div class="card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end">';
echo '<div class="col-md-4"><label class="form-label">Class' . ($isAdmin ? '' : ' (your homerooms)') . '</label><select class="form-select" name="cls"><option value="">— Select class —</option>';
$classPool = $isAdmin ? $sections : $homerooms;
foreach ($classPool as $h) {
    $gName = $isAdmin ? $h['grade_name'] : $h['grade_name'];
    $sName = $isAdmin ? $h['name'] : $h['section_name'];
    $v = ($isAdmin ? $h['grade_id'] : $h['grade_id']) . ':' . ($isAdmin ? $h['id'] : $h['section_id']);
    echo '<option value="' . e($v) . '" ' . ($selCls === $v ? 'selected' : '') . '>' . e($gName . ' - ' . $sName) . '</option>';
}
echo '</select></div>';
echo '<div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="' . e($from) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="' . e($to) . '"></div>';
echo '<div class="col-md-2"><button class="btn btn-brand w-100"><i class="fa-solid fa-chart-column me-1"></i>Run Report</button></div>';
echo '</form></div></div>';

if (!$allowed || $gradeId === null) {
    echo '<div class="alert alert-info">' . ($isAdmin ? 'Pick a class and date range to run the report.' : 'You are not the homeroom teacher of any class yet.') . '</div>';
} else {
    $students = db_fetch_all('SELECT id, student_code, full_name FROM school_students WHERE grade_id = ? AND section_id = ? AND status = 1 ORDER BY full_name', [$gradeId, $sectionId], 'ii');
    $rows = db_fetch_all(
        'SELECT student_id, status, COUNT(*) cnt FROM school_attendance
         WHERE grade_id = ? AND section_id = ? AND att_date BETWEEN ? AND ?
         GROUP BY student_id, status', [$gradeId, $sectionId, $from, $to], 'iiss');
    $byStudent = [];
    $tot = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
    foreach ($rows as $r) {
        $byStudent[$r['student_id']][$r['status']] = (int) $r['cnt'];
        $tot[$r['status']] = ($tot[$r['status']] ?? 0) + (int) $r['cnt'];
    }
    $grand = $tot['present'] + $tot['absent'] + $tot['late'] + $tot['excused'];
    $days = (int) db_fetch_value('SELECT COUNT(DISTINCT att_date) FROM school_attendance WHERE grade_id = ? AND section_id = ? AND att_date BETWEEN ? AND ?', [$gradeId, $sectionId, $from, $to], 'iiss');

    $classLabel = '';
    foreach ($sections as $s) if ((int) $s['id'] === $sectionId) $classLabel = $s['grade_name'] . ' - ' . $s['name'];

    echo '<div class="row g-3 mb-4">';
    echo stat_card('Students', count($students), 'fa-user-graduate', 'brand');
    echo stat_card('Days Recorded', $days, 'fa-calendar-days', 'success');
    echo stat_card('Overall Present', $grand ? money_raw($tot['present'] * 100 / max($grand, 1)) . '%' : '—', 'fa-user-check', 'success');
    echo stat_card('Total Absences', $tot['absent'], 'fa-user-xmark', 'danger');
    echo '</div>';

    echo '<div class="card"><div class="card-header py-2 fw-semibold">Attendance — ' . e($classLabel) . ' (' . fmt_date($from) . ' → ' . fmt_date($to) . ')</div>';
    echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
    echo '<thead><tr><th>Student</th><th class="text-center">Present</th><th class="text-center">Absent</th><th class="text-center">Late</th><th class="text-center">Excused</th><th class="text-center">Rate</th></tr></thead><tbody>';
    if (!$students) echo '<tr><td colspan="6" class="text-center text-muted py-4">No students in this class</td></tr>';
    foreach ($students as $s) {
        $d = $byStudent[$s['id']] ?? [];
        $p = $d['present'] ?? 0; $a = $d['absent'] ?? 0; $l = $d['late'] ?? 0; $e2 = $d['excused'] ?? 0;
        $recs = $p + $a + $l + $e2;
        $rate = $recs ? ($p + $l) * 100 / $recs : null;
        echo '<tr><td class="fw-semibold">' . e($s['full_name']) . ' <span class="small text-muted">' . e($s['student_code'] ?? '') . '</span></td>';
        echo '<td class="text-center">' . $p . '</td><td class="text-center">' . ($a ? '<span class="badge text-bg-danger">' . $a . '</span>' : '0') . '</td>';
        echo '<td class="text-center">' . $l . '</td><td class="text-center">' . $e2 . '</td>';
        echo '<td class="text-center">' . ($rate === null ? '—' : '<span class="badge ' . ($rate >= 90 ? 'text-bg-success' : ($rate >= 75 ? 'text-bg-warning' : 'text-bg-danger')) . '">' . money_raw($rate) . '%</span>') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}
ui_page_close();
