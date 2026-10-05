<?php
/**
 * /school/exams — online exams uploaded as .docx per subject allocation.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.teach');
$teacher = school_current_teacher();
if (!$teacher) {
    ui_page_open(['title' => 'School — Online Exams', 'icon' => 'fa-file-word', 'breadcrumb' => ['School' => '/school', 'Exams' => null]]);
    echo '<div class="alert alert-warning">No teacher profile is linked to your account.</div>';
    ui_page_close();
    exit;
}
$isAdmin = has_permission('school.manage');
$allocs = school_teacher_allocations($teacher['id']);

$where = '';
$params = [];
if (!$isAdmin) {
    $where = ' WHERE e.teacher_id = ?';
    $params = [$teacher['id']];
}
$exams = db_fetch_all(
    "SELECT e.*, t.full_name teacher_name, g.name grade_name, sec.name section_name, sub.name subject_name
     FROM school_exams e
     JOIN school_teachers t ON t.id = e.teacher_id
     JOIN grades g ON g.id = e.grade_id
     JOIN sections sec ON sec.id = e.section_id
     JOIN subjects sub ON sub.id = e.subject_id
     $where ORDER BY e.id DESC LIMIT 100", $params);

ui_page_open(['title' => 'School — Online Exams', 'icon' => 'fa-file-word', 'breadcrumb' => ['School' => '/school', 'Exams' => null]]);
require __DIR__ . '/_js.php';

echo '<div class="alert alert-light border small mb-3">Upload exams as <strong>.docx</strong> for your allocated classes only. The subject\'s mark categories must total <strong>exactly 100</strong> first (exam validation).</div>';

echo '<div class="card mb-4"><div class="card-header py-2 fw-semibold"><i class="fa-solid fa-upload me-2"></i>Upload Exam (.docx)</div><div class="card-body">';
echo '<form id="examForm" class="row g-3">';
echo '<div class="col-md-4"><label class="form-label required">Class & subject (your allocations)</label><select class="form-select" name="alloc" required><option value="">— Select allocation —</option>';
foreach ($allocs as $a) {
    $t = school_categories_total((int) $a['subject_id']);
    echo '<option value="' . (int) $a['id'] . '">' . e($a['subject_name'] . ' — ' . $a['grade_name'] . ' - ' . $a['section_name']) . ' (' . money_raw($t) . '/100)</option>';
}
echo '</select></div>';
echo '<div class="col-md-4"><label class="form-label required">Exam title</label><input class="form-control" name="title" placeholder="e.g. First Semester Mid Exam" required></div>';
echo '<div class="col-md-4"><label class="form-label required">Exam file (.docx)</label><input type="file" class="form-control" name="exam_file" accept=".docx" required></div>';
echo '<div class="col-12"><button type="submit" class="btn btn-brand"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Upload Exam</button></div>';
echo '</form></div></div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Title</th><th>Subject</th><th>Class</th>' . ($isAdmin ? '<th>Teacher</th>' : '') . '<th>Uploaded</th><th>Preview</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$exams) echo '<tr><td colspan="7" class="text-center text-muted py-4">No exams uploaded yet</td></tr>';
foreach ($exams as $ex) {
    echo '<tr><td class="fw-semibold">' . e($ex['title']) . '</td><td class="small">' . e($ex['subject_name']) . '</td><td class="small">' . e($ex['grade_name'] . ' - ' . $ex['section_name']) . '</td>';
    if ($isAdmin) echo '<td class="small">' . e($ex['teacher_name']) . '</td>';
    echo '<td class="small">' . fmt_date($ex['created_at'], true) . '</td>';
    echo '<td class="small text-muted" style="max-width:260px">' . e(mb_strimwidth((string) ($ex['file_text'] ?? '— text extraction unavailable on this server —'), 0, 90, '…')) . '</td>';
    echo '<td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="/ajax/school?download_exam=' . (int) $ex['id'] . '"><i class="fa-solid fa-download me-1"></i>Download</a> ';
    echo '<button class="btn btn-sm btn-light border" data-school-action="delete_exam" data-id="' . (int) $ex['id'] . '"><i class="fa-solid fa-trash"></i></button></td></tr>';
}
echo '</tbody></table></div></div>';
?>
<script>
document.getElementById('examForm')?.addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'upload_exam');
  fd.append('csrf_token', App.csrf);
  const res = await fetch('/ajax/school', { method: 'POST', headers: { 'X-CSRF-Token': App.csrf }, body: fd });
  const data = await res.json();
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 700); }
  else App.toast(data.message || 'Upload failed.', 'danger');
});
</script>
<?php
ui_page_close();
