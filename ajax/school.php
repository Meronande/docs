<?php
/**
 * AJAX school (LMS) endpoint.
 *
 * GET  ?form=teacher[&id=]        -> teacher modal form
 * GET  ?template                  -> CSV template for bulk teacher upload
 * GET  ?roster=&alloc=            -> students + categories + marks for an allocation (JSON)
 * GET  ?att_roster&grade=&section=&date= -> students + saved attendance (JSON)
 * GET  ?download_exam=<id>        -> stream an uploaded exam file
 * POST save_teacher | delete_teacher | upload_teachers | save_student | delete_student
 *      save_grade | save_section | save_subject | delete_grade | delete_section | delete_subject
 *      save_homeroom | delete_homeroom | save_allocations | delete_allocation
 *      save_category | delete_category | save_marks | upload_exam | delete_exam | save_attendance
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/school.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('school.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

function school_require_manage(): void
{
    if (!has_permission('school.manage')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

/** The logged-in teacher may touch this class if they run it as homeroom. */
function school_can_touch_class($gradeId, $sectionId): bool
{
    if (has_permission('school.manage')) return true;
    $t = school_current_teacher();
    if (!$t) return false;
    return (bool) db_fetch_value('SELECT COUNT(*) FROM school_homerooms WHERE teacher_id = ? AND grade_id = ? AND section_id = ?', [$t['id'], $gradeId, $sectionId], 'iii');
}

