<?php
/**
 * Database connection (MySQLi, exceptions, utf8mb4).
 * Every query in the application uses prepared statements through this handle.
 */

declare(strict_types=1);

// Dev mode: detailed errors. Production: set to false.
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true);
}

if (APP_DEBUG) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    mysqli_report(MYSQLI_REPORT_OFF);
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('log_errors', '1');
}

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'clinic_db');
define('DB_USER', getenv('DB_USER') ?: 'clinic');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Clinic@2024');

/**
 * Get the shared MySQLi connection.
 */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        // Reconnect automatically if the server went away (long-running dev sessions).
        if ($conn->ping()) {
            return $conn;
        }
        $conn->close();
    }
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);
        $conn->set_charset('utf8mb4');
        $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    } catch (mysqli_sql_exception $e) {
        error_log('[DB] ' . $e->getMessage());
        if (APP_DEBUG) {
            die('<div style="font-family:monospace;padding:20px;">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;">Something went wrong. Please try again later.</div>');
    }
    return $conn;
}

/**
 * Safe query: prepared statement, returns mysqli_result or true.
 * $types: 's' string, 'i' int, 'd' double, 'b' blob (null = auto-detect)
 */
function db_query(string $sql, array $params = [], ?string $types = null)
{
    $conn = db();
    if (!$params) {
        $res = $conn->query($sql);
        if ($res === false) {
            error_log('[SQL] ' . $conn->error . ' | ' . $sql);
            if (APP_DEBUG) {
                throw new RuntimeException($conn->error);
            }
            throw new RuntimeException('Database error');
        }
        return $res;
    }
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log('[SQL-PREP] ' . $conn->error . ' | ' . $sql);
        if (APP_DEBUG) {
            throw new RuntimeException($conn->error);
        }
        throw new RuntimeException('Database error');
    }
    $types = $types ?? str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) {
        // INSERT / UPDATE / DELETE — safe because get_result() failed (no result set)
        $stmt->free_result();
        $affected = $stmt->affected_rows;
        $insertId = $stmt->insert_id;
        $stmt->close();
        return ['affected' => $affected, 'insert_id' => $insertId];
    }
    // SELECT — get_result() returns a buffered result (mysqlnd); do NOT call
    // store_result() afterwards (it triggers "Commands out of sync").
    return $result;
}

/** Fetch all rows as associative arrays. */
function db_fetch_all(string $sql, array $params = [], ?string $types = null): array
{
    $res = db_query($sql, $params, $types);
    if (is_array($res)) {
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

/** Fetch a single row or null. */
function db_fetch_one(string $sql, array $params = [], ?string $types = null): ?array
{
    $res = db_query($sql, $params, $types);
    if (is_array($res)) {
        return null;
    }
    $row = $res->fetch_assoc();
    return $row ?: null;
}

/** Fetch single scalar value or null. */
function db_fetch_value(string $sql, array $params = [], ?string $types = null)
{
    $row = db_fetch_one($sql, $params, $types);
    return $row ? array_values($row)[0] : null;
}

/** Execute INSERT/UPDATE/DELETE; returns insert id (or affected rows). */
function db_execute(string $sql, array $params = [], ?string $types = null): int
{
    $res = db_query($sql, $params, $types);
    if (is_array($res)) {
        return $res['insert_id'] ?: $res['affected'];
    }
    return 0;
}

/** Run a callable inside a transaction (nested calls reuse the outer transaction). */
function db_transaction(callable $fn)
{
    static $depth = 0;
    $conn = db();
    if ($depth === 0) {
        $conn->begin_transaction();
    }
    $depth++;
    try {
        $result = $fn();
        $depth--;
        if ($depth === 0) {
            $conn->commit();
        }
        return $result;
    } catch (Throwable $e) {
        $depth--;
        if ($depth === 0) {
            $conn->rollback();
        }
        throw $e;
    }
}
