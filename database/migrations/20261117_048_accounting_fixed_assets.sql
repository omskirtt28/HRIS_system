-- Select the intended database before running this additive migration (take a dump first). Needs migrations 039 and 040.
-- Accounting Phase 3 B6: fixed asset register and straight-line depreciation (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17).
-- A depreciation run is one draft journal entry (Dr expense / Cr accumulated depreciation) approved like any entry;
-- acct_depreciation keeps the per-asset amounts of that entry. A voided or reversed entry frees the month again.
-- Idempotent: IF NOT EXISTS on every table.
CREATE TABLE IF NOT EXISTS acct_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_no VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  acquired_on DATE NOT NULL,
  cost DECIMAL(15,2) NOT NULL,
  salvage DECIMAL(15,2) NOT NULL DEFAULT 0,
  life_months SMALLINT UNSIGNED NOT NULL,
  asset_account_id BIGINT UNSIGNED NOT NULL,
  accum_account_id BIGINT UNSIGNED NOT NULL,
  expense_account_id BIGINT UNSIGNED NOT NULL,
  disposed_on DATE NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_asset_no (asset_no),
  CONSTRAINT chk_acct_asset_cost CHECK (cost > 0 AND salvage >= 0 AND salvage < cost),
  CONSTRAINT chk_acct_asset_life CHECK (life_months BETWEEN 1 AND 600),
  CONSTRAINT chk_acct_asset_disposed CHECK (disposed_on IS NULL OR disposed_on >= acquired_on),
  CONSTRAINT fk_acct_asset_gl FOREIGN KEY (asset_account_id) REFERENCES acct_accounts(id),
  CONSTRAINT fk_acct_asset_accum FOREIGN KEY (accum_account_id) REFERENCES acct_accounts(id),
  CONSTRAINT fk_acct_asset_exp FOREIGN KEY (expense_account_id) REFERENCES acct_accounts(id),
  CONSTRAINT fk_acct_asset_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_depreciation (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT UNSIGNED NOT NULL,
  period_month DATE NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  KEY idx_acct_dep_asset (asset_id, period_month),
  KEY idx_acct_dep_entry (entry_id),
  CONSTRAINT chk_acct_dep_amount CHECK (amount > 0),
  CONSTRAINT fk_acct_dep_asset FOREIGN KEY (asset_id) REFERENCES acct_assets(id),
  CONSTRAINT fk_acct_dep_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
