---
title: Org Structure
type: module
status: draft
tags: [module, hris, mvp]
updated: 2026-10-24
---

# Org Structure

## Purpose

The master data every other module points at: the company, its branches, departments, positions, reporting lines, cost centers and the holiday calendar. It is small, boring and load-bearing. Get it wrong and approvals route to the wrong person, payroll applies the wrong holiday, and cost reports are meaningless.

Two non-obvious responsibilities live here:

- **The holiday calendar.** It looks like reference data but it is a direct payroll input: the day type of a date determines the pay multiplier. Local holidays differ per city, so the calendar is per-branch, not global.
- **Reporting lines as history.** Who approved what, last March, depended on who the manager was last March. A single `manager_id` column that gets overwritten destroys that.

## Scope

### In scope

- Company profile: legal name, trade name, TIN, employer registration numbers, registered address
- Branches / work locations, each with a city for local holidays and a timezone assumption
- Departments, optionally nested
- Positions / job titles with level and salary grade reference
- Reporting lines, derived from the dated placement in [[Employee-Records]]
- Cost centers and their mapping to departments or branches
- Holiday calendar: regular holidays, special non-working days, local holidays, company-declared non-working days
- Shift and schedule templates owned jointly with [[Attendance-and-DTR]]
- Org chart visualisation and orphan detection

### Out of scope

- The employees themselves → [[Employee-Records]]
- Who can see which branch → scope rules in [[Auth-and-RBAC]]
- Shift assignment to a specific employee → [[Attendance-and-DTR]]
- Salary amounts → [[Employee-Records]] compensation history
- Client companies in an agency model → depends on [[ADR-002-Multi-Tenancy]]

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `companies` | The employer. One row unless ADR-002 says otherwise. | `id`, `legal_name`, `trade_name`, `tin`, `sss_employer_no`, `philhealth_employer_no`, `pagibig_employer_no`, `address`, `logo_path` |
| `branches` | Physical location / work site. | `id`, `company_id`, `code`, `name`, `city`, `province`, `is_head_office`, `active` |
| `departments` | Organisational unit. | `id`, `company_id`, `code`, `name`, `parent_department_id`, `cost_center_id`, `head_employee_id`, `active` |
| `positions` | Job title master. | `id`, `code`, `title`, `level`, `salary_grade`, `is_managerial`, `default_ot_exempt`, `active` |
| `cost_centers` | Accounting dimension for payroll charging. | `id`, `code`, `name`, `gl_account_hint`, `active` |
| `holiday_calendars` | A named calendar; one national plus local ones. | `id`, `name`, `scope` (`NATIONAL`/`BRANCH`/`COMPANY`), `branch_id` |
| `holidays` | A dated entry with its day type. | `id`, `calendar_id`, `holiday_date`, `name`, `day_type` (`REGULAR_HOLIDAY`/`SPECIAL_NON_WORKING`/`COMPANY_NON_WORKING`), `is_recurring_annually` |
| `shift_templates` | Reusable shift definition. | `id`, `code`, `name`, `start_time`, `end_time`, `break_minutes`, `is_night_shift`, `crosses_midnight`, `required_hours` |
| `work_schedules` | A weekly pattern of shift templates plus rest days. | `id`, `name`, `pattern_json`, `rest_days` |

## Core Flows

### Flow 1 — Initial org setup

1. Sys admin enters the company profile including all employer registration numbers, which [[Reports-and-Analytics]] needs for remittance headers.
2. Creates branches, each with a city — the city drives local holiday applicability.
3. Creates departments, assigning a parent for nesting and a cost center.
4. Creates positions, marking which are managerial and what their default OT exemption is.
5. Creates shift templates and work schedules.
6. Loads the holiday calendar for the current year.

### Flow 2 — Load the annual holiday calendar

