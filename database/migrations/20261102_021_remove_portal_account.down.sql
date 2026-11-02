-- Select the intended database first. Rollback is refused while any account is in a removed state (the columns hold the data needed to restore it).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_021_down$$
CREATE PROCEDURE stageb_021_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='removed_at') THEN
  IF (SELECT COUNT(*) FROM users WHERE removed_at IS NOT NULL) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Removed accounts are in use; rollback would destroy them';
  END IF;
 END IF;
 DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code='users.remove';
 DELETE FROM permissions WHERE code='users.remove';
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='removed_at') THEN
  ALTER TABLE users DROP COLUMN removed_status, DROP COLUMN removed_employee_id, DROP COLUMN removed_email, DROP COLUMN removed_at;
 END IF;
END$$
DELIMITER ;
CALL stageb_021_down();
DROP PROCEDURE stageb_021_down;
