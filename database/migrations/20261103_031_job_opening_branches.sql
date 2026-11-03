-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- hiring-review-pass-visibility (SPEC-hiring-review-pass-visibility.md): HR sets the branches (and so the areas) a Retail job opening covers.
-- The area is branches.area_id, so it is not stored twice. Re-runnable. Additive: no existing row changes.
CREATE TABLE IF NOT EXISTS job_opening_branches(
 job_opening_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(job_opening_id,branch_id),
 CONSTRAINT fk_job_branch_job FOREIGN KEY(job_opening_id) REFERENCES job_openings(id),
 CONSTRAINT fk_job_branch_branch FOREIGN KEY(branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;
