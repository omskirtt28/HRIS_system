---
title: Business Rules
type: design
status: draft
tags: [design, business-rules, ph-statutory, needs-verification]
updated: 2026-10-26
---

# Business Rules

## Purpose

One index of every `BR-###` in the vault. Rules are **defined in the module note that owns them** (so the reasoning sits next to the flows); this note aggregates them so Claude Code can load the whole rulebook in one read, and so numbering collisions are visible. If a rule here disagrees with its module, the module wins and this index is stale: fix it.

## How to use

- Each rule becomes at least one unit test. Name tests `test_BR_###_<what>`. See [[Build-Plan]].
- Rules that compute money are pure functions in the domain layer ([[Architecture]]) and are tested with worked examples from [[PH-Labor-and-Payroll-Notes]].
- Never renumber (see [[Conventions]]). Withdrawn rules stay with status `WITHDRAWN`.

> [!warning] NEEDS VERIFICATION
> Any rule that mentions a rate, ceiling, bracket, multiplier or window is a **mechanism only**. The numbers live in effectivity-dated rate tables ([[ADR-003-Payroll-Engine-Approach]]) and must be confirmed against the issuing agency (DOLE, SSS, PhilHealth, HDMF, BIR) before use. Unverified tables must not be usable in a run (BR-514).

## Block map

| Block | Domain | Owning notes |
|---|---|---|
| 100-199 | Org and access | [[Org-Structure]], [[Auth-and-RBAC]] |
| 200-299 | Employee records | [[Employee-Records]] |
| 300-399 | Attendance | [[Attendance-and-DTR]] |
| 400-499 | Leave | [[Leave-Management]] |
| 500-599 | Payroll | [[Payroll]] |
| 600-699 | Reports | [[Reports-and-Analytics]] |
| 700-799 | Portal and notifications | [[Self-Service-Portal]], [[Notifications]] |
| 800-899 | Lifecycle | [[Recruitment-and-Onboarding]], [[Offboarding-and-Clearance]], [[Performance-Management]] |


## 100s - Org structure and access

| ID | Rule | Owner |
|---|---|---|
| BR-101 | Department and position codes are unique within a company and immutable once referenced by an employee placement. | [[Org-Structure]] |
| BR-102 | A department's `parent_department_id` must not create a cycle. Validate on save. | [[Org-Structure]] |
| BR-103 | Reporting lines must not create a cycle; an employee cannot be their own ancestor manager. | [[Org-Structure]] |
| BR-104 | Master data is deactivated, never deleted, if referenced anywhere. | [[Org-Structure]] |
| BR-105 | A holiday date may appear only once per calendar. A branch calendar entry overrides the national entry for the same date. | [[Org-Structure]] |
| BR-106 | `position.default_ot_exempt` seeds the employee's exemption flag at hire but never overrides it afterwards. See BR-208. | [[Org-Structure]] |
| BR-107 | A shift template crossing midnight attributes all its hours to the *shift start date*, not the calendar date of each punch. This single rule prevents a large class of night-shift payroll errors. See BR-309. | [[Org-Structure]] |
| BR-108 | Every department must resolve to exactly one cost center for payroll charging, directly or by inheriting from its parent. | [[Org-Structure]] |
| BR-110 | A user account and an employee record are distinct entities; the link is optional and one-to-one at most. | [[Auth-and-RBAC]] |
| BR-111 | Permissions attach to roles, never directly to users. No per-user permission grants. | [[Auth-and-RBAC]] |
| BR-112 | Scope attaches to users, because two users with the same role may have different scopes. | [[Auth-and-RBAC]] |
| BR-113 | Every query returning employee-related data must take a scope filter. An unscoped path is a defect. | [[Auth-and-RBAC]] |
| BR-114 | `employee.edit` and `payroll.run` must not be held by the same role. | [[Auth-and-RBAC]] |
| BR-115 | `ratetable.manage` and `ratetable.verify` must be held by different users for a given table version. | [[Auth-and-RBAC]] |
| BR-116 | The audit log is append-only. No role holds `audit.delete`. | [[Auth-and-RBAC]] |
| BR-117 | Passwords are stored only as a modern adaptive hash. Never reversible, never logged, never emailed. | [[Auth-and-RBAC]] |
| BR-118 | Sessions expire on an absolute and an idle timeout, and are revocable server-side. | [[Auth-and-RBAC]] |
| BR-119 | A user cannot approve, review or release anything concerning their own employee record. | [[Auth-and-RBAC]] |
| BR-120 | Separation of an employee disables their account and revokes their sessions on the effective separation date. | [[Auth-and-RBAC]] |
| BR-121 | Permission denials are logged with the attempted action, and the response reveals nothing about what exists. | [[Auth-and-RBAC]] |
| BR-122 | The Super Admin role cannot be assigned, edited, or have its users changed from the UI, by anyone. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-123 | Only a Super Admin may create, assign or change accounts whose role uses the HR or Admin portal. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-124 | A non-Super-Admin may assign a role only if they hold every permission that role has. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-125 | Nobody edits their own role's permissions; a non-Super-Admin can neither grant nor remove a permission they do not hold. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-126 | A user cannot change their own role or employee link; a role change signs the account out everywhere. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-127 | Role creation, role permission changes, user role changes and employee link/unlink are audited. (2026-10-24) | [[Auth-and-RBAC]] |

## 200s - Employee records

