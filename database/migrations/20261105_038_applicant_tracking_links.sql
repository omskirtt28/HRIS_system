-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- applicant-tracking-link (SPEC-applicant-tracking-link.md, John approved 2026-11-05): private read-only tracking links.
-- Stores only the SHA-256 of each token, with a 90-day expiry and room for later revocation. Re-runnable. Additive: no existing row changes.
CREATE TABLE IF NOT EXISTS applicant_tracking_links(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_atl_token(token_hash),
 KEY idx_atl_application(application_id),
 CONSTRAINT fk_atl_application FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
