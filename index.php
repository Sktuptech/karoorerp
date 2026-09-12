<?php
/**
 * ErpPOS - Main Application Entry Point
 * Handles routing, authentication, and view rendering
 * 
 * @version 1.0.0
 * @author Sktuptech
 */

// ============================================================
// ERROR HANDLING & REPORTING
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ============================================================
// SECURITY HEADERS
// ============================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// CONFIGURATION & PATH SETUP
// ============================================================
define('BASE_PATH', __DIR__);
define('APP_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'app');
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'config');
define('SRC_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'src');
define('VIEWS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'views');
define('API_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1');

// Load configuration
if (!file_exists(CONFIG_PATH . '/app.php')) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Configuration file not found',
        'code' => 500
    ]));
}
require_once CONFIG_PATH . '/app.php';

// ============================================================
// AUTOLOADER & CORE CLASSES
// ============================================================
spl_autoload_register(function($class) {
    $file = SRC_PATH . DIRECTORY_SEPARATOR . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load core classes
require_once SRC_PATH . '/Database.php';
require_once SRC_PATH . '/Auth.php';
require_once SRC_PATH . '/Validator.php';
require_once SRC_PATH . '/Response.php';
require_once SRC_PATH . '/Helpers.php';

// ============================================================
// SESSION & AUTHENTICATION
// ============================================================
session_start();

// ============================================================
// REQUEST PARSING - IMPROVED ROUTE MATCHING
// ============================================================

/**
 * Parse clean URI from various sources
 * Handles different server configurations
 */
function getCleanUri() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $basePath = dirname($scriptName);
    
    // Remove base path from request URI
    if (!empty($basePath) && $basePath !== '/' && strpos($requestUri, $basePath) === 0) {
        $requestUri = substr($requestUri, strlen($basePath));
    }
    
    // Handle query string
    if (strpos($requestUri, '?') !== false) {
        $requestUri = substr($requestUri, 0, strpos($requestUri, '?'));
    }
    
    // Normalize: remove trailing slashes (except for root)
    if ($requestUri !== '/' && substr($requestUri, -1) === '/') {
        $requestUri = rtrim($requestUri, '/');
    }
    
    // Ensure leading slash
    if (empty($requestUri)) {
        $requestUri = '/';
    }
    if ($requestUri[0] !== '/') {
        $requestUri = '/' . $requestUri;
    }
    
    return urldecode($requestUri);
}

/**
 * Get base URL for the application
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = ($scriptName === '/' || $scriptName === '\\') ? '' : $scriptName;
    
    return $protocol . '://' . $host . $basePath;
}

// ============================================================
// ROUTE DEFINITIONS
// ============================================================

$pageRoutes = [
    // Authentication routes
    '/'                    => 'auth/login.php',
    '/login'               => 'auth/login.php',
    '/logout'              => 'auth/logout.php',
    
    // Main application routes
    '/dashboard'           => 'pages/dashboard.php',
    '/sales'               => 'pages/sales.php',
    '/purchases'           => 'pages/purchases.php',
    '/inventory'           => 'pages/inventory.php',
    '/pos'                 => 'pages/pos.php',
    '/customers'           => 'pages/customers.php',
    '/contacts'            => 'pages/contacts.php',
    '/suppliers'           => 'pages/suppliers.php',
    '/finance'             => 'pages/finance.php',
    '/expenses'            => 'pages/expenses.php',
    '/hrm'                 => 'pages/hrm.php',
    '/reports'             => 'pages/reports.php',
    '/settings'            => 'pages/settings.php',
    '/recycle-bin'         => 'pages/recycle_bin.php',
];

// API routes (handled separately)
$apiRoutes = [
    '/api/v1/auth'         => 'auth.php',
    '/api/v1/dashboard'    => 'dashboard.php',
    '/api/v1/sales'        => 'sales.php',
    '/api/v1/purchases'    => 'purchases.php',
    '/api/v1/inventory'    => 'inventory.php',
    '/api/v1/pos'          => 'pos.php',
    '/api/v1/customers'    => 'customers.php',
    '/api/v1/suppliers'    => 'suppliers.php',
    '/api/v1/finance'      => 'finance.php',
    '/api/v1/expenses'     => 'expenses.php',
    '/api/v1/hrm'          => 'hrm.php',
    '/api/v1/reports'      => 'reports.php',
    '/api/v1/system'       => 'system.php',
];

// ============================================================
// ROUTE MATCHING & RESOLUTION
// ============================================================

/**
 * Resolve view file path with multiple fallback attempts
 */
function resolveViewPath($viewPath) {
    // Attempt 1: Direct path
    $fullPath = VIEWS_PATH . '/' . ltrim($viewPath, '/');
    if (file_exists($fullPath)) {
        return $fullPath;
    }
    
    // Attempt 2: With .php extension if not present
    if (substr($fullPath, -4) !== '.php') {
        $fullPath .= '.php';
        if (file_exists($fullPath)) {
            return $fullPath;
        }
    }
    
    // Attempt 3: Check as directory with index.php
    $indexPath = VIEWS_PATH . '/' . ltrim($viewPath, '/') . '/index.php';
    if (file_exists($indexPath)) {
        return $indexPath;
    }
    
    return null;
}

/**
 * Resolve API file path
 */
function resolveApiPath($apiPath) {
    $filename = basename($apiPath);
    $fullPath = API_PATH . '/' . $filename . '.php';
    
    if (file_exists($fullPath)) {
        return $fullPath;
    }
    
    return null;
}

// ============================================================
// REQUEST HANDLING
// ============================================================

$requestPath = getCleanUri();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$baseUrl = getBaseUrl();

// Determine if this is an API request
$isApiRequest = strpos($requestPath, '/api/v1/') === 0;

// ============================================================
// API REQUEST HANDLER
// ============================================================

if ($isApiRequest) {
    // Extract API endpoint
    $apiEndpoint = null;
    
    foreach ($apiRoutes as $route => $file) {
        if (strpos($requestPath, $route) === 0) {
            $apiEndpoint = $route;
            break;
        }
    }
    
    if ($apiEndpoint && isset($apiRoutes[$apiEndpoint])) {
        $apiFile = resolveApiPath($apiRoutes[$apiEndpoint]);
        
        if ($apiFile && file_exists($apiFile)) {
            // Set API context and include
            define('IS_API_REQUEST', true);
            include $apiFile;
            exit;
        } else {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'API endpoint not found',
                'path' => $requestPath,
                'code' => 404
            ]);
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid API route',
            'path' => $requestPath,
            'code' => 404
        ]);
        exit;
    }
}

