-- Select the intended database before running this additive migration (take a dump first). Needs migrations 039 to 048.
-- Accounting Phase 3 B3-B5 (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17):
--  * acct_settings: posting accounts by chart code (payroll release mapping, optional Salaries Payable, VAT and EWT accounts).
--  * acct_tax_codes: VAT and EWT (ATC) codes with rates typed in by the client. NOTHING is seeded: rates are NEEDS VERIFICATION.
--  * bills/invoices keep the tax code, rate, amount and account used, so the VAT summary and EWT list never depend on today's settings.
-- With no settings and no tax codes, every screen and posting behaves exactly as before.
-- Idempotent: IF NOT EXISTS on tables and columns (MariaDB).
CREATE TABLE IF NOT EXISTS acct_settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_acct_setting_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_tax_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(3) NOT NULL,
  code VARCHAR(20) NOT NULL,
  description VARCHAR(150) NOT NULL,
  rate DECIMAL(7,4) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_tax_code (kind, code),
  CONSTRAINT chk_acct_tax_kind CHECK (kind IN ('VAT','EWT')),
  CONSTRAINT chk_acct_tax_rate CHECK (rate > 0 AND rate < 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE acct_bills ADD COLUMN IF NOT EXISTS vat_code_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS vat_account_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS ewt_code_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS ewt_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ewt_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ewt_account_id BIGINT UNSIGNED NULL;
ALTER TABLE acct_invoices ADD COLUMN IF NOT EXISTS vat_code_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS vat_account_id BIGINT UNSIGNED NULL;
