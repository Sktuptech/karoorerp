<?php

declare(strict_types=1);

use Karoor\Core\Auth;
use Karoor\Core\Database;
use Karoor\Core\Helpers;

if (!isset($auth, $database, $config) || !$auth instanceof Auth || !$database instanceof Database || !is_array($config)) {
    throw new RuntimeException('The application header requires a complete application context.');
}

$currentUser = $auth->requireAuth();
$layoutAction = isset($_POST['_layout_action']) && is_string($_POST['_layout_action'])
    ? $_POST['_layout_action']
    : '';

if ($layoutAction === 'logout') {
    if (!karoor_verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        http_response_code(419);
        exit('The security token is invalid or has expired.');
    }
    $auth->logout();
    Helpers::safeRedirect(Helpers::appPath($config, '/login'));
}

$layoutWarehouses = $database->fetchAll(
    'SELECT id, code, name
     FROM warehouses
     WHERE company_id = :company_id AND is_active = 1 AND deleted_at IS NULL
     ORDER BY is_default DESC, name ASC',
    ['company_id' => (int) $currentUser['company_id']]
);
$warehouseIds = array_map(static fn (array $warehouse): int => (int) $warehouse['id'], $layoutWarehouses);

if ($layoutAction === 'set_warehouse') {
    if (!karoor_verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        http_response_code(419);
        exit('The security token is invalid or has expired.');
    }
    $selectedWarehouseId = filter_var($_POST['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
    if ($selectedWarehouseId === false || !in_array($selectedWarehouseId, $warehouseIds, true)) {
        http_response_code(422);
        exit('The selected warehouse is unavailable.');
    }
    $_SESSION['active_warehouse_id'] = $selectedWarehouseId;
    $returnUri = (string) ($_SERVER['REQUEST_URI'] ?? Helpers::appPath($config, '/dashboard'));
    Helpers::safeRedirect($returnUri);
}

$activeWarehouseId = (int) ($_SESSION['active_warehouse_id'] ?? $currentUser['default_warehouse_id'] ?? 0);
if (!in_array($activeWarehouseId, $warehouseIds, true)) {
    $activeWarehouseId = $warehouseIds[0] ?? 0;
    if ($activeWarehouseId > 0) {
        $_SESSION['active_warehouse_id'] = $activeWarehouseId;
    }
}

$activeWarehouse = null;
foreach ($layoutWarehouses as $warehouse) {
    if ((int) $warehouse['id'] === $activeWarehouseId) {
        $activeWarehouse = $warehouse;
        break;
    }
}

$notificationCountRow = $database->fetchOne(
    'SELECT COUNT(*) AS unread_count
     FROM notifications
     WHERE company_id = :company_id
       AND (user_id = :user_id OR user_id IS NULL)
       AND read_at IS NULL
       AND (expires_at IS NULL OR expires_at > NOW())',
    ['company_id' => (int) $currentUser['company_id'], 'user_id' => (int) $currentUser['id']]
);
$unreadNotificationCount = (int) ($notificationCountRow['unread_count'] ?? 0);
$layoutNotifications = $database->fetchAll(
    'SELECT id, notification_type, title, message, action_url, created_at
     FROM notifications
     WHERE company_id = :company_id
       AND (user_id = :user_id OR user_id IS NULL)
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY read_at IS NULL DESC, created_at DESC
     LIMIT 6',
    ['company_id' => (int) $currentUser['company_id'], 'user_id' => (int) $currentUser['id']]
);

$pageTitle = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== '' ? $pageTitle : 'Dashboard';
$breadcrumbs = isset($breadcrumbs) && is_array($breadcrumbs) ? $breadcrumbs : [['label' => $pageTitle]];
$initialsParts = preg_split('/\s+/', trim((string) $currentUser['full_name'])) ?: [];
$userInitials = '';
foreach (array_slice($initialsParts, 0, 2) as $part) {
    $userInitials .= strtoupper(substr($part, 0, 1));
}
$userInitials = $userInitials !== '' ? $userInitials : 'U';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1120">
    <meta name="csrf-token" content="<?= Helpers::escape(karoor_csrf_token()) ?>">
    <meta name="api-base-url" content="<?= Helpers::escape(Helpers::appPath($config, '/api/v1')) ?>">
    <meta name="app-login-url" content="<?= Helpers::escape(Helpers::appPath($config, '/login')) ?>">
    <meta name="app-currency" content="<?= Helpers::escape((string) $config['app']['currency']) ?>">
    <meta name="app-timezone" content="<?= Helpers::escape((string) $config['app']['timezone']) ?>">
    <title><?= Helpers::escape($pageTitle) ?> · <?= Helpers::escape((string) $config['app']['name']) ?></title>
    <script src="<?= Helpers::escape(Helpers::appPath($config, '/assets/js/theme.js')) ?>"></script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="<?= Helpers::escape(Helpers::appPath($config, '/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="app-body">
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="app-shell">
    <input class="sidebar-toggle-input" type="checkbox" id="desktopSidebarToggle" aria-hidden="true">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open navigation">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                </button>
                <label class="icon-button d-none d-lg-inline-flex" for="desktopSidebarToggle" title="Collapse navigation" aria-label="Collapse navigation">
                    <i class="fa-solid fa-bars-staggered" aria-hidden="true"></i>
                </label>
                <div class="topbar-title-wrap">
                    <span class="topbar-eyebrow">Workspace</span>
                    <strong class="topbar-page-title"><?= Helpers::escape($pageTitle) ?></strong>
                </div>
            </div>

            <div class="topbar-actions">
                <?php if ($auth->can('products.view')): ?>
                    <form class="global-search d-none d-md-flex" role="search" method="get" action="<?= Helpers::escape(Helpers::appPath($config, '/inventory')) ?>">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <label class="visually-hidden" for="globalSearch">Search products</label>
                        <input id="globalSearch" name="search" type="search" placeholder="Search products, SKU, barcode…" autocomplete="off" aria-keyshortcuts="/">
                        <input type="hidden" name="view" value="products">
                    </form>
                <?php endif; ?>

                <?php if (count($layoutWarehouses) > 1): ?>
                    <form class="warehouse-switcher d-none d-xl-flex" method="post">
                        <input type="hidden" name="_layout_action" value="set_warehouse">
                        <input type="hidden" name="_csrf_token" value="<?= Helpers::escape(karoor_csrf_token()) ?>">
                        <i class="fa-solid fa-warehouse" aria-hidden="true"></i>
                        <label class="visually-hidden" for="warehouseSelector">Active warehouse</label>
                        <select id="warehouseSelector" name="warehouse_id">
                            <?php foreach ($layoutWarehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $activeWarehouseId ? 'selected' : '' ?>>
                                    <?= Helpers::escape((string) $warehouse['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" aria-label="Apply warehouse" title="Apply warehouse"><i class="fa-solid fa-check" aria-hidden="true"></i></button>
                    </form>
                <?php elseif ($activeWarehouse !== null): ?>
                    <div class="warehouse-chip d-none d-xl-flex" title="Active warehouse">
                        <i class="fa-solid fa-warehouse" aria-hidden="true"></i>
                        <span><?= Helpers::escape((string) $activeWarehouse['name']) ?></span>
                    </div>
                <?php endif; ?>

                <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch to light mode" title="Switch to light mode">
                    <i class="fa-solid fa-sun" data-theme-icon aria-hidden="true"></i>
                </button>

                <div class="dropdown">
                    <button class="icon-button notification-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications<?= $unreadNotificationCount > 0 ? ' (' . $unreadNotificationCount . ' unread)' : '' ?>">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-dot"><?= min($unreadNotificationCount, 99) ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notification-menu">
                        <div class="dropdown-heading">
                            <div><strong>Notifications</strong><span><?= $unreadNotificationCount ?> unread</span></div>
                        </div>
                        <div class="notification-list">
                            <?php if ($layoutNotifications === []): ?>
                                <div class="dropdown-empty">
                                    <i class="fa-regular fa-bell-slash" aria-hidden="true"></i>
                                    <strong>You’re all caught up</strong>
                                    <span>New business alerts will appear here.</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($layoutNotifications as $notification): ?>
                                    <?php
                                    $notificationUrl = (string) ($notification['action_url'] ?? '');
                                    $notificationUrl = str_starts_with($notificationUrl, '/') && !str_starts_with($notificationUrl, '//')
                                        ? Helpers::appPath($config, $notificationUrl)
                                        : '';
                                    ?>
                                    <?php if ($notificationUrl !== ''): ?>
                                        <a href="<?= Helpers::escape($notificationUrl) ?>" class="notification-item">
                                    <?php else: ?>
                                        <div class="notification-item">
                                    <?php endif; ?>
                                        <span class="notification-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
                                        <span class="notification-copy">
                                            <strong><?= Helpers::escape((string) $notification['title']) ?></strong>
                                            <span><?= Helpers::escape((string) $notification['message']) ?></span>
                                            <time datetime="<?= Helpers::escape((string) $notification['created_at']) ?>"><?= Helpers::escape(date('M j, H:i', strtotime((string) $notification['created_at']))) ?></time>
                                        </span>
                                    <?php if ($notificationUrl !== ''): ?>
                                        </a>
                                    <?php else: ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dropdown">
                    <button class="profile-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open user menu">
                        <span class="avatar" aria-hidden="true"><?= Helpers::escape($userInitials) ?></span>
                        <span class="profile-copy d-none d-sm-flex">
                            <strong><?= Helpers::escape((string) $currentUser['full_name']) ?></strong>
                            <small><?= Helpers::escape((string) $currentUser['company_name']) ?></small>
                        </span>
                        <i class="fa-solid fa-chevron-down d-none d-sm-inline" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end profile-menu">
                        <div class="profile-menu-heading">
                            <span class="avatar avatar-lg" aria-hidden="true"><?= Helpers::escape($userInitials) ?></span>
                            <span><strong><?= Helpers::escape((string) $currentUser['full_name']) ?></strong><small><?= Helpers::escape((string) $currentUser['email']) ?></small></span>
                        </div>
                        <?php if ($auth->can('settings.manage')): ?>
                            <a class="dropdown-item" href="<?= Helpers::escape(Helpers::appPath($config, '/settings')) ?>"><i class="fa-solid fa-gear" aria-hidden="true"></i> Settings</a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <form method="post">
                            <input type="hidden" name="_layout_action" value="logout">
                            <input type="hidden" name="_csrf_token" value="<?= Helpers::escape(karoor_csrf_token()) ?>">
                            <button class="dropdown-item dropdown-item-danger" type="submit"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content-area" id="mainContent" tabindex="-1">
            <div class="page-heading">
                <nav aria-label="Breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= Helpers::escape(Helpers::appPath($config, '/dashboard')) ?>"><i class="fa-solid fa-house" aria-hidden="true"></i><span class="visually-hidden">Dashboard</span></a></li>
                        <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
                            <?php
                            $breadcrumbLabel = is_array($breadcrumb) ? (string) ($breadcrumb['label'] ?? '') : (string) $breadcrumb;
                            $breadcrumbUrl = is_array($breadcrumb) ? ($breadcrumb['url'] ?? null) : null;
                            $isLastBreadcrumb = $index === array_key_last($breadcrumbs);
                            ?>
                            <li class="breadcrumb-item<?= $isLastBreadcrumb ? ' active' : '' ?>"<?= $isLastBreadcrumb ? ' aria-current="page"' : '' ?>>
                                <?php if (!$isLastBreadcrumb && is_string($breadcrumbUrl)): ?>
                                    <a href="<?= Helpers::escape(Helpers::appPath($config, $breadcrumbUrl)) ?>"><?= Helpers::escape($breadcrumbLabel) ?></a>
                                <?php else: ?>
                                    <?= Helpers::escape($breadcrumbLabel) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <div class="page-title-row">
                    <div>
                        <h1><?= Helpers::escape($pageTitle) ?></h1>
                        <?php if (isset($pageDescription) && is_string($pageDescription) && $pageDescription !== ''): ?>
                            <p><?= Helpers::escape($pageDescription) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($pageActions) && is_callable($pageActions)): ?>
                        <div class="page-actions"><?php $pageActions(); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-content">
