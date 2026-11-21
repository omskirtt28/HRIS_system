-- Select the intended database first. Refused once any cheque or any e-wallet/petty cash account exists (rollback would lose them).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_052_down$$
CREATE PROCEDURE stageb_052_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_cheques') THEN
  IF (SELECT COUNT(*) FROM acct_cheques)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cheques are recorded; rollback would delete the cheque register';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='acct_bank_accounts' AND column_name='kind') THEN
  IF (SELECT COUNT(*) FROM acct_bank_accounts WHERE kind<>'BANK')>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='E-wallet or petty cash accounts exist; rollback would turn them into bank accounts';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_cheques;
 ALTER TABLE acct_bank_accounts DROP COLUMN IF EXISTS kind;
END$$
DELIMITER ;
CALL stageb_052_down();
DROP PROCEDURE stageb_052_down;
