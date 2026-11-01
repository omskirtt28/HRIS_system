-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- Creates one new table: a lead's (Manager / ADL / Sales Captain / OIC) "Follow up with HR" on a request, used for the once-per-24h limit. Nothing existing is changed.
CREATE TABLE IF NOT EXISTS ticket_lead_followups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_followup (ticket_id,user_id,created_at),
  CONSTRAINT fk_lead_followup_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
  CONSTRAINT fk_lead_followup_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
