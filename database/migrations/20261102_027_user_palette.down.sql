-- Select the intended database first. Rollback is refused while any user picked a palette other than light (the column holds that choice).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_027_down$$
CREATE PROCEDURE stageb_027_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='ui_palette') THEN
  IF (SELECT COUNT(*) FROM users WHERE ui_palette<>'light') > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='User palette data is in use; rollback would destroy it';
  END IF;
  ALTER TABLE users DROP COLUMN ui_palette;
 END IF;
END$$
DELIMITER ;
CALL stageb_027_down();
DROP PROCEDURE stageb_027_down;
