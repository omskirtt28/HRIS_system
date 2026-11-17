-- Select the intended database before running this additive migration (take a dump first). Needs migrations 039 to 042.
-- Accounting Phase 2 slice A3: bank accounts, payments (disbursement vouchers and collection receipts) and bank reconciliation
-- (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17). Like bills and invoices, a payment keeps no journal lines of its own:
-- its draft journal entry holds them (DV: Dr 2010 AP / Cr bank; CR: Dr bank / Cr 1100 AR), so approval, void and reversal reuse the journal flow.
-- Idempotent: IF NOT EXISTS on every table. Adds nothing to existing tables.
CREATE TABLE IF NOT EXISTS acct_bank_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  bank_name VARCHAR(100) NULL,
  account_no VARCHAR(30) NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_bank_name (name),
  UNIQUE KEY uq_acct_bank_gl (account_id),
  CONSTRAINT fk_acct_bank_gl FOREIGN KEY (account_id) REFERENCES acct_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_no VARCHAR(20) NOT NULL,
  kind ENUM('DISBURSEMENT','COLLECTION') NOT NULL,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  pay_date DATE NOT NULL,
  reference VARCHAR(50) NULL,
  memo VARCHAR(150) NULL,
  total DECIMAL(15,2) NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_payment_no (payment_no),
  UNIQUE KEY uq_acct_payment_entry (entry_id),
  KEY idx_acct_payment_bank (bank_account_id),
  KEY idx_acct_payment_vendor (vendor_id),
  KEY idx_acct_payment_customer (customer_id),
  CONSTRAINT chk_acct_payment_total CHECK (total > 0),
  CONSTRAINT chk_acct_payment_party CHECK ((kind='DISBURSEMENT' AND vendor_id IS NOT NULL AND customer_id IS NULL) OR (kind='COLLECTION' AND customer_id IS NOT NULL AND vendor_id IS NULL)),
  CONSTRAINT fk_acct_payment_bank FOREIGN KEY (bank_account_id) REFERENCES acct_bank_accounts(id),
  CONSTRAINT fk_acct_payment_vendor FOREIGN KEY (vendor_id) REFERENCES acct_vendors(id),
  CONSTRAINT fk_acct_payment_customer FOREIGN KEY (customer_id) REFERENCES acct_customers(id),
  CONSTRAINT fk_acct_payment_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id),
  CONSTRAINT fk_acct_payment_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which bills a disbursement pays, or which invoices a collection settles (partial amounts allowed).
CREATE TABLE IF NOT EXISTS acct_payment_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  bill_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL,
  UNIQUE KEY uq_acct_alloc_bill (payment_id,bill_id),
  UNIQUE KEY uq_acct_alloc_invoice (payment_id,invoice_id),
  KEY idx_acct_alloc_bill (bill_id),
  KEY idx_acct_alloc_invoice (invoice_id),
  CONSTRAINT chk_acct_alloc_amount CHECK (amount > 0),
  CONSTRAINT chk_acct_alloc_doc CHECK ((bill_id IS NULL) <> (invoice_id IS NULL)),
  CONSTRAINT fk_acct_alloc_payment FOREIGN KEY (payment_id) REFERENCES acct_payments(id),
  CONSTRAINT fk_acct_alloc_bill FOREIGN KEY (bill_id) REFERENCES acct_bills(id),
  CONSTRAINT fk_acct_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES acct_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One finished reconciliation per bank account per month; the cleared posted lines are listed in acct_recon_lines.
CREATE TABLE IF NOT EXISTS acct_bank_recons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  period_month DATE NOT NULL,
  statement_balance DECIMAL(15,2) NOT NULL,
  book_balance DECIMAL(15,2) NOT NULL,
  reconciled_by_user_id BIGINT UNSIGNED NOT NULL,
  reconciled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_recon_month (bank_account_id,period_month),
  CONSTRAINT fk_acct_recon_bank FOREIGN KEY (bank_account_id) REFERENCES acct_bank_accounts(id),
  CONSTRAINT fk_acct_recon_user FOREIGN KEY (reconciled_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_recon_lines (
  line_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  recon_id BIGINT UNSIGNED NOT NULL,
  KEY idx_acct_recon_line_recon (recon_id),
  CONSTRAINT fk_acct_recon_line FOREIGN KEY (line_id) REFERENCES acct_journal_lines(id),
  CONSTRAINT fk_acct_recon_line_recon FOREIGN KEY (recon_id) REFERENCES acct_bank_recons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
