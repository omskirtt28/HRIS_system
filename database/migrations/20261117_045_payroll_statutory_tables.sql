-- Select the intended database before running this additive migration (take a dump first).
-- Payroll engine slice P1: statutory rate tables (SSS, PhilHealth, Pag-IBIG, BIR withholding) as effectivity-dated
-- config rows (HRIS-Vault/02-Modules/Payroll.md Flow 8, BR-503/BR-514; plan Plans/2026-11-17-payroll-engine.md).
-- No rate is seeded: every figure is NEEDS VERIFICATION and is entered by a person who read the circular,
-- then verified by a second person. Row column meaning depends on table_code (see app/PayrollStatutory.php).
-- Idempotent: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS payroll_stat_tables (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_code VARCHAR(20) NOT NULL,
  pay_frequency VARCHAR(12) NOT NULL DEFAULT 'MONTHLY',
  version_label VARCHAR(60) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  source_reference VARCHAR(150) NOT NULL,
  entered_by BIGINT UNSIGNED NOT NULL,
  entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  UNIQUE KEY uq_pst_version (table_code, pay_frequency, effective_from),
  CONSTRAINT chk_pst_code CHECK (table_code IN ('SSS','PHILHEALTH','PAGIBIG','BIR_WITHHOLDING')),
  CONSTRAINT chk_pst_freq CHECK (pay_frequency IN ('MONTHLY','SEMI_MONTHLY','WEEKLY','DAILY')),
  CONSTRAINT chk_pst_range CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pst_two_people CHECK (verified_by IS NULL OR verified_by <> entered_by),
  CONSTRAINT fk_pst_enterer FOREIGN KEY (entered_by) REFERENCES users(id),
  CONSTRAINT fk_pst_verifier FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_stat_table_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_id BIGINT UNSIGNED NOT NULL,
  bracket_from DECIMAL(15,4) NOT NULL,
  bracket_to DECIMAL(15,4) NULL,
  fixed_amount DECIMAL(15,4) NULL,
  rate_percent DECIMAL(9,4) NULL,
  employee_amount DECIMAL(15,4) NULL,
  employer_amount DECIMAL(15,4) NULL,
  employee_rate DECIMAL(9,4) NULL,
  employer_rate DECIMAL(9,4) NULL,
  base_ceiling DECIMAL(15,4) NULL,
  UNIQUE KEY uq_pstr_bracket (table_id, bracket_from),
  CONSTRAINT chk_pstr_range CHECK (bracket_to IS NULL OR bracket_to >= bracket_from),
  CONSTRAINT fk_pstr_table FOREIGN KEY (table_id) REFERENCES payroll_stat_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
