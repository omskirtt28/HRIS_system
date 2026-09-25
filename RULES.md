# HRIS Engineering Rules

**Status:** Mandatory baseline v1

These rules apply to all HRIS modules, starting with Recruitment.

## 1. Architecture Rules

1. Build by domain module, not by random page.
2. Shared concerns belong to HRIS Core.
3. Recruitment must not use HR Service Desk ticket tables as applicant/application tables.
4. Applicant and Employee are separate entities.
5. A user account is separate from Employee and Applicant identities.
6. Modules reference organization masters by ID; they do not duplicate master data.
7. Business rules live on the server, never only in JavaScript UI.
8. Keep route/controller logic thin; business behavior belongs in services/domain policies.
9. Use transactions for multi-record business actions.
10. Every module must have explicit permissions and scope rules.

## 2. Naming Rules

Database:
- table names: `snake_case`, plural;
- primary key: `id`;
- foreign key: `<entity>_id`;
- timestamps: `created_at`, `updated_at`;
- actor fields: `created_by`, `updated_by` where appropriate;
- status/stage codes: uppercase stable codes in application logic, normalized values in DB;
- external human-readable references: unique `reference_no` or domain-specific reference field.

Code:
- services describe business capability;
- routes describe resources;
- avoid files named `helpers2`, `misc`, `new`, `final`, `temp`, or similar.

## 3. Database Rules

- MySQL 8.x is the source of truth.
- `database/schema.sql` is the initial clean-install bootstrap for v1.
- After the first deployment, all schema changes use numbered migrations.
- Never edit a migration that has already been deployed.
- Add a new migration for every post-v1 schema change.
- Use foreign keys for real relationships.
- Add indexes for foreign keys and frequent filters.
- Do not use JSON as a substitute for normal relational design when fields are known and queried.
- Store JSON only for flexible snapshots/configuration where justified.
- Use MySQL `DATETIME`/`TIMESTAMP` consistently for business and audit timestamps; application timezone defaults to Asia/Manila.
- Use the configured application timezone consistently; default Asia/Manila. If production standardizes on UTC storage later, convert only at the application boundary.
- Never hard-delete audit logs or stage history.

## 4. Recruitment Domain Rules

- One applicant may have multiple applications.
- One application belongs to one job opening.
- Current application stage must have matching stage history.
- Stage changes use a controlled transition service.
- Client Review can occur only after endorsement.
- Client users can see only applications endorsed to their client.
- Offer status is a separate business record.
- Deployment status is a separate business record.
- Employee creation/conversion requires an explicit successful hiring/deployment action.
- Rejected or withdrawn applicants remain historically reportable subject to retention/privacy rules.
- Duplicate applicant detection must not silently merge records.

## 5. Security Rules

- Authentication is centralized.
- Authorization is server-side.
- Use permission codes, not UI role-name checks.
- Every record read must apply scope.
- Never trust IDs supplied by the browser without authorization checks.
- Use parameterized SQL only.
- Hash passwords with approved password hashing.
- Use secure PHP server-side sessions; regenerate session IDs on login and after privilege changes.
- Revoke sessions after sensitive account/role/scope changes.
- Rate-limit authentication and public application endpoints.
- Use CSRF protection when cookie-based mutation flows are introduced.
- Protect uploaded files against direct unauthenticated access.
- Do not log passwords, tokens, full government IDs, or sensitive document contents.

## 6. Privacy & Recruitment Data

Applicant data is confidential HR information.

Apply data minimization:
- Client users receive only fields required to assess endorsed candidates.
- Internal notes are never visible to applicants or clients unless explicitly designed as shared communication.
- Government IDs and pre-employment documents require stricter visibility permissions.
- Reports should aggregate where detailed identity is unnecessary.
- Export actions must be permission-gated and audited.

Data retention rules must be configurable before production deployment.

## 7. API Rules

- API version prefix when production API stabilizes, e.g. `/api/v1`.
- Use resource-oriented endpoints.
- Validate body, params, and query values.
- Return consistent error shapes.
- Do not leak stack traces or SQL errors to clients.
- Use pagination for collection endpoints.
- Use idempotency controls for externally repeatable create/submit operations where duplication is harmful.
- Use optimistic locking/version checks for conflict-prone records where appropriate.

## 8. Audit Rules

Audit at minimum:
- login/security events;
- account/role/permission changes;
- applicant creation/update of sensitive fields;
- application stage changes;
- interview decisions;
- endorsement;
- client review decision;
- offer generation/send/decision;
- deployment completion;
- employee conversion;
- document upload/delete/version changes;
- exports;
- integration configuration changes.

Audit must capture actor, action, module, record identifier, timestamp, result, and relevant old/new values without copying unnecessary secrets/PII.

## 9. Notification Rules

- Business service emits events; notification service decides channels/templates.
- Do not send email directly from Recruitment service files.
- Notification failure must not roll back a valid completed business transaction.
- Retry delivery through queue/worker.
- Deduplicate events with an idempotent event key.
- Deep links must re-check authorization when opened.

## 10. Frontend Rules

- No business-critical hardcoded sample data in production.
- No fake functional controls.
- All destructive actions require confirmation.
- Every save action shows success/error state.
- Do not silently discard form data on validation error.
- Use server data for permissions and allowed actions.
- Hide unavailable actions for UX, but still enforce them server-side.
- Keep English labels consistent with `DESIGN.md`.

