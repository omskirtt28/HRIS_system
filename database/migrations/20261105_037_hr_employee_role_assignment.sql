-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- Plan 2026-11-05-hr-role-change-retail-parity (SPEC-hr-user-role-change.md, John 2026-11-05). Two narrow permissions, idempotent:
--  users.assign_employee_role -> HR_USER only: change an account to any Employee-portal role (no users.manage, no copy of OIC/Manager/SC grants).
--  employees.view_team -> team-lead roles: read-only My Team roster, scoped in code to the viewer's own people (no assignment = empty). No approvals, no recruitment.
-- Super Admin passes every permission check, so it is not granted explicitly.
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('users.assign_employee_role','Assign Employee Portal Roles','Users','In Users & Access, change an Employee portal account to another Employee portal role. Does not allow HR/Admin/Client roles, Super Admin, own account, or editing role permissions.',36),
 ('employees.view_team','View My Team Roster','Employees','Read-only My Team list of the people assigned to the viewer (direct reports and assigned branch/area). No pay, IDs or documents.',37);

CREATE TABLE IF NOT EXISTS hr_employee_role_assignment_037_seed (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @first_run=(SELECT COUNT(*)=0 FROM hr_employee_role_assignment_037_seed);

-- Ledger records only grants this migration adds, so down removes exactly those (a pre-existing grant is kept).
INSERT IGNORE INTO hr_employee_role_assignment_037_seed(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('users.assign_employee_role','employees.view_team')
 WHERE @first_run=1
   AND ((p.code='users.assign_employee_role' AND r.code='HR_USER')
     OR (p.code='employees.view_team' AND r.code IN ('MANAGER','RETAIL_MANAGER','ADL','SC','OIC','TC','MT')))
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM hr_employee_role_assignment_037_seed;