/** The logged-in teacher must own this subject allocation; returns the allocation row. */
function school_owned_allocation($allocId)
{
    $a = db_fetch_one('SELECT a.*, g.name grade_name, sec.name section_name, sub.name subject_name
                       FROM school_allocations a
                       JOIN grades g ON g.id = a.grade_id
                       JOIN sections sec ON sec.id = a.section_id
                       JOIN subjects sub ON sub.id = a.subject_id
                       WHERE a.id = ?', [$allocId], 'i');
    if (!$a) json_response(['ok' => false, 'message' => 'Allocation not found.'], 404);
    if (has_permission('school.manage')) return $a;
    $t = school_current_teacher();
    if (!$t || (int) $a['teacher_id'] !== (int) $t['id']) json_response(['ok' => false, 'message' => 'This class is not allocated to you.'], 403);
    return $a;
}

/** Guard: mark categories of a subject must total exactly 100. */
function school_assert_categories_complete($subjectId, $subjectName): void
{
    $total = school_categories_total($subjectId);
    if (abs($total - 100.0) > 0.001) {
        json_response(['ok' => false, 'message' => 'Mark categories for ' . $subjectName . ' total ' . money_raw($total) . '/100. The school admin must make the categories sum to exactly 100 first.']);
    }
}

// ---------------------------------------------------------------------
// GET: teacher modal form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') === 'teacher') {
    school_require_manage();
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $t = $id ? (db_fetch_one('SELECT * FROM school_teachers WHERE id = ?', [$id], 'i') ?? []) : [];

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/school">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save_teacher">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($t['full_name'] ?? '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="' . e($t['email'] ?? '') . '"><div class="form-text">Used as the login username when creating an account.</div></div>';
    echo '<div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="' . e($t['phone'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Qualification</label><input class="form-control" name="qualification" value="' . e($t['qualification'] ?? '') . '"></div>';
    if (!$id) {
        echo '<div class="col-md-6"><label class="form-label">Login account</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="create_login" value="1" checked></div><div class="form-text">Creates a Teacher login (username from email/name, password <code>Teacher@123</code>).</div></div>';
    }
    echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($t['status'] ?? 1) === 1 ? 'checked' : '') . '></div></div>';
    echo '</div></form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

// ---------------------------------------------------------------------
// GET: student modal form
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') === 'student') {
    school_require_manage();
    $id = get('id', '') !== '' ? (int) get('id') : null;
    $s = $id ? (db_fetch_one('SELECT * FROM school_students WHERE id = ?', [$id], 'i') ?? []) : [];

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/school">';
    echo '<input type="hidden" name="id" value="' . e((string) ($id ?? '')) . '">';
    echo '<input type="hidden" name="action" value="save_student">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="' . e($s['full_name'] ?? '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label">Gender</label><select class="form-select" name="gender"><option value="">—</option>';
    foreach (['Male', 'Female'] as $gen) echo '<option ' . (($s['gender'] ?? '') === $gen ? 'selected' : '') . '>' . $gen . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Grade</label><select class="form-select" name="grade_id" id="stGrade"><option value="">—</option>';
    foreach (db_fetch_all('SELECT * FROM grades WHERE status = 1 ORDER BY sort_order, name') as $g) echo '<option value="' . (int) $g['id'] . '" ' . ((string) ($s['grade_id'] ?? '') === (string) $g['id'] ? 'selected' : '') . '>' . e($g['name']) . '</option>';
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Section</label><select class="form-select" name="section_id" id="stSection"><option value="">—</option>';
    foreach (db_fetch_all('SELECT s.*, g.name grade_name FROM sections s JOIN grades g ON g.id = s.grade_id WHERE s.status = 1 ORDER BY g.sort_order, s.name') as $sec) echo '<option value="' . (int) $sec['id'] . '" data-grade="' . (int) $sec['grade_id'] . '" ' . ((string) ($s['section_id'] ?? '') === (string) $sec['id'] ? 'selected' : '') . '>' . e($sec['grade_name'] . ' - ' . $sec['name']) . '</option>';
    echo '</select><div class="form-text">Sections are managed in Academic Setup.</div></div>';
    echo '<div class="col-md-6"><label class="form-label">Guardian Name</label><input class="form-control" name="guardian_name" value="' . e($s['guardian_name'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Guardian Phone</label><input class="form-control" name="guardian_phone" value="' . e($s['guardian_phone'] ?? '') . '"></div>';
    echo '<div class="col-md-6"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="status" value="1" ' . ((int) ($s['status'] ?? 1) === 1 ? 'checked' : '') . '></div></div>';
    echo '</div></form>';
    $html = ob_get_clean();
    $html .= <<<'JS'
<script>
(function () {
  const gradeSel = document.getElementById('stGrade');
  const secSel = document.getElementById('stSection');
  if (!gradeSel || !secSel) return;
  const apply = () => { secSel.querySelectorAll('option').forEach(o => { if (o.dataset.grade) o.hidden = o.dataset.grade !== gradeSel.value; }); };
  gradeSel.addEventListener('change', apply);
  apply();
})();
</script>
JS;
    json_response(['ok' => true, 'html' => $html]);
}

// ---------------------------------------------------------------------
// GET: CSV template for bulk teacher upload
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('template') !== '') {
    school_require_manage();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teachers_template.csv"');
    echo "Full Name,Email,Phone,Qualification\n";
    echo "Abebe Kebede,abebe@school.edu,0911000000,BSc Mathematics\n";
    exit;
}

// ---------------------------------------------------------------------
// GET: roster + categories + saved marks for one allocation (JSON)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('roster') !== '') {
    $alloc = school_owned_allocation((int) get('roster'));
    school_assert_categories_complete((int) $alloc['subject_id'], $alloc['subject_name']);
    $students = db_fetch_all(
        'SELECT id, student_code, full_name FROM school_students
         WHERE grade_id = ? AND section_id = ? AND status = 1 ORDER BY full_name',
        [$alloc['grade_id'], $alloc['section_id']], 'ii');
    $categories = db_fetch_all('SELECT id, name, max_mark FROM school_mark_categories WHERE subject_id = ? ORDER BY sort_order, id', [$alloc['subject_id']], 'i');
    $marks = [];
    foreach (db_fetch_all('SELECT student_id, category_id, score FROM school_marks WHERE subject_id = ?', [$alloc['subject_id']], 'i') as $m) {
        $marks[$m['student_id'] . '_' . $m['category_id']] = money_raw($m['score']);
    }
    json_response(['ok' => true, 'allocation' => $alloc, 'students' => $students, 'categories' => $categories, 'marks' => $marks,
                   'total' => money_raw(school_categories_total((int) $alloc['subject_id']))]);
}

// ---------------------------------------------------------------------
// GET: attendance roster for one class + date (JSON)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('att_roster') !== '') {
    $gradeId = (int) get('grade');
    $sectionId = (int) get('section');
    $date = get('date');
    if (!valid_date($date)) json_response(['ok' => false, 'message' => 'Invalid date.']);
    if (!school_can_touch_class($gradeId, $sectionId)) json_response(['ok' => false, 'message' => 'You are not the homeroom teacher of this class.'], 403);
    $students = db_fetch_all(
        'SELECT id, student_code, full_name FROM school_students
         WHERE grade_id = ? AND section_id = ? AND status = 1 ORDER BY full_name',
        [$gradeId, $sectionId], 'ii');
    $saved = [];
    foreach (db_fetch_all('SELECT student_id, status, note FROM school_attendance WHERE grade_id = ? AND section_id = ? AND att_date = ?', [$gradeId, $sectionId, $date], 'iis') as $a) {
        $saved[$a['student_id']] = ['status' => $a['status'], 'note' => (string) $a['note']];
    }
    json_response(['ok' => true, 'students' => $students, 'saved' => $saved, 'date' => $date]);
}

// ---------------------------------------------------------------------
// GET: download an exam file
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('download_exam') !== '') {
    $exam = db_fetch_one('SELECT * FROM school_exams WHERE id = ?', [(int) get('download_exam')], 'i');
    if (!$exam) json_response(['ok' => false, 'message' => 'Exam not found.'], 404);
    if (!has_permission('school.manage')) {
        $t = school_current_teacher();
        if (!$t || (int) $exam['teacher_id'] !== (int) $t['id']) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $path = school_exam_dir() . '/' . basename((string) $exam['file_path']);
    if (!is_file($path)) json_response(['ok' => false, 'message' => 'File missing on server.'], 404);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) ($exam['file_name'] ?: ('exam_' . $exam['id'] . '.docx'))) . '"');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------