## 11. AI Rules

If AI is enabled for resume parsing, candidate matching, screening assistance, summaries, or drafting:
- AI output is advisory;
- no automatic final rejection or hiring decision solely from an AI score;
- store model/version and relevant metadata for traceability when AI output affects workflow;
- authorized HR users must be able to review/override;
- sensitive data sent to external AI providers requires approved privacy/security configuration;
- never represent generated demo scores as factual evaluation results.

## 12. Testing Rules

Minimum test coverage by module:
- permissions;
- scope isolation;
- validation;
- state transitions;
- transactions/rollback;
- duplicate/idempotency behavior;
- audit creation;
- notification event emission;
- report scoping;
- critical end-to-end workflows.

Recruitment critical path:

```text
Create manpower request
-> approve/open job
-> submit application
-> screen
-> interview
-> endorse
-> client decision
-> offer
-> deployment
-> employee conversion
```

## 13. Git / Delivery Rules

- Keep secrets out of Git.
- `.env` is never committed.
- Real applicant/employee exports are never committed.
- New schema requires migration in the same change.
- New permissions require seed/config updates and tests.
- New user-facing workflow requires documentation update.
- Code must pass tests before deployment.
- Production deployment must not contain debug accounts, sample applicants, or localhost-only configuration.

## 14. Definition of Done

A Recruitment feature is not complete until:
- UI works;
- API works;
- validation exists;
- permission exists;
- scope is enforced;
- database migration is present if needed;
- audit is present where required;
- tests pass;
- errors are handled;
- empty/loading states exist;
- docs are updated if architecture/schema/rules changed.

---

## 2026-09-24 - UI/UX and Role Rules

1. Every authenticated page must use the shared HRIS design system.
2. Employee, HR, HR Admin, and Client users must receive role-appropriate navigation and dashboards.
3. Authorization must be enforced in PHP on every protected route; UI visibility alone is never sufficient.
4. New modules must be responsive and use the established modern components before they are considered complete.
5. Avoid hard-coded localhost links. Local testing is expected at `http://localhost:3000`, but URLs must be generated through application helpers/config.
6. `public/meeting-preview.html` may contain sample presentation data and must never be used as a production data source.

## 13. Phase 1 RBAC and Master-Data Rules

- New protected functions require a stable permission code.
- Use `Auth::can('permission.code')` for UI visibility and `Auth::requirePermission('permission.code')` on the server for protected actions/pages.
- Do not authorize by checking whether a sidebar item is visible.
- `SUPER_ADMIN` is an emergency/system administration role and may bypass permission rows; other roles must follow assigned permission records.
- Custom roles must declare a portal (`employee`, `hr`, `admin`, or `client`) and receive explicit permissions before use.
- Account creation must use `password_hash()` and never store a temporary password in logs.
- Account deactivation must revoke active tracked sessions.
- A logged-in administrator may not deactivate their own account from the normal Users UI.
- Organization masters are shared HRIS Core data. Recruitment and future modules must reference the same Department, Position, Branch and Employment Type records.
- Master records should normally be made inactive instead of deleted when historical records may reference them.
- Permission, role, user-status and organization-master changes must be audited.
- New write forms must include CSRF protection and use prepared SQL statements.


## Branding Rule
Use only the approved PMBSI assets in `public/assets/branding/` for system identity. The full logo is used on public/login surfaces and browser favicon/app icons. The compact mark may still be used only in narrow sidebar/compact UI contexts. Text-only placeholder logos must not be reintroduced.

- Public website/navigation branding must display the full PMBSI wordmark. Do not replace the public header with the compact mark. Use the full PMBSI wordmark for favicon and app-icon branding. The compact mark is reserved only for narrow UI contexts where needed.


## Employee Master Rules

- `employee_no` is the stable human-readable employee identifier and must remain unique.
- One Employee portal user may link to at most one employee record.
- Department, position, branch, and employment type are master-data references; do not hard-code labels into employee records.
- Position/department assignments must be consistent when both are selected.
- Employee creation requires `employees.manage` and must be written to the audit log.
- Do not duplicate identity data when future Attendance, Leave, Performance, Training, Documents, or Offboarding modules are added; reference `employees.id`.
- Recruitment → Employee conversion will be added in a later Phase 2 step and must create/link this same employee master record rather than a parallel table.


## Phase 2B Employee 201 File Rules

- `employees.id` is the permanent parent identity for all 201 File child records.
- Personal/employment edits require `employees.manage` and must create an audit log entry.
- Department, position, branch, employment type, and status changes must create an `employee_employment_history` event; do not overwrite history.
- One Employee portal user may link to only one employee record; unlinking is allowed by authorized HR users.
- Employee documents must be stored outside the public directory and accessed through authenticated/authorized routes only.
- Uploads are allow-listed by extension/MIME and size; filenames stored on disk must be randomized.
- Profile photos accept JPG, PNG, or WEBP only and must not be placed in a public upload directory.
- A Government ID type is unique per employee and may be updated by saving the same type again.
- Deleting a 201 File child record is an audited HR action.


## Password Administration Rule
- Passwords must remain one-way hashed using PHP `password_hash`; the system must never expose or persist recoverable/plaintext passwords.
- Super Admin may reset another user to a new temporary password. Password resets must be audited and active sessions for the target account must be revoked.
