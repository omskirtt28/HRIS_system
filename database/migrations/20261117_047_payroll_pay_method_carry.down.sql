-- Select the intended database first. Refused once a FIXED pay method or a carried balance exists (dropping them changes pay history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_047_down$$
CREATE PROCEDURE stageb_047_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_employee_rates' AND column_name='pay_method') THEN
  IF (SELECT COUNT(*) FROM payroll_employee_rates WHERE pay_method<>'PER_HOUR')>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixed pay methods are in use; rollback would change how those employees are paid';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payroll_run_employees' AND column_name='carry_out') THEN
  IF (SELECT COUNT(*) FROM payroll_run_employees WHERE carry_out<>0)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Carried balances are in use; rollback would lose amounts owed by employees';
  END IF;
 END IF;
 ALTER TABLE payroll_employee_rates DROP CONSTRAINT IF EXISTS chk_per_pay_method;
 ALTER TABLE payroll_employee_rates DROP COLUMN IF EXISTS pay_method;
 ALTER TABLE payroll_run_employees DROP COLUMN IF EXISTS carry_out;
END$$
DELIMITER ;
CALL stageb_047_down();
DROP PROCEDURE stageb_047_down;
