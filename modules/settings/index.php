<?php
/**
 * /settings — system settings (clinic info, finance, prefixes, security).
 * POST: settings.manage required. View: settings.view.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('settings.view');

$canManage = has_permission('settings.manage');

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) { flash('danger', 'You do not have permission to change settings.'); redirect('/settings'); }
    require_csrf();

    $keys = [
        'clinic_name', 'clinic_phone', 'clinic_email', 'clinic_address',
        'currency', 'currency_symbol', 'timezone', 'date_format',
        'tax_percent', 'receipt_footer',
        'invoice_prefix', 'patient_prefix', 'appointment_prefix', 'prescription_prefix',
        'lab_prefix', 'payment_prefix', 'queue_prefix', 'doctor_prefix', 'staff_prefix',
        'medicine_prefix', 'sale_prefix', 'claim_prefix', 'visit_prefix',
        'branch_prefix', 'service_prefix', 'diagnosis_prefix',
        'session_timeout_minutes', 'low_stock_alert_days',
    ];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $_POST)) continue;
        $val = trim((string) $_POST[$key]);
        $type = in_array($key, ['tax_percent', 'session_timeout_minutes', 'low_stock_alert_days'], true) ? 'number' : 'text';
        db_execute('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $val, $type], 'sss');
    }

    // Optional logo upload
    if (!empty($_FILES['clinic_logo_file']['name']) && ($_FILES['clinic_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $path = upload_image($_FILES['clinic_logo_file'], 'branding');
            if ($path) db_execute("UPDATE settings SET setting_value = ? WHERE setting_key = 'clinic_logo'", [$path], 's');
        } catch (Throwable $ex) {
            flash('warning', 'Logo was not saved: ' . $ex->getMessage());
        }
    }

    audit_log('update', 'Settings', null, 'System settings updated');
    flash('success', 'Settings saved successfully.');
    redirect('/settings');
}

// ---------------------------------------------------------------------
// Grouped definitions: key => [label, type, hint]
// ---------------------------------------------------------------------
$sections = [
    'Clinic Information' => [
        'clinic_name'    => ['Clinic Name', 'text', 'Shown in sidebar, invoices and login page.'],
        'clinic_phone'   => ['Phone', 'text', ''],
        'clinic_email'   => ['Email', 'text', ''],
        'clinic_address' => ['Address', 'textarea', ''],
    ],
    'Localization & Currency' => [
        'currency'        => ['Currency Code', 'text', 'e.g. ETB, USD'],
        'currency_symbol' => ['Currency Symbol', 'text', 'e.g. Br, $'],
        'timezone'        => ['Timezone', 'select', 'PHP timezone identifier (e.g. Africa/Addis_Ababa)'],
        'date_format'     => ['Date Format', 'select', 'How dates are displayed'],
    ],
    'Finance' => [
        'tax_percent'    => ['Tax / VAT Percent', 'number', 'Applied to new invoices'],
        'receipt_footer' => ['Receipt Footer', 'textarea', 'Printed at the bottom of invoices'],
    ],
    'Document Prefixes' => [
        'invoice_prefix'     => ['Invoice Prefix', 'text', ''],
        'patient_prefix'     => ['Patient Prefix', 'text', ''],
        'appointment_prefix' => ['Appointment Prefix', 'text', ''],
        'prescription_prefix' => ['Prescription Prefix', 'text', ''],
        'lab_prefix'         => ['Lab Order Prefix', 'text', ''],
        'payment_prefix'     => ['Payment Prefix', 'text', ''],
        'queue_prefix'       => ['Queue Prefix', 'text', ''],
        'doctor_prefix'      => ['Doctor Prefix', 'text', ''],
        'staff_prefix'       => ['Staff Prefix', 'text', ''],
        'medicine_prefix'    => ['Medicine Prefix', 'text', ''],
        'sale_prefix'        => ['Pharmacy Sale Prefix', 'text', ''],
        'claim_prefix'       => ['Insurance Claim Prefix', 'text', ''],
        'visit_prefix'       => ['Visit Prefix', 'text', ''],
        'branch_prefix'      => ['Branch Prefix', 'text', ''],
        'service_prefix'     => ['Service Prefix', 'text', ''],
        'diagnosis_prefix'   => ['Diagnosis Prefix', 'text', ''],
    ],
    'Security & Alerts' => [
        'session_timeout_minutes' => ['Session Timeout (minutes)', 'number', 'Users are logged out after this idle time'],
        'low_stock_alert_days'    => ['Expiry Warning Window (days)', 'number', 'Medicines expiring within this window are flagged'],
    ],
];

$selectOptions = [
    'timezone' => [
        'Africa/Addis_Ababa', 'Africa/Nairobi', 'Africa/Cairo', 'Europe/London', 'Europe/Berlin',
        'Asia/Dubai', 'Asia/Kolkata', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'UTC',
    ],
    'date_format' => ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd-M-Y'],
];

$current = [];
foreach (db_fetch_all('SELECT setting_key, setting_value FROM settings') as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

ui_page_open(['title' => 'System Settings', 'icon' => 'fa-gear', 'breadcrumb' => ['Administration' => null, 'Settings' => null]]);
?>
<?php if ($canManage): ?>
<form method="post" action="/settings" enctype="multipart/form-data">
  <?= csrf_field() ?>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($sections as $sectionName => $fields): ?>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2"><i class="fa-solid fa-sliders me-2 text-brand"></i><?= e($sectionName) ?></div>
        <div class="card-body">
          <?php foreach ($fields as $key => [$label, $type, $hint]): ?>
            <?php $val = $current[$key] ?? ''; ?>
            <div class="mb-3">
              <label class="form-label small fw-semibold mb-1"><?= e($label) ?></label>
              <?php if ($type === 'textarea'): ?>
                <textarea class="form-control" name="<?= e($key) ?>" rows="2" <?= $canManage ? '' : 'disabled' ?>><?= e($val) ?></textarea>
              <?php elseif ($type === 'select'): ?>
                <select class="form-select" name="<?= e($key) ?>" <?= $canManage ? '' : 'disabled' ?>>
                  <?php foreach ($selectOptions[$key] ?? [] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $val === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="<?= $type === 'number' ? 'number' : 'text' ?>" step="any"
                       class="form-control" name="<?= e($key) ?>" value="<?= e($val) ?>" <?= $canManage ? '' : 'disabled' ?>>
              <?php endif; ?>
              <?php if ($hint): ?><div class="form-text small"><?= e($hint) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if ($sectionName === 'Clinic Information'): ?>
            <div class="mb-1">
              <label class="form-label small fw-semibold mb-1">Clinic Logo</label>
              <div class="d-flex align-items-center gap-3">
                <?php $logo = $current['clinic_logo'] ?? ''; ?>
                <?php if ($logo): ?><img src="/<?= e($logo) ?>" alt="logo" style="height:44px;border-radius:8px"><?php endif; ?>
                <input type="file" class="form-control" name="clinic_logo_file" accept="image/png,image/jpeg,image/webp" <?= $canManage ? '' : 'disabled' ?>>
              </div>
              <div class="form-text small">PNG/JPG/WebP up to 4 MB. Current key: <code>clinic_logo</code> <?= $logo ? '(uploaded)' : '(none)' ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/config/supabase.php'; ?>
<div class="card mt-3">
  <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
    <span><i class="fa-solid fa-cloud-arrow-up me-2 text-brand"></i>Cloud Backup (Supabase)</span>
    <?php if ($canManage): ?>
      <span class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-sm btn-light border" id="sbTest"><i class="fa-solid fa-plug-circle-check me-1"></i>Test Connection</button>
        <button type="button" class="btn btn-sm btn-light border" id="sbList"><i class="fa-solid fa-list me-1"></i>List Backups</button>
        <button type="button" class="btn btn-sm btn-brand" id="sbBackup"><i class="fa-solid fa-database me-1"></i>Back Up Now</button>
      </span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php $sbConfigured = supabase_enabled(); ?>
    <?php if (!$sbConfigured): ?>
      <div class="alert alert-warning mb-2 py-2 small">
        <i class="fa-solid fa-key me-2"></i>Supabase is not configured yet. Add <code>SUPABASE_URL</code>, <code>SUPABASE_ANON_KEY</code>
        and <code>SUPABASE_SERVICE_ROLE_KEY</code> in the Keys tab, then reload this page.
        <code>SUPABASE_URL</code> looks like <code>https://abcdefgh.supabase.co</code>.
      </div>
    <?php endif; ?>
    <div class="small text-muted mb-2">
      Creates a full SQL dump of the clinic database (schema + data) and uploads it to a private <code>clinic-backups</code>
      bucket in your Supabase project, stored offsite under <code>backups/&lt;year&gt;/&lt;month&gt;/</code>.
      Restore any file on a MySQL/MariaDB server.
    </div>
    <div id="sbStatus" class="small text-muted"></div>
    <div id="sbFiles" class="mt-2"></div>
  </div>
</div>
<script>
(function () {
  var btnTest = document.getElementById('sbTest'),
      btnBackup = document.getElementById('sbBackup'),
      btnList = document.getElementById('sbList');
  if (!btnTest && !btnBackup && !btnList) return;
  var sbConfigured = <?= $sbConfigured ? 'true' : 'false' ?>;
  var status = document.getElementById('sbStatus'),
      files = document.getElementById('sbFiles');

  function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
  function fmtSize(n) { n = n || 0; return n >= 1048576 ? (n / 1048576).toFixed(2) + ' MB' : (n >= 1024 ? (n / 1024).toFixed(1) + ' KB' : n + ' B'); }
  function busy(on) { [btnTest, btnBackup, btnList].forEach(function (b) { if (b) b.disabled = on; }); }
  function say(html, cls) { status.className = 'small ' + (cls || 'text-muted'); status.innerHTML = html; }

  function renderFiles(list) {
    if (!list || !list.length) { files.innerHTML = '<div class="text-muted small">No backups stored yet.</div>'; return; }
    var rows = list.map(function (f) {
      var when = (f.created || '').replace('T', ' ').slice(0, 19);
      return '<tr><td class="text-break">' + esc(f.name) + '</td><td class="text-nowrap small">' + esc(when) +
        '</td><td class="text-end text-nowrap small">' + fmtSize(f.size) + '</td></tr>';
    }).join('');
    files.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
      '<thead><tr><th>Backup file</th><th>Uploaded</th><th class="text-end">Size</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  async function run(action, btn, busyLabel) {
    var old = btn.innerHTML;
    busy(true); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>' + busyLabel;
    try {
      var r = await App.postJSON('/ajax/supabase', { action: action });
      if (r.ok) {
        if (action === 'test') {
          say('<i class="fa-solid fa-circle-check text-success me-1"></i>' + esc(r.message || 'Connected.'), 'text-success');
        } else if (action === 'backup') {
          say('<i class="fa-solid fa-circle-check text-success me-1"></i>' + esc(r.message || 'Backup uploaded.') +
            ' <span class="text-muted">(' + esc(r.object || '') + ' — ' + r.rows + ' rows, ' + r.size_mb + ' MB)</span>', 'text-success');
          loadBackups();
        } else {
          renderFiles(r.files || []);
          say('<i class="fa-solid fa-circle-check text-success me-1"></i>' + ((r.files || []).length) + ' backup file(s) found.', 'text-success');
        }
      } else {
        say('<i class="fa-solid fa-circle-exclamation text-danger me-1"></i>' + esc(r.message || 'Request failed.'), 'text-danger');
      }
    } catch (e) {
      say('<i class="fa-solid fa-circle-exclamation text-danger me-1"></i>Request failed: ' + esc(e && e.message ? e.message : e), 'text-danger');
    } finally {
      busy(false); btn.innerHTML = old;
    }
  }

  async function loadBackups() {
    if (!sbConfigured) return;
    try {
      var r = await App.postJSON('/ajax/supabase', { action: 'list' });
      if (r.ok) renderFiles(r.files || []);
    } catch (e) { /* silent — bucket may not exist yet */ }
  }

  if (btnTest) btnTest.addEventListener('click', function () { run('test', btnTest, 'Testing…'); });
  if (btnList) btnList.addEventListener('click', function () { run('list', btnList, 'Loading…'); });
  if (btnBackup) btnBackup.addEventListener('click', function () {
    if (confirm('Create and upload a full database backup to Supabase now?')) run('backup', btnBackup, 'Backing up…');
  });
  document.addEventListener('DOMContentLoaded', function () { loadBackups(); });
})();
</script>

<?php if ($canManage): ?>
  <div class="d-flex gap-2 mt-3">
    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk me-1"></i>Save All Settings</button>
    <a href="/settings" class="btn btn-light border">Reset</a>
  </div>
</form>
<?php else: ?>
  <div class="alert alert-info mt-3 mb-0"><i class="fa-solid fa-lock me-2"></i>Settings are read-only for your role.</div>
<?php endif; ?>
<?php
ui_page_close();
