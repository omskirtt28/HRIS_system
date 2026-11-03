-- Select the intended database first. Refused while ratings, site types or MT users exist (that data would be destroyed).
DELIMITER $$
DROP PROCEDURE IF EXISTS screening_028_down$$
CREATE PROCEDURE screening_028_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='applications' AND column_name='screening_rating') THEN
  IF (SELECT COUNT(*) FROM applications WHERE screening_rating IS NOT NULL)>0 OR (SELECT COUNT(*) FROM screening_reviews WHERE rating IS NOT NULL)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Screening ratings are in use; rollback would destroy them';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_openings' AND column_name='site_type') THEN
  IF (SELECT COUNT(*) FROM job_openings WHERE site_type IS NOT NULL)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Job site types are in use; rollback would destroy them';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM screening_028_seed WHERE kind='role' AND ref='MT') AND (SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='MT')>0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Users hold the MT role; rollback refused';
 END IF;
 DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
  JOIN screening_028_seed s ON s.kind='grant' AND s.ref=CONCAT(r.code,':',p.code) WHERE r.code='OIC';
 IF EXISTS(SELECT 1 FROM screening_028_seed WHERE kind='role' AND ref='MT') THEN
  DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.code='MT';
  DELETE FROM roles WHERE code='MT';
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='applications' AND column_name='screening_rating') THEN ALTER TABLE applications DROP COLUMN screening_rating; END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='screening_reviews' AND column_name='rating') THEN ALTER TABLE screening_reviews DROP COLUMN rating; END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_openings' AND column_name='site_type') THEN ALTER TABLE job_openings DROP COLUMN site_type; END IF;
 DROP TABLE IF EXISTS screening_028_seed;
END$$
DELIMITER ;
CALL screening_028_down();
DROP PROCEDURE screening_028_down;
