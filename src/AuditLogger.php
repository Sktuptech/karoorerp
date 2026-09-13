<?php

declare(strict_types=1);

namespace Karoor\Core;

use JsonException;
use RuntimeException;

final class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'current_password',
        'new_password',
        'password_hash',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'authorization',
        'remember_token',
        'remember_token_hash',
        'csrf_token',
        '_csrf_token',
        'secret',
        'app_key',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function log(
        string $action,
        string $module,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $recordType = null,
        ?int $userId = null,
        ?int $companyId = null
    ): int {
        $action = strtoupper(trim($action));
        $module = strtolower(trim($module));

        if (!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $action)) {
            throw new RuntimeException('The audit action is invalid.');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{1,59}$/', $module)) {
            throw new RuntimeException('The audit module is invalid.');
        }

        $sessionUser = $_SESSION['auth_user'] ?? null;
        if (is_array($sessionUser)) {
            $userId ??= isset($sessionUser['id']) ? (int) $sessionUser['id'] : null;
            $companyId ??= isset($sessionUser['company_id']) ? (int) $sessionUser['company_id'] : null;
        }

        return $this->database->insert('audit_logs', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'old_values' => $this->encode($oldValues),
            'new_values' => $this->encode($newValues),
            'ip_address' => Helpers::clientIp(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'request_id' => Helpers::requestId(),
        ]);
    }

    /** @param array<string, mixed>|null $values */
    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        try {
            return json_encode(
                $this->redact($values),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode audit values.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $values[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
