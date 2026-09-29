<?php
/**
 * Router script for PHP built-in dev server.
 * Serves static files directly; everything else goes through index.php.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file) && !preg_match('/\.php$/i', $uri)) {
    return false; // let the built-in server serve css/js/img/uploads
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
