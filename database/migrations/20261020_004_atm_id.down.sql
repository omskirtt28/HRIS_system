DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_004_down$$
CREATE PROCEDURE stageb_004_down()
BEGIN
 IF EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code IN ('ATM_REQUEST','ID_REQUEST')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ATM/ID tickets are in use; audit and ticket data must be retained';
 END IF;
 DELETE FROM ticket_types WHERE code IN ('ATM_REQUEST','ID_REQUEST');
END$$
DELIMITER ;
CALL stageb_004_down();
DROP PROCEDURE stageb_004_down;
