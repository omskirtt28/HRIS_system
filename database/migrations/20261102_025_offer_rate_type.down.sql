-- Select the intended database first. Rollback is refused while any offer was typed as Daily/Hourly (the columns hold that input).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_025_down$$
CREATE PROCEDURE stageb_025_down()
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='offers' AND column_name='rate_type') THEN
  IF (SELECT COUNT(*) FROM offers WHERE rate_type<>'MONTHLY' OR rate_amount IS NOT NULL) > 0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Offer rate data is in use; rollback would destroy it';
  END IF;
  ALTER TABLE offers DROP COLUMN rate_amount, DROP COLUMN rate_type;
 END IF;
END$$
DELIMITER ;
CALL stageb_025_down();
DROP PROCEDURE stageb_025_down;
