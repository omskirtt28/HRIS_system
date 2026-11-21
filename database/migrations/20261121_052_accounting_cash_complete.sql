-- Select the intended database before running this additive migration (take a dump first). Needs 043.
-- Accounting CASH card complete (HRIS-Vault/05-Implementation/Plans/2026-11-21-accounting-cash-complete.md, John 2026-11-21):
-- a cash account kind (bank, e-wallet, petty cash; petty cash has no statement reconciliation) and a cheque register.
-- A cheque may stand alone (free-text payee) or point at one disbursement; CLEARED is set only by a finished reconciliation.
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB) and CREATE TABLE IF NOT EXISTS.
ALTER TABLE acct_bank_accounts ADD COLUMN IF NOT EXISTS kind ENUM('BANK','EWALLET','PETTY') NOT NULL DEFAULT 'BANK' AFTER name;

CREATE TABLE IF NOT EXISTS acct_cheques (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  cheque_no VARCHAR(30) NOT NULL,
  payee VARCHAR(150) NOT NULL,
  cheque_date DATE NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  status ENUM('ISSUED','CLEARED','BOUNCED','VOID','STALE') NOT NULL DEFAULT 'ISSUED',
  payment_id BIGINT UNSIGNED NULL,
  recon_id BIGINT UNSIGNED NULL,
  memo VARCHAR(150) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_cheque_no (bank_account_id,cheque_no),
  UNIQUE KEY uq_acct_cheque_payment (payment_id),
  KEY idx_acct_cheque_recon (recon_id),
  CONSTRAINT chk_acct_cheque_amount CHECK (amount > 0),
  CONSTRAINT fk_acct_cheque_bank FOREIGN KEY (bank_account_id) REFERENCES acct_bank_accounts(id),
  CONSTRAINT fk_acct_cheque_payment FOREIGN KEY (payment_id) REFERENCES acct_payments(id),
  CONSTRAINT fk_acct_cheque_recon FOREIGN KEY (recon_id) REFERENCES acct_bank_recons(id) ON DELETE SET NULL,
  CONSTRAINT fk_acct_cheque_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
