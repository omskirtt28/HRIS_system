-- Select the intended database first. Rollback is refused while any encrypted password copy or portal-account ticket exists.
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_020_down$$
CREATE PROCEDURE stageb_020_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='password_enc') THEN
  IF (SELECT COUNT(*) FROM users WHERE password_enc IS NOT NULL OR must_change_password=1 OR password_changed_at IS NOT NULL) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal account data is in use; rollback would destroy it';
  END IF;
 END IF;
 IF EXISTS (SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='PORTAL_ACCOUNT_REQUEST') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal account tickets exist; rollback would destroy them';
 END IF;
 DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code IN ('users.view_directory','users.view_credentials','ticket.file.portal_account');
 DELETE FROM permissions WHERE code IN ('users.view_directory','users.view_credentials','ticket.file.portal_account');
 DELETE FROM ticket_types WHERE code='PORTAL_ACCOUNT_REQUEST';
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='password_enc') THEN
  ALTER TABLE users DROP COLUMN password_changed_at, DROP COLUMN must_change_password, DROP COLUMN password_enc;
 END IF;
END$$
DELIMITER ;
CALL stageb_020_down();
DROP PROCEDURE stageb_020_down;