| ID | Rule | Owner |
|---|---|---|
| BR-201 | `employee_no` is unique and immutable once a payroll run has referenced the employee. | [[Employee-Records]] |
| BR-202 | An employee is *payroll-ready* only when: all four government IDs are present, an active compensation row covers the cut-off, and an active placement exists. Enforced at payroll inclusion, not at save. | [[Employee-Records]] |
| BR-203 | Compensation, status and placement are never updated in place. A change closes the current row and opens a new one; effectivity ranges must not overlap or gap. | [[Employee-Records]] |
| BR-204 | Resolving an employee's status, rate or placement for any computation is always *as of a date*, never "current". | [[Employee-Records]] |
| BR-205 | `separation_date` makes the employee ineligible for payroll runs whose cut-off starts after that date, but they remain fully visible for history and final pay. | [[Employee-Records]] |
| BR-206 | A fixed-term or project-based status requires an end date; regular must not have one. | [[Employee-Records]] |
| BR-207 | Government ID formats are validated structurally only. The system never asserts an ID is genuine. | [[Employee-Records]] |
| BR-208 | OT and night-differential exemption is an explicit boolean on the employee, defaulting to false, never inferred from position title. See Q7 in [[Open-Questions]]. | [[Employee-Records]] |
| BR-224 | Records referenced by payroll, DTR or leave history are never hard-deleted. Foreign keys restrict delete; removal is archive or anonymise only, per retention policy (see [[Security-and-Compliance]]). | [[Employee-Records]] |

## 300s - Attendance and DTR

| ID | Rule | Owner |
|---|---|---|
| BR-301 | Tardiness is the positive difference between actual in and scheduled in, after a configurable grace period. The grace period does not reduce the deduction once exceeded, unless policy says otherwise. | [[Attendance-and-DTR]] |
| BR-302 | Undertime is the positive difference between scheduled out and actual out, counted separately from tardiness. Both can occur on one day. | [[Attendance-and-DTR]] |
| BR-303 | Overtime is hours beyond the shift's required hours, capped at approved hours when OT approval is required. | [[Attendance-and-DTR]] |
| BR-304 | The OT multiplier depends on the day type: ordinary day, rest day, special non-working day, regular holiday, and combinations. | [[Attendance-and-DTR]] |
| BR-306 | Night differential applies to the overlap between hours actually worked and the statutory night window, and may coincide with overtime, in which case both premiums apply. | [[Attendance-and-DTR]] |
| BR-307 | Hours worked never includes unpaid break time. | [[Attendance-and-DTR]] |
| BR-308 | Raw punch logs are append-only. No update, no delete, except full reversal of an import batch that has not been locked. | [[Attendance-and-DTR]] |
| BR-309 | A midnight-crossing shift attributes all hours to the shift start date. | [[Attendance-and-DTR]] |
| BR-310 | Day type is a combination, not a single value: `{holiday_type, is_rest_day}`. Resolution order and multipliers are in [[Business-Rules]]. | [[Attendance-and-DTR]] |
| BR-311 | A locked DTR snapshot is immutable. Payroll must read the snapshot, never the live `dtr_days`. | [[Attendance-and-DTR]] |
| BR-312 | Approved paid leave fills scheduled hours as `leave_hours` and must not also count as `hours_worked`. Double-counting leave is a classic overpayment bug. | [[Attendance-and-DTR]] |

## 400s - Leave

| ID | Rule | Owner |
|---|---|---|
| BR-401 | Approved paid leave fills the employee's scheduled hours for that date as `leave_hours` in the DTR and must not be counted as hours worked. | [[Leave-Management]] |
| BR-402 | Balance is always computed as the sum of `leave_ledger` rows for the employee, type and leave year. No stored counter is authoritative. | [[Leave-Management]] |
| BR-403 | Available = earned − used − pending. A pending request reserves balance so an employee cannot over-file by filing twice. | [[Leave-Management]] |
| BR-404 | Only working days consume leave balance. Rest days and holidays inside a leave range are excluded. | [[Leave-Management]] |
| BR-405 | Leave may not be filed into a locked payroll cut-off. Such filings become next-period adjustments. | [[Leave-Management]] |
| BR-406 | Cancelling approved leave writes a reversing ledger row referencing the original; it never deletes or edits the original. | [[Leave-Management]] |
| BR-407 | Overlapping leave requests for the same employee and date are rejected, regardless of type. | [[Leave-Management]] |
| BR-408 | Accrual is idempotent per employee, type and accrual period. | [[Leave-Management]] |
| BR-409 | Cash conversion of unused convertible leave is paid at the employee's rate in effect on the conversion date. | [[Leave-Management]] |
| BR-410 | Leave with `is_paid = false` records the absence and instructs [[Payroll]] to deduct the equivalent, rather than paying it. | [[Leave-Management]] |
| BR-411 | A leave type with a gender restriction or an ID requirement is unfileable unless the employee record satisfies it, checked at filing time. | [[Leave-Management]] |
| BR-412 | An approval decision is valid only if the approver was in the employee's reporting chain, or a valid delegate, as of the filing date. | [[Leave-Management]] |

## 500s - Payroll

