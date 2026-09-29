<?php
/**
 * Authentication, session security, permissions, audit log, notifications.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// Session security
// ---------------------------------------------------------------------

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CLINICSESSID');
    session_start();
}

// ---------------------------------------------------------------------
// Settings cache
// ---------------------------------------------------------------------

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_fetch_all("SELECT setting_key, setting_value FROM settings") as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            error_log('[SETTINGS] ' . $e->getMessage());
        }
    }
    return $cache[$key] ?? $default;
}

// ---------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        // Late caller (e.g. output already started elsewhere): fall back to a
        // per-request token so we never emit an empty field.
        static $fallback = null;
        if ($fallback === null) {
            $fallback = hash('sha256', session_id() ?: (microtime() . random_bytes(8)));
        }
        return $fallback;
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

/** Require a valid CSRF token for POST/AJAX; dies with 403 otherwise. */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'Invalid security token. Please refresh the page.'], 403);
        }
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

// ---------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    static $loaded = false;
    if (!$loaded) {
        $user = db_fetch_one(
            "SELECT u.*, r.role_name, r.is_system AS role_is_system
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.status = 1 LIMIT 1",
            [(int) $_SESSION['user_id']], 'i'
        ) ?: null;
        $loaded = true;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Attempt login with throttling; returns error message or null on success. */
function attempt_login(string $username, string $password): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $maxAttempts = 5;
    $windowMinutes = 15;

    $recent = (int) db_fetch_value(
        "SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$username, $ip, $windowMinutes], 'ssi'
    );
    if ($recent >= $maxAttempts) {
        return 'Too many failed attempts. Try again in ' . $windowMinutes . ' minutes.';
    }

    $user = db_fetch_one(
        "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id
         WHERE (u.username = ? OR u.email = ?) AND u.status = 1 LIMIT 1",
        [$username, $username], 'ss'
    );
    if (!$user || !password_verify($password, $user['password'])) {
        db_execute(
            "INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)",
            [$username, $ip], 'ss'
        );
        // Opportunistic cleanup.
        db_execute("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        return 'Invalid username or password.';
    }

    // Successful login.
    db_execute("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?", [$username, $ip], 'ss');
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    db_execute("UPDATE users SET last_login = NOW() WHERE id = ?", [(int) $user['id']], 'i');

    audit_log('login', 'Auth', (int) $user['id'], 'User logged in');
    return null;
}

/** Enforce authenticated access + session timeout. */
function require_login(): void
{
    $timeout = (int) setting('session_timeout_minutes', '60');
    if (is_logged_in()) {
        if ($timeout > 0 && isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $timeout * 60) {
            logout_user();
            redirect('/login.php?timeout=1');
        }
        $_SESSION['last_activity'] = time();
        return;
    }
    $target = $_SERVER['REQUEST_URI'] ?? '/';
    redirect('/login.php?returnTo=' . urlencode($target));
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit_log('logout', 'Auth', (int) current_user()['id'], 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------
// Permissions & branch scoping
// ---------------------------------------------------------------------

function user_permissions(int $userId): array
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $rows = db_fetch_all(
            "SELECT p.permission_key FROM role_permissions rp
             JOIN users u ON u.role_id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ?",
            [$userId], 'i'
        );
        $cache[$userId] = array_column($rows, 'permission_key');
    }
    return $cache[$userId];
}

function has_permission(string $key): bool
{
    $user = current_user();
    if (!$user) return false;
    return in_array($key, user_permissions((int) $user['id']), true);
}

/** Page guard: verifies permission and redirects (or 403s for AJAX). */
function require_permission(string $key): void
{
    require_login();
    require_csrf_soft();
    if (!has_permission($key)) {
        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
        http_response_code(403);
        require dirname(__DIR__) . '/errors/403.php';
        exit;
    }
}

/** CSRF check for non-POST page loads (no-op; POSTs go through require_csrf). */
function require_csrf_soft(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
    }
}

/** Can the user see data across all branches? */
function sees_all_branches(): bool
{
    $user = current_user();
    if (!$user) return false;
    return has_permission('branches.all') || (int) ($user['see_all_branches'] ?? 0) === 1;
}

/** Branch filter helper: returns branch id when scoped, null when "all". */
function scope_branch_id(): ?int
{
    if (sees_all_branches()) {
        $sel = get('branch_id', '');
        return $sel !== '' ? (int) $sel : null;
    }
    return current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null;
}

/** "AND branch_id = ?" fragment + params for branch-scoped queries. */
function branch_condition(string $alias = '', ?int $branchId = null): array
{
    $branchId = $branchId ?? scope_branch_id();
    if ($branchId === null) {
        return ['', []];
    }
    $col = ($alias ? $alias . '.' : '') . 'branch_id';
    return [" AND $col = ?", [$branchId]];
}

