<?php
/**
 * Shared bootstrap for /school/* pages. Not intended as a route.
 */
declare(strict_types=1);
define('APP_BOOT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/config/auth.php';
require_once $root . '/config/school.php';
require_once $root . '/includes/ui.php';
require_login();

$grades = db_fetch_all('SELECT * FROM grades WHERE status = 1 ORDER BY sort_order, name');
$subjects = db_fetch_all('SELECT * FROM subjects WHERE status = 1 ORDER BY name');
$sections = db_fetch_all('SELECT s.*, g.name grade_name, g.sort_order grade_sort FROM sections s JOIN grades g ON g.id = s.grade_id WHERE s.status = 1 ORDER BY g.sort_order, g.name, s.name');
$teachers = db_fetch_all('SELECT * FROM school_teachers WHERE status = 1 ORDER BY full_name');
$sectionGroups = [];
foreach ($sections as $s) {
    $sectionGroups[$s['grade_id']][] = ['id' => (int) $s['id'], 'name' => $s['name']];
}
