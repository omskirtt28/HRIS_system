-- Lee Code and Payroll Group on the employee master (Ocampo's roster import). Idempotent.
-- Both columns are appended at the end so no existing column moves; no other column changes.
-- employees.lee_code: free-text code from the Lee system, unique when set (blank is stored as NULL, never '').
-- employees.payroll_group: payroll group label from the roster (for example OJEW = Ocampo's).

-- 1. lee_code VARCHAR(30) NULL
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'lee_code');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD COLUMN lee_code VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Unique index (checked first: it only succeeds when no two employees share a Lee Code).
SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'uq_employees_lee_code');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD UNIQUE KEY uq_employees_lee_code (lee_code)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. payroll_group VARCHAR(30) NULL
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'payroll_group');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD COLUMN payroll_group VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'idx_employees_payroll_group');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD KEY idx_employees_payroll_group (payroll_group)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verification
SELECT column_name, column_type, is_nullable FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name IN ('lee_code', 'payroll_group');
SELECT index_name, non_unique FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name IN ('uq_employees_lee_code', 'idx_employees_payroll_group');
