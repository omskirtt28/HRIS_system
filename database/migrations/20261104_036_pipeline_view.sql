-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- pipeline-site-split (SPEC-pipeline-head-office-retail.md): each HR user's saved Pipeline view = Head Office or Retail (+ the Retail branches they picked).
-- A default view, not a permission: HR can switch site any time. Re-runnable. Additive: no existing row changes.
CREATE TABLE IF NOT EXISTS user_pipeline_view(
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 site ENUM('HEAD_OFFICE','RETAIL') NOT NULL DEFAULT 'HEAD_OFFICE',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_upv_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_pipeline_view_branches(
 user_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,branch_id),
 CONSTRAINT fk_upvb_view FOREIGN KEY(user_id) REFERENCES user_pipeline_view(user_id) ON DELETE CASCADE,
 CONSTRAINT fk_upvb_branch FOREIGN KEY(branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;
