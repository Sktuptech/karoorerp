<?php

declare(strict_types=1);

namespace Karoor\Core;

use DateTimeImmutable;
use RuntimeException;

final class Auth
{
    private const SESSION_KEY = 'auth_user';
    private const REMEMBER_COOKIE = 'karoor_remember';
    private const DUMMY_PASSWORD_HASH = '$2y$12$wBo1wvELfPfhcgFfNGEjwePp4Di4BsLK9iANjgm2ZyrbaF045QwD2';

    /** @var array<string, mixed>|null */
    private ?array $resolvedUser = null;

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Database $database,
        private readonly AuditLogger $audit,
        private readonly array $config
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('A secure session must be active before Auth is created.');
        }
    }

    public function login(
        string $identifier,
        string $password,
        bool $remember = false,
        ?int $companyId = null
    ): bool {
        $identifier = trim($identifier);
        $ipAddress = Helpers::clientIp() ?? 'unknown';
        $identifierHash = hash('sha256', strtolower($identifier));

        if ($identifier === '' || $password === '' || $this->isRateLimited($identifierHash, $ipAddress)) {
            return false;
        }

        $parameters = ['username' => $identifier, 'email' => $identifier];
        $companyClause = '';
        if ($companyId !== null) {
            $companyClause = ' AND u.company_id = :company_id';
            $parameters['company_id'] = $companyId;
        }

        $users = $this->database->fetchAll(
            'SELECT u.*
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id
             WHERE (u.username = :username OR u.email = :email)
               AND u.deleted_at IS NULL
               AND c.deleted_at IS NULL
               AND c.is_active = 1' . $companyClause . '
             LIMIT 2',
            $parameters
        );

        $candidateHash = count($users) === 1
            ? (string) $users[0]['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $passwordIsValid = password_verify($password, $candidateHash);
        if (count($users) !== 1 || !$passwordIsValid) {
            $user = count($users) === 1 ? $users[0] : null;
            $this->recordFailedLogin($identifierHash, $ipAddress, $user);
            return false;
        }

        $user = $users[0];
        if (!$this->canAuthenticate($user)) {
            $this->recordFailedLogin($identifierHash, $ipAddress, $user, false);
            return false;
        }

        $newHash = null;
        $algorithm = $this->config['security']['password_algorithm'] ?? PASSWORD_DEFAULT;
        $options = (array) ($this->config['security']['password_options'] ?? []);
        if (password_needs_rehash((string) $user['password_hash'], $algorithm, $options)) {
            $newHash = password_hash($password, $algorithm, $options);
        }

        $this->database->transaction(function (Database $database) use ($user, $identifierHash, $ipAddress, $newHash): void {
            $database->insert('login_attempts', [
                'identifier_hash' => $identifierHash,
                'ip_address' => $ipAddress,
                'was_successful' => 1,
            ]);

            $sql = 'UPDATE users
                    SET failed_login_count = 0,
                        locked_until = NULL,
                        last_login_at = NOW(),
                        last_login_ip = :ip_address';
            $parameters = ['ip_address' => $ipAddress, 'id' => (int) $user['id']];
            if ($newHash !== null) {
                $sql .= ', password_hash = :password_hash';
                $parameters['password_hash'] = $newHash;
            }
            $sql .= ' WHERE id = :id';
            $database->execute($sql, $parameters);

            $this->audit->log(
                'LOGIN',
                'authentication',
                (int) $user['id'],
                null,
                ['status' => 'successful'],
                'users',
                (int) $user['id'],
                (int) $user['company_id']
            );
        });

        $this->establishSession($user);
        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        } else {
            $this->clearRememberToken((int) $user['id']);
        }

        $this->pruneLoginAttempts();
        return true;
    }

    public function resumeFromRememberCookie(): bool
    {
        if ($this->check() || !isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return $this->check();
        }

        $parts = explode('.', (string) $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            $this->expireRememberCookie();
            return false;
        }

        $user = $this->database->fetchOne(
            'SELECT u.*
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id
             WHERE u.id = :id
               AND u.status = \'ACTIVE\'
               AND u.deleted_at IS NULL
               AND c.is_active = 1
               AND c.deleted_at IS NULL
               AND u.remember_token_expires_at > NOW()
             LIMIT 1',
            ['id' => (int) $parts[0]]
        );

        $providedHash = hash('sha256', $parts[1]);
        if (
            $user === null
            || !is_string($user['remember_token_hash'])
            || !hash_equals((string) $user['remember_token_hash'], $providedHash)
        ) {
            $this->expireRememberCookie();
            return false;
        }

        $this->establishSession($user);
        $this->issueRememberToken((int) $user['id']);
        $this->audit->log(
            'LOGIN',
            'authentication',
            (int) $user['id'],
            null,
            ['status' => 'remembered_session'],
            'users',
            (int) $user['id'],
            (int) $user['company_id']
        );

        return true;
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->database->transaction(function (Database $database) use ($user): void {
                $database->execute(
                    'UPDATE users
                     SET remember_token_hash = NULL, remember_token_expires_at = NULL
                     WHERE id = :id',
                    ['id' => (int) $user['id']]
                );
                $this->audit->log(
                    'LOGOUT',
                    'authentication',
                    (int) $user['id'],
                    null,
                    ['status' => 'successful'],
                    'users',
                    (int) $user['id'],
                    (int) $user['company_id']
                );
            });
        }

        $this->expireRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        $this->resolvedUser = null;
        $this->permissionCache = null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        $sessionUser = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($sessionUser) || !isset($sessionUser['id'], $sessionUser['user_agent_hash'])) {
            return null;
        }

        $currentAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!hash_equals((string) $sessionUser['user_agent_hash'], $currentAgentHash)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }

        $this->resolvedUser = $this->database->fetchOne(
            'SELECT u.id, u.company_id, u.branch_id, u.default_warehouse_id,
                    u.username, u.email, u.full_name, u.phone, u.avatar_path,
                    u.locale, u.force_password_change, u.last_login_at,
                    c.name AS company_name, c.currency_code, c.timezone
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id
             WHERE u.id = :id
               AND u.status = \'ACTIVE\'
               AND u.deleted_at IS NULL
               AND c.is_active = 1
               AND c.deleted_at IS NULL
             LIMIT 1',
            ['id' => (int) $sessionUser['id']]
        );

        if ($this->resolvedUser === null) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $this->resolvedUser;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user === null ? null : (int) $user['id'];
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** @param list<string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $permissions */
    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        if ($this->permissionCache === null) {
            $rows = $this->database->fetchAll(
                'SELECT DISTINCT p.name
                 FROM user_roles ur
                 INNER JOIN users u ON u.id = ur.user_id
                 INNER JOIN roles r ON r.id = ur.role_id
                 INNER JOIN role_permissions rp ON rp.role_id = r.id
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = :user_id
                   AND r.is_active = 1
                   AND r.deleted_at IS NULL
                   AND (r.company_id = u.company_id OR r.company_id IS NULL)
                 ORDER BY p.name',
                ['user_id' => (int) $user['id']]
            );
            $this->permissionCache = array_values(array_map(
                static fn (array $row): string => (string) $row['name'],
                $rows
            ));
        }

        return $this->permissionCache;
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public function roles(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        $rows = $this->database->fetchAll(
            'SELECT r.id, r.name, r.slug
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND r.is_active = 1
               AND r.deleted_at IS NULL
               AND (r.company_id = :company_id OR r.company_id IS NULL)
             ORDER BY r.name',
            ['user_id' => (int) $user['id'], 'company_id' => (int) $user['company_id']]
        );

        return array_map(
            static fn (array $role): array => [
                'id' => (int) $role['id'],
                'name' => (string) $role['name'],
                'slug' => (string) $role['slug'],
            ],
            $rows
        );
    }

    public function hasRole(string $slug): bool
    {
        $slug = strtolower(trim($slug));
        foreach ($this->roles() as $role) {
            if (hash_equals((string) $role['slug'], $slug)) {
                return true;
            }
        }

        return false;
    }

    public function changePassword(string $currentPassword, string $newPassword): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $passwordRow = $this->database->fetchOne(
            'SELECT password_hash
             FROM users
             WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
            ['id' => (int) $user['id'], 'company_id' => (int) $user['company_id']]
        );
        if (
            $passwordRow === null
            || !password_verify($currentPassword, (string) $passwordRow['password_hash'])
        ) {
            return false;
        }

        $newHash = password_hash(
            $newPassword,
            $this->config['security']['password_algorithm'],
            (array) $this->config['security']['password_options']
        );
        if (!is_string($newHash)) {
            throw new RuntimeException('Unable to hash the new password.');
        }

        $this->database->transaction(function (Database $database) use ($user, $newHash): void {
            $updated = $database->execute(
                'UPDATE users
                 SET password_hash = :password_hash,
                     force_password_change = 0,
                     remember_token_hash = NULL,
                     remember_token_expires_at = NULL
                 WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL',
                [
                    'password_hash' => $newHash,
                    'id' => (int) $user['id'],
                    'company_id' => (int) $user['company_id'],
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Unable to update the password.');
            }

            $this->audit->log(
                'PASSWORD_CHANGE',
                'authentication',
                (int) $user['id'],
                null,
                ['force_password_change' => false],
                'users',
                (int) $user['id'],
                (int) $user['company_id']
            );
        });

        if (function_exists('karoor_rotate_csrf_token')) {
            \karoor_rotate_csrf_token();
        }
        $this->resolvedUser = null;
        return true;
    }

    /** @return array<string, mixed> */
    public function requireAuth(): array
    {
        $user = $this->user();
        if ($user !== null) {
            return $user;
        }

        if (Helpers::wantsJson()) {
            Response::unauthorized();
        }

        $requestedPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        Helpers::safeRedirect(Helpers::appPath($this->config, '/login') . '?next=' . rawurlencode($requestedPath));
    }

    public function requirePermission(string $permission): void
    {
        $this->requireAuth();
        if ($this->can($permission)) {
            return;
        }

        if (Helpers::wantsJson()) {
            Response::forbidden();
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You do not have permission to perform this action.';
        exit;
    }

    /** @param array<string, mixed> $user */
    private function canAuthenticate(array &$user): bool
    {
        if ($user['status'] === 'LOCKED' && $user['locked_until'] !== null) {
            $lockExpiry = new DateTimeImmutable((string) $user['locked_until']);
            if ($lockExpiry <= new DateTimeImmutable('now')) {
                $this->database->execute(
                    'UPDATE users SET status = \'ACTIVE\', failed_login_count = 0, locked_until = NULL WHERE id = :id',
                    ['id' => (int) $user['id']]
                );
                $user['status'] = 'ACTIVE';
            }
        }

        return $user['status'] === 'ACTIVE';
    }

    /** @param array<string, mixed>|null $user */
    private function recordFailedLogin(
        string $identifierHash,
        string $ipAddress,
        ?array $user,
        bool $incrementUser = true
    ): void {
        $this->database->transaction(function (Database $database) use (
            $identifierHash,
            $ipAddress,
            $user,
            $incrementUser
        ): void {
            $database->insert('login_attempts', [
                'identifier_hash' => $identifierHash,
                'ip_address' => $ipAddress,
                'was_successful' => 0,
            ]);

            if ($user !== null && $incrementUser) {
                $maximum = (int) $this->config['security']['login_max_attempts'];
                $decay = (int) $this->config['security']['login_decay_seconds'];
                $attemptRow = $database->fetchOne(
                    'SELECT COUNT(*) AS attempts
                     FROM login_attempts
                     WHERE identifier_hash = :identifier_hash
                       AND ip_address = :ip_address
                       AND was_successful = 0
                       AND attempted_at >= DATE_SUB(NOW(), INTERVAL :decay SECOND)',
                    ['identifier_hash' => $identifierHash, 'ip_address' => $ipAddress, 'decay' => $decay]
                );
                $failedCount = (int) ($attemptRow['attempts'] ?? 1);
                $shouldLock = $failedCount >= $maximum;
                $database->execute(
                    'UPDATE users
                     SET failed_login_count = :failed_count,
                         status = IF(:lock_status = 1, \'LOCKED\', status),
                         locked_until = IF(:lock_expiry = 1, DATE_ADD(NOW(), INTERVAL :decay SECOND), locked_until)
                     WHERE id = :id',
                    [
                        'failed_count' => $failedCount,
                        'lock_status' => $shouldLock ? 1 : 0,
                        'lock_expiry' => $shouldLock ? 1 : 0,
                        'decay' => $decay,
                        'id' => (int) $user['id'],
                    ]
                );
            }

            $this->audit->log(
                'LOGIN_FAILED',
                'authentication',
                $user === null ? null : (int) $user['id'],
                null,
                ['status' => 'failed'],
                'users',
                $user === null ? null : (int) $user['id'],
                $user === null ? null : (int) $user['company_id']
            );
        });
    }

    private function isRateLimited(string $identifierHash, string $ipAddress): bool
    {
        $row = $this->database->fetchOne(
            'SELECT COUNT(*) AS attempts
             FROM login_attempts
             WHERE identifier_hash = :identifier_hash
               AND ip_address = :ip_address
               AND was_successful = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :decay SECOND)',
            [
                'identifier_hash' => $identifierHash,
                'ip_address' => $ipAddress,
                'decay' => (int) $this->config['security']['login_decay_seconds'],
            ]
        );

        return (int) ($row['attempts'] ?? 0) >= (int) $this->config['security']['login_max_attempts'];
    }

    /** @param array<string, mixed> $user */
    private function establishSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'id' => (int) $user['id'],
            'company_id' => (int) $user['company_id'],
            'user_agent_hash' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'authenticated_at' => time(),
        ];
        $_SESSION['_last_regeneration'] = time();
        $_SESSION['_last_activity'] = time();
        if (function_exists('karoor_rotate_csrf_token')) {
            \karoor_rotate_csrf_token();
        }
        $this->resolvedUser = null;
        $this->permissionCache = null;
    }

    private function issueRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $lifetime = (int) $this->config['security']['remember_lifetime'];
        $this->database->execute(
            'UPDATE users
             SET remember_token_hash = :token_hash,
                 remember_token_expires_at = DATE_ADD(NOW(), INTERVAL :lifetime SECOND)
             WHERE id = :id',
            ['token_hash' => hash('sha256', $token), 'lifetime' => $lifetime, 'id' => $userId]
        );

        $this->setRememberCookie($userId . '.' . $token, time() + $lifetime);
    }

    private function clearRememberToken(int $userId): void
    {
        $this->database->execute(
            'UPDATE users SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE id = :id',
            ['id' => $userId]
        );
        $this->expireRememberCookie();
    }

    private function expireRememberCookie(): void
    {
        $this->setRememberCookie('', time() - 3600);
    }

    private function setRememberCookie(string $value, int $expires): void
    {
        $session = $this->config['session'];
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => (string) $session['cookie_path'],
            'domain' => (string) $session['cookie_domain'],
            'secure' => (bool) $session['cookie_secure'],
            'httponly' => true,
            'samesite' => (string) $session['cookie_samesite'],
        ]);
    }

    private function pruneLoginAttempts(): void
    {
        if (random_int(1, 100) === 1) {
            $this->database->execute('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        }
    }
}
