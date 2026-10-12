-- Employee Status (formerly "Employment type"): HR picks one status; the internal lifecycle status is derived in EmployeeStatusPolicy.
-- Idempotent: safe to run more than once. Table/column names are unchanged (employment_types / employees.employment_type_id).

INSERT INTO employment_types (code, name, active, sort_order) VALUES
  ('REGULAR',               'Regular',               1, 10),
  ('PROBATIONARY',          'Probationary',          1, 20),
  ('SEASONAL',              'Seasonal',              1, 30),
  ('RESIGN',                'Resign',                1, 40),
  ('CONTRACTUAL',           'Contractual',           1, 50),
  ('PART_TIME',             'Part Time',             1, 60),
  ('RETURNEE_PROBATIONARY', 'Returnee-Probationary', 1, 70),
  ('MATERNITY_LEAVE',       'Maternity Leave',       1, 80),
  ('ON_LEAVE',              'On Leave',              1, 90)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1, sort_order = VALUES(sort_order);

-- Not in the requested list: kept as-is, listed after the main statuses.
UPDATE employment_types SET sort_order = 100 WHERE code = 'PROJECT_BASED';
UPDATE employment_types SET sort_order = 110 WHERE code = 'FIXED_TERM';

-- Intern is removed from the choices. Retired (not deleted) so history rows keep resolving.
UPDATE employment_types SET active = 0 WHERE code = 'INTERN';
