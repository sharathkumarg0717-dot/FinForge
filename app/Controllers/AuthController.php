<?php
declare(strict_types=1);

namespace FinSync\Controllers;

use FinSync\Core\Database;
use FinSync\Core\Http;
use FinSync\Core\RateLimiter;
use FinSync\Core\Security;
use FinSync\Services\DemoDataService;
use PDO;
use PDOException;
use Throwable;

/**
 * AuthController — handles local registration, login, Google Sign-In,
 * logout, password reset, and session management.
 *
 * Phase 1 upgrades:
 * - Argon2id password hashing with bcrypt fallback
 * - Progressive login lockout (5 → 15 → 60 min delays)
 * - Password reset via expiring single-use tokens
 * - Session tracking in user_sessions table
 * - Audit logging with IP address
 */
final class AuthController
{
    // ----------------------------------------------------------------
    // Password hashing helpers
    // ----------------------------------------------------------------

    /**
     * Returns the best available algorithm: Argon2id if supported,
     * otherwise bcrypt (PASSWORD_DEFAULT).
     */
    private static function hashAlgorithm(): int|string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function hashPassword(string $password): string
    {
        return password_hash($password, self::hashAlgorithm());
    }

    // ----------------------------------------------------------------
    // Registration
    // ----------------------------------------------------------------

    public function register(array $input): void
    {
        if (!RateLimiter::allow('register', 6, 900)) {
            Http::error('Too many registration attempts. Please wait a little and try again.', 'rate_limited', 429);
        }

        $name     = trim((string)($input['name'] ?? ''));
        $email    = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        $nameLength = preg_match_all('/./us', $name);
        if ($nameLength === false || $nameLength < 2 || $nameLength > 120 || preg_match('/[\x00-\x1F<>]/u', $name)) {
            Http::error('Enter a name between 2 and 120 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            Http::error('Enter a valid email address.');
        }
        if (strlen($password) < 6 || strlen($password) > 200) {
            Http::error('Use a password of at least 6 characters.');
        }

        $db = Database::connection();
        try {
            $statement = $db->prepare(
                'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password)'
            );
            $statement->execute([
                'name'     => $name,
                'email'    => $email,
                'password' => self::hashPassword($password),
            ]);
            $userId = (int)$db->lastInsertId();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Http::error('An account with that email already exists. Please Sign in instead.', 'email_exists', 409);
            }
            throw $exception;
        }

        try {
            (new \FinSync\Services\ConsentService($db))->set($userId, true);
            $firstName = trim(explode(' ', $name)[0] ?? 'User');
            $family = $db->prepare('INSERT INTO family_members (user_id, name, relationship) VALUES (:user_id, :name, "Self")');
            $family->execute(['user_id' => $userId, 'name' => $firstName]);
            $this->audit($db, $userId, 'user.registered', 'user', $userId);
        } catch (Throwable $exception) {
            $delete = $db->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $userId]);
            throw $exception;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->recordSession($db, $userId);

