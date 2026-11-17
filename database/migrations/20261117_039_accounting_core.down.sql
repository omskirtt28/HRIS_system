-- Select the intended database first. Refused once any journal entry, closed month or chart change exists (that is the client's books).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_039_down$$
CREATE PROCEDURE stageb_039_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_journal_entries') THEN
  IF (SELECT COUNT(*) FROM acct_journal_entries)>0 OR (SELECT COUNT(*) FROM acct_closed_periods)>0
     OR (SELECT COUNT(*) FROM acct_accounts)<>27 OR (SELECT COUNT(*) FROM acct_accounts WHERE updated_at<>created_at OR is_active<>1)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Accounting data is in use; rollback would delete the books';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_core_039_grants') THEN
  DELETE rp FROM role_permissions rp JOIN acct_core_039_grants g ON g.role_id=rp.role_id AND g.permission_id=rp.permission_id;
 END IF;
 DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code IN ('accounting.view','accounting.entry','accounting.approve');
 DELETE FROM permissions WHERE code IN ('accounting.view','accounting.entry','accounting.approve');
 DROP TABLE IF EXISTS acct_journal_lines;
 DROP TABLE IF EXISTS acct_closed_periods;
 DROP TABLE IF EXISTS acct_journal_entries;
 DROP TABLE IF EXISTS acct_accounts;
 DROP TABLE IF EXISTS acct_core_039_grants;
 DROP TABLE IF EXISTS acct_core_039_seed;
END$$
DELIMITER ;
CALL stageb_039_down();
DROP PROCEDURE stageb_039_down;
