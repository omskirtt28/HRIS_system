-- Select the intended database first. Refused once a voided or closing entry exists (removing the columns would lose that history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_040_down$$
CREATE PROCEDURE stageb_040_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='acct_journal_entries' AND column_name='entry_kind') THEN
  IF (SELECT COUNT(*) FROM acct_journal_entries WHERE status='VOID' OR entry_kind<>'NORMAL' OR void_reason IS NOT NULL)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Voided or closing entries are in use; rollback would lose them';
  END IF;
 END IF;
 ALTER TABLE acct_journal_entries DROP CONSTRAINT IF EXISTS chk_acct_je_kind;
 ALTER TABLE acct_journal_entries DROP CONSTRAINT IF EXISTS chk_acct_je_status;
 ALTER TABLE acct_journal_entries ADD CONSTRAINT chk_acct_je_status CHECK (status IN ('DRAFT','FOR_APPROVAL','POSTED'));
 ALTER TABLE acct_journal_entries DROP FOREIGN KEY IF EXISTS fk_acct_je_voider;
 ALTER TABLE acct_journal_entries DROP INDEX IF EXISTS fk_acct_je_voider;
 ALTER TABLE acct_journal_entries DROP COLUMN IF EXISTS voided_at, DROP COLUMN IF EXISTS voided_by_user_id, DROP COLUMN IF EXISTS void_reason, DROP COLUMN IF EXISTS entry_kind;
END$$
DELIMITER ;
CALL stageb_040_down();
DROP PROCEDURE stageb_040_down;
