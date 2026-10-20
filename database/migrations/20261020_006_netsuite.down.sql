DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_006_down$$
CREATE PROCEDURE stageb_006_down()
BEGIN
 IF EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code='NETSUITE_CONCERN') OR EXISTS(SELECT 1 FROM ticket_netsuite_remarks) OR EXISTS(SELECT 1 FROM ticket_accounting_memberships WHERE provenance<>'SEED' OR assigned_by IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='NetSuite/Accounting is in use; retain data and history';
 END IF;
 DELETE FROM ticket_types WHERE code='NETSUITE_CONCERN';
 ALTER TABLE ticket_steps DROP CONSTRAINT chk_ticket_step_type, DROP CONSTRAINT chk_ticket_step_named;
 ALTER TABLE ticket_steps ADD CONSTRAINT chk_ticket_step_type CHECK(approver_type IN ('MANAGER','ADL','HR')),ADD CONSTRAINT chk_ticket_step_named CHECK(approver_type='HR' OR approver_user_id IS NOT NULL);
 DROP TABLE ticket_netsuite_remarks;
 DROP TABLE ticket_accounting_memberships;
 DROP TABLE ticket_accounting_seed_results;
 DELETE p FROM permissions p JOIN ticket_accounting_permission_seed s ON s.permission_id=p.id WHERE s.owned=1;
 DROP TABLE ticket_accounting_permission_seed;
END$$
DELIMITER ;
CALL stageb_006_down();
DROP PROCEDURE stageb_006_down;
