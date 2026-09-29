<?php
/**
 * /audit — audit trail viewer.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('audit.view');

$q = get('q', '');
$actionF = get('action', '');
$moduleF = get('module', '');
$userF = get('user_id', '');
$from = get('from', '');
$to = get('to', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') { $where .= ' AND (a.description LIKE ?)'; $params[] = "%$q%"; }
if ($actionF !== '') { $where .= ' AND a.action = ?'; $params[] = $actionF; }
if ($moduleF !== '') { $where .= ' AND a.module = ?'; $params[] = $moduleF; }
if ($userF !== '') { $where .= ' AND a.user_id = ?'; $params[] = (int) $userF; }
if ($from !== '' && valid_date($from)) { $where .= ' AND a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && valid_date($to)) { $where .= ' AND a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }

$total = (int) db_fetch_value("SELECT COUNT(*) FROM audit_logs a $where", $params);
[$offset, $perPage] = paginate($total, 25);
$rows = db_fetch_all(
    "SELECT a.*, u.full_name user_name, u.username, b.branch_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN branches b ON b.id = a.branch_id
     $where ORDER BY a.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$users = db_fetch_all('SELECT id, full_name FROM users ORDER BY full_name');
$actions = db_fetch_all('SELECT DISTINCT action FROM audit_logs ORDER BY action');
$modules = db_fetch_all('SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module');
$actionIcon = [
    'login' => 'fa-right-to-bracket', 'logout' => 'fa-right-from-bracket',
    'create' => 'fa-plus', 'update' => 'fa-pen', 'delete' => 'fa-trash',
    'payment' => 'fa-money-bill-wave', 'cancel' => 'fa-ban', 'export' => 'fa-file-csv',
    'permission_change' => 'fa-user-shield', 'dispense' => 'fa-pills',
];
$actionTone = ['create' => 'success', 'update' => 'primary', 'delete' => 'danger', 'payment' => 'success',
    'cancel' => 'danger', 'login' => 'info', 'logout' => 'secondary', 'export' => 'dark', 'permission_change' => 'warning'];

ui_page_open(['title' => 'Audit Log', 'icon' => 'fa-clipboard-list', 'breadcrumb' => ['Administration' => null, 'Audit Log' => null]]);
?>
<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
  <form method="get" class="d-flex flex-wrap gap-2">
    <input name="q" class="form-control form-control-sm" placeholder="Search description..." value="<?= e($q) ?>" style="width:200px">
    <select name="action" class="form-select form-select-sm" style="width:130px"><option value="">All actions</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= $actionF === $a['action'] ? 'selected' : '' ?>><?= label_case($a['action']) ?></option><?php endforeach; ?>
    </select>
    <select name="module" class="form-select form-select-sm" style="width:140px"><option value="">All modules</option>
      <?php foreach ($modules as $m): ?><option value="<?= e((string) $m['module']) ?>" <?= $moduleF === $m['module'] ? 'selected' : '' ?>><?= e((string) $m['module']) ?></option><?php endforeach; ?>
    </select>
    <select name="user_id" class="form-select form-select-sm" style="width:160px"><option value="">All users</option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $userF === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" style="width:145px">
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" style="width:145px">
    <button class="btn btn-sm btn-outline-brand">Filter</button>
  </form>
  <span class="small text-muted"><i class="fa-solid fa-clock-rotate-left me-1"></i>Retention is unlimited in this version.</span>
</div>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>Branch</th><th>IP</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No audit entries found</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="small text-nowrap text-muted" title="<?= e($r['created_at']) ?>"><?= fmt_date($r['created_at'], true) ?></td>
      <td class="small text-nowrap"><?= e(or_na($r['user_name'])) ?></td>
      <td class="text-nowrap"><span class="badge text-bg-<?= $actionTone[$r['action']] ?? 'secondary' ?> small">
        <i class="fa-solid <?= $actionIcon[$r['action']] ?? 'fa-circle-dot' ?> me-1"></i><?= label_case($r['action']) ?></span></td>
      <td class="small"><?= e(or_na($r['module'])) ?></td>
      <td class="small" style="max-width:420px"><?= e(or_na($r['description'])) ?></td>
      <td class="small"><?= e(or_na($r['branch_name'])) ?></td>
      <td class="small text-muted"><?= e(or_na($r['ip_address'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted"><?= result_count_label() ?></span><?= pagination_links() ?></div>
</div>
<?php
ui_page_close();
