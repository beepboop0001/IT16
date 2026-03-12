<?php
// auth/LoginSecurity.php
// Brute-force protection for login security

require_once __DIR__ . '/../config/Database.php';

class LoginSecurity {
    
    // Lockout configuration
    private const FIRST_LOCKOUT_ATTEMPTS = 5;
    private const FIRST_LOCKOUT_MINUTES = 1;
    private const SECOND_LOCKOUT_ATTEMPTS = 2;
    private const SECOND_LOCKOUT_MINUTES = 5;
    
    /**
     * Check if account is currently locked
     * 
     * @param string $username
     * @return array ['locked' => bool, 'message' => string, 'minutes_remaining' => int]
     */
    public static function checkAccountLock(string $username): array {
        $db = Database::get();
        $stmt = $db->prepare("SELECT id, lockout_until, lockout_level FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['locked' => false, 'message' => '', 'minutes_remaining' => 0];
        }
        
        // Check if lockout has expired
        if ($user['lockout_until']) {
            $now = new DateTime();
            $lockoutTime = new DateTime($user['lockout_until']);
            
            if ($now < $lockoutTime) {
                $diff = $lockoutTime->diff($now);
                $minutesRemaining = ($diff->i) + ($diff->h * 60) + ($diff->d * 1440);
                
                $level = (int)$user['lockout_level'];
                
                // If 0 or less minutes remaining, show generic message
                if ($minutesRemaining <= 0) {
                    return [
                        'locked' => true,
                        'message' => "Account temporarily locked. Please try again later.",
                        'minutes_remaining' => 0,
                        'level' => $level
                    ];
                }
                
                return [
                    'locked' => true,
                    'message' => "Account temporarily locked. Please try again in {$minutesRemaining} minute(s).",
                    'minutes_remaining' => $minutesRemaining,
                    'level' => $level
                ];
            } else {
                // Lockout has expired, clear it
                self::clearLockout($user['id']);
            }
        }
        
        return ['locked' => false, 'message' => '', 'minutes_remaining' => 0];
    }
    
    /**
     * Record a failed login attempt
     * 
     * @param string $username
     * @return array ['locked' => bool, 'attempts' => int, 'message' => string]
     */
    public static function recordFailedAttempt(string $username): array {
        $db = Database::get();
        
        // Get user
        $stmt = $db->prepare("SELECT id, failed_login_attempts, lockout_level FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['locked' => false, 'attempts' => 0, 'message' => ''];
        }
        
        $currentAttempts = (int)$user['failed_login_attempts'];
        $currentLevel = (int)$user['lockout_level'];
        $newAttempts = $currentAttempts + 1;
        $locked = false;
        $message = '';
        
        // Determine if we need to lock
        if ($currentLevel === 0) {
            // First lockout: lock after 5 attempts
            if ($newAttempts >= self::FIRST_LOCKOUT_ATTEMPTS) {
                $lockoutUntil = (new DateTime())->modify('+' . self::FIRST_LOCKOUT_MINUTES . ' minute')->format('Y-m-d H:i:s');
                $db->prepare("UPDATE users SET failed_login_attempts = ?, lockout_until = ?, lockout_level = 1 WHERE id = ?")
                   ->execute([$newAttempts, $lockoutUntil, $user['id']]);
                $locked = true;
                $message = "Account temporarily locked for " . self::FIRST_LOCKOUT_MINUTES . " minute(s) due to multiple failed login attempts.";
            } else {
                // Just increment attempts
                $db->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
                   ->execute([$newAttempts, $user['id']]);
                $remaining = self::FIRST_LOCKOUT_ATTEMPTS - $newAttempts;
                $message = "Invalid credentials. {$remaining} attempt(s) remaining before account lockout.";
            }
        } elseif ($currentLevel === 1) {
            // Second lockout: lock after 2 more attempts
            if ($newAttempts >= self::FIRST_LOCKOUT_ATTEMPTS + self::SECOND_LOCKOUT_ATTEMPTS) {
                $lockoutUntil = (new DateTime())->modify('+' . self::SECOND_LOCKOUT_MINUTES . ' minute')->format('Y-m-d H:i:s');
                $db->prepare("UPDATE users SET failed_login_attempts = ?, lockout_until = ?, lockout_level = 2 WHERE id = ?")
                   ->execute([$newAttempts, $lockoutUntil, $user['id']]);
                $locked = true;
                $message = "Account temporarily locked for " . self::SECOND_LOCKOUT_MINUTES . " minute(s) due to multiple failed login attempts.";
            } else {
                // Just increment attempts
                $db->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
                   ->execute([$newAttempts, $user['id']]);
                $remaining = (self::FIRST_LOCKOUT_ATTEMPTS + self::SECOND_LOCKOUT_ATTEMPTS) - $newAttempts;
                $message = "Invalid credentials. {$remaining} attempt(s) remaining before extended account lockout.";
            }
        }
        
        return [
            'locked' => $locked,
            'attempts' => $newAttempts,
            'message' => $message
        ];
    }
    
    /**
     * Reset failed attempts on successful login
     * 
     * @param int $userId
     */
    public static function resetFailedAttempts(int $userId): void {
        $db = Database::get();
        $db->prepare("UPDATE users SET failed_login_attempts = 0, lockout_until = NULL, lockout_level = 0 WHERE id = ?")
           ->execute([$userId]);
    }
    
    /**
     * Clear lockout for a user
     * 
     * @param int $userId
     */
    public static function clearLockout(int $userId): void {
        $db = Database::get();
        $db->prepare("UPDATE users SET lockout_until = NULL, lockout_level = 0 WHERE id = ?")
           ->execute([$userId]);
    }
    
    /**
     * Get login attempt statistics for a user
     * 
     * @param string $username
     * @return array
     */
    public static function getAttemptStats(string $username): array {
        $db = Database::get();
        $stmt = $db->prepare("SELECT failed_login_attempts, lockout_until, lockout_level FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return ['attempts' => 0, 'locked' => false, 'level' => 0, 'locked_until' => null];
        }
        
        return [
            'attempts' => (int)$result['failed_login_attempts'],
            'locked' => !empty($result['lockout_until']) && new DateTime($result['lockout_until']) > new DateTime(),
            'level' => (int)$result['lockout_level'],
            'locked_until' => $result['lockout_until']
        ];
    }
    
    /**
     * Get all users currently locked out
     * 
     * @return array
     */
    public static function getLockedOutUsers(): array {
        $db = Database::get();
        $stmt = $db->query("
            SELECT id, username, name, lockout_until, lockout_level, failed_login_attempts
            FROM users
            WHERE lockout_until IS NOT NULL AND lockout_until > NOW()
            ORDER BY lockout_until DESC
        ");
        return $stmt->fetchAll();
    }
}
