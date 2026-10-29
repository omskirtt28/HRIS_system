-- Select the intended database first. Rollback is refused if any PMBSI department exists.
DELIMITER $$
DROP PROCEDURE IF EXISTS department_work_company_down$$
CREATE PROCEDURE department_work_company_down()
BEGIN
 IF EXISTS (SELECT 1 FROM departments WHERE work_company <> '2LOG') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Non-2LOG departments exist; rollback would destroy classification';
 END IF;
 ALTER TABLE departments DROP COLUMN work_company;
END$$
DELIMITER ;
CALL department_work_company_down();
DROP PROCEDURE department_work_company_down;
