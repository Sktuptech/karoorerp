<?php

declare(strict_types=1);

/**
 * Karoor ERP application configuration.
 *
 * Values can be supplied by the web server environment or by a non-public
 * .env file in the project root. Existing environment values always win.
 */

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('Karoor ERP requires PHP 8.2 or newer.');
}

if (!function_exists('karoor_load_environment')) {
    /** @return void */
    function karoor_load_environment(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read the environment file.');
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException(sprintf('Invalid environment entry on line %d.', $lineNumber + 1));
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
                throw new RuntimeException(sprintf('Invalid environment key on line %d.', $lineNumber + 1));
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    if ($first === '"') {
                        $value = stripcslashes($value);
                    }
                }
            }

            if (getenv($name) !== false || array_key_exists($name, $_ENV)) {
                continue;
            }

            $_ENV[$name] = $value;
        }
    }
}

if (!function_exists('karoor_env')) {
    function karoor_env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('karoor_is_https')) {
    function karoor_is_https(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $trustedProxy = filter_var(karoor_env('TRUST_PROXY_HEADERS', false), FILTER_VALIDATE_BOOL);
        return $trustedProxy
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}

$projectRoot = dirname(__DIR__);
karoor_load_environment($projectRoot . '/.env');

$environment = strtolower((string) karoor_env('APP_ENV', 'development'));
if (!in_array($environment, ['development', 'testing', 'production'], true)) {
    throw new RuntimeException('APP_ENV must be development, testing, or production.');
}

$debug = filter_var(karoor_env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
$timezone = (string) karoor_env('APP_TIMEZONE', 'Africa/Addis_Ababa');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('APP_TIMEZONE is not a valid PHP timezone identifier.');
}

$currency = strtoupper((string) karoor_env('APP_CURRENCY', 'ETB'));
if (!preg_match('/^[A-Z]{3}$/', $currency)) {
    throw new RuntimeException('APP_CURRENCY must be a three-letter ISO currency code.');
}

$appKey = (string) karoor_env('APP_KEY', '');
if ($environment === 'production' && strlen($appKey) < 32) {
    throw new RuntimeException('APP_KEY must contain at least 32 characters in production.');
}

date_default_timezone_set($timezone);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

$secureCookies = filter_var(
    karoor_env('SESSION_SECURE_COOKIE', $environment === 'production' || karoor_is_https()),
    FILTER_VALIDATE_BOOL
);

$config = [
    'app' => [
        'name' => (string) karoor_env('APP_NAME', 'Karoor ERP'),
        'environment' => $environment,
        'debug' => $debug,
        'url' => rtrim((string) karoor_env('APP_URL', 'http://localhost'), '/'),
        'base_path' => $projectRoot,
        'timezone' => $timezone,
        'currency' => $currency,
        'currency_symbol' => (string) karoor_env('APP_CURRENCY_SYMBOL', 'Br'),
        'date_format' => (string) karoor_env('APP_DATE_FORMAT', 'Y-m-d'),
        'key' => $appKey,
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => (string) karoor_env('DB_HOST', '127.0.0.1'),
        'port' => (int) karoor_env('DB_PORT', 3306),
        'database' => (string) karoor_env('DB_DATABASE', 'karoor_erp'),
        'username' => (string) karoor_env('DB_USERNAME', 'root'),
        'password' => (string) karoor_env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'timezone' => (string) karoor_env('DB_TIMEZONE', '+03:00'),
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => (int) karoor_env('DB_CONNECT_TIMEOUT', 5),
        ],
    ],
    'session' => [
        'name' => (string) karoor_env('SESSION_NAME', 'karoor_session'),
        'lifetime' => (int) karoor_env('SESSION_LIFETIME', 28_800),
        'idle_timeout' => (int) karoor_env('SESSION_IDLE_TIMEOUT', 1_800),
        'regenerate_interval' => (int) karoor_env('SESSION_REGENERATE_INTERVAL', 900),
        'cookie_path' => (string) karoor_env('SESSION_COOKIE_PATH', '/'),
        'cookie_domain' => (string) karoor_env('SESSION_COOKIE_DOMAIN', ''),
        'cookie_secure' => $secureCookies,
        'cookie_httponly' => true,
        'cookie_samesite' => (string) karoor_env('SESSION_SAME_SITE', 'Lax'),
    ],
    'security' => [
        'csrf_token_name' => '_csrf_token',
        'csrf_header' => 'X-CSRF-Token',
        'csrf_token_bytes' => 32,
        'password_algorithm' => PASSWORD_DEFAULT,
        'password_options' => [],
        'login_max_attempts' => (int) karoor_env('LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_seconds' => (int) karoor_env('LOGIN_DECAY_SECONDS', 900),
        'remember_lifetime' => (int) karoor_env('REMEMBER_LIFETIME', 2_592_000),
        'max_upload_bytes' => (int) karoor_env('MAX_UPLOAD_BYTES', 5_242_880),
        'allowed_image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_image_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
    'paths' => [
        'uploads' => $projectRoot . '/uploads',
        'logs' => $projectRoot . '/storage/logs',
        'backups' => $projectRoot . '/storage/backups',
    ],
];

$appUrl = parse_url($config['app']['url']);
if ($appUrl === false || !isset($appUrl['scheme'], $appUrl['host']) || !in_array($appUrl['scheme'], ['http', 'https'], true)) {
    throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
}

if ($environment === 'production') {
    if ($appUrl['scheme'] !== 'https') {
        throw new RuntimeException('APP_URL must use HTTPS in production.');
    }
    if ($config['database']['username'] === '' || strtolower($config['database']['username']) === 'root') {
        throw new RuntimeException('Production must use a dedicated, non-root database user.');
    }
    if ($config['database']['password'] === '') {
        throw new RuntimeException('DB_PASSWORD must not be empty in production.');
    }
}

if ($config['database']['port'] < 1 || $config['database']['port'] > 65_535) {
    throw new RuntimeException('DB_PORT must be between 1 and 65535.');
}

if (
    $config['session']['lifetime'] < 300
    || $config['session']['idle_timeout'] < 60
    || $config['session']['idle_timeout'] > $config['session']['lifetime']
    || $config['session']['regenerate_interval'] < 60
) {
    throw new RuntimeException('Session durations are invalid or unsafe.');
}

if (
    $config['security']['login_max_attempts'] < 1
    || $config['security']['login_decay_seconds'] < 60
    || $config['security']['remember_lifetime'] < 300
    || $config['security']['max_upload_bytes'] < 1
) {
    throw new RuntimeException('Security limits must contain positive, safe values.');
}

if (!in_array($config['session']['cookie_samesite'], ['Lax', 'Strict', 'None'], true)) {
    throw new RuntimeException('SESSION_SAME_SITE must be Lax, Strict, or None.');
}

if ($config['session']['cookie_samesite'] === 'None' && !$config['session']['cookie_secure']) {
    throw new RuntimeException('SameSite=None session cookies must also be Secure.');
}

if (!function_exists('karoor_start_session')) {
    /** @param array<string, mixed> $sessionConfig */
    function karoor_start_session(array $sessionConfig): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot start a secure session after output at %s:%d.', $file, $line));
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $sessionConfig['cookie_secure'] ? '1' : '0');
        ini_set('session.cookie_samesite', (string) $sessionConfig['cookie_samesite']);
        ini_set('session.gc_maxlifetime', (string) $sessionConfig['lifetime']);
        session_name((string) $sessionConfig['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) $sessionConfig['cookie_path'],
            'domain' => (string) $sessionConfig['cookie_domain'],
            'secure' => (bool) $sessionConfig['cookie_secure'],
            'httponly' => true,
            'samesite' => (string) $sessionConfig['cookie_samesite'],
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start the application session.');
        }

        $now = time();
        $lastActivity = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($now - $lastActivity > (int) $sessionConfig['idle_timeout']) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_last_regeneration'] = $now;
        }

        $lastRegeneration = (int) ($_SESSION['_last_regeneration'] ?? 0);
        if ($lastRegeneration === 0 || $now - $lastRegeneration >= (int) $sessionConfig['regenerate_interval']) {
            session_regenerate_id(true);
            $_SESSION['_last_regeneration'] = $now;
        }

        $_SESSION['_last_activity'] = $now;
    }
}

if (!function_exists('karoor_csrf_token')) {
    function karoor_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('A session must be started before requesting a CSRF token.');
        }

        if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('karoor_verify_csrf')) {
    function karoor_verify_csrf(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !is_string($token)) {
            return false;
        }

        $storedToken = $_SESSION['_csrf_token'] ?? null;
        return is_string($storedToken) && hash_equals($storedToken, $token);
    }
}

if (!function_exists('karoor_rotate_csrf_token')) {
    function karoor_rotate_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('A session must be started before rotating a CSRF token.');
        }

        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('karoor_escape')) {
    function karoor_escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('karoor_send_security_headers')) {
    function karoor_send_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; font-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' https://cdn.jsdelivr.net; connect-src 'self'");
    }
}

return $config;
