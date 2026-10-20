DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_007_down$$
CREATE PROCEDURE stageb_007_down()
BEGIN
 IF EXISTS(SELECT 1 FROM ticket_manpower_requests) OR EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='MANPOWER_REQUEST') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manpower tickets are in use; retain ticket and audit data';
 END IF;
 DELETE FROM ticket_types WHERE code='MANPOWER_REQUEST';DROP TABLE ticket_manpower_requests;
END$$
DELIMITER ;
CALL stageb_007_down();
DROP PROCEDURE stageb_007_down;
