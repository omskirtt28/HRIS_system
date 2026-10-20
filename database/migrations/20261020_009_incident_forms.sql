SET @has_comment_visibility=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_comments' AND column_name='visibility');
SET @comment_ddl=IF(@has_comment_visibility=0,'ALTER TABLE ticket_comments ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT ''ALL'', ADD CONSTRAINT chk_ticket_comment_visibility CHECK(visibility IN (''ALL'',''STAFF_ONLY''))','SELECT 1');
PREPARE incident_ddl FROM @comment_ddl;EXECUTE incident_ddl;DEALLOCATE PREPARE incident_ddl;
CREATE TABLE IF NOT EXISTS ticket_incident_permission_seed(permission_id BIGINT UNSIGNED PRIMARY KEY,owned TINYINT NOT NULL) ENGINE=InnoDB;
SET @sensitive_existed=(SELECT COUNT(*) FROM permissions WHERE code='ticket.sensitive.view');
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES('ticket.sensitive.view','View sensitive tickets','HR Requests','Restricted Incident feed and staff-only comments',60);
INSERT IGNORE INTO ticket_incident_permission_seed SELECT id,IF(@sensitive_existed=0,1,0) FROM permissions WHERE code='ticket.sensitive.view';
INSERT IGNORE INTO ticket_types(code,name,routing_profile,routing_config_json) VALUES('INCIDENT_REPORT','Incident Report','HR_ONLY','{"FEED":true,"NO_APPROVAL_STEPS":true}');
