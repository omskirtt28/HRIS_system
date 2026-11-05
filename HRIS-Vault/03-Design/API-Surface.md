---
title: API Surface
type: design
status: draft
tags: [design, api, mvp]
updated: 2026-10-17
---

# API Surface

## Purpose

The HTTP contract between browser and server, and the shape any future integration would use. Stack-neutral: the same paths work whether [[ADR-001-Tech-Stack]] lands on Laravel or something else. Server-rendered pages may call the same application services directly; this note defines the JSON surface so it is testable and so the [[Self-Service-Portal]] can be a thin client.

## Conventions

| Topic | Rule |
|---|---|
| Base path | `/api/v1` |
| Format | JSON, UTF-8, `snake_case` keys matching [[Data-Model]] columns |
| Auth | Session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`) plus CSRF token on unsafe methods. Bearer tokens only if an integration is added later. See [[Auth-and-RBAC]] |
| IDs | Numeric `id` in paths; `employee_no` is a searchable attribute, not a path key |
| Money | Strings with 4 decimals (`"12345.6700"`), never JSON floats |
| Dates | `YYYY-MM-DD`; timestamps ISO 8601 with `+08:00` offset |
| Pagination | `?page=1&per_page=50` (max 200); response `{data, meta:{page, per_page, total}}` |
| Filtering | Whitelisted query params only; unknown params return 422 |
| Idempotency | `Idempotency-Key` header required on payroll compute/release, imports, leave filing |
| Concurrency | `updated_at` echoed as `ETag`; `If-Match` on PATCH of employees, rate tables, compensation. Mismatch returns 412 |
| Scope | Every list/read is filtered by the caller's `user_scopes` in the repository (BR-113). Out-of-scope IDs return **404**, not 403, to avoid leaking existence |

### Error envelope

```json
{
  "error": {
    "code": "DTR_NOT_LOCKED",
    "message": "Cut-off 2026-10-16..2026-10-31 has unlocked DTR days.",
    "fields": { "payroll_period_id": ["must reference a locked period"] },
    "request_id": "01J9..."
  }
}
```

| HTTP | When |
|---|---|
| 400 | Malformed JSON |
| 401 | No/expired session |
| 403 | Authenticated, in scope, lacks permission |
| 404 | Missing or out of scope |
| 409 | State conflict (run already released, duplicate period run) |
| 412 | `If-Match` mismatch |
| 422 | Validation or business-rule failure; `code` carries the BR where relevant |
| 429 | Rate limited (login, password reset) |

## Endpoints

Permission codes are defined in [[Auth-and-RBAC]]. "Own" means the caller's linked employee.

### Auth and users

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/auth/login` | public | Rate limited, lockout per BR-101 family |
| POST | `/auth/logout` | session | Revokes session row |
| POST | `/auth/password/forgot` | public | Always 202 regardless of email existence |
| POST | `/auth/password/reset` | token | Single-use token |
| GET | `/me` | session | User, roles, effective permissions, linked employee id |
| GET/POST | `/users` | `users.manage` | |
| PATCH | `/users/{id}` | `users.manage` | roles, scopes, active flag |
| GET | `/users/{id}/sessions` | `users.manage` | |
| DELETE | `/sessions/{id}` | `users.manage` or own | Revoke |

### Organisation

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH | `/branches`, `/departments`, `/positions`, `/cost-centers`, `/employment-statuses` | `org.read` / `org.manage` |
| GET/POST/PATCH | `/holiday-calendars`, `/holiday-calendars/{id}/holidays` | `org.manage` |

