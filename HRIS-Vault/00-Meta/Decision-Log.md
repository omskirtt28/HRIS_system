---
title: Decision Log
type: moc
status: draft
tags: [meta, decision]
updated: 2026-10-24
---

# Decision Log

Index of Architecture Decision Records. One ADR per decision that is expensive to reverse. Use [[ADR-000-Template]] for new ones.

## Index

| ID | Decision | Status | Date | Supersedes |
|---|---|---|---|---|
| ADR-001 | [[ADR-001-Tech-Stack]] — language, framework, database | proposed | 2026-10-17 | — |
| ADR-002 | [[ADR-002-Multi-Tenancy]] — single company vs multi-company | proposed | 2026-10-17 | — |
| ADR-003 | [[ADR-003-Payroll-Engine-Approach]] — rules as config vs hardcoded | proposed | 2026-10-17 | — |

## Decisions recorded without an ADR

Smaller, reversible decisions John made during implementation. Not ADRs because migration 010 has a guarded down script.

| Date | Decision | Why | Where |
|---|---|---|---|
| 2026-10-24 | Ticket type access is per-type permissions (`ticket.file.<type>`) in `role_permissions`; no new table. | Reuses the existing role screen; HR changes access with no code. | [[Self-Service-Portal]], migration 010 |
| 2026-10-24 | Manpower Request routes HR_ONLY (was Manager/ADL then HR). Payroll Concern keeps its SLICE-15 chain. All other ticket types HR_ONLY. | Leads file Manpower, HR decides; Payroll Concern still needs the line approver. | BR-723 |
| 2026-10-24 | ~~ADL, SC, OIC, TC are created by HR on the screen, not seeded.~~ Superseded the same day by migration 011 (next row). ADL/TC/OIC/Manager are approver-capable (`payroll.approve_adl` / `payroll.approve_manager`); SC is a filer only. TC assumed to have the same access as ADL. | Keeps roles data, not code. | [[Auth-and-RBAC]] |
| 2026-10-24 | Migration 011 seeds ADL (Area Development Leader), SC (Sales Captain), OIC and TC (Team Coordinator) on the employee portal, only if the code does not exist yet. Each = MANAGER's base portal access (no HR/admin permission) + five staff types + Manpower + NetSuite + `tickets.request_self`. Approver: ADL, TC → `payroll.approve_adl`; OIC → `payroll.approve_manager`; SC → none. Existing COORDINATOR role (HR portal, Recruitment) left unchanged and is **not** TC. | John asked for the roles ready-made; HR can still edit them on the Roles screen. | [[Auth-and-RBAC]], migration 011 |
| 2026-10-24 | Only Super Admin creates or assigns HR/Admin-portal accounts; HRIS Admin manages the other roles' accounts. Super Admin cannot be assigned from the UI at all. | Closes privilege escalation through the user screen. | BR-122–BR-126 |
| 2026-10-24 | The `employee-*` page gate in `index.php` checks `roles.portal = 'employee'` (`Auth::requirePortal`), keeping Super Admin and HRIS Admin, instead of the list EMPLOYEE/MANAGER/SUPER_ADMIN/HRIS_ADMIN. | Roles seeded by migration 011 (ADL, SC, OIC, TC) and any role HR creates would otherwise be refused on the employee dashboard even though they can file tickets. Portal is already the role-level setting HR controls. | [[Auth-and-RBAC]] |
| 2026-10-24 | Ticket visibility above the employee is read-only and rule-based: branch leads (new effective-dated `branch_leads`, several per branch), the ADL via `directory_area_ids`, the Head Office Manager via the existing routing resolver, plus HR extra viewers; permission `ticket.view_team`, deny by default. No comments, attachments or notifications for viewers; full history by frozen `branch_id`; Incident Reports and drafts excluded. | Gives supervisors sight of their people's requests without touching HR_ONLY handling or approval rights. | BR-724..731, [[Self-Service-Portal]], migration 012 _Superseded in part 2026-10-24: leads and extra viewers replaced by automatic Sales Captain / OIC (see below)._ |
| 2026-10-24 | ADL coverage reuses `directory_area_ids` (no new coverage table); changes are audited. Accepted risk: a field edited from Employee 201 now also controls ticket visibility. | John's decision; avoids a second place to keep ADL areas in step. | BR-729, [[Org-Structure]] |
| 2026-10-24 | Extra viewer (`ticket_viewer_overrides`) is additive: it adds one viewer for one employee and never replaces the branch lead, ADL or Manager. HR cannot hide an employee from their branch lead. | Simplest safe reading of "optional per-employee override"; confirm if replacement is wanted. | BR-730 _Superseded 2026-10-24: no extra viewers._ |
| 2026-10-24 | PMBSI: no special rule and no change. Redundancy audit decisions kept: four ADL definitions, four HO-vs-branch classifiers and three approver sources left as they are. | John's instruction. | [[Org-Structure]] |
| 2026-10-24 | Employee 201 shows resolved ticket viewers as a read-only line, reusing the viewer lookups; no new screen or table, editing stays on Ticket Viewers and Assigned areas for ADL. Standing workflow "test then apply" recorded in CLAUDE.md and Conventions: after Stage B, a verified backup, passing isolated tests and a clean up/rerun/down/up with guarded down mean the migration is applied to localhost without asking again; push, deploy, deleting data/roles, history purge or applying anything that fails a check still need John's explicit word. Tests that depended on live rows (ADL role edits, ADL accounts/areas) now build their own fixtures. | John's instruction. | BR-732, [[Org-Structure]], [[Conventions]] |
| 2026-10-24 | Ticket viewers are automatic: ADL by area, Sales Captain and OIC by branch and position, Head Office Manager. No manual lead, no exceptions; `branch_leads` and `ticket_viewer_overrides` kept (applied) but deprecated and never read. A branch without a usable Sales Captain is a visible PROBLEM, not a blocker. Employee 201 shows one "Who can view this employee's tickets" panel; the Ticket Viewers screen is a report only. | John's decision; removes a second place to keep leads in step with positions and accounts. | BR-724, BR-728, BR-730..733, migration 013 |
| 2026-10-24 | OIC Authorization is an HR_ONLY request type filed by ADL/SC; HR serves it and assigns the OIC role by hand; access is date-bound and the account returns to its previous role after the end date. Safe defaults chosen: only a plain Employee account (or none) can be nominated; two Sales Captains in one branch can both view but are flagged; the previous role is recorded when HR serves the request; expiry runs from the page-load hook and the scheduled task, not a daily claim. | John's decision; the defaults are the least access that satisfies it. | BR-734..736, [[Auth-and-RBAC]], migration 013 |

