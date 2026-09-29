<?php
/**
 * /expenses — expense recording & listing.
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_permission('expenses.view');

$q = get('q', '');
$catF = get('category_id', '');
$branchF = get('branch_id', '');
$from = get('from', '');
$to = get('to', '');

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') { $where .= ' AND (ex.description LIKE ?)'; $params[] = "%$q%"; }
if ($catF !== '') { $where .= ' AND ex.category_id = ?'; $params[] = (int) $catF; }
if ($from !== '' && valid_date($from)) { $where .= ' AND ex.expense_date >= ?'; $params[] = $from; }
if ($to !== '' && valid_date($to)) { $where .= ' AND ex.expense_date <= ?'; $params[] = $to; }
if ($branchF !== '' && sees_all_branches()) { $where .= ' AND ex.branch_id = ?'; $params[] = (int) $branchF; }
else {
    $branchId = scope_branch_id();
    if ($branchId !== null) { $where .= ' AND ex.branch_id = ?'; $params[] = $branchId; }
}

$__scopeBranchId = scope_branch_id();
$branchScope = $__scopeBranchId !== null ? ' AND branch_id = ' . (int) $__scopeBranchId : '';
$stats = [
    'total' => (float) db_fetch_value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE 1=1' . $branchScope),
    'month' => (float) db_fetch_value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")' . $branchScope),
    'today' => (float) db_fetch_value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURDATE()' . $branchScope),
    'count' => (int) db_fetch_value('SELECT COUNT(*) FROM expenses WHERE 1=1' . $branchScope),
];

$total = (int) db_fetch_value("SELECT COUNT(*) FROM expenses ex $where", $params);
[$offset, $perPage] = paginate($total, 15);
$rows = db_fetch_all(
    "SELECT ex.*, c.category_name, m.method_name, b.branch_name
     FROM expenses ex
     LEFT JOIN expense_categories c ON c.id = ex.category_id
     LEFT JOIN payment_methods m ON m.id = ex.payment_method_id
     LEFT JOIN branches b ON b.id = ex.branch_id
     $where ORDER BY ex.expense_date DESC, ex.id DESC LIMIT $perPage OFFSET $offset",
    $params
);
$cats = db_fetch_all('SELECT id, category_name FROM expense_categories WHERE status = 1 ORDER BY category_name');

ui_page_open(['title' => 'Expenses', 'icon' => 'fa-receipt', 'breadcrumb' => ['Finance' => null, 'Expenses' => null]]);
echo '<div data-crud-url="/ajax/expenses" data-crud-title="Expense">';

echo '<div class="row g-3 mb-4">';
echo stat_card('Total Expenses', money($stats['total']), 'fa-receipt', 'brand');
echo stat_card('This Month', money($stats['month']), 'fa-calendar-days', 'warning');
echo stat_card('Today', money($stats['today']), 'fa-clock', 'info');
echo stat_card('Records', $stats['count'], 'fa-list', 'success');
echo '</div>';

echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">';
echo '<form method="get" class="d-flex flex-wrap gap-2">';
echo '<input name="q" class="form-control form-control-sm" placeholder="Search description..." value="' . e($q) . '" style="width:170px">';
echo '<select name="category_id" class="form-select form-select-sm" style="width:160px"><option value="">All categories</option>';
foreach ($cats as $c) echo '<option value="' . (int) $c['id'] . '" ' . ($catF === (string) $c['id'] ? 'selected' : '') . '>' . e($c['category_name']) . '</option>';
echo '</select>';
if (sees_all_branches()) {
    echo '<select name="branch_id" class="form-select form-select-sm" style="width:150px"><option value="">All branches</option>';
    foreach (visible_branches() as $b) echo '<option value="' . (int) $b['id'] . '" ' . ($branchF === (string) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
    echo '</select>';
}
echo '<input type="date" name="from" class="form-control form-control-sm" value="' . e($from) . '" style="width:150px">';
echo '<input type="date" name="to" class="form-control form-control-sm" value="' . e($to) . '" style="width:150px">';
echo '<button class="btn btn-sm btn-outline-brand">Filter</button>';
echo '</form>';
if (has_permission('expenses.create') || has_permission('expenses.edit')) {
    echo '<button class="btn btn-brand" data-action="create" data-url="/ajax/expenses?form=1" data-title="Expense"><i class="fa-solid fa-plus me-1"></i>Record Expense</button>';
}
echo '</div>';

echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">';
echo '<thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th>Branch</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="7" class="text-center text-muted py-4">No expenses found</td></tr>';
foreach ($rows as $r) {
    $id = (int) $r['id'];
    echo '<tr>';
    echo '<td class="text-nowrap small">' . fmt_date($r['expense_date']) . '</td>';
    echo '<td class="small"><span class="badge text-bg-light border">' . e(or_na($r['category_name'])) . '</span></td>';
    echo '<td class="small">' . e(or_na($r['description'])) . '</td>';
    echo '<td class="small">' . e(or_na($r['method_name'])) . '</td>';
    echo '<td class="small">' . e(or_na($r['branch_name'])) . '</td>';
    echo '<td class="text-end text-nowrap fw-semibold text-danger">- ' . money($r['amount']) . '</td>';
    echo '<td class="text-end text-nowrap">';
    if (has_permission('expenses.edit')) {
        echo '<button class="btn btn-sm btn-light" data-action="edit" data-id="' . $id . '" data-url="/ajax/expenses?form=1" data-title="Expense"><i class="fa-solid fa-pen"></i></button> ';
    }
    if (has_permission('expenses.delete')) {
        echo '<button class="btn btn-sm btn-light text-danger" data-action="delete" data-id="' . $id . '" data-name="' . e(or_na($r['description'], 'expense')) . '"><i class="fa-solid fa-trash"></i></button>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="d-flex justify-content-between align-items-center p-3"><span class="small text-muted">' . result_count_label() . '</span>' . pagination_links() . '</div>';
echo '</div></div>';
ui_page_close();
