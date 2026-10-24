-- Slice-15 follow-up: automatic ticket viewers (Sales Captain / OIC / ADL) + OIC Authorization request type.
-- Additive and re-runnable. Needs migrations 008 (ticket.file_for_others), 011 (lead roles, optional) and 012 (ticket viewers).
-- Changes no existing ticket, step, routing, approver, user or role row. Viewers are derived at read time from branch,
-- position and area; nothing here grants a role (HR does that by hand in Users).
CREATE TABLE IF NOT EXISTS oic_authorizations (
 ticket_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'REQUESTED',
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 previous_user_id BIGINT UNSIGNED NULL,
 previous_role_id BIGINT UNSIGNED NULL,
 expired_at DATETIME NULL,
 restored_role_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_oic_auth_dates CHECK(end_date>=start_date),
 CONSTRAINT chk_oic_auth_status CHECK(status IN ('REQUESTED','APPROVED','EXPIRED')),
 KEY idx_oic_auth_due(status,end_date),
 KEY idx_oic_auth_employee(employee_id,start_date,end_date),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(employee_id) REFERENCES employees(id),
 FOREIGN KEY(branch_id) REFERENCES branches(id),
 FOREIGN KEY(approved_by) REFERENCES users(id),
 FOREIGN KEY(previous_user_id) REFERENCES users(id),
 FOREIGN KEY(previous_role_id) REFERENCES roles(id),
 FOREIGN KEY(restored_role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Ledger so the down script removes only what this migration introduced (role_id 0 = the permission itself).
CREATE TABLE IF NOT EXISTS oic_authorization_seed (
 permission_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(permission_id,role_id)
) ENGINE=InnoDB;
SET @oic_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='ticket.file.oic_authorization');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('ticket.file.oic_authorization','File OIC Authorization','HR Requests','File an OIC Authorization for an employee of your own branch (Sales Captain) or area (ADL)',45);
INSERT IGNORE INTO oic_authorization_seed(permission_id,role_id)
 SELECT id,0 FROM permissions WHERE code='ticket.file.oic_authorization' AND @oic_perm_exists=0;
-- Deny by default: only ADL and SC are granted. HR/Admin file for others through ticket.file_for_others.
INSERT IGNORE INTO oic_authorization_seed(permission_id,role_id)
 SELECT p.id,r.id FROM permissions p CROSS JOIN roles r
 WHERE p.code='ticket.file.oic_authorization' AND r.code IN ('ADL','SC')
 AND NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT role_id,permission_id FROM oic_authorization_seed WHERE role_id<>0;
INSERT IGNORE INTO ticket_types(code,name,routing_profile) VALUES('OIC_AUTHORIZATION','OIC Authorization','HR_ONLY');
-- Retired as access input: the rows (if any) are kept for history and are never read by the viewer rules.
ALTER TABLE branch_leads COMMENT='DEPRECATED in 013: never read as ticket-viewer access input; kept for history.';
ALTER TABLE ticket_viewer_overrides COMMENT='DEPRECATED in 013: never read as ticket-viewer access input; kept for history.';
