-- Select the intended database first. Refused once any remittance exists (that is the client's statutory payment history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_044_down$$
CREATE PROCEDURE stageb_044_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_remittances') THEN
  IF (SELECT COUNT(*) FROM acct_remittances)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Remittances are in use; rollback would delete the remittance records';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_remittances;
END$$
DELIMITER ;
CALL stageb_044_down();
DROP PROCEDURE stageb_044_down;
