---
title: User Stories
type: plan
status: draft
tags: [product, backlog, mvp]
updated: 2026-10-17
---

# User Stories

US-### backlog grouped by module, MoSCoW prioritised per [[Conventions]]. Priorities are relative to **MVP** unless the row says otherwise. Personas are P1–P5 from [[Personas]].

> [!note] How to read this
> A story is a conversation starter, not a spec. The binding detail is the FR table in the module note and the computation rules in [[Business-Rules]].

## Auth, RBAC and admin — [[Auth-and-RBAC]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-101 | As a user, I log in with my company email and password so I reach only my own portal. | All | Must |
| US-102 | As a sys admin, I assign roles to a user so permissions follow the role, not the person. | P5 | Must |
| US-103 | As a sys admin, I scope a manager to a department or branch so they approve only their own team. | P5 | Must |
| US-104 | As a sys admin, I read an audit log of who changed what and when, so pay disputes are resolvable. | P5 | Must |
| US-105 | As a user, I reset my forgotten password without emailing IT. | All | Should |
| US-106 | As a sys admin, I revoke an active session immediately when someone is terminated. | P5 | Should |
| US-107 | As a sys admin, I enforce MFA on payroll and admin roles. | P5 | Could |

## Org structure — [[Org-Structure]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-110 | As a sys admin, I define company, branches, departments and positions so employees can be placed. | P5 | Must |
| US-111 | As an HR admin, I set an employee's reporting manager so approvals route correctly. | P1 | Must |
| US-112 | As an HR admin, I maintain the holiday calendar including local holidays per branch, so payroll applies the right day type. | P1 | Must |
| US-113 | As an HR admin, I see the org chart so I can spot employees with no manager. | P1 | Should |
| US-114 | As a finance user, I tag departments with cost centers so payroll output can be charged correctly. | P2 | Should |
| US-115 | As an HR admin, I reorganise a department without losing the historical placement of past payroll runs. | P1 | Should |

## Employee records — [[Employee-Records]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-201 | As an HR admin, I create an employee 201 record with personal, employment and government ID data. | P1 | Must |
| US-202 | As an HR admin, I am blocked from including an employee in payroll if a required government ID is missing. | P1 | Must |
| US-203 | As an HR admin, I record employment status and its effectivity date so probationary-to-regular transitions are dated. | P1 | Must |
| US-204 | As an HR admin, I record compensation with an effectivity date so a mid-year raise does not rewrite history. | P1 | Must |
| US-205 | As an HR admin, I attach documents to a 201 file: contract, IDs, clearances, medical. | P1 | Must |
| US-206 | As an employee, I update my own contact details and emergency contact. | P4 | Should |
| US-207 | As an employee, I request a change to a sensitive field and HR approves it. | P4 | Should |
| US-208 | As an HR admin, I record dependents so they can be referenced for benefits. | P1 | Should |
| US-209 | As an HR admin, I see which contracts and IDs expire in the next 60 days. | P1 | Should |
| US-210 | As an HR admin, I bulk-import an employee roster from a spreadsheet with a dry-run validation report. | P1 | Should |
| US-211 | As an HR admin, I view a full change history of one employee record. | P1 | Could |

## Attendance and DTR — [[Attendance-and-DTR]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-301 | As a payroll officer, I import biometric punch logs for a date range and see exactly what was accepted and rejected. | P2 | Must |
| US-302 | As a payroll officer, I see a computed DTR per employee per day with hours worked, late, undertime, OT and night differential. | P2 | Must |
| US-303 | As an HR admin, I assign a shift schedule to an employee so required hours are known. | P1 | Must |
| US-304 | As a payroll officer, I correct a missing or wrong punch with a reason, and the correction is audited. | P2 | Must |
| US-305 | As a payroll officer, I lock the DTR for a cut-off so payroll computes on frozen data. | P2 | Must |
| US-306 | As an employee, I view my own DTR during the cut-off so I can dispute errors before payroll runs. | P4 | Must |
| US-307 | As a manager, I approve my team's overtime before it is paid. | P3 | Must |
| US-308 | As a payroll officer, I see the day type applied per date so holiday pay is explainable. | P2 | Must |
| US-309 | As an HR admin, I define a rotating or night shift pattern, including one crossing midnight. | P1 | Should |
| US-310 | As an employee, I file a manual time entry when the biometric device is down. | P4 | Should |
| US-311 | As a payroll officer, I see a list of employees with no attendance at all for the cut-off, so nobody is silently unpaid. | P2 | Must |
| US-312 | As an HR admin, I configure a grace period for tardiness. | P1 | Should |

## Leave management — [[Leave-Management]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-401 | As an employee, I file a leave request choosing a leave type, dates and reason, and see my remaining balance first. | P4 | Must |
| US-402 | As a manager, I approve or reject a leave request with a reason, and the employee is notified. | P3 | Must |
| US-403 | As an HR admin, I configure leave types with accrual rules, carry-over and cash-conversion settings. | P1 | Must |
| US-404 | As an employee, I see my leave balance split into earned, used, pending and available. | P4 | Must |
| US-405 | As a payroll officer, I see approved paid leave reflected in the DTR and paid by payroll. | P2 | Must |
| US-406 | As an HR admin, I file leave on an employee's behalf for those without portal access. | P1 | Must |
| US-407 | As an HR admin, I process maternity leave including the SSS benefit and the salary differential. | P1 | Should |
| US-408 | As an employee, I file a half-day leave. | P4 | Should |
| US-409 | As an HR admin, I run year-end leave processing: carry-over, forfeiture and cash conversion of unused convertible leave. | P1 | Should |
| US-410 | As a manager, I see a team leave calendar so I do not approve overlapping absences. | P3 | Should |
| US-411 | As an employee, I cancel a filed leave before it starts, and the balance is restored. | P4 | Should |
| US-412 | As an HR admin, I attach supporting documents to a leave filing, e.g. a medical certificate. | P1 | Could |

