-- Select the intended database first. Rollback is refused while any follow-up record exists.
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_019_down$$
CREATE PROCEDURE stageb_019_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ticket_lead_followups') THEN
  IF (SELECT COUNT(*) FROM ticket_lead_followups) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Lead follow-ups are in use; rollback would destroy them';
  END IF;
  DROP TABLE ticket_lead_followups;
 END IF;
END$$
DELIMITER ;
CALL stageb_019_down();
DROP PROCEDURE stageb_019_down;
