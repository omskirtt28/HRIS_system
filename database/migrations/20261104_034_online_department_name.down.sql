-- Select the intended database first. Guarded: restores "Online Shop" only if this migration renamed the row and it still says "Online".
-- Re-runnable: recreates an empty ledger first so a second run is a no-op.
CREATE TABLE IF NOT EXISTS department_034_rename(department_id INT NOT NULL PRIMARY KEY, old_name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL) ENGINE=InnoDB;
UPDATE departments d JOIN department_034_rename r ON r.department_id=d.id SET d.name=r.old_name WHERE d.name='Online';
SET @drop034 = IF(EXISTS(SELECT 1 FROM departments d JOIN department_034_rename r ON r.department_id=d.id WHERE d.name COLLATE utf8mb4_unicode_ci <> r.old_name COLLATE utf8mb4_unicode_ci), 'SELECT 1', 'DROP TABLE IF EXISTS department_034_rename');
PREPARE s034 FROM @drop034; EXECUTE s034; DEALLOCATE PREPARE s034;
