-- Select the intended database before running this additive migration.
-- Existing employee assignments remain unchanged and unclassified.
SET @has_work_company := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='work_company');
SET @work_company_ddl := IF(@has_work_company=0, 'ALTER TABLE employees ADD COLUMN work_company VARCHAR(10) NULL', 'SELECT 1');
PREPARE work_company_stmt FROM @work_company_ddl;
EXECUTE work_company_stmt;
DEALLOCATE PREPARE work_company_stmt;
-- Verify: inspect information_schema.columns for employees.work_company in the selected database.
