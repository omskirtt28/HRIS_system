---
title: Payroll engine (peso computation) + posting to Accounting
type: plan
status: done
tags: [plan, payroll, accounting]
updated: 2026-11-17
---

# Payroll engine (peso computation) + posting to Accounting

Status: done - P1 (045) and P2-P5 (046) built and applied to hris_clean 2026-11-17; 13th month (050) done 2026-11-17. Later items (final pay, bank file, loans) and Q-P4..Q-P7 remain
Date: 2026-11-17
Session: [[Session-Log]]

## Context
John (2026-11-17) picked "Compute pay in HRIS" for payroll posting to the journal. Today `PayrollCalculator` is attendance only (minutes: regular, late, undertime, OT); there is no gross, deductions, net or payslip, so there is nothing to post. The design is already in [[Payroll]] (rates, divisor, statutory contributions, semi-monthly problem, 13th month, final pay). This plan cuts it into slices. It is its own project: payroll rules, money, a new schema, and every rate is NEEDS VERIFICATION.

## Blockers bago mag-code (John / client)
1. Statutory tables as effectivity-dated config rows, with the circular number: SSS schedule, PhilHealth rate/floor/ceiling, Pag-IBIG rates/cap, BIR withholding table (Q22-Q26 in [[Open-Questions]]). Walang rate na ilalagay sa code o vault.
2. Annual factor / divisor per employment type (monthly-paid), and the OT / holiday / night-diff multipliers (named config rows).
3. Semi-monthly strategy for monthly contributions (one of the three in [[Payroll]]).
4. Which client first (Northwind demo lang ba o real buyer), para alam kung anong data ang pang-test.

## Mga gagawin (one slice per session)
1. **P1 Rate config**: migration for statutory/premium config tables (effectivity-dated, circular no.), admin screen to enter them, read-only to everyone else. No computation yet.
2. **P2 Gross pay**: per finalized cutoff, compute basic + OT/holiday/night premiums from the existing attendance minutes x `payroll_employee_rates`, snapshot per employee (immutable after release). Preview screen + CSV.
3. **P3 Deductions + net**: SSS/PhilHealth/Pag-IBIG EE/ER, withholding tax, loans/advances as manual lines; net pay. Payroll register report.
4. **P4 Release + payslip**: Review -> Release (Finance approver, not the preparer), payslip in the employee portal (existing portal payslip slot).
5. **P5 Post to Accounting**: on release, build a DRAFT journal entry (one per run, linked by `payroll_run_id`, unique) through a payroll-item -> GL account mapping screen; it goes through the normal JE approval. Reversal of a released run = reversing JE.
6. Later: 13th month, final pay, bank file, remittance reports (ties to Accounting Phase 2 Remittances).

## Critical files
- app/PayrollCalculator.php, app/PayrollCutoff*.php, app/PayrollRepository.php (attendance source, do not break)
- app/Accounting.php (insertHeader/insertLines reuse for P5)
- HRIS-Vault/02-Modules/Payroll.md, 03-Design/Business-Rules.md, Data-Model.md

## Verification
- Pure tests for every formula with hand-computed fixtures (no real rates: fixtures use made-up config rows).
- DB tests: release locks the snapshot; re-run refused; posting JE balances and is unique per run.
- Stage B per migration; full check on a pmbsi_hris copy (hris_clean has no employees, most fixtures fail there).

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute slice P1 of HRIS-Vault/05-Implementation/Plans/2026-11-17-payroll-engine.md. Gamitin ang hris-patch skill. Huwag maglagay ng statutory rate sa code. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
