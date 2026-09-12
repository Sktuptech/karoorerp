<?php
/**
 * 404 Error Page - Page Not Found
 * Displayed when a requested page or resource cannot be found
 */

// Set HTTP status code
http_response_code(404);

// Determine if JSON response is expected (API calls)
$acceptJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
$isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

if ($acceptJson || $isApiRequest) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Page or resource not found',
        'path' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'code' => 404
    ]);
} else {
    // HTML error page
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 60px 40px;
            text-align: center;
        }
        
        .error-code {
            font-size: 120px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 20px;
            line-height: 1;
            text-shadow: 2px 2px 4px rgba(102, 126, 234, 0.1);
        }
        
        .error-title {
            font-size: 32px;
            color: #2c3e50;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .error-description {
            font-size: 16px;
            color: #7f8c8d;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .requested-path {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 30px;
            word-break: break-all;
            color: #495057;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            font-weight: 600;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #e9ecef;
            color: #2c3e50;
        }
        
        .btn-secondary:hover {
            background: #dee2e6;
            transform: translateY(-2px);
        }
        
        .helpful-links {
            margin-top: 40px;
            padding-top: 40px;
            border-top: 1px solid #e9ecef;
            text-align: left;
        }
        
        .helpful-links h3 {
            color: #2c3e50;
            font-size: 18px;
            margin-bottom: 15px;
        }
        
        .helpful-links ul {
            list-style: none;
        }
        
        .helpful-links li {
            margin-bottom: 10px;
        }
        
        .helpful-links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .helpful-links a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .footer-text {
            margin-top: 40px;
            font-size: 14px;
            color: #95a5a6;
        }
        
        .footer-text strong {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">404</div>
        <div class="error-title">Page Not Found</div>
        <div class="error-description">
            Sorry! The page you're looking for doesn't exist or has been moved.
        </div>
        
        <div class="requested-path">
            <strong>Requested:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'unknown'); ?>
        </div>
        
        <div class="action-buttons">
            <a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) : '/'; ?>" class="btn btn-primary">
                ← Go to Home
            </a>
            <button class="btn btn-secondary" onclick="history.back();">
                ← Go Back
            </button>
        </div>
        
        <div class="helpful-links">
            <h3>Quick Navigation</h3>
            <ul>
                <li><a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) : '/'; ?>">Dashboard</a></li>
                <li><a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) . '/sales' : '/sales'; ?>">Sales</a></li>
                <li><a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) . '/inventory' : '/inventory'; ?>">Inventory</a></li>
                <li><a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) . '/customers' : '/customers'; ?>">Customers</a></li>
                <li><a href="<?php echo isset($baseUrl) ? htmlspecialchars($baseUrl) . '/reports' : '/reports'; ?>">Reports</a></li>
            </ul>
        </div>
        
        <div class="footer-text">
            <strong>Error Code:</strong> HTTP 404<br>
            <strong>Timestamp:</strong> <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>
    <?php
}
