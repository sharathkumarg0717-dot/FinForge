-- =====================================================================
-- FinSync Phase 2 Migration: Expense, Income, Budget & Recurring
-- Safe to run on existing databases. Uses CREATE TABLE IF NOT EXISTS.
-- Does NOT drop any existing tables or delete any user data.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------
-- 1. Expense Categories Table (Default & User Custom)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expense_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL, -- NULL indicates system default available to all
  name VARCHAR(60) NOT NULL,
  type ENUM('expense', 'income') NOT NULL DEFAULT 'expense',
  icon VARCHAR(50) NOT NULL DEFAULT 'fa-tags',
  color VARCHAR(20) NOT NULL DEFAULT '#6366f1',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cat_user (user_id),
  INDEX idx_cat_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default categories if none exist
INSERT IGNORE INTO expense_categories (user_id, name, type, icon, color, is_default) VALUES
  (NULL, 'Salary', 'income', 'fa-money-bill-wave', '#10b981', 1),
  (NULL, 'Business income', 'income', 'fa-briefcase', '#059669', 1),
  (NULL, 'Investments', 'income', 'fa-chart-line', '#0d9488', 1),
  (NULL, 'Other income', 'income', 'fa-wallet', '#14b8a6', 1),
  (NULL, 'Food', 'expense', 'fa-utensils', '#f97316', 1),
  (NULL, 'Groceries', 'expense', 'fa-basket-shopping', '#ea580c', 1),
  (NULL, 'Shopping', 'expense', 'fa-bag-shopping', '#ec4899', 1),
  (NULL, 'Transport', 'expense', 'fa-bus', '#3b82f6', 1),
  (NULL, 'Fuel', 'expense', 'fa-gas-pump', '#0284c7', 1),
  (NULL, 'Rent', 'expense', 'fa-house', '#8b5cf6', 1),
  (NULL, 'Utilities', 'expense', 'fa-bolt', '#eab308', 1),
  (NULL, 'Healthcare', 'expense', 'fa-heart-pulse', '#ef4444', 1),
  (NULL, 'Education', 'expense', 'fa-graduation-cap', '#6366f1', 1),
  (NULL, 'Entertainment', 'expense', 'fa-film', '#a855f7', 1),
  (NULL, 'Travel', 'expense', 'fa-plane', '#06b6d4', 1),
  (NULL, 'Loan payments', 'expense', 'fa-building-columns', '#dc2626', 1),
  (NULL, 'Insurance', 'expense', 'fa-shield-halved', '#4f46e5', 1),
  (NULL, 'Investments', 'expense', 'fa-piggy-bank', '#10b981', 1);

-- -----------------------------------------------------------------
-- 2. Expenses & Income Transactions Table
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('expense', 'income') NOT NULL DEFAULT 'expense',
  amount DECIMAL(12,2) NOT NULL,
  category VARCHAR(60) NOT NULL,
  subcategory VARCHAR(60) NULL,
  payment_method VARCHAR(40) NOT NULL DEFAULT 'cash', -- 'cash', 'upi', 'credit_card', 'debit_card', 'net_banking', 'other'
  transaction_date DATE NOT NULL,
  notes TEXT NULL,
  receipt_filename VARCHAR(255) NULL,
  source ENUM('manual', 'csv', 'bank') NOT NULL DEFAULT 'manual',
  is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expenses_user_date (user_id, transaction_date),
  INDEX idx_expenses_user_cat (user_id, category),
  INDEX idx_expenses_user_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 3. Monthly Budgets Table
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS budgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(60) NOT NULL,
  monthly_limit DECIMAL(12,2) NOT NULL,
  month VARCHAR(7) NOT NULL, -- 'YYYY-MM'
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_user_cat_month (user_id, category, month),
  INDEX idx_budgets_user_month (user_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 4. Recurring Transactions Table
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recurring_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  type ENUM('expense', 'income') NOT NULL DEFAULT 'expense',
  amount DECIMAL(12,2) NOT NULL,
  category VARCHAR(60) NOT NULL,
  frequency ENUM('weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
  day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
  next_due_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recurring_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_recurring_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

