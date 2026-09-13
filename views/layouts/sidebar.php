<?php

declare(strict_types=1);

use Karoor\Core\Auth;
use Karoor\Core\Helpers;

if (!isset($auth, $config) || !$auth instanceof Auth || !is_array($config)) {
    throw new RuntimeException('The sidebar requires an authenticated application context.');
}

$sidebarPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$sidebarBasePath = parse_url((string) $config['app']['url'], PHP_URL_PATH);
$sidebarBasePath = is_string($sidebarBasePath) ? rtrim($sidebarBasePath, '/') : '';
if ($sidebarBasePath !== '' && str_starts_with($sidebarPath, $sidebarBasePath)) {
    $sidebarPath = substr($sidebarPath, strlen($sidebarBasePath)) ?: '/';
}
$sidebarView = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
$sidebarReport = isset($_GET['report']) && is_string($_GET['report']) ? $_GET['report'] : '';

$sidebarSections = [
    '' => [
        ['label' => 'Dashboard', 'icon' => 'fa-solid fa-border-all', 'path' => '/dashboard', 'permission' => 'dashboard.view'],
    ],
    'Operations' => [
        ['label' => 'POS', 'icon' => 'fa-solid fa-cash-register', 'path' => '/pos', 'permission' => 'pos.use'],
        ['label' => 'Sales', 'icon' => 'fa-solid fa-receipt', 'path' => '/sales', 'permission' => ['sales.view', 'sales.view_own']],
        ['label' => 'Purchases', 'icon' => 'fa-solid fa-cart-flatbed', 'path' => '/purchases', 'permission' => 'purchases.view'],
        ['label' => 'Expenses', 'icon' => 'fa-solid fa-wallet', 'path' => '/expenses', 'permission' => 'expenses.view'],
        ['label' => 'Payments', 'icon' => 'fa-solid fa-money-bill-transfer', 'path' => '/finance', 'query' => 'view=payments', 'permission' => 'finance.view'],
    ],
    'Inventory' => [
        ['label' => 'Products', 'icon' => 'fa-solid fa-box', 'path' => '/inventory', 'query' => 'view=products', 'permission' => 'products.view'],
        ['label' => 'Categories', 'icon' => 'fa-solid fa-layer-group', 'path' => '/inventory', 'query' => 'view=categories', 'permission' => 'inventory.view'],
        ['label' => 'Warehouses', 'icon' => 'fa-solid fa-warehouse', 'path' => '/inventory', 'query' => 'view=warehouses', 'permission' => 'inventory.view'],
        ['label' => 'Stock', 'icon' => 'fa-solid fa-boxes-stacked', 'path' => '/inventory', 'query' => 'view=stock', 'permission' => 'inventory.view'],
        ['label' => 'Stock Transfers', 'icon' => 'fa-solid fa-arrow-right-arrow-left', 'path' => '/inventory', 'query' => 'view=transfers', 'permission' => 'inventory.transfer'],
        ['label' => 'Stock Adjustments', 'icon' => 'fa-solid fa-sliders', 'path' => '/inventory', 'query' => 'view=adjustments', 'permission' => 'inventory.adjust'],
        ['label' => 'Stock Movements', 'icon' => 'fa-solid fa-clock-rotate-left', 'path' => '/inventory', 'query' => 'view=movements', 'permission' => 'inventory.view'],
    ],
    'Parties' => [
        ['label' => 'Customers', 'icon' => 'fa-solid fa-users', 'path' => '/contacts', 'query' => 'view=customers', 'permission' => 'customers.view'],
        ['label' => 'Suppliers', 'icon' => 'fa-solid fa-truck-field', 'path' => '/contacts', 'query' => 'view=suppliers', 'permission' => 'suppliers.view'],
    ],
    'Finance' => [
        ['label' => 'Chart of Accounts', 'icon' => 'fa-solid fa-sitemap', 'path' => '/finance', 'query' => 'view=chart-of-accounts', 'permission' => 'finance.view'],
        ['label' => 'Accounts', 'icon' => 'fa-solid fa-building-columns', 'path' => '/finance', 'query' => 'view=accounts', 'permission' => 'finance.view'],
        ['label' => 'Journal Entries', 'icon' => 'fa-solid fa-arrow-trend-up', 'path' => '/finance', 'query' => 'view=journals', 'permission' => 'finance.view'],
        ['label' => 'General Ledger', 'icon' => 'fa-solid fa-book-open', 'path' => '/finance', 'query' => 'view=ledger', 'permission' => 'finance.view'],
        ['label' => 'Income Statement', 'icon' => 'fa-solid fa-chart-line', 'path' => '/finance', 'query' => 'view=income-statement', 'permission' => 'finance.reports'],
        ['label' => 'Balance Sheet', 'icon' => 'fa-solid fa-scale-balanced', 'path' => '/finance', 'query' => 'view=balance-sheet', 'permission' => 'finance.reports'],
        ['label' => 'Cash Flow', 'icon' => 'fa-solid fa-water', 'path' => '/finance', 'query' => 'view=cash-flow', 'permission' => 'finance.reports'],
        ['label' => 'Receivables', 'icon' => 'fa-solid fa-hand-holding-dollar', 'path' => '/finance', 'query' => 'view=receivables', 'permission' => 'finance.view'],
        ['label' => 'Payables', 'icon' => 'fa-solid fa-file-invoice-dollar', 'path' => '/finance', 'query' => 'view=payables', 'permission' => 'finance.view'],
    ],
    'HRM' => [
        ['label' => 'Employees', 'icon' => 'fa-solid fa-id-badge', 'path' => '/hrm', 'query' => 'view=employees', 'permission' => 'hr.view'],
        ['label' => 'Departments', 'icon' => 'fa-solid fa-people-group', 'path' => '/hrm', 'query' => 'view=departments', 'permission' => 'hr.employee'],
        ['label' => 'Attendance', 'icon' => 'fa-solid fa-user-clock', 'path' => '/hrm', 'query' => 'view=attendance', 'permission' => 'hr.attendance'],
        ['label' => 'Leave', 'icon' => 'fa-solid fa-calendar-day', 'path' => '/hrm', 'query' => 'view=leave', 'permission' => 'hr.leave'],
        ['label' => 'Payroll', 'icon' => 'fa-solid fa-money-check-dollar', 'path' => '/hrm', 'query' => 'view=payroll', 'permission' => 'hr.payroll'],
        ['label' => 'Salary Slips', 'icon' => 'fa-solid fa-file-lines', 'path' => '/hrm', 'query' => 'view=salary-slips', 'permission' => 'hr.payroll'],
    ],
    'Reports' => [
        ['label' => 'Sales Reports', 'icon' => 'fa-solid fa-chart-column', 'path' => '/reports', 'query' => 'report=sales', 'permission' => 'reports.view'],
        ['label' => 'Purchase Reports', 'icon' => 'fa-solid fa-chart-area', 'path' => '/reports', 'query' => 'report=purchases', 'permission' => 'reports.view'],
        ['label' => 'Inventory Reports', 'icon' => 'fa-solid fa-chart-simple', 'path' => '/reports', 'query' => 'report=inventory', 'permission' => 'reports.view'],
        ['label' => 'Financial Reports', 'icon' => 'fa-solid fa-chart-pie', 'path' => '/reports', 'query' => 'report=finance', 'permission' => 'finance.reports'],
        ['label' => 'HR Reports', 'icon' => 'fa-solid fa-chart-gantt', 'path' => '/reports', 'query' => 'report=hr', 'permission' => 'hr.view'],
        ['label' => 'Profit Reports', 'icon' => 'fa-solid fa-coins', 'path' => '/reports', 'query' => 'report=profit', 'permission' => 'reports.view'],
    ],
    'System' => [
        ['label' => 'Users', 'icon' => 'fa-solid fa-user-gear', 'path' => '/settings', 'query' => 'view=users', 'permission' => 'users.manage'],
        ['label' => 'Roles & Permissions', 'icon' => 'fa-solid fa-shield-halved', 'path' => '/settings', 'query' => 'view=roles', 'permission' => 'roles.manage'],
        ['label' => 'Audit Logs', 'icon' => 'fa-solid fa-list-check', 'path' => '/settings', 'query' => 'view=audit', 'permission' => 'audit.view'],
        ['label' => 'Recycle Bin', 'icon' => 'fa-solid fa-trash-arrow-up', 'path' => '/recycle-bin', 'permission' => 'recycle_bin.manage'],
        ['label' => 'Backups', 'icon' => 'fa-solid fa-database', 'path' => '/settings', 'query' => 'view=backups', 'permission' => 'backups.manage'],
        ['label' => 'Settings', 'icon' => 'fa-solid fa-gear', 'path' => '/settings', 'permission' => 'settings.manage'],
    ],
];

