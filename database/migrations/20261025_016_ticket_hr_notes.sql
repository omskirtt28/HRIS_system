-- Patch 4: HR-only internal notes on a ticket status change. Additive and re-runnable.
-- Nothing in the requester, viewer, approver, Accounting, notification or event code reads this table; only the HR detail view does.
CREATE TABLE IF NOT EXISTS ticket_hr_notes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 ticket_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NULL,
 author_user_id BIGINT UNSIGNED NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_ticket_hr_notes_ticket(ticket_id,id),
 CONSTRAINT fk_ticket_hr_notes_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB COMMENT='HR-only internal notes; never shown to the requester, viewers, approvers or Accounting';
