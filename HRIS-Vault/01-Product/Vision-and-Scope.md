---
title: Vision and Scope
type: plan
status: draft
tags: [product, mvp]
updated: 2026-10-17
---

# Vision and Scope

## Problem

HR and payroll in a mid-sized Philippine company runs on spreadsheets, a biometric terminal's export file, and institutional memory. Concretely:

- The 201 file is a physical folder. Nobody knows which employees are missing a PhilHealth number until a remittance fails.
- Attendance arrives as a raw punch dump. Someone manually converts punches into a DTR, interprets holidays, and types totals into a payroll sheet. This is the single largest source of error and overtime-for-HR.
- Payroll is a workbook with hand-maintained formulas. When SSS or BIR changes a table, the formulas are edited in place and the old period can no longer be reproduced.
- Leave balances are tracked in a separate sheet, so an employee's filed leave, approved leave, and paid leave disagree.
- Government reports (alphalist, R-3, RF-1, MCRF) are re-keyed from the payroll sheet each period.
- Employees ask HR by chat for their payslip, leave balance and 201 details, because there is nowhere to look.

The cost is not just time: it is **unreproducible payroll**. A finished run cannot be re-derived from its inputs, so disputes are settled by argument.

## Vision

One system where an employee exists once, their attendance is derived from immutable raw logs by explicit rules, payroll is a reproducible function of attendance plus versioned rate tables, and every statutory report is generated from the same data rather than re-keyed.

The guiding property: **any past payroll run can be recomputed and will produce the same numbers**, because the rules that produced it are stored with it.

## Goals

1. **Single employee record.** One `employees` row is the authority for identity, employment and government IDs, referenced by every module. See [[Employee-Records]].
2. **Attendance that computes itself.** Raw punches in, a reviewable DTR out, with tardiness, undertime, OT, night differential and day type applied by rule rather than by judgement. See [[Attendance-and-DTR]].
3. **Reproducible payroll.** A run snapshots its inputs and the rate-table versions it used; recomputing yields identical output. See [[Payroll]] and [[ADR-003-Payroll-Engine-Approach]].
4. **Leave with one balance.** Filed, approved and paid leave reconcile to a single ledger. See [[Leave-Management]].
5. **Reports generated, not re-keyed.** Government and management reports read from payroll output. See [[Reports-and-Analytics]].
6. **Employee self-service.** Payslip, DTR, leave balance and 201 data visible to the employee without asking HR. See [[Self-Service-Portal]].
7. **Defensible compliance.** PII handled per RA 10173, with audit trails on anything that touches pay or personal data. See [[Security-and-Compliance]].

## Non-goals

Explicitly out of scope. Saying so now prevents scope creep later.

| Non-goal | Why |
|---|---|
| General ledger / full accounting | Payroll produces a journal export; the books live in accounting software. |
| Bank payment file generation in MVP | Needs a confirmed bank format per bank. Deferred to v1 once a bank is chosen. |
| Native mobile apps | Responsive web first. A PWA shell is a v1 consideration, not MVP. |
| Biometric device firmware / vendor SDK work | The system ingests logs; it does not manage devices. See [[ADR-002-Multi-Tenancy]] for the related scoping question. |
| Learning management / training catalog | Adjacent product. Performance is already a Later item — see [[Performance-Management]]. |
| Benefits administration beyond statutory | HMO and insurance administration is vendor-portal work. |
| Multi-country payroll | Philippine statutory rules only. Localisation is a rewrite, not a feature. |
| Automatic statutory rate updates from the internet | Rates are entered by a human who read the circular, with effectivity dates. Automation here is a liability. |

## Success metrics

| Metric | Baseline (today) | Target |
|---|---|---|
| Time to close one payroll cut-off | Days of spreadsheet work | Under 4 hours, dominated by DTR review |
| Payroll items corrected after release | Unknown, discovered by complaint | Under 1% of payslips per run, each with a traceable adjustment |
| Reproducibility | Not possible | 100% of runs recomputable to the same net pay |
| Employee queries to HR for payslip/leave balance | Constant | Near zero; self-service covers it |
| Time to produce a government remittance report | Manual re-keying per period | Generated in under 5 minutes |
| 201 records with complete government IDs | Partial | 100% before an employee can be included in a payroll run |

## Constraints

- **Single developer** (John Joshua) plus Claude Code. Scope must fit that. This argues strongly for the modular monolith in [[Architecture]].
- An **existing PHP 8.2 / MySQL 8 codebase** already implements recruitment, employee directory, attendance import and parts of payroll. This is a brownfield project; the plan must build on it, not pretend otherwise. See [[ADR-001-Tech-Stack]].
- Local deployment (XAMPP) today; hosted deployment is a decision, not a given.
- Philippine statutory compliance is non-negotiable and changes annually.

## Scope boundary for MVP

MVP = the minimum that lets one company run one real payroll cut-off end to end without a spreadsheet:

[[Auth-and-RBAC]] → [[Org-Structure]] → [[Employee-Records]] → [[Attendance-and-DTR]] → [[Leave-Management]] → [[Payroll]] → payslip.

Everything else is v1 or later. See [[Roadmap]].

Related: [[Personas]] · [[User-Stories]] · [[Roadmap]] · [[Open-Questions]] · [[Build-Plan]] · [[Architecture]]