## Status meanings

| Status | Meaning |
|---|---|
| proposed | Written, awaiting John's decision. Do not build on it. |
| accepted | Decided. Code must conform. |
| rejected | Considered and declined; kept for the reasoning. |
| superseded | Replaced by a later ADR, named in the row above. |

## Decisions still needing an ADR

- [ ] ADR-004 — Attendance ingestion: live biometric device integration vs scheduled file import. See [[Attendance-and-DTR]].
- [ ] ADR-005 — Approval workflow engine: generic configurable workflow vs per-module hardcoded approval chains. See [[Leave-Management]].
- [ ] ADR-006 — Document storage: filesystem vs database blobs vs object storage, with retention. See [[Security-and-Compliance]].
- [ ] ADR-007 — Payslip delivery: portal only, email PDF, or both; and whether payslips are encrypted at rest. See [[Self-Service-Portal]].
- [ ] ADR-008 — Audit trail depth: which tables get row-level change history, and for how long. See [[Auth-and-RBAC]].
- [ ] ADR-009 — Payroll reversal model: void-and-reissue vs adjusting entries in the next period. See [[Payroll]].

> [!note] When to write an ADR
> Write one if reversing the choice later would mean a data migration, a rewrite of more than one module, or a conversation with the client. Otherwise just decide it in the module note.

Related: [[Start-Here]] · [[Conventions]] · [[Architecture]] · [[Open-Questions]]
