-- =====================================================================
-- FinSync Phase 1 Migration: Authentication & Security Hardening
-- Safe to run on existing databases. Uses CREATE TABLE IF NOT EXISTS.
-- Does NOT drop any existing tables or delete any user data.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------
-- 1. Add login_attempts & lockout fields to existing users table
--    (safe: ALTER with IF NOT EXISTS via separate checks)
-- -----------------------------------------------------------------
-- Add failed_login_count column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'failed_login_count'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN failed_login_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER role',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add locked_until column if it doesn't exist
SET @col_exists2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'locked_until'
);
SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER failed_login_count',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- -----------------------------------------------------------------
-- 2. Password Reset Tokens
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,  -- SHA-256 hex of the raw token
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_prt_user (user_id),
  INDEX idx_prt_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 3. User Sessions (for logout-all-devices)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  session_token_hash VARCHAR(64) NOT NULL UNIQUE, -- SHA-256 of PHP session ID
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(300) NULL,
  last_active_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_session_user (user_id, expires_at),
  INDEX idx_session_token (session_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 4. Security Audit Log enrichment (add ip_address if missing)
-- -----------------------------------------------------------------
SET @col_exists3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_logs'
      AND COLUMN_NAME = 'ip_address'
);
SET @sql3 = IF(@col_exists3 = 0,
    'ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45) NULL AFTER metadata',
    'SELECT 1'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Done. Verify with:
--   SHOW TABLES;
--   DESCRIBE users;
--   DESCRIBE password_reset_tokens;
--   DESCRIBE user_sessions;
-- =====================================================================

