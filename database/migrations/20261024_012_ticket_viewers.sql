-- Slice-15 follow-up: read-only ticket viewers (branch leads, per-employee overrides, ticket.view_team).
-- Additive and re-runnable. Needs migrations 002 (permissions) and 011 (lead roles, optional: missing roles are skipped).
-- Access is evaluated at read time; nothing here changes an existing ticket, step, routing or approver row.
CREATE TABLE IF NOT EXISTS branch_leads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 branch_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 assigned_by BIGINT UNSIGNED NULL,
 superseded_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_branch_lead_dates CHECK(effective_to IS NULL OR effective_to>=effective_from),
 KEY idx_branch_lead_pair(branch_id,employee_id,effective_from),
 KEY idx_branch_lead_employee(employee_id,effective_from,effective_to),
 KEY idx_branch_lead_branch(branch_id,effective_from,effective_to),
 FOREIGN KEY(branch_id) REFERENCES branches(id),
 FOREIGN KEY(employee_id) REFERENCES employees(id),
 FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_viewer_overrides (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 viewer_employee_id BIGINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 assigned_by BIGINT UNSIGNED NULL,
 superseded_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_viewer_override_dates CHECK(effective_to IS NULL OR effective_to>=effective_from),
 CONSTRAINT chk_viewer_override_self CHECK(employee_id<>viewer_employee_id),
 KEY idx_viewer_override_pair(employee_id,viewer_employee_id,effective_from),
 KEY idx_viewer_override_viewer(viewer_employee_id,effective_from,effective_to),
 FOREIGN KEY(employee_id) REFERENCES employees(id),
 FOREIGN KEY(viewer_employee_id) REFERENCES employees(id),
 FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Ledger so the down script removes only what this migration introduced (role_id 0 = the permission itself).
CREATE TABLE IF NOT EXISTS ticket_viewer_seed (
 permission_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(permission_id,role_id)
) ENGINE=InnoDB;
SET @viewer_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='ticket.view_team');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('ticket.view_team','View team HR requests (read-only)','HR Requests','Read-only access to HR requests of the branch / area / direct reports; never approve, comment or attach',40);
INSERT IGNORE INTO ticket_viewer_seed(permission_id,role_id)
 SELECT id,0 FROM permissions WHERE code='ticket.view_team' AND @viewer_perm_exists=0;
-- Deny by default: only these roles are granted; a role that already holds it is left alone and not recorded.
INSERT IGNORE INTO ticket_viewer_seed(permission_id,role_id)
 SELECT p.id,r.id FROM permissions p CROSS JOIN roles r
 WHERE p.code='ticket.view_team' AND r.code IN ('ADL','SC','OIC','TC','MANAGER')
 AND NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT role_id,permission_id FROM ticket_viewer_seed WHERE role_id<>0;
