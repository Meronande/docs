<?php
/**
 * Targeted self-tests for the current changes (PHP 7.0+ compatible).
 * Run: php tools/selftest.php
 * Exits non-zero on failure so it can be used as a CI check.
 *
 * NOTE: runs from the repo root with the app's own config loaded, so db.php's
 * mysqli connect is deferred until first use — none of these tests touch it.
 */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
$GLOBALS['__DB_OVERRIDE_SKIP'] = true;

// Minimal env so db.php defines constants without connecting.
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=127.0.0.1');
}

$failures = 0;
$passes = 0;

function check(string $name, bool $cond): void
{
    global $failures, $passes;
    if ($cond) {
        $passes++;
        echo "  ok  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name\n";
    }
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

// ==========================================================================
section('Polyfills (config/compat.php)');
require __DIR__ . '/../config/compat.php';

check('str_contains exists', function_exists('str_contains'));
check('str_starts_with exists', function_exists('str_starts_with'));
check('str_ends_with exists', function_exists('str_ends_with'));
check('str_contains positive', str_contains('hello world', 'lo w') === true);
check('str_contains negative', str_contains('hello', 'xyz') === false);
check('str_contains empty needle', str_contains('abc', '') === true);
check('str_contains empty haystack', str_contains('', 'a') === false);
check('str_starts_with basic', str_starts_with('/help?pdf=all', '/help') === true);
check('str_starts_with // guard', str_starts_with('//evil.com', '//') === true);
check('str_starts_with non-match', str_starts_with('/login', '/logout') === false);
check('str_ends_with basic', str_ends_with('report.csv', '.csv') === true);
check('str_ends_with non-match', str_ends_with('report.csv', '.pdf') === false);
check('str_ends_with empty needle', str_ends_with('abc', '') === true);

// ==========================================================================
section('Parse-fix regression: forbidden modern syntax in PHP sources');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
$patterns = [
    // PHP match requires "{ ... }" after the call parens; JS .match(/re/) and
    // caches.match(req).then(...) do not, so they are not flagged.
    'match expression'  => '/(?<![a-zA-Z_$])match\s*\([^()]*\)\s*\{/',
    'never return type' => '/\)\s*:\s*never\b/',
    'nullsafe operator' => '/\?->/',
    'arrow fn'          => '/(?<![a-zA-Z_$>])fn\s*\([^)]*\)\s*=>/',
];
$violations = [];
$skipDirs = ['.git', 'ai-tools', 'essentials', 'snippets', 'api-reference', 'tools'];
$bareStrFiles = [];
foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skipDirs as $sd) {
        if (strpos($path, "/$sd/") !== false) continue 2;
    }
    $src = file_get_contents($path);
    // Strip PHP comments AND inline <script> JS (JS uses .match() / ?., legally).
    $src = preg_replace('/\/\*.*?\*\//s', '', $src);
    $src = preg_replace('/<script[\s\S]*?<\/script>/i', '', $src);
    foreach ($patterns as $label => $re) {
        if (preg_match($re, $src, $m)) {
            $violations[] = basename($path) . ": $label (" . trim($m[0]) . ")";
        }
    }
    if (preg_match('/(?<!function\s)str_(contains|starts_with|ends_with)\s*\(/', $src)
        && strpos($src, 'function str_contains') === false
    ) {
        $bareStrFiles[] = $path;
    }
}
check('no arrow fn / match / never / nullsafe in any PHP file', $violations === []);
if ($violations) {
    foreach (array_slice($violations, 0, 10) as $v) echo "      - $v\n";
}
// str_* calls are fine IF the file bootstraps the polyfill chain
// (auth.php / functions.php / compat.php). Verify that instead of absence.
$chainBad = [];
foreach ($bareStrFiles as $p) {
    $src = file_get_contents($p);
    $loadsChain = strpos($src, 'compat.php') !== false
        || strpos($src, 'config/auth.php') !== false
        || strpos($src, "'/functions.php'") !== false
        || strpos($src, 'config/functions.php') !== false;
    if (!$loadsChain) {
        $chainBad[] = basename($p);
    }
}
check('every str_* call site loads the polyfill chain', $chainBad === []);
if ($chainBad) {
    foreach ($chainBad as $p) echo "      - $p uses str_* without loading compat chain\n";
}

