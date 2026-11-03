-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- applicant-profile-polish T3 (SPEC-applicant-profile-polish.md): interview scorecard, 1-5 per criterion plus a recommendation. Advice only.
-- Re-runnable. One row per scorer per application (a second save updates it).
CREATE TABLE IF NOT EXISTS interview_scorecards(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 scorer_id BIGINT UNSIGNED NOT NULL,
 communication TINYINT UNSIGNED NOT NULL,
 job_knowledge TINYINT UNSIGNED NOT NULL,
 attitude TINYINT UNSIGNED NOT NULL,
 culture_fit TINYINT UNSIGNED NOT NULL,
 overall TINYINT UNSIGNED NOT NULL,
 recommendation VARCHAR(12) NOT NULL,
 note TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_iscore_app_scorer(application_id,scorer_id),
 CONSTRAINT chk_iscore_range CHECK(communication BETWEEN 1 AND 5 AND job_knowledge BETWEEN 1 AND 5 AND attitude BETWEEN 1 AND 5 AND culture_fit BETWEEN 1 AND 5 AND overall BETWEEN 1 AND 5),
 CONSTRAINT fk_iscore_app FOREIGN KEY(application_id) REFERENCES applications(id),
 CONSTRAINT fk_iscore_user FOREIGN KEY(scorer_id) REFERENCES users(id)
) ENGINE=InnoDB;
