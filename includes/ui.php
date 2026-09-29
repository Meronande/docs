<?php
/**
 * Reusable UI page helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/notification.php';

/**
 * Open a standard admin page: emits header, sidebar, main wrapper,
 * breadcrumb, title and flashes. Call at top of module pages.
 */
function ui_page_open(array $opts = []): void
{
    $opts += ['title' => '', 'breadcrumb' => [], 'icon' => 'fa-table-list'];
    $pageTitle = $opts['title'];
    $breadcrumb = $opts['breadcrumb'];
    $pageIcon = $opts['icon'];
    require dirname(__DIR__) . '/includes/header.php';
    require dirname(__DIR__) . '/includes/sidebar.php';
    echo '<div class="app-main">';
    // Title block + flashes render through footer include for consistent placement
    $GLOBALS['__ui_title'] = $opts;
}

/**
 * Close a standard admin page (footer include handles title/flashes placement).
 */
function ui_page_close(): void
{
    $opts = $GLOBALS['__ui_title'] ?? [];
    $pageTitle = $opts['title'] ?? '';
    $breadcrumb = $opts['breadcrumb'] ?? [];
    $pageIcon = $opts['icon'] ?? 'fa-table-list';
    require dirname(__DIR__) . '/includes/footer.php';
    echo '</div>'; // close .app-main (footer.php opened .app-content internally)
}

/** Stat card for dashboards. */
function stat_card(string $label, $value, string $icon, string $tone = 'brand', ?string $url = null): string
{
    $iconEl = '<span class="stat-icon' . ($tone !== 'brand' ? ' bg-soft-' . e($tone) : '') . '"><i class="fa-solid ' . e($icon) . '"></i></span>';
    $inner = $iconEl . '<div><div class="stat-value">' . e((string) $value) . '</div><div class="stat-label">' . e($label) . '</div></div>';
    if ($url) {
        return '<a class="stat-card d-flex align-items-center gap-3 text-dark h-100" href="' . e($url) . '">' . $inner . '</a>';
    }
    return '<div class="stat-card d-flex align-items-center gap-3 h-100">' . $inner . '</div>';
}

/** Standard table card header with search + Add button. */
function table_toolbar(string $crudUrl, string $crudTitle, string $searchId = 'tableSearch'): string
{
    return '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
      <input type="search" id="' . e($searchId) . '" class="form-control table-search" placeholder="Search current page...">
      <button class="btn btn-brand" data-action="create" data-url="' . e($crudUrl) . '" data-title="' . e($crudTitle) . '">
        <i class="fa-solid fa-plus me-1"></i> Add ' . e($crudTitle) . '</button>
    </div>';
}

/** SweetAlert2 include (for pages that need it before app.js runs). */
function sweetalert2_assets(): string
{
    return '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
}
