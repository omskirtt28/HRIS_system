DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_012_down$$
CREATE PROCEDURE stageb_012_down()
BEGIN
 -- Refuse once HR has assigned any lead or override; the history must be retained.
 IF EXISTS(SELECT 1 FROM branch_leads) OR EXISTS(SELECT 1 FROM ticket_viewer_overrides) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket viewers are in use; retain branch leads and overrides';
 END IF;
 DELETE rp FROM role_permissions rp JOIN ticket_viewer_seed s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
 DELETE rp FROM role_permissions rp JOIN ticket_viewer_seed s ON s.permission_id=rp.permission_id WHERE s.role_id=0;
 DELETE p FROM permissions p JOIN ticket_viewer_seed s ON s.permission_id=p.id WHERE s.role_id=0;
 DROP TABLE ticket_viewer_seed;DROP TABLE ticket_viewer_overrides;DROP TABLE branch_leads;
END$$
DELIMITER ;
CALL stageb_012_down();DROP PROCEDURE stageb_012_down;