### Employees

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/employees` | `employees.read` | Filters: `branch_id`, `department_id`, `status`, `q`, `incomplete=1` |
| POST | `/employees` | `employees.create` | Creates employee plus first placement/status/compensation atomically |
| GET | `/employees/{id}` | `employees.read` | Sensitive blocks gated separately |
| PATCH | `/employees/{id}` | `employees.update` | Personal fields only |
| GET/POST | `/employees/{id}/placements` | `employees.read` / `employees.update` | Dated; overlap rejected (BR-203) |
| GET/POST | `/employees/{id}/compensation` | `compensation.read` / `compensation.update` | Dated; needs second approver if configured |
| GET/PUT | `/employees/{id}/government-ids` | `govids.read` / `govids.update` | Reads logged to audit |
| GET/POST/DELETE | `/employees/{id}/documents` | `documents.*` | Download via `/documents/{id}/download` only |
| POST | `/employees/import` | `employees.import` | Multipart; returns validation report, `commit=1` to apply |
| GET/POST | `/employee-change-requests` | own / `employees.update` | |
| POST | `/employee-change-requests/{id}/approve` `/reject` | `employees.update` | Reason required on reject |

### Attendance

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/attendance/imports` | `attendance.import` | Multipart device export; keyed by file hash (idempotent) |
| GET | `/attendance/imports/{id}` | `attendance.import` | Counts, unmapped device IDs, errors |
| POST | `/attendance/imports/{id}/rollback` | `attendance.import` | Only if no covered day is locked (BR-308) |
| GET | `/dtr` | `dtr.read` | `employee_id`, `from`, `to`, `status` |
| GET | `/dtr/{employee_id}/{work_date}` | `dtr.read` or own | Includes sessions and raw punches |
| POST | `/dtr/corrections` | `dtr.correct` or own (request) | Audited; triggers re-derivation |
| POST | `/dtr/derive` | `dtr.derive` | Body: period or employee+range. Idempotent |
| POST | `/payroll-periods/{id}/dtr-lock` `/dtr-unlock` | `dtr.lock` | Unlock needs reason and is audited |
| GET/POST | `/overtime-requests` | own / scoped | |
| POST | `/overtime-requests/{id}/approve` `/reject` | `ot.approve` | Writes `hours_approved` |
| GET/POST | `/employees/{id}/schedules` | `schedules.manage` | Dated |

### Leave

| Method | Path | Permission |
|---|---|---|
| GET | `/leave-types` | session |
| GET | `/me/leave-balances` | own |
| GET | `/employees/{id}/leave-balances` | `leave.read` |
| POST | `/leave-requests` | own, or `leave.file_on_behalf` |
| GET | `/leave-requests` | scoped |
| POST | `/leave-requests/{id}/approve` `/reject` `/cancel` | `leave.approve` / own |
| POST | `/leave/accrual-runs` | `leave.admin` |
| POST | `/leave/year-end` | `leave.admin`; `dry_run=1` default |
| POST | `/leave/ledger-adjustments` | `leave.admin`; reason required |
| GET/POST | `/approval-delegations` | own |

### Payroll

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/POST/PATCH | `/rate-tables`, `/rate-tables/{code}/versions` | `ratetables.manage` | Version unusable until `verified_at` set (BR-514) |
| POST | `/rate-tables/versions/{id}/verify` | `ratetables.verify` | Different user than creator |
| GET/POST | `/payroll-periods` | `payroll.read` / `payroll.manage` | |
| POST | `/payroll-runs` | `payroll.run` | Creates DRAFT for a locked period |
| POST | `/payroll-runs/{id}/compute` | `payroll.run` | Snapshots inputs, records rate-table version set |
| GET | `/payroll-runs/{id}/employees` | `payroll.read` | Register rows; per-line breakdown |
| POST | `/payroll-runs/{id}/employees/{eid}/exclude` | `payroll.run` | Reason required |
| POST | `/payroll-runs/{id}/review` `/release` | `payroll.review` / `payroll.release` | Separation of duties: releaser differs from computer where configured |
| POST | `/payroll-runs/{id}/reverse` | `payroll.release` | Only before release; after release use adjustments |
| POST | `/payroll-runs/{id}/recompute-check` | `payroll.read` | Recomputes from snapshot, returns diff (expected empty) |
| POST | `/payroll-adjustments` | `payroll.run` | Against a released run, lands in a later run |
| GET/POST | `/employee-loans` | `loans.manage` | Amortisation schedule |
| GET | `/payslips/{id}` `/payslips/{id}/pdf` | own, or `payroll.read` | Sets `viewed_at` for own |
| GET | `/me/payslips` | own | |
| POST | `/thirteenth-month-runs` | `payroll.run` | |
| POST | `/final-pay-computations` | `payroll.run` | Requires clearance state per [[Offboarding-and-Clearance]] |
| GET | `/payroll-runs/{id}/journal-export` | `payroll.export` | CSV |

### Reports

| Method | Path | Permission |
|---|---|---|
| GET | `/reports` | `reports.read` (catalogue filtered by permission) |
| POST | `/reports/{code}/generate` | per-report permission; async, returns `job_id` |
| GET | `/reports/jobs/{id}` | owner |
| GET | `/reports/jobs/{id}/download` | owner, logged |

### Cross-cutting

