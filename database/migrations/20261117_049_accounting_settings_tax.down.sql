-- Select the intended database first. Refused once a setting, a tax code or a taxed bill/invoice exists (that is the client's tax history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_049_down$$
CREATE PROCEDURE stageb_049_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_tax_codes') THEN
  IF (SELECT COUNT(*) FROM acct_tax_codes)>0 OR (SELECT COUNT(*) FROM acct_settings)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Accounting settings or tax codes are in use; rollback would lose the posting setup';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='acct_bills' AND column_name='vat_code_id') THEN
  IF (SELECT COUNT(*) FROM acct_bills WHERE vat_code_id IS NOT NULL OR ewt_code_id IS NOT NULL)>0 OR (SELECT COUNT(*) FROM acct_invoices WHERE vat_code_id IS NOT NULL)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Taxed bills or invoices are in use; rollback would lose their VAT and EWT';
  END IF;
 END IF;
 ALTER TABLE acct_bills DROP COLUMN IF EXISTS vat_code_id, DROP COLUMN IF EXISTS vat_rate, DROP COLUMN IF EXISTS vat_amount, DROP COLUMN IF EXISTS vat_account_id,
   DROP COLUMN IF EXISTS ewt_code_id, DROP COLUMN IF EXISTS ewt_rate, DROP COLUMN IF EXISTS ewt_amount, DROP COLUMN IF EXISTS ewt_account_id;
 ALTER TABLE acct_invoices DROP COLUMN IF EXISTS vat_code_id, DROP COLUMN IF EXISTS vat_rate, DROP COLUMN IF EXISTS vat_amount, DROP COLUMN IF EXISTS vat_account_id;
 DROP TABLE IF EXISTS acct_tax_codes;
 DROP TABLE IF EXISTS acct_settings;
END$$
DELIMITER ;
CALL stageb_049_down();
DROP PROCEDURE stageb_049_down;
