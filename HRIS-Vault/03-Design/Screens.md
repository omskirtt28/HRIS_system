---
title: Screens
type: design
status: draft
tags: [design, screens, ui, mvp]
updated: 2026-10-17
---

# Screens

## Purpose

Inventory of every screen with a stable `SCR-###` ID, so modules, stories and the [[Build-Plan]] can point at the same thing. This is an inventory and a set of layout rules, not mockups. Number blocks follow the module ranges in [[Conventions]], except the 700s which are the employee/manager-facing portal.

## Surfaces

| Surface | Audience | Device | Shell |
|---|---|---|---|
| Admin console | HR admin, payroll officer, sys admin | Desktop first | Left nav by module, top bar with company/period context |
| Manager view | Department heads, supervisors | Desktop + phone | Same shell, nav filtered to team scope |
| Employee portal | All employees with an account | **Phone first** | Bottom tab bar: Home, Payslips, Time, Leave, Me |

One codebase, one design system, three nav configurations driven by permissions from [[Auth-and-RBAC]]. A nav item that the user lacks permission for is not rendered; the route is still denied server-side.

## Inventory

### Access and admin (100s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-001 | Login | everyone | [[Auth-and-RBAC]] | email, password, lockout message, forgot password link |
| SCR-100 | Users and roles | sys admin | [[Auth-and-RBAC]] | user table, role assignment, scope editor, active sessions with revoke |
| SCR-110 | Rate tables | payroll officer | [[Payroll]] | table_code list, effectivity timeline, `verified_at` badge, source circular field |
| SCR-120 | Holiday calendar | HR admin | [[Org-Structure]] | year grid, holiday type, per-branch overrides, copy-from-last-year |
| SCR-130 | Audit log | sys admin, DPO | [[Security-and-Compliance]] | filter by actor/entity/date, before/after diff, export |

### Employees (200s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-200 | Employee list | HR admin | [[Employee-Records]] | search, filters (branch, dept, status), completeness badge, New employee |
| SCR-201 | 201 file | HR admin | [[Employee-Records]] | tabs: Personal, Employment, Compensation, Government IDs, Dependents, Documents, History |
| SCR-205 | Change request review | HR admin | [[Employee-Records]] | old vs new side by side, approve/reject with reason |
| SCR-210 | Bulk import | HR admin | [[Employee-Records]] | template download, upload, row-level validation report, commit |

### Attendance and leave (300s, 400s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-300 | DTR review | payroll officer | [[Attendance-and-DTR]] | grid employee x date, status chips (OK, FLAGGED, CORRECTED, LOCKED), day drawer with raw punches |
| SCR-301 | Attendance import | payroll officer | [[Attendance-and-DTR]] | file upload, date range, preview counts, unmapped device IDs, commit/rollback batch |
| SCR-310 | Team attendance | manager | [[Attendance-and-DTR]] | scoped grid, late/absent highlights |
| SCR-410 | Leave queue | HR admin | [[Leave-Management]] | all requests, filters, override with reason |
| SCR-420 | Team leave calendar | manager | [[Leave-Management]] | month view, overlaps, balance on hover |
| SCR-430 | Leave year-end | HR admin | [[Leave-Management]] | dry-run result, carry-over and conversion preview, confirm |

### Payroll (500s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-500 | Payroll run wizard | payroll officer | [[Payroll]] | steps: select period, check DTR lock, include/exclude, compute, review, release |
| SCR-510 | Payroll register | payroll officer | [[Payroll]] | one row per employee, columns per earning/deduction, footed totals, drill-down to payslip and DTR |
| SCR-520 | Payslip preview | payroll officer | [[Payroll]] | exactly what the employee will see, release gate |

### Reports (600s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-600 | Report centre | payroll officer, HR | [[Reports-and-Analytics]] | report picker, parameters (tax month, period), generate, download history |

### Portal and approvals (700s)

