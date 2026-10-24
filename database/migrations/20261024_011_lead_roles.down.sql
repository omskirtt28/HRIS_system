DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_011_down$$
CREATE PROCEDURE stageb_011_down()
BEGIN
 -- Refuse once a seeded role holds a user or HR changed its permissions; pre-existing roles are never removed.
 IF EXISTS(SELECT 1 FROM users u JOIN lead_role_seed s ON s.role_id=u.role_id AND s.owned=1)
 OR EXISTS(SELECT 1 FROM role_permissions rp JOIN lead_role_seed s ON s.role_id=rp.role_id AND s.owned=1 WHERE NOT EXISTS(SELECT 1 FROM lead_role_grant_seed g WHERE g.role_id=rp.role_id AND g.permission_id=rp.permission_id))
 OR EXISTS(SELECT 1 FROM lead_role_grant_seed g WHERE NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=g.role_id AND rp.permission_id=g.permission_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Lead roles are in use; retain roles and their users';
 END IF;
 DELETE rp FROM role_permissions rp JOIN lead_role_grant_seed g ON g.role_id=rp.role_id AND g.permission_id=rp.permission_id;
 DELETE r FROM roles r JOIN lead_role_seed s ON s.role_id=r.id WHERE s.owned=1;
 DROP TABLE lead_role_grant_seed;DROP TABLE lead_role_seed;
END$$
DELIMITER ;
CALL stageb_011_down();DROP PROCEDURE stageb_011_down;