// ============================================================
// PAGE REQUEST HANDLER
// ============================================================

// Check authentication for protected routes
$publicRoutes = ['/', '/login', '/logout'];
$isPublicRoute = in_array($requestPath, $publicRoutes);

// Redirect to login if not authenticated and accessing protected route
if (!$isPublicRoute && !isset($_SESSION['user_id'])) {
    if ($requestPath !== '/login') {
        header('Location: ' . $baseUrl . '/login');
        exit;
    }
}

// Find matching route
$viewPath = null;
$matchedRoute = null;

// Exact match first
if (isset($pageRoutes[$requestPath])) {
    $viewPath = $pageRoutes[$requestPath];
    $matchedRoute = $requestPath;
} else {
    // Try to find partial match or redirect
    foreach ($pageRoutes as $route => $file) {
        if ($route !== '/' && strpos($requestPath, $route) === 0) {
            $viewPath = $file;
            $matchedRoute = $route;
            break;
        }
    }
}

// ============================================================
// VIEW RENDERING
// ============================================================

if ($viewPath) {
    $resolvedPath = resolveViewPath($viewPath);
    
    if ($resolvedPath && file_exists($resolvedPath)) {
        // Set template context variables
        $page = $matchedRoute;
        $baseUrl = $baseUrl;
        $requestPath = $requestPath;
        
        // Load layout and view
        ob_start();
        include $resolvedPath;
        $pageContent = ob_get_clean();
        
        // Include main layout if not API
        if (file_exists(VIEWS_PATH . '/layouts/main.php')) {
            include VIEWS_PATH . '/layouts/main.php';
        } else {
            echo $pageContent;
        }
        exit;
    } else {
        // View file not found
        http_response_code(404);
        include VIEWS_PATH . '/errors/404.php';
        exit;
    }
} else {
    // No matching route found
    http_response_code(404);
    
    // Try to load 404 error page
    if (file_exists(VIEWS_PATH . '/errors/404.php')) {
        include VIEWS_PATH . '/errors/404.php';
    } else {
        // Fallback error response
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Page not found',
            'path' => $requestPath,
            'availableRoutes' => array_keys($pageRoutes),
            'code' => 404
        ]);
    }
    exit;
}

// ============================================================
// FALLBACK - Should not reach here
// ============================================================
http_response_code(500);
echo json_encode([
    'status' => 'error',
    'message' => 'Internal server error',
    'code' => 500
]);
exit;
