DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_016_down$$
CREATE PROCEDURE stageb_016_down()
BEGIN
 -- Refuse once HR has written an internal note: dropping the table would destroy HR's records.
 IF EXISTS(SELECT 1 FROM ticket_hr_notes) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR internal notes are in use; retain the table';
 END IF;
 DROP TABLE ticket_hr_notes;
END$$
DELIMITER ;
CALL stageb_016_down();DROP PROCEDURE stageb_016_down;
