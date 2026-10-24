-- Patch 1: Certificate of Employment (COE) request type + HR-managed .docx template.
-- Additive and re-runnable. Needs migration 013 (ticket types) and the ticket.file.atm permission from 010.
-- Changes no existing ticket, step, routing, approver, user or role row. The old "Request COE" tile on the employee
-- dashboard is a dead link with no table behind it; nothing here touches it.
CREATE TABLE IF NOT EXISTS coe_requests (
 ticket_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 purpose VARCHAR(20) NOT NULL,
 purpose_other VARCHAR(200) NULL,
 needed_by DATE NOT NULL,
 delivery VARCHAR(12) NOT NULL,
 include_salary TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_coe_purpose CHECK(purpose IN ('Loan','Visa/Embassy','Bank','New employer','Other')),
 CONSTRAINT chk_coe_delivery CHECK(delivery IN ('HR pickup','Email')),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coe_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 version_no INT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(80) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 file_size INT UNSIGNED NOT NULL,
 placeholders_json VARCHAR(1000) NOT NULL,
 note VARCHAR(200) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_coe_template_version(version_no),
 FOREIGN KEY(uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coe_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 template_id BIGINT UNSIGNED NULL,
 template_version INT UNSIGNED NULL,
 stored_name VARCHAR(80) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 file_size INT UNSIGNED NOT NULL,
 date_issued DATE NOT NULL,
 include_salary TINYINT(1) NOT NULL DEFAULT 0,
 generated_by BIGINT UNSIGNED NOT NULL,
 generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_coe_document_ticket(ticket_id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 FOREIGN KEY(template_id) REFERENCES coe_templates(id),
 FOREIGN KEY(generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- HR-admin configuration (signatory, letterhead text, company display name). Nothing is hard-coded in the app.
CREATE TABLE IF NOT EXISTS coe_settings (
 setting_key VARCHAR(40) NOT NULL PRIMARY KEY,
 setting_value VARCHAR(255) NOT NULL DEFAULT '',
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Ledger so the down script removes only what this migration introduced (role_id 0 = the permission itself).
CREATE TABLE IF NOT EXISTS coe_seed (
 permission_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(permission_id,role_id)
) ENGINE=InnoDB;
SET @coe_file_exists=(SELECT COUNT(*) FROM permissions WHERE code='ticket.file.coe');
SET @coe_tpl_exists=(SELECT COUNT(*) FROM permissions WHERE code='ticket.coe.template.manage');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('ticket.file.coe','File Certificate of Employment','HR Requests','File a Certificate of Employment request for yourself',46),
 ('ticket.coe.template.manage','Manage COE template','HR Requests','Upload the COE .docx template and set the signatory (HR roles only)',47);
INSERT IGNORE INTO coe_seed(permission_id,role_id) SELECT id,0 FROM permissions WHERE code='ticket.file.coe' AND @coe_file_exists=0;
INSERT IGNORE INTO coe_seed(permission_id,role_id) SELECT id,0 FROM permissions WHERE code='ticket.coe.template.manage' AND @coe_tpl_exists=0;
-- Same roles that file ATM / ID requests today.
INSERT IGNORE INTO coe_seed(permission_id,role_id)
 SELECT pc.id,rp.role_id FROM role_permissions rp JOIN permissions pa ON pa.id=rp.permission_id AND pa.code='ticket.file.atm'
 JOIN permissions pc ON pc.code='ticket.file.coe'
 WHERE NOT EXISTS(SELECT 1 FROM role_permissions x WHERE x.role_id=rp.role_id AND x.permission_id=pc.id);
-- Template management: HR roles only (Super Admin passes every permission check on its own).
INSERT IGNORE INTO coe_seed(permission_id,role_id)
 SELECT p.id,r.id FROM permissions p CROSS JOIN roles r
 WHERE p.code='ticket.coe.template.manage' AND r.code IN ('HR_USER','HRIS_ADMIN')
 AND NOT EXISTS(SELECT 1 FROM role_permissions x WHERE x.role_id=r.id AND x.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM coe_seed WHERE role_id<>0;
INSERT IGNORE INTO ticket_types(code,name,routing_profile) VALUES('COE_REQUEST','Certificate of Employment','HR_ONLY');
