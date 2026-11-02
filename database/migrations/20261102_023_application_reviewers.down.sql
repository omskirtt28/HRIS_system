-- Select the intended database first. Rollback is refused while any endorsement-to-reviewer row exists (they hold the reviewers' decisions).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_023_down$$
CREATE PROCEDURE stageb_023_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='application_reviewers') THEN
  IF (SELECT COUNT(*) FROM application_reviewers) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewer endorsements are in use; rollback would destroy them';
  END IF;
  DROP TABLE application_reviewers;
 END IF;
 DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code='recruitment.review';
 DELETE FROM permissions WHERE code='recruitment.review';
END$$
DELIMITER ;
CALL stageb_023_down();
DROP PROCEDURE stageb_023_down;
