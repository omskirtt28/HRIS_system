DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_015_down$$
CREATE PROCEDURE stageb_015_down()
BEGIN
 -- Refuse if HR has granted the permission by hand to any role beyond the seeded ones; those grants are configuration to retain.
 IF EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id AND p.code='ticket.report.view'
   WHERE NOT EXISTS(SELECT 1 FROM ticket_report_seed s WHERE s.permission_id=rp.permission_id AND s.role_id=rp.role_id)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticketing Report access is in use; retain the grants';
 END IF;
 DELETE rp FROM role_permissions rp JOIN ticket_report_seed s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
 DELETE rp FROM role_permissions rp JOIN ticket_report_seed s ON s.permission_id=rp.permission_id WHERE s.role_id=0;
 DELETE p FROM permissions p JOIN ticket_report_seed s ON s.permission_id=p.id WHERE s.role_id=0;
 DROP TABLE ticket_report_seed;
END$$
DELIMITER ;
CALL stageb_015_down();DROP PROCEDURE stageb_015_down;
