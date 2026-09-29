<?php
/**
 * Generic master-data list page.
 * URL: /masterdata/<slug>  e.g. /masterdata/services, /masterdata/payment-methods
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';

$slug = get('slug');
$map = [
    'appointment-types'      => ['appointment_types', 'masterdata.view', 'Appointment Types', 'fa-calendar-plus'],
    'appointment-statuses'   => ['appointment_statuses', 'masterdata.view', 'Appointment Statuses', 'fa-list-check'],
    'payment-methods'        => ['payment_methods', 'masterdata.view', 'Payment Methods', 'fa-credit-card'],
    'expense-categories'     => ['expense_categories', 'masterdata.view', 'Expense Categories', 'fa-file-invoice'],
    'medicine-categories'    => ['medicine_categories', 'masterdata.view', 'Medicine Categories', 'fa-capsules'],
    'suppliers'              => ['suppliers', 'masterdata.view', 'Suppliers', 'fa-truck-field'],
    'lab-test-categories'    => ['lab_test_categories', 'masterdata.view', 'Lab Test Categories', 'fa-vial-circle-check'],
    'services'               => ['services', 'masterdata.view', 'Services & Pricing', 'fa-hand-holding-dollar'],
];

if (!isset($map[$slug])) {
    http_response_code(404);
    require dirname(__DIR__, 2) . '/errors/404.php';
    exit;
}
[$resource, $perm, $title, $icon] = $map[$slug];
require_permission($perm);
$canManage = has_permission('masterdata.manage');

// Which whitelist entry does this resource use? Derive from ajax/crud.php.
$resourceKey = [
    'appointment_types' => 'appointment_types', 'appointment_statuses' => 'appointment_statuses',
    'payment_methods' => 'payment_methods', 'expense_categories' => 'expense_categories',
    'medicine_categories' => 'medicine_categories', 'suppliers' => 'suppliers',
    'lab_test_categories' => 'lab_test_categories', 'services' => 'services',
][$resource];

// Load list data ----------------------------------------------------------------
$search = get('q', '');
$statusFilter = get('status', '');
$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $nameCol = ['appointment_types' => 'type_name', 'appointment_statuses' => 'status_name',
        'payment_methods' => 'method_name', 'expense_categories' => 'category_name',
        'medicine_categories' => 'category_name', 'suppliers' => 'supplier_name',
        'lab_test_categories' => 'category_name', 'services' => 'service_name'][$resource];
    $where .= " AND `$nameCol` LIKE ?";
    $params[] = "%$search%";
}
if ($statusFilter !== '') {
    $where .= ' AND status = ?';
    $params[] = (int) $statusFilter;
}
$total = (int) db_fetch_value("SELECT COUNT(*) FROM `$resource` $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all("SELECT * FROM `$resource` $where ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);

$crudUrl = '/ajax/crud?resource=' . $resourceKey;
ui_page_open(['title' => $title, 'icon' => $icon, 'breadcrumb' => ['Master Data' => '/masterdata', $title => null]]);

echo '<div data-crud-url="' . e($crudUrl) . '" data-crud-title="' . e(rtrim($title, 's')) . '">';
echo table_toolbar($crudUrl, rtrim($title, 's'));
echo '<div class="card"><div class="card-body">';
echo '<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">' . result_count_label() . '</span>';
echo '<form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="masterdata"><input type="hidden" name="slug" value="' . e($slug) . '">';
echo '<input type="text" name="q" class="form-control form-control-sm" placeholder="Search..." value="' . e($search) . '" style="width:180px">';
echo '<select name="status" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">';
echo '<option value="">All status</option><option value="1"' . ($statusFilter === '1' ? ' selected' : '') . '>Active</option>';
echo '<option value="0"' . ($statusFilter === '0' ? ' selected' : '') . '>Inactive</option></select></form></div>';

echo '<div class="table-responsive"><table class="table table-hover align-middle">';
echo '<thead><tr><th>#</th><th>Name</th><th>Details</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) {
    echo '<tr><td colspan="5" class="text-center text-muted py-4">No data found</td></tr>';
}
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $name = $r['type_name'] ?? $r['status_name'] ?? $r['method_name'] ?? $r['category_name'] ?? $r['supplier_name'] ?? $r['service_name'] ?? '';
    $details = $r['description'] ?? '';
    if ($resource === 'services') {
        $details = money($r['price']) . ($r['service_code'] ? ' · ' . $r['service_code'] : '');
    } elseif ($resource === 'suppliers') {
        $details = or_na($r['phone']) . ' · ' . or_na($r['email']);
    }
    echo '<tr>';
    echo '<td class="text-muted">' . $id . '</td>';
    echo '<td class="fw-semibold">' . e($name) . '</td>';
    echo '<td class="text-muted small">' . e(or_na($details)) . '</td>';
    echo '<td>' . status_badge($r['status']) . '</td>';
    echo '<td class="text-end">';
    if ($canManage) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="' . e($crudUrl) . '" data-title="' . e(rtrim($title, 's')) . '" title="Edit"><i class="fa-solid fa-pen"></i></button> ';
        echo '<button class="btn btn-sm btn-light" data-action="toggle" data-id="' . $id . '" data-url="' . e($crudUrl) . '" title="' . ((int) $r['status'] === 1 ? 'Deactivate' : 'Activate') . '">';
        echo '<i class="fa-solid ' . ((int) $r['status'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary') . '"></i></button> ';
        echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-url="' . e($crudUrl) . '" data-name="' . e($name) . '" data-title="' . e(rtrim($title, 's')) . '" title="Delete"><i class="fa-solid fa-trash"></i></button>';
    } else {
        echo '<span class="text-muted small">View only</span>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2"><span class="small text-muted"></span>' . pagination_links() . '</div>';
echo '</div></div></div>';
ui_page_close();