| ID | Screen | Primary user | Module | Key elements |
|---|---|---|---|---|
| SCR-700 | Approvals inbox | manager, HR | [[Self-Service-Portal]] | one list: leave, OT, DTR corrections, change requests; approve/reject inline |
| SCR-701 | Delegation | manager | [[Self-Service-Portal]] | pick delegate and date range |
| SCR-710 | Portal home | employee | [[Self-Service-Portal]] | next pay date, last payslip, balances, pending items |
| SCR-711 | Payslip view | employee | [[Self-Service-Portal]] | one payslip, PDF download, marks `viewed_at` |
| SCR-712 | My leave balances | employee | [[Leave-Management]] | per type: entitled, used, pending, available |
| SCR-713 | My BIR 2316 | employee | [[Reports-and-Analytics]] | list by year, download, acknowledge |
| SCR-720 | My DTR | employee | [[Attendance-and-DTR]] | open cut-off, status per day, lock date visible |
| SCR-721 | Dispute DTR day | employee | [[Attendance-and-DTR]] | day, proposed times, reason, attachment |
| SCR-722 | File overtime | employee | [[Attendance-and-DTR]] | date, hours, reason |
| SCR-730 | File leave | employee | [[Leave-Management]] | type first, balance shown before dates, half-day option, attachment |
| SCR-731 | My leave requests | employee | [[Leave-Management]] | status, cancel request |
| SCR-740 | My 201 | employee | [[Self-Service-Portal]] | read view, inline edit of non-sensitive fields |
| SCR-741 | Sensitive field change | employee | [[Self-Service-Portal]] | new value, supporting document, goes to SCR-205 |
| SCR-742 | Document request | employee | [[Offboarding-and-Clearance]] | COE and similar, status tracking |

## As built: HR requests and ticket screens (750s, verified 2026-10-25)

Server-rendered PHP pages addressed as `?page=<id>`; the sidebar groups are built in `app/View.php`. A nav item is shown only if the permission passes; the page is still refused server-side. Hub: [[Employee-Tickets]]. UI rules: [[Design-System]].

| ID | Screen (nav label) | Page id | Gate | Module |
|---|---|---|---|---|
| SCR-750 | HR Concerns (my requests) | `hr-requests-my` | `tickets.request_self` or `ticket.file_for_others` | [[Employee-Tickets]] |
| SCR-751 | File an HR Request (types limited to `ticket.file.<type>`) | `hr-request-new` | same + per-type permission | [[Employee-Tickets]] |
| SCR-752 | Ticket page: header card, status stepper, HR response card, details, chat thread, collapsed History; HR form splits "Response to the employee" and "Internal note (HR only)" | `hr-request` | read gate (requester, HR, named approver, Accounting, viewer) | [[Employee-Tickets]] |
| SCR-753 | HR Request Inbox (Manager/ADL approvals) | `hr-request-inbox` | `payroll.approve_manager` or `payroll.approve_adl` | [[Employee-Tickets]] |
| SCR-754 | Team Requests (read-only) | `hr-request-team` | `ticket.view_team` | [[Employee-Tickets]] |
| SCR-755 | HR Request Queue: tabs with counts, search, filter chips, age colour, preview drawer, quick In Progress/Served/Cancel, bulk Served/Cancel (ATM, ID, Uniform), uniform size summary, CSV | `hr-service-requests` (+ `hr-request-preview`, `hr-service-requests-export`) | `tickets.review_hr`, non-employee portal | [[Employee-Tickets]] |
| SCR-756 | Payroll Concerns (HR) | `hr-payroll-concerns` | `tickets.review_hr` | [[Payroll]] |
| SCR-757 | Accounting Follow-up (completed NetSuite concerns) | `hr-accounting-requests` | `ticket.accounting.view` membership | [[Employee-Tickets]] |
| SCR-758 | Incident Feed (restricted) | `hr-incidents` | `ticket.sensitive.view` (unverified: exact gate is in `TicketRepository::listing` mode `feed`) | [[Employee-Tickets]] |
| SCR-759 | HR dashboard: "Needs your action today" strip, Company and Period filters, six KPI cards linking to the filtered queue, requests-by-type bars, pending-age chart, Requires attention panel | `hr-dashboard` | HR portal | [[Employee-Tickets]] |
| SCR-760 | Ticketing Report (+ CSV, one-page A4 print) | `hr-ticket-report`, `-export`, `-print` | `ticket.report.view` + HR portal | [[Employee-Tickets]] |
| SCR-761 | Ticket Approvers (Manager/ADL assignments, preview before save) | `hr-ticket-approvers` | `tickets.approvers.manage` | [[Employee-Tickets]] |
| SCR-762 | Ticket Viewers (read-only problem report) | `hr-ticket-viewers` | `tickets.approvers.manage` | [[Org-Structure]] |
| SCR-763 | Accounting Members | `hr-ticket-accounting-members` | `tickets.review_hr` | [[Employee-Tickets]] |
| SCR-764 | Printable HR Forms (SW / OFJI) | `hr-print-forms` | `tickets.review_hr` | [[Employee-Tickets]] |
| SCR-765 | COE Template (upload .docx, activate version, signatory settings) + COE preview/download | `hr-coe-template`, `hr-coe-preview`, `hr-coe-download`, `hr-coe-template-file` | `ticket.coe.template.manage` (template); preview HR; download requester + HR | [[Employee-Tickets]] |
| SCR-766 | Users / Roles & Permissions (per-type filing grants, ADL/SC/OIC/TC) | `admin-users`, `admin-roles` | users/roles permissions | [[Auth-and-RBAC]] |
| SCR-767 | Employee 201, Employment tab, panel "Who can view this employee's tickets" (read-only, red PROBLEM lines) | `hr-employee` | employee view | [[Org-Structure]] |

