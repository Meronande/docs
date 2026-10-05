<?php
/**
 * /school/students — school students (per grade + section).
 */
require_once __DIR__ . '/_init.php';
require_permission('school.view');
$canManage = has_permission('school.manage');

$gF = get('grade_id', '');
$sF = get('section_id', '');
$where = ' WHERE s.status = 1';
$params = [];
if ($gF !== '') { $where .= ' AND s.grade_id = ?'; $params[] = (int) $gF; }
if ($sF !== '') { $where .= ' AND s.section_id = ?'; $params[] = (int) $sF; }
$rows = db_fetch_all(
    "SELECT s.*, g.name grade_name, sec.name section_name
     FROM school_students s
     LEFT JOIN grades g ON g.id = s.grade_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     $where ORDER BY g.sort_order, g.name, sec.name, s.full_name LIMIT 300", $params);

ui_page_open(['title' => 'School — Students', 'icon' => 'fa-user-graduate', 'breadcrumb' => ['School' => '/school', 'Students' => null]]);
echo '<div data-crud-url="/ajax/school" data-crud-title="Student">';
echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="school_students">';
echo '<select name="grade_id" class="form-select form-select-sm" style="width:150px"><option value="">All grades</option>';
foreach ($grades as $g) echo '<option value="' . (int) $g['id'] . '" ' . ($gF === (string) $g['id'] ? 'selected' : '') . '>' . e($g['name']) . '</option>';
echo '</select><select name="section_id" class="form-select form-select-sm" style="width:130px"><option value="">All sections</option>';
foreach ($sections as $s) echo '<option value="' . (int) $s['id'] . '" ' . ($sF === (string) $s['id'] ? 'selected' : '') . '>' . e($s['grade_name']) . ' - ' . e($s['name']) . '</option>';
echo '</select><button class="btn btn-sm btn-outline-brand">Filter</button></form>';
if ($canManage) echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/school?form=student" data-title="Student"><i class="fa-solid fa-user-graduate me-1"></i>Add Student</button>';
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Student</th><th>Gender</th><th>Class</th><th>Guardian</th><th>Phone</th>' . ($canManage ? '<th class="text-end">Actions</th>' : '') . '</tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No students found</td></tr>';
foreach ($rows as $s) {
    echo '<tr><td class="small text-nowrap">' . e($s['student_code'] ?? '—') . '</td>';
    echo '<td class="fw-semibold">' . e($s['full_name']) . '</td>';
    echo '<td class="small">' . e(or_na($s['gender'])) . '</td>';
    echo '<td class="small">' . e(or_na($s['grade_name'] . ($s['section_name'] ? ' - ' . $s['section_name'] : ''))) . '</td>';
    echo '<td class="small">' . e(or_na($s['guardian_name'])) . '</td>';
    echo '<td class="small">' . e(or_na($s['guardian_phone'])) . '</td>';
    if ($canManage) {
        echo '<td class="text-end text-nowrap"><button class="btn btn-sm btn-light border" data-action="edit" data-id="' . (int) $s['id'] . '" data-url="/ajax/school?form=student" data-title="Student"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light border" data-school-action="delete_student" data-id="' . (int) $s['id'] . '"><i class="fa-solid fa-user-slash"></i></button></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div></div>';


