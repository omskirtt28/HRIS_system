-- Select the intended database first. Refused while any tracking link exists (applicants hold those links; dropping would break them).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_038_down$$
CREATE PROCEDURE stageb_038_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='applicant_tracking_links') THEN
  IF (SELECT COUNT(*) FROM applicant_tracking_links)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Applicant tracking links are in use; rollback would break them';
  END IF;
  DROP TABLE applicant_tracking_links;
 END IF;
END$$
DELIMITER ;
CALL stageb_038_down();
DROP PROCEDURE stageb_038_down;
