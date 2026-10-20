DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_005_down$$
CREATE PROCEDURE stageb_005_down()
BEGIN
 IF EXISTS(SELECT 1 FROM uniform_requests) OR EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='UNIFORM_REQUEST') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Uniform tickets are in use; retain ticket and audit data';
 END IF;
 DELETE FROM ticket_types WHERE code='UNIFORM_REQUEST';
 DROP TABLE uniform_requests;
END$$
DELIMITER ;
CALL stageb_005_down();
DROP PROCEDURE stageb_005_down;
