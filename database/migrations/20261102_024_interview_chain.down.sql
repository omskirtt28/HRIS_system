-- Select the intended database first. Rollback is refused while any reviewer is marked final or was passed the candidate by someone (the columns hold that chain).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_024_down$$
CREATE PROCEDURE stageb_024_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='application_reviewers' AND column_name='is_final') THEN
  IF (SELECT COUNT(*) FROM application_reviewers WHERE is_final=1 OR passed_by IS NOT NULL) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Interview chain data is in use; rollback would destroy it';
  END IF;
  ALTER TABLE application_reviewers DROP COLUMN passed_by, DROP COLUMN is_final;
 END IF;
END$$
DELIMITER ;
CALL stageb_024_down();
DROP PROCEDURE stageb_024_down;