1. HR admin opens SCR-120 at the start of the year.
2. Enters each holiday from the official proclamation with its date, name and day type.
3. Recurring holidays may be carried forward from the prior year as drafts, but each must be confirmed — dates of several holidays move annually by proclamation.
4. Local holidays are attached to a branch calendar, not the national one.
5. On save, the system warns if any date in a future payroll cut-off is still unconfirmed.

> [!warning] NEEDS VERIFICATION
> Which dates are regular holidays versus special non-working days is fixed annually by Presidential Proclamation, and some observances shift. The system must never ship a hardcoded holiday list. Load from the current proclamation each year and record the proclamation number on the calendar.

### Flow 3 — Resolve day type for a date and employee

Called by [[Attendance-and-DTR]] and [[Payroll]] for every DTR day.

1. Determine the employee's branch as of that date, from the dated placement.
2. Look for a holiday on that date in the branch calendar; if none, fall back to the national calendar.
3. If a holiday exists, its `day_type` applies.
4. Independently determine whether the date is the employee's rest day from their work schedule.
5. Return the combined day type — e.g. *regular holiday falling on a rest day*, which carries its own multiplier. See BR-304 and BR-310.

### Flow 4 — Reorganise a department

1. HR admin edits or merges a department.
2. System lists affected employees and pending approvals routed through it.
3. Changes to employee placement create *new* dated placement rows; existing rows are closed, not edited.
4. Past payroll runs continue to report against the placement in force at the time. The department row is deactivated rather than deleted.

### Flow 5 — Orphan and integrity check

1. A dashboard panel lists: employees with no manager, employees whose manager is separated, departments with no head, departments with no cost center, and branches with no holiday calendar.
2. Each is a link to the fix. This check is cheap and catches most routing bugs before they reach an approver.

## Business Rules

- **BR-101** — Department and position codes are unique within a company and immutable once referenced by an employee placement.
- **BR-102** — A department's `parent_department_id` must not create a cycle. Validate on save.
- **BR-103** — Reporting lines must not create a cycle; an employee cannot be their own ancestor manager.
- **BR-104** — Master data is deactivated, never deleted, if referenced anywhere.
- **BR-105** — A holiday date may appear only once per calendar. A branch calendar entry overrides the national entry for the same date.
- **BR-106** — `position.default_ot_exempt` seeds the employee's exemption flag at hire but never overrides it afterwards. See BR-208.
- **BR-107** — A shift template crossing midnight attributes all its hours to the *shift start date*, not the calendar date of each punch. This single rule prevents a large class of night-shift payroll errors. See BR-309.
- **BR-108** — Every department must resolve to exactly one cost center for payroll charging, directly or by inheriting from its parent.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-101 | The system shall store a company profile including TIN and all employer registration numbers. (US-110) | Must |
| FR-102 | The system shall maintain branch, department and position masters with unique codes and active flags. (US-110) | Must |
| FR-103 | The system shall support nested departments without cycles. (BR-102) | Should |
| FR-104 | The system shall derive reporting lines from dated employee placements so historical approval authority is reconstructable. (US-111, US-115) | Must |
| FR-105 | The system shall maintain a holiday calendar per scope — national, branch and company — with a day type per entry. (US-112) | Must |
| FR-106 | The system shall resolve the applicable day type for a given employee and date, combining holiday and rest-day status. (BR-310) | Must |
| FR-107 | The system shall maintain cost centers and map every department to exactly one. (US-114, BR-108) | Should |
| FR-108 | The system shall maintain shift templates including break duration, required hours and a midnight-crossing flag. (US-309) | Must |
| FR-109 | The system shall maintain work schedules as weekly patterns with designated rest days. (US-303) | Must |
| FR-110 | The system shall display an org chart and list employees with no manager or a separated manager. (US-113) | Should |
| FR-111 | The system shall prevent deletion of master data referenced by any employee, placement or payroll run. (BR-104) | Must |
| FR-112 | The system shall warn when a future payroll cut-off contains dates with no confirmed holiday calendar entry. | Should |
| FR-113 | The system shall allow carrying the prior year's holidays forward as unconfirmed drafts requiring confirmation. | Could |
| FR-114 | The system shall record the source proclamation reference on a holiday calendar year. | Should |
| FR-115 | The system shall audit all master data changes with actor and timestamp. | Must |

