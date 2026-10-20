DELETE rp FROM role_permissions rp JOIN ticket_permission_seeds s ON s.permission_id=rp.permission_id AND s.role_id=rp.role_id WHERE s.role_id<>0;
DELETE p FROM permissions p JOIN ticket_permission_seeds s ON s.permission_id=p.id AND s.role_id=0
 WHERE NOT EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.permission_id=p.id);
DROP TABLE IF EXISTS ticket_permission_seeds;
DROP TABLE IF EXISTS employee_approver_assignments;
