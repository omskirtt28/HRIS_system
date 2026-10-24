---
title: Auth and RBAC
type: module
status: draft
tags: [module, hris, mvp, security]
updated: 2026-10-24
---

# Auth and RBAC

## Purpose

Decide who may do what, to whose data, and leave a record of it. In an HRIS this is not a generic login module: it guards salary data, government IDs and medical certificates, and it is the mechanism that makes separation of duties real.

Three concepts, kept separate:

- **Authentication** — who are you. Credentials, sessions, MFA.
- **Authorisation (permission)** — what action may you perform. `payroll.run`, `employee.edit`.
- **Scope** — on whose records. A department head has `leave.approve` but only for their own team.

Permission without scope is the classic HRIS hole: a manager who can approve leave can approve *anyone's* leave. Scope must be enforced in the data layer, not just hidden in the UI.

> [!warning] Authorisation lives on the server
> Hiding a button is not a permission check. Every write and every read of sensitive data must be checked server-side at the point of data access. A rule inherited from the existing codebase: business rules live on the server, never only in JavaScript.

## Scope

### In scope

- User accounts linked to employees, and accounts not linked to any employee (system/service)
- Password authentication, hashing, reset, change, strength policy
- Session management, tracked server-side, with revocation
- Roles, permissions, role-permission mapping
- Scope rules: company, branch, department, reporting-chain, self
- Permission checking API used by every module
- Audit trail of authentication events and of permission-relevant actions
- MFA for privileged roles
- Account lifecycle tied to employment status
- Separation-of-duties constraints

### Out of scope

- Employee personal data → [[Employee-Records]]
- Approval *routing* (who is the right approver for this leave) → [[Leave-Management]]; this module answers whether a given person *may* approve
- Data privacy obligations and retention → [[Security-and-Compliance]]
- Notification delivery → [[Notifications]]
- Single sign-on / directory integration → later consideration

## Roles

| Role code | Persona | Intent |
|---|---|---|
| `EMPLOYEE` | P4 | Own data only. Default for every employee account. |
| `MANAGER` | P3 | Approvals and visibility within the reporting chain. Additive to `EMPLOYEE`. |
| `HR_ADMIN` | P1 | People data across the company; no pay amounts. |
| `PAYROLL_OFFICER` | P2 | Payroll and DTR; read-only on people data. |
| `HR_HEAD` | P1 | HR admin plus policy configuration and HR reports. |
| `SYS_ADMIN` | P5 | Everything, fully audited, cannot alter audit records. |
| `AUDITOR` | — | Read-only across payroll and audit log, no write anywhere. Useful for an external review and cheap to add. |

Roles are additive. A department head is `EMPLOYEE` + `MANAGER`.

## Permission matrix

Permission codes are `module.action`. This is the authoritative matrix; [[Personas]] holds the orientation version.

