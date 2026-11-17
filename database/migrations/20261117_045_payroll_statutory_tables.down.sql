-- Select the intended database first. Refused once any statutory table was entered (payroll runs will cite these versions).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_045_down$$
CREATE PROCEDURE stageb_045_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_stat_tables') THEN
  IF (SELECT COUNT(*) FROM payroll_stat_tables)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Statutory tables are in use; rollback would delete the entered rate versions';
  END IF;
 END IF;
 DROP TABLE IF EXISTS payroll_stat_table_rows;
 DROP TABLE IF EXISTS payroll_stat_tables;
END$$
DELIMITER ;
CALL stageb_045_down();
DROP PROCEDURE stageb_045_down;
