DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_010_down$$
CREATE PROCEDURE stageb_010_down()
BEGIN
 -- Refuse once HR has granted the new permissions to any role, or a Manpower ticket was routed HR_ONLY.
 IF EXISTS(SELECT 1 FROM role_permissions rp JOIN ticket_file_permission_seed s ON s.permission_id=rp.permission_id AND s.owned=1 WHERE NOT EXISTS(SELECT 1 FROM ticket_file_grant_seed g WHERE g.role_id=rp.role_id AND g.permission_id=rp.permission_id))
 OR EXISTS(SELECT 1 FROM ticket_steps st JOIN tickets t ON t.id=st.ticket_id JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='MANPOWER_REQUEST' AND st.routing_profile_snapshot='HR_ONLY') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Per-type filing access is in use; retain roles and Manpower history';
 END IF;
 UPDATE ticket_types ty JOIN ticket_type_route_seed s ON s.code=ty.code SET ty.routing_profile=s.routing_profile,ty.routing_config_json=s.routing_config_json;
 DELETE rp FROM role_permissions rp JOIN ticket_file_grant_seed g ON g.role_id=rp.role_id AND g.permission_id=rp.permission_id;
 DELETE p FROM permissions p JOIN ticket_file_permission_seed s ON s.permission_id=p.id WHERE s.owned=1;
 DROP TABLE ticket_file_grant_seed;DROP TABLE ticket_file_permission_seed;DROP TABLE ticket_type_route_seed;
END$$
DELIMITER ;
CALL stageb_010_down();DROP PROCEDURE stageb_010_down;
