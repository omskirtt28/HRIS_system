-- Select the intended database before running this additive migration (take a dump first). Needs 046.
-- Payroll engine follow-up (John 2026-11-17): per-rate pay method (PER_HOUR = paid by attendance hours; FIXED = half the monthly
-- salary per cutoff less absences, late and undertime) and carry-forward of a negative net to the next payroll (BR-507).
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB).
ALTER TABLE payroll_employee_rates ADD COLUMN IF NOT EXISTS pay_method VARCHAR(10) NOT NULL DEFAULT 'PER_HOUR';
ALTER TABLE payroll_employee_rates DROP CONSTRAINT IF EXISTS chk_per_pay_method;
ALTER TABLE payroll_employee_rates ADD CONSTRAINT chk_per_pay_method CHECK (pay_method IN ('PER_HOUR','FIXED') AND (pay_method='PER_HOUR' OR salary_basis='MONTHLY'));
ALTER TABLE payroll_run_employees ADD COLUMN IF NOT EXISTS carry_out DECIMAL(15,4) NOT NULL DEFAULT 0;
