<?php
declare(strict_types=1);

namespace FinSync\Core;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('finsync_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        return (string)($_SESSION['csrf_token'] ?? '');
    }

    public static function requireCsrf(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $val) {
                if (strcasecmp($key, 'X-CSRF-Token') === 0) {
                    $sent = (string)$val;
                    break;
                }
            }
        }
        $known = self::csrfToken();
        if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
            Http::error('This request could not be verified. Refresh the page and try again.', 'csrf_failed', 419);
        }
    }

    public static function currentUserId(): ?int
    {
        $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        return $id === false ? null : ($id === null ? null : (int)$id);
    }

    public static function requireUser(): int
    {
        $id = self::currentUserId();
        if ($id === null) {
            Http::error('Sign in to continue.', 'authentication_required', 401);
        }
        return $id;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
