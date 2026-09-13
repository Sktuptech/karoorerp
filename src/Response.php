<?php

declare(strict_types=1);

namespace Karoor\Core;

use JsonException;
use Throwable;

final class Response
{
    /** @param mixed $data
     *  @param array<string, mixed> $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        array $meta = [],
        int $status = 200
    ): never {
        self::send(true, $message, $data, [], $meta, $status);
    }

    /** @param array<string, mixed>|list<mixed> $errors
     *  @param array<string, mixed> $meta
     */
    public static function error(
        string $message,
        array $errors = [],
        int $status = 400,
        array $meta = []
    ): never {
        self::send(false, $message, null, $errors, $meta, $status);
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $message = 'Please correct the highlighted fields.'): never
    {
        self::error($message, $errors, 422);
    }

    public static function unauthorized(string $message = 'Authentication is required.'): never
    {
        self::error($message, [], 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): never
    {
        self::error($message, [], 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): never
    {
        self::error($message, [], 404);
    }

    public static function serverError(Throwable $exception): never
    {
        error_log(sprintf(
            '[%s] %s in %s:%d',
            date(DATE_ATOM),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));

        self::error('An unexpected error occurred. Please try again.', [], 500);
    }

    /** @param mixed $data
     *  @param array<string, mixed>|list<mixed> $errors
     *  @param array<string, mixed> $meta
     */
    private static function send(
        bool $success,
        string $message,
        mixed $data,
        array $errors,
        array $meta,
        int $status
    ): never {
        if ($status < 100 || $status > 599) {
            $status = 500;
        }

        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
        }

        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta === [] ? (object) [] : $meta,
        ];

        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            http_response_code(500);
            echo '{"success":false,"message":"Unable to encode the response.","data":null,"errors":[],"meta":{}}';
        }

        exit;
    }
}
