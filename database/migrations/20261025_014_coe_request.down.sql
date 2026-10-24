DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_014_down$$
CREATE PROCEDURE stageb_014_down()
BEGIN
 -- Refuse once any COE request, document or uploaded template exists; issued certificates must be retained.
 IF EXISTS(SELECT 1 FROM coe_requests) OR EXISTS(SELECT 1 FROM coe_documents) OR EXISTS(SELECT 1 FROM coe_templates)
 OR EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='COE_REQUEST') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COE requests are in use; retain the history';
 END IF;
 DELETE FROM ticket_types WHERE code='COE_REQUEST';
 DELETE rp FROM role_permissions rp JOIN coe_seed s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
 DELETE rp FROM role_permissions rp JOIN coe_seed s ON s.permission_id=rp.permission_id WHERE s.role_id=0;
 DELETE p FROM permissions p JOIN coe_seed s ON s.permission_id=p.id WHERE s.role_id=0;
 DROP TABLE coe_seed;DROP TABLE coe_settings;DROP TABLE coe_documents;DROP TABLE coe_templates;DROP TABLE coe_requests;
END$$
DELIMITER ;
CALL stageb_014_down();DROP PROCEDURE stageb_014_down;