| Permission | EMPLOYEE | MANAGER | HR_ADMIN | PAYROLL | HR_HEAD | SYS_ADMIN | AUDITOR |
|---|---|---|---|---|---|---|---|
| `employee.view.self` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `employee.view.team` | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `employee.view.all` | — | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| `employee.edit` | — | — | ✓ | — | ✓ | ✓ | — |
| `employee.edit.self.basic` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `employee.request.change` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `employee.view.compensation` | self | — | — | ✓ | ✓ | ✓ | ✓ |
| `employee.view.bank` | self | — | — | ✓ | — | ✓ | — |
| `employee.import` | — | — | ✓ | — | ✓ | ✓ | — |
| `org.manage` | — | — | ✓ | — | ✓ | ✓ | — |
| `holiday.manage` | — | — | ✓ | — | ✓ | ✓ | — |
| `attendance.view.self` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `attendance.view.team` | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `attendance.import` | — | — | — | ✓ | — | ✓ | — |
| `attendance.correct` | request | approve team | ✓ | ✓ | ✓ | ✓ | — |
| `attendance.lock` | — | — | — | ✓ | — | ✓ | — |
| `overtime.approve` | — | ✓ | — | — | ✓ | ✓ | — |
| `leave.file.self` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `leave.file.onbehalf` | — | — | ✓ | — | ✓ | ✓ | — |
| `leave.approve` | — | ✓ | override | — | ✓ | ✓ | — |
| `leave.configure` | — | — | ✓ | — | ✓ | ✓ | — |
| `leave.yearend` | — | — | — | — | ✓ | ✓ | — |
| `payroll.run` | — | — | — | ✓ | — | ✓ | — |
| `payroll.review` | — | — | — | ✓ | — | ✓ | ✓ |
| `payroll.release` | — | — | — | ✓ | — | ✓ | — |
| `payroll.adjust` | — | — | — | ✓ | — | ✓ | — |
| `payslip.view.self` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `payslip.view.all` | — | — | — | ✓ | — | ✓ | ✓ |
| `ratetable.manage` | — | — | — | — | — | ✓ | — |
| `ratetable.verify` | — | — | — | ✓ | — | ✓ | — |
| `report.hr` | — | team | ✓ | — | ✓ | ✓ | ✓ |
| `report.statutory` | — | — | — | ✓ | — | ✓ | ✓ |
| `user.manage` | — | — | — | — | — | ✓ | — |
| `role.manage` | — | — | — | — | — | ✓ | — |
| `audit.view` | — | — | — | — | — | ✓ | ✓ |
| `audit.delete` | — | — | — | — | — | — | — |

`audit.delete` exists as a row with no holder on purpose: the audit log is append-only for everyone, including the sys admin.

> [!note] Separation of duties
> `employee.edit` and `payroll.run` are never held by the same role. Creating a fake employee and paying them must require two people. `ratetable.manage` and `ratetable.verify` are likewise split (BR-514). On a small team this is inconvenient; document any deliberate exception rather than quietly granting both.

## Scope model

| Scope type | Meaning | Resolution |
|---|---|---|
| `SELF` | The user's own employee record only | `employee_id` on the user |
| `REPORTING_CHAIN` | Direct and indirect reports | Walk `employee_placements.manager_employee_id` as of the relevant date |
| `DEPARTMENT` | One or more departments | Explicit grant rows |
| `BRANCH` | One or more branches | Explicit grant rows |
| `COMPANY` | Everything in the company | Implicit for admin roles |

