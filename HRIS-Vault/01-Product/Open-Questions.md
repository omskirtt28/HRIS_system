---
title: Open Questions
type: plan
status: review
tags: [product, decisions, needs-verification]
updated: 2026-10-24
---

# Open Questions

Decisions John needs to make. Each is blocking something concrete. When one is decided: tick it, record the answer inline, and if it is architectural, promote it to an ADR in [[Decision-Log]].

> [!warning] These are not rhetorical
> Several of these silently change the data model. Deciding them late means a migration. The top five block [[Build-Plan]] SLICE-01.

## Blocking — decide before writing schema

- [ ] **Q1. One company or many?** Will this system ever hold a second legal entity, or client companies as in an agency/manpower setup? The existing codebase already has `clients` and `branches`, which suggests yes. This determines whether every table carries a tenant key. → [[ADR-002-Multi-Tenancy]], [[Data-Model]]
- [ ] **Q2. Keep the PHP/MySQL codebase or rewrite?** There is a working modular monolith already. Rewriting costs months; keeping it constrains tooling. → [[ADR-001-Tech-Stack]], [[Architecture]]
- [ ] **Q3. Are statutory rates config rows or code constants?** Strongly recommended as effectivity-dated config, because the whole reproducibility goal depends on it. Needs an explicit yes. → [[ADR-003-Payroll-Engine-Approach]], [[Payroll]]
- [ ] **Q4. Pay frequency and cut-off calendar.** Semi-monthly 1–15 / 16–end, or 11–25 / 26–10? Is the cut-off the same for all employees, or different for office vs site staff? Does pay date fall in the same month as the cut-off? This shapes the DTR lock, the tax table used, and 13th month computation. → [[Payroll]], [[Attendance-and-DTR]]
- [ ] **Q5. How does attendance get in?** Live integration with the biometric device, scheduled export file dropped in a folder, or manual upload by the payroll officer? Which device make/model, and what does its export actually look like? Everything in [[Attendance-and-DTR]] downstream depends on this. → needs ADR-004

## Important — decide before the payroll slice

- [ ] **Q6. Which leave types exist, with what rules?** Statutory SIL only, or company VL/SL on top? Accrual monthly or granted annually in advance? Carry-over cap? Which types convert to cash? Is SL convertible? → [[Leave-Management]], [[Business-Rules]]
- [ ] **Q7. Who is exempt from OT and night differential?** Managerial and supervisory employees typically are, but the boundary is a company policy call and must be a field on the employee record, not a guess. → [[Employee-Records]], [[Business-Rules]]
- [ ] **Q8. Monthly-paid or daily-paid employees — or both?** This changes the divisor used to derive a daily and hourly rate, and whether unworked holidays are already included in the monthly salary. It is the single most common source of PH payroll disputes. → [[Payroll]], [[Business-Rules]]
- [ ] **Q9. How are corrections after release handled?** Void and reissue the payslip, or post an adjustment in the next period? Released runs should be immutable, so this needs an answer. → needs ADR-009, [[Payroll]]
- [ ] **Q10. What exactly must appear on the payslip?** Required lines, YTD figures, employer share shown or not, language, and whether a PDF must be downloadable. → [[Self-Service-Portal]], [[Payroll]]
- [ ] **Q11. Loans and cash advances.** Are SSS/Pag-IBIG loans and company advances tracked with amortisation schedules inside this system, or entered as a flat deduction per period? → [[Payroll]]
- [ ] **Q12. De minimis and allowances.** Which allowances does the company actually give, which are taxable, and which fall under de minimis ceilings? → [[Payroll]], [[PH-Labor-and-Payroll-Notes]]

## Operational

- [ ] **Q13. Where does this run?** Stays on XAMPP on an office machine, moves to a VPS, or hosted locally with external access for field employees? Drives the whole security posture. → [[Architecture]], [[Security-and-Compliance]]
- [ ] **Q14. Backup and recovery.** Who takes backups, how often, tested how? Payroll data loss is unrecoverable by hand. → [[Security-and-Compliance]]
- [ ] **Q15. Do employees get accounts?** All 300, or only office staff? If field employees have no account, HR files leave on their behalf (US-406) and self-service value drops. → [[Self-Service-Portal]], [[Auth-and-RBAC]]
- [ ] **Q16. Email sending.** Is there an SMTP account or transactional email service available for notifications and payslip alerts? → [[Notifications]]
- [ ] **Q17. Data migration.** What historical data must come in — current employees only, or past payroll history too? Partial-year history matters for 13th month and tax annualisation. → [[Employee-Records]], [[Payroll]]
- [ ] **Q18. Document storage.** Scanned 201 documents: filesystem, database, or object storage? With what retention and access logging? → needs ADR-006, [[Security-and-Compliance]]

