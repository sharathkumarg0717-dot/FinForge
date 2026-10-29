-- FinSync local schema. Import into the empty `finsync` database with phpMyAdmin.
-- Demo fixtures are synthetic; personal_finance_records stores user-entered data.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS simulated_payments;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS recurring_payments;
DROP TABLE IF EXISTS investment_holdings;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS financial_accounts;
DROP TABLE IF EXISTS consents;
DROP TABLE IF EXISTS family_members;
DROP TABLE IF EXISTS personal_finance_records;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  role VARCHAR(30) NOT NULL DEFAULT 'member',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personal_finance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  record_month DATE NOT NULL,
  monthly_income DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_income DECIMAL(12,2) NOT NULL DEFAULT 0,
  monthly_expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_categories LONGTEXT NOT NULL,
  savings DECIMAL(12,2) NOT NULL DEFAULT 0,
  investments DECIMAL(12,2) NOT NULL DEFAULT 0,
  loans_debt DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit_card_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  financial_goals TEXT NOT NULL,
  notes TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_personal_finance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_personal_finance_owner_month (user_id, record_month, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  relationship VARCHAR(50) NOT NULL,
  email VARCHAR(190) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_family_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_family_owner_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  family_member_id BIGINT UNSIGNED NULL,
  member_scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(family_member_id, 0)) STORED,
  scope VARCHAR(64) NOT NULL,
  is_granted TINYINT(1) NOT NULL DEFAULT 0,
  granted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  -- Restrict hard deletes because family_member_id feeds the generated unique key.
  CONSTRAINT fk_consent_member FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_consent_scope (user_id, member_scope_key, scope),
  INDEX idx_consent_active (user_id, scope, is_granted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  family_member_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(40) NOT NULL DEFAULT 'mock_bank',
  institution VARCHAR(120) NOT NULL,
  account_type ENUM('checking','savings','credit','investment','loan','wallet') NOT NULL,
  masked_number VARCHAR(24) NOT NULL,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_synced_at DATETIME NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_member FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  INDEX idx_account_owner_status (user_id, status),
  INDEX idx_account_member (family_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  direction ENUM('income','expense') NOT NULL,
  category VARCHAR(60) NOT NULL,
  description VARCHAR(180) NOT NULL,
  transaction_date DATE NOT NULL,
  provider_reference VARCHAR(80) NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_transaction_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE,
  INDEX idx_transaction_owner_date (user_id, transaction_date),
  INDEX idx_transaction_account_date (account_id, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investment_holdings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  instrument VARCHAR(120) NOT NULL,
  symbol VARCHAR(16) NOT NULL,
  units DECIMAL(14,6) NOT NULL,
  price DECIMAL(14,4) NOT NULL,
  market_value DECIMAL(14,2) NOT NULL,
  valuation_date DATE NOT NULL,
  CONSTRAINT fk_holding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_holding_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE,
  INDEX idx_holding_owner (user_id, valuation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  cadence ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  next_due_date DATE NOT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  is_demo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_recurring_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_recurring_owner_due (user_id, status, next_due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  message VARCHAR(240) NOT NULL,
  due_date DATE NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notification_owner (user_id, is_read, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  resource_type VARCHAR(60) NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_actor_date (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE simulated_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  recurring_payment_id BIGINT UNSIGNED NULL,
  description VARCHAR(140) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  state ENUM('demo_completed') NOT NULL DEFAULT 'demo_completed',
  receipt_reference VARCHAR(48) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sim_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sim_payment_recurring FOREIGN KEY (recurring_payment_id) REFERENCES recurring_payments(id) ON DELETE SET NULL,
  INDEX idx_sim_payment_owner_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
