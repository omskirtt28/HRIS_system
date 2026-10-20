-- Slice-15 step 2: additive, no changes to legacy payroll/leave tables.
CREATE TABLE IF NOT EXISTS payroll_concerns (
 ticket_id BIGINT UNSIGNED PRIMARY KEY,
 concern_date DATE NOT NULL,
 is_late TINYINT(1) NOT NULL DEFAULT 0,
 late_reason VARCHAR(40) NULL,
 outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 outcome_note TEXT NULL,
 CONSTRAINT fk_concern_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 CONSTRAINT chk_concern_outcome CHECK(outcome IS NULL OR outcome IN ('NO_CHANGE','MANUAL_ADJUSTMENT')),
 KEY idx_concern_date(concern_date,is_late)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_submission_keys (
 submission_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 requester_user_id BIGINT UNSIGNED NOT NULL,
 ticket_id BIGINT UNSIGNED NOT NULL UNIQUE,
 FOREIGN KEY(requester_user_id) REFERENCES users(id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ticket_notification_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id BIGINT UNSIGNED NOT NULL,
 recipient_user_id BIGINT UNSIGNED NOT NULL,
 delivered_at DATETIME NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_ticket_notification(event_id,recipient_user_id),
 KEY idx_ticket_notification_pending(delivered_at,id),
 FOREIGN KEY(event_id) REFERENCES ticket_events(id),
 FOREIGN KEY(recipient_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ticket_types(code,name,routing_profile,routing_config_json) VALUES
 ('PAYROLL_CONCERN','Payroll Concern','HO_MANAGER_THEN_HR','{"HEAD_OFFICE":"HO_MANAGER_THEN_HR","BRANCH":"BRANCH_ADL_THEN_HR","LATE":"HR_ONLY"}');