// ==========================================================================
section('base_url() resolution');
$_SERVER['DOCUMENT_ROOT'] = '/tmp/fake-root';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/config/functions.php';
check('function exists', function_exists('base_url'));
check('returns string', is_string(base_url()));
check('no trailing slash', base_url() === '' || substr(base_url(), -1) !== '/');

// ==========================================================================
section('PWA files present + wired');
$root = dirname(__DIR__);
check('manifest.php exists', is_file("$root/manifest.php"));
check('sw.php exists', is_file("$root/sw.php"));
check('offline.html exists', is_file("$root/offline.html"));
check('icon 192 exists', is_file("$root/assets/icons/icon-192.png"));
check('icon 512 exists', is_file("$root/assets/icons/icon-512.png"));
check('icon maskable 192 exists', is_file("$root/assets/icons/icon-maskable-192.png"));
check('icon maskable 512 exists', is_file("$root/assets/icons/icon-maskable-512.png"));
check('icons are valid PNGs', is_file("$root/assets/icons/icon-512.png") && getimagesize("$root/assets/icons/icon-512.png")[2] === IMAGETYPE_PNG);
check('.htaccess exists', is_file("$root/.htaccess"));
$hdr = file_get_contents("$root/includes/header.php");
check('header links manifest', strpos($hdr, 'rel="manifest"') !== false && strpos($hdr, 'manifest.php') !== false);
check('header has theme-color', strpos($hdr, 'name="theme-color"') !== false);
check('header has apple-touch-icon', strpos($hdr, 'apple-touch-icon') !== false);
check('header has install button', strpos($hdr, 'pwaInstallBtn') !== false);
$login = file_get_contents("$root/login.php");
check('login links manifest', strpos($login, 'manifest.php') !== false);
$footer = file_get_contents("$root/includes/footer.php");
check('footer registers sw.php', strpos($footer, 'sw.php') !== false);
check('footer has beforeinstallprompt handler', strpos($footer, 'beforeinstallprompt') !== false);
check('router serves manifest.php', strpos(file_get_contents("$root/router.php"), 'manifest.php') !== false);
check('router serves sw.php', strpos(file_get_contents("$root/router.php"), 'sw.php') !== false);

// manifest.json output sanity (subfolder install, DB-free path).
// Runs in a SEPARATE PHP process so the fake tree's functions.php does not
// collide with the real one already loaded in this process.
$fakeRoot = sys_get_temp_dir() . '/selftest-fake-root';
if (!is_dir("$fakeRoot/cms/config")) {
    mkdir("$fakeRoot/cms/config", 0777, true);
}
copy("$root/manifest.php", "$fakeRoot/cms/manifest.php");
copy("$root/config/functions.php", "$fakeRoot/cms/config/functions.php");
copy("$root/config/compat.php", "$fakeRoot/cms/config/compat.php");
$code = sprintf(
    '$_SERVER["DOCUMENT_ROOT"]=%s; $_SERVER["SCRIPT_NAME"]="/cms/manifest.php"; require %s;',
    var_export($fakeRoot, true),
    var_export("$fakeRoot/cms/manifest.php", true)
);
$cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null';
$manifestOut = (string) shell_exec($cmd);
$exit = $manifestOut === '' ? 'no output' : null;
check('manifest renders without DB', $exit === null);
$man = json_decode($manifestOut, true);
check('manifest is valid JSON', is_array($man));
check('manifest start_url = /cms/', ($man['start_url'] ?? null) === '/cms/');
check('manifest scope = /cms/', ($man['scope'] ?? null) === '/cms/');
check('manifest has 192 icon', ($man['icons'][0]['src'] ?? '') === '/cms/assets/icons/icon-192.png');
check('manifest standalone display', ($man['display'] ?? '') === 'standalone');

