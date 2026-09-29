<?php
/**
 * Dynamic web app manifest — works at domain root OR in a subfolder
 * (e.g. http://localhost/cms/manifest.php). Kept DB-free on purpose:
 * it must never fail, even if the database is temporarily unreachable.
 */
require_once __DIR__ . '/config/functions.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

$base = base_url();
$name = 'Clinic Management System';

$manifest = [
    'name'             => $name,
    'short_name'       => mb_substr($name, 0, 12),
    'description'      => 'Patients, appointments, queue, pharmacy, laboratory, billing and reports — across all your branches.',
    'id'               => $base . '/',
    'start_url'        => $base . '/',
    'scope'            => $base . '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#f4f6f9',
    'theme_color'      => '#0d8a80',
    'lang'             => 'en',
    'icons'            => [
        ['src' => "$base/assets/icons/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$base/assets/icons/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => "$base/assets/icons/icon-maskable-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => "$base/assets/icons/icon-maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => "$base/favicon.svg", 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
