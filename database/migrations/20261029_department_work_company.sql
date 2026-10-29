-- Select the intended database before running this additive migration.
-- Existing department masters remain 2LOG; employee assignments are unchanged.
SET @has_department_company := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='departments' AND column_name='work_company');
SET @department_company_ddl := IF(@has_department_company=0, 'ALTER TABLE departments ADD COLUMN work_company VARCHAR(10) NOT NULL DEFAULT ''2LOG''', 'SELECT 1');
PREPARE department_company_stmt FROM @department_company_ddl;
EXECUTE department_company_stmt;
DEALLOCATE PREPARE department_company_stmt;
-- Verify the column and classification counts in the selected database as described in README.md.
