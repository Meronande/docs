<?php
/**
 * /school/allocations — allocate a teacher to a subject in one or more
 * grade + section combinations (tick the checkboxes).
 */
require_once __DIR__ . '/_init.php';
require_permission('school.manage');

$allocRows = db_fetch_all(
    'SELECT a.*, t.full_name teacher_name, g.name grade_name, sec.name section_name, sub.name subject_name, g.sort_order grade_sort
     FROM school_allocations a
     JOIN school_teachers t ON t.id = a.teacher_id
     JOIN grades g ON g.id = a.grade_id
     JOIN sections sec ON sec.id = a.section_id
     JOIN subjects sub ON sub.id = a.subject_id
     ORDER BY g.sort_order, g.name, sec.name, sub.name');

ui_page_open(['title' => 'School — Subject Allocations', 'icon' => 'fa-book-open', 'breadcrumb' => ['School' => '/school', 'Allocations' => null]]);

echo '<div class="card mb-4"><div class="card-body"><form id="allocForm" class="row g-3">';
echo '<div class="col-md-3"><label class="form-label required">Teacher</label><select class="form-select" name="teacher_id" required><option value="">— Select teacher —</option>';
foreach ($teachers as $t) echo '<option value="' . (int) $t['id'] . '">' . e($t['full_name']) . '</option>';
echo '</select></div>';
echo '<div class="col-md-3"><label class="form-label required">Subject</label><select class="form-select" name="subject_id" required><option value="">— Select subject —</option>';
foreach ($subjects as $s) echo '<option value="' . (int) $s['id'] . '">' . e($s['name']) . '</option>';
echo '</select></div>';
echo '<div class="col-md-6"><label class="form-label required">Classes (grade + section)</label><div class="border rounded p-2" style="max-height:190px;overflow:auto">';
foreach ($grades as $g) {
    echo '<div class="small fw-semibold mt-1">' . e($g['name']) . '</div><div class="d-flex flex-wrap gap-2">';
    foreach ($sections as $s) {
        if ((int) $s['grade_id'] !== (int) $g['id']) continue;
        echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="class[]" id="cls_' . (int) $s['id'] . '" value="' . (int) $g['id'] . ':' . (int) $s['id'] . '">';
        echo '<label class="form-check-label small" for="cls_' . (int) $s['id'] . '">' . e($s['name']) . '</label></div>';
    }
    echo '</div>';
}
echo '</div></div>';
echo '<div class="col-12"><button type="submit" class="btn btn-brand"><i class="fa-solid fa-book-open me-1"></i>Assign Subject</button> <span class="small text-muted">A class+subject already assigned to another teacher is skipped.</span></div>';
echo '</form></div></div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Teacher</th><th>Subject</th><th>Grade</th><th>Section</th><th>Assigned</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$allocRows) echo '<tr><td colspan="6" class="text-center text-muted py-4">No subject allocations yet</td></tr>';
foreach ($allocRows as $r) {
    echo '<tr><td class="fw-semibold">' . e($r['teacher_name']) . '</td><td>' . e($r['subject_name']) . '</td>';
    echo '<td>' . e($r['grade_name']) . '</td><td>' . e($r['section_name']) . '</td>';
    echo '<td class="small">' . fmt_date($r['created_at'], true) . '</td>';
    echo '<td class="text-end"><button class="btn btn-sm btn-light border" data-school-action="delete_allocation" data-id="' . (int) $r['id'] . '"><i class="fa-solid fa-trash"></i></button></td></tr>';
}
echo '</tbody></table></div></div>';
require __DIR__ . '/_js.php';
?>
<script>
document.getElementById('allocForm')?.addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  const classes = [...this.querySelectorAll('[name="class[]"]:checked')].map(c => c.value);
  if (!classes.length) { App.toast('Tick at least one grade + section checkbox.', 'danger'); return; }
  const data = await postSchool({ action: 'save_allocations', teacher_id: fd.get('teacher_id'), subject_id: fd.get('subject_id'), 'class': classes });
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 700); }
  else App.toast(data.message || 'Failed.', 'danger');
});
</script>
<?php
ui_page_close();
