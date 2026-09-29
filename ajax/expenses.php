<?php
/**
 * AJAX expenses endpoint.
 * GET  ?form[&id=]  -> expense form
 * POST action=save  -> create/update expense
 * POST action=delete
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__) . '/config/auth.php';

if (!is_logged_in()) json_response(['ok' => false, 'message' => 'Not authenticated.'], 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
} elseif (!has_permission('expenses.view')) {
    json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('form') !== '') {
    if (!has_permission('expenses.create') && !has_permission('expenses.edit')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    $id = (int) get('id', '0');
    $row = $id ? db_fetch_one('SELECT * FROM expenses WHERE id = ?', [$id], 'i') : null;
    if ($row) {
        assert_branch_access($row['branch_id'] !== null ? (int) $row['branch_id'] : null);
        if (!has_permission('expenses.edit')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }

    ob_start();
    echo '<form id="crudForm" data-url="/ajax/expenses">';
    echo '<input type="hidden" name="action" value="save">';
    if ($id) echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label required">Amount</label><input type="number" step="0.01" min="0.01" class="form-control" name="amount" value="' . e($row ? money_raw($row['amount']) : '') . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label required">Date</label><input type="date" class="form-control" name="expense_date" value="' . e($row['expense_date'] ?? date('Y-m-d')) . '" required></div>';
    echo '<div class="col-md-6"><label class="form-label required">Category</label><select class="form-select" name="category_id" required><option value="">— Select —</option>';
    foreach (db_fetch_all('SELECT id, category_name FROM expense_categories WHERE status = 1 ORDER BY category_name') as $c) {
        echo '<option value="' . (int) $c['id'] . '" ' . ($row && (int) $row['category_id'] === (int) $c['id'] ? 'selected' : '') . '>' . e($c['category_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label">Payment Method</label><select class="form-select" name="payment_method_id"><option value="">— Select —</option>';
    foreach (db_fetch_all('SELECT id, method_name FROM payment_methods WHERE status = 1 ORDER BY id') as $m) {
        echo '<option value="' . (int) $m['id'] . '" ' . ($row && (int) $row['payment_method_id'] === (int) $m['id'] ? 'selected' : '') . '>' . e($m['method_name']) . '</option>';
    }
    echo '</select></div>';
    if (sees_all_branches()) {
        echo '<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">— Head office —</option>';
        foreach (visible_branches() as $b) {
            echo '<option value="' . (int) $b['id'] . '" ' . ($row && (int) $row['branch_id'] === (int) $b['id'] ? 'selected' : '') . '>' . e($b['branch_name']) . '</option>';
        }
        echo '</select></div>';
    } else {
        echo '<input type="hidden" name="branch_id" value="' . (scope_branch_id() ?? current_user()['branch_id'] ?? 0) . '">';
    }
    echo '<div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" value="' . e($row['description'] ?? '') . '" placeholder="What was purchased?"></div>';
    echo '</div></form>';
    json_response(['ok' => true, 'html' => ob_get_clean()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    $id = (int) post('id', '0');
    $isEdit = $id > 0;
    if (!has_permission($isEdit ? 'expenses.edit' : 'expenses.create')) {
        json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    }
    if ($isEdit) {
        $old = db_fetch_one('SELECT id, branch_id FROM expenses WHERE id = ?', [$id], 'i');
        if (!$old) json_response(['ok' => false, 'message' => 'Expense not found.'], 404);
        assert_branch_access($old['branch_id'] !== null ? (int) $old['branch_id'] : null);
    }

    $amount = (float) post('amount', '0');
    $date = post('expense_date');
    $catId = (int) post('category_id', '0');
    if ($amount <= 0) json_response(['ok' => false, 'message' => 'Amount must be greater than zero.']);
    if (!valid_date($date) || $date === '') json_response(['ok' => false, 'message' => 'A valid date is required.']);
    if ($catId === 0) json_response(['ok' => false, 'message' => 'Category is required.']);

    $branchId = sees_all_branches()
        ? (post('branch_id') !== '' ? (int) post('branch_id') : null)
        : (scope_branch_id() ?? (current_user()['branch_id'] !== null ? (int) current_user()['branch_id'] : null));

    if ($isEdit) {
        db_execute('UPDATE expenses SET branch_id = ?, category_id = ?, amount = ?, expense_date = ?, description = ?, payment_method_id = ? WHERE id = ?',
            [$branchId, $catId, $amount, $date, post('description') ?: null, post('payment_method_id') !== '' ? (int) post('payment_method_id') : null, $id],
            'iidsssi');
        audit_log('update', 'Expense', $id, 'Updated expense ' . money($amount) . ' (' . post('description') . ')');
        json_response(['ok' => true, 'message' => 'Expense updated.']);
    }
    $newId = db_execute('INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by) VALUES (?,?,?,?,?,?,?)',
        [$branchId, $catId, $amount, $date, post('description') ?: null, post('payment_method_id') !== '' ? (int) post('payment_method_id') : null, (int) current_user()['id']],
        'iidsssi');
    audit_log('create', 'Expense', $newId, 'Recorded expense ' . money($amount) . ' (' . post('description') . ')');
    json_response(['ok' => true, 'message' => 'Expense recorded.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (!has_permission('expenses.delete')) json_response(['ok' => false, 'message' => 'Permission denied.'], 403);
    $id = (int) post('id', '0');
    $row = db_fetch_one('SELECT id, branch_id, amount, description FROM expenses WHERE id = ?', [$id], 'i');
    if (!$row) json_response(['ok' => false, 'message' => 'Expense not found.'], 404);
    assert_branch_access($row['branch_id'] !== null ? (int) $row['branch_id'] : null);
    db_execute('DELETE FROM expenses WHERE id = ?', [$id], 'i');
    audit_log('delete', 'Expense', $id, 'Deleted expense ' . money($row['amount']) . ' (' . ($row['description'] ?? '') . ')');
    json_response(['ok' => true, 'message' => 'Expense deleted.']);
}

json_response(['ok' => false, 'message' => 'Unsupported action.'], 400);
