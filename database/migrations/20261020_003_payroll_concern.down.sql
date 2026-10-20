-- Reversible before use. Never destroy filed tickets or append-only history on rollback.
DELIMITER $$
DROP PROCEDURE IF EXISTS slice15_003_check_rollback$$
CREATE PROCEDURE slice15_003_check_rollback()
BEGIN
 IF EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='PAYROLL_CONCERN')
 OR EXISTS(SELECT 1 FROM ticket_submission_keys) OR EXISTS(SELECT 1 FROM ticket_notification_outbox) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll Concern is in use. Keep its data/schema and roll back application code; restore a verified backup only under an approved maintenance plan.';
 END IF;
END$$
DELIMITER ;
CALL slice15_003_check_rollback();
DROP PROCEDURE slice15_003_check_rollback;
DELETE FROM ticket_types WHERE code='PAYROLL_CONCERN';
DROP TABLE ticket_notification_outbox;
DROP TABLE ticket_submission_keys;
DROP TABLE payroll_concerns;