## Edge Cases

| Case | Expected handling |
|---|---|
| Local holiday in one city only | Branch calendar entry. Employees in other branches are unaffected. Requires branch to be correct on the placement. |
| Holiday falls on a Sunday or rest day | Both conditions apply and the combined multiplier is higher than either alone. Must be modelled as a pair, not a single enum. See BR-310. |
| Two holidays on the same date | A "double holiday" has its own multiplier. Allow two entries for the same date and let the rule engine combine them — do not block it. |
| Holiday declared retroactively after payroll ran | Released run is immutable; the difference becomes an adjustment in the next period. Q9 in [[Open-Questions]]. |
| Department merged mid-year | Deactivate, do not delete. Historical rows still point at it and must still render a name. |
| Employee has two managers (matrix reporting) | Not supported in MVP. One approval manager per placement; a functional manager can be a non-routing field. Decide explicitly rather than drifting into it. |
| Manager separates with pending approvals | Orphan check surfaces it; pending items reassign to the next manager up the chain. See [[Notifications]]. |
| Employee works at a different branch from their assigned one | Attendance records the punch location; the branch on the placement governs holiday applicability. Flag the mismatch rather than guessing. |
| Position renamed | Keep the position row and its code; past payroll and 2316 forms may show the historical title, so a title snapshot on hire is worth storing. |
| New branch opened mid-year with no calendar | Orphan check flags it. Fall back to the national calendar with a visible warning rather than silently applying nothing. |

## As built: branch leads and ADL areas (2026-10-24)

Employee Directory groups (SW Retail, Ocampo's Retail, Head Office SW/Ocampo's, Urban Farm, Department Managers, ADL) are display groups. Two org facts now also decide who may read tickets (read-only, see [[Self-Service-Portal]], BR-724..731):

- **Branch leads are gone (migration 013).** The Sales Captain and the OIC of a retail branch are derived automatically from the employee records (same branch, position Sales Captain / Officer In Charge, active linked account). `branch_leads` and `ticket_viewer_overrides` are deprecated and never read. At a branch the SC and the TC are the same role in practice: no retail "Team Coordinator" position exists in the data; all Team Coordinators sit in Head Office departments, so a TC is not a viewer by position.
- **ADL areas** (`employees.directory_area_ids`, "Assigned areas for ADL" in Employee 201): an ADL covers every branch whose `area_id` is in the list, including branches added later. Ocampo's has one area (`OCAMPOS`, 6 branches), SW has eight (MM1, MM2, MM3, North, Province South, Metro South, Visayas, Mindanao). A retail branch with no area cannot be covered by any ADL.
- **Head Office (BR-737..739):** the ONLY viewer is the employee's direct Manager, the Reporting To on Employee 201, read live. A manager change moves all tickets, old ones included, to the new Manager. Viewing needs an active linked account with `ticket.view_team`, not the approve permission or an assignment. A Manager (role MANAGER) as filer goes straight to HR with no viewer above. Head Office has an empty branch code on purpose; routing must not depend on it. Verified bug 2026-10-24: Joshua Burwell (MIS / IT) reported to Charles Chan, but the resolver returned no Manager because the code was empty, so Team Requests stayed empty.
- **Data seen on localhost (2026-10-24):** 8 ADLs, 7 without an account, none with an area, 4 showing branch "Head Office"; 23 of 63 staffed retail branches have no Sales Captain; 3 active retail branches have no area. HR fixes these from the Ticket Viewers report.
- **Employee 201 (2026-10-24, revised 013):** the Employment tab shows the read-only panel "Who can view this employee's tickets" with the ADL, Sales Captain and OIC (or the Manager for Head Office, with red PROBLEM lines for no Reporting To, no account, or a role without `ticket.view_team`; "Not applicable" when the employee is a Manager), their employee numbers and account status, and red PROBLEM lines (no Sales Captain, Sales Captain without account, no area / no covering ADL, ADL recorded under Head Office, more than one Sales Captain). Display only; fix the position, account or Assigned areas for ADL instead.
- **Localhost update (2026-10-24, after migration 012 applied):** 8 ADLs, 1 with an account, 1 with an assigned area (Ocampos, the same ADL); no branch leads set yet. A Sales Captain also carries the Ocampos area, which is ignored because only an ADL's areas count (Q37).
- **Localhost after migration 013 (2026-10-24):** of 63 staffed retail branches, 1 has a Sales Captain with a usable account, 39 have a Sales Captain without a usable account, 23 have no Sales Captain on record, 1 has two; 6 are covered by an ADL with a usable account. 5 branches resolve only through the ADL, 57 resolve to nobody; 28 of 363 active employees have a viewer. Matched position names: `Sales Captain` (41 active employees) and `Officer In Charge` (1).

