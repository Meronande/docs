<?php
/**
 * GET  /ajax/notifications           -> latest notifications + unread count
 * POST /ajax/notifications           -> action=read (id) | read_all
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/includes/notification.php';

if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    if ($action === 'read') {
        mark_notifications_read([(int) post('id', '0')]);
    } elseif ($action === 'read_all') {
        mark_notifications_read([]);
    }
    json_response(['ok' => true]);
}

$items = array_map(function (array $n) {
    return [
        'id' => (int) $n['id'],
        'title' => $n['title'],
        'message' => $n['message'],
        'type' => $n['notification_type'],
        'url' => $n['url'],
        'ago' => time_ago($n['created_at']),
        'unread' => (int) $n['is_read'] === 0,
    ];
}, notifications_for_me(15));

json_response(['ok' => true, 'unread' => unread_count(), 'items' => $items]);
