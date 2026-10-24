-- Slice-15 follow-up: lead roles ADL, SC, OIC and TC (employee portal). Re-runnable; needs migration 010.
-- A role that already exists (same code) is left exactly as HR configured it. COORDINATOR is a separate HR-portal role.
-- Grants: MANAGER's base portal access (no HR/admin permission), the five staff types, Manpower, NetSuite,
-- plus approver access: ADL and TC -> payroll.approve_adl, OIC -> payroll.approve_manager, SC -> none.
CREATE TABLE IF NOT EXISTS lead_role_seed(role_id BIGINT UNSIGNED PRIMARY KEY,code VARCHAR(50) NOT NULL,owned TINYINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS lead_role_grant_seed(role_id BIGINT UNSIGNED NOT NULL,permission_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB;
SET @first_run=(SELECT COUNT(*)=0 FROM lead_role_seed);

DROP TEMPORARY TABLE IF EXISTS tmp_011_roles;
CREATE TEMPORARY TABLE tmp_011_roles(code VARCHAR(50) PRIMARY KEY,name VARCHAR(120) NOT NULL,approver VARCHAR(100) NULL);
INSERT INTO tmp_011_roles VALUES
 ('ADL','Area Development Leader','payroll.approve_adl'),
 ('SC','Sales Captain',NULL),
 ('OIC','OIC','payroll.approve_manager'),
 ('TC','Team Coordinator','payroll.approve_adl');
DROP TEMPORARY TABLE IF EXISTS tmp_011_base;
CREATE TEMPORARY TABLE tmp_011_base(code VARCHAR(100) PRIMARY KEY);
INSERT INTO tmp_011_base VALUES
 ('dashboard.employee.view'),('employees.view_self'),('attendance.view_self'),('leave.view_self'),('leave.request_self'),
 ('payroll.request_self'),('requests.request_self'),('team.view'),('tickets.request_self'),
 ('ticket.file.atm'),('ticket.file.id'),('ticket.file.incident'),('ticket.file.uniform'),('ticket.file.payroll_concern'),
 ('ticket.file.manpower'),('ticket.file.netsuite');

-- Roles that already existed are recorded as not owned, so neither grants nor the down script touch them.
INSERT IGNORE INTO lead_role_seed SELECT r.id,r.code,0 FROM roles r JOIN tmp_011_roles t ON t.code=r.code WHERE @first_run=1;
INSERT IGNORE INTO roles(code,name,portal,is_system) SELECT code,name,'employee',0 FROM tmp_011_roles WHERE @first_run=1;
INSERT IGNORE INTO lead_role_seed SELECT r.id,r.code,1 FROM roles r JOIN tmp_011_roles t ON t.code=r.code WHERE @first_run=1;

INSERT IGNORE INTO lead_role_grant_seed(role_id,permission_id)
SELECT s.role_id,p.id FROM lead_role_seed s JOIN tmp_011_roles t ON t.code=s.code JOIN permissions p
 ON p.code IN (SELECT code FROM tmp_011_base) OR p.code=t.approver
WHERE @first_run=1 AND s.owned=1;
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM lead_role_grant_seed WHERE @first_run=1;
DROP TEMPORARY TABLE tmp_011_base;DROP TEMPORARY TABLE tmp_011_roles;
