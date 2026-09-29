<?php
/**
 * /insurance — insurance companies (via CRUD engine) + quick stats.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('insurance.view');

$claimsPending = (int) db_fetch_value("SELECT COUNT(*) FROM insurance_claims WHERE status = 'pending'");
$claimsApprovedAmt = (float) db_fetch_value("SELECT COALESCE(SUM(approved_amount),0) FROM insurance_claims WHERE status IN ('approved','paid')");

ui_page_open(['title' => 'Insurance', 'icon' => 'fa-shield-halved', 'breadcrumb' => ['Finance' => null, 'Insurance' => null]]);

echo '<div class="row g-3 mb-4">';
echo stat_card('Pending Claims', $claimsPending, 'fa-hourglass-half', 'warning', '/insurance/claims');
echo stat_card('Approved Amount', money($claimsApprovedAmt), 'fa-circle-check', 'success', '/insurance/claims');
echo stat_card('Claims Center', 'Open', 'fa-folder-open', 'brand', '/insurance/claims');
echo '</div>';

echo '<div class="card">';
echo '<div class="card-header py-2 d-flex align-items-center"><span><i class="fa-solid fa-building-shield me-2 text-brand"></i>Insurance Companies</span>';
if (has_permission('insurance.create')) {
    echo '<button class="btn btn-sm btn-brand ms-auto" data-action="create" data-url="/ajax/crud?resource=insurance_companies" data-title="Insurance Company"><i class="fa-solid fa-plus me-1"></i>Add Company</button>';
}
echo '</div><div class="card-body">';

$rows = db_fetch_all('SELECT ic.*,
        (SELECT COUNT(*) FROM insurance_claims c WHERE c.insurance_company_id = ic.id) claims_count,
        (SELECT COUNT(*) FROM patients pt WHERE pt.insurance_company_id = ic.id) patients_count
     FROM insurance_companies ic ORDER BY ic.company_name');
?>
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead><tr><th>Company</th><th>Phone</th><th>Email</th><th>Patients</th><th>Claims</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No insurance companies yet</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
    <tr>
      <td class="fw-semibold"><?= e($r['company_name']) ?></td>
      <td class="small"><?= e(or_na($r['phone'])) ?></td>
      <td class="small"><?= e(or_na($r['email'])) ?></td>
      <td class="small"><?= (int) $r['patients_count'] ?></td>
      <td class="small"><?= (int) $r['claims_count'] ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-light" href="/insurance/claims?company_id=<?= $id ?>" title="Claims"><i class="fa-solid fa-folder-open"></i></a>
        <?php if (has_permission('insurance.edit')): ?>
          <button class="btn btn-sm btn-light" data-action="edit" data-id="<?= $id ?>" data-url="/ajax/crud?resource=insurance_companies" data-title="Insurance Company"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-sm btn-light" data-action="toggle" data-id="<?= $id ?>" data-url="/ajax/crud?resource=insurance_companies" title="Enable/Disable"><i class="fa-solid fa-power-off"></i></button>
        <?php endif; ?>
        <?php if (has_permission('insurance.delete')): ?>
          <button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="<?= $id ?>" data-url="/ajax/crud?resource=insurance_companies" data-title="Insurance Company" data-name="<?= e($r['company_name']) ?>"><i class="fa-solid fa-trash"></i></button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
echo '</div></div>';
ui_page_close();
