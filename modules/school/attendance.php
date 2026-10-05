<?php
/**
 * /school/attendance — daily attendance by the homeroom teacher.
 */
require_once __DIR__ . '/_init.php';
require_permission('school.teach');
$teacher = school_current_teacher();
$isAdmin = has_permission('school.manage');
if (!$teacher && !$isAdmin) {
    ui_page_open(['title' => 'School — Attendance', 'icon' => 'fa-calendar-check', 'breadcrumb' => ['School' => '/school', 'Attendance' => null]]);
    echo '<div class="alert alert-warning">No teacher profile is linked to your account.</div>';
    ui_page_close();
    exit;
}
$homerooms = $teacher ? school_teacher_homerooms($teacher['id']) : [];
$today = date('Y-m-d');
$selDate = get('date', $today);
if (!valid_date($selDate)) $selDate = $today;

ui_page_open(['title' => 'School — Attendance', 'icon' => 'fa-calendar-check', 'breadcrumb' => ['School' => '/school', 'Attendance' => null]]);
require __DIR__ . '/_js.php';

echo '<div class="alert alert-light border small mb-3">Homeroom teachers take daily attendance for their own class. <a href="/school/reports">Attendance reports →</a></div>';

echo '<div class="card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end">';
echo '<div class="col-md-4"><label class="form-label">Class' . ($isAdmin ? ' (any)' : ' (your homerooms)') . '</label><select class="form-select" name="cls">';
$selCls = get('cls', '');
if ($isAdmin) {
    foreach ($grades as $g) {
        foreach ($sections as $s) {
            if ((int) $s['grade_id'] !== (int) $g['id']) continue;
            $v = $g['id'] . ':' . $s['id'];
            echo '<option value="' . $v . '" ' . ($selCls === $v ? 'selected' : '') . '>' . e($g['name'] . ' - ' . $s['name']) . '</option>';
        }
    }
} else {
    foreach ($homerooms as $h) {
        $v = $h['grade_id'] . ':' . $h['section_id'];
        echo '<option value="' . $v . '" ' . ($selCls === $v ? 'selected' : '') . '>' . e($h['grade_name'] . ' - ' . $h['section_name']) . ' (' . (int) $h['student_count'] . ' students)</option>';
    }
}
echo '</select></div>';
echo '<div class="col-md-3"><label class="form-label">Date</label><input type="date" class="form-control" name="date" value="' . e($selDate) . '" max="' . e($today) . '"></div>';
echo '<div class="col-md-2"><button class="btn btn-brand w-100"><i class="fa-solid fa-list-check me-1"></i>Load</button></div>';
echo '</form></div></div>';

$canLoad = false;
if ($selCls !== '' && preg_match('/^(\d+):(\d+)$/', $selCls, $m)) {
    $gradeId = (int) $m[1]; $sectionId = (int) $m[2];
    if ($isAdmin) $canLoad = true;
    else foreach ($homerooms as $h) if ((int) $h['grade_id'] === $gradeId && (int) $h['section_id'] === $sectionId) $canLoad = true;
}

if ($canLoad):
    echo '<div id="attHost" class="card"><div class="card-body text-center py-4"><span class="spinner-border text-secondary"></span></div></div>';
else:
    echo '<div class="alert alert-info">' . ($isAdmin ? 'Pick a class and date to load the roster.' : 'You are not the homeroom teacher of any class yet.') . '</div>';
endif;
?>
<script>
document.addEventListener('DOMContentLoaded', async function () {
  const host = document.getElementById('attHost');
  if (!host) return;
  const res = await fetch('/ajax/school?att_roster=1&grade=<?php echo $gradeId ?? 0; ?>&section=<?php echo $sectionId ?? 0; ?>&date=<?php echo e($selDate); ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const data = await res.json();
  if (!data.ok) { host.innerHTML = '<div class="alert alert-danger m-3">' + App.escapeHtml(data.message || 'Could not load roster.') + '</div>'; return; }
  const opts = { present: ['success', 'P'], absent: ['danger', 'A'], late: ['warning', 'L'], excused: ['secondary', 'E'] };
  let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Student</th><th class="text-center" style="width:260px">Status</th></tr></thead><tbody>';
  if (!data.students.length) html += '<tr><td colspan="2" class="text-center text-muted py-4">No students in this class</td></tr>';
  data.students.forEach(s => {
    const cur = (data.saved[s.id] || {}).status || 'present';
    html += '<tr><td class="small fw-semibold">' + App.escapeHtml(s.full_name) + ' <span class="text-muted">' + App.escapeHtml(s.student_code || '') + '</span></td><td class="text-center">';
    for (const k in opts) {
      const [c, l] = opts[k];
      html += '<input type="radio" class="btn-check" name="status_' + s.id + '" id="st_' + s.id + '_' + k + '" value="' + k + '"' + (cur === k ? ' checked' : '') + '>';
      html += '<label class="btn btn-sm btn-outline-' + c + '" for="st_' + s.id + '_' + k + '">' + l + '</label> ';
    }
    html += '</td></tr>';
  });
  html += '</tbody></table></div><div class="d-flex justify-content-between align-items-center p-3 border-top"><button class="btn btn-brand" id="saveAtt"><i class="fa-solid fa-floppy-disk me-1"></i>Save Attendance</button><span class="small text-muted">P present · A absent · L late · E excused</span></div>';
  host.innerHTML = html;

  document.getElementById('saveAtt')?.addEventListener('click', async function () {
    const status = {};
    host.querySelectorAll('input[type=radio]:checked').forEach(r => {
      const sid = r.name.replace('status_', '');
      status[sid] = r.value;
    });
    const res = await postSchool({ action: 'save_attendance', grade_id: <?php echo $gradeId ?? 0; ?>, section_id: <?php echo $sectionId ?? 0; ?>, date: '<?php echo e($selDate); ?>', status: status });
    if (res.ok) { App.toast(res.message, 'success'); setTimeout(() => location.reload(), 600); }
    else App.toast(res.message || 'Failed.', 'danger');
  });
});
</script>
<?php
ui_page_close();
