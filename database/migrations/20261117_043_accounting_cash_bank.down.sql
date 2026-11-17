-- Select the intended database first. Refused once any bank account, payment or reconciliation exists (that is the client's cash history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_043_down$$
CREATE PROCEDURE stageb_043_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_recon_lines') THEN
  IF (SELECT COUNT(*) FROM acct_bank_accounts)>0 OR (SELECT COUNT(*) FROM acct_payments)>0 OR (SELECT COUNT(*) FROM acct_bank_recons)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bank accounts, payments or reconciliations are in use; rollback would delete the cash records';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_recon_lines;
 DROP TABLE IF EXISTS acct_bank_recons;
 DROP TABLE IF EXISTS acct_payment_allocations;
 DROP TABLE IF EXISTS acct_payments;
 DROP TABLE IF EXISTS acct_bank_accounts;
END$$
DELIMITER ;
CALL stageb_043_down();
DROP PROCEDURE stageb_043_down;
