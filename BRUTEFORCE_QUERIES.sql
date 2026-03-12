-- ============================================
-- Brute-Force Login Protection - Database Updates
-- Run these queries to update your existing database
-- ============================================

-- Add lockout tracking columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lockout_until DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lockout_level INT DEFAULT 0;

-- Optional: Add indexes for better query performance
ALTER TABLE users ADD INDEX idx_lockout_until (lockout_until);
ALTER TABLE users ADD INDEX idx_username (username);

-- Verify the columns were added
-- SELECT id, username, failed_login_attempts, lockout_until, lockout_level FROM users;

-- Optional: Add audit log entries for new action types (if not already present)
INSERT IGNORE INTO permissions (name, label, description) VALUES
('view_security_logs', 'View Security Logs', 'View login attempts and security events');

-- Optional: View all users currently locked out
-- SELECT id, username, name, lockout_until, lockout_level, failed_login_attempts 
-- FROM users 
-- WHERE lockout_until IS NOT NULL AND lockout_until > NOW() 
-- ORDER BY lockout_until DESC;

-- Optional: Manually unlock a user (replace 5 with actual user_id)
-- UPDATE users SET lockout_until = NULL, lockout_level = 0, failed_login_attempts = 0 
-- WHERE id = 5;

-- Optional: Reset all lockouts (emergency only)
-- UPDATE users SET lockout_until = NULL, lockout_level = 0, failed_login_attempts = 0;