if (!function_exists('karoor_sidebar_allowed')) {
    /** @param string|list<string> $permission */
    function karoor_sidebar_allowed(Auth $auth, string|array $permission): bool
    {
        return is_array($permission) ? $auth->canAny($permission) : $auth->can($permission);
    }
}

if (!function_exists('karoor_render_sidebar_navigation')) {
    /** @param array<string, list<array<string, mixed>>> $sections */
    function karoor_render_sidebar_navigation(
        array $sections,
        Auth $auth,
        array $config,
        string $currentPath,
        string $currentView,
        string $currentReport
    ): void {
        foreach ($sections as $section => $items) {
            $visibleItems = array_values(array_filter(
                $items,
                static fn (array $item): bool => karoor_sidebar_allowed($auth, $item['permission'])
            ));
            if ($visibleItems === []) {
                continue;
            }
            if ($section !== '') {
                echo '<p class="sidebar-section-label">' . Helpers::escape($section) . '</p>';
            }
            echo '<ul class="sidebar-nav-list">';
            foreach ($visibleItems as $item) {
                $itemView = '';
                $itemReport = '';
                if (isset($item['query'])) {
                    parse_str((string) $item['query'], $queryValues);
                    $itemView = is_string($queryValues['view'] ?? null) ? $queryValues['view'] : '';
                    $itemReport = is_string($queryValues['report'] ?? null) ? $queryValues['report'] : '';
                }
                $isActive = $currentPath === $item['path']
                    && ($itemView === '' || $currentView === $itemView)
                    && ($itemReport === '' || $currentReport === $itemReport);
                $url = Helpers::appPath($config, (string) $item['path']);
                if (isset($item['query'])) {
                    $url .= '?' . $item['query'];
                }
                echo '<li><a class="sidebar-link' . ($isActive ? ' active' : '') . '" href="'
                    . Helpers::escape($url) . '" title="' . Helpers::escape((string) $item['label']) . '"'
                    . ($isActive ? ' aria-current="page"' : '') . '>'
                    . '<span class="sidebar-icon"><i class="' . Helpers::escape((string) $item['icon']) . '" aria-hidden="true"></i></span>'
                    . '<span class="sidebar-link-label">' . Helpers::escape((string) $item['label']) . '</span>'
                    . '</a></li>';
            }
            echo '</ul>';
        }
    }
}
?>
<aside class="app-sidebar d-none d-lg-flex" aria-label="Primary navigation">
    <a class="brand" href="<?= Helpers::escape(Helpers::appPath($config, '/dashboard')) ?>">
        <span class="brand-mark" aria-hidden="true">K</span>
        <span class="brand-copy"><strong>Karoor</strong><small>Enterprise ERP</small></span>
    </a>
    <nav class="sidebar-scroll">
        <?php karoor_render_sidebar_navigation($sidebarSections, $auth, $config, $sidebarPath, $sidebarView, $sidebarReport); ?>
    </nav>
    <div class="sidebar-footer-card">
        <span class="sidebar-footer-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
        <span class="sidebar-footer-copy"><strong>Secure workspace</strong><small>Protected business data</small></span>
    </div>
