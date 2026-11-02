-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- Employee portal account (SPEC-employee-portal-account.md): 3 nullable/defaulted user columns, 3 permissions with their grants,
-- and the PORTAL_ACCOUNT_REQUEST ticket type. Idempotent. No existing row is changed (INSERT IGNORE and ADD COLUMN IF NOT EXISTS only).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS password_enc VARCHAR(255) NULL AFTER password_hash,
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_enc,
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER must_change_password;

INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('users.view_directory','View Account Directory','Administration','Read-only list of user accounts (no create, role, disable or link actions).',31),
 ('users.view_credentials','View Account Passwords','Administration','Reveal the current password of a user account (audited).',32),
 ('ticket.file.portal_account','File Portal Account Requests','HR Requests','May choose Portal account / forgotten password in File an HR Request',90);

-- HR (role HR_USER) may read the directory and reveal passwords.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('users.view_directory','users.view_credentials') WHERE r.code='HR_USER';
-- Everyone who can file other HR requests, plus plain Employees, may file the forgotten-password request.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='ticket.file.portal_account' WHERE r.code IN ('EMPLOYEE','ADL','MANAGER','OIC','SC','TC','HR_USER','HRIS_ADMIN');

INSERT IGNORE INTO ticket_types(code,name,routing_profile,routing_config_json) VALUES('PORTAL_ACCOUNT_REQUEST','Portal account / forgotten password','HR_ONLY',NULL);
