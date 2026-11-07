-- =====================================================================
-- FinSync Phase 3 Migration: Multiple Stock Broker Integrations
-- Safe to run on existing databases. Uses CREATE TABLE IF NOT EXISTS.
-- Supports: Zerodha Kite Connect, Dhan API, Groww (CSV/Partner).
-- Does NOT drop any existing tables or delete user data.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------
-- 1. Broker Connections Table
-- Stores encrypted credentials and token lifecycle state
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS broker_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  broker_code VARCHAR(40) NOT NULL, -- 'zerodha', 'dhan', 'groww'
  broker_name VARCHAR(80) NOT NULL,
  client_id VARCHAR(80) NULL,
  account_id BIGINT UNSIGNED NULL,
  api_key_enc TEXT NULL,
  access_token_enc TEXT NULL,
  token_expires_at DATETIME NULL,
  status ENUM('disconnected', 'connected', 'token_expired', 'error') NOT NULL DEFAULT 'disconnected',
  last_sync_at DATETIME NULL,
  sync_status ENUM('idle', 'syncing', 'success', 'failed') NOT NULL DEFAULT 'idle',
  error_message VARCHAR(500) NULL,
  is_live TINYINT(1) NOT NULL DEFAULT 1,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_broker_conn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_user_broker (user_id, broker_code),
  INDEX idx_broker_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 2. Broker Sync Logs Table
-- Audit trail for portfolio synchronizations
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS broker_sync_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  broker_connection_id BIGINT UNSIGNED NULL,
  broker_code VARCHAR(40) NOT NULL,
  sync_type ENUM('api', 'csv', 'manual') NOT NULL DEFAULT 'api',
  holdings_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status ENUM('success', 'failed') NOT NULL,
  error_message VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_broker_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_broker_sync_user (user_id, broker_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
