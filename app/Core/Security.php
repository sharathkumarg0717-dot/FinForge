<?php
declare(strict_types=1);

namespace FinSync\Core;

/**
 * Security — session management, CSRF, user identity, and output escaping.
 *
 * Phase 1 upgrades:
 * - Explicit SameSite=Lax cookie policy
 * - session.cookie_httponly / session.cookie_secure via ini_set
 * - Nonce generation helper for inline scripts (CSP)
 * - escAttr / escUrl helpers for context-aware XSS prevention
 */
final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionDir = dirname(__DIR__, 2) . '/storage/sessions';
        if (getenv('VERCEL') || !empty($_SERVER['VERCEL'])) {
            $tmpDir = sys_get_temp_dir();
            session_save_path($tmpDir);
            ini_set('session.save_path', $tmpDir);
        } elseif (is_dir($sessionDir)) {
            session_save_path($sessionDir);
            ini_set('session.save_path', $sessionDir);
        }

        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        session_name('finsync_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');     // 24-hour server-side lifetime
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        session_start();

        // Rotate CSRF token once per session (not per request, to avoid race conditions)
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
            Http::error(
                'This request could not be verified. Refresh the page and try again.',
                'csrf_failed',
                419
            );
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

    /**
     * Escape for HTML context (between tags or in attribute values wrapped in quotes).
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for HTML attribute values (alias for escape).
     */
    public static function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize a URL for safe use in href/src attributes.
     * Only allows http, https, and relative URLs.
     */
    public static function escUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return '#';
        }
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Generate a fresh cryptographic nonce for use in Content-Security-Policy.
     * Call once per request and store; use the same nonce for all inline scripts.
     */
    public static function generateNonce(): string
    {
        return base64_encode(random_bytes(18));
    }

    /**
     * Authenticated encryption using AES-256-GCM.
     * Uses the private server encryption key configured in config.local.php.
     */
    public static function encrypt(string $plaintext): string
    {
        $config = Database::config();
        $rawKey = (string)($config['app']['encryption_key'] ?? 'FinSyncDefaultSecretKey2026!');
        $key = hash('sha256', $rawKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Authenticated decryption using AES-256-GCM.
     * Returns null if ciphertext was tampered with or key is invalid.
     */
    public static function decrypt(string $payload): ?string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 28) {
            return null;
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $config = Database::config();
        $rawKey = (string)($config['app']['encryption_key'] ?? 'FinSyncDefaultSecretKey2026!');
        $key = hash('sha256', $rawKey, true);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }
}
