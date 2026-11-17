-- Select the intended database first. Refused once any customer or invoice exists (that is the client's receivables history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_042_down$$
CREATE PROCEDURE stageb_042_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_invoices') THEN
  IF (SELECT COUNT(*) FROM acct_invoices)>0 OR (SELECT COUNT(*) FROM acct_customers)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customers or invoices are in use; rollback would delete the receivables';
  END IF;
 END IF;
 DROP TABLE IF EXISTS acct_invoices;
 DROP TABLE IF EXISTS acct_customers;
END$$
DELIMITER ;
CALL stageb_042_down();
DROP PROCEDURE stageb_042_down;
