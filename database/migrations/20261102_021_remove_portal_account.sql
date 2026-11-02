-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- Remove portal account (SPEC-remove-portal-account.md): 4 nullable users columns that record a soft removal, plus the users.remove permission
-- for HR. Idempotent. No existing row is changed (ADD COLUMN IF NOT EXISTS and INSERT IGNORE only).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS removed_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS removed_email VARCHAR(190) NULL AFTER removed_at,
  ADD COLUMN IF NOT EXISTS removed_employee_id BIGINT UNSIGNED NULL AFTER removed_email,
  ADD COLUMN IF NOT EXISTS removed_status VARCHAR(20) NULL AFTER removed_employee_id;

INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('users.remove','Remove Portal Accounts','Administration','Remove an employee portal account (the Employee 201 file is kept) and restore it later.',33);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='users.remove' WHERE r.code='HR_USER';