| ID | Rule | Owner |
|---|---|---|
| BR-501 | A payroll run may only be created against a locked DTR snapshot, and reads only that snapshot. | [[Payroll]] |
| BR-502 | A run snapshots employee compensation, placement and flags at creation; it never reads live employee data thereafter. | [[Payroll]] |
| BR-503 | A run records the exact version of every rate table it used, and recomputation uses those versions, not current ones. | [[Payroll]] |
| BR-504 | Daily and hourly rate derivation uses the employee's pay basis and configured annual factor. Never a hardcoded divisor. | [[Payroll]] |
| BR-505 | Gross pay is the sum of earning lines; net pay is gross less employee-share deductions. Employer share is never deducted from the employee. | [[Payroll]] |
| BR-506 | Taxable income excludes non-taxable earning types, employee statutory contributions, and de minimis amounts within their ceiling. Amounts above a de minimis ceiling are taxable. | [[Payroll]] |
| BR-507 | Net pay must not be negative. If deductions exceed gross, the excess carries forward as a balance rather than producing a negative payslip. | [[Payroll]] |
| BR-508 | A released run is immutable. Corrections are dated adjustments in a later run. | [[Payroll]] |
| BR-509 | The sum of per-period statutory deductions within a month must equal the monthly contribution due for remittance. | [[Payroll]] |
| BR-510 | Loan amortisation is capped at the outstanding balance; the final instalment is the remainder, not the standard amount. | [[Payroll]] |
| BR-511 | An employee appears at most once per run per run type. | [[Payroll]] |
| BR-512 | Monetary computation is performed at four decimal places and rounded to two only on presentation and payment; the rounding difference must be accounted for, not dropped. | [[Payroll]] |
| BR-513 | 13th month base includes only earning types flagged as included; OT and allowances are excluded by default. | [[Payroll]] |
| BR-514 | An unverified rate table version cannot be used by a payroll run. | [[Payroll]] |
| BR-515 | Statutory contributions are computed on the employee's monthly compensation, not on a single cut-off's gross, even when paid semi-monthly. | [[Payroll]] |

## 600s - Reports

| ID | Rule | Owner |
|---|---|---|
| BR-601 | Statutory reports read only from released payroll runs and must display the run IDs used. | [[Reports-and-Analytics]] |
| BR-602 | A statutory report's monthly total must reconcile to the sum of the corresponding payroll deduction lines for that tax month; a mismatch blocks recording the filing. | [[Reports-and-Analytics]] |
| BR-603 | Turnover rate uses a single declared formula, stated on the report: separations in the period divided by average headcount in the period. Any other formula must be a separate, differently named report. | [[Reports-and-Analytics]] |
| BR-604 | Headcount and any other point-in-time figure resolves status and placement as of the requested date, never from current values. | [[Reports-and-Analytics]] |
| BR-605 | Demographic and health-adjacent reports are aggregate only and suppress groups below a minimum size. | [[Reports-and-Analytics]] |
| BR-606 | Every report generation is logged with actor, parameters, row count and source run IDs. | [[Reports-and-Analytics]] |
| BR-607 | Export of PII is logged as a sensitive-data access event. | [[Reports-and-Analytics]] |
| BR-608 | A filing recorded in `statutory_filings` is immutable; a correction is a new filing row referencing the original. | [[Reports-and-Analytics]] |

## 700s - Portal and notifications

