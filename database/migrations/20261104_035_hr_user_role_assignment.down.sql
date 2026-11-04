-- Select the intended database first. Removes the grant this migration added (ledger), then the permission and ledger. Grants hold no business data, so no used-data guard is needed.
DELETE rp FROM role_permissions rp JOIN hr_role_assignment_035_seed s ON s.role_id=rp.role_id AND s.permission_id=rp.permission_id;
DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code='recruitment.set_reviewer_role';
DELETE FROM permissions WHERE code='recruitment.set_reviewer_role';
DROP TABLE IF EXISTS hr_role_assignment_035_seed;
