<?php
/**
 * /masterdata — hub for dynamic master data management.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('masterdata.view');

$groups = [
    ['fa-calendar-check', 'Appointments', [
        ['/masterdata/appointment-types', 'Appointment Types', 'fa-calendar-plus'],
        ['/masterdata/appointment-statuses', 'Appointment Statuses', 'fa-list-check'],
    ]],
    ['fa-money-bill-wave', 'Finance', [
        ['/masterdata/payment-methods', 'Payment Methods', 'fa-credit-card'],
        ['/masterdata/expense-categories', 'Expense Categories', 'fa-file-invoice'],
    ]],
    ['fa-pills', 'Pharmacy', [
        ['/masterdata/medicine-categories', 'Medicine Categories', 'fa-capsules'],
        ['/masterdata/suppliers', 'Suppliers', 'fa-truck-field'],
    ]],
    ['fa-flask-vial', 'Laboratory', [
        ['/masterdata/lab-test-categories', 'Lab Test Categories', 'fa-vial-circle-check'],
    ]],
    ['fa-gear', 'Services', [
        ['/masterdata/services', 'Services & Pricing', 'fa-hand-holding-dollar'],
    ]],
];

ui_page_open(['title' => 'Master Data', 'icon' => 'fa-database', 'breadcrumb' => ['Master Data' => null]]);
echo '<div class="row g-3">';
foreach ($groups as [$icon, $label, $links]) {
    echo '<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">';
    echo '<h6 class="card-title mb-3"><span class="page-icon me-2"><i class="fa-solid ' . e($icon) . '"></i></span>' . e($label) . '</h6>';
    foreach ($links as [$url, $text, $li]) {
        echo '<a class="d-flex align-items-center gap-2 py-2 px-2 rounded text-dark menu-link" href="' . e($url) . '">';
        echo '<i class="fa-solid ' . e($li) . ' text-secondary fa-fw"></i><span>' . e($text) . '</span>';
        echo '<i class="fa-solid fa-chevron-right ms-auto text-muted" style="font-size:.7rem"></i></a>';
    }
    echo '</div></div></div>';
}
echo '</div>';
ui_page_close();
