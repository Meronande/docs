<?php
/**
 * /school/setup — academic setup: grades, sections, subjects.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.manage');

$gradeRows = db_fetch_all('SELECT g.*, (SELECT COUNT(*) FROM school_students s WHERE s.grade_id = g.id AND s.status = 1) students FROM grades g ORDER BY g.status DESC, g.sort_order, g.name');
$subjectRows = db_fetch_all('SELECT sub.*, (SELECT COUNT(*) FROM school_allocations a WHERE a.subject_id = sub.id) allocations FROM subjects sub ORDER BY sub.status DESC, sub.name');

ui_page_open(['title' => 'School — Academic Setup', 'icon' => 'fa-sliders', 'breadcrumb' => ['School' => '/school', 'Academic Setup' => null]]);
require __DIR__ . '/_js.php';

echo '<div class="row g-4">';
echo '<div class="col-lg-4"><div class="card h-100"><div class="card-header py-2 fw-semibold"><i class="fa-solid fa-layer-group me-2 text-brand"></i>Grades</div><div class="card-body">';
echo '<form id="gradeForm" class="d-flex gap-2 mb-3"><input class="form-control form-control-sm" name="name" placeholder="e.g. Grade 13" required><button class="btn btn-sm btn-brand">Add</button></form>';
echo '<table class="table table-sm mb-0"><thead><tr><th>Grade</th><th>Students</th><th></th></tr></thead><tbody>';
foreach ($gradeRows as $g) {
    echo '<tr' . ((int) $g['status'] === 0 ? ' class="text-muted"' : '') . '><td class="small">' . e($g['name']) . '</td><td class="small">' . (int) $g['students'] . '</td>';
    echo '<td class="text-end">' . ((int) $g['status'] === 1 ? '<button class="btn btn-sm btn-light border" data-school-action="delete_grade" data-id="' . (int) $g['id'] . '" title="Deactivate"><i class="fa-solid fa-ban"></i></button>' : '') . '</td></tr>';
}
echo '</tbody></table></div></div></div>';

echo '<div class="col-lg-4"><div class="card h-100"><div class="card-header py-2 fw-semibold"><i class="fa-solid fa-people-group me-2 text-brand"></i>Sections</div><div class="card-body">';
echo '<form id="sectionForm" class="d-flex gap-2 mb-3"><select class="form-select form-select-sm" name="grade_id" required><option value="">Grade…</option>';
foreach ($grades as $g) echo '<option value="' . (int) $g['id'] . '">' . e($g['name']) . '</option>';
echo '</select><input class="form-control form-control-sm" name="name" placeholder="e.g. D" maxlength="5" required style="width:90px"><button class="btn btn-sm btn-brand">Add</button></form>';
echo '<table class="table table-sm mb-0"><thead><tr><th>Class</th><th></th></tr></thead><tbody>';
foreach ($sections as $s) {
    echo '<tr><td class="small">' . e($s['grade_name'] . ' - ' . $s['name']) . '</td>';
    echo '<td class="text-end"><button class="btn btn-sm btn-light border" data-school-action="delete_section" data-id="' . (int) $s['id'] . '" title="Deactivate"><i class="fa-solid fa-ban"></i></button></td></tr>';
}
echo '</tbody></table></div></div></div>';

echo '<div class="col-lg-4"><div class="card h-100"><div class="card-header py-2 fw-semibold"><i class="fa-solid fa-book me-2 text-brand"></i>Subjects</div><div class="card-body">';
echo '<form id="subjectForm" class="d-flex gap-2 mb-3"><input class="form-control form-control-sm" name="code" placeholder="Code" maxlength="10" style="width:90px"><input class="form-control form-control-sm" name="name" placeholder="e.g. Technical Drawing" required><button class="btn btn-sm btn-brand">Add</button></form>';
echo '<table class="table table-sm mb-0"><thead><tr><th>Subject</th><th>Allocations</th><th></th></tr></thead><tbody>';
foreach ($subjectRows as $s) {
    echo '<tr' . ((int) $s['status'] === 0 ? ' class="text-muted"' : '') . '><td class="small">' . e($s['name']) . ($s['code'] ? ' <span class="text-muted">(' . e($s['code']) . ')</span>' : '') . '</td><td class="small">' . (int) $s['allocations'] . '</td>';
    echo '<td class="text-end">' . ((int) $s['status'] === 1 ? '<button class="btn btn-sm btn-light border" data-school-action="delete_subject" data-id="' . (int) $s['id'] . '" title="Deactivate"><i class="fa-solid fa-ban"></i></button>' : '') . '</td></tr>';
}
echo '</tbody></table></div></div></div>';
echo '</div>';
?>
<script>
const forms = { gradeForm: 'save_grade', sectionForm: 'save_section', subjectForm: 'save_subject' };
Object.keys(forms).forEach(function (id) {
  document.getElementById(id)?.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const fd = new FormData(this);
    const data = { action: forms[id] };
    for (const [k, v] of fd.entries()) data[k] = v;
    const res = await postSchool(data);
    if (res.ok) { App.toast(res.message, 'success'); setTimeout(() => location.reload(), 500); }
    else App.toast(res.message || 'Failed.', 'danger');
  });
});
</script>
<?php
ui_page_close();
