<?php
/**
 * /school/marks — teachers add marks for their allocated subjects only.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.teach');
$teacher = school_current_teacher();
if (!$teacher) {
    ui_page_open(['title' => 'School — Marks', 'icon' => 'fa-pen-to-square', 'breadcrumb' => ['School' => '/school', 'Marks' => null]]);
    echo '<div class="alert alert-warning">No teacher profile is linked to your account. Ask the school admin to create your teacher record.</div>';
    ui_page_close();
    exit;
}

$allocs = school_teacher_allocations($teacher['id']);
$sel = (int) get('alloc', '0');
ui_page_open(['title' => 'School — Add Marks', 'icon' => 'fa-pen-to-square', 'breadcrumb' => ['School' => '/school', 'Marks' => null]]);
require __DIR__ . '/_js.php';

echo '<div class="alert alert-light border small mb-3">You can only enter marks for classes allocated to you. Mark categories per subject must total <strong>exactly 100</strong> (set by the school admin) before marks can be saved.</div>';

echo '<div class="card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="school_marks">';
echo '<div class="col-md-7"><label class="form-label">My subject allocations</label><select class="form-select" name="alloc" onchange="this.form.submit()"><option value="">— Select allocation —</option>';
foreach ($allocs as $a) {
    $t = school_categories_total((int) $a['subject_id']);
    echo '<option value="' . (int) $a['id'] . '" ' . ($sel === (int) $a['id'] ? 'selected' : '') . '>' . e($a['subject_name'] . ' — ' . $a['grade_name'] . ' - ' . $a['section_name']) . ' (' . money_raw($t) . '/100)</option>';
}
echo '</select></div></form></div></div>';

if ($sel) {
    $owned = false;
    foreach ($allocs as $a) if ((int) $a['id'] === $sel) { $owned = true; $alloc = $a; }
    if (!$owned) {
        echo '<div class="alert alert-danger">This allocation is not yours.</div>';
    } else {
        $total = school_categories_total((int) $alloc['subject_id']);
        if (abs($total - 100.0) > 0.001) {
            echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Mark categories for <strong>' . e($alloc['subject_name']) . '</strong> total <strong>' . money_raw($total) . '/100</strong>. The school admin must complete them to exactly 100 before marks can be entered.</div>';
        } else {
            echo '<div id="marksHost" class="card"><div class="card-body text-center py-4"><span class="spinner-border text-secondary"></span></div></div>';
        }
    }
}
?>
<script>
document.addEventListener('DOMContentLoaded', async function () {
  const host = document.getElementById('marksHost');
  if (!host) return;
  const allocId = <?php echo (int) $sel; ?>;
  const res = await fetch('/ajax/school?roster=' + allocId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const data = await res.json();
  if (!data.ok) { host.innerHTML = '<div class="alert alert-danger m-3">' + App.escapeHtml(data.message || 'Could not load roster.') + '</div>'; return; }
  let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Student</th>';
  data.categories.forEach(c => { html += '<th class="text-center" style="min-width:90px">' + App.escapeHtml(c.name) + '<div class="small text-muted">/' + parseFloat(c.max_mark) + '</div></th>'; });
  html += '<th class="text-center">Total</th></tr></thead><tbody>';
  if (!data.students.length) html += '<tr><td colspan="' + (data.categories.length + 2) + '" class="text-center text-muted py-4">No students in this class yet</td></tr>';
  data.students.forEach(s => {
    html += '<tr><td class="small fw-semibold">' + App.escapeHtml(s.full_name) + ' <span class="text-muted">' + App.escapeHtml(s.student_code || '') + '</span></td>';
    data.categories.forEach(c => {
      const v = data.marks[s.id + '_' + c.id] || '';
      html += '<td class="text-center"><input type="number" step="0.5" min="0" max="' + parseFloat(c.max_mark) + '" class="form-control form-control-sm mark-input" data-student="' + s.id + '" data-cat="' + c.id + '" data-max="' + parseFloat(c.max_mark) + '" value="' + v + '"></td>';
    });
    html += '<td class="text-center fw-bold row-total">0.0</td></tr>';
  });
  html += '</tbody></table></div><div class="d-flex justify-content-between align-items-center p-3 border-top"><button class="btn btn-brand" id="saveMarks"><i class="fa-solid fa-floppy-disk me-1"></i>Save Marks</button><span class="small text-muted">Scores are limited to each category maximum.</span></div>';
  host.innerHTML = html;

  const recalc = () => host.querySelectorAll('tr').forEach(tr => {
    let sum = 0;
    tr.querySelectorAll('.mark-input').forEach(i => { sum += parseFloat(i.value) || 0; });
    const out = tr.querySelector('.row-total');
    if (out) out.textContent = sum.toFixed(1);
  });
  host.addEventListener('input', recalc);
  recalc();

  document.getElementById('saveMarks')?.addEventListener('click', async function () {
    const marks = {};
    let bad = null;
    host.querySelectorAll('.mark-input').forEach(i => {
      if (i.value === '') return;
      if (parseFloat(i.value) > parseFloat(i.dataset.max) && bad === null) bad = i;
      const st = i.dataset.student, cat = i.dataset.cat;
      marks[st] = marks[st] || {};
      marks[st][cat] = i.value;
    });
    if (bad) { App.toast('Score exceeds the category maximum (/' + parseFloat(bad.dataset.max) + ').', 'danger'); bad.focus(); return; }
    const res = await postSchool({ action: 'save_marks', alloc: allocId, marks: marks });
    if (res.ok) { App.toast(res.message, 'success'); setTimeout(() => location.reload(), 600); }
    else App.toast(res.message || 'Failed.', 'danger');
  });
});
</script>
<?php
ui_page_close();
