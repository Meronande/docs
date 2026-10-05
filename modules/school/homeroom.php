<?php
/**
 * /school/homeroom — assign a homeroom teacher per grade + section.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.manage');

$rows = db_fetch_all(
    'SELECT h.*, t.full_name teacher_name, g.name grade_name, sec.name section_name, g.sort_order grade_sort
     FROM school_homerooms h
     JOIN school_teachers t ON t.id = h.teacher_id
     JOIN grades g ON g.id = h.grade_id
     JOIN sections sec ON sec.id = h.section_id
     ORDER BY g.sort_order, g.name, sec.name');

ui_page_open(['title' => 'School — Homeroom Teachers', 'icon' => 'fa-door-open', 'breadcrumb' => ['School' => '/school', 'Homeroom' => null]]);
echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">';
echo '<h6 class="mb-0 text-muted">One homeroom teacher per grade + section — they take daily attendance for that class.</h6>';
echo '</div>';

echo '<div class="card mb-4"><div class="card-body"><form id="homeroomForm" class="row g-3 align-items-end">';
echo '<div class="col-md-4"><label class="form-label required">Teacher</label><select class="form-select" name="teacher_id" required><option value="">— Select teacher —</option>';
foreach ($teachers as $t) echo '<option value="' . (int) $t['id'] . '">' . e($t['full_name']) . '</option>';
echo '</select></div>';
echo '<div class="col-md-4"><label class="form-label required">Grade</label><select class="form-select" id="hrGrade" required><option value="">— Select grade —</option>';
foreach ($grades as $g) echo '<option value="' . (int) $g['id'] . '">' . e($g['name']) . '</option>';
echo '</select></div>';
echo '<div class="col-md-2"><label class="form-label required">Section</label><select class="form-select" id="hrSection" required disabled><option value="">—</option></select></div>';
echo '<div class="col-md-2"><button type="submit" class="btn btn-brand w-100"><i class="fa-solid fa-door-open me-1"></i>Assign</button></div>';
echo '</form></div></div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Grade</th><th>Section</th><th>Homeroom Teacher</th><th>Students</th><th>Assigned</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="6" class="text-center text-muted py-4">No homeroom assignments yet</td></tr>';
foreach ($rows as $r) {
    echo '<tr><td class="fw-semibold">' . e($r['grade_name']) . '</td><td>' . e($r['section_name']) . '</td>';
    echo '<td>' . e($r['teacher_name']) . '</td>';
    echo '<td class="small">' . (int) (db_fetch_value('SELECT COUNT(*) FROM school_students WHERE grade_id = ? AND section_id = ? AND status = 1', [$r['grade_id'], $r['section_id']], 'ii')) . '</td>';
    echo '<td class="small">' . fmt_date($r['created_at'], true) . '</td>';
    echo '<td class="text-end"><button class="btn btn-sm btn-light border" data-school-action="delete_homeroom" data-id="' . (int) $r['id'] . '"><i class="fa-solid fa-trash"></i></button></td></tr>';
}
echo '</tbody></table></div></div>';
require __DIR__ . '/_js.php';
?>
<script>
const SECTION_GROUPS = <?php echo json_encode($sectionGroups); ?>;
document.getElementById('hrGrade')?.addEventListener('change', function () {
  const sel = document.getElementById('hrSection');
  sel.innerHTML = '<option value="">—</option>';
  (SECTION_GROUPS[this.value] || []).forEach(function (s) {
    const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o);
  });
  sel.disabled = !this.value;
});
document.getElementById('homeroomForm')?.addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  const data = await postSchool({ action: 'save_homeroom', teacher_id: fd.get('teacher_id'), grade_id: document.getElementById('hrGrade').value, section_id: document.getElementById('hrSection').value });
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 500); }
  else App.toast(data.message || 'Failed.', 'danger');
});
</script>
<?php
ui_page_close();