// POST: save teacher (+ optional Teacher login)
// ---------------------------------------------------------------------
if (post('action') === 'save_teacher') {
    school_require_manage();
    $id = post('id', '') !== '' ? (int) post('id') : null;
    $name = post('full_name');
    if ($name === '') json_response(['ok' => false, 'message' => 'Full name is required.']);
    $email = post('email') ?: null;
    $data = [
        'full_name' => $name,
        'email' => $email,
        'phone' => post('phone') ?: null,
        'qualification' => post('qualification') ?: null,
        'status' => isset($_POST['status']) ? 1 : 0,
    ];
    try {
        $teacherId = db_transaction(function () use ($data, $id, $name, $email) {
            if ($id) {
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
                db_execute("UPDATE school_teachers SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
                audit_log('update', 'School Teacher', $id, 'Updated teacher: ' . $name);
                return $id;
            }
            $data['teacher_code'] = generate_code('teacher_prefix', 'school_teachers', 'teacher_code');
            if (isset($_POST['create_login'])) {
                $data['user_id'] = school_create_teacher_user($name, $email);
            }
            $cols = '`' . implode('`, `', array_keys($data)) . '`';
            $newId = db_execute("INSERT INTO school_teachers ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
            audit_log('create', 'School Teacher', $newId, 'Added teacher: ' . $name);
            return $newId;
        });
        json_response(['ok' => true, 'message' => 'Teacher saved.', 'id' => $teacherId]);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong.']);
    }
}

/** Create a Teacher-role user account; returns the user id. */
function school_create_teacher_user($fullName, $email)
{
    $role = db_fetch_one("SELECT id FROM roles WHERE role_name = 'Teacher' AND status = 1");
    if (!$role) throw new RuntimeException("The 'Teacher' role is missing — run database/migration_school.sql.");
    $base = ($email !== null && $email !== '') ? strtolower((string) preg_replace('/@.*$/', '', $email)) : strtolower((string) preg_replace('/[^a-z]/i', '', (string) $fullName));
    $base = (string) preg_replace('/[^a-z0-9._-]/', '', $base);
    if (strlen($base) < 3) $base = 'teacher';
    $username = substr($base, 0, 45);
    $n = 1;
    while ((int) db_fetch_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username], 's') > 0) {
        $n++;
        $username = substr($base, 0, 43) . $n;
    }
    return db_execute('INSERT INTO users (role_id, full_name, username, email, password, status) VALUES (?,?,?,?,?,1)',
        [$role['id'], $fullName, $username, $email, password_hash('Teacher@123', PASSWORD_DEFAULT)], 'iisss');
}

