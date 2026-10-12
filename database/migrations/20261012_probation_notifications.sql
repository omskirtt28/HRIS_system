-- Automatic 6-month probation dates + in-app / email notifications. Idempotent.

-- 1. Probation dates on the employee (filled automatically from the hire date).
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'probation_start_date');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD COLUMN probation_start_date DATE NULL AFTER regularization_date, ADD COLUMN probation_end_date DATE NULL AFTER probation_start_date, ADD KEY idx_employees_probation_end (probation_end_date)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Notifications (one row per recipient; dedupe_key keeps each reminder to once).
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_employee_id BIGINT UNSIGNED NULL,
  recipient_user_id BIGINT UNSIGNED NULL,
  subject_employee_id BIGINT UNSIGNED NULL,
  kind VARCHAR(40) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  link VARCHAR(255) NULL,
  due_date DATE NULL,
  dedupe_key VARCHAR(190) NOT NULL,
  read_at DATETIME NULL,
  email_to VARCHAR(190) NULL,
  emailed_at DATETIME NULL,
  email_error VARCHAR(255) NULL,
  email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_dedupe (dedupe_key),
  KEY idx_notifications_user (recipient_user_id, read_at, created_at),
  KEY idx_notifications_subject (subject_employee_id, kind),
  KEY idx_notifications_email (emailed_at, email_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Once-a-day job guard.
CREATE TABLE IF NOT EXISTS job_runs (
  job VARCHAR(60) NOT NULL,
  last_run_on DATE NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (job)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Backfill: current Probationary employees (6 months from the hire date, ends the day before the anniversary).
UPDATE employees e JOIN employment_types t ON t.id = e.employment_type_id AND t.code = 'PROBATIONARY'
   SET e.probation_start_date = e.hire_date,
       e.probation_end_date   = DATE_SUB(DATE_ADD(e.hire_date, INTERVAL 6 MONTH), INTERVAL 1 DAY)
 WHERE e.hire_date IS NOT NULL AND e.probation_end_date IS NULL;

-- 5. Regularization date follows the probation automatically (6 months after the start).
UPDATE employees e JOIN employment_types t ON t.id = e.employment_type_id AND t.code IN ('PROBATIONARY','RETURNEE_PROBATIONARY')
   SET e.regularization_date = DATE_ADD(e.probation_start_date, INTERVAL 6 MONTH)
 WHERE e.probation_start_date IS NOT NULL AND (e.regularization_date IS NULL OR e.regularization_date <> DATE_ADD(e.probation_start_date, INTERVAL 6 MONTH));

-- 6. Seasonal contracts: 3 months from the hire date, no regularization date.
UPDATE employees e JOIN employment_types t ON t.id = e.employment_type_id AND t.code = 'SEASONAL'
   SET e.probation_start_date = e.hire_date,
       e.probation_end_date   = DATE_SUB(DATE_ADD(e.hire_date, INTERVAL 3 MONTH), INTERVAL 1 DAY),
       e.regularization_date  = NULL
 WHERE e.hire_date IS NOT NULL AND e.probation_end_date IS NULL;
