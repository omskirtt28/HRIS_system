-- Select the intended database first. Guarded: removes Consultant only if this migration added it AND no employee uses it.
-- If it is in use the row and the ledger stay (re-run after those employees are moved to another status).
DELETE FROM employment_types WHERE code='CONSULTANT'
  AND EXISTS(SELECT 1 FROM employment_type_033_seed WHERE code='CONSULTANT')
  AND NOT EXISTS(SELECT 1 FROM employees WHERE employment_type_id=employment_types.id);
SET @drop033 = IF(EXISTS(SELECT 1 FROM employment_types WHERE code='CONSULTANT'), 'SELECT 1', 'DROP TABLE IF EXISTS employment_type_033_seed');
PREPARE s033 FROM @drop033; EXECUTE s033; DEALLOCATE PREPARE s033;
