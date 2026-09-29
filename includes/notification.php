<?php
/**
 * Notification helpers shared by AJAX + includes.
 */

function notif_icon(string $type): array
{
    $map = [
        'success'     => ['fa-circle-check', 'text-success'],
        'warning'     => ['fa-triangle-exclamation', 'text-warning'],
        'danger'      => ['fa-circle-exclamation', 'text-danger'],
        'appointment' => ['fa-calendar-check', 'text-primary'],
        'laboratory'  => ['fa-flask-vial', 'text-info'],
        'pharmacy'    => ['fa-pills', 'text-success'],
        'finance'     => ['fa-money-bill-wave', 'text-warning'],
        'system'      => ['fa-gear', 'text-secondary'],
        'info'        => ['fa-circle-info', 'text-primary'],
    ];
    return $map[$type] ?? $map['info'];
}

function notif_badge(string $type): string
{
    $map = [
        'success' => 'success', 'warning' => 'warning', 'danger' => 'danger',
        'appointment' => 'primary', 'laboratory' => 'info', 'pharmacy' => 'success',
        'finance' => 'warning', 'system' => 'secondary', 'info' => 'primary',
    ];
    return 'text-bg-' . ($map[$type] ?? 'primary');
}

function time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return fmt_date($datetime, true);
}
