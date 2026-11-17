-- Select the intended database before running this additive migration (take a dump first). Needs 039 and 046.
-- 13th month pay (Payroll Flow 6, John 2026-11-17): one run per year = basic pay earned in the year's RELEASED payroll runs / 12.
-- Basic = basic/regular pay + paid leave less absences and late (no OT, night, premium or adjustments). Active employees only:
-- resigned employees get theirs in back pay. The tax-exempt ceiling is typed by HR on each run (never seeded, NEEDS VERIFICATION);
-- the excess is recorded as taxable but not withheld. Release by a second person creates one DRAFT journal entry.
-- Idempotent: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS payroll_13th_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pay_year SMALLINT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  exempt_ceiling DECIMAL(15,2) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'COMPUTED',
  active_year SMALLINT UNSIGNED AS (IF(status<>'VOIDED',pay_year,NULL)) STORED,
  employee_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_taxable DECIMAL(15,4) NOT NULL DEFAULT 0,
  computed_by BIGINT UNSIGNED NOT NULL,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by BIGINT UNSIGNED NULL,
  released_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  entry_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_p13_active_year (active_year),
  UNIQUE KEY uq_p13_entry (entry_id),
  CONSTRAINT chk_p13_status CHECK (status IN ('COMPUTED','RELEASED','VOIDED')),
  CONSTRAINT chk_p13_ceiling CHECK (exempt_ceiling >= 0),
  CONSTRAINT chk_p13_two_people CHECK (released_by IS NULL OR released_by <> computed_by),
  CONSTRAINT fk_p13_computer FOREIGN KEY (computed_by) REFERENCES users(id),
  CONSTRAINT fk_p13_releaser FOREIGN KEY (released_by) REFERENCES users(id),
  CONSTRAINT fk_p13_voider FOREIGN KEY (voided_by) REFERENCES users(id),
  CONSTRAINT fk_p13_entry FOREIGN KEY (entry_id) REFERENCES acct_journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_13th_employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  basic_total DECIMAL(15,4) NOT NULL,
  amount DECIMAL(15,4) NOT NULL,
  taxable DECIMAL(15,4) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_p13e_run_employee (run_id, employee_id),
  KEY idx_p13e_employee (employee_id),
  CONSTRAINT fk_p13e_run FOREIGN KEY (run_id) REFERENCES payroll_13th_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_p13e_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
