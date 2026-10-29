<?php
declare(strict_types=1);

namespace FinSync\Core;

final class Http
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 65536) {
            self::error('The request body is too large.', 'request_too_large', 413);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::json(['error' => ['code' => 'invalid_json', 'message' => 'Send a valid JSON request body.']], 400);
        }
        return $decoded;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function error(string $message, string $code = 'request_failed', int $status = 400): void
    {
        self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function requireJson(): void
    {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            self::error('Send state-changing requests as application/json.', 'unsupported_media_type', 415);
        }
    }
}
