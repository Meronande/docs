<?php
/**
 * /school/categories — school admin creates mark categories per subject.
 * The weights must total exactly 100; marks & exams validate this.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.manage');

$selSubject = (int) get('subject_id', '0');
$subjectList = db_fetch_all('SELECT sub.*, (SELECT COALESCE(SUM(max_mark),0) FROM school_mark_categories c WHERE c.subject_id = sub.id) total FROM subjects sub WHERE sub.status = 1 ORDER BY sub.name');
if (!$selSubject && $subjectList) $selSubject = (int) $subjectList[0]['id'];
$cats = $selSubject ? db_fetch_all('SELECT * FROM school_mark_categories WHERE subject_id = ? ORDER BY sort_order, id', [$selSubject], 'i') : [];
$total = $selSubject ? school_categories_total($selSubject) : 0.0;
$complete = abs($total - 100.0) < 0.001;

ui_page_open(['title' => 'School — Mark Categories', 'icon' => 'fa-tags', 'breadcrumb' => ['School' => '/school', 'Mark Categories' => null]]);
require __DIR__ . '/_js.php';

echo '<div class="card mb-4"><div class="card-body">';
echo '<form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="school_categories">';
echo '<div class="col-md-5"><label class="form-label">Subject</label><select class="form-select" name="subject_id" onchange="this.form.submit()">';
foreach ($subjectList as $s) {
    $t = (float) $s['total'];
    echo '<option value="' . (int) $s['id'] . '" ' . ($selSubject === (int) $s['id'] ? 'selected' : '') . '>' . e($s['name']) . ' (' . money_raw($t) . '/100' . ($t > 99.999 ? ' ✓' : '') . ')</option>';
}
echo '</select></div></form>';
echo '</div></div>';

if ($selSubject):
$subjectName = '';
foreach ($subjectList as $s) if ((int) $s['id'] === $selSubject) $subjectName = $s['name'];
echo '<div class="row g-4">';
echo '<div class="col-lg-7"><div class="card"><div class="card-header py-2 fw-semibold">Categories for ' . e($subjectName) . '</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">';
echo '<thead><tr><th>Category</th><th>Max Mark</th><th>Share</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$cats) echo '<tr><td colspan="4" class="text-center text-muted py-4">No categories yet — add them until the total is exactly 100</td></tr>';
foreach ($cats as $c) {
    echo '<tr><td class="fw-semibold">' . e($c['name']) . '</td><td>' . money_raw($c['max_mark']) . '</td><td class="small">' . money_raw($c['max_mark']) . '%</td>';
    echo '<td class="text-end"><button class="btn btn-sm btn-light border" data-school-action="delete_category" data-id="' . (int) $c['id'] . '"><i class="fa-solid fa-trash"></i></button></td></tr>';
}
echo '</tbody><tfoot class="table-light fw-bold"><tr><td>Total</td><td>' . money_raw($total) . '/100</td><td colspan="2">';
echo $complete ? '<span class="badge text-bg-success">Ready — marks & exams allowed</span>' : '<span class="badge text-bg-danger">Not ready — must total exactly 100</span>';
echo '</td></tr></tfoot></table></div></div></div>';

echo '<div class="col-lg-5"><div class="card"><div class="card-header py-2 fw-semibold">Add Category</div><div class="card-body">';
echo '<form id="categoryForm" class="row g-3">';
echo '<input type="hidden" name="subject_id" value="' . $selSubject . '">';
echo '<div class="col-7"><label class="form-label required">Name</label><input class="form-control" name="name" placeholder="Test 1 / Assignment / Mid Exam / Final" required></div>';
echo '<div class="col-5"><label class="form-label required">Max Mark (weight)</label><input type="number" step="0.5" min="0.5" max="100" class="form-control" name="max_mark" required></div>';
echo '<div class="col-12"><button type="submit" class="btn btn-brand"><i class="fa-solid fa-plus me-1"></i>Add Category</button>';
echo '<div class="form-text mt-2">Running total: <strong id="catTotal">' . money_raw($total) . '</strong>/100. Adding more than 100 is rejected; teachers cannot enter marks or upload exams until the total is exactly 100.</div></div>';
echo '</form></div></div></div>';
echo '</div>';
endif;
?>
<script>
document.getElementById('categoryForm')?.addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const fd = new FormData(this);
  const data = { action: 'save_category' };
  for (const [k, v] of fd.entries()) data[k] = v;
  const res = await postSchool(data);
  if (res.ok) { App.toast(res.message, 'success'); setTimeout(() => location.reload(), 500); }
  else App.toast(res.message || 'Failed.', 'danger');
});
</script>
<?php
ui_page_close();