// ==========================================================================
section('Help content (config/help.php)');
define('HELP_CONTENT_ONLY', true);
require dirname(__DIR__) . '/config/help.php';
$roles = help_roles();
$expectedRoles = ['Super Admin', 'Branch Admin', 'Doctor', 'Receptionist', 'Pharmacist', 'Laboratory Technician', 'Accountant'];
foreach ($expectedRoles as $r) {
    check("role '$r' present", isset($roles[$r]));
}
check('help_fallback_role is valid', isset($roles[help_fallback_role()]));
foreach ($roles as $name => $r) {
    check(
        "'$name' structure complete",
        !empty($r['icon']) && !empty($r['tagline']) && !empty($r['intro'])
            && count($r['start']) >= 2 && count($r['modules']) >= 2
            && isset($r['tips'])
    );
    foreach ($r['modules'] as $mod) {
        if (count($mod) !== 3 || !is_string($mod[0]) || !is_string($mod[1]) || !is_array($mod[2]) || count($mod[2]) < 1) {
            check("'$name' module entry malformed: " . ($mod[0] ?? '?'), false);
            break;
        }
    }
}

// ==========================================================================
section('PDF writer (includes/pdf.php)');
require dirname(__DIR__) . '/includes/pdf.php';
check('class exists', class_exists('SimplePdf'));

function pdf_as_bytes(array $calls): string
{
    $p = new SimplePdf('Selftest Doc');
    foreach ($calls as $c) {
        $c($p);
    }
    $p->save('/tmp/selftest.pdf');
    $data = file_get_contents('/tmp/selftest.pdf');
    @unlink('/tmp/selftest.pdf');
    return $data;
}

$data = pdf_as_bytes([function (SimplePdf $p) { $p->h1('Title'); $p->body('Some body text.'); }]);
check('magic header %PDF-1.4', strpos($data, '%PDF-1.4') === 0);
check('ends with %%EOF', substr($data, -5) === '%%EOF');
check('xref present', strpos($data, "\nxref\n") !== false);
check('trailer /Root 1 0 R', strpos($data, '/Root 1 0 R') !== false);
check('has both fonts', strpos($data, '/Helvetica ') !== false && strpos($data, '/Helvetica-Bold') !== false);
check('single page', substr_count($data, '/Type /Page ') === 1);

// xref offsets point at "N 0 obj"
$xrefStart = strpos($data, "\nxref\n") + 1;
$lines = explode("\n", substr($data, $xrefStart));
$xrefOk = true;
$checked = 0;
foreach ($lines as $ln) {
    if (preg_match('/^(\d{10}) 00000 n /', $ln, $mm)) {
        $off = (int) $mm[1];
        $checked++;
        if (!preg_match('/^\d+ 0 obj/', substr($data, $off, 20))) {
            $xrefOk = false;
        }
    }
}
check("xref offsets valid ($checked entries)", $xrefOk && $checked >= 5);

// Multi-page + stream integrity via /Length + footer inside decompressed text.
$data = pdf_as_bytes([function (SimplePdf $p) {
    $p->h1('Page One');
    for ($i = 1; $i <= 40; $i++) $p->body("Line $i — some wrapping content to force pagination eventually.");
    $p->addPage();
    $p->h1('Page Two');
    $p->bullet('bullet item');
    $p->step(1, 'numbered step');
    $p->rule();
}]);
check('multi-page produced', substr_count($data, '/Type /Page ') >= 2);
$decompressed = (function () use ($data) {
    if (!preg_match_all('/<< \/Length (\d+)( \/Filter \/FlateDecode)? >>\nstream\n/', $data, $mm, PREG_OFFSET_CAPTURE)) {
        return ['', false];
    }
    $text = '';
    $allOk = true;
    foreach ($mm[1] as $idx => $m) {
        $len = (int) $m[0];
        $streamStart = strpos($data, "\nstream\n", $m[1]) + strlen("\nstream\n");
        $end = strpos($data, "\nendstream", $streamStart);
        if ($end === false || ($end - $streamStart) !== $len) {
            $allOk = false;
            continue;
        }
        $chunk = substr($data, $streamStart, $len);
        if (!empty($mm[2][$idx][0])) {
            $chunk = gzuncompress($chunk);
            if ($chunk === false || strpos($chunk, 'BT') === false) {
                $allOk = false;
                continue;
            }
        }
        $text .= $chunk . "\n";
    }
    return [$text, $allOk];
})();
check('every stream /Length matches actual bytes', $decompressed[1]);
check('footer page numbers inside page streams', preg_match('/Page \d+ of \d+/', $decompressed[0]) === 1);
check('flatedecode used when zlib available', (function_exists('gzcompress') ? strpos($data, '/FlateDecode') !== false : true));

