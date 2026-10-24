---
title: Self Service Portal
type: module
status: draft
tags: [module, hris, v1]
updated: 2026-10-24
---

# Self Service Portal

## Purpose

Give employees and managers a small, fast, phone-friendly surface that answers the handful of questions they actually have, so they stop asking HR. It is not a second HRIS — it is a deliberately narrow set of views over data the other modules own.

The design constraint that matters: **the employee portal is used by someone standing up, on mobile data, who will give it one attempt.** Six screens that load fast beat thirty that are complete.

Scope discipline: the portal owns **no data**. Every screen is a scoped read of another module, plus a small number of write actions that create requests for approval.

## Scope

### In scope

- Employee dashboard: next pay date, latest payslip, leave balances, pending requests
- Payslip list, view and download
- Own DTR for the current and recent cut-offs, with dispute filing
- Leave filing, balance view, request history, cancellation
- Own 201 view, with direct edit of non-sensitive fields and change requests for sensitive ones
- Document download: payslip, BIR 2316, certificate of employment
- Request a certificate of employment and track its status
- Manager approvals inbox across all request types
- Manager team views: attendance, leave calendar
- Mobile-first responsive layout
- Notification preferences

### Out of scope

- Any administrative function → the HR, payroll and admin portals
- Viewing other employees' data beyond a manager's scope → [[Auth-and-RBAC]]
- Payroll computation → [[Payroll]]
- Attendance punching from the portal → open question, not assumed
- A native mobile app → Phase 3 per [[Roadmap]]
- Chat with HR → out of scope; the portal's job is to make the question unnecessary

## What each persona can do

### Employee (P4)

| Job | Screen | Reads from | Writes |
|---|---|---|---|
| See my next pay date and last payslip | SCR-710 dashboard | [[Payroll]] | — |
| View and download a payslip | SCR-711 | [[Payroll]] | marks `viewed_at` |
| See my leave balances | SCR-712 | [[Leave-Management]] | — |
| File a leave | SCR-730 | [[Leave-Management]] | `leave_requests` |
| Cancel a filed leave | SCR-731 | [[Leave-Management]] | cancellation request |
| View my DTR for the open cut-off | SCR-720 | [[Attendance-and-DTR]] | — |
| Dispute a DTR day | SCR-721 | [[Attendance-and-DTR]] | correction request |
| File overtime | SCR-722 | [[Attendance-and-DTR]] | `overtime_requests` |
| View my 201 | SCR-740 | [[Employee-Records]] | direct edit of non-sensitive fields |
| Request a change to a sensitive field | SCR-741 | [[Employee-Records]] | `employee_change_requests` |
| Download my BIR 2316 | SCR-713 | [[Reports-and-Analytics]] | acknowledgement |
| Request a certificate of employment | SCR-742 | [[Offboarding-and-Clearance]] | document request |

### Manager (P3)

| Job | Screen | Notes |
|---|---|---|
| One inbox of everything awaiting me | SCR-700 | Leave, OT, DTR corrections, change requests — one list, not four. This is the single highest-value screen in the portal. |
| Approve or reject with remarks | SCR-700 | Two taps from a notification deep link |
| See team attendance for a date range | SCR-310 | Scoped to the reporting chain |
| See a team leave calendar | SCR-420 | Shows overlaps before approving |
| Delegate approvals while away | SCR-701 | Writes `approval_delegations` |

## Core Flows

### Flow 1 — Employee checks their payslip

1. Employee is notified that a payslip is released, with a deep link.
2. Logs in; if the session is fresh they land directly on the payslip.
3. The payslip shows the period, earnings lines, deduction lines, net pay, and YTD figures from the payslip's stored snapshot — not recomputed, so an old payslip always renders the same figures it was issued with.
4. Downloads a PDF. `viewed_at` is recorded, which answers "did they receive it".

### Flow 2 — Employee files a leave

1. SCR-730 shows available balance per leave type **before** dates are chosen.
2. Employee picks type, dates, day part and reason, attaching a document if required.
3. Client-side shows an immediate estimate of days consumed, excluding rest days and holidays; the server recomputes authoritatively on submit.
4. Validation failures are explained in plain language — "you have 2.5 days available and this request needs 3" — not as an error code.
5. On submit the employee sees the request in a pending list with the named approver.

### Flow 3 — Employee disputes a DTR day

1. SCR-720 shows the current cut-off's DTR with a clear status per day and a visible lock date.
2. A flagged or wrong day offers **Dispute this day**.
3. Employee states what actually happened and submits.
4. The request routes to their manager, then payroll. On approval a `dtr_corrections` row is created and the DTR re-derives.
5. After the lock date, the dispute path explains that the cut-off is closed and the correction will appear as an adjustment.

