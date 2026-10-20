DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_008_down$$
CREATE PROCEDURE stageb_008_down()
BEGIN
 IF EXISTS(SELECT 1 FROM branch_transfers) OR EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='BRANCH_TRANSFER') OR EXISTS(SELECT 1 FROM ticket_effects WHERE effect_type='BRANCH_TRANSFER')
 OR EXISTS(SELECT 1 FROM employee_placements WHERE source_ticket_id IS NOT NULL OR provenance<>'OBSERVED_CURRENT' OR effective_to IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Branch Transfer/placement history is in use; retain history';
 END IF;
 DELETE FROM ticket_types WHERE code='BRANCH_TRANSFER';DROP TABLE branch_transfers;
 IF (SELECT created_placements FROM ticket_transfer_migration_state WHERE id=1)=1 THEN DROP TABLE employee_placements;END IF;
 DELETE rp FROM role_permissions rp JOIN ticket_transfer_permission_seed s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
 DELETE p FROM permissions p JOIN ticket_transfer_permission_seed s ON s.permission_id=p.id WHERE s.role_id=0;
 DROP TABLE ticket_transfer_permission_seed;DROP TABLE ticket_transfer_migration_state;
END$$
DELIMITER ;
CALL stageb_008_down();DROP PROCEDURE stageb_008_down;
