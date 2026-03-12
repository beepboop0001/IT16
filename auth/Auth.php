<?php
// Auth.php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/LoginSecurity.php';

class Auth {
    private static ?array $user = null;
    private static array $perms = [];

    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['uid'])) {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT u.*, r.name AS role_name, r.label AS role_label
                                  FROM users u JOIN roles r ON r.id = u.role_id
                                  WHERE u.id = ? AND u.is_active = 1");
            $stmt->execute([$_SESSION['uid']]);
            self::$user = $stmt->fetch() ?: null;
            if (self::$user) {
                $p = $db->prepare("SELECT p.name FROM permissions p
                                   JOIN role_permissions rp ON rp.permission_id = p.id
                                   WHERE rp.role_id = ?");
                $p->execute([self::$user['role_id']]);
                self::$perms = array_column($p->fetchAll(), 'name');
            }
        }
    }

    public static function login(string $username, string $password): bool {
        $db   = Database::get();
        
        // ── Check if account is locked ────────────────────────────────────
        $lockStatus = LoginSecurity::checkAccountLock($username);
        if ($lockStatus['locked']) {
            AuditLog::record(
                action:     'login_blocked_lockout',
                details:    "Login attempt blocked - account locked. Username: \"$username\". Lock level: {$lockStatus['level']}",
                username:   $username,
                role:       'unknown'
            );
            return false;
        }
        
        // ── Get user ──────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT u.*, r.name AS role_name, r.label AS role_label
                              FROM users u JOIN roles r ON r.id = u.role_id
                              WHERE u.username = ? AND u.is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // ── Failed login ──────────────────────────────────────────────────
        if (!$user || !password_verify($password, $user['password'])) {
            $failureResult = LoginSecurity::recordFailedAttempt($username);
            
            AuditLog::record(
                action:     'login_failed',
                details:    "Failed login attempt for username \"$username\". Attempts: {$failureResult['attempts']}.",
                username:   $username,
                role:       'unknown'
            );
            
            // Also log lockout if triggered
            if ($failureResult['locked']) {
                AuditLog::record(
                    action:     'account_locked',
                    details:    "Account \"$username\" locked due to too many failed login attempts.",
                    username:   $username,
                    role:       'unknown'
                );
            }
            
            return false;
        }

        // ── Successful login ──────────────────────────────────────────────
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        self::$user      = $user;
        
        // Reset failed attempts on successful login
        LoginSecurity::resetFailedAttempts($user['id']);

        $p = $db->prepare("SELECT p.name FROM permissions p
                           JOIN role_permissions rp ON rp.permission_id = p.id
                           WHERE rp.role_id = ?");
        $p->execute([$user['role_id']]);
        self::$perms = array_column($p->fetchAll(), 'name');

        AuditLog::record(
            action:     'login_success',
            details:    "User \"{$user['name']}\" logged in successfully.",
            userId:     $user['id'],
            username:   $user['username'],
            role:       $user['role_name']
        );

        return true;
    }

    public static function logout(): void {
        // Record before destroying session
        if (self::$user) {
            AuditLog::record(
                action:   'logout',
                details:  "User \"" . self::$user['name'] . "\" logged out.",
                userId:   self::$user['id'],
                username: self::$user['username'],
                role:     self::$user['role_name']
            );
        }

        session_destroy();
        self::$user  = null;
        self::$perms = [];
    }

    public static function user(): ?array    { return self::$user; }
    public static function check(): bool     { return self::$user !== null; }
    public static function can(string $p): bool { return in_array($p, self::$perms, true); }
    public static function isOwner(): bool   { return (self::$user['role_name'] ?? '') === 'owner'; }
    public static function isCashier(): bool { return (self::$user['role_name'] ?? '') === 'cashier'; }
    public static function isFirstLogin(): bool { return (self::$user['first_login'] ?? 0) === 1; }

    public static function requireLogin(): void {
        if (!self::check()) { header('Location: /index.php'); exit; }
    }
    public static function requirePermission(string $p): void {
        if (!self::can($p)) { header('Location: /pages/dashboard.php?err=forbidden'); exit; }
    }

    /**
     * Update user password and clear first_login flag
     */
    public static function updatePassword(int $userId, string $newPassword): bool {
        $db = Database::get();
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        $stmt = $db->prepare("UPDATE users SET password = ?, first_login = 0 WHERE id = ?");
        $result = $stmt->execute([$hashedPassword, $userId]);
        
        if ($result && self::$user && self::$user['id'] === $userId) {
            self::$user['password'] = $hashedPassword;
            self::$user['first_login'] = 0;
        }
        
        return $result;
    }
}