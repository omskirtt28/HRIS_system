-- Select the intended database before running this additive migration (take a dump first). Needs migrations 039 to 043.
-- Accounting Phase 2 slice A4: government remittances (SSS, PhilHealth, Pag-IBIG, BIR) (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17).
-- Like a bill, a remittance keeps no lines of its own: its draft journal entry holds them (Dr payable lines / Cr the bank's cash account),
-- so its status is the entry's status and approval, void and reversal reuse the journal flow.
-- Deadlines and BIR form numbers are NEEDS VERIFICATION and are not stored. Idempotent: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS acct_remittances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  remittance_no VARCHAR(20) NOT NULL,
  agency VARCHAR(12) NOT NULL,
  period_month DATE NOT NULL,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  reference VARCHAR(50) NOT NULL,
  memo VARCHAR(150) NULL,
  total DECIMAL(15,2) NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_remit_no (remittance_no),
  UNIQUE KEY uq_acct_remit_entry (entry_id),
  KEY idx_acct_remit_period (period_month, agency),
  KEY idx_acct_remit_ref (agency, reference),
  CONSTRAINT chk_acct_remit_agency CHECK (agency IN ('SSS','PHILHEALTH','PAGIBIG','BIR')),
  CONSTRAINT chk_acct_remit_period CHECK (DAY(period_month)=1),
  CONSTRAINT chk_acct_remit_total CHECK (total > 0),
  CONSTRAINT fk_acct_remit_bank FOREIGN KEY (bank_account_id) REFERENCES acct_bank_accounts(id),
  CONSTRAINT fk_acct_remit_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id),
  CONSTRAINT fk_acct_remit_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