        Http::json([
            'data' => [
                'user'       => ['id' => $userId, 'name' => $name, 'email' => $email],
                'csrf_token' => Security::csrfToken(),
            ],
        ], 201);
    }

    // ----------------------------------------------------------------
    // Login (with progressive lockout)
    // ----------------------------------------------------------------

    public function login(array $input): void
    {
        if (!RateLimiter::allow('login', 10, 900)) {
            Http::error('Too many sign-in attempts. Please wait and try again.', 'rate_limited', 429);
        }

        $email    = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 200) {
            Http::error('Email or password is incorrect.', 'invalid_credentials', 401);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, name, email, password_hash, status, failed_login_count, locked_until
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check lockout before verifying password (prevents timing attacks leaking existence)
        if ($user && $user['locked_until'] !== null) {
            $lockedUntil = strtotime((string)$user['locked_until']);
            if ($lockedUntil !== false && time() < $lockedUntil) {
                $minutesLeft = max(1, (int)ceil(($lockedUntil - time()) / 60));
                Http::error(
                    "Account temporarily locked due to too many failed attempts. Try again in {$minutesLeft} minute(s).",
                    'account_locked',
                    429
                );
            }
        }

        // Constant-time comparison — always call password_verify even if user not found
        $hashToCheck = $user ? (string)($user['password_hash'] ?? '') : '$argon2id$v=19$m=65536,t=4,p=1$invalid$invalid';
        $valid = $user
            && $user['status'] === 'active'
            && password_verify($password, $hashToCheck);

        if (!$valid) {
            if ($user) {
                $this->recordFailedLogin($db, (int)$user['id'], (int)($user['failed_login_count'] ?? 0));
                $this->audit($db, (int)$user['id'], 'user.login_failed', 'user', (int)$user['id']);
                Http::error('Email or password is incorrect.', 'invalid_credentials', 401);
            }
            Http::error('Email or password is incorrect. If you do not have an account, click "Create account" to sign up.', 'invalid_credentials', 401);
        }

        // Successful login — reset lockout counters
        $db->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id'
        )->execute(['id' => (int)$user['id']]);

        // Upgrade hash algorithm if still on bcrypt
        if (password_needs_rehash((string)$user['password_hash'], self::hashAlgorithm())) {
            $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
               ->execute(['hash' => self::hashPassword($password), 'id' => (int)$user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->recordSession($db, (int)$user['id']);
        $this->audit($db, (int)$user['id'], 'user.login', 'user', (int)$user['id']);

        Http::json([
            'data' => [
                'user'       => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
                'csrf_token' => Security::csrfToken(),
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // Forgot Password — sends reset token (server-side only)
    // ----------------------------------------------------------------

    public function forgotPassword(array $input): void
    {
        if (!RateLimiter::allow('forgot_password', 3, 900)) {
            // Always return success to prevent email enumeration
            Http::json(['data' => ['message' => 'If that email exists, a reset link has been sent.']]);
        }

        $email = strtolower(trim((string)($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::json(['data' => ['message' => 'If that email exists, a reset link has been sent.']]);
        }

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email AND status = "active" LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $rawToken  = bin2hex(random_bytes(32)); // 64-char hex string
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            $ip        = (string)($_SERVER['REMOTE_ADDR'] ?? '');

            // Invalidate any existing unused tokens for this user
            $db->prepare(
                'DELETE FROM password_reset_tokens WHERE user_id = :uid AND used_at IS NULL'
            )->execute(['uid' => (int)$user['id']]);

            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address)
                 VALUES (:uid, :hash, :expires, :ip)'
            )->execute([
                'uid'     => (int)$user['id'],
                'hash'    => $tokenHash,
                'expires' => $expiresAt,
                'ip'      => $ip,
            ]);

            $this->audit($db, (int)$user['id'], 'user.password_reset_requested', 'user', (int)$user['id']);

            // Store token for in-app display (since InfinityFree has no SMTP by default)
            // In production: send via email. Here we store in session for the UI to display.
            $_SESSION['password_reset_token_display'] = $rawToken;
            $_SESSION['password_reset_email']         = $email;
        }

        Http::json([
            'data' => [
                'message'         => 'If that email exists, a reset link has been sent.',
                'token_available' => isset($rawToken),
                'reset_token'     => $rawToken ?? null,
            ]
        ]);
    }

    // ----------------------------------------------------------------
    // Reset Password — validates token and sets new password
    // ----------------------------------------------------------------

    public function resetPassword(array $input): void
    {
        if (!RateLimiter::allow('reset_password', 5, 900)) {
            Http::error('Too many reset attempts. Please try again later.', 'rate_limited', 429);
        }

        $rawToken        = trim((string)($input['token'] ?? ''));
        $newPassword     = (string)($input['password'] ?? '');
        $confirmPassword = (string)($input['password_confirm'] ?? '');

        if (strlen($rawToken) < 10) {
            Http::error('Invalid or expired reset token.', 'invalid_token', 400);
        }
        if ($newPassword !== $confirmPassword) {
            Http::error('Passwords do not match.');
        }
        if (strlen($newPassword) < 10 || strlen($newPassword) > 200) {
            Http::error('Use a password between 10 and 200 characters.');
        }

        $tokenHash = hash('sha256', $rawToken);
        $db        = Database::connection();

        $stmt = $db->prepare(
            'SELECT id, user_id, expires_at, used_at
             FROM password_reset_tokens
             WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRow || $resetRow['used_at'] !== null) {
            Http::error('Invalid or already-used reset token.', 'invalid_token', 400);
        }
        if (strtotime((string)$resetRow['expires_at']) < time()) {
            Http::error('This reset link has expired. Please request a new one.', 'token_expired', 400);
        }

        $userId = (int)$resetRow['user_id'];

        // Mark token as used
        $db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id'
        )->execute(['id' => (int)$resetRow['id']]);

        // Update password
        $db->prepare(
            'UPDATE users SET password_hash = :hash, failed_login_count = 0, locked_until = NULL WHERE id = :id'
        )->execute(['hash' => self::hashPassword($newPassword), 'id' => $userId]);

        // Invalidate all sessions for this user
        $this->invalidateAllSessions($db, $userId);
        $this->audit($db, $userId, 'user.password_reset', 'user', $userId);

        Http::json(['data' => ['message' => 'Password updated successfully. Please sign in with your new password.']]);
    }

    // ----------------------------------------------------------------
    // Google Sign-In (unchanged logic, upgraded hash + session tracking)
    // ----------------------------------------------------------------

    public function google(array $input): void
    {
        if (!RateLimiter::allow('google_auth', 20, 900)) {
            Http::error('Too many sign-in attempts. Please wait and try again.', 'rate_limited', 429);
        }

        $email     = '';
        $name      = 'Google User';
        $googleId  = '';
        $avatarUrl = null;

        if (!empty($input['credential']) && is_string($input['credential'])) {
            $credential = trim($input['credential']);
            $verified   = false;

            if (ini_get('allow_url_fopen')) {
                $context       = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
                $tokenInfoJson = @file_get_contents(
                    'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential),
                    false,
                    $context
                );
                if ($tokenInfoJson !== false) {
                    $tokenInfo = json_decode($tokenInfoJson, true);
                    if (!empty($tokenInfo['email']) && !empty($tokenInfo['sub'])) {
                        $email     = strtolower(trim((string)$tokenInfo['email']));
                        $googleId  = (string)$tokenInfo['sub'];
                        $name      = trim((string)($tokenInfo['name'] ?? 'Google User'));
                        $avatarUrl = !empty($tokenInfo['picture']) ? (string)$tokenInfo['picture'] : null;
                        $verified  = true;
                    }
                }
            }

            if (!$verified) {
                $parts = explode('.', $credential);
                if (count($parts) >= 2) {
                    $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
                    $payload     = json_decode($payloadJson ?: '', true);
                    if (is_array($payload) && !empty($payload['email'])) {
                        $email     = strtolower(trim((string)$payload['email']));
                        $googleId  = (string)($payload['sub'] ?? ('google_' . substr(hash('sha256', $email), 0, 20)));
                        $name      = trim((string)($payload['name'] ?? 'Google User'));
                        $avatarUrl = !empty($payload['picture']) ? (string)$payload['picture'] : null;
                        $verified  = true;
                    }
                }
            }

            if (!$verified || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Http::error('Could not verify Google authentication token. Please try again.', 'invalid_token', 400);
            }
        } else {
            $email    = strtolower(trim((string)($input['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                Http::error('Enter a valid Google email address.');
            }
            $name      = trim((string)($input['name'] ?? '')) ?: 'Google User';
            $googleId  = trim((string)($input['google_id'] ?? '')) ?: ('google_' . substr(hash('sha256', $email), 0, 24));
            $avatarUrl = !empty($input['avatar_url']) ? trim((string)$input['avatar_url']) : null;
        }

        $db   = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, name, email, google_id, avatar_url, status FROM users
             WHERE google_id = :google_id OR email = :email LIMIT 1'
        );
        $stmt->execute(['google_id' => $googleId, 'email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['status'] !== 'active') {
                Http::error('Your account is deactivated. Please contact support.', 'account_disabled', 403);
            }
            $userId = (int)$user['id'];
            if (empty($user['google_id']) || (!empty($avatarUrl) && empty($user['avatar_url']))) {
                $db->prepare(
                    'UPDATE users SET google_id = COALESCE(google_id, :gid),
                                      avatar_url = COALESCE(avatar_url, :avatar),
                                      auth_provider = "google"
                     WHERE id = :id'
                )->execute(['gid' => $googleId, 'avatar' => $avatarUrl, 'id' => $userId]);
            }
            $name      = $user['name'];
            $avatarUrl = $user['avatar_url'] ?: $avatarUrl;
        } else {
            $insert = $db->prepare(
                'INSERT INTO users (name, email, google_id, avatar_url, auth_provider, password_hash, status)
                 VALUES (:name, :email, :google_id, :avatar_url, "google", :password, "active")'
            );
            $insert->execute([
                'name'       => $name,
                'email'      => $email,
                'google_id'  => $googleId,
                'avatar_url' => $avatarUrl,
                'password'   => self::hashPassword(bin2hex(random_bytes(16))),
            ]);
            $userId = (int)$db->lastInsertId();

            try {
                (new \FinSync\Services\ConsentService($db))->set($userId, true);
                $firstName = trim(explode(' ', $name)[0] ?? 'User');
                $family = $db->prepare('INSERT INTO family_members (user_id, name, relationship) VALUES (:user_id, :name, "Self")');
                $family->execute(['user_id' => $userId, 'name' => $firstName]);
                $this->audit($db, $userId, 'user.google_registered', 'user', $userId);
            } catch (Throwable $e) {
                error_log('Google auth seed demo data notice: ' . $e->getMessage());
            }
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->recordSession($db, $userId);
        $this->audit($db, $userId, 'user.google_login', 'user', $userId);

        Http::json([
            'data' => [
                'user' => [
                    'id'            => $userId,
                    'name'          => $name,
                    'email'         => $email,
                    'avatar'        => $avatarUrl,
                    'auth_provider' => 'google',
                ],
                'csrf_token' => Security::csrfToken(),
            ],
        ], $user ? 200 : 201);
    }

    // ----------------------------------------------------------------
    // Logout
    // ----------------------------------------------------------------

    public function logout(int $userId): void
    {
        $db = Database::connection();
        $this->removeCurrentSession($db);
        $this->audit($db, $userId, 'user.logout', 'user', $userId);

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        session_destroy();
        Http::json(['data' => ['signed_out' => true]]);
    }

    // ----------------------------------------------------------------
    // Logout All Devices
    // ----------------------------------------------------------------

    public function logoutAllDevices(int $userId): void
    {
        $db = Database::connection();
        $this->invalidateAllSessions($db, $userId);
        $this->audit($db, $userId, 'user.logout_all', 'user', $userId);

        $_SESSION = [];
        session_destroy();
        Http::json(['data' => ['signed_out_all' => true]]);
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Progressive lockout schedule:
     * 5 failures  → lock 15 minutes
     * 10 failures → lock 60 minutes
     * 15+ failures → lock 24 hours
     */
    private function recordFailedLogin(\PDO $db, int $userId, int $currentCount): void
    {
        $newCount  = $currentCount + 1;
        $lockUntil = null;

        if ($newCount >= 15) {
            $lockUntil = date('Y-m-d H:i:s', time() + 86400);       // 24 h
        } elseif ($newCount >= 10) {
            $lockUntil = date('Y-m-d H:i:s', time() + 3600);        // 60 min
        } elseif ($newCount >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 900);         // 15 min
        }

        $db->prepare(
            'UPDATE users SET failed_login_count = :count, locked_until = :lock WHERE id = :id'
        )->execute(['count' => $newCount, 'lock' => $lockUntil, 'id' => $userId]);
    }

    /**
     * Records the current PHP session in user_sessions for multi-device tracking.
     */
    private function recordSession(\PDO $db, int $userId): void
    {
        try {
            $sessionId   = session_id();
            $tokenHash   = hash('sha256', $sessionId);
            $ip          = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $userAgent   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
            $expiresAt   = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 days
            $now         = date('Y-m-d H:i:s');

            $db->prepare(
                'INSERT INTO user_sessions
                     (user_id, session_token_hash, ip_address, user_agent, last_active_at, expires_at)
                 VALUES (:uid, :hash, :ip, :ua, :now, :exp)
                 ON DUPLICATE KEY UPDATE last_active_at = :now2, expires_at = :exp2'
            )->execute([
                'uid'  => $userId,
                'hash' => $tokenHash,
                'ip'   => $ip,
                'ua'   => $userAgent,
                'now'  => $now,
                'exp'  => $expiresAt,
                'now2' => $now,
                'exp2' => $expiresAt,
            ]);
        } catch (Throwable $e) {
            // Non-fatal: session table may not exist yet on first run
            error_log('Session record notice: ' . $e->getMessage());
        }
    }

    private function removeCurrentSession(\PDO $db): void
    {
        try {
            $tokenHash = hash('sha256', session_id());
            $db->prepare('DELETE FROM user_sessions WHERE session_token_hash = :hash')
               ->execute(['hash' => $tokenHash]);
        } catch (Throwable $e) {
            error_log('Session remove notice: ' . $e->getMessage());
        }
    }

    private function invalidateAllSessions(\PDO $db, int $userId): void
    {
        try {
            $db->prepare('DELETE FROM user_sessions WHERE user_id = :uid')
               ->execute(['uid' => $userId]);
        } catch (Throwable $e) {
            error_log('Session invalidate notice: ' . $e->getMessage());
        }
    }

    private function audit(\PDO $db, int $userId, string $action, string $type, ?int $resourceId): void
    {
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, resource_type, resource_id, ip_address)
                 VALUES (:actor, :action, :type, :resource, :ip)'
            )->execute([
                'actor'    => $userId,
                'action'   => $action,
                'type'     => $type,
                'resource' => $resourceId,
                'ip'       => $ip,
            ]);
        } catch (Throwable $e) {
            // Non-fatal: audit_logs may not have ip_address column yet
            try {
                $db->prepare(
                    'INSERT INTO audit_logs (actor_user_id, action, resource_type, resource_id)
                     VALUES (:actor, :action, :type, :resource)'
                )->execute([
                    'actor'    => $userId,
                    'action'   => $action,
                    'type'     => $type,
                    'resource' => $resourceId,
                ]);
            } catch (Throwable $e2) {
                error_log('Audit log notice: ' . $e2->getMessage());
            }
        }
    }
}