</aside>

<div class="offcanvas offcanvas-start mobile-sidebar" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header">
        <a class="brand" href="<?= Helpers::escape(Helpers::appPath($config, '/dashboard')) ?>">
            <span class="brand-mark" aria-hidden="true">K</span>
            <span class="brand-copy"><strong id="mobileSidebarLabel">Karoor</strong><small>Enterprise ERP</small></span>
        </a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
    </div>
    <div class="offcanvas-body sidebar-scroll">
        <nav aria-label="Mobile navigation">
            <?php karoor_render_sidebar_navigation($sidebarSections, $auth, $config, $sidebarPath, $sidebarView, $sidebarReport); ?>
        </nav>
    </div>
</div>

<?php
$mobileBottomCandidates = [
    ['label'=>'Home','icon'=>'fa-solid fa-house','path'=>'/dashboard','permission'=>'dashboard.view'],
    ['label'=>'POS','icon'=>'fa-solid fa-cash-register','path'=>'/pos','permission'=>'pos.use'],
    ['label'=>'Sales','icon'=>'fa-solid fa-receipt','path'=>'/sales','permission'=>['sales.view','sales.view_own']],
    ['label'=>'Stock','icon'=>'fa-solid fa-boxes-stacked','path'=>'/inventory','query'=>'view=stock','permission'=>'inventory.view'],
    ['label'=>'Purchases','icon'=>'fa-solid fa-cart-flatbed','path'=>'/purchases','permission'=>'purchases.view'],
    ['label'=>'Reports','icon'=>'fa-solid fa-chart-column','path'=>'/reports','permission'=>'reports.view'],
];
$mobileBottomItems = array_slice(array_values(array_filter(
    $mobileBottomCandidates,
    static fn(array $item): bool => karoor_sidebar_allowed($auth, $item['permission'])
)), 0, 4);
$mobileBottomPaths = array_column($mobileBottomItems, 'path');
?>
<nav class="mobile-bottom-nav d-lg-none" aria-label="Quick navigation">
    <?php foreach ($mobileBottomItems as $item): ?>
        <?php
        $mobileUrl = Helpers::appPath($config, $item['path']) . (isset($item['query']) ? '?' . $item['query'] : '');
        $mobileActive = $sidebarPath === $item['path'];
        ?>
        <a class="mobile-bottom-link<?= $mobileActive ? ' active' : '' ?>" href="<?= Helpers::escape($mobileUrl) ?>"<?= $mobileActive ? ' aria-current="page"' : '' ?>>
            <i class="<?= Helpers::escape($item['icon']) ?>" aria-hidden="true"></i>
            <span><?= Helpers::escape($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <button class="mobile-bottom-link<?= !in_array($sidebarPath, $mobileBottomPaths, true) ? ' active' : '' ?>" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open all navigation">
        <i class="fa-solid fa-grip" aria-hidden="true"></i><span>More</span>
    </button>
</nav>
