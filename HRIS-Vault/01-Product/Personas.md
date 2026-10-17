---
title: Personas
type: plan
status: draft
tags: [product, personas]
updated: 2026-10-17
---

# Personas

Five personas. Each maps to a role in [[Auth-and-RBAC]] and to screens in [[Screens]]. Written as the actual humans in a PH company, not as abstractions.

## P1 — HR Admin ("Ate Marites, HR Officer")

- **Context:** Owns the 201 files, onboarding, leave administration and employee data accuracy. Lives in the system all day. Moderate computer skill, very high domain skill — she knows the rules better than the system will at first.
- **Primary jobs:** Create and maintain employee records, process leave filings, manage the holiday calendar, chase incomplete government IDs, produce HR reports.
- **Success looks like:** Never typing the same employee detail twice; seeing at a glance who is missing a PhilHealth number or an expiring contract.
- **Pain if we get it wrong:** Forced to maintain a shadow spreadsheet because the system cannot express a real-world exception.
- **Permissions:** Full read/write on [[Employee-Records]], [[Leave-Management]], [[Org-Structure]], [[Recruitment-and-Onboarding]]. Read-only on [[Payroll]] amounts.
- **Key screens:** SCR-200 employee list, SCR-201 201 file, SCR-410 leave queue, SCR-120 holiday calendar.

## P2 — Payroll Officer ("Kuya Dennis, Payroll")

- **Context:** Runs the cut-off. Highly numerate, deeply suspicious of any number he cannot trace. Will reconcile the system's output against his own spreadsheet for the first three runs — and he should.
- **Primary jobs:** Review and lock the DTR, enter adjustments, run payroll, review the register, release payslips, prepare remittances.
- **Success looks like:** Every peso traceable from a punch to a payslip line. A reversible run. A register that foots.
- **Pain if we get it wrong:** A black-box total. One unexplained figure destroys his trust in the whole system, permanently.
- **Needs above all:** Drill-down from net pay → earnings line → DTR day → raw punch. And an explicit "which rate table version was used" stamp.
- **Permissions:** Read/write on [[Payroll]], read/write on DTR corrections in [[Attendance-and-DTR]], read on [[Employee-Records]] including bank and compensation. Cannot edit his own record.
- **Key screens:** SCR-300 DTR review, SCR-500 payroll run wizard, SCR-510 payroll register, SCR-520 payslip preview.

## P3 — Manager / Approver ("Engr. Rene, Dept Head")

- **Context:** Uses the system a few minutes a week, usually on a phone, usually because someone is waiting on him. Will not log in to learn a UI.
- **Primary jobs:** Approve or reject leave filings, approve overtime, approve DTR corrections for his team, view team attendance.
- **Success looks like:** One screen showing everything awaiting his approval, approvable in two taps, with enough context not to have to ask.
- **Pain if we get it wrong:** Approvals pile up and HR approves on his behalf, destroying the audit trail.
- **Needs:** Email/notification deep links straight to the pending item. Delegation when he is on leave — see [[Notifications]].
- **Permissions:** Approve within his reporting scope only (see scope rules in [[Auth-and-RBAC]]). Read team attendance and leave; no access to pay amounts.
- **Key screens:** SCR-700 approvals inbox, SCR-310 team attendance, SCR-420 team leave calendar.

## P4 — Rank-and-file Employee ("Employee, 1 of 300")

- **Context:** Mobile-first, low patience, often on data. May be a field or site-based employee who rarely visits the office. May share a device.
- **Primary jobs:** View payslip, check leave balance, file a leave, check own DTR, dispute a missing punch, update contact details and dependents.
- **Success looks like:** Payslip visible the day pay lands, without asking HR. Leave balance that matches what HR says.
- **Pain if we get it wrong:** Falls back to chatting HR, which is the status quo we are replacing.
- **Needs:** Honest leave balance (available vs pending), clear reason text on a rejection, DTR visible *before* the cut-off closes so disputes happen in time.
- **Permissions:** Own record only. Can request changes to sensitive fields rather than edit them directly.
- **Key screens:** SCR-710 my payslips, SCR-720 my DTR, SCR-730 file leave, SCR-740 my 201.

## P5 — System Admin ("John / IT")

- **Context:** That is you. Configures the system, fixes the data nobody else can, and is the only one who can hurt the audit trail.
- **Primary jobs:** Manage users and roles, configure statutory rate tables and their effectivity dates, manage the org structure, read the audit log, perform data corrections.
- **Success looks like:** Changing a PhilHealth rate is a dated config row, not a code deploy.
- **Pain if we get it wrong:** Statutory rates live in code and every annual circular becomes a release.
- **Needs:** A rate-table editor with effectivity dating, an audit log viewer, and a loud warning before any destructive action.
- **Permissions:** Everything, but **all admin actions are audited and admin cannot delete audit rows**. See [[Security-and-Compliance]].
- **Key screens:** SCR-100 users and roles, SCR-110 rate tables, SCR-130 audit log.

## Permission summary

Authoritative matrix lives in [[Auth-and-RBAC]]; this is the orientation version.

| Capability | HR Admin | Payroll | Manager | Employee | Sys Admin |
|---|---|---|---|---|---|
| View any 201 file | Yes | Yes (limited) | Team only | Own only | Yes |
| Edit 201 file | Yes | No | No | Request only | Yes |
| View pay amounts | No | Yes | No | Own only | Yes |
| Run payroll | No | Yes | No | No | Yes |
| Approve leave | Override | No | Team | No | Yes |
| Configure rate tables | No | No | No | No | Yes |
| Read audit log | No | No | No | No | Yes |

## Deliberate tension

> [!note] Separation of duties
> HR Admin edits people; Payroll Officer pays them. Neither should be able to do both, because *create a fake employee and pay them* must require two people. This is why the matrix above splits edit-201 from run-payroll even though it is less convenient. Carry it into [[Auth-and-RBAC]].

Related: [[User-Stories]] · [[Screens]] · [[Auth-and-RBAC]] · [[Self-Service-Portal]] · [[Vision-and-Scope]]
