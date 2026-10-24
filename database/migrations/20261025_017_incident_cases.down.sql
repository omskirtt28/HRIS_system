DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_017_down$$
CREATE PROCEDURE stageb_017_down()
BEGIN
 -- Refuse once a case, a participant or an incident-only status exists: dropping would destroy HR case records.
 IF EXISTS(SELECT 1 FROM incident_details) OR EXISTS(SELECT 1 FROM incident_participants)
 OR EXISTS(SELECT 1 FROM tickets WHERE status IN ('UNDER_REVIEW','RESOLVED','CLOSED','DISMISSED')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Incident cases are in use; retain data and audit history';
 END IF;
 DROP TABLE incident_participants;
 DROP TABLE incident_details;
 ALTER TABLE tickets DROP CONSTRAINT IF EXISTS chk_ticket_status,
  ADD CONSTRAINT chk_ticket_status CHECK(status IN ('DRAFT','SUBMITTED','PENDING_APPROVAL','PENDING_HR','IN_PROGRESS','COMPLETED','REJECTED','RETURNED','CANCELLED'));
END$$
DELIMITER ;
CALL stageb_017_down();DROP PROCEDURE stageb_017_down;
