CREATE TABLE IF NOT EXISTS employee_approver_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 approver_user_id BIGINT UNSIGNED NOT NULL,
 approver_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 assigned_by BIGINT UNSIGNED NULL,
 provenance VARCHAR(40) NOT NULL,
 source_reference VARCHAR(100) NULL,
 source_actor_user_id BIGINT UNSIGNED NULL,
 superseded_at DATETIME NULL,
 superseded_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_assignment_type CHECK(approver_type IN ('MANAGER','ADL')),
 CONSTRAINT chk_assignment_dates CHECK(effective_to IS NULL OR effective_to>=effective_from),
 UNIQUE KEY uq_assignment_source(employee_id,provenance,source_reference),
 KEY idx_assignment_effective(employee_id,approver_type,effective_from,effective_to),
 FOREIGN KEY(employee_id) REFERENCES employees(id),
 FOREIGN KEY(approver_user_id) REFERENCES users(id),
 FOREIGN KEY(assigned_by) REFERENCES users(id),
 FOREIGN KEY(source_actor_user_id) REFERENCES users(id),
 FOREIGN KEY(superseded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- A separate ledger makes reversal remove only permissions/grants introduced by this migration.
CREATE TABLE IF NOT EXISTS ticket_permission_seeds (
 permission_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(permission_id,role_id)
) ENGINE=InnoDB;
-- Save newly inserted permission identities without capturing pre-existing permissions.
SET @slice15_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='tickets.approvers.manage');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('tickets.approvers.manage','Manage ticket approvers','HR Requests','HR-only effective-dated Manager/ADL assignments',10);
INSERT IGNORE INTO ticket_permission_seeds(permission_id,role_id)
 SELECT id,0 FROM permissions WHERE code='tickets.approvers.manage' AND @slice15_perm_exists=0;
SET @slice15_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='tickets.request_self');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('tickets.request_self','File own HR requests','HR Requests','File and view own generic tickets',20);
INSERT IGNORE INTO ticket_permission_seeds(permission_id,role_id)
 SELECT id,0 FROM permissions WHERE code='tickets.request_self' AND @slice15_perm_exists=0;
SET @slice15_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='tickets.review_hr');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('tickets.review_hr','Review HR requests','HR Requests','HR review of generic tickets',30);
INSERT IGNORE INTO ticket_permission_seeds(permission_id,role_id)
 SELECT id,0 FROM permissions WHERE code='tickets.review_hr' AND @slice15_perm_exists=0;
INSERT IGNORE INTO ticket_permission_seeds(permission_id,role_id)
 SELECT p.id,r.id FROM permissions p CROSS JOIN roles r
 WHERE ((p.code='tickets.approvers.manage' AND r.portal IN ('hr','admin') AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id WHERE rp.role_id=r.id AND oldp.code='employees.manage'))
 OR (p.code='tickets.review_hr' AND r.portal IN ('hr','admin') AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id WHERE rp.role_id=r.id AND oldp.code='payroll.approve_hr'))
 OR (p.code='tickets.request_self' AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions oldp ON oldp.id=rp.permission_id WHERE rp.role_id=r.id AND oldp.code='payroll.request_self')))
 AND NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT role_id,permission_id FROM ticket_permission_seeds WHERE role_id<>0;
-- Backfill is run by the companion service after SQL, inside one transaction with audit events.
-- Legacy effective dates/assigned_by are unknown; observations begin on the migration date.
