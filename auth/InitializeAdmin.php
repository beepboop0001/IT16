<?php
// auth/InitializeAdmin.php
// This script ensures a default admin account exists if no Owner account is present

require_once __DIR__ . '/../config/Database.php';

class InitializeAdmin {
    
    /**
     * Initialize default admin account if no Owner account exists
     * 
     * Default credentials:
     * - Username: admin
     * - Password: 123
     * - Role: Owner
     * - First login: true (must change password)
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public static function ensureDefaultAdmin(): array {
        try {
            $db = Database::get();
            
            // Check if any Owner account exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'owner'");
            $stmt->execute();
            $ownerCount = $stmt->fetchColumn();
            
            if ($ownerCount > 0) {
                return ['success' => true, 'message' => 'Owner account already exists.'];
            }
            
            // Get the Owner role ID
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE name = 'owner'");
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            
            if (!$roleId) {
                return ['success' => false, 'message' => 'Owner role not found in database.'];
            }
            
            // Hash the default password
            $defaultPassword = password_hash('123', PASSWORD_BCRYPT);
            
            // Create the default admin account with first_login = 1
            $insertStmt = $db->prepare("
                INSERT INTO users (name, username, password, role_id, is_active, first_login, created_at)
                VALUES (?, ?, ?, ?, 1, 1, NOW())
            ");
            
            $result = $insertStmt->execute([
                'Default Administrator',
                'admin',
                $defaultPassword,
                $roleId
            ]);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Default admin account created successfully. Username: admin, Password: 123'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to create default admin account.'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error initializing admin account: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if Owner account exists
     */
    public static function ownerExists(): bool {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'owner'");
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get the number of Owner accounts
     */
    public static function getOwnerCount(): int {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'owner'");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}
