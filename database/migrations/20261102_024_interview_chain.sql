-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- interview-chain (SPEC-interview-chain.md): a reviewer can be marked the final interviewer, and records who passed the candidate to them.
-- Two columns on application_reviewers. Idempotent. Existing rows get is_final=0 and passed_by=NULL (HR endorsed), so nothing changes meaning.
ALTER TABLE application_reviewers
  ADD COLUMN IF NOT EXISTS is_final TINYINT(1) NOT NULL DEFAULT 0 AFTER decided_at,
  ADD COLUMN IF NOT EXISTS passed_by BIGINT UNSIGNED NULL AFTER is_final;
