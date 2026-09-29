<?php
/**
 * /help — how to use the system, per role. Help-only (informational) page
 * with per-role PDF download. Visible to every logged-in user.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_once dirname(__DIR__, 2) . '/includes/pdf.php';
require_once dirname(__DIR__, 2) . '/config/help.php';
require_login();

// ---------------------------------------------------------------------
// PDF download: /help?pdf=<Role Name> or /help?pdf=all
// ---------------------------------------------------------------------
$pdfFor = get('pdf');
if ($pdfFor !== '') {
    $roles = help_roles();
    $title = setting('clinic_name', 'Clinic') . ' — Help Guide';

    if (strcasecmp($pdfFor, 'all') === 0) {
        $pdf = new SimplePdf($title . ' (All Roles)');
        $pdf->h1('Help Guide — All Roles');
        $pdf->body('How to use the Clinic Management System, step by step, for every role. Find your role below.');
        $pdf->rule();
        $first = true;
        foreach ($roles as $roleName => $r) {
            if (!$first) $pdf->addPage();
            $first = false;
            pdf_render_role($pdf, $title, $roleName, $r);
        }
        $pdf->output('help-guide-all-roles.pdf');
    }

    if (isset($roles[$pdfFor])) {
        $pdf = new SimplePdf($title . ' — ' . $pdfFor);
        pdf_render_role($pdf, $title, $pdfFor, $roles[$pdfFor]);
        $pdf->output('help-guide-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $pdfFor)) . '.pdf');
    }

    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}

/** Render one role's section into the PDF. */
function pdf_render_role(SimplePdf $pdf, string $docTitle, string $roleName, array $r): void
{
    $pdf->h1($roleName);
    $pdf->body($r['tagline']);
    $pdf->body($r['intro']);
    $pdf->h2('Getting Started');
    $n = 1;
    foreach ($r['start'] as $s) {
        $pdf->step($n++, $s);
    }
    $pdf->h2('Modules & How To Use Them');
    foreach ($r['modules'] as [$mod, $modIcon, $steps]) {
        $pdf->h3($mod);
        foreach ($steps as $st) {
            $pdf->bullet($st);
        }
    }
    if (!empty($r['tips'])) {
        $pdf->h2('Good To Know');
        foreach ($r['tips'] as $t) {
            $pdf->bullet($t);
        }
    }
}

// ---------------------------------------------------------------------
// On-screen page
// ---------------------------------------------------------------------
$roles = help_roles();
$me = current_user();
$myRole = (string) ($me['role_name'] ?? '');
if (!isset($roles[$myRole])) {
    $myRole = help_fallback_role();
}

ui_page_open(['title' => 'Help — How To Use', 'icon' => 'fa-circle-question', 'breadcrumb' => ['Help' => null]]);
?>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card help-hero h-100">
      <div class="card-body d-flex flex-column justify-content-center">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="page-icon"><i class="fa-solid <?= e($roles[$myRole]['icon']) ?>"></i></span>
          <div>
            <div class="text-muted small text-uppercase">Guide for your role</div>
            <h2 class="fw-bold mb-0"><?= e($myRole) ?></h2>
          </div>
        </div>
        <p class="text-muted mb-0"><?= e($roles[$myRole]['tagline']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body d-flex flex-column justify-content-center gap-2">
        <h6 class="fw-bold mb-1"><i class="fa-solid fa-file-pdf me-2 text-danger"></i>Download as PDF</h6>
        <p class="text-muted small mb-2">Print-friendly guide with every step for your role.</p>
        <a class="btn btn-brand" href="/help?pdf=<?= e(rawurlencode($myRole)) ?>">
          <i class="fa-solid fa-download me-1"></i>My Role PDF (<?= e($myRole) ?>)
        </a>
        <a class="btn btn-outline-secondary" href="/help?pdf=all">
          <i class="fa-solid fa-users me-1"></i>All Roles PDF
        </a>
      </div>
    </div>
  </div>
</div>

<!-- My role guide -->
<div class="card mb-4" id="myRoleGuide">
  <div class="card-header py-2 d-flex align-items-center">
    <i class="fa-solid <?= e($roles[$myRole]['icon']) ?> me-2"></i>
    <strong><?= e($myRole) ?></strong> — how to use the system
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-5">
        <h6 class="fw-bold text-uppercase small text-muted mb-3"><i class="fa-solid fa-flag-checkered me-1"></i>Getting Started</h6>
        <ol class="help-steps ps-3">
          <?php foreach ($roles[$myRole]['start'] as $i => $s): ?>
            <li class="mb-2"><?= e($s) ?></li>
          <?php endforeach; ?>
        </ol>
        <?php if (!empty($roles[$myRole]['tips'])): ?>
        <h6 class="fw-bold text-uppercase small text-muted mt-4 mb-2"><i class="fa-solid fa-lightbulb me-1"></i>Good To Know</h6>
        <?php foreach ($roles[$myRole]['tips'] as $t): ?>
          <div class="small mb-1"><i class="fa-solid fa-circle-check text-success me-1"></i><?= e($t) ?></div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="col-lg-7">
        <h6 class="fw-bold text-uppercase small text-muted mb-3"><i class="fa-solid fa-list-check me-1"></i>Modules &amp; How To Use Them</h6>
        <div class="accordion" id="helpAcc">
          <?php foreach ($roles[$myRole]['modules'] as $mi => [$mod, $modIcon, $steps]): ?>
          <div class="accordion-item">
            <h2 class="accordion-header">
              <button class="accordion-button <?= $mi === 0 ? '' : 'collapsed' ?> py-2" type="button" data-bs-toggle="collapse" data-bs-target="#helpMod<?= $mi ?>">
                <i class="fa-solid <?= e($modIcon) ?> me-2 text-brand"></i><?= e($mod) ?>
                <span class="badge bg-secondary-subtle text-secondary ms-2"><?= count($steps) ?></span>
              </button>
            </h2>
            <div id="helpMod<?= $mi ?>" class="accordion-collapse collapse <?= $mi === 0 ? 'show' : '' ?>" data-bs-parent="#helpAcc">
              <div class="accordion-body small">
                <ul class="mb-0 ps-3">
                  <?php foreach ($steps as $st): ?>
                    <li class="mb-1"><?= e($st) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Other roles -->
<div class="card">
  <div class="card-header py-2"><i class="fa-solid fa-users me-2"></i>Other Roles</div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($roles as $roleName => $r): if ($roleName === $myRole) continue; ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100 help-role-card">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="page-icon"><i class="fa-solid <?= e($r['icon']) ?>"></i></span>
              <strong><?= e($roleName) ?></strong>
            </div>
            <p class="text-muted small mb-2"><?= e($r['tagline']) ?></p>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-light border" href="/help?pdf=<?= e(rawurlencode($roleName)) ?>">
                <i class="fa-solid fa-file-pdf me-1 text-danger"></i>PDF
              </a>
              <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#otherRole-<?= md5($roleName) ?>">
                <i class="fa-solid fa-eye me-1"></i>Quick view
              </button>
            </div>
            <div class="collapse mt-2" id="otherRole-<?= md5($roleName) ?>">
              <ol class="small ps-3 mb-2">
                <?php foreach (array_slice($r['start'], 0, 3) as $s): ?>
                  <li class="mb-1"><?= e($s) ?></li>
                <?php endforeach; ?>
              </ol>
              <div class="small text-muted mb-0"><?= count($r['modules']) ?> modules covered — download the PDF for full steps.</div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php ui_page_close(); ?>