| Method | Path | Permission |
|---|---|---|
| GET | `/approvals/inbox` | session (aggregates leave, OT, DTR correction, change requests the caller can act on) |
| GET | `/notifications` `PATCH /notifications/{id}/read` | own |
| GET | `/audit-log` | `audit.read` |
| GET | `/health` | public, no data; DB ping only |

### Recruitment, onboarding, offboarding, performance

Follow the same CRUD-plus-transition pattern: resource collections (`/applicants`, `/job-offers`, `/onboarding-checklists`, `/separations`, `/clearance-items`, `/review-cycles`) with explicit transition endpoints (`POST .../{id}/convert-to-employee`, `.../clear`, `.../submit`). Detailed in [[Recruitment-and-Onboarding]], [[Offboarding-and-Clearance]], [[Performance-Management]]. Not MVP except where [[Build-Plan]] pulls them in.

## As built: HTTP surface of the PHP app (2026-10-25)

The `/api/v1` JSON contract above is the target. The running app is server-rendered: GET `?page=<id>` returns a page, POST `action=<name>` (CSRF token, prepared SQL) mutates then redirects. Handlers: `TicketModule`, `TicketAssignmentModule`, `TicketViewerModule`, `TicketCoeModule`, `TicketReportModule`. Screens and gates: [[Screens]] (SCR-750..767).

| Kind | Name | Gate | Effect |
|---|---|---|---|
| POST | `core_ticket_create` (SUBMIT or draft) | filing permission + `ticket.file.<type>`; idempotency key in `ticket_submission_keys` | Create, freeze route, files attachments |
| POST | `core_ticket_edit` | requester, draft/returned, type still fileable (BR-722) | Edit or resubmit |
| POST | `core_ticket_act` | approver / HR / requester per `TicketService::permissions()`; `version` check | Approve, return, reject, In Progress, Served, Cancel; HR note (BR-767) |
| POST | `core_ticket_quick`, `core_ticket_bulk` | `tickets.review_hr`, HR portal | One ticket at a time through `act()` (BR-740..742) |
| POST | `core_ticket_comment`, `core_ticket_attach` | requester/approver/HR, never `viewer_only` | Comment (ALL or STAFF_ONLY), attachment |
| service | `TicketService::incidentStatus`, `incidentAddParticipant`, `incidentRemoveParticipant`; listing mode `incidents_mine` (I1, **no HTTP route or form yet**; routes arrive with the I2/I3 screens) | handling HR reviewer (status, people); reporter may cancel while SUBMITTED; `mine` for reporter and participants with access (BR-769..775) | Case status with required remarks and optional HR-only note; include or remove a person; audited events |
| GET | `incident-mine`, `incident-new` | signed-in employee/HR/admin account; `incident-new` also needs the filing permission | My Incidents list; the filing form with a single-use submission key |
| GET | `incident-people?q=[&case=id]` | filing permission, or with `case` the HR reviewer handling that case (BR-780; reporter and people who already read it are left out); JSON `{people:[{id,name,branch}]}`, min 2 characters, max 10, active employee/HR/admin accounts, not the caller | Participants picker search (BR-778) |
| POST | `core_incident_create` | CSRF + filing permission + one linked active Employee 201; allowlisted form answers, nature/category/filer/report date server-derived | Files through `TicketService::create`; frozen official snapshot and required DOCX inside transaction/cleanup; PDF optional. Failure retains answers, success opens case. Existing submission key deduplicates. BR-784..786; generic create refuses this type |
| GET | `incident-document&id=...&format=pdf\|docx` | authenticated case reader AND reporter or eligible handling HR, checked per request | Private official file; no-store, generated download filename. No historical backfill; PDF may be unavailable. BR-787 |
| POST | `incident-act`, `core_incident_pdf_retry`, `ticket_id`, `version` | CSRF + eligible handling HR + current version | Bounded local LibreOffice conversion of frozen DOCX; failure retains DOCX/case and retry state. No external upload. BR-786/787 |

