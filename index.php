<?php

declare(strict_types=1);

use Karoor\Core\AuditLogger;
use Karoor\Core\Auth;
use Karoor\Core\Database;
use Karoor\Core\Helpers;
use Karoor\Core\Response;

if (PHP_SAPI === 'cli-server') {
    $developmentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (
        is_string($developmentPath)
        && !str_contains($developmentPath, '..')
        && preg_match('#^/(?:assets/(?:css|js)|uploads/(?:products|employees|company))/[a-zA-Z0-9_./-]+\.(?:css|js|jpg|jpeg|png|webp|gif|svg|ico)$#', $developmentPath) === 1
    ) {
        $developmentFile = realpath(__DIR__ . $developmentPath);
        if (
            $developmentFile !== false
            && str_starts_with($developmentFile, __DIR__ . DIRECTORY_SEPARATOR)
            && is_file($developmentFile)
        ) {
            return false;
        }
    }
}

$config = require __DIR__ . '/config/app.php';

define('KAROOR_BOOTSTRAPPED', true);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/AuditLogger.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Backup.php';

karoor_start_session($config['session']);
karoor_send_security_headers();
header('X-Request-ID: ' . Helpers::requestId());

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

set_exception_handler(
    static function (Throwable $exception) use ($config): void {
        $logDirectory = (string) $config['paths']['logs'];
        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0700, true);
        }

        $message = sprintf(
            "[%s] request=%s %s in %s:%d\n%s\n",
            date(DATE_ATOM),
            Helpers::requestId(),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        $logFile = $logDirectory . '/application-' . date('Y-m-d') . '.log';
        if (!is_dir($logDirectory) || error_log($message, 3, $logFile) === false) {
            error_log($message);
        }

        if (Helpers::wantsJson()) {
            Response::error('An unexpected error occurred. Please try again.', [], 500, [
                'request_id' => Helpers::requestId(),
            ]);
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Application error</title><body>'
            . '<h1>Unable to complete the request</h1>'
            . '<p>Please try again. Reference: ' . Helpers::escape(Helpers::requestId()) . '</p>'
            . '</body></html>';
        exit;
    }
);

$database = new Database($config['database']);
$auditLogger = new AuditLogger($database);
$auth = new Auth($database, $auditLogger, $config);
$auth->resumeFromRememberCookie();

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$configuredBasePath = parse_url((string) $config['app']['url'], PHP_URL_PATH);
$configuredBasePath = is_string($configuredBasePath) ? rtrim($configuredBasePath, '/') : '';
if ($configuredBasePath !== '') {
    if ($requestPath === $configuredBasePath) {
        $requestPath = '/';
    } elseif (str_starts_with($requestPath, $configuredBasePath . '/')) {
        $requestPath = substr($requestPath, strlen($configuredBasePath));
    }
}
$requestPath = '/' . trim($requestPath, '/');

if (str_contains($requestPath, "\0")) {
    http_response_code(400);
    exit('Invalid request path.');
}

if ($requestPath === '/api/v1' || str_starts_with($requestPath, '/api/v1/')) {
    if (Helpers::requestMethod() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $segments = array_values(array_filter(explode('/', trim(substr($requestPath, 7), '/')), 'strlen'));
    $resource = $segments[0] ?? '';
    $apiFiles = [
        'auth' => 'auth.php',
        'dashboard' => 'dashboard.php',
        'pos' => 'pos.php',
        'sales' => 'sales.php',
        'purchases' => 'purchases.php',
        'inventory' => 'inventory.php',
        'customers' => 'customers.php',
        'suppliers' => 'suppliers.php',
        'finance' => 'finance.php',
        'expenses' => 'expenses.php',
        'hrm' => 'hrm.php',
        'reports' => 'reports.php',
        'system' => 'system.php',
    ];

    if (!isset($apiFiles[$resource])) {
        Response::notFound('The requested API endpoint was not found.');
    }

    $apiSegments = array_slice($segments, 1);
    $apiAction = $apiSegments[0] ?? 'index';

    if ($resource !== 'auth') {
        $apiUser = $auth->requireAuth();
        if ((bool) $apiUser['force_password_change']) {
            Response::forbidden('You must change your password before using Karoor ERP.');
        }
    }

    if (!in_array(Helpers::requestMethod(), ['GET', 'HEAD'], true)) {
        $csrfHeader = (string) $config['security']['csrf_header'];
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $csrfHeader));
        $token = $_SERVER[$serverKey] ?? $_POST[$config['security']['csrf_token_name']] ?? null;
        if (!karoor_verify_csrf(is_string($token) ? $token : null)) {
            Response::error('The security token is invalid or has expired.', [], 419);
        }
    }

    $apiFile = __DIR__ . '/api/v1/' . $apiFiles[$resource];
    if (!is_file($apiFile)) {
        Response::notFound('The requested API endpoint is not available.');
    }

    require $apiFile;
    exit;
}

$authenticatedUser = $auth->user();
$mustChangePassword = $authenticatedUser !== null && (bool) $authenticatedUser['force_password_change'];

if ($requestPath === '/') {
    $destination = $authenticatedUser === null
        ? '/login'
        : ($mustChangePassword ? '/change-password' : '/dashboard');
    Helpers::safeRedirect(Helpers::appPath($config, $destination));
}

if ($requestPath === '/login' && $authenticatedUser !== null) {
    Helpers::safeRedirect(Helpers::appPath($config, $mustChangePassword ? '/change-password' : '/dashboard'));
}

if ($mustChangePassword && $requestPath !== '/change-password') {
    Helpers::safeRedirect(Helpers::appPath($config, '/change-password'));
}

$pageRoutes = [
    '/login' => ['views/auth/login.php', null],
    '/change-password' => ['views/auth/change-password.php', null],
    '/dashboard' => ['views/pages/dashboard.php', 'dashboard.view'],
    '/pos' => ['views/pages/pos.php', 'pos.use'],
    '/sales' => ['views/pages/sales.php', ['sales.view', 'sales.view_own']],
    '/purchases' => ['views/pages/purchases.php', 'purchases.view'],
    '/inventory' => ['views/pages/inventory.php', 'inventory.view'],
    '/contacts' => ['views/pages/contacts.php', ['customers.view', 'suppliers.view']],
    '/finance' => ['views/pages/finance.php', 'finance.view'],
    '/expenses' => ['views/pages/expenses.php', 'expenses.view'],
    '/hrm' => ['views/pages/hrm.php', 'hr.view'],
    '/reports' => ['views/pages/reports.php', 'reports.view'],
    '/recycle-bin' => ['views/pages/recycle_bin.php', 'recycle_bin.manage'],
    '/settings' => ['views/pages/settings.php', ['settings.manage', 'users.manage', 'roles.manage', 'audit.view', 'backups.manage']],
    '/print/receipt' => ['views/print/receipt.php', 'sales.print'],
    '/print/invoice' => ['views/print/invoice.php', 'sales.print'],
    '/print/payslip' => ['views/print/payslip.php', 'hr.payroll'],
];

$route = $pageRoutes[$requestPath] ?? null;
if ($route === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Page not found.');
}

[$view, $permission] = $route;
if (is_string($permission)) {
    $auth->requirePermission($permission);
} elseif (is_array($permission)) {
    $auth->requireAuth();
    if (!$auth->canAny($permission)) {
        if (Helpers::wantsJson()) {
            Response::forbidden();
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('You do not have permission to perform this action.');
    }
}

$viewFile = __DIR__ . '/' . $view;
if (!is_file($viewFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Page not found.');
}

$currentUser = $auth->user();
require $viewFile;
