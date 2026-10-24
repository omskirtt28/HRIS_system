-- Patch 2: Ticketing Report permission. Additive and re-runnable; creates no table that holds business data.
-- The report page reads tickets, employees and branches only. It is granted to the roles that already review HR requests
-- (tickets.review_hr), so nobody gains access to data they could not already see in the queue.
CREATE TABLE IF NOT EXISTS ticket_report_seed (
 permission_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(permission_id,role_id)
) ENGINE=InnoDB;
SET @report_perm_exists=(SELECT COUNT(*) FROM permissions WHERE code='ticket.report.view');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('ticket.report.view','View Ticketing Report','HR Requests','Open the HR Ticketing Report, its CSV export and the printable summary (HR roles only)',48);
INSERT IGNORE INTO ticket_report_seed(permission_id,role_id) SELECT id,0 FROM permissions WHERE code='ticket.report.view' AND @report_perm_exists=0;
INSERT IGNORE INTO ticket_report_seed(permission_id,role_id)
 SELECT pr.id,rp.role_id FROM role_permissions rp JOIN permissions ph ON ph.id=rp.permission_id AND ph.code='tickets.review_hr'
 JOIN permissions pr ON pr.code='ticket.report.view'
 WHERE NOT EXISTS(SELECT 1 FROM role_permissions x WHERE x.role_id=rp.role_id AND x.permission_id=pr.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM ticket_report_seed WHERE role_id<>0;
