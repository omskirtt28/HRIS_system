-- Select the intended database before running this additive migration (take a dump first).
-- Accounting: void a draft without losing its JE number, and year-end closing entries (HRIS-Vault/02-Modules/Accounting.md, John 2026-11-17).
-- Idempotent: IF NOT EXISTS / IF EXISTS on every change. Needs migration 039.
ALTER TABLE acct_journal_entries
  ADD COLUMN IF NOT EXISTS entry_kind VARCHAR(10) NOT NULL DEFAULT 'NORMAL' AFTER status,
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL AFTER return_note,
  ADD COLUMN IF NOT EXISTS voided_by_user_id BIGINT UNSIGNED NULL AFTER posted_at,
  ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL AFTER voided_by_user_id;
ALTER TABLE acct_journal_entries ADD CONSTRAINT fk_acct_je_voider FOREIGN KEY IF NOT EXISTS (voided_by_user_id) REFERENCES users(id);
-- VOID = a discarded draft; it keeps its number so the JE sequence has no gaps. CLOSING = year-end entry, left out of the income statement.
ALTER TABLE acct_journal_entries DROP CONSTRAINT IF EXISTS chk_acct_je_status;
ALTER TABLE acct_journal_entries ADD CONSTRAINT chk_acct_je_status CHECK (status IN ('DRAFT','FOR_APPROVAL','POSTED','VOID'));
ALTER TABLE acct_journal_entries DROP CONSTRAINT IF EXISTS chk_acct_je_kind;
ALTER TABLE acct_journal_entries ADD CONSTRAINT chk_acct_je_kind CHECK (entry_kind IN ('NORMAL','CLOSING'));