Ticket Viewers GET `hr-ticket-viewers&focus=branches_without_sc|retail_missing|ho_missing`: existing HR access gate; unknown focus ignored. Matching section expands, employee focus applies before 50-row pagination and query/anchor are retained across pages. Default callers/global dashboard counts remain unchanged (BR-788).
| POST | `incident-act` with `core_incident_status`, `core_incident_participant_add`, `core_incident_participant_remove`, `core_incident_note`, `core_incident_message` | CSRF + signed-in employee/HR/admin account; reader or handler rules enforced by `TicketService` (BR-781) | Case page actions; always redirects to the case. GET on `incident-act` redirects to My Incidents |
| POST | `core_ticket_netsuite_remark`, `core_ticket_accounting_member` | HR lane / Accounting membership | NetSuite remarks; membership |
| POST | `ticket_approvers_preview` / `_save` / `_cancel` | `tickets.approvers.manage` | Effective-dated Manager/ADL assignments |
| POST | `coe_template_upload` / `coe_template_activate` / `coe_settings_save` | `ticket.coe.template.manage` | COE template versions and signatory |
| GET | `hr-request-attachment` | ticket read gate | Streamed download, `nosniff` |
| GET | `hr-service-requests-export` | `tickets.review_hr` | Queue CSV, audited, formula-safe |
| GET | `hr-ticket-report-export`, `hr-ticket-report-print` | `ticket.report.view` | CSV (cap 5000, audited) and one-page print |
| GET | `hr-coe-download`, `hr-coe-template-file` | requester + HR / HR | Private .docx, every download audited |

Errors: a refused request returns HTTP 403 with the ticket error page. Scheduled jobs: `scripts/run_notifications.php`, `scripts/run_ticket_notifications.php`.

## Design rules

1. **State changes are verbs on sub-resources** (`/release`, `/approve`), not `PATCH status=`. Prevents skipping the transition guard.
2. **No endpoint returns unscoped data**, and none is reachable without a declared permission (deny by default, see [[Architecture]]).
3. **Mass assignment is whitelisted per endpoint.** `employee_no`, `created_by`, `rate_table_version_set` are never client-settable.
4. **Long jobs** (derive, compute, report) return 202 with a job resource; the UI polls. Compute for 300 employees should finish in seconds, but the contract does not assume it.
5. **Webhook/device push is out of scope** until [[Open-Questions]] Q5 is answered; imports are file uploads.

## Open Questions

- [ ] Server-rendered pages with JSON for XHR only, or a full SPA? Affects how much of this is public contract (see [[ADR-001-Tech-Stack]]).
- [ ] Do we need an OpenAPI file generated from code? Recommended once the first three slices exist.

## Related

[[Architecture]] · [[Data-Model]] · [[Auth-and-RBAC]] · [[Screens]] · [[Security-and-Compliance]] · [[Business-Rules]] · [[Payroll]] · [[Attendance-and-DTR]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]

## 2026-10-31 - My Profile routes
- GET employee-profile (page), GET employee-profile-photo, GET employee-profile-document?id=[&download=1], POST employee-profile-reveal (csrf, id = own government ID row, returns JSON number, no-store, audited REVEAL_OWN_GOV_ID). Guard: signed-in employee/hr/admin portal account (EmployeeProfileService::requireAccess), own employee row only; no employee id parameter. Ownership of ID rows and documents is enforced in the SQL (employee_id = signed-in employee).

## 2026-10-31 - Follow up route
- POST hr-request (action `core_ticket_followup`, ticket_id, csrf): `TicketLeadFollowup::followUp`. Read-only lead only, open status, not Incident Report, once per 24h. Opening a request as a viewer marks its notifications read.

- 2026-11-02 POST page=user-password-reveal (JSON, CSRF, users.view_credentials, 10 per minute) returns ok, password, message. page=change-password GET and POST. Removed action create_user. reset_user_password now allows users.view_credentials (employee accounts only).

- 2026-11-02 POST generate_employee_account (employee_id, account_role EMPLOYEE or HR, csrf; employees.manage, HR role also needs users.view_credentials) and POST generate_missing_accounts (csrf; employees.manage and users.view_credentials) return a redirect with a flash; the bulk result is kept in the session for one directory render. Removed action link_user_employee. update_employee_employment ignores any posted user_id.

- 2026-11-02 POST remove_user and POST restore_user (user_id, csrf; users.remove) on page=admin-users, redirect back with a flash. Scope: not self, not Super Admin, Admin-portal only by Super Admin. toggle_user_status, reset_user_password and change_user_role now refuse a removed account.


- 2026-11-02 POST approve_manpower (ticket_id, version, client_id or new_employer, priority NORMAL|URGENT, position_title, remarks, csrf) and POST decline_manpower (ticket_id, version, remarks required, csrf) on page=hr-manpower; recruitment.manage plus HR ticket review; errors flash and redirect back to hr-manpower. create_manpower now also takes new_employer and refuses a missing employer. GET hr-manpower accepts filter=declined|all.

