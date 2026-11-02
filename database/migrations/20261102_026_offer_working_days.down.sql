-- Select the intended database first. Rollback is refused while any offer used 22 days (the column holds that input).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_026_down$$
CREATE PROCEDURE stageb_026_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='offers' AND column_name='working_days') THEN
  IF (SELECT COUNT(*) FROM offers WHERE working_days<>26) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Offer working-days data is in use; rollback would destroy it';
  END IF;
  ALTER TABLE offers DROP COLUMN working_days;
 END IF;
END$$
DELIMITER ;
CALL stageb_026_down();
DROP PROCEDURE stageb_026_down;
