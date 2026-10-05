<?php
/**
 * /school — manage teachers + bulk upload by Excel (CSV/XLSX).
 */
require_once __DIR__ . '/_init.php';
require_permission('school.view');
$canManage = has_permission('school.manage');

$q = get('q', '');
$where = ' WHERE t.status = 1';
$params = [];
if ($q !== '') { $where .= ' AND (t.full_name LIKE ? OR t.email LIKE ? OR t.teacher_code LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$rows = db_fetch_all(
    "SELECT t.*, u.username, u.id uid
     FROM school_teachers t LEFT JOIN users u ON u.id = t.user_id
     $where ORDER BY t.full_name LIMIT 200", $params);
$noLogin = (int) db_fetch_value('SELECT COUNT(*) FROM school_teachers WHERE status = 1 AND user_id IS NULL');
$homeroomCount = (int) db_fetch_value('SELECT COUNT(*) FROM school_homerooms');
$allocCount = (int) db_fetch_value('SELECT COUNT(*) FROM school_allocations');

ui_page_open(['title' => 'School — Teachers', 'icon' => 'fa-chalkboard-user', 'breadcrumb' => ['School' => null, 'Teachers' => null]]);
echo '<div data-crud-url="/ajax/school" data-crud-title="Teacher">';
echo '<div class="row g-3 mb-4">';
echo stat_card('Teachers', count($rows), 'fa-chalkboard-user', 'brand');
echo stat_card('Without Login', $noLogin, 'fa-user-slash', $noLogin ? 'warning' : 'success');
echo stat_card('Homeroom Assignments', $homeroomCount, 'fa-door-open', 'success', '/school/homeroom');
echo stat_card('Subject Allocations', $allocCount, 'fa-book-open', 'success', '/school/allocations');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="school"><input name="q" class="form-control form-control-sm" placeholder="Search teacher..." value="' . e($q) . '" style="width:220px"><button class="btn btn-sm btn-outline-brand">Search</button></form>';
if ($canManage) {
    echo '<div class="d-flex gap-2">';
    echo '<a class="btn btn-sm btn-light border" href="/ajax/school?template=1"><i class="fa-solid fa-file-csv me-1"></i>Download template</a>';
    echo '<button class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#uploadTeachersModal"><i class="fa-solid fa-file-excel me-1"></i>Upload by Excel</button>';
    echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/school?form=teacher" data-title="Teacher"><i class="fa-solid fa-user-plus me-1"></i>Add Teacher</button>';
    echo '</div>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Code</th><th>Teacher</th><th>Email</th><th>Phone</th><th>Qualification</th><th>Login</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No teachers yet' . ($canManage ? ' — add one or upload an Excel/CSV file' : '') . '</td></tr>';
foreach ($rows as $t) {
    echo '<tr>';
    echo '<td class="small text-nowrap">' . e($t['teacher_code'] ?? '—') . '</td>';
    echo '<td class="fw-semibold">' . e($t['full_name']) . '</td>';
    echo '<td class="small">' . e(or_na($t['email'])) . '</td>';
    echo '<td class="small">' . e(or_na($t['phone'])) . '</td>';
    echo '<td class="small">' . e(or_na($t['qualification'])) . '</td>';
    echo '<td class="small">' . ($t['username'] ? '<span class="badge text-bg-success">' . e($t['username']) . '</span>' : '<span class="badge text-bg-secondary">no login</span>') . '</td>';
    if ($canManage) {
        echo '<td class="text-end text-nowrap"><button class="btn btn-sm btn-light border" data-action="edit" data-id="' . (int) $t['id'] . '" data-url="/ajax/school?form=teacher" data-title="Teacher"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light border" data-school-action="delete_teacher" data-id="' . (int) $t['id'] . '" title="Deactivate"><i class="fa-solid fa-user-slash"></i></button></td>';
    } else {
        echo '<td></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div></div>';

if ($canManage):
require __DIR__ . '/_js.php';
?>
<div class="modal fade" id="uploadTeachersModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="uploadTeachersForm">
        <div class="modal-header"><h5 class="modal-title">Upload Teachers by Excel</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="alert alert-light border small">Columns: <strong>Full Name, Email, Phone, Qualification</strong>. CSV is always supported; .xlsx works on servers with the zip extension. Each imported teacher gets a login (username from email/name, password <code>Teacher@123</code>).</div>
          <input type="file" class="form-control" name="file" accept=".csv,.xlsx" required>
          <div class="form-text" id="utResult"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand"><i class="fa-solid fa-upload me-1"></i>Import Teachers</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', async function (ev) {
  const btn = ev.target.closest('[data-school-action]');
  if (!btn) return;
  if (!confirm('Deactivate this record?')) return;
  const data = await postSchool({ action: btn.dataset.schoolAction, id: btn.dataset.id });
  if (data.ok) { App.toast(data.message, 'success'); setTimeout(() => location.reload(), 500); }
  else App.toast(data.message || 'Failed.', 'danger');
});
document.getElementById('uploadTeachersForm')?.addEventListener('submit', async function (ev) {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  fd.append('action', 'upload_teachers');
  fd.append('csrf_token', App.csrf);
  const out = document.getElementById('utResult');
  out.textContent = 'Importing...';
  try {
    const res = await fetch('/ajax/school', { method: 'POST', headers: { 'X-CSRF-Token': App.csrf }, body: fd });
    const data = await res.json();
    out.innerHTML = App.escapeHtml(data.message || '');
    if (data.errors && data.errors.length) out.innerHTML += '<div class="text-danger small">' + data.errors.map(App.escapeHtml).join('<br>') + '</div>';
    if (data.ok) setTimeout(() => location.reload(), 900);
  } catch (e) { out.textContent = 'Network error.'; }
});
</script>
<?php endif;
ui_page_close();