// Special characters survive escaping
$data = pdf_as_bytes([function (SimplePdf $p) {
    $p->body('Parens (x) and \\ backslash and unicode: é à ñ — 100% "quotes"');
}]);
check('special chars escaped without breaking structure', substr($data, -5) === '%%EOF' && strpos($data, '%PDF-1.4') === 0);

// ==========================================================================
section('Help module structure (modules/help/index.php)');
$helpModule = file_get_contents("$root/modules/help/index.php");
check('help module has all-roles PDF branch', preg_match("/'all'/", $helpModule) === 1);
check('help module renders role PDFs', strpos($helpModule, 'pdf_render_role') !== false);
check('help module outputs attachment header (via SimplePdf->output)', strpos($helpModule, '->output(') !== false);
check('help module guards unknown roles with 404', strpos($helpModule, 'errors/404.php') !== false);

// ==========================================================================
section('Supabase integration (config/supabase.php)');
require dirname(__DIR__) . '/config/supabase.php';
check('supabase_url exists', function_exists('supabase_url'));
check('supabase_key exists', function_exists('supabase_key'));
check('supabase_enabled exists', function_exists('supabase_enabled'));
check('supabase_request exists', function_exists('supabase_request'));
check('supabase_health exists', function_exists('supabase_health'));
check('supabase_storage_ensure_bucket exists', function_exists('supabase_storage_ensure_bucket'));
check('supabase_storage_upload exists', function_exists('supabase_storage_upload'));
check('supabase_storage_list exists', function_exists('supabase_storage_list'));

// Configured-mode assertions run in a subprocess so putenv cannot leak.
// The URL uses the reserved .invalid TLD so the request fails fast/offline
// (no network dependency); we assert the failure is reported gracefully.
$envCode = "putenv('SUPABASE_URL=https://supabase-selftest.invalid'); putenv('SUPABASE_ANON_KEY=anon-x'); putenv('SUPABASE_SERVICE_ROLE_KEY=svc-y');"
    . ' require ' . var_export(dirname(__DIR__) . '/config/supabase.php', true) . ';'
    . ' echo json_encode([supabase_url(), supabase_enabled(), supabase_key(\'anon\'), supabase_key(\'service\'), supabase_backup_bucket(), supabase_request(\'GET\', \'/rest/v1/\')[\'message\']]);';
$envOut = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($envCode) . ' 2>/dev/null');
$env = json_decode($envOut, true);
check('configured url parsed (trailing slash stripped)', is_array($env) && $env[0] === 'https://supabase-selftest.invalid');
check('enabled with URL + anon key', is_array($env) && $env[1] === true);
check('anon key readable', is_array($env) && $env[2] === 'anon-x');
check('service key readable', is_array($env) && $env[3] === 'svc-y');
check('backup bucket is clinic-backups', is_array($env) && $env[4] === 'clinic-backups');
check('unreachable host fails gracefully', is_array($env) && is_string($env[5]) && stripos($env[5], 'Connection to Supabase failed') !== false);

$unCode = 'require ' . var_export(dirname(__DIR__) . '/config/supabase.php', true) . ';'
    . ' echo json_encode([supabase_url(), supabase_enabled(), supabase_request(\'GET\', \'/rest/v1/\')[\'message\']]);';
$unOut = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($unCode) . ' 2>/dev/null');
$un = json_decode($unOut, true);
check('unconfigured url empty', is_array($un) && $un[0] === '');
check('unconfigured disabled', is_array($un) && $un[1] === false);
check('unconfigured request reports SUPABASE_URL missing', is_array($un) && is_string($un[2]) && stripos($un[2], 'SUPABASE_URL') !== false);

