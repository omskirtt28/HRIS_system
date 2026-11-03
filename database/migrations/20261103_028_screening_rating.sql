-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- rec-screening-score (SPEC-rec-screening-score.md): Negative/Fair/Outstanding rating, job site type, role MT, review grant for OIC and MT.
-- Re-runnable. Roles/grants that already existed are never touched by the down script (ledger tables record what this migration added).
ALTER TABLE applications ADD COLUMN IF NOT EXISTS screening_rating VARCHAR(12) NULL;
ALTER TABLE screening_reviews ADD COLUMN IF NOT EXISTS rating VARCHAR(12) NULL;
ALTER TABLE job_openings ADD COLUMN IF NOT EXISTS site_type VARCHAR(12) NULL; -- RETAIL / HEAD_OFFICE / OTHER; NULL = take it from the manpower request branch

CREATE TABLE IF NOT EXISTS screening_028_seed(kind VARCHAR(10) NOT NULL,ref VARCHAR(100) NOT NULL,PRIMARY KEY(kind,ref)) ENGINE=InnoDB;

-- Role MT (Management Trainee, employee portal). Recorded only when this run created it.
INSERT INTO screening_028_seed(kind,ref) SELECT 'role','MT' FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM roles WHERE code='MT');
INSERT IGNORE INTO roles(code,name,portal,is_system) VALUES('MT','Management Trainee','employee',0);

-- MT gets what Sales Captain gets (no payroll approver), then the review permission for OIC and MT.
INSERT INTO screening_028_seed(kind,ref)
SELECT 'grant',CONCAT(r.code,':',p.code) FROM roles r JOIN role_permissions sc ON sc.role_id=(SELECT id FROM roles WHERE code='SC') JOIN permissions p ON p.id=sc.permission_id
WHERE r.code='MT' AND NOT EXISTS(SELECT 1 FROM role_permissions x WHERE x.role_id=r.id AND x.permission_id=p.id) AND EXISTS(SELECT 1 FROM screening_028_seed s WHERE s.kind='role' AND s.ref='MT');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT (SELECT id FROM roles WHERE code='MT'),sc.permission_id FROM role_permissions sc WHERE sc.role_id=(SELECT id FROM roles WHERE code='SC') AND EXISTS(SELECT 1 FROM screening_028_seed s WHERE s.kind='role' AND s.ref='MT');
INSERT INTO screening_028_seed(kind,ref)
SELECT 'grant',CONCAT(r.code,':recruitment.review') FROM roles r WHERE r.code IN('OIC','MT') AND NOT EXISTS(SELECT 1 FROM role_permissions x JOIN permissions p ON p.id=x.permission_id WHERE x.role_id=r.id AND p.code='recruitment.review');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='recruitment.review' WHERE r.code IN('OIC','MT');
