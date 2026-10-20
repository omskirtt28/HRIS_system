DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_009_down$$
CREATE PROCEDURE stageb_009_down()
BEGIN
 IF EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='INCIDENT_REPORT') OR EXISTS(SELECT 1 FROM ticket_comments WHERE visibility='STAFF_ONLY')
 OR EXISTS(SELECT 1 FROM role_permissions rp JOIN ticket_incident_permission_seed s ON s.permission_id=rp.permission_id WHERE s.owned=1) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Incident/staff comments are in use; retain data and audit history';
 END IF;
 DELETE FROM ticket_types WHERE code='INCIDENT_REPORT';
 ALTER TABLE ticket_comments DROP CONSTRAINT chk_ticket_comment_visibility,DROP COLUMN visibility;
 DELETE p FROM permissions p JOIN ticket_incident_permission_seed s ON s.permission_id=p.id WHERE s.owned=1;
 DROP TABLE ticket_incident_permission_seed;
END$$
DELIMITER ;
CALL stageb_009_down();DROP PROCEDURE stageb_009_down;