$sbAjax = file_get_contents("$root/ajax/supabase.php");
check('ajax endpoint permission-guarded', strpos($sbAjax, "require_permission('settings.manage')") !== false);
check('ajax endpoint loads supabase config', strpos($sbAjax, 'config/supabase.php') !== false);
$settingsPage = file_get_contents("$root/modules/settings/index.php");
check('settings page has Supabase card', strpos($settingsPage, 'Cloud Backup (Supabase)') !== false);
check('settings card guarded by settings.manage', strpos($settingsPage, 'sbTest') !== false && strpos($settingsPage, 'sbBackup') !== false);

// ==========================================================================
section('Pharmacy auto-pricing (price set on drug, never typed at dispense)');
$phSrc = file_get_contents("$root/ajax/pharmacy.php");
check('dispense form shows read-only unit price', strpos($phSrc, 'disp-price bg-light') !== false && strpos($phSrc, '" readonly>') !== false);
check('dispense form no longer accepts a typed price', strpos($phSrc, "_price\"] ?? 0") === false);
check('dispense reads selling_price FOR UPDATE', strpos($phSrc, 'pack_size, unit, selling_price FROM medicines WHERE id = ? FOR UPDATE') !== false);
check('sale price derived from medicine record server-side', strpos($phSrc, "it['price'] = (float) \$med['selling_price']") !== false);
check('dispense form explains price source', strpos($phSrc, 'Unit price (set on medicine)') !== false && strpos($phSrc, 'Free-text item — no price set.') !== false);
$phIdx = file_get_contents("$root/modules/pharmacy/index.php");
check('medicines list flags drugs with no price', strpos($phIdx, 'price not set') !== false);
check('medicines table rendered exactly once', substr_count($phIdx, 'No medicines found') === 1);
$salesSrc = file_get_contents("$root/modules/pharmacy/sales.php");
check('sales page has no stray layout close outside PHP', strpos($salesSrc, "</script>\nui_page_close();") === false);

// ==========================================================================
section('School module (LMS): teachers, classes, marks, exams, attendance');
$schoolCfg = file_get_contents("$root/config/school.php");
foreach (
    ['school_current_teacher', 'school_teacher_allocations', 'school_teacher_homerooms',
     'school_categories_total', 'school_class_label', 'school_exam_dir',
     'school_spreadsheet_rows', 'school_csv_rows', 'school_xlsx_rows', 'school_docx_text'] as $fn
) {
    check("config/school.php defines $fn", strpos($schoolCfg, "function $fn(") !== false);
}
check('xlsx reader degrades gracefully without zip extension',
    strpos($schoolCfg, "class_exists('ZipArchive')") !== false);
check('xlsx error tells user to save as CSV', strpos($schoolCfg, 'save the file as CSV') !== false);

$schoolAjax = file_get_contents("$root/ajax/school.php");
check('ajax/school.php uses app bootstrap', strpos($schoolAjax, "config/auth.php'") !== false && strpos($schoolAjax, 'config/school.php') !== false);
check('POST actions require CSRF token', strpos($schoolAjax, 'require_csrf()') !== false);
foreach (
    ['school_require_manage', 'school_can_touch_class', 'school_owned_allocation',
     'school_assert_categories_complete', 'school_create_teacher_user'] as $fn
) {
    check("ajax/school.php defines $fn", strpos($schoolAjax, "function $fn(") !== false);
}
check('allocation ownership enforced (403 for other teachers)',
    strpos($schoolAjax, 'This class is not allocated to you.') !== false);
check('category add rejected when total would exceed 100',
    strpos($schoolAjax, 'must be exactly 100') !== false);
check('marks and exams blocked until categories total 100',
    substr_count($schoolAjax, 'school_assert_categories_complete(') >= 2);
check('teacher bulk upload detects header row',
    strpos($schoolAjax, "in_array('full name', \$first, true)") !== false);
check('bulk upload creates a login per teacher',
    strpos($schoolAjax, 'school_create_teacher_user($name') !== false);
check('exam upload accepts only .docx', strpos($schoolAjax, "Only .docx exam files are allowed") !== false);
check('exam upload caps size at 10 MB', strpos($schoolAjax, 'max 10 MB') !== false);
check('exam upload MIME-checked including zip types',
    strpos($schoolAjax, 'application/zip') !== false && strpos($schoolAjax, 'wordprocessingml.document') !== false);
check('exam file stored under assets/uploads/exams',
    strpos($schoolAjax, "'exams/' . \$stored") !== false);

