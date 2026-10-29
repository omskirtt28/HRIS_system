---
title: Employee Tickets
type: module
status: built
tags: [module, hris, tickets, hub]
updated: 2026-10-25
---

# Employee Tickets

## 2026-10-27 approved dashboard and official IR patch

Follow-up submit fix (John): Others nature specification optional, blank/short allowed, max5000; no200-character requirement/cap. Supporting Others specification max5000 with its conditional requirement retained. Backend/UI match; both PHP lint pass, runtime acceptance by John. BR-785 amended.

[[2026-10-27-dashboard-incident-template]] implements BR-784..788: dashboard action strip removal, focused setup-warning links, handler status affordance and exact-template official Incident Report. Form answers/filer/master hash/version are frozen in existing payload; required DOCX generation is transactional, PDF conversion optional with handler retry. Only reporter/eligible handler receive official-document access; participant conversation access and generic attachment limits/types stay as before. Free-text document names grant no access; no historical document backfill or category rewrite. Case shows document before conversation, rail unchanged. No new table/migration. Syntax/static review only; converter/rendered fidelity and runtime acceptance unverified. Period picker/Download Report are a separate patch.

## Purpose

Hub note for the HR ticket engine ("HR Concerns" / "HR Requests" in the app): one generic, audited way for an employee or a branch lead to file a request, have it routed, and have HR handle it. Everything else about tickets hangs off this note. Built in Slice 15 and its follow-ups (migrations 001-016, 2026-10-20 to 2026-10-25), live on localhost `pmbsi_hris`; not pushed or deployed.

## Request types

| Type (code) | Who files | Route | Detail table | Notes |
|---|---|---|---|---|
| Payroll Concern (`PAYROLL_CONCERN`) | everyone | Manager (Head Office) or ADL (branch), then HR; HR-only when late or no cutoff | `payroll_concerns` | BR-766, [[Payroll]] |
| ATM (`ATM_REQUEST`), ID (`ID_REQUEST`) | everyone | HR_ONLY | none | bulk-serviceable |
| Uniform (`UNIFORM_REQUEST`) | everyone | HR_ONLY | `uniform_requests` | size summary per branch |
| Incident Report (`INCIDENT_REPORT`) | everyone | no approval steps; HR sets the case status (SUBMITTED to CLOSED, DISMISSED) | `incident_details`, `incident_participants` | confidential case with participants and a thread, BR-769..776; still excluded from the queue, report and CSV (BR-744, BR-761) |
| Certificate of Employment (`COE_REQUEST`) | self | HR_ONLY | `coe_*` | .docx template, private file, BR-750..755 |
| Manpower (`MANPOWER_REQUEST`) | leads and above | HR_ONLY | `ticket_manpower_requests` | not the recruitment requisition, see [[Recruitment-and-Onboarding]] |
| NetSuite Concern (`NETSUITE_CONCERN`) | leads and above | HR_ONLY, Accounting follow-up | `ticket_netsuite_remarks` | Accounting members see it once Completed |
| Branch Transfer (`BRANCH_TRANSFER`) | HR/Admin for others | HR_ONLY | `branch_transfers`, `employee_placements` | [[Org-Structure]] |
| OIC Authorization (`OIC_AUTHORIZATION`) | ADL, SC | HR_ONLY | `oic_authorizations` | date-bound OIC role, BR-734..736 |

## Where things are written down

- Tables, columns, migrations, permissions and roles: [[Data-Model]] ("As built: HR ticket engine tables").
- Engine design, routing profiles (`HR_ONLY`, Manager then HR, ADL then HR), viewer resolution, permission model, test-then-apply: [[Architecture]].
- Rules: [[Business-Rules]] BR-720 to BR-767, the access matrix and privacy table; filing matrix and requirements in [[Self-Service-Portal]].
- Screens and page ids: [[Screens]] (SCR-750..767). Handlers and routes: [[API-Surface]].
- Roles ADL, SC, OIC, TC, per-type grants, Users/Roles screens: [[Auth-and-RBAC]].
- Branch/area coverage, derived viewers, Employee 201 panel, Ticket Viewers report: [[Org-Structure]], [[Employee-Records]].
- Bell and email notices (never carry private text): [[Notifications]].
- Reports: Ticketing Report and HR dashboard in [[Reports-and-Analytics]] terms; UI conventions in [[Design-System]].
- Decisions: [[Decision-Log]]. Open points: [[Open-Questions]]. Build history: [[Progress]], [[Slice-15-Step-1-Results]], [[Slice-15-Step-2-Results]], [[Slice-15-Stage-B-Results]]. Repo `CHANGELOG.md` is the plain-English changelog.

## Flow in one paragraph

