<?php
// AuditLog.php

require_once __DIR__ . '/../config/Database.php';

class AuditLog {
    public static function record(
        string $action,
        string $details,
        string $entityType = null,
        int $entityId = null,
        int $userId = null,
        string $username = null,
        string $role = null
    ): void {
        try {
            // ── Capture real IP address ───────────────────────────────────
            // Check common proxy headers first, fall back to REMOTE_ADDR
            $ip = $_SERVER['HTTP_CLIENT_IP']
               ?? $_SERVER['HTTP_X_FORWARDED_FOR']
               ?? $_SERVER['REMOTE_ADDR']
               ?? '';

            // HTTP_X_FORWARDED_FOR can be a comma-separated list — take the first (client) IP
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }

            $db   = Database::get();
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (action, details, target_type, target_id, user_id, username, role, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $action,
                $details,
                $entityType,
                $entityId,
                $userId,
                $username,
                $role,
                $ip,
            ]);
        } catch (Exception $e) {
            // Log errors silently to prevent breaking application flow
            error_log("AuditLog::record failed: " . $e->getMessage());
        }
    }
}