-- Select the intended database first. Refused while any HR user has a saved Pipeline view (that data would be destroyed).
DELIMITER $$
DROP PROCEDURE IF EXISTS pipeline_view_036_down$$
CREATE PROCEDURE pipeline_view_036_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='user_pipeline_view') THEN
  IF (SELECT COUNT(*) FROM user_pipeline_view)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Saved Pipeline views are in use; rollback would destroy them';
  END IF;
  DROP TABLE IF EXISTS user_pipeline_view_branches;
  DROP TABLE user_pipeline_view;
 END IF;
END$$
DELIMITER ;
CALL pipeline_view_036_down();
DROP PROCEDURE pipeline_view_036_down;
