-- Select the intended database first. Refused while any scorecard exists (that data would be destroyed).
DELIMITER $$
DROP PROCEDURE IF EXISTS iscore_030_down$$
CREATE PROCEDURE iscore_030_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='interview_scorecards') THEN
  IF (SELECT COUNT(*) FROM interview_scorecards)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Interview scorecards are in use; rollback would destroy them';
  END IF;
  DROP TABLE interview_scorecards;
 END IF;
END$$
DELIMITER ;
CALL iscore_030_down();
DROP PROCEDURE iscore_030_down;
