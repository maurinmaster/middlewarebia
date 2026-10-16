<?php

namespace BiaMiddleware;

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            session_start();
        }
    }

    public static function check(): bool {
        self::startSession();
        return !empty($_SESSION['auth_user_id']);
    }

    public static function user(): ?array {
        self::startSession();
        if (!self::check()) {
            return null;
        }
        return $_SESSION['auth_user'] ?? null;
    }

    public static function login(string $username, string $password): bool {
        self::startSession();
        $db = Database::getConnection();
        
        $cleanUser = trim($username);
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$cleanUser]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = (int)$user['id'];
            $_SESSION['auth_user'] = [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'name' => $user['name'] ?: $user['username']
            ];
            return true;
        }

        // Fallback de contingência para variáveis de ambiente
        $envUser = getenv('ADMIN_USER') ?: 'admin';
        $envPass = getenv('ADMIN_PASSWORD') ?: 'bia@2026';
        if ($cleanUser === $envUser && $password === $envPass) {
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = 1;
            $_SESSION['auth_user'] = [
                'id' => 1,
                'username' => $envUser,
                'name' => 'Administrador'
            ];
            return true;
        }

        return false;
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function requireAuth(): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function changePassword(int $userId, string $newPassword): bool {
        $db = Database::getConnection();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$hash, $userId]);
    }
}
