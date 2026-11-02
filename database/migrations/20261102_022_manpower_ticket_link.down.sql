-- Select the intended database first. Rollback is refused while any recruitment request is linked to a ticket (the link is the only record of where it came from).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_022_down$$
CREATE PROCEDURE stageb_022_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='manpower_requests' AND column_name='ticket_id') THEN
  IF (SELECT COUNT(*) FROM manpower_requests WHERE ticket_id IS NOT NULL) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ticket-linked manpower requests are in use; rollback would destroy the link';
  END IF;
  ALTER TABLE manpower_requests DROP FOREIGN KEY IF EXISTS fk_mr_ticket;
  ALTER TABLE manpower_requests DROP INDEX IF EXISTS uq_mr_ticket;
  ALTER TABLE manpower_requests DROP COLUMN ticket_id;
 END IF;
END$$
DELIMITER ;
CALL stageb_022_down();
DROP PROCEDURE stageb_022_down;
