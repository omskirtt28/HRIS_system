CREATE TABLE IF NOT EXISTS ticket_transfer_migration_state (id INT PRIMARY KEY,created_placements TINYINT NOT NULL) ENGINE=InnoDB;
INSERT IGNORE INTO ticket_transfer_migration_state VALUES(1,IF(EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='employee_placements'),0,1));
CREATE TABLE IF NOT EXISTS employee_placements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,
 effective_from DATE NULL,effective_to DATE NULL,source_ticket_id BIGINT UNSIGNED NULL,provenance VARCHAR(30) NOT NULL,
 observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 current_employee_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN employee_id ELSE NULL END) STORED,
 UNIQUE KEY uq_current_placement(current_employee_id),UNIQUE KEY uq_placement_ticket(source_ticket_id),
 KEY idx_placement_interval(employee_id,effective_from,effective_to),CHECK(effective_to IS NULL OR effective_to>=effective_from),
 FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(branch_id) REFERENCES branches(id),FOREIGN KEY(source_ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO employee_placements(employee_id,branch_id,effective_from,provenance)
 SELECT e.id,e.branch_id,NULL,'OBSERVED_CURRENT' FROM employees e
 WHERE e.branch_id IS NOT NULL AND e.status IN ('ACTIVE','PROBATIONARY','ON_LEAVE') AND (SELECT created_placements FROM ticket_transfer_migration_state WHERE id=1)=1
 AND NOT EXISTS(SELECT 1 FROM employee_placements ep WHERE ep.employee_id=e.id);
CREATE TABLE IF NOT EXISTS branch_transfers (
 ticket_id BIGINT UNSIGNED PRIMARY KEY,employee_id BIGINT UNSIGNED NOT NULL,from_branch_id BIGINT UNSIGNED NOT NULL,to_branch_id BIGINT UNSIGNED NOT NULL,
 effective_date DATE NOT NULL,company VARCHAR(10) NOT NULL,CHECK(from_branch_id<>to_branch_id),CHECK(company IN ('SW','OFJI')),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),FOREIGN KEY(employee_id) REFERENCES employees(id),FOREIGN KEY(from_branch_id) REFERENCES branches(id),FOREIGN KEY(to_branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_transfer_permission_seed (permission_id BIGINT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(permission_id,role_id)) ENGINE=InnoDB;
SET @transfer_existed=(SELECT COUNT(*) FROM permissions WHERE code='ticket.file_for_others');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES('ticket.file_for_others','File tickets for others','HR Requests','Select a subject employee; requester remains the filing account',50);
INSERT IGNORE INTO ticket_transfer_permission_seed SELECT id,0 FROM permissions WHERE code='ticket.file_for_others' AND @transfer_existed=0;
INSERT IGNORE INTO ticket_transfer_permission_seed SELECT p.id,r.id FROM permissions p CROSS JOIN roles r WHERE p.code='ticket.file_for_others' AND r.portal IN ('hr','admin')
 AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions ep ON ep.id=rp.permission_id WHERE rp.role_id=r.id AND ep.code='employees.manage')
 AND NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM ticket_transfer_permission_seed WHERE role_id<>0;
INSERT IGNORE INTO ticket_types(code,name,routing_profile) VALUES('BRANCH_TRANSFER','Branch Transfer','HR_ONLY');
