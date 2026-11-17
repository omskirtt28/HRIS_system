-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- Accounting Phase 1 core (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17): chart of accounts, double-entry journal, closed months.
-- Idempotent: tables use IF NOT EXISTS, the sample chart and grants are seeded once through ledgers.
CREATE TABLE IF NOT EXISTS acct_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  type CHAR(1) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_accounts_code (code),
  CONSTRAINT chk_acct_accounts_type CHECK (type IN ('A','L','E','I','X'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_journal_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(20) NOT NULL,
  entry_date DATE NOT NULL,
  memo VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  reverses_entry_id BIGINT UNSIGNED NULL,
  return_note VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  submitted_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  posted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acct_je_no (entry_no),
  UNIQUE KEY uq_acct_je_reverses (reverses_entry_id),
  KEY idx_acct_je_status_date (status, entry_date),
  KEY idx_acct_je_date (entry_date),
  CONSTRAINT chk_acct_je_status CHECK (status IN ('DRAFT','FOR_APPROVAL','POSTED')),
  CONSTRAINT fk_acct_je_reverses FOREIGN KEY (reverses_entry_id) REFERENCES acct_journal_entries(id),
  CONSTRAINT fk_acct_je_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_acct_je_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acct_journal_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  line_no SMALLINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NULL,
  debit DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_acct_line (entry_id, line_no),
  KEY idx_acct_line_account (account_id),
  CONSTRAINT chk_acct_line_amount CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))),
  CONSTRAINT fk_acct_line_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id),
  CONSTRAINT fk_acct_line_account FOREIGN KEY (account_id) REFERENCES acct_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A row = that month is closed; no entry dated in it can be saved, submitted or posted.
CREATE TABLE IF NOT EXISTS acct_closed_periods (
  period_month DATE NOT NULL PRIMARY KEY,
  closed_by_user_id BIGINT UNSIGNED NOT NULL,
  closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_acct_period_first CHECK (DAY(period_month) = 1),
  CONSTRAINT fk_acct_period_user FOREIGN KEY (closed_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic sample chart (the client's own chart is NEEDS VERIFICATION); seeded once, editable on the Chart of Accounts screen.
CREATE TABLE IF NOT EXISTS acct_core_039_seed (k VARCHAR(40) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @seed_coa=(SELECT COUNT(*)=0 FROM acct_core_039_seed WHERE k='coa');
INSERT IGNORE INTO acct_accounts(code,name,type)
 SELECT * FROM (SELECT '1010' c,'Cash on Hand' n,'A' t UNION ALL SELECT '1020','Cash in Bank - Savings','A' UNION ALL SELECT '1030','Cash in Bank - Payroll','A'
  UNION ALL SELECT '1100','Accounts Receivable','A' UNION ALL SELECT '1200','Advances to Employees','A' UNION ALL SELECT '1300','Input VAT','A'
  UNION ALL SELECT '1500','Office Equipment','A' UNION ALL SELECT '1510','Accumulated Depreciation','A'
  UNION ALL SELECT '2010','Accounts Payable','L' UNION ALL SELECT '2100','SSS Payable','L' UNION ALL SELECT '2110','PhilHealth Payable','L' UNION ALL SELECT '2120','Pag-IBIG Payable','L'
  UNION ALL SELECT '2130','Withholding Tax Payable','L' UNION ALL SELECT '2140','Expanded Withholding Tax Payable','L' UNION ALL SELECT '2200','Output VAT','L'
  UNION ALL SELECT '3010','Owner''s Capital','E' UNION ALL SELECT '3020','Retained Earnings','E'
  UNION ALL SELECT '4010','Service Revenue','I' UNION ALL SELECT '4020','Other Income','I'
  UNION ALL SELECT '5010','Salaries and Wages','X' UNION ALL SELECT '5020','Employer Contributions (SSS, PhilHealth, Pag-IBIG)','X' UNION ALL SELECT '5030','13th Month Pay','X'
  UNION ALL SELECT '5100','Rent Expense','X' UNION ALL SELECT '5110','Utilities Expense','X' UNION ALL SELECT '5120','Office Supplies Expense','X'
  UNION ALL SELECT '5130','Depreciation Expense','X' UNION ALL SELECT '5140','Transportation Expense','X') s WHERE @seed_coa=1;
INSERT IGNORE INTO acct_core_039_seed(k) VALUES('coa');

-- accounting.view: read screens and reports. accounting.entry: Bookkeeper drafts and submits. accounting.approve: Finance Head posts, reverses, closes months, edits the chart.
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('accounting.view','View Accounting','Accounting','Open the Accounting screens: chart of accounts, journal entries, fiscal periods and financial reports (with CSV download).',90),
 ('accounting.entry','Prepare Journal Entries','Accounting','Create and edit draft journal entries and submit them for approval (Bookkeeper).',91),
 ('accounting.approve','Approve and Post Journal Entries','Accounting','Approve and post or return submitted entries, create reversing entries, close and reopen months, and edit the chart of accounts (Finance Head). Cannot approve an entry they prepared.',92);
CREATE TABLE IF NOT EXISTS acct_core_039_grants (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @seed_grants=(SELECT COUNT(*)=0 FROM acct_core_039_seed WHERE k='grants');
-- Admin only; HR roles get it through the existing roles screen. Super Admin passes every check.
INSERT IGNORE INTO acct_core_039_grants(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('accounting.view','accounting.entry','accounting.approve')
 WHERE @seed_grants=1 AND r.code='HRIS_ADMIN'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM acct_core_039_grants;
INSERT IGNORE INTO acct_core_039_seed(k) VALUES('grants');
