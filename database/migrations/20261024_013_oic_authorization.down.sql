DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_013_down$$
CREATE PROCEDURE stageb_013_down()
BEGIN
 -- Refuse once any OIC Authorization exists; the history of who held OIC access must be retained.
 IF EXISTS(SELECT 1 FROM oic_authorizations)
 OR EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='OIC_AUTHORIZATION')
 OR EXISTS(SELECT 1 FROM ticket_effects WHERE effect_type='OIC_AUTHORIZATION') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OIC authorizations are in use; retain the history';
 END IF;
 DELETE FROM ticket_types WHERE code='OIC_AUTHORIZATION';
 DELETE rp FROM role_permissions rp JOIN oic_authorization_seed s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
 DELETE rp FROM role_permissions rp JOIN oic_authorization_seed s ON s.permission_id=rp.permission_id WHERE s.role_id=0;
 DELETE p FROM permissions p JOIN oic_authorization_seed s ON s.permission_id=p.id WHERE s.role_id=0;
 DROP TABLE oic_authorization_seed;DROP TABLE oic_authorizations;
 ALTER TABLE branch_leads COMMENT='';
 ALTER TABLE ticket_viewer_overrides COMMENT='';
END$$
DELIMITER ;
CALL stageb_013_down();DROP PROCEDURE stageb_013_down;
