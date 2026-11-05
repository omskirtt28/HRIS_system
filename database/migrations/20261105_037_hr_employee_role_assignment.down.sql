-- Select the intended database first. Removes the grants this migration added (ledger), then both permissions and the ledger. Grants hold no business data, so no used-data guard is needed.
DELETE rp FROM role_permissions rp JOIN hr_employee_role_assignment_037_seed s ON s.role_id=rp.role_id AND s.permission_id=rp.permission_id;
DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code IN ('users.assign_employee_role','employees.view_team');
DELETE FROM permissions WHERE code IN ('users.assign_employee_role','employees.view_team');
DROP TABLE IF EXISTS hr_employee_role_assignment_037_seed;
