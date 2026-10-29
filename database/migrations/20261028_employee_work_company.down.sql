-- Select the intended database first. Rollback is refused if any assignment exists.
DELIMITER $$
DROP PROCEDURE IF EXISTS employee_work_company_down$$
CREATE PROCEDURE employee_work_company_down()
BEGIN
 IF EXISTS (SELECT 1 FROM employees WHERE work_company IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Work company assignments exist; rollback would destroy data';
 END IF;
 ALTER TABLE employees DROP COLUMN work_company;
END$$
DELIMITER ;
CALL employee_work_company_down();
DROP PROCEDURE employee_work_company_down;
