<?php
/**
 * Front controller — clean URLs like /branches, /patients/view/12
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}

// Public routes (no login required)
$public = ['/', '/login', '/logout', '/setup', '/index.php', '/login.php', '/logout.php', '/setup.php'];

if (in_array($uri, $public, true)) {
    switch ($uri) {
        case '/':
        case '/index.php':
            if (is_logged_in()) {
                redirect('/dashboard');
            }
            redirect('/login');
        case '/login':
        case '/login.php':
            require __DIR__ . '/login.php';
            return true;
        case '/logout':
        case '/logout.php':
            require __DIR__ . '/logout.php';
            return true;
        case '/setup':
        case '/setup.php':
            require __DIR__ . '/setup.php';
            return true;
    }
}

// AJAX endpoints: /ajax/<file> -> ajax/<file>.php (permission checks live inside each file)
if (preg_match('#^/ajax/([a-z0-9_\-]+)$#', $uri, $m)) {
    $file = __DIR__ . '/ajax/' . $m[1] . '.php';
    if (is_file($file)) {
        require $file;
        return true;
    }
    json_response(['ok' => false, 'message' => 'Endpoint not found.'], 404);
}

// Logged-in area guard for every other route
require_login();

// Reserved pages handled by dedicated files
$direct = [
    '/profile' => __DIR__ . '/profile.php',
    '/search'  => __DIR__ . '/search.php',
];
if (isset($direct[$uri])) {
    require $direct[$uri];
    return true;
}

// Module routing: /patients, /patients/view/12, /reports/patients ...
$segments = explode('/', trim($uri, '/'));
$module = $segments[0] ?? '';
$action = $segments[1] ?? 'index';

// Extra numeric segment maps to ?id= (e.g. /patients/view/12)
if (isset($segments[2]) && ctype_digit($segments[2])) {
    $_GET['id'] = $segments[2];
}

$moduleBase = __DIR__ . '/modules';

// masterdata uses a generic list page: /masterdata/<slug>
if ($module === 'masterdata' && $action !== 'index') {
    $_GET['slug'] = $action;
    require $moduleBase . '/masterdata/list.php';
    return true;
}

$moduleFile = $moduleBase . '/' . $module . '/' . $action . '.php';

if (is_file($moduleFile)) {
    require $moduleFile;
    return true;
}

// Support modules/<module>/index.php for bare /module
if ($action === 'index' && is_file($moduleBase . '/' . $module . '/index.php')) {
    require $moduleBase . '/' . $module . '/index.php';
    return true;
}

http_response_code(404);
require __DIR__ . '/errors/404.php';
return true;
