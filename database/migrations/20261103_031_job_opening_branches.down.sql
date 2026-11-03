-- Select the intended database first. Refused while any job opening has a branch scope (that data would be destroyed).
DELIMITER $$
DROP PROCEDURE IF EXISTS job_branch_031_down$$
CREATE PROCEDURE job_branch_031_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='job_opening_branches') THEN
  IF (SELECT COUNT(*) FROM job_opening_branches)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Job opening branch scopes are in use; rollback would destroy them';
  END IF;
  DROP TABLE job_opening_branches;
 END IF;
END$$
DELIMITER ;
CALL job_branch_031_down();
DROP PROCEDURE job_branch_031_down;
