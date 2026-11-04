-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- John 2026-11-04: the department "Online Shop" is called "Online". Name only: id, code (ONLINE_SHOP), employees and positions stay.
-- Re-runnable. The ledger records the old name so the down script restores it only if nobody renamed the row again.
CREATE TABLE IF NOT EXISTS department_034_rename(department_id INT NOT NULL PRIMARY KEY, old_name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL) ENGINE=InnoDB;
INSERT INTO department_034_rename(department_id,old_name)
  SELECT id,name FROM departments WHERE code='ONLINE_SHOP' AND name='Online Shop'
    AND NOT EXISTS(SELECT 1 FROM departments o WHERE o.name='Online')
    AND NOT EXISTS(SELECT 1 FROM department_034_rename);
UPDATE departments SET name='Online'
  WHERE code='ONLINE_SHOP' AND name='Online Shop' AND EXISTS(SELECT 1 FROM department_034_rename r WHERE r.department_id=departments.id);