## Payroll — [[Payroll]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-501 | As a payroll officer, I create a payroll run for a cut-off and see which employees are included and why. | P2 | Must |
| US-502 | As a payroll officer, I compute gross earnings from the locked DTR: basic, OT, night differential, holiday pay, allowances. | P2 | Must |
| US-503 | As a payroll officer, I compute statutory deductions from versioned, effectivity-dated rate tables. | P2 | Must |
| US-504 | As a payroll officer, I compute withholding tax per payroll frequency. | P2 | Must |
| US-505 | As a payroll officer, I review a payroll register that foots before releasing anything. | P2 | Must |
| US-506 | As a payroll officer, I drill from a net pay figure down to the individual DTR day and raw punch behind it. | P2 | Must |
| US-507 | As a payroll officer, I reverse or void an unreleased run without leaving partial data. | P2 | Must |
| US-508 | As a payroll officer, I release a run, after which it is immutable and corrections become dated adjustments. | P2 | Must |
| US-509 | As an employee, I view and download my payslip. | P4 | Must |
| US-510 | As a payroll officer, I enter one-off earnings and deductions for a run, e.g. a cash advance or an allowance. | P2 | Must |
| US-511 | As a payroll officer, I manage recurring deductions such as a loan with a balance that amortises per period. | P2 | Should |
| US-512 | As a payroll officer, I compute and release 13th month pay as its own run. | P2 | Should |
| US-513 | As a payroll officer, I compute final pay for a separated employee including pro-rated 13th month and leave conversion. | P2 | Should |
| US-514 | As a payroll officer, I recompute a past run and confirm it reproduces identical figures. | P2 | Must |
| US-515 | As a payroll officer, I export a bank payment file. | P2 | Won't (MVP) |
| US-516 | As a payroll officer, I run year-end tax annualisation producing refunds or additional withholding. | P2 | Could |
| US-517 | As a payroll officer, I configure de minimis benefits so amounts within the ceiling are untaxed and the excess is taxed. | P2 | Should |

## Reports and analytics — [[Reports-and-Analytics]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-601 | As a payroll officer, I generate the SSS contribution collection list for a period. | P2 | Should |
| US-602 | As a payroll officer, I generate the PhilHealth RF-1 remittance report. | P2 | Should |
| US-603 | As a payroll officer, I generate the Pag-IBIG MCRF. | P2 | Should |
| US-604 | As a payroll officer, I generate the BIR alphalist for the year. | P2 | Should |
| US-605 | As an employee, I receive my BIR 2316 for the year. | P4 | Should |
| US-606 | As an HR admin, I see headcount by department, status and branch as of any date. | P1 | Should |
| US-607 | As an HR admin, I see turnover rate for a period with reasons for leaving. | P1 | Could |
| US-608 | As an HR admin, I see an attendance summary: tardiness, absences and OT hours per department. | P1 | Should |
| US-609 | As a payroll officer, I export a payroll journal for the accounting system. | P2 | Should |
| US-610 | As a sys admin, I export any report to CSV and XLSX. | P5 | Should |

## Self-service portal — [[Self-Service-Portal]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-701 | As an employee, I see a dashboard with my next pay date, leave balance and pending requests. | P4 | Should |
| US-702 | As an employee, I request a certificate of employment and track its status. | P4 | Could |
| US-703 | As a manager, I see one inbox of everything awaiting my approval. | P3 | Must |
| US-704 | As an employee, I use the portal comfortably on a phone. | P4 | Must |

## Notifications — [[Notifications]]

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-710 | As a manager, I am notified when a request needs my approval, with a deep link to it. | P3 | Should |
| US-711 | As an employee, I am notified when my leave is approved or rejected. | P4 | Should |
| US-712 | As an employee, I am notified when a payslip is released. | P4 | Should |
| US-713 | As an HR admin, I am notified when a probationary period or contract is about to end. | P1 | Should |
| US-714 | As a user, I control which notifications reach me by email. | All | Could |

## Recruitment, onboarding, offboarding, performance

| ID | Story | Persona | Priority |
|---|---|---|---|
| US-801 | As an HR admin, I track applicants through configurable pipeline stages. | P1 | Should (exists) |
| US-802 | As an HR admin, I convert a hired applicant into an employee record without re-typing data. | P1 | Must |
| US-803 | As an HR admin, I run an onboarding checklist for a new hire with owners and due dates. | P1 | Should |
| US-810 | As an HR admin, I initiate offboarding with a clearance checklist per department. | P1 | Should |
| US-811 | As a payroll officer, I am blocked from releasing final pay until clearance is complete or accountabilities are deducted. | P2 | Should |
| US-820 | As a manager, I conduct a performance review against a defined cycle and form. | P3 | Won't (MVP) |
| US-821 | As an employee, I set goals and see my review history. | P4 | Won't (MVP) |

## Coverage check

- Every **Must** story must map to at least one FR in a module note. Unmapped Musts are a planning bug — track in [[Open-Questions]].
- Every module note's FR table should cite the US it serves.

Related: [[Personas]] · [[Roadmap]] · [[Build-Plan]] · [[Business-Rules]] · [[Screens]]
