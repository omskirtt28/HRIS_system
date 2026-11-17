-- Select the intended database before running this additive migration (take a dump first). Needs 039 (acct_journal_entries) and 045.
-- Payroll engine P2-P5 (Plans/2026-11-17-payroll-engine.md, John 2026-11-17): a payroll run per FINALIZED attendance cutoff (BR-501),
-- per-employee results and lines (gross, SSS/PhilHealth/Pag-IBIG split across the month's cutoffs, withholding tax, manual adjustments, net),
-- release by a second person (immutable after, BR-508) which creates one draft journal entry. Lines cite the statutory version used (BR-503).
-- Money is DECIMAL(15,4) (BR-512). Idempotent: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS payroll_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cutoff_id BIGINT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'COMPUTED',
  active_cutoff_id BIGINT UNSIGNED AS (IF(status<>'VOIDED',cutoff_id,NULL)) STORED,
  employee_count INT UNSIGNED NOT NULL DEFAULT 0,
  issue_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_gross DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_employer DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_net DECIMAL(15,4) NOT NULL DEFAULT 0,
  computed_by BIGINT UNSIGNED NOT NULL,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by BIGINT UNSIGNED NULL,
  released_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  entry_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_payroll_run_active (active_cutoff_id),
  UNIQUE KEY uq_payroll_run_entry (entry_id),
  KEY idx_payroll_run_pay (pay_date),
  CONSTRAINT chk_payroll_run_status CHECK (status IN ('COMPUTED','RELEASED','VOIDED')),
  CONSTRAINT chk_payroll_run_two_people CHECK (released_by IS NULL OR released_by <> computed_by),
  CONSTRAINT fk_payroll_run_cutoff FOREIGN KEY (cutoff_id) REFERENCES payroll_cutoffs(id),
  CONSTRAINT fk_payroll_run_computer FOREIGN KEY (computed_by) REFERENCES users(id),
  CONSTRAINT fk_payroll_run_releaser FOREIGN KEY (released_by) REFERENCES users(id),
  CONSTRAINT fk_payroll_run_voider FOREIGN KEY (voided_by) REFERENCES users(id),
  CONSTRAINT fk_payroll_run_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  salary_basis VARCHAR(20) NULL,
  monthly_base DECIMAL(15,4) NOT NULL DEFAULT 0,
  gross DECIMAL(15,4) NOT NULL DEFAULT 0,
  taxable DECIMAL(15,4) NOT NULL DEFAULT 0,
  deductions DECIMAL(15,4) NOT NULL DEFAULT 0,
  employer_share DECIMAL(15,4) NOT NULL DEFAULT 0,
  net DECIMAL(15,4) NOT NULL DEFAULT 0,
  issues_json TEXT NOT NULL,
  UNIQUE KEY uq_pre_run_employee (run_id, employee_id),
  KEY idx_pre_employee (employee_id),
  CONSTRAINT fk_pre_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_employee_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  code VARCHAR(20) NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount DECIMAL(15,4) NOT NULL,
  employer_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  stat_table_id BIGINT UNSIGNED NULL,
  KEY idx_prl_employee (run_employee_id),
  CONSTRAINT chk_prl_kind CHECK (kind IN ('EARNING','DEDUCTION')),
  CONSTRAINT fk_prl_employee FOREIGN KEY (run_employee_id) REFERENCES payroll_run_employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_prl_table FOREIGN KEY (stat_table_id) REFERENCES payroll_stat_tables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_adjustments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pra_run (run_id, employee_id),
  CONSTRAINT chk_pra_kind CHECK (kind IN ('EARNING','DEDUCTION')),
  CONSTRAINT chk_pra_amount CHECK (amount > 0),
  CONSTRAINT fk_pra_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_pra_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_pra_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
