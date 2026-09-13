<?php

declare(strict_types=1);

namespace Karoor\Core;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class Helpers
{
    private static ?string $requestId = null;

    /** @return array<string, mixed> */
    public static function requestData(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $body = file_get_contents('php://input');
            if ($body === false || trim($body) === '') {
                return [];
            }

            try {
                $root = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The request body contains invalid JSON.', 0, $exception);
            }

            if (!is_object($root) || !is_array($data)) {
                throw new RuntimeException('The JSON request body must be an object.');
            }

            return $data;
        }

        return $_POST;
    }

    public static function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return str_contains($accept, 'application/json') || str_contains($uri, '/api/');
    }

    public static function clientIp(): ?string
    {
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trustProxy = function_exists('karoor_env')
            && filter_var(\karoor_env('TRUST_PROXY_HEADERS', false), FILTER_VALIDATE_BOOL);

        if ($trustProxy) {
            $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            foreach ($forwarded as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : null;
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = self::uuid();
        }
        return self::$requestId;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    public static function slug(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transliterated !== false) {
                $value = $transliterated;
            }
        }

        $value = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
        return trim($value, '-') ?: 'item-' . substr(bin2hex(random_bytes(5)), 0, 10);
    }

    public static function escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array{page: int, per_page: int, offset: int} */
    public static function pagination(int $defaultPerPage = 25, int $maximumPerPage = 100): array
    {
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $perPage = filter_var(
            $_GET['per_page'] ?? $defaultPerPage,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => $maximumPerPage]]
        ) ?: $defaultPerPage;

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /** @return array{current_page: int, per_page: int, total: int, last_page: int} */
    public static function paginationMeta(int $total, int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => max(0, $total),
            'last_page' => max(1, (int) ceil(max(0, $total) / max(1, $perPage))),
        ];
    }

    public static function safeRedirect(string $path, int $status = 302): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\r") || str_contains($path, "\n")) {
            $path = '/';
        }

        header('Location: ' . $path, true, $status);
        exit;
    }

    /** @param array<string, mixed> $config */
    public static function appPath(array $config, string $path = '/'): string
    {
        $basePath = parse_url((string) ($config['app']['url'] ?? ''), PHP_URL_PATH);
        $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
        $path = '/' . ltrim($path, '/');

        return ($basePath === '' ? '' : $basePath) . $path;
    }

    public static function nextDocumentNumber(
        Database $database,
        int $companyId,
        ?int $branchId,
        string $documentType,
        ?DateTimeImmutable $date = null
    ): string {
        $documentType = strtoupper(trim($documentType));
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $documentType)) {
            throw new RuntimeException('The document type is invalid.');
        }

        $date ??= new DateTimeImmutable('now');

        return $database->transaction(function (Database $db) use ($companyId, $branchId, $documentType, $date): string {
            $sequence = $db->fetchOne(
                'SELECT id, prefix, next_number, padding, reset_period, last_reset_date
                 FROM document_sequences
                 WHERE company_id = :company_id
                   AND branch_id <=> :branch_id
                   AND document_type = :document_type
                 FOR UPDATE',
                ['company_id' => $companyId, 'branch_id' => $branchId, 'document_type' => $documentType]
            );

            if ($sequence === null) {
                throw new RuntimeException('No numbering sequence is configured for this document type.');
            }

            $period = (string) $sequence['reset_period'];
            $lastReset = isset($sequence['last_reset_date']) ? new DateTimeImmutable((string) $sequence['last_reset_date']) : null;
            $mustReset = match ($period) {
                'YEARLY' => $lastReset === null || $lastReset->format('Y') !== $date->format('Y'),
                'MONTHLY' => $lastReset === null || $lastReset->format('Ym') !== $date->format('Ym'),
                default => false,
            };

            $number = $mustReset ? 1 : (int) $sequence['next_number'];
            $periodPart = match ($period) {
                'YEARLY' => $date->format('Y') . '-',
                'MONTHLY' => $date->format('Ym') . '-',
                default => '',
            };
            $formatted = (string) $sequence['prefix']
                . $periodPart
                . str_pad((string) $number, (int) $sequence['padding'], '0', STR_PAD_LEFT);

            $db->execute(
                'UPDATE document_sequences
                 SET next_number = :next_number, last_reset_date = :last_reset_date
                 WHERE id = :id',
                [
                    'next_number' => $number + 1,
                    'last_reset_date' => $period === 'NEVER' ? $sequence['last_reset_date'] : $date->format('Y-m-d'),
                    'id' => (int) $sequence['id'],
                ]
            );

            return $formatted;
        });
    }
}