## 2026-11-02 - rec-jobs routes
GET hr-jobs (recruitment.view; ?filter=draft|published|closed, ?q=, ?new=1, ?request_id=, ?edit=). POST save_job (job_id 0 = create; manpower_request_id or client_id/new_employer; fields title, description, requirements, location_text, employment_type FULL_TIME|CONTRACT, openings 1-999, salary_min/max, closes_on, department_id, is_featured, publish_now), publish_job, unpublish_job, close_job, reopen_job (job_id); all need recruitment.manage and CSRF, errors flash and redirect to hr-jobs, and a refused save_job keeps the form input. Removed: POST create_manpower. Public readers use JobOpenings::PUBLIC_WHERE.

## 2026-11-02 - rec-apply-job routes
GET hr-application-resume?id=<application id>[&download=1] (recruitment.view). Streams the applicant's latest RESUME from uploads.resume_dir, found by application id (never a posted path); PDF inline, DOC/DOCX or &download=1 as attachment; nosniff; 404 when none; audit VIEW_RESUME. Not available to the client portal or the public. Apply page: GET apply?job_id=N now needs a public job (hidden job_opening_id); with none it shows a Browse jobs prompt, no form. Error redirects keep job_id (return_job).

## 2026-11-02 - rec-endorse-manager routes
POST endorse (recruitment.manage; fields application_id, note, reviewers[]) now endorses to reviewers. GET review-candidates and GET review-candidate?id= (recruitment.review; 404 unless endorsed to the signed-in user). GET review-resume?id=[&download=1] (recruitment.review plus a reviewer row for this user; HR keeps hr-application-resume). POST review_decide (application_id, decision PROCEED|NOT_SUITABLE|ANOTHER_INTERVIEW, comment) and POST review_schedule (application_id, interview_type, scheduled_at, location_or_link, notes), both recruitment.review and own rows only. Notification kinds CANDIDATE_REVIEW (to the reviewer, sidebar badge on review-candidates) and CANDIDATE_DECISION (to HR, badge on hr-applicants).

## 2026-11-02 - interview-invite and interview-chain routes
POST resend_interview_invite (recruitment.manage; interview_id, application_id; CSRF). POST review_pass_on (recruitment.review; application_id, to[], final[], note; CSRF; scoped to reviewers of the candidate). POST endorse also accepts final[] (subset of reviewers[]). POST schedule_interview and review_schedule now send the invite email.

- 2026-11-02 hr-appearance-palettes Task 2 of 5 (Claude): `POST index.php?page=palette-save` (csrf, palette = light|dark|beige|earth|pastel). JSON only: 200 {ok,palette}; 401 signed out; 403 not the HR portal role (Admin included); 405 not POST; 419 bad or missing token (generic POST guard); 422 unknown value. Saves users.ui_palette and refreshes the session; login now puts ui_palette in the session user.

Update 2026-11-02: POST action upload_own_photo (page employee-profile): any internal account with My Profile access; own employee row only (employee_id never read from the form); same 3 MB JPG/PNG/WEBP rules as upload_employee_photo; errors flash back to My Profile.
- `hr-pipeline` GET q, job_id, recruiter_id; `hr-applicants` GET q, stage, job_id, recruiter_id, p (page, 25 per page). Read-only, permission unchanged.
- 2026-11-04 POST action pipeline_save_view (page hr-pipeline): recruitment.view, CSRF; fields site (HEAD_OFFICE|RETAIL, anything else = HEAD_OFFICE), optional branches_form + branch[] (all branches ticked = no filter). GET hr-pipeline accepts site (HEAD_OFFICE|RETAIL|UNASSIGNED; UNASSIGNED never saved) and branch[] (override of the saved view); GET hr-applicants accepts site (HEAD_OFFICE|RETAIL|UNASSIGNED, else ignored) and branch[].

- 2026-11-05: GET index.php?page=employee-team&q=&p= (employees.view_team). POST change_user_role: users.manage -> changeUserRole, else users.assign_employee_role -> changeEmployeeUserRole (CSRF global).

- 2026-11-05: GET index.php?page=track&t=<64 hex> (public, private link) or &ref=&email= (public). POST apply now redirects to track&t=<token> (no email/ref in URL); falls back to track&ref= if the link cannot be issued.
