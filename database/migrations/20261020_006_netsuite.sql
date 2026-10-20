CREATE TABLE IF NOT EXISTS ticket_accounting_permission_seed (permission_id BIGINT UNSIGNED PRIMARY KEY,owned TINYINT NOT NULL) ENGINE=InnoDB;
SET @accounting_existed=(SELECT COUNT(*) FROM permissions WHERE code='ticket.accounting.view');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES('ticket.accounting.view','Accounting ticket follow-up','HR Requests','Explicit Accounting membership; completed NetSuite concerns only',40);
INSERT IGNORE INTO ticket_accounting_permission_seed SELECT id,IF(@accounting_existed=0,1,0) FROM permissions WHERE code='ticket.accounting.view';
CREATE TABLE IF NOT EXISTS ticket_accounting_memberships (
 user_id BIGINT UNSIGNED PRIMARY KEY,permission_id BIGINT UNSIGNED NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,assigned_by BIGINT UNSIGNED NULL,provenance VARCHAR(20) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(permission_id) REFERENCES permissions(id),FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_accounting_seed_results (
 employee_no VARCHAR(30) PRIMARY KEY,result VARCHAR(30) NOT NULL,user_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ticket_accounting_seed_results(employee_no,result)
VALUES('16856','PENDING'),('18405','PENDING'),('17862','PENDING'),('18280','PENDING'),('18087','PENDING'),('17790','PENDING'),('18055','PENDING');
UPDATE ticket_accounting_seed_results r SET
 result=CASE WHEN (SELECT COUNT(*) FROM employees e WHERE e.employee_no=r.employee_no)=0 THEN 'MISSING_EMPLOYEE'
 WHEN (SELECT COUNT(*) FROM employees e WHERE e.employee_no=r.employee_no)<>1 THEN 'AMBIGUOUS_EMPLOYEE'
 WHEN (SELECT COUNT(*) FROM employees e JOIN users u ON u.id=e.user_id WHERE e.employee_no=r.employee_no AND e.status IN ('ACTIVE','PROBATIONARY','ON_LEAVE') AND u.status='ACTIVE')<>1 THEN 'MISSING_ACTIVE_ACCOUNT'
 WHEN (SELECT COUNT(*) FROM employees e WHERE e.user_id=(SELECT x.user_id FROM employees x WHERE x.employee_no=r.employee_no LIMIT 1))<>1 THEN 'AMBIGUOUS_ACCOUNT'
 ELSE 'LINKED' END,
 user_id=CASE WHEN result='LINKED' THEN (SELECT e.user_id FROM employees e WHERE e.employee_no=r.employee_no LIMIT 1) ELSE NULL END;
INSERT IGNORE INTO ticket_accounting_memberships(user_id,permission_id,provenance)
 SELECT r.user_id,p.id,'SEED' FROM ticket_accounting_seed_results r JOIN permissions p ON p.code='ticket.accounting.view' WHERE r.result='LINKED';
CREATE TABLE IF NOT EXISTS ticket_netsuite_remarks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ticket_id BIGINT UNSIGNED NOT NULL,
 lane VARCHAR(20) NOT NULL,body TEXT NOT NULL,author_user_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(lane IN ('HR','ACCOUNTING')),FOREIGN KEY(ticket_id) REFERENCES tickets(id),FOREIGN KEY(author_user_id) REFERENCES users(id),KEY idx_netsuite_remarks(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE ticket_steps DROP CONSTRAINT chk_ticket_step_type, DROP CONSTRAINT chk_ticket_step_named;
ALTER TABLE ticket_steps ADD CONSTRAINT chk_ticket_step_type CHECK(approver_type IN ('MANAGER','ADL','HR','ACCOUNTING')),
 ADD CONSTRAINT chk_ticket_step_named CHECK(approver_type IN ('HR','ACCOUNTING') OR approver_user_id IS NOT NULL);
INSERT IGNORE INTO ticket_types(code,name,routing_profile,routing_config_json) VALUES('NETSUITE_CONCERN','NetSuite Concern','HR_ONLY','{"FOLLOW_UP":"ACCOUNTING"}');