// ---------------------------------------------------------------------
// POST: bulk upload teachers from CSV / XLSX
// ---------------------------------------------------------------------
if (post('action') === 'upload_teachers') {
    school_require_manage();
    if (empty($_FILES['file']['name'])) json_response(['ok' => false, 'message' => 'Choose a file to upload.']);
    try {
        $rows = school_spreadsheet_rows($_FILES['file']['tmp_name'], $_FILES['file']['name']);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    }
    if (!$rows) json_response(['ok' => false, 'message' => 'The file is empty.']);
    $first = array_map(function ($c) { return strtolower((string) $c); }, $rows[0]);
    if (in_array('full name', $first, true)) array_shift($rows); // header row
    $created = 0; $skipped = 0; $logins = 0; $errors = [];
    foreach ($rows as $idx => $row) {
        $name = trim((string) ($row[0] ?? ''));
        $email = trim((string) ($row[1] ?? '')) ?: null;
        $phone = trim((string) ($row[2] ?? '')) ?: null;
        $qual = trim((string) ($row[3] ?? '')) ?: null;
        if ($name === '') { $skipped++; continue; }
        try {
            db_transaction(function () use ($name, $email, $phone, $qual, &$logins) {
                $userId = school_create_teacher_user($name, $email);
                $logins++;
                db_execute('INSERT INTO school_teachers (user_id, teacher_code, full_name, email, phone, qualification, status) VALUES (?,?,?,?,?,?,1)',
                    [$userId, generate_code('teacher_prefix', 'school_teachers', 'teacher_code'), $name, $email, $phone, $qual], 'isssss');
            });
            audit_log('create', 'School Teacher', 0, 'Bulk-imported teacher: ' . $name);
            $created++;
        } catch (Throwable $ex) {
            $skipped++;
            $errors[] = 'Row ' . ($idx + 1) . ' (' . $name . '): ' . $ex->getMessage();
        }
    }
    $msg = 'Imported ' . $created . ' teacher(s) with login(s) (default password Teacher@123)';
    if ($skipped > 0) $msg .= ', skipped ' . $skipped;
    json_response(['ok' => true, 'message' => $msg . '.', 'created' => $created, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 5)]);
}

// ---------------------------------------------------------------------
// POST: students CRUD
// ---------------------------------------------------------------------
if (post('action') === 'save_student') {
    school_require_manage();
    $id = post('id', '') !== '' ? (int) post('id') : null;
    $name = post('full_name');
    if ($name === '') json_response(['ok' => false, 'message' => 'Full name is required.']);
    $gradeId = post('grade_id') !== '' ? (int) post('grade_id') : null;
    $sectionId = post('section_id') !== '' ? (int) post('section_id') : null;
    $data = [
        'full_name' => $name,
        'gender' => in_array(post('gender'), ['Male', 'Female'], true) ? post('gender') : null,
        'grade_id' => $gradeId,
        'section_id' => $sectionId,
        'guardian_name' => post('guardian_name') ?: null,
        'guardian_phone' => post('guardian_phone') ?: null,
        'status' => isset($_POST['status']) ? 1 : 0,
    ];
    try {
        if ($id) {
            $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($data)));
            db_execute("UPDATE school_students SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
            audit_log('update', 'School Student', $id, 'Updated student: ' . $name);
            json_response(['ok' => true, 'message' => 'Student updated.']);
        }
        $data['student_code'] = generate_code('student_prefix', 'school_students', 'student_code');
        $cols = '`' . implode('`, `', array_keys($data)) . '`';
        $newId = db_execute("INSERT INTO school_students ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
        audit_log('create', 'School Student', $newId, 'Added student: ' . $name);
        json_response(['ok' => true, 'message' => 'Student added.']);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong.']);
    }
}

if (post('action') === 'delete_teacher' || post('action') === 'delete_student') {
    school_require_manage();
    $id = (int) post('id', '0');
    if (post('action') === 'delete_teacher') {
        $row = db_fetch_one('SELECT user_id, full_name FROM school_teachers WHERE id = ?', [$id], 'i');
        db_execute('UPDATE school_teachers SET status = 0 WHERE id = ?', [$id], 'i');
        if ($row && $row['user_id']) db_execute('UPDATE users SET status = 0 WHERE id = ?', [$row['user_id']], 'i');
        audit_log('delete', 'School Teacher', $id, 'Deactivated teacher: ' . ($row['full_name'] ?? '#' . $id));
    } else {
        $row = db_fetch_one('SELECT full_name FROM school_students WHERE id = ?', [$id], 'i');
        db_execute('UPDATE school_students SET status = 0 WHERE id = ?', [$id], 'i');
        audit_log('delete', 'School Student', $id, 'Deactivated student: ' . ($row['full_name'] ?? '#' . $id));
    }
    json_response(['ok' => true, 'message' => 'Deactivated.']);
}