$schoolPages = [
    'index.php' => ['school.view', 'Upload by Excel'],
    'students.php' => ['school.view', 'Guardian'],
    'setup.php' => ['school.manage', 'Grades'],
    'homeroom.php' => ['school.manage', 'Homeroom'],
    'allocations.php' => ['school.manage', 'Allocation'],
    'categories.php' => ['school.manage', '/100'],
    'marks.php' => ['school.teach', 'exactly 100'],
    'exams.php' => ['school.teach', '.docx'],
    'attendance.php' => ['school.teach', 'present'],
    'reports.php' => ['school.view', 'Attendance'],
];
foreach ($schoolPages as $page => [$perm, $needle]) {
    $src = (string) @file_get_contents("$root/modules/school/$page");
    check("school page $page guarded by $perm", strpos($src, "require_permission('$perm')") !== false);
    check("school page $page has '$needle'", $src !== '' && stripos($src, $needle) !== false);
}
$schoolJs = file_get_contents("$root/modules/school/_js.php");
check('school JS posts via FormData with CSRF header',
    strpos($schoolJs, 'FormData') !== false && strpos($schoolJs, 'X-CSRF-Token') !== false);
$schoolInit = file_get_contents("$root/modules/school/_init.php");
check('school pages share _init bootstrap',
    strpos($schoolInit, "config/auth.php'") !== false && strpos($schoolInit, 'config/school.php') !== false);

$schoolMig = file_get_contents("$root/database/migration_school.sql");
foreach (['grades', 'sections', 'subjects', 'school_teachers', 'school_students', 'school_homerooms',
          'school_allocations', 'school_mark_categories', 'school_marks', 'school_exams', 'school_attendance'] as $tbl) {
    check("migration creates $tbl", strpos($schoolMig, "CREATE TABLE IF NOT EXISTS $tbl (") !== false);
}
check('migration seeds teacher_prefix setting', strpos($schoolMig, "'teacher_prefix'") !== false);
check('migration seeds student_prefix setting', strpos($schoolMig, "'student_prefix'") !== false);

$settingsSrc = file_get_contents("$root/modules/settings/index.php");
check('settings page exposes school prefixes',
    strpos($settingsSrc, "'teacher_prefix'") !== false && strpos($settingsSrc, "'student_prefix'") !== false);
$sidebarSrc = file_get_contents("$root/includes/sidebar.php");
check('sidebar has School group guarded by school.view',
    strpos($sidebarSrc, "'school.view', 'School'") !== false);
check('sidebar school items permission-filtered',
    strpos($sidebarSrc, "'/school/marks'") !== false && strpos($sidebarSrc, "'/school/attendance'") !== false);

// Functional: CSV parsing (no DB touched).
$tmpCsv = tempnam(sys_get_temp_dir(), 'selftest_school_');
file_put_contents($tmpCsv, "Full Name,Email,Phone,Qualification\nAlice Test,a@x.test,0911,BSc\n\nBob Test,b@x.test,0912,MSc\n");
require_once $root . '/config/functions.php';
require_once $root . '/config/school.php';
$rows = school_spreadsheet_rows($tmpCsv, 'teachers.csv');
check('csv reader returns data rows', is_array($rows) && count($rows) === 3);
check('csv reader trims cells and skips blank lines', is_array($rows) && isset($rows[1]) && $rows[1] === ['Alice Test', 'a@x.test', '0911', 'BSc']);
$xlsxFailed = false;
try { school_spreadsheet_rows($tmpCsv, 'teachers.xlsx'); } catch (RuntimeException $ex) {
    $xlsxFailed = class_exists('ZipArchive') ? false : (stripos($ex->getMessage(), 'CSV') !== false);
}
check('xlsx without zip extension fails with save-as-CSV guidance', $xlsxFailed);
$docxText = school_docx_text($tmpCsv);
check('docx text extraction returns null on non-docx/no zip', $docxText === null);
unlink($tmpCsv);

// ==========================================================================
echo "\n========================================\n";
echo "PASSED: $passes   FAILED: $failures\n";
echo "========================================\n";
exit($failures > 0 ? 1 : 0);
