-- Select the intended database first. Removes only what migration 032 created.
-- Refused while an account holds the role or an employee holds the position (that data would be orphaned).
DELIMITER $$
DROP PROCEDURE IF EXISTS retail_manager_032_down$$
CREATE PROCEDURE retail_manager_032_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='retail_manager_seed') THEN
  IF EXISTS(SELECT 1 FROM retail_manager_seed s JOIN users u ON u.role_id=s.ref_id WHERE s.item='ROLE') THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Accounts use the Retail Manager role; rollback refused';
  END IF;
  IF EXISTS(SELECT 1 FROM retail_manager_seed s JOIN employees e ON e.position_id=s.ref_id WHERE s.item='POSITION') THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Employees hold the Retail Manager position; rollback refused';
  END IF;
  DELETE rp FROM role_permissions rp JOIN retail_manager_seed s ON s.ref_id=rp.role_id AND s.item='ROLE';
  DELETE r FROM roles r JOIN retail_manager_seed s ON s.ref_id=r.id AND s.item='ROLE';
  DELETE p FROM positions p JOIN retail_manager_seed s ON s.ref_id=p.id AND s.item='POSITION';
  DROP TABLE retail_manager_seed;
 END IF;
END$$
DELIMITER ;
CALL retail_manager_032_down();
DROP PROCEDURE retail_manager_032_down;
