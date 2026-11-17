-- Select the intended database first. Refused once any vendor or bill exists (that is the client's payables history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_041_down$$
CREATE PROCEDURE stageb_041_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_bills') THEN
  IF (SELECT COUNT(*) FROM acct_bills)>0 OR (SELECT COUNT(*) FROM acct_vendors)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vendors or bills are in use; rollback would delete the payables';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_bills;
 DROP TABLE IF EXISTS acct_vendors;
END$$
DELIMITER ;
CALL stageb_041_down();
DROP PROCEDURE stageb_041_down;
