-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- rec-manpower-intake (SPEC-rec-manpower-intake.md): link a recruitment manpower request to the Manpower Request ticket it came from.
-- One nullable column, unique so a ticket can be approved into at most one request. Idempotent. No existing row is changed.
ALTER TABLE manpower_requests
  ADD COLUMN IF NOT EXISTS ticket_id BIGINT UNSIGNED NULL AFTER request_no,
  ADD UNIQUE KEY IF NOT EXISTS uq_mr_ticket (ticket_id),
  ADD FOREIGN KEY IF NOT EXISTS fk_mr_ticket (ticket_id) REFERENCES tickets(id);