- [x] **Q28. TC access.** Team Coordinator is assumed to have the same access as ADL (files Manpower/NetSuite, approver-capable). Confirm. → [[Self-Service-Portal]] — **Answered 2026-10-24 (John):** yes; migration 011 gives TC `payroll.approve_adl`, same grants as ADL. COORDINATOR is a different (recruitment) role.
- [ ] **Q29. Staff lost Manpower and NetSuite.** Migration 010 gave the `EMPLOYEE` role only the five staff types, so plain employees can no longer file Manpower or NetSuite Concern (by design). Confirm no staff member needs them before ADL/SC/OIC/TC roles exist. → [[Self-Service-Portal]]
- [ ] **Q30. Approvers for unassigned employees.** 363 active employees still have no Manager/ADL approver for Payroll Concern. Once HR creates the ADL/TC/OIC/Manager accounts and links them to employees, assign them in Ticket Approvers. Who does this, and by when? → [[Auth-and-RBAC]]
- [ ] **Q31. Super Admin from the UI.** Super Admin cannot be assigned from the user screen by anyone (BR-122). Is a DB-level procedure acceptable for the rare new Super Admin, or is a guarded UI path wanted? → [[Auth-and-RBAC]]
- [ ] **Q32. Override semantics.** Extra viewers only add; there is no way to remove the branch lead's or ADL's view of one employee. Is replacement ever needed? → [[Self-Service-Portal]]
- [ ] **Q33. Branch lead data.** 23 of 63 staffed retail branches have no Sales Captain on record, 1 branch has two, and only one Sales Captain has an account. Who supplies the leads and creates the accounts? → [[Org-Structure]]
- [ ] **Q34. ADL setup.** (Updated 2026-10-24 after 012: 7 of 8 ADLs still have no account; 1 ADL now has Assigned areas, Ocampos.) Originally: 7 of 8 ADLs have no account, none has Assigned areas, 4 show branch Head Office, 3 active retail branches have no area. HR to complete before ADL coverage works. → [[Org-Structure]]
- [ ] **Q35. Attachments for viewers.** Viewers see no attachment list and cannot download (strict reading of read-only). Confirm, or allow download. → [[Self-Service-Portal]]
- [ ] **Q36. Hazelyn Cabrera as OMOA lead.** Hazelyn Cabrera (1637, Sales Captain, active account, works at OMOA) is not yet a branch lead of OMOA, so Ocampo's OMOA employees show the ADL only. HR to assign her on Ticket Viewers (confirm she is the lead, and whether other Ocampo's branches need leads). → [[Org-Structure]]
- [ ] **Q37. Stale area on a non-ADL.** Employee 1651 (Sales Captain, no account) carries the Ocampos area in Assigned areas for ADL. It has no effect (only an ADL's areas count), but it looks like a mistaken entry; HR to clear it or confirm. → [[Org-Structure]]

## Compliance

- [ ] **Q19. Is there a designated Data Protection Officer and a privacy notice?** RA 10173 obligations attach to the company, and the system must support consent capture and data-subject requests. → [[Security-and-Compliance]]
- [ ] **Q20. Retention periods.** How long are payroll records, DTRs and 201 files kept, and what happens at the end? → [[Security-and-Compliance]]
- [ ] **Q21. Which government reports are actually filed, and in which format?** Agencies accept specific file layouts or portal uploads, and these differ by employer size. → [[Reports-and-Analytics]]

## Statutory figures to verify

- [ ] **Q22.** Confirm current SSS contribution schedule and MSC brackets, with effectivity date and circular number.
- [ ] **Q23.** Confirm current PhilHealth premium rate, floor and ceiling.
- [ ] **Q24.** Confirm current Pag-IBIG contribution rates and the compensation cap.
- [ ] **Q25.** Confirm the current BIR withholding tax tables for each payroll frequency in use.
- [ ] **Q26.** Confirm the de minimis benefit ceilings and the 13th-month/other-benefits tax-exempt ceiling.
- [ ] **Q27.** Confirm current night differential percentage, OT multipliers and holiday pay rules against the Labor Code and the latest DOLE handbook.

> [!warning] NEEDS VERIFICATION
> Q22–Q27 are all deliberately unanswered in this vault. No figure for any of them appears anywhere as fact. Each must be confirmed against the issuing agency's current circular, recorded with its effectivity date in [[PH-Labor-and-Payroll-Notes]], and entered as a config row — never as a constant in code.

Related: [[Decision-Log]] · [[Roadmap]] · [[Business-Rules]] · [[PH-Labor-and-Payroll-Notes]] · [[Start-Here]]
