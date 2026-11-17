-- Select the intended database first. Refused once a back pay record exists (dropping it loses pay history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_051_down$$
CREATE PROCEDURE stageb_051_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_final_pays') THEN
  IF (SELECT COUNT(*) FROM payroll_final_pays)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Back pay records are in use; rollback would lose pay history';
  END IF;
 END IF;
 DROP TABLE IF EXISTS payroll_final_pay_items;
 DROP TABLE IF EXISTS payroll_final_pays;
END$$
DELIMITER ;
CALL stageb_051_down();
DROP PROCEDURE stageb_051_down;