// ---------------------------------------------------------------------
// POST: academic setup — grades / sections / subjects
// ---------------------------------------------------------------------
if (post('action') === 'save_grade' || post('action') === 'save_subject' || post('action') === 'save_section') {
    school_require_manage();
    $action = post('action');
    try {
        if ($action === 'save_grade') {
            $name = trim((string) post('name'));
            if ($name === '') json_response(['ok' => false, 'message' => 'Grade name is required.']);
            db_execute('INSERT INTO grades (name, sort_order) VALUES (?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), status = 1', [$name, (int) post('sort_order', '0')], 'si');
            audit_log('create', 'School Grade', 0, 'Saved grade: ' . $name);
            json_response(['ok' => true, 'message' => 'Grade saved.']);
        }
        if ($action === 'save_subject') {
            $name = trim((string) post('name'));
            $code = strtoupper(trim((string) post('code')));
            if ($name === '') json_response(['ok' => false, 'message' => 'Subject name is required.']);
            db_execute('INSERT INTO subjects (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE code = VALUES(code), status = 1', [$code !== '' ? $code : null, $name], 'ss');
            audit_log('create', 'School Subject', 0, 'Saved subject: ' . $name);
            json_response(['ok' => true, 'message' => 'Subject saved.']);
        }
        if ($action === 'save_section') {
            $gradeId = (int) post('grade_id', '0');
            $name = strtoupper(trim((string) post('name')));
            if (!$gradeId || $name === '') json_response(['ok' => false, 'message' => 'Pick a grade and a section name.']);
            db_execute('INSERT INTO sections (grade_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 1', [$gradeId, $name], 'is');
            audit_log('create', 'School Section', 0, 'Saved section ' . $name);
            json_response(['ok' => true, 'message' => 'Section saved.']);
        }
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Could not save (duplicate name?).']);
    }
}

if (post('action') === 'delete_grade' || post('action') === 'delete_subject' || post('action') === 'delete_section') {
    school_require_manage();
    $id = (int) post('id', '0');
    $map = ['delete_grade' => ['grades', 'School Grade'], 'delete_subject' => ['subjects', 'School Subject'], 'delete_section' => ['sections', 'School Section']];
    [$table, $label] = $map[post('action')];
    db_execute("UPDATE $table SET status = 0 WHERE id = ?", [$id], 'i');
    audit_log('delete', $label, $id, 'Deactivated #' . $id);
    json_response(['ok' => true, 'message' => 'Deactivated.']);
}

// ---------------------------------------------------------------------
// POST: homeroom assignment (one teacher per grade+section)
// ---------------------------------------------------------------------
if (post('action') === 'save_homeroom') {
    school_require_manage();
    $teacherId = (int) post('teacher_id', '0');
    $gradeId = (int) post('grade_id', '0');
    $sectionId = (int) post('section_id', '0');
    if (!$teacherId || !$gradeId || !$sectionId) json_response(['ok' => false, 'message' => 'Pick a teacher, grade and section.']);
    $teacher = db_fetch_one('SELECT full_name FROM school_teachers WHERE id = ? AND status = 1', [$teacherId], 'i');
    if (!$teacher) json_response(['ok' => false, 'message' => 'Teacher not found.']);
    db_transaction(function () use ($teacherId, $gradeId, $sectionId) {
        db_execute('DELETE FROM school_homerooms WHERE grade_id = ? AND section_id = ?', [$gradeId, $sectionId], 'ii');
        db_execute('INSERT INTO school_homerooms (teacher_id, grade_id, section_id) VALUES (?,?,?)', [$teacherId, $gradeId, $sectionId], 'iii');
    });
    audit_log('update', 'School Homeroom', 0, $teacher['full_name'] . ' assigned as homeroom teacher (grade ' . $gradeId . ', section ' . $sectionId . ')');
    json_response(['ok' => true, 'message' => $teacher['full_name'] . ' assigned as homeroom teacher.']);
}

if (post('action') === 'delete_homeroom') {
    school_require_manage();
    $id = (int) post('id', '0');
    db_execute('DELETE FROM school_homerooms WHERE id = ?', [$id], 'i');
    audit_log('delete', 'School Homeroom', $id, 'Removed homeroom assignment');
    json_response(['ok' => true, 'message' => 'Homeroom assignment removed.']);
}

