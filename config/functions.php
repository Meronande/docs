<?php
/**
 * Global helpers: escaping, codes, numbers, uploads, dates, validation.
 */

declare(strict_types=1);

/** HTML-escape (XSS protection). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Friendly fallback for empty values ("N/A", "0" kept where meaningful via $keepZero). */
function or_na($value, string $fallback = 'N/A'): string
{
    if ($value === null) return $fallback;
    $v = trim((string) $value);
    return ($v === '' || strcasecmp($v, 'null') === 0 || strcasecmp($v, 'undefined') === 0) ? $fallback : (string) $value;
}

/** Format money using the currency symbol from settings. */
function money($amount): string
{
    return setting('currency_symbol', 'Br') . ' ' . number_format((float) ($amount ?? 0), 2);
}

/** Raw numeric money (for inputs/exports). */
function money_raw($amount): string
{
    return number_format((float) ($amount ?? 0), 2, '.', '');
}

/** Format date according to system settings. */
function fmt_date($date, bool $withTime = false): string
{
    if (empty($date) || $date === '0000-00-00') return 'N/A';
    $ts = strtotime((string) $date);
    if ($ts === false) return 'N/A';
    $format = setting('date_format', 'd/m/Y');
    return date($format . ($withTime ? ' H:i' : ''), $ts);
}

/** Current datetime string (server/db time). */
function now_dt(): string
{
    return date('Y-m-d H:i:s');
}

/** Generate the next sequential code for a table, e.g. PAT-000007. */
function generate_code(string $prefixSetting, string $table, string $column, int $pad = 6): string
{
    $prefix = setting($prefixSetting, substr(strtoupper($prefixSetting), 0, 3));
    $max = db_fetch_value("SELECT MAX(id) FROM `$table`");
    $next = ((int) $max) + 1;
    // Guarantee uniqueness even after deletes.
    do {
        $code = $prefix . '-' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        $exists = db_fetch_value("SELECT id FROM `$table` WHERE `$column` = ? LIMIT 1", [$code]);
        $next++;
    } while ($exists !== null);
    return $code;
}

/** Redirect and exit. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** JSON response helper for AJAX endpoints. */
function json_response($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Get a POST value (trimmed) or default. */
function post(string $key, $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/** Get a GET value (trimmed) or default. */
function get(string $key, $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/** Validate an email address. */
function valid_email(string $email): bool
{
    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Validate a date string (Y-m-d). */
function valid_date(string $date): bool
{
    if ($date === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/**
 * Secure file upload.
 * Returns relative path (assets/uploads/...) or null. Throws on invalid file.
 */
function upload_image(array $file, string $subdir = 'patients', int $maxBytes = 4194304): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int) $file['error'] . ').');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File is too large (max ' . round($maxBytes / 1048576) . ' MB).');
    }
    // Validate MIME by content, not by name.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP or PDF files are allowed.');
    }
    $subdir = preg_replace('/[^a-z0-9_\-]/', '', strtolower($subdir)) ?: 'misc';
    $dir = dirname(__DIR__) . '/assets/uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    // Never trust the original filename.
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return 'assets/uploads/' . $subdir . '/' . $name;
}

/** Delete an uploaded file if it lives under assets/uploads. */
function delete_upload(?string $relativePath): void
{
    if (!$relativePath) return;
    $base = realpath(dirname(__DIR__) . '/assets/uploads');
    $target = realpath(dirname(__DIR__) . '/' . $relativePath);
    if ($base && $target && str_starts_with($target, $base) && is_file($target)) {
        @unlink($target);
    }
}

/** Status badge HTML (active/inactive master records). */
function status_badge($status): string
{
    $active = (int) $status === 1;
    return '<span class="badge ' . ($active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary') . '">'
        . ($active ? 'Active' : 'Inactive') . '</span>';
}

/** Build a query-string URL preserving current filters. */
function qs_url(string $page, array $extra = []): string
{
    $params = array_merge($_GET, $extra);
    unset($params['page_no']);
    if ($page) {
        $params['page'] = $page;
    }
    return '?' . http_build_query($params);
}

/** Simple paginator. Returns [offset, limit] and renders nothing itself. */
function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $current = min(max(1, (int) get('page_no', '1')), $pages);
    $GLOBALS['paginator'] = ['total' => $total, 'pages' => $pages, 'current' => $current, 'per_page' => $perPage];
    return [($current - 1) * $perPage, $perPage];
}

/** Render pagination links (Bootstrap). */
function pagination_links(): string
{
    $p = $GLOBALS['paginator'] ?? null;
    if (!$p || $p['pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0 justify-content-center">';
    $url = fn(int $n) => e(qs_url('', ['page_no' => $n]));
    $html .= '<li class="page-item ' . ($p['current'] <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . $url(max(1, $p['current'] - 1)) . '">&laquo;</a></li>';
    $start = max(1, $p['current'] - 2);
    $end = min($p['pages'], $p['current'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item ' . ($i === $p['current'] ? 'active' : '') . '"><a class="page-link" href="' . $url($i) . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($p['current'] >= $p['pages'] ? 'disabled' : '') . '"><a class="page-link" href="' . $url(min($p['pages'], $p['current'] + 1)) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

/** Result count label for listings. */
function result_count_label(): string
{
    $p = $GLOBALS['paginator'] ?? null;
    if (!$p) return '';
    return 'Showing ' . $p['total'] . ' record' . ($p['total'] === 1 ? '' : 's');
}

/** Compute age from date of birth. */
function age_from_dob(?string $dob): ?int
{
    if (!$dob) return null;
    $d = date_create($dob);
    if (!$d) return null;
    return (int) date_diff($d, date_create('today'))->y;
}

/** Session flash message helpers. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Human-friendly enum label. */
function label_case(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', $value));
}