An employee (or a lead filing for someone) opens File an HR Request and sees only the types their role may file (BR-720/721). Submit freezes the company/branch snapshot and the approval chain (BR-766 decides the Payroll Concern route). HR works the HR Request Queue (tabs, quick and bulk actions for ATM, ID, Uniform), writes a **response** the requester sees and an optional **internal note** only HR sees (BR-767), and marks it Served. Read-only viewers (ADL by area, Sales Captain and OIC by branch, Head Office Manager) follow the request in Team Requests without acting on it. HR keeps setup screens (Ticket Approvers, Ticket Viewers problem report, Accounting Members, COE Template, Printable HR Forms) and the Ticketing Report.

## Verified state (2026-10-25)

10 ticket types active, 3 routing profiles, 118 tables, migrations 001-017 applied on localhost (017 = incident cases). Tests live under `tests/` (core, services, Payroll Concern, assignments, viewers, OIC, COE, queue/bulk, dashboard, report, HR response, file access, lead roles, portal gate, legacy regression) and run only against an isolated database copy; `ticket_migration_roundtrips` and the older browser suites were not re-run in the last patches. The COE suite has one known order-dependent check (seed equals ATM grants).

## UI redesign (plan [[2026-10-25-ticketing-ui-ux-redesign]])

P1 Foundation done 2026-10-25: shared components and date/label helpers ([[Design-System]]), stepper shows "skipped" when a Served request skipped In Progress. P2-P5 pending. Lesson: the approval timeline must not show step remarks or the acting HR name to view-only users (caught by `ticket_hr_response_test`).

## Incident cases (plan [[2026-10-25-incident-report-module]])

Phase I1 (data, rules, access, status and people services, notifications, tests) done 2026-10-25 with migration 017. Phase I2 (employee UI) done 2026-10-25, no migration: sidebar group Incident Reports (My Incidents, File an Incident, HR: Incident Cases), `incident-mine`, `incident-new`, `incident-people`, `core_incident_create`, six categories, Incident Report removed from File an HR Request and My HR Requests, old links redirected (BR-777 to BR-779, SCR-768/769). Phase I3 (Case page) done 2026-10-25, no migration: conversation, HR update and internal-note cards, people rail, summary, attachments, timeline, sticky composer, handler panel (status with remarks, include or remove people, internal note, reopen) and reporter withdraw (BR-780, BR-781, SCR-770). I3 leftovers patched 2026-10-26 (dark-mode chip contrast, one-query `peopleOnCase`). I4 HR Incident Cases pending. Rules BR-769 to BR-781; fields and participants in [[Data-Model]]. Open: Q56 to Q60.

## Open Questions

- [ ] ADL holds `tickets.approvers.manage` and `ticket.accounting.view` live; neither is in migration 011. Intended? See [[Open-Questions]].
- [x] ~~No live role holds `ticket.sensitive.view`; who should read the Incident feed?~~ Closed by Incident phase I1 (2026-10-25): readers are the reporter, handling HR, included participants and reporter-picked CCs (BR-769); `ticket.sensitive.view` no longer opens a case. See [[Open-Questions]] Q51.
- [ ] Payroll cutoff calendar statuses are stale (cutoff 1 OPEN after it ended, cutoff 47 UPCOMING after it started); not changed. [[Payroll]]

## Related

[[Self-Service-Portal]] · [[Auth-and-RBAC]] · [[Org-Structure]] · [[Employee-Records]] · [[Notifications]] · [[Payroll]] · [[Recruitment-and-Onboarding]] · [[Reports-and-Analytics]] · [[Data-Model]] · [[Architecture]] · [[Business-Rules]] · [[Screens]] · [[API-Surface]] · [[Design-System]] · [[Progress]]

**I4 (2026-10-26):** `hr-incidents` is the Incident Cases queue (tabs, filters, aging; BR-782). `TicketIncident::OPEN_QUEUE`, `queueFilters`, `TicketRepository::incidentQueueCounts`; view `incident_queue_page` in `IncidentCaseViews.php`.

## 2026-10-29 - Incident case page cleanup
Compact panel update: status action remains visible; optional status note and separate internal note are collapsible and labelled HR-only. People, summary, attachments and timeline expand on demand; participant and attachment counts and latest visible event are shown collapsed. Static review only; rendered and live behavior unverified.

HR actions = status form (always visible) + internal note; "Include someone" UI removed (`core_incident_participant_add` and `incident-people` remain with no UI caller). Composer is a normal card. Original report is inline: PDF iframe if a PDF exists, else read-only sheet of `official_incident` answers (reporter + eligible HR only); Download (.docx)/Open PDF in the card header; handler-only "Create PDF copy". See Q65, Q66.
