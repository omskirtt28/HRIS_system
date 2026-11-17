-- Select the intended database before running this additive migration (take a dump first). Needs 039, 046 and 050.
-- Back pay / final pay (Payroll Flow 7, John 2026-11-17): one live record per archived employee (inactive, resigned, terminated).
-- Back pay = pro-rated 13th month of the separation year (released runs, same base as 050) + leave conversion (SL only for now)
-- + manual earnings, less manual deductions (accountabilities, tax adjustment). The last salary stays in the regular payroll run.
-- Release needs a second person and a "Clearance is complete" confirmation; it creates one DRAFT journal entry.
-- Idempotent: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS payroll_final_pays (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  separation_date DATE NOT NULL,
  pay_date DATE NOT NULL,
  exempt_ceiling DECIMAL(15,2) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'COMPUTED',
  active_employee_id BIGINT UNSIGNED AS (IF(status<>'VOIDED',employee_id,NULL)) STORED,
  basic_ytd DECIMAL(15,4) NOT NULL DEFAULT 0,
  thirteenth DECIMAL(15,4) NOT NULL DEFAULT 0,
  thirteenth_taxable DECIMAL(15,4) NOT NULL DEFAULT 0,
  leave_days DECIMAL(8,2) NOT NULL DEFAULT 0,
  daily_rate DECIMAL(15,4) NOT NULL DEFAULT 0,
  leave_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  earnings_total DECIMAL(15,4) NOT NULL DEFAULT 0,
  deductions_total DECIMAL(15,4) NOT NULL DEFAULT 0,
  net DECIMAL(15,4) NOT NULL DEFAULT 0,
  issues_json TEXT NOT NULL,
  computed_by BIGINT UNSIGNED NOT NULL,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by BIGINT UNSIGNED NULL,
  released_at DATETIME NULL,
  clearance_confirmed_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  entry_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_pfp_active_employee (active_employee_id),
  UNIQUE KEY uq_pfp_entry (entry_id),
  KEY idx_pfp_status (status),
  CONSTRAINT chk_pfp_status CHECK (status IN ('COMPUTED','RELEASED','VOIDED')),
  CONSTRAINT chk_pfp_ceiling CHECK (exempt_ceiling >= 0),
  CONSTRAINT chk_pfp_two_people CHECK (released_by IS NULL OR released_by <> computed_by),
  CONSTRAINT chk_pfp_clearance CHECK (status<>'RELEASED' OR clearance_confirmed_at IS NOT NULL),
  CONSTRAINT fk_pfp_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_pfp_computer FOREIGN KEY (computed_by) REFERENCES users(id),
  CONSTRAINT fk_pfp_releaser FOREIGN KEY (released_by) REFERENCES users(id),
  CONSTRAINT fk_pfp_voider FOREIGN KEY (voided_by) REFERENCES users(id),
  CONSTRAINT fk_pfp_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_final_pay_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  final_pay_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pfpi_pay (final_pay_id),
  CONSTRAINT chk_pfpi_kind CHECK (kind IN ('EARNING','DEDUCTION','TAX')),
  CONSTRAINT chk_pfpi_amount CHECK (amount > 0),
  CONSTRAINT fk_pfpi_pay FOREIGN KEY (final_pay_id) REFERENCES payroll_final_pays(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfpi_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
