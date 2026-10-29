-- Apply once to an existing FinSync database to add personal finance records.
-- This does not drop or modify existing tables or financial data.
CREATE TABLE IF NOT EXISTS personal_finance_records (
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
