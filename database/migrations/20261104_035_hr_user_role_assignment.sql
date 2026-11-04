-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- endorse-inline-role-fix (SPEC-endorse-inline-role-fix.md, John 2026-11-04): HR sets a reviewer-position account to Sales Captain or Management Trainee from the Endorse card.
-- One narrow permission instead of widening HR_USER (no users.manage, no recruitment.review, no self-service grants). Idempotent. Super Admin passes every permission check, so it is granted to HR_USER only.
INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('recruitment.set_reviewer_role','Set Reviewer Role From Endorse Card','Recruitment','On the Endorse card, set a listed reviewer-position account (Sales Captain, Management Trainee) to the role its position maps to. Does not open Users & Access.',35);

CREATE TABLE IF NOT EXISTS hr_role_assignment_035_seed (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @first_run=(SELECT COUNT(*)=0 FROM hr_role_assignment_035_seed);

-- Ledger records only grants this migration adds, so down removes exactly those.
INSERT IGNORE INTO hr_role_assignment_035_seed(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='recruitment.set_reviewer_role'
 WHERE @first_run=1 AND r.code='HR_USER'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM hr_role_assignment_035_seed;