## Open Questions

- [ ] Are departments actually nested in this company, or flat? Nesting costs complexity.
- [ ] Do cost centers map to departments, branches, or a combination?
- [ ] In an agency model, is the client a first-class entity alongside branch? → [[ADR-002-Multi-Tenancy]]
- [ ] Are there site-based employees whose local holidays differ from the head office? (affects FR-105)
- [ ] Is matrix reporting real in this company? If yes, decide now, not after approvals break.
- [ ] Is a salary grade structure in use, or is pay set per employee?

## Related

[[Employee-Records]] · [[Attendance-and-DTR]] · [[Payroll]] · [[Auth-and-RBAC]] · [[Reports-and-Analytics]] · [[Data-Model]] · [[Business-Rules]] · [[Screens]] · [[ADR-002-Multi-Tenancy]] · [[PH-Labor-and-Payroll-Notes]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]

## 2026-10-29 - PMBSI departments (prepared)
- Organization Setup includes PMBSI Departments with name-first creation, global duplicate-name check, retire and reactivate. Existing departments classify as 2LOG after the prepared migration. No migration applied or runtime verified.

- 2026-10-29: John authorized and applied department work-company migration to localhost pmbsi_hris. Fresh private backup department_preapply_20261029_150231.sql restored to hris_department_verify_20261029_150231: 118 base-table checksums and schema dumps (excluding comments) matched. Post-apply: VARCHAR(10) NOT NULL DEFAULT 2LOG; 29 existing departments 2LOG; original department field mismatches 0; employee assignment mismatches 0 across 370 employees, employee checksum 1552809314 unchanged. PMBSI insert and guarded down were tested on the earlier isolated database only. Browser/application flow not tested; no commit or deployment.

- 2026-10-29: Organization Setup positions split by existing Employee Add/Edit eligibility into 2LOG, PMBSI and Needs classification. Company add validates name and optional active company department; shared records and eligibility unchanged, no schema or data migration. PHP lint passed; browser, application and DB behavior unverified.

- 2026-10-29: Organization Setup hides Needs classification and adds position default-department editing with company eligibility, active department validation, permission, CSRF and audit. Existing assignment and history data are unchanged; changing a default can affect later employee/recruitment validation. Human Resources Assistant removal remains blocked pending database reference verification; no DB access or write performed. PHP syntax checked; runtime and browser unverified.

- 2026-10-29: Organization Setup UI: compact actions and responsive rows, position department editor, Deactivate/Reactivate and Inactive labels. Existing actions and data unchanged; PHP lint passed, rendered and runtime behavior unverified.

## 2026-10-29 - Organization Setup side-by-side UI/UX

- Organization Setup UI/UX implemented: desktop cards stay paired (Branches & Sites keeps its wide card), records show name/status then metadata and a dedicated action row. All Add controls have visible labels, Position name spans its form, and the native department editor opens below the actions. Deactivate/Reactivate use distinct treatments; existing POST payloads, CSRF, manage gates and company eligibility are preserved.