// ---------------------------------------------------------------------
// POST: subject allocation — teacher + subject + checked grade/sections
// ---------------------------------------------------------------------
if (post('action') === 'save_allocations') {
    school_require_manage();
    $teacherId = (int) post('teacher_id', '0');
    $subjectId = (int) post('subject_id', '0');
    $classes = array_map('strval', (array) ($_POST['class'] ?? []));
    if (!$teacherId || !$subjectId) json_response(['ok' => false, 'message' => 'Pick a teacher and a subject.']);
    if (!$classes) json_response(['ok' => false, 'message' => 'Tick at least one grade + section checkbox.']);
    $teacher = db_fetch_one('SELECT full_name FROM school_teachers WHERE id = ? AND status = 1', [$teacherId], 'i');
    $subject = db_fetch_one('SELECT name FROM subjects WHERE id = ? AND status = 1', [$subjectId], 'i');
    if (!$teacher || !$subject) json_response(['ok' => false, 'message' => 'Teacher or subject not found.']);
    $added = 0;
    foreach ($classes as $pair) {
        if (!preg_match('/^(\d+):(\d+)$/', (string) $pair, $m)) continue;
        $gradeId = (int) $m[1]; $sectionId = (int) $m[2];
        $exists = (int) db_fetch_value('SELECT COUNT(*) FROM school_allocations WHERE grade_id = ? AND section_id = ? AND subject_id = ?', [$gradeId, $sectionId, $subjectId], 'iii');
        if ($exists > 0) continue;
        db_execute('INSERT INTO school_allocations (teacher_id, grade_id, section_id, subject_id) VALUES (?,?,?,?)', [$teacherId, $gradeId, $sectionId, $subjectId], 'iiii');
        $added++;
    }
    audit_log('create', 'School Allocation', 0, $teacher['full_name'] . ' allocated to ' . $subject['name'] . ' in ' . $added . ' class(es)');
    json_response(['ok' => true, 'message' => $teacher['full_name'] . ' allocated to ' . $subject['name'] . ' in ' . $added . ' class(es)' . ($added < count($classes) ? ' (' . (count($classes) - $added) . ' already taken)' : '') . '.']);
}

if (post('action') === 'delete_allocation') {
    school_require_manage();
    $id = (int) post('id', '0');
    db_execute('DELETE FROM school_allocations WHERE id = ?', [$id], 'i');
    audit_log('delete', 'School Allocation', $id, 'Removed subject allocation');
    json_response(['ok' => true, 'message' => 'Allocation removed.']);
}

// ---------------------------------------------------------------------
// POST: mark categories (dynamic; running total stays ≤ 100 and must
// reach exactly 100 before marks/exams are accepted)
// ---------------------------------------------------------------------
if (post('action') === 'save_category') {
    school_require_manage();
    $subjectId = (int) post('subject_id', '0');
    $name = trim((string) post('name'));
    $maxMark = (float) post('max_mark', '0');
    if (!$subjectId || $name === '') json_response(['ok' => false, 'message' => 'Pick a subject and enter a category name.']);
    if ($maxMark <= 0) json_response(['ok' => false, 'message' => 'Maximum mark must be greater than zero.']);
    $subject = db_fetch_one('SELECT name FROM subjects WHERE id = ?', [$subjectId], 'i');
    if (!$subject) json_response(['ok' => false, 'message' => 'Subject not found.'], 404);
    $existing = (float) db_fetch_value('SELECT COALESCE(SUM(max_mark),0) FROM school_mark_categories WHERE subject_id = ?', [$subjectId], 'i');
    $newTotal = $existing + $maxMark;
    if ($newTotal > 100.001) {
        json_response(['ok' => false, 'message' => 'Rejected: categories would total ' . money_raw($newTotal) . '/100 for ' . $subject['name'] . '. The sum must be exactly 100.']);
    }
    $sort = (int) db_fetch_value('SELECT COALESCE(MAX(sort_order),0)+1 FROM school_mark_categories WHERE subject_id = ?', [$subjectId], 'i');
    db_execute('INSERT INTO school_mark_categories (subject_id, name, max_mark, sort_order, created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE max_mark = VALUES(max_mark), sort_order = VALUES(sort_order)',
        [$subjectId, $name, $maxMark, $sort, (int) current_user()['id']], 'isdis');
    audit_log('create', 'School Mark Category', 0, $subject['name'] . ' category: ' . $name . ' (' . money_raw($maxMark) . ') — total now ' . money_raw($newTotal) . '/100');
    json_response(['ok' => true, 'message' => 'Category saved. ' . $subject['name'] . ' categories now total ' . money_raw($newTotal) . '/100.' . ($newTotal < 99.999 ? ' Add ' . money_raw(100 - $newTotal) . ' more.' : '')]);
}

