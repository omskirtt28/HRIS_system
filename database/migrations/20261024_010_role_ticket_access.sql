-- Slice-15 follow-up: per-type filing permissions (ticket.file.<type>) and Manpower routed HR_ONLY.
-- Re-runnable. Roles ADL/SC/OIC/TC are NOT created here; HR creates them in Roles & Permissions.
CREATE TABLE IF NOT EXISTS ticket_file_permission_seed(permission_id BIGINT UNSIGNED PRIMARY KEY,owned TINYINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_file_grant_seed(role_id BIGINT UNSIGNED NOT NULL,permission_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(role_id,permission_id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_type_route_seed(code VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,routing_profile VARCHAR(50) NOT NULL,routing_config_json LONGTEXT NULL) ENGINE=InnoDB;
SET @first_run=(SELECT COUNT(*)=0 FROM ticket_file_permission_seed);

DROP TEMPORARY TABLE IF EXISTS tmp_010_codes;
CREATE TEMPORARY TABLE tmp_010_codes(code VARCHAR(100) PRIMARY KEY,name VARCHAR(160) NOT NULL,description VARCHAR(255) NOT NULL,sort_order INT NOT NULL,tier VARCHAR(10) NOT NULL);
INSERT INTO tmp_010_codes VALUES
 ('ticket.file.atm','File ATM requests','May choose ATM Request in File an HR Request',70,'STAFF'),
 ('ticket.file.id','File ID requests','May choose ID Request in File an HR Request',71,'STAFF'),
 ('ticket.file.incident','File Incident Reports','May choose Incident Report in File an HR Request',72,'STAFF'),
 ('ticket.file.uniform','File Uniform requests','May choose Uniform Request in File an HR Request',73,'STAFF'),
 ('ticket.file.payroll_concern','File Payroll Concerns','May choose Payroll Concern in File an HR Request',74,'STAFF'),
 ('ticket.file.manpower','File Manpower requests','May choose Manpower Request (routed to HR only)',75,'LEAD'),
 ('ticket.file.netsuite','File NetSuite Concerns','May choose NetSuite Concern in File an HR Request',76,'LEAD');

-- Permissions that already existed are recorded as not owned so the down script keeps them.
INSERT IGNORE INTO ticket_file_permission_seed SELECT p.id,0 FROM permissions p JOIN tmp_010_codes c ON c.code=p.code;
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) SELECT code,name,'HR Requests',description,sort_order FROM tmp_010_codes;
INSERT IGNORE INTO ticket_file_permission_seed SELECT p.id,1 FROM permissions p JOIN tmp_010_codes c ON c.code=p.code;

-- First run only: keep today's filing access. Filers (tickets.request_self / ticket.file_for_others) get the staff
-- types; HR filers and approver-capable filers (Manager/ADL permission) also get Manpower and NetSuite.
INSERT IGNORE INTO ticket_file_grant_seed(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN tmp_010_codes c JOIN permissions p ON p.code=c.code
WHERE @first_run=1 AND r.code<>'SUPER_ADMIN'
 AND NOT EXISTS(SELECT 1 FROM role_permissions x WHERE x.role_id=r.id AND x.permission_id=p.id)
 AND (
  (c.tier='STAFF' AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id=rp.permission_id WHERE rp.role_id=r.id AND q.code IN ('tickets.request_self','ticket.file_for_others')))
  OR (c.tier='LEAD' AND (EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id=rp.permission_id WHERE rp.role_id=r.id AND q.code='ticket.file_for_others')
   OR (EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id=rp.permission_id WHERE rp.role_id=r.id AND q.code='tickets.request_self')
    AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions q ON q.id=rp.permission_id WHERE rp.role_id=r.id AND q.code IN ('payroll.approve_manager','payroll.approve_adl')))))
 );
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM ticket_file_grant_seed WHERE @first_run=1;
DROP TEMPORARY TABLE tmp_010_codes;

-- Manpower: filed by leads, HR sets the status. Open tickets keep their frozen steps.
INSERT IGNORE INTO ticket_type_route_seed SELECT code,routing_profile,routing_config_json FROM ticket_types WHERE code='MANPOWER_REQUEST';
UPDATE ticket_types SET routing_profile='HR_ONLY',routing_config_json=NULL WHERE code='MANPOWER_REQUEST';
