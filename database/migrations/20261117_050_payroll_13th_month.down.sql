-- Select the intended database first. Refused once a 13th month run exists (dropping it loses pay history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_050_down$$
CREATE PROCEDURE stageb_050_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_13th_runs') THEN
  IF (SELECT COUNT(*) FROM payroll_13th_runs)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='13th month runs are in use; rollback would lose pay history';
  END IF;
 END IF;
 DROP TABLE IF EXISTS payroll_13th_employees;
 DROP TABLE IF EXISTS payroll_13th_runs;
END$$
DELIMITER ;
CALL stageb_050_down();
DROP PROCEDURE stageb_050_down;