if (post('action') === 'delete_category') {
    school_require_manage();
    $id = (int) post('id', '0');
    db_execute('DELETE FROM school_mark_categories WHERE id = ?', [$id], 'i');
    audit_log('delete', 'School Mark Category', $id, 'Removed mark category');
    json_response(['ok' => true, 'message' => 'Category removed.']);
}

// ---------------------------------------------------------------------
// POST: save marks — only for the teacher's own subject allocation.
// Requires the subject's mark categories to total exactly 100.
// Body: marks[<student_id>][<category_id>] = score
// ---------------------------------------------------------------------
if (post('action') === 'save_marks') {
    if (!has_permission('school.teach')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $alloc = school_owned_allocation((int) post('alloc', '0'));
    school_assert_categories_complete((int) $alloc['subject_id'], $alloc['subject_name']);
    $marks = (array) ($_POST['marks'] ?? []);
    if (!$marks) json_response(['ok' => false, 'message' => 'Nothing to save.']);
    $categories = [];
    foreach (db_fetch_all('SELECT id, name, max_mark FROM school_mark_categories WHERE subject_id = ?', [$alloc['subject_id']], 'i') as $c) {
        $categories[(int) $c['id']] = $c;
    }
    $saved = 0;
    try {
        db_transaction(function () use ($marks, $categories, $alloc, &$saved) {
            foreach ($marks as $studentId => $byCat) {
                $studentId = (int) $studentId;
                $inClass = (int) db_fetch_value('SELECT COUNT(*) FROM school_students WHERE id = ? AND grade_id = ? AND section_id = ? AND status = 1', [$studentId, $alloc['grade_id'], $alloc['section_id']], 'iii');
                if (!$inClass) throw new RuntimeException('Student #' . $studentId . ' is not in ' . $alloc['grade_name'] . ' - ' . $alloc['section_name'] . '.');
                foreach ((array) $byCat as $catId => $score) {
                    $catId = (int) $catId;
                    if (!isset($categories[$catId])) continue;
                    $score = trim((string) $score);
                    if ($score === '') continue;
                    if (!is_numeric($score)) throw new RuntimeException('Invalid score for ' . $categories[$catId]['name'] . '.');
                    if ((float) $score < 0 || (float) $score > (float) $categories[$catId]['max_mark']) {
                        throw new RuntimeException($categories[$catId]['name'] . ' score must be between 0 and ' . money_raw($categories[$catId]['max_mark']) . '.');
                    }
                    db_execute('INSERT INTO school_marks (student_id, subject_id, category_id, score, entered_by) VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE score = VALUES(score), entered_by = VALUES(entered_by)',
                        [$studentId, $alloc['subject_id'], $catId, $score, (int) current_user()['id']], 'iiids');
                    $saved++;
                }
            }
        });
        audit_log('update', 'School Marks', (int) $alloc['id'], 'Saved ' . $saved . ' mark(s) for ' . $alloc['subject_name'] . ' (' . $alloc['grade_name'] . ' - ' . $alloc['section_name'] . ')');
        json_response(['ok' => true, 'message' => 'Saved ' . $saved . ' mark(s) for ' . $alloc['subject_name'] . '.']);
    } catch (RuntimeException $ex) {
        json_response(['ok' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong.']);
    }
}

// ---------------------------------------------------------------------
// POST: upload online exam (.docx) — only for the teacher's own
// subject allocation. Requires mark categories to total exactly 100.
// ---------------------------------------------------------------------
if (post('action') === 'upload_exam') {
    if (!has_permission('school.teach')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $alloc = school_owned_allocation((int) post('alloc', '0'));
    school_assert_categories_complete((int) $alloc['subject_id'], $alloc['subject_name']);
    $title = trim((string) post('title'));
    if ($title === '') json_response(['ok' => false, 'message' => 'Enter an exam title.']);
    if (empty($_FILES['exam_file']['name'])) json_response(['ok' => false, 'message' => 'Choose a .docx file to upload.']);
    $file = $_FILES['exam_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_response(['ok' => false, 'message' => 'Upload failed (code ' . (int) $file['error'] . ').']);
    if ($file['size'] > 10485760) json_response(['ok' => false, 'message' => 'File is too large (max 10 MB).']);
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'docx') json_response(['ok' => false, 'message' => 'Only .docx exam files are allowed (Word: File > Save As > .docx).']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $okMimes = ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'];
    if (!in_array($mime, $okMimes, true)) json_response(['ok' => false, 'message' => 'That file is not a valid .docx document.']);

    try {
        $dir = school_exam_dir();
        $stored = bin2hex(random_bytes(16)) . '.docx';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            json_response(['ok' => false, 'message' => 'Could not store the exam file.']);
        }
        $t = school_current_teacher();
        $examId = db_execute('INSERT INTO school_exams (teacher_id, grade_id, section_id, subject_id, title, file_path, file_name, file_text, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)',
            [$alloc['teacher_id'], $alloc['grade_id'], $alloc['section_id'], $alloc['subject_id'], $title, 'exams/' . $stored, (string) $file['name'], school_docx_text($dir . '/' . $stored), (int) current_user()['id']],
            'iiiissssi');
        audit_log('create', 'School Exam', $examId, 'Uploaded exam "' . $title . '" (' . $alloc['subject_name'] . ', ' . $alloc['grade_name'] . ' - ' . $alloc['section_name'] . ')');
        json_response(['ok' => true, 'message' => 'Exam "' . $title . '" uploaded for ' . $alloc['subject_name'] . ' (' . $alloc['grade_name'] . ' - ' . $alloc['section_name'] . ').']);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong.']);
    }
}

if (post('action') === 'delete_exam') {
    $id = (int) post('id', '0');
    $exam = db_fetch_one('SELECT * FROM school_exams WHERE id = ?', [$id], 'i');
    if (!$exam) json_response(['ok' => false, 'message' => 'Exam not found.'], 404);
    if (!has_permission('school.manage')) {
        $t = school_current_teacher();
        if (!$t || (int) $exam['teacher_id'] !== (int) $t['id']) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    @unlink(school_exam_dir() . '/' . basename((string) $exam['file_path']));
    db_execute('DELETE FROM school_exams WHERE id = ?', [$id], 'i');
    audit_log('delete', 'School Exam', $id, 'Deleted exam ' . $exam['title']);
    json_response(['ok' => true, 'message' => 'Exam deleted.']);
}

// ---------------------------------------------------------------------
// POST: save attendance — homeroom teacher of the class (or admin)
// Body: status[<student_id>] = present|absent|late|excused
// ---------------------------------------------------------------------
if (post('action') === 'save_attendance') {
    if (!has_permission('school.teach')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $gradeId = (int) post('grade_id', '0');
    $sectionId = (int) post('section_id', '0');
    $date = post('date');
    if (!valid_date($date)) json_response(['ok' => false, 'message' => 'Invalid date.']);
    if (!school_can_touch_class($gradeId, $sectionId)) json_response(['ok' => false, 'message' => 'You are not the homeroom teacher of this class.'], 403);
    $statuses = (array) ($_POST['status'] ?? []);
    if (!$statuses) json_response(['ok' => false, 'message' => 'Mark at least one student.']);
    $allowed = ['present', 'absent', 'late', 'excused'];
    $saved = 0;
    try {
        db_transaction(function () use ($statuses, $gradeId, $sectionId, $date, $allowed, &$saved) {
            foreach ($statuses as $studentId => $status) {
                $studentId = (int) $studentId;
                $status = (string) $status;
                if (!in_array($status, $allowed, true)) continue;
                $inClass = (int) db_fetch_value('SELECT COUNT(*) FROM school_students WHERE id = ? AND grade_id = ? AND section_id = ? AND status = 1', [$studentId, $gradeId, $sectionId], 'iii');
                if (!$inClass) continue;
                db_execute('INSERT INTO school_attendance (student_id, grade_id, section_id, att_date, status, taken_by) VALUES (?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE status = VALUES(status), taken_by = VALUES(taken_by)',
                    [$studentId, $gradeId, $sectionId, $date, $status, (int) current_user()['id']], 'iiisss');
                $saved++;
            }
        });
        audit_log('update', 'School Attendance', 0, 'Attendance for ' . $saved . ' student(s) on ' . $date . ' (grade ' . $gradeId . ', section ' . $sectionId . ')');
        json_response(['ok' => true, 'message' => 'Attendance saved for ' . $saved . ' student(s) on ' . fmt_date($date) . '.']);
    } catch (Throwable $ex) {
        json_response(['ok' => false, 'message' => APP_DEBUG ? $ex->getMessage() : 'Something went wrong.']);
    }
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