Sidebar: group **Requests** (all portals) holds the ticket screens; group **HR Request Setup** (third group for HR and Admin portals) holds Ticket Approvers, Ticket Viewers, Accounting Members, COE Template, Printable HR Forms. The older SCR-7xx rows above are the design target; where both exist, the page ids here are the built ones.

## Layout and interaction rules

1. **Money is right-aligned, tabular figures, two decimals, peso sign in headers not cells.** Totals row is sticky.
2. **Every derived number is drillable.** Net pay to earnings to DTR day to raw punches. This is exit criterion 4 in [[Roadmap]].
3. **Status is a chip with a stable code and a label**, never colour alone (accessibility; also works on a printed register).
4. **Destructive or irreversible actions** (release payroll, lock DTR, year-end) use a confirm dialog that restates the count and period: "Release 287 payslips for 16-31 Oct?".
5. **Approvals are two taps from a notification** (see [[Notifications]]): deep link opens SCR-700 filtered to the item.
6. **Empty states say what to do next**, e.g. "No DTR for this cut-off. Import attendance (SCR-301)".
7. **Forms validate on the server and echo field errors inline.** Client checks are convenience only.
8. **Phone breakpoints**: the 700-series must work at 360px with no horizontal scroll. Admin screens may require desktop; say so in a banner rather than rendering broken.
9. **Period context**: payroll and DTR screens show the active cut-off and its lock state in the top bar at all times.
10. **Language**: English UI for MVP; labels stored as keys so Filipino can be added later without touching templates.

## Navigation sketch

```mermaid
graph TD
  L["SCR-001 Login"] --> H{"Role"}
  H -->|Employee| P["SCR-710 Portal home"]
  H -->|Manager| I["SCR-700 Approvals inbox"]
  H -->|HR / Payroll| E["SCR-200 Employee list"]
  P --> P1["SCR-711 Payslip"]
  P --> P2["SCR-720 My DTR"]
  P --> P3["SCR-730 File leave"]
  E --> E1["SCR-201 201 file"]
  E --> D["SCR-300 DTR review"]
  D --> R["SCR-500 Run wizard"]
  R --> G["SCR-510 Register"]
  G --> S["SCR-520 Payslip preview"]
```

## Open Questions

- [ ] Is a Filipino-language UI required at launch, or only for payslips? (Q10 in [[Open-Questions]])
- [ ] Do field employees without accounts need a kiosk mode on a shared device? (Q15)

## Related

[[Self-Service-Portal]] · [[Auth-and-RBAC]] · [[User-Stories]] · [[Personas]] · [[API-Surface]] · [[Build-Plan]] · [[Architecture]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]
