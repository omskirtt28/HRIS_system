-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- Adds "Consultant" to Employee Status (employment_types). No code change: unknown codes map to internal ACTIVE in EmployeeStatusPolicy.
-- Re-runnable. The ledger records that this run inserted the row, so the down script never removes a row someone else created.
CREATE TABLE IF NOT EXISTS employment_type_033_seed(code VARCHAR(40) NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO employment_type_033_seed(code) SELECT 'CONSULTANT' FROM DUAL
  WHERE NOT EXISTS(SELECT 1 FROM employment_types WHERE code='CONSULTANT');
INSERT INTO employment_types (code, name, active, sort_order) VALUES ('CONSULTANT', 'Consultant', 1, 120)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1;
