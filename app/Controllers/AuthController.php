<?php
declare(strict_types=1);

namespace FinSync\Controllers;

use FinSync\Core\Database;
use FinSync\Core\Http;
use FinSync\Core\RateLimiter;
use FinSync\Core\Security;
use FinSync\Services\DemoDataService;
use PDOException;
use Throwable;

final class AuthController
{
    public function register(array $input): void
    {
        if (!RateLimiter::allow('register', 6, 900)) {
            Http::error('Too many registration attempts. Please wait a little and try again.', 'rate_limited', 429);
        }
        $name = trim((string)($input['name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        $nameLength = preg_match_all('/./us', $name);
        if ($nameLength === false || $nameLength < 2 || $nameLength > 120 || preg_match('/[\x00-\x1F<>]/u', $name)) {
            Http::error('Enter a name between 2 and 120 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            Http::error('Enter a valid email address.');
        }
        if (strlen($password) < 10 || strlen($password) > 200) {
            Http::error('Use a password between 10 and 200 characters.');
        }
        if (($input['consent_demo'] ?? false) !== true) {
            Http::error('Please explicitly consent to using the synthetic FinSync demo records.');
        }

        $db = Database::connection();
        try {
            $statement = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password)');
            $statement->execute(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$db->lastInsertId();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Http::error('An account with that email already exists.', 'email_exists', 409);
            }
            throw $exception;
        }

        try {
            (new \FinSync\Services\ConsentService($db))->set($userId, true);
            (new DemoDataService($db))->seedFor($userId, $name);
            $this->audit($userId, 'user.registered', 'user', $userId);
        } catch (Throwable $exception) {
            $delete = $db->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $userId]);
            throw $exception;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        Http::json(['data' => ['user' => ['id' => $userId, 'name' => $name, 'email' => $email], 'csrf_token' => Security::csrfToken()]], 201);
    }

    public function login(array $input): void
    {
        if (!RateLimiter::allow('login', 10, 900)) {
            Http::error('Too many sign-in attempts. Please wait and try again.', 'rate_limited', 429);
        }
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 200) {
            Http::error('Email or password is incorrect.', 'invalid_credentials', 401);
        }
        $statement = Database::connection()->prepare(
            'SELECT id, name, email, password_hash, status FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!$user || $user['status'] !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            Http::error('Email or password is incorrect.', 'invalid_credentials', 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->audit((int)$user['id'], 'user.login', 'user', (int)$user['id']);
        Http::json([
            'data' => [
                'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
                'csrf_token' => Security::csrfToken(),
            ],
        ]);
    }

    public function logout(int $userId): void
    {
        $this->audit($userId, 'user.logout', 'user', $userId);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        Http::json(['data' => ['signed_out' => true]]);
    }

    private function audit(int $userId, string $action, string $type, ?int $resourceId): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, resource_type, resource_id) VALUES (:actor, :action, :type, :resource)'
        );
        $statement->execute(['actor' => $userId, 'action' => $action, 'type' => $type, 'resource' => $resourceId]);
    }
}
