---
title: Accounting CASH card complete (e-wallet, petty cash, cheque register)
status: done (built + verified, applied to hris_demo 2026-11-21)
date: 2026-11-21
---
# Plan: CASH card complete
Source: Friday read-only plan, 2026-11-21. John agreed to all 3 recommended defaults.

## Decisions (John)
1. Petty cash replenish = normal DV/JE, no new code.
2. Cheque may have no payment (payment_id NULL, free-text payee); linking to a payment only for DISBURSEMENT on same bank.
3. STALE = manual button + "stale after 180 days" hint, no cron. 180-day rule NEEDS VERIFICATION, not hard-coded.

## Schema (migration 052, up + guarded down)
- `acct_bank_accounts.kind ENUM('BANK','EWALLET','PETTY') NOT NULL DEFAULT 'BANK'` (information_schema guard).
- New `acct_cheques` (bank_account_id, cheque_no, payee, cheque_date, amount>0, status ISSUED/CLEARED/BOUNCED/VOID/STALE, payment_id NULL UNIQUE, recon_id NULL, memo, created_by, timestamps; UNIQUE(bank_account_id, cheque_no)).
- Down refuses (SIGNAL) if cheque rows or non-BANK kind exist.
- demo_seed.php:24 glob must be widened to `202611*_0[4-9]*`.

## Code
app/CashBank.php (TYPES, saveBank kind, banks() balance, cheque funcs, finishRecon/undoRecon cheque clearing), app/AccountingModule.php (acct_cheque_save, acct_cheque_status, kind field, route `cheques`), app/CashBankViews.php (Type select, balance + Ledger link, cheques page), Accounting.php:94-95 (close checklist skips PETTY), recon picker skips PETTY.
Perms: accounting.entry (ISSUED/VOID), accounting.approve (BOUNCED/STALE). CLEARED only via reconciliation.

## Tests
Extend tests/cash_bank_db_test.php + tests/accounting_http_test.php; migration up/re-run/down/up; `php scripts/check.php task`.

## Vault to sync after build
Accounting.md, Data-Model.md (052), Business-Rules.md, Screens.md, CHANGELOG, Progress, Session-Log.