/** Visible branches for dropdowns/filters. */
function visible_branches(): array
{
    if (sees_all_branches()) {
        return db_fetch_all("SELECT id, branch_name, branch_code FROM branches WHERE status = 1 ORDER BY branch_name");
    }
    $bid = current_user()['branch_id'];
    return $bid
        ? db_fetch_all("SELECT id, branch_name, branch_code FROM branches WHERE id = ? AND status = 1", [(int) $bid], 'i')
        : [];
}

/** Enforce that a record belongs to the user's branch (unless all-branch access). */
function assert_branch_access(?int $recordBranchId): void
{
    if ($recordBranchId === null || sees_all_branches()) return;
    if ((int) current_user()['branch_id'] !== null && $recordBranchId !== (int) current_user()['branch_id']) {
        http_response_code(403);
        require dirname(__DIR__) . '/errors/403.php';
        exit;
    }
}

// ---------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------

function audit_log(string $action, string $module, ?int $recordId, string $description): void
{
    $user = current_user();
    try {
        db_execute(
            "INSERT INTO audit_logs (user_id, branch_id, action, module, record_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $user['id'] ?? null,
                $user['branch_id'] ?? null,
                $action,
                $module,
                $recordId,
                mb_substr($description, 0, 500),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ],
            null
        );
    } catch (Throwable $e) {
        error_log('[AUDIT] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------

/**
 * Create a notification. $target = ['user_id'=>?,'role_id'=>?,'permission_key'=>?,'branch_id'=>?]
 * branch_id resolved from record context when omitted.
 */
function notify(array $target, string $title, string $message, string $type = 'info', ?string $refType = null, ?int $refId = null, ?string $url = null): void
{
    try {
        db_execute(
            "INSERT INTO notifications (user_id, branch_id, role_id, permission_key, title, message, notification_type, reference_type, reference_id, url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $target['user_id'] ?? null,
                $target['branch_id'] ?? null,
                $target['role_id'] ?? null,
                $target['permission_key'] ?? null,
                mb_substr($title, 0, 150),
                $message,
                $type,
                $refType,
                $refId,
                $url,
            ],
            null
        );
    } catch (Throwable $e) {
        error_log('[NOTIFY] ' . $e->getMessage());
    }
}

/** Convenience: notify all users with a permission in a branch (null branch = all). */
function notify_permission(string $permissionKey, string $title, string $message, string $type, ?int $branchId, string $refType, ?int $refId, ?string $url = null): void
{
    notify(
        ['permission_key' => $permissionKey, 'branch_id' => $branchId],
        $title, $message, $type, $refType, $refId, $url
    );
}

/** Mark notifications read for the current user (specific ids or all visible). */
function mark_notifications_read(array $ids = []): void
{
    $user = current_user();
    if (!$user) return;
    $uid = (int) $user['id'];
    $bid = $user['branch_id'];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        db_execute(
            "INSERT IGNORE INTO notification_reads (notification_id, user_id)
             SELECT n.id, ? FROM notifications n
             WHERE n.id IN ($in)
               AND (n.user_id = ? OR (n.user_id IS NULL AND (n.branch_id IS NULL OR n.branch_id = ? OR ? IS NULL)))",
            array_merge([$uid], $ids, [$uid, $bid, $bid])
        );
    } else {
        db_execute(
            "INSERT IGNORE INTO notification_reads (notification_id, user_id)
             SELECT n.id, ? FROM notifications n
             WHERE (n.user_id = ? OR (n.user_id IS NULL AND (n.branch_id IS NULL OR n.branch_id = ? OR ? IS NULL)))
               AND NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?)",
            [$uid, $uid, $bid, $bid, $uid],
            'iiiii'
        );
    }
}

/** Visible notifications for the current user (latest first). */
function notifications_for_me(int $limit = 10): array
{
    $user = current_user();
    if (!$user) return [];
    $uid = (int) $user['id'];
    $bid = $user['branch_id'];
    return db_fetch_all(
        "SELECT n.*,
                (n.user_id = ? OR EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?)) AS is_read
         FROM notifications n
         WHERE n.user_id = ?
            OR (n.user_id IS NULL AND (n.branch_id IS NULL OR n.branch_id = ? OR ? IS NULL))
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT " . (int) $limit,
        [$uid, $uid, $uid, $bid, $bid],
        null
    );
}

/** Count unread notifications for the badge (read = row in notification_reads). */
function unread_count(): int
{
    $user = current_user();
    if (!$user) return 0;
    $uid = (int) $user['id'];
    $bid = $user['branch_id'];
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM notifications n
         WHERE (n.user_id = ? OR (n.user_id IS NULL AND (n.branch_id IS NULL OR n.branch_id = ? OR ? IS NULL)))
           AND NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?)",
        [$uid, $bid, $bid, $uid],
        'iiii'
    );
}