Scope is resolved to a **set of employee IDs** and applied as a filter in the data layer. Every query over employee-related data takes a scope argument; there is no unscoped query path.

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `users` | Login account. Separate from the person. | `id`, `email`, `password_hash`, `employee_id` (nullable), `status` (`ACTIVE`/`DISABLED`/`LOCKED`), `must_change_password`, `mfa_secret_enc`, `mfa_enabled`, `last_login_at`, `failed_attempts`, `locked_until` |
| `roles` | Role master. | `id`, `code`, `name`, `is_system` |
| `permissions` | Permission master. | `id`, `code`, `name`, `module` |
| `role_permissions` | Mapping. | `role_id`, `permission_id` |
| `user_roles` | Assignment. | `user_id`, `role_id`, `granted_by`, `granted_at` |
| `user_scopes` | Scope grants. | `user_id`, `scope_type`, `scope_ref_id`, `granted_by` |
| `user_sessions` | Server-tracked sessions, revocable. | `id`, `user_id`, `session_token_hash`, `ip_address`, `user_agent`, `created_at`, `last_seen_at`, `expires_at`, `revoked_at`, `revoked_by` |
| `auth_events` | Authentication audit. | `user_id`, `event_type` (`LOGIN_SUCCESS`/`LOGIN_FAIL`/`LOGOUT`/`PASSWORD_RESET`/`MFA_CHALLENGE`/`LOCKOUT`), `ip_address`, `occurred_at`, `detail` |
| `audit_log` | Business-action audit. Append-only. | `id`, `actor_user_id`, `actor_employee_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `occurred_at`, `request_id` |
| `password_resets` | Single-use reset tokens. | `user_id`, `token_hash`, `expires_at`, `used_at` |

## Core Flows

### Flow 1 — Login

1. User submits email and password on SCR-001.
2. System looks up the account; a non-existent account and a wrong password produce the **same** generic failure and the same timing, so accounts cannot be enumerated.
3. Failed attempts increment; beyond a threshold the account locks for a cooldown period. Both are logged to `auth_events`.
4. On success: verify hash, regenerate the session ID, create a `user_sessions` row, reset the failure counter, log `LOGIN_SUCCESS`.
5. If the role requires MFA and it is unenrolled, force enrolment before granting access.
6. If `must_change_password`, force a change before anything else.
7. Route to the portal implied by the user's highest role.

### Flow 2 — Permission check

Called on every protected action, server-side.

1. Resolve the user's effective permission set from their roles, cached per request.
2. Check the required permission code. Absent → deny, log, return a generic authorisation error that does not reveal what exists.
3. If the action targets employee data, resolve the user's scope to an employee-ID set and verify the target is inside it.
4. For approval actions, additionally verify the actor was in the subject's reporting chain as of the relevant date, or is a valid delegate (BR-412).
5. Permit, and for sensitive-data reads, log the access (see [[Security-and-Compliance]]).

### Flow 3 — Account lifecycle tied to employment

1. An employee record may exist without a user account; not everyone gets a login (Q15).
2. Creating an account links `users.employee_id` and grants `EMPLOYEE` by default.
3. On separation, [[Offboarding-and-Clearance]] disables the account and revokes all active sessions on the separation date — not "eventually". A departed employee with a live session is the most likely real breach.
4. On role change, permissions update immediately; existing sessions re-resolve permissions per request rather than caching them in the session.

### Flow 4 — Password reset

1. User requests a reset by email.
2. System always responds identically whether or not the account exists.
3. If it exists, a single-use, time-limited token is emailed. The old password remains valid until the reset completes.
4. On reset: password updated, all other sessions revoked, event logged, user notified by email that a reset occurred.

### Flow 5 — Session revocation

1. Sys admin views active sessions on SCR-100 with IP, device and last-seen.
2. Revokes one or all. `revoked_at` is set and the next request on that session is rejected — revocation must be server-side, which is why sessions are tracked rather than purely cookie-based.

## Business Rules

- **BR-110** — A user account and an employee record are distinct entities; the link is optional and one-to-one at most.
- **BR-111** — Permissions attach to roles, never directly to users. No per-user permission grants.
- **BR-112** — Scope attaches to users, because two users with the same role may have different scopes.
- **BR-113** — Every query returning employee-related data must take a scope filter. An unscoped path is a defect.
- **BR-114** — `employee.edit` and `payroll.run` must not be held by the same role.
- **BR-115** — `ratetable.manage` and `ratetable.verify` must be held by different users for a given table version.
- **BR-116** — The audit log is append-only. No role holds `audit.delete`.
- **BR-117** — Passwords are stored only as a modern adaptive hash. Never reversible, never logged, never emailed.
- **BR-118** — Sessions expire on an absolute and an idle timeout, and are revocable server-side.
- **BR-119** — A user cannot approve, review or release anything concerning their own employee record.
- **BR-120** — Separation of an employee disables their account and revokes their sessions on the effective separation date.
- **BR-121** — Permission denials are logged with the attempted action, and the response reveals nothing about what exists.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-120 | The system shall authenticate users by email and password, with the password stored as an adaptive hash. (US-101, BR-117) | Must |
| FR-121 | The system shall lock an account after a configurable number of failed attempts, for a configurable cooldown, and shall log both. | Must |
| FR-122 | The system shall return an identical response for an unknown account and a wrong password. | Must |
| FR-123 | The system shall regenerate the session identifier on successful login. | Must |
| FR-124 | The system shall track sessions server-side and shall allow immediate revocation of any session. (US-106, BR-118) | Must |
| FR-125 | The system shall route a user to the portal implied by their highest role. (US-101) | Must |
| FR-126 | The system shall define roles with permission sets and shall assign roles to users. (US-102, BR-111) | Must |
| FR-127 | The system shall grant scopes to users and shall resolve a scope to a set of employee IDs. (US-103, BR-112) | Must |
| FR-128 | The system shall check both permission and scope server-side at the point of data access for every protected action. (BR-113) | Must |
| FR-129 | The system shall verify, for approval actions, that the actor was in the subject's reporting chain as of the relevant date or is a valid delegate. | Must |
| FR-130 | The system shall prevent a user from approving, reviewing or releasing anything concerning their own employee record. (BR-119) | Must |
| FR-131 | The system shall maintain an append-only audit log of every create, update and delete on employee, attendance, leave, payroll and configuration data, with actor, timestamp, before and after values. (US-104, BR-116) | Must |
| FR-132 | The system shall log all authentication events including failures and lockouts. | Must |
| FR-133 | The system shall provide a searchable audit log view filterable by actor, entity, action and date range. (US-104) | Must |
| FR-134 | The system shall support password reset by a single-use time-limited emailed token, revoking other sessions on completion. (US-105) | Should |
| FR-135 | The system shall enforce a configurable password policy and shall support forced change on first login. | Should |
| FR-136 | The system shall support MFA and shall allow it to be required per role. (US-107) | Could |
| FR-137 | The system shall disable the account and revoke all sessions of a separated employee on the separation date. (BR-120) | Must |
| FR-138 | The system shall prevent assignment of role combinations that violate a declared separation-of-duties constraint, or shall require an explicit documented override. (BR-114, BR-115) | Should |
| FR-139 | The system shall support employee records with no user account. | Must |
| FR-140 | The system shall log permission denials without revealing the existence of the target resource. (BR-121) | Should |
| FR-141 | The system shall provide a read-only auditor role with no write permission anywhere. | Could |
| FR-142 | The system shall protect all state-changing requests against cross-site request forgery. | Must |

## Edge Cases

| Case | Expected handling |
|---|---|
| HR admin is also an employee filing their own leave | Allowed, but they must not approve it. BR-119 escalates to the next level. |
| Sys admin edits their own role | Must not be able to remove the last `SYS_ADMIN`, and the change is audited. |
| Employee promoted to manager mid-cut-off | Scope resolves as of the relevant date, so past approvals remain valid and future ones route to the new chain. |
| Manager on leave | Delegation grants temporary approval authority with an end date; the delegate's action records `acted_as_delegate_for`. |
| Shared device in a factory or site office | Short idle timeout, explicit logout, and no "remember me" on employee portal accounts. |
| Employee has no email address | Either no account, or a username-based account. Decide under Q15; do not invent fake emails. |
| Account exists for a separated employee who is rehired | New employee record per [[Employee-Records]]; the account is re-enabled and re-linked, or a new one created. Decide and document. |
| Two employees share a personal email | Reject. Email is the account identifier and must be unique. |
| Role deleted while users hold it | Block deletion; deactivate instead. |
| Audit log grows very large | Partition or archive by date, but never delete within the retention period. See [[Security-and-Compliance]]. |
| Session hijacking concern | Bind the session to a hash of the user agent, rotate on privilege change, and keep the absolute timeout short for payroll and admin roles. |
| Permission check forgotten on a new endpoint | Mitigate structurally: deny-by-default routing where an endpoint without a declared permission is unreachable, rather than relying on reviewer memory. |

## As built: role and user administration (2026-10-24)

The existing PHP app keeps one role per user (`users.role_id`), not the `user_roles` table above. Permission codes in the app use the app's own names (`users.manage`, `roles.manage`, `ticket.file.*`), not the `module.action` names in the target matrix.

Screens: **Roles & Permissions** (`admin-roles`) and **Users & Access** (`admin-users`). HR/Admin can now, with no code or manual DB work:

- create a role (code `A-Z0-9_`, unique, portal) and tick its permissions;
- create a user, change an existing user's role, and link or unlink a user to an employee (`employees.user_id`, one-to-one);
- see a warning listing active non-client accounts with no employee link (they cannot file HR requests).

Roles ADL, SC, OIC and TC are seeded by migration `20261024_011_lead_roles.sql` (employee portal, skipped for any code that already exists). Each gets MANAGER's base portal access without HR/admin permissions — `dashboard.employee.view`, `employees.view_self`, `attendance.view_self`, `leave.view_self`, `leave.request_self`, `payroll.request_self`, `requests.request_self`, `team.view`, `tickets.request_self` — plus the five staff `ticket.file.*` types, `ticket.file.manpower` and `ticket.file.netsuite`. Approver permission: ADL and TC → `payroll.approve_adl`, OIC → `payroll.approve_manager`, SC → none (filer only). A user on an approver-capable role appears automatically in **Ticket Approvers** (ADL/TC in the ADL list, OIC and Manager in the Manager list). HR can still change these roles on the Roles screen; the down script refuses once a seeded role has users or edited permissions.

The existing `COORDINATOR` role (HR portal: HR dashboard, Recruitment view/manage, Reports) is a recruitment coordinator, not TC, and is unchanged.

Escalation guards (server-side, in `FoundationRepository`):

- **BR-122** — The Super Admin role cannot be assigned, edited, or have its users changed from the UI, by anyone.
- **BR-123** — Only a Super Admin may create, assign or change accounts whose role uses the HR or Admin portal.
- **BR-124** — A non-Super-Admin may assign a role only if they hold every permission that role has.
- **BR-125** — Nobody may edit the permissions of their own role. A non-Super-Admin can neither grant nor remove a permission they do not hold; such permissions are preserved on save.
- **BR-126** — A user cannot change their own role or employee link from this screen. A role change signs the account out everywhere (role is held in the session).
- **BR-127** — Role creation, role permission changes (added/removed codes), user role changes and employee link/unlink are written to `audit_logs`.

| ID | Requirement | Priority |
|---|---|---|
| FR-143 | The system shall let HR/Admin create roles and set their permissions from the UI, subject to BR-122–BR-125. | Must |
| FR-144 | The system shall let HR/Admin change an existing user's role from the UI, subject to BR-122–BR-126. | Must |
| FR-145 | The system shall let HR/Admin link or unlink a user and an employee from the user screen, rejecting archived employees and employees linked to another account. | Must |
| FR-146 | The system shall warn about active non-client accounts without an employee link. | Should |
| FR-147 | The system shall audit role creation, role permission changes, user role changes and employee links. (BR-127) | Must |

Roles are still never deleted (no delete action exists), so a role in use cannot be removed.

## Open Questions

- [ ] Do all employees get accounts, or only office staff? → Q15 in [[Open-Questions]]
- [ ] Is MFA realistic for payroll and admin roles here?
- [ ] Given a small team, can separation of duties between compute and release actually be staffed? If not, record the accepted risk.
- [ ] Is an external auditor role needed?
- [ ] Idle and absolute session timeouts per role?
- [ ] Is SSO or directory integration ever likely? (affects whether to abstract the identity provider now)
- [ ] Which sensitive-data *reads* are logged, not just writes? → [[Security-and-Compliance]]

## Related

[[Employee-Records]] · [[Org-Structure]] · [[Payroll]] · [[Leave-Management]] · [[Attendance-and-DTR]] · [[Self-Service-Portal]] · [[Security-and-Compliance]] · [[Personas]] · [[Data-Model]] · [[API-Surface]] · [[Screens]] · [[Offboarding-and-Clearance]] · [[Business-Rules]]
