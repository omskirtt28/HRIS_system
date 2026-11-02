-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- rec-endorse-manager (SPEC-rec-endorse-manager.md): HR endorses a candidate to one or more reviewers (Manager / ADL / SC / TC); each has their own decision.
-- Standalone table: endorsements (client flow) is NOT altered. Idempotent. No existing row is changed.
CREATE TABLE IF NOT EXISTS application_reviewers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  endorsed_by BIGINT UNSIGNED NOT NULL,
  note TEXT NULL,
  endorsed_at DATETIME NOT NULL,
  decision VARCHAR(30) NULL,
  decision_comment TEXT NULL,
  decided_at DATETIME NULL,
  UNIQUE KEY uq_ar_app_reviewer (application_id, reviewer_user_id),
  KEY idx_ar_reviewer (reviewer_user_id, decision),
  CONSTRAINT chk_ar_decision CHECK (decision IS NULL OR decision IN ('PROCEED','NOT_SUITABLE','ANOTHER_INTERVIEW')),
  CONSTRAINT fk_ar_app FOREIGN KEY (application_id) REFERENCES applications(id),
  CONSTRAINT fk_ar_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id),
  CONSTRAINT fk_ar_by FOREIGN KEY (endorsed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES
 ('recruitment.review','Review Endorsed Candidates','Recruitment','See only the candidates HR endorsed to me, view the resume, schedule an interview and give a decision.',34);

-- Only the four reviewer roles; never recruitment.view, so they cannot open Applicants or Job Openings.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='recruitment.review' WHERE r.code IN ('MANAGER','ADL','SC','TC');
