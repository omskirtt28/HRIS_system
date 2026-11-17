-- Select the intended database before running this additive migration (take a dump first). Needs migrations 039 to 041.
-- Accounting Phase 2 slice A2: customers and sales invoices (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17).
-- Same shape as bills (041): an invoice keeps no lines of its own; its draft journal entry holds them
-- (Dr 1100 Accounts Receivable / Cr income lines), so approval, void and reversal reuse the journal flow.
-- Idempotent: IF NOT EXISTS on every table.
CREATE TABLE IF NOT EXISTS acct_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  tin VARCHAR(20) NULL,
  contact VARCHAR(150) NULL,
  terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_customer_name (name),
  CONSTRAINT chk_acct_customer_terms CHECK (terms_days <= 365)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(20) NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  customer_ref VARCHAR(50) NULL,
  memo VARCHAR(150) NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_invoice_no (invoice_no),
  UNIQUE KEY uq_acct_invoice_entry (entry_id),
  KEY idx_acct_invoice_customer (customer_id),
  KEY idx_acct_invoice_due (due_date),
  CONSTRAINT chk_acct_invoice_total CHECK (total > 0),
  CONSTRAINT chk_acct_invoice_due CHECK (due_date >= invoice_date),
  CONSTRAINT fk_acct_invoice_customer FOREIGN KEY (customer_id) REFERENCES acct_customers(id),
  CONSTRAINT fk_acct_invoice_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id),
  CONSTRAINT fk_acct_invoice_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
