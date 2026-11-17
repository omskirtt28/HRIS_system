---
title: Accounting Phase 2 (AP, AR, Cash and Bank, Remittances)
type: plan
status: done
tags: [plan, accounting]
updated: 2026-11-17
---

# Accounting Phase 2: AP, AR, Cash and Bank, Remittances

Status: done - A1 (041), A2 (042), A3 (043), A4 (044) applied hris_clean; step 5 month-close checklist done 2026-11-17 (no migration). Next: Phase 3 or payroll engine
Date: 2026-11-17
Session: [[Session-Log]]

## Context
Phase 1 (migration 039) + void/year-end closing (migration 040) are built. Phase 2 is in [[Accounting]]. Every sub-ledger below posts to the existing journal through `Accounting::insertHeader/insertLines` as a DRAFT JE that goes through the normal approval, so the books stay in one place and segregation of duties is unchanged. One slice per session.

## Mga gagawin
1. **A1 Vendors + Accounts Payable**: vendors table; bill (vendor, date, due date, expense lines, total) -> on approve, JE Dr expense / Cr 2010 AP. AP aging (current, 1-30, 31-60, 61-90, 90+). Screen hidden until built.
2. **A2 Customers + Accounts Receivable**: customers; invoice -> JE Dr 1100 AR / Cr income. AR aging, same buckets.
3. **A3 Cash and Bank**: bank accounts mapped to cash GL accounts; disbursement voucher (pays bills, JE Dr AP / Cr bank) and collection receipt (JE Dr bank / Cr AR); partial payments; bank reconciliation per month (statement balance vs book, mark cleared lines).
4. **A4 Remittances**: record SSS/PhilHealth/Pag-IBIG/BIR remittance (JE Dr payable / Cr bank) with reference no. and period. Deadlines and BIR form numbers NEEDS VERIFICATION, shown as "To confirm". Full value only after the payroll engine (see [[2026-11-17-payroll-engine]]).
5. Month close checklist on Fiscal Periods: all posted, bank reconciled, remittances recorded.

## Hindi kasama
VAT/EWT tax books, fixed assets, accounting dashboard (Phase 3); multi-currency; multi-company books.

## Open questions (John / client)
- VAT registered ba ang client (affects bill/invoice lines: Input/Output VAT).
- EWT on supplier bills: ATC codes and rates NEEDS VERIFICATION.
- Numbering format for bills, invoices, vouchers (default BILL-/INV-/DV-/OR-YYYY-NNNN).
- Who approves bills/vouchers: same accounting.approve, or a new accounting.pay permission.

## Critical files
- app/Accounting.php, app/AccountingModule.php, app/AccountingViews.php, public/assets/accounting.css
- index.php sidebar (Accounting group), database/migrations/ (041+)
- HRIS-Vault/02-Modules/Accounting.md, 03-Design/Data-Model.md, Business-Rules.md, Screens.md

## Verification
- Every document that posts creates exactly one balanced JE; voiding a document before posting voids its draft JE; posted documents are reversed, never edited.
- Aging buckets tested with fixed dates; partial payments never exceed the open balance (row lock).
- Stage B per migration, accounting tests + floors raised in scripts/check.php.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute slice A1 of HRIS-Vault/05-Implementation/Plans/2026-11-17-accounting-phase2.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
