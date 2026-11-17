-- Select the intended database first. Refused once any asset exists (that is the client's asset register).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_048_down$$
CREATE PROCEDURE stageb_048_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_assets') THEN
  IF (SELECT COUNT(*) FROM acct_assets)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixed assets are in use; rollback would delete the asset register';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_depreciation;
 DROP TABLE IF EXISTS acct_assets;
END$$
DELIMITER ;
CALL stageb_048_down();
DROP PROCEDURE stageb_048_down;