| ID | Rule | Owner |
|---|---|---|
| BR-701 | Every portal read is scoped: an employee sees only their own record; a manager only their reporting chain as of the current date. Enforced server-side per BR-113. | [[Self-Service-Portal]] |
| BR-702 | The portal creates requests; it does not directly mutate payroll, DTR or sensitive employee data. | [[Self-Service-Portal]] |
| BR-703 | A payslip renders from its stored snapshot, never from a live recomputation. | [[Self-Service-Portal]] |
| BR-704 | A manager may not approve their own request. BR-119. | [[Self-Service-Portal]] |
| BR-705 | The portal shows the DTR lock date prominently, because the employee's window to dispute is bounded by it. | [[Self-Service-Portal]] |
| BR-706 | Leave balance shown to an employee is the same derived figure HR sees. No separate calculation path. | [[Self-Service-Portal]] |
| BR-707 | Documents are served through an authorisation check, never by a guessable URL. No direct file paths. | [[Self-Service-Portal]] |
| BR-708 | Sensitive documents such as payslips and 2316 forms are not emailed as unprotected attachments; the notification links to the portal. | [[Self-Service-Portal]] |
| BR-710 | Events are emitted after the business transaction commits, never within it. | [[Notifications]] |
| BR-711 | Notification delivery failure must never fail or roll back the underlying business action. | [[Notifications]] |
| BR-712 | Mandatory events cannot be disabled by user preference. | [[Notifications]] |
| BR-713 | Notifications are deduplicated by `dedupe_key` within a configurable window. | [[Notifications]] |
| BR-714 | Sensitive documents are never attached to an email; the email links to the portal. BR-708. | [[Notifications]] |
| BR-715 | Notification bodies contain no salary figures, government ID numbers, medical details or leave reasons — only that an item exists and awaits action. | [[Notifications]] |
| BR-716 | Scheduled reminders are idempotent per subject and due date. | [[Notifications]] |
| BR-717 | Escalation never auto-approves and is capped at a configured level. | [[Notifications]] |
| BR-718 | Every delivery attempt is logged with its outcome; terminal failures are visible to an administrator. | [[Notifications]] |
| BR-719 | Recipients are resolved at send time from current reporting lines and delegations, so a stale cached recipient is never used. | [[Notifications]] |
| BR-720 | An HR request type may be filed only if the role holds that type's `ticket.file.<type>` permission plus a filing permission; Branch Transfer needs `ticket.file_for_others`. Access matrix in [[Self-Service-Portal]]. (2026-10-24) | [[Self-Service-Portal]] |
| BR-721 | Type access is enforced server-side at the type list, create, edit/resubmit and the new-request page, not only by hiding dropdown options. (2026-10-24) | [[Self-Service-Portal]] |
| BR-722 | A draft or returned ticket of a type the role can no longer file cannot be edited or resubmitted; it can still be cancelled. (2026-10-24) | [[Self-Service-Portal]] |
| BR-723 | Manpower Request routes HR_ONLY; Payroll Concern keeps Manager/ADL then HR. Other ticket types are HR_ONLY. (2026-10-24) | [[Self-Service-Portal]] |
| BR-724 | Read-only ticket viewers: for a retail employee the **ADL** covering the branch's area, the **Sales Captain** of the branch and the **OIC** of the branch; for a Head Office employee ONLY the employee's direct **Manager** (the Reporting To, BR-737). They may read the employee's HR requests (list, detail, ordinary comments, history). They cannot approve, return, reject, cancel, complete, edit, comment or attach, and receive no notifications. View access never implies approve permission. HR stays the only handler. (2026-10-24, revised by migration 013) | [[Self-Service-Portal]] |
| BR-725 | Viewer access needs the `ticket.view_team` permission (deny by default; seeded to ADL, SC, OIC, TC, MANAGER) and an active account linked to an active employee. (2026-10-24) | [[Self-Service-Portal]] |
| BR-726 | Incident Reports and drafts are never visible through viewer access. (2026-10-24) | [[Self-Service-Portal]] |
| BR-727 | Retail: viewers are resolved from the ticket's frozen `branch_id` (full history, not the employee's current branch). Head Office: the employee's direct Manager = Reporting To (`employees.manager_employee_id`), BR-737. Evaluated when read, never snapshotted; a changed Sales Captain, OIC or ADL gains or loses the existing tickets at once and routing steps are untouched. (2026-10-24) | [[Org-Structure]] |
| BR-728 | Sales Captain and OIC are **derived automatically, never assigned**: employees of the SAME branch whose position is Sales Captain (or Officer In Charge) with an active linked account holding `ticket.view_team`. A branch has one Sales Captain and may also have an OIC. An OIC may also be an employee on the OIC role holding a served OIC Authorization covering today (BR-735). Head Office has no Sales Captain or OIC. (2026-10-24, revised by migration 013) | [[Org-Structure]] |
| BR-729 | ADL coverage = branches whose `area_id` is in the ADL's `directory_area_ids`, only for an ADL (directory rule) with a linked active account; branches added to an area later are covered with no reassignment. Every change to `directory_area_ids` is audited (`CHANGE_ADL_AREAS`). (2026-10-24) | [[Org-Structure]] |
| BR-730 | No manual lead and no exceptions: `branch_leads` and `ticket_viewer_overrides` (migration 012) are DEPRECATED. The tables are kept but nothing reads them as access input, so existing rows cannot grant access; the schema comment says so. The Ticket Viewers screen has no assign form. (2026-10-24, migration 013) | [[Org-Structure]] |
| BR-731 | HR sees, by name, the PROBLEMS that leave a branch with only the ADL (or nobody): branch with no Sales Captain, Sales Captain without a usable account, more than one Sales Captain in a branch, retail branch without an area or without a covering ADL, ADL without account/area, retail ADL whose branch is Head Office; plus the employees with no resolvable viewer (Ticket Viewers screen, read-only). (2026-10-24, revised) | [[Org-Structure]] |
| BR-732 | The Employee 201 Employment tab shows one read-only panel "Who can view this employee's tickets": ADL, Sales Captain and OIC (or the Manager for Head Office), each with name, employee number and account status, plus red PROBLEM lines for the conditions in BR-731 that apply. It uses the same lookups as the access rule (`TicketViewerService::viewersFor`), never edits, and has no assign button. (2026-10-24, revised) | [[Org-Structure]] |
| BR-733 | A branch must always have a Sales Captain. If there is none, or the Sales Captain has no usable account, only the ADL can view and the branch is shown as a PROBLEM on Employee 201 and in the report. Filing is never blocked. (2026-10-24) | [[Org-Structure]] |
| BR-734 | OIC Authorization is a request type (`ticket.file.oic_authorization`, granted to ADL and SC only; HR/Admin portal accounts file for others through `ticket.file_for_others`). Fields: proposed employee, branch, reason, start date, end date (end on or after start, not in the past). The nominee must be an active retail employee in the filer's own branch (Sales Captain) or assigned areas (ADL); never the filer, never Head Office; the branch must be the nominee's current branch. HR_ONLY routing; HR sets the status. (2026-10-24) | [[Self-Service-Portal]] |
| BR-735 | OIC access is date-bound and automatic. After HR serves the request, HR creates or links the OIC user and gives the OIC role by hand in Users (nothing grants a role automatically). OIC viewer access for a role-OIC account exists only while a served authorization covers today for that branch, so it ends the day after the end date even before the role is reverted. `oic_authorizations.previous_role_id` records the account's role when HR served the request; `TicketOicAuthorization::expireDue()` (page-load hook, idempotent, audited `OIC_AUTHORIZATION_EXPIRED`, sessions revoked) returns the account to that role (Employee when none is usable) only if it is still on the OIC role. (2026-10-24) | [[Auth-and-RBAC]] |
| BR-737 | Head Office viewer = ONLY the employee's direct Manager, the "Reporting To" on Employee 201 (`employees.manager_employee_id`), read LIVE and never snapshotted: after a Manager change the new Manager sees the old tickets and the old Manager loses them. Needs an active linked account with `ticket.view_team` and an active employee record; it does not need `payroll.approve_manager` or an approver assignment (viewing is not approving). Replaces "as returned by the routing resolver". (2026-10-24) | [[Org-Structure]] |
| BR-738 | A Manager (the filer's own account holds the `MANAGER` role) has no viewer above them: their requests go straight to HR (Payroll Concern uses the HR-only profile; every other type is HR_ONLY already) and the Manager line on Employee 201 reads "Not applicable", not a problem. A Manager sees a direct report's requests like an ADL: Payroll Concern in the HR Request Inbox (Manager then HR, unchanged), every other type read-only in Team Requests, Incident Reports hidden, no comments, attachments, approve or notifications. (2026-10-24) | [[Self-Service-Portal]] |
| BR-739 | Head Office is stored with an EMPTY branch code (roster import). The routing resolver therefore requires only `branch_id`, never `branch_code`; an empty code must not read as "no Manager". (2026-10-24) | [[Auth-and-RBAC]] |
| BR-736 | Escalation guard: a request is blocked if the nominee already holds a higher or lead role: an account on any role other than Employee (including OIC, Team Coordinator, Manager, ADL, SC, HR, Admin, Super Admin) or the position Sales Captain, ADL or Officer In Charge. Checked at filing and again when HR serves. Expiry never touches a Super Admin, HR, admin or client account, never assigns a role from those portals, and keeps a permanent OIC (by position) as is. (2026-10-24) | [[Auth-and-RBAC]] |

## 800s - Lifecycle: recruitment, offboarding, performance

| ID | Rule | Owner |
|---|---|---|
| BR-801 | An applicant and an employee are separate entities. Conversion creates a new employee row and never mutates the applicant. | [[Recruitment-and-Onboarding]] |
| BR-802 | An application may only be converted from a terminal-success stage with an accepted offer. | [[Recruitment-and-Onboarding]] |
| BR-803 | Applicant personal data is retained only for the consented retention period, after which it is anonymised or deleted. See [[Security-and-Compliance]]. | [[Recruitment-and-Onboarding]] |
| BR-804 | A stage move to a terminal-fail stage requires a rejection reason. | [[Recruitment-and-Onboarding]] |
| BR-805 | An offer above the requisition's approved salary range requires an explicit override with an approver recorded. | [[Recruitment-and-Onboarding]] |
| BR-806 | Blocking onboarding tasks must be complete before the employee is payroll-ready, in addition to BR-202. | [[Recruitment-and-Onboarding]] |
| BR-807 | A requisition's filled count may not exceed its approved headcount without re-approval. | [[Recruitment-and-Onboarding]] |
| BR-808 | Interviewers see only the applicant data their round requires, not the full file. | [[Recruitment-and-Onboarding]] |
| BR-810 | An employee's record is archived on separation, never deleted. BR-224 still applies. | [[Offboarding-and-Clearance]] |
| BR-811 | Final pay may not be released while any clearance item is `PENDING`. | [[Offboarding-and-Clearance]] |
| BR-812 | Accountabilities are deducted from final pay unless explicitly waived by an authorised approver, with the waiver audited. | [[Offboarding-and-Clearance]] |
| BR-813 | A separation cannot complete while employees still report to the departing employee. | [[Offboarding-and-Clearance]] |
| BR-814 | Future-dated approved leave is cancelled on separation, restoring the leave balance. | [[Offboarding-and-Clearance]] |
| BR-815 | System access is revoked and all sessions terminated on the effective separation date, not later. | [[Offboarding-and-Clearance]] |
| BR-816 | An employee separated mid-cut-off is paid for that cut-off exactly once, in either the regular run or the final pay run. | [[Offboarding-and-Clearance]] |
| BR-817 | Rehire creates a new employee record; tenure, leave accrual and 13th month restart. | [[Offboarding-and-Clearance]] |
| BR-818 | Final pay net may not be negative; a shortfall becomes a receivable. | [[Offboarding-and-Clearance]] |
| BR-820 | Section and item weights within a template must total 100%. | [[Performance-Management]] |
| BR-821 | The reviewer is resolved from the reporting line as of the cycle start date, not the submission date. | [[Performance-Management]] |
| BR-822 | A review cannot be closed without employee acknowledgement, or an explicitly recorded refusal to acknowledge. | [[Performance-Management]] |
| BR-823 | Acknowledgement records receipt, not agreement. | [[Performance-Management]] |
| BR-824 | A calibrated score differing from the manager's score must carry a recorded reason. | [[Performance-Management]] |
| BR-825 | Goals must be marked agreed by both parties before the assessment phase opens. | [[Performance-Management]] |
| BR-826 | A probationary evaluation must reference the performance standards communicated at hire. | [[Performance-Management]] |
| BR-827 | A completed review is immutable; a correction is an appended addendum. | [[Performance-Management]] |
| BR-828 | An employee may not be their own reviewer; such cases escalate one level up. | [[Performance-Management]] |
| BR-829 | Review content is visible only to the employee, their reviewer, the reviewer's manager, and HR. | [[Performance-Management]] |

## Convention example

| ID | Rule | Owner |
|---|---|---|
| BR-012 | Night differential window is configuration (start and end time in a rate table), never a constant. Worked example for the convention only; the live rule is BR-306. | [[Attendance-and-DTR]] |

## Cross-cutting invariants

These are not numbered rules but every rule above must preserve them.

1. **Reproducibility**: a released payroll run recomputes identically from its snapshot and rate-table version set ([[Payroll]]).
2. **As-of resolution**: status, rate, placement, schedule are always resolved for a date (BR-204).
3. **Append-only history**: raw punches, leave ledger, audit log are never updated in place (BR-116, BR-308).
4. **Hours before pesos**: only [[Attendance-and-DTR]] derives hours; [[Payroll]] only prices them.
5. **Server-side truth**: no rule exists only in JavaScript.

## As built: HR queue bulk and quick actions (2026-10-25)

| ID | Rule |
|---|---|
| BR-740 | Bulk processing from the HR Request Queue covers only ATM, ID and Uniform requests that are Pending or In Progress, and only Served or Cancel. Payroll Concern, Branch Transfer, Manpower, NetSuite, OIC Authorization and Incident Reports are skipped with a stated reason. |
| BR-741 | Bulk and quick actions run one ticket at a time through `TicketService::act()` (never a raw UPDATE), so HR permission, version check, self-request block, events and notifications are identical to the detail page. The list sends the version it showed; a changed ticket is skipped. |
| BR-742 | Up to 100 tickets per bulk request; shared remarks up to 500 characters; each run writes one summary audit row (BULK_/QUICK_ plus the action). Non-HR requests are refused server-side before anything runs. |
| BR-743 | Age = days since filed, shown only for open requests: green up to 2 days, amber 3 to 5, red over 5. |
| BR-744 | Incident Reports never appear in the queue, tab counts, dashboard breakdowns or CSV. Their count and latest title show on the dashboard only for HR or `ticket.sensitive.view`. |

## As built: Certificate of Employment (2026-10-25)

| ID | Rule |
|---|---|
| BR-750 | A Certificate of Employment is filed by the employee for themselves, routes HR_ONLY and is never processed by bulk or quick actions; Served requires the HR preview first. |
| BR-751 | Served is refused unless HR opened the preview for the same values, salary choice and template in the last 30 minutes; the preview blocks on missing position, hire date, company, purpose or (when ticked) a salary rate. Nothing is invented. |
| BR-752 | Include salary is an HR decision made at Served, default no. `{{salary}}` is filled only when ticked and a salary rate effective on the issue date exists. |
| BR-753 | date_issued is the Served date. The generated .docx is stored once per ticket and never regenerated. |
| BR-754 | Only the requester and HR can see the COE ticket or download the file, through the ticket access check; viewers, ADL, Sales Captain, OIC, Manager and Accounting cannot. Every generation and download is audited (no values in the audit row). |
| BR-755 | Template upload: .docx only, real ZIP/Word structure, no macros or DOCTYPE/entities, up to 2 MB, all required placeholders and no unknown ones; versions are kept and audited. Values are written as XML text (escaped); placeholders typed into data are not substituted again. |

## As built: Ticketing Report (2026-10-25)

| ID | Rule |
|---|---|
| BR-760 | The Ticketing Report is open only to HR-portal roles with `ticket.report.view` (seeded to the roles that review HR requests). Non-HR roles are refused even if granted the permission. |
| BR-761 | Drafts and Incident Reports never enter request counts, lists or the CSV; Incident Reports appear only as a count. |
| BR-762 | Average time to Served = filed date to closed date, calendar days, over requests Served in the period. Pending-over-5-days uses the same age as the queue (PENDING_HR only). |
| BR-763 | Throughput is team totals per day (per week beyond 62 days); no per-person figures. |
| BR-764 | Uniform pieces per size per branch use the branch the uniform is for and leave out Cancelled/Rejected; the branch filter follows that same target for Uniform requests. |
| BR-765 | CSV: name and branch only, no free text, formula-leading cells neutralised, capped at 5000 rows, each export audited. |
| BR-766 | A Payroll Concern is always fileable. The cutoff for the concern date decides only the route: open cutoff = normal chain (Manager/ADL then HR); finalized, deadline passed, calendar CLOSED or not OPEN (e.g. UPCOMING), or no single cutoff for the date = HR-only, flagged late (`FINALIZED`, `DEADLINE_EXPIRED`, `CALENDAR_CLOSED`, `CALENDAR_NOT_OPEN`, `NO_CUTOFF`). No cutoff = `payroll_period_id` NULL. Finalized/locked payroll and attendance are never touched; HR still records a manual instruction for a later open cutoff. |
| BR-767 | Remarks written on a status change (In Progress, Served, Cancelled, Rejected, Returned) are the **response**: visible to the requester and to HR, shown on the ticket page, never to viewers, ADL/SC/OIC/Manager (beyond today), Accounting or other staff. HR may add an optional **internal note** (`ticket_hr_notes`, max 2000 characters): HR-only, never in events, comments, steps, notifications or exports; a forged note from a non-HR user is refused. Incident Reports show no requester-side response card. The requester is notified in the bell of the new status and that a response is waiting, without its text. | Self-Service-Portal | NEW |
| BR-768 | The File TA form and Check Attendance use one set of existing logs. When device statuses leave every slot ambiguous (1 punch, or an IN/OUT pair that fits several slots) the punches are placed by time in Time In, Break Out, Break In, Time Out (`AttendanceSlotDisplay`), display only. Slots with a log are shown and their inputs hidden; only the missing slots are asked. More than four punches place nothing. Submit re-reads the same context under the cutoff lock: a filled slot is never overwritten by a TA, and a TA with nothing missing is refused. Nothing is written to `attendance_daily`; `PayrollCalculator::assign()`, snapshots and finalized attendance are unchanged. | Attendance-and-DTR | NEW |
| BR-769 | **Incident case readers (supersedes the `ticket.sensitive.view` gate).** An Incident Report case is readable by: the reporter; HR reviewers (`tickets.review_hr`, HR portal) who are not named on the case; and participants with access (a CC the reporter picked, or anyone HR included). Viewers (ADL, Sales Captain, OIC, Manager), Accounting, `ticket.sensitive.view` holders and every other employee never read it, its thread, its files or its notifications. A draft is readable by its author only. One rule in `TicketIncident::readClause`, used by `TicketRepository::find()` and every list. (2026-10-25, I1) |
| BR-770 | **People on a case.** The reporter picks up to 20 people when filing (roles CC, Involved, Witness; never themselves, no repeats, active accounts only). A reporter-picked CC gets access; a picked Involved or Witness is recorded for HR but gets **no access** unless HR includes them. HR (the handling reviewer) may include anyone in any role, which always grants access, and may remove anyone (access ends at once, row deleted, audit event kept). The reporter cannot change people after filing. Schema CHECK: access without HR is possible only for a CC. Unconfirmed assumption, see Q57. (2026-10-25, I1) |
| BR-771 | **Case status flow, set by HR only.** SUBMITTED to UNDER_REVIEW to IN_PROGRESS to RESOLVED to CLOSED; DISMISSED from SUBMITTED, UNDER_REVIEW or IN_PROGRESS; RESOLVED and CLOSED reopen to IN_PROGRESS; CANCELLED only by the reporter (or the handler) while SUBMITTED. Remarks are required on every HR change (DISMISSED needs the reason, a reopen needs one too). RESOLVED may skip IN_PROGRESS from UNDER_REVIEW. CLOSED, DISMISSED and CANCELLED set `closed_at`; a reopen clears it. Version-checked, audited as ticket events. (2026-10-25, I1) |
| BR-772 | **Thread.** Messages from the reporter, participants with access and HR are visible to everyone who can read the case. HR remarks on a status change show as an "HR update" to every reader. An internal note (a STAFF_ONLY comment or the note saved with a status change, `ticket_hr_notes`) is visible to the handling HR reviewer only. Readers can post while the case is SUBMITTED, UNDER_REVIEW, IN_PROGRESS or RESOLVED; a closed, dismissed or cancelled case takes no new messages (the handler can still add an internal note). Messages are never edited or deleted. (2026-10-25, I1) |
| BR-773 | **Who sees whom.** Everyone who can read a case sees the names on it. Role labels are shown only for Reporter and CC; Involved and Witness labels, who has access, and people-change events (PARTICIPANT_ADDED, PARTICIPANT_REMOVED) are visible to the handling HR reviewer only. Whether the reporter's identity should be hidden from Involved people is open (Q58). (2026-10-25, I1) |
| BR-774 | **Notifications carry no private text.** Incident notifications are in-app only and say "An incident case you are part of was updated" with the ticket number: no title, narrative, place, remarks, notes or role. Recipients: reporter and participants with access; HR reviewers (not named on the case) for a newly published case and for messages from non-HR; a newly included person gets only "added"; a removed person gets nothing. (2026-10-25, I1) |
| BR-775 | **HR conflict.** An HR reviewer who is the reporter, or is named anywhere on the case, is not a handler: no status changes, no people changes, no internal notes, and no blanket HR read. They read the case only as reporter or if HR gave them access. (2026-10-25, I1) |
| BR-776 | **Case fields.** Each case has a title and "what happened" (in `payload_json`), and in `incident_details`: when (date and time, not in the future), where (up to 200 characters), an optional branch, and a category from a controlled list of six (Workplace conduct, Harassment or discrimination, Safety and health, Property or equipment, Attendance-related, Other; codes in `TicketIncident::CATEGORIES`, chosen 2026-10-25 under John's delegation, Q56). A case filed as Other needs an explanation of at least 30 characters. The category sorts and filters; it is not a legal classification. Nothing here is a retention, disciplinary or legal rule (Q59, NEEDS VERIFICATION). (2026-10-25, I1, revised I2) |
| BR-777 | **Filing an incident (I2).** Incident Reports are filed only from File an Incident (`incident-new`, POST `core_incident_create`): one page, Submit only (no draft), the ticket type set on the server, only the listed form fields read, CSRF checked, idempotent by submission key. The generic `core_ticket_create` refuses the Incident Report type; File an HR Request no longer lists it and old links redirect to File an Incident; My HR Requests no longer lists incident cases. Filing needs `ticket.file.incident` plus exactly one active linked Employee 201 record; without one the page explains it instead of showing a form (Q60). (2026-10-25, I2) |
| BR-778 | **People search (I2).** The picker's search (`incident-people`, JSON) needs the filing permission, at least 2 characters, returns at most 10 active accounts in the employee, HR or admin portals (never client-portal, inactive or the signed-in user) and exposes only name and branch (no email, role or id card data). The same account rule applies when the reporter picks people and when HR includes someone. (2026-10-25, I2) |
| BR-779 | **My Incidents (I2).** `incident-mine` lists only cases the user filed or has access to (BR-769); HR reviewers get no blanket access there. Each row shows title, ticket number, category, when it happened, status chip, last update, and "You reported this" or "Shared with you"; never another person's role label, internal notes or messages. The old feed `hr-incidents` stays as HR's Incident Cases until I4; anyone else who opens it lands on My Incidents. (2026-10-25, I2) |
| BR-780 | **Case page (I3).** The case opens in `hr-request` for every incident id; it replaces the generic ticket detail. Header (case number, status pill, five-step stepper: Submitted, Under review, In progress, Resolved, Closed; a step the case skipped shows as skipped, a dismissed or withdrawn case stops at the step it reached), conversation, sticky composer, people rail, case summary, attachments, timeline. Markup that is private by role is absent for other readers: role labels other than Reporter and CC, who has access, people-change events, internal notes and the HR panel exist only for the handling HR reviewer. HR panel (handler only): status with required remarks and an optional internal note, include or remove people, internal note, Reopen (In progress from Resolved or Closed). The reporter may withdraw while SUBMITTED. Names (authors of messages and HR updates, timeline actors) are shown to every reader, as everyone who can read a case sees the names on it (BR-773). The handler's people search is `incident-people?case=<id>`: allowed only to the handler of that case, same account filter as BR-778, and it leaves out the reporter and everyone who already reads the case. No change to who can read a case (BR-769). (2026-10-25, I3) |
| BR-781 | **Case actions (I3).** POST to `incident-act` with CSRF: `core_incident_status` (reporter cancel or handler status), `core_incident_participant_add`/`_remove` (handler), `core_incident_note` (handler, saved as a STAFF_ONLY comment), `core_incident_message` (any reader while the case is open). Each goes to the existing `TicketService` method, which re-checks reader, handler and version; the form is never trusted for role, status flow or access. Failures flash the rule message and return to the case; unexpected errors are logged and shown generically. HR cannot cancel a case; it dismisses it with a reason (assumption, Q61). (2026-10-25, I3) |
| BR-782 | **Incident Cases queue (I4).** `hr-incidents` (HR only; others go to My Incidents) lists cases through the same reader rule as BR-769 (`readClause`), never drafts. Tabs: Open (default), Submitted, Under review, In progress, Resolved, Closed, Dismissed, Cancelled, All; tab counts use the same filters and reader rule as the rows. **Open = Submitted + Under review + In progress** (`TicketIncident::OPEN_QUEUE`), the one definition used by the queue and the HR dashboard Open metric, so both numbers match for the same HR user (the dashboard company filter can only lower it). Filters (whitelisted, bad values fall back): category, where it happened (else reporter's branch), filed date range, search by case no., title or reporter (parameterised, wildcards escaped). Aging shows days since filed for open cases (ok up to 2, warn 3 to 5, late over 5). The Case page "back" link rebuilds the queue view from whitelisted filters only. Q61 confirmed: HR dismisses, never cancels; HR names visible. (2026-10-26, I4) |
| BR-783 | **Payroll attendance export review (built 2026-10-12, tests 2026-10-26).** Before the review export, `currentReport` rebuilds `attendance_daily`. Imported rows whose biometric ID equals an exact Employee Code are re-matched automatically (audit `BIOMETRIC_REMATCH`); no name or numeric guessing. An employee with no punches in any imported file shows "No biometric logs received for this employee", not "Waiting for files". A Source Summary (Excel sheet / PDF pages) lists each group's employees with and without logs. Tests: `tests/attendance_export_review_test.php` (49 checks). |

## As built: access matrix by role and ticket type (verified live 2026-10-25)

Grants are `role_permissions` rows, so HR can change them; this is the state on the live database. Hub: [[Employee-Tickets]]; permission list: [[Data-Model]].

**Who may FILE (BR-720, BR-721; also Roles in [[Self-Service-Portal]])**

| Type | EMPLOYEE | MANAGER / OIC / TC | SC | ADL | HR_USER, HRIS_ADMIN |
|---|---|---|---|---|---|
| ATM, ID, Incident, Uniform, Payroll Concern, COE | yes | yes | yes | yes | yes (COE for self) |
| Manpower, NetSuite | no | yes | yes | yes | yes |
| OIC Authorization | no | no | yes (own branch) | yes (own areas) | HR/Admin for others only via `ticket.file_for_others` (no `ticket.file.oic_authorization` row live: unverified how HR files it) |
| Branch Transfer | no | no | no | `ticket.file_for_others` held live (unverified intent) | yes |

**Incident cases** use their own read rule (BR-769, `TicketIncident::readClause`) inside the same gate; the sentence below applies to every other type.

**Who may READ / ACT on a ticket** (one gate, `TicketRepository::find`): requester (own tickets); HR with `tickets.review_hr` on the HR portal (handler, sets status); the named step approver (Payroll Concern Manager/ADL step: inbox); Accounting members (NetSuite concerns once COMPLETED, lane remarks only); read-only viewers per BR-724..727 (list, detail, ordinary comments, history; no attachments, approve, comment, notification).

**Privacy rules (summary of BR-726, BR-744, BR-754, BR-761, BR-767):**

| Data | Requester | HR | Viewers (ADL/SC/OIC/Manager) | Accounting |
|---|---|---|---|---|
| Incident Report | own case; participants with access per BR-769 | handling reviewer: full case, thread, internal notes, people (BR-769..775); queue, report and CSV still exclude it (BR-744, BR-761: count only) | never | never |
| COE ticket and generated .docx | yes | yes (preview, serve, download; every generation/download audited) | never | never |
| HR response (status remarks) | yes | yes | never | never |
| HR internal note | never | yes | never | never |
| Drafts | own | no | never | no |

**Bulk and quick actions:** BR-740 to BR-744 (ATM, ID, Uniform only; Served or Cancel; one ticket at a time through `TicketService::act()`; max 100 per run; Incident never listed).

**Rule-number housekeeping (2026-10-25):** the OIC/viewer functional requirements in [[Self-Service-Portal]] were renumbered FR-730,732-735 -> FR-760..764 because FR-730..739 already belong to [[Notifications]].

## Open Questions

- [ ] Rules touching Q6-Q12 in [[Open-Questions]] (leave types, OT exemption, pay basis, correction model) are provisional until John answers.

## Related

[[Conventions]] · [[Data-Model]] · [[Security-and-Compliance]] · [[Decision-Log]] · [[Glossary]] · [[Start-Here]]
