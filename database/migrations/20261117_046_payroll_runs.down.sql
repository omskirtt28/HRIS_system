-- Select the intended database first. Refused once any payroll run exists (computed or released pay history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_046_down$$
CREATE PROCEDURE stageb_046_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_runs') THEN
  IF (SELECT COUNT(*) FROM payroll_runs)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll runs are in use; rollback would delete computed pay history';
  END IF;
 END IF;
 DROP TABLE IF EXISTS payroll_run_adjustments;
 DROP TABLE IF EXISTS payroll_run_lines;
 DROP TABLE IF EXISTS payroll_run_employees;
 DROP TABLE IF EXISTS payroll_runs;
END$$
DELIMITER ;
CALL stageb_046_down();
DROP PROCEDURE stageb_046_down;