### Flow 4 — Manager clears their inbox

1. Notification deep-links to SCR-700, pre-filtered to pending items.
2. Each row shows employee, request type, dates, the one number that matters (remaining balance, OT hours, the corrected time), and any overlap warning.
3. Approve or reject inline with optional remarks; rejection requires remarks.
4. Bulk approve is available for same-type requests but never silently — it lists exactly what will be approved first.
5. Items the manager may not act on, such as their own request, are visibly excluded with the reason.

### Flow 5 — Change request on a sensitive field

1. Employee edits a sensitive field on SCR-740.
2. The UI states plainly that the change needs HR approval and shows the current value unchanged.
3. HR approves on SCR-205; only then does the record change.
4. Employee is notified of the outcome.

## Business Rules

- **BR-701** — Every portal read is scoped: an employee sees only their own record; a manager only their reporting chain as of the current date. Enforced server-side per BR-113.
- **BR-702** — The portal creates requests; it does not directly mutate payroll, DTR or sensitive employee data.
- **BR-703** — A payslip renders from its stored snapshot, never from a live recomputation.
- **BR-704** — A manager may not approve their own request. BR-119.
- **BR-705** — The portal shows the DTR lock date prominently, because the employee's window to dispute is bounded by it.
- **BR-706** — Leave balance shown to an employee is the same derived figure HR sees. No separate calculation path.
- **BR-707** — Documents are served through an authorisation check, never by a guessable URL. No direct file paths.
- **BR-708** — Sensitive documents such as payslips and 2316 forms are not emailed as unprotected attachments; the notification links to the portal.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-701 | The system shall provide an employee dashboard showing next pay date, latest payslip, leave balances and pending requests. (US-701) | Should |
| FR-702 | The system shall allow an employee to view and download their payslips. (US-509) | Must |
| FR-703 | The system shall render a payslip from its issued snapshot. (BR-703) | Must |
| FR-704 | The system shall record when an employee views a payslip. | Should |
| FR-705 | The system shall display an employee's leave balances split into earned, used, pending and available. (US-404) | Must |
| FR-706 | The system shall allow an employee to file, view and cancel leave requests. (US-401, US-411) | Must |
| FR-707 | The system shall allow an employee to view their own DTR for the current and recent cut-offs. (US-306) | Must |
| FR-708 | The system shall display the DTR lock date for the current cut-off. (BR-705) | Must |
| FR-709 | The system shall allow an employee to file a DTR correction request for a specific date. (US-306) | Must |
| FR-710 | The system shall allow an employee to file overtime. (US-307) | Should |
| FR-711 | The system shall allow an employee to view their 201 record and edit non-sensitive contact fields. (US-206) | Should |
| FR-712 | The system shall route employee edits of sensitive fields through an HR approval queue. (US-207, BR-702) | Should |
| FR-713 | The system shall allow an employee to download their BIR 2316 and record acknowledgement. (US-605) | Should |
| FR-714 | The system shall allow an employee to request a certificate of employment and track its status. (US-702) | Could |
| FR-715 | The system shall provide a manager with a single inbox of all pending approvals across request types. (US-703) | Must |
| FR-716 | The system shall allow inline approval or rejection with remarks, requiring remarks on rejection. (US-402) | Must |
| FR-717 | The system shall support bulk approval of same-type requests with an explicit confirmation listing the items. | Could |
| FR-718 | The system shall provide a manager with team attendance and team leave calendar views scoped to their reporting chain. (US-305, US-410) | Should |
| FR-719 | The system shall allow a manager to delegate approval authority for a date range. | Should |
| FR-720 | The system shall support deep links from notifications directly to the target item, authenticating first. (US-710) | Should |
| FR-721 | The system shall be usable on a mobile browser at common phone widths without horizontal scrolling. (US-704) | Must |
| FR-722 | The system shall serve all documents through an authorisation check rather than a direct file path. (BR-707) | Must |
| FR-723 | The system shall allow a user to set notification preferences. (US-714) | Could |
| FR-724 | The system shall explain validation failures in plain language, including the specific figures involved. | Should |
| FR-725 | The system shall exclude from a manager's inbox any item they are not permitted to act on, stating why. | Should |

## Edge Cases

