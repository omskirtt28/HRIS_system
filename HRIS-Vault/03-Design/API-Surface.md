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