| Case | Expected handling |
|---|---|
| Employee has no account | They do not use the portal; HR files on their behalf per US-406. This must not degrade the HR flow. |
| Employee logs in before any payslip exists | Dashboard shows a helpful empty state, not a blank panel or an error. |
| Payslip released while the employee is mid-session | The list refreshes on navigation; no stale "no payslips" message. |
| Manager is also an employee with their own pending leave | Visible in their list as *their* request, clearly not actionable by them. |
| Manager with no direct reports | Team views show an explicit empty state, not a permission error. |
| Employee on a very slow connection | Payslip list must paginate and the PDF must be an explicit download, never auto-fetched. |
| Shared device at a site office | Short idle timeout, explicit logout, no credential remembering. See [[Auth-and-RBAC]]. |
| Employee disputes a day after lock | Dispute path explains the cut-off is closed and offers the adjustment route instead of silently failing. |
| Employee cancels leave that already started | Blocked; explains that only future-dated leave is cancellable and directs them to HR. |
| Two devices, same account | Allowed; both sessions tracked and individually revocable. |
| Employee changes their own email | It is the login identifier, so it is a sensitive field requiring HR approval, not a direct edit. |
| Deep link clicked while logged out | Authenticate, then land on the target item — not on a generic dashboard. |

## As built: who may file which HR request (2026-10-24)

"File an HR Request" (Slice-15 ticket engine) lists only the types the user's role may file. Each type has a permission in `role_permissions`; no new table or column. Branch Transfer keeps using `ticket.file_for_others`.

| Type | Permission | Staff | ADL / SC / OIC / TC / Manager | HR / Admin |
|---|---|---|---|---|
| ATM Request | `ticket.file.atm` | ✓ | ✓ | ✓ |
| ID Request | `ticket.file.id` | ✓ | ✓ | ✓ |
| Incident Report | `ticket.file.incident` | ✓ | ✓ | ✓ |
| Uniform Request | `ticket.file.uniform` | ✓ | ✓ | ✓ |
| Payroll Concern | `ticket.file.payroll_concern` | ✓ | ✓ | ✓ |
| Manpower Request | `ticket.file.manpower` | — | ✓ | ✓ |
| NetSuite Concern | `ticket.file.netsuite` | — | ✓ | ✓ |
| Branch Transfer | `ticket.file_for_others` | — | — | ✓ |

Routing: everything is HR_ONLY except **Payroll Concern**, which keeps Manager (Head Office) or ADL (Branch) then HR, and late filings straight to HR (SLICE-15 unchanged). **Manpower** moved from Manager/ADL-then-HR to HR_ONLY: leads file it, HR sets the status. Manpower tickets already open keep their frozen steps. The Recruitment `manpower_requests` table is untouched.

Migration 010 kept today's access for existing roles: `EMPLOYEE` got the five staff types; `MANAGER`, `HR_USER` and `HRIS_ADMIN` got all seven. Super Admin passes every check implicitly. New roles get whatever HR ticks.

- **BR-720** — A user may file a ticket type only if their role holds that type's permission and a filing permission (`tickets.request_self` or `ticket.file_for_others`). Branch Transfer requires `ticket.file_for_others`.
- **BR-721** — Type access is enforced on the server at every filing path: the type list, create, edit/resubmit, and the new-request page's `?type=` selection. Hiding a type in the dropdown is not the control.
- **BR-722** — If a role loses a type's permission, the user's draft or returned ticket of that type can no longer be edited or resubmitted; it can still be cancelled.
- **BR-723** — Manpower Request routes HR_ONLY. Payroll Concern routing is unchanged.

| ID | Requirement | Priority |
|---|---|---|
| FR-726 | The system shall show in "File an HR Request" only the types the user's role may file, and reject any other type server-side. (BR-720, BR-721) | Must |
| FR-727 | The system shall let HR grant or remove a type for a role from Roles & Permissions, with no code change. | Must |

## Open Questions

- [ ] Do all employees get portal accounts? → Q15 in [[Open-Questions]]
- [ ] Should the portal allow punching in/out, and with what anti-abuse control?
- [ ] Is payslip PDF download required, or is an on-screen view enough? → Q10
- [ ] How many past cut-offs of DTR should an employee see?
- [ ] Is the certificate of employment request flow needed in v1?
- [ ] Should employees see employer contribution shares on the payslip?
- [ ] Is a PWA install prompt worth it for field employees?

## Related

[[Payroll]] · [[Attendance-and-DTR]] · [[Leave-Management]] · [[Employee-Records]] · [[Auth-and-RBAC]] · [[Notifications]] · [[Reports-and-Analytics]] · [[Offboarding-and-Clearance]] · [[Screens]] · [[API-Surface]] · [[Personas]] · [[Business-Rules]] · [[Security-and-Compliance]]
