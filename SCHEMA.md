# HRIS Database Schema Baseline

**Status:** Implementation baseline v1  
**Database:** MySQL 8.0+

This schema separates shared HRIS Core data from Recruitment-owned data. The executable clean-install schema is `database/schema.sql`; this document is the human-readable contract for the same domain and its planned extensions. Existing HR Service Desk concepts may be reused only when their responsibility matches this model.

## 1. Core Identity & Organization

Existing reusable concepts from the supplied HR Service Desk backend:

### `users`
Login account only.

Key fields:
- `id`
- `email`
- `password_hash`
- `status`
- `employee_id` nullable
- `last_login_at`
- `created_at`
- `updated_at`

### `roles`
- `id`
- `code` unique
- `name`
- `is_system`

### `permissions`
- `id`
- `code` unique
- `name`
- `module`

### `user_roles`
- `user_id`
- `role_id`

Unique: `(user_id, role_id)`

### `role_permissions`
- `role_id`
- `permission_id`

### `user_scopes`
Defines client/branch/organization scope.

### `clients`
External client/employer records used by manpower/recruitment operations.

### `branches`
PMBSI branches or client sites depending on type/ownership.

### `departments`
Shared department master.

### `positions`
Shared position master. Recruitment job openings may reference this but can keep a historical title snapshot.

### `employees`
Employee Master. Created/linked only after successful hire/deployment conversion.

## 2. Recruitment Configuration

### `recruitment_stages`
Configurable display/order for controlled application stages.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| code | varchar unique | stable: `APPLIED`, `SCREENING`, etc. |
| name | varchar | display label |
| sequence_no | int | pipeline order |
| stage_type | varchar | NORMAL / TERMINAL_SUCCESS / TERMINAL_FAIL / HOLD |
| client_visible | boolean | |
| applicant_visible | boolean | |
| active | boolean | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Canonical initial codes:
`APPLIED`, `SCREENING`, `INTERVIEW`, `ENDORSED`, `CLIENT_REVIEW`, `OFFER`, `DEPLOYMENT`, `DEPLOYED`, `REJECTED`, `WITHDRAWN`, `ON_HOLD`, `RETURNED`.

### `recruitment_rejection_reasons`
Controlled rejection/withdrawal reasons.

### `recruitment_sources`
Examples: Careers Site, JobStreet, Referral, Walk-in, Social Media, Agency Pool.

### `interview_types`
Examples: Phone Screening, Initial Interview, Final Interview, Client Interview, Technical Interview.

## 3. Manpower Requests

### `manpower_requests`
Represents approved demand for personnel.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| request_no | varchar unique | human-readable reference |
| client_id | bigint FK clients | nullable only for internal hiring if supported |
| branch_id | bigint FK branches | work/site location |
| department_id | bigint FK departments | |
| position_id | bigint FK positions | |
| position_title | varchar | historical snapshot |
| requested_headcount | int | > 0 |
| filled_headcount | int | derived or maintained safely |
| employment_type | varchar | FULL_TIME / CONTRACT / etc. |
| priority | varchar | NORMAL / URGENT |
| target_start_date | date | nullable |
| status | varchar | DRAFT / SUBMITTED / APPROVED / OPEN / PARTIALLY_FILLED / FILLED / CLOSED / CANCELLED |
| requested_by | bigint FK users | |
| approved_by | bigint FK users | nullable |
| approved_at | DATETIME | nullable |
| notes | text | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Indexes:
- `(client_id, status)`
- `(branch_id, status)`
- `(position_id, status)`
- `(requested_by, created_at desc)`

## 4. Job Openings

### `job_openings`
Public/internal posting connected to a manpower request.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| manpower_request_id | bigint FK | |
| job_code | varchar unique | |
| slug | varchar unique | public URL |
| title | varchar | |
| description | text | |
| requirements | text | or structured child table later |
| location_text | varchar | display snapshot |
| employment_type | varchar | |
| salary_min | numeric | nullable |
| salary_max | numeric | nullable |
| currency | char(3) | default PHP |
| openings | int | |
| status | varchar | DRAFT / PUBLISHED / PAUSED / CLOSED |
| published_at | DATETIME | nullable |
| closes_at | DATETIME | nullable |
| created_by | bigint FK users | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

## 5. Applicant Master

### `applicants`
One person record independent of a specific job application.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| applicant_no | varchar unique | e.g. APP-2026-000001 |
| user_id | bigint FK users | nullable; only when applicant portal account exists |
| first_name | varchar | |
| middle_name | varchar | nullable |
| last_name | varchar | |
| suffix | varchar | nullable |
| preferred_name | varchar | nullable |
| email | VARCHAR(190) with normalized/LOWER() lookup | |
| mobile_no | varchar | |
| birth_date | date | only if business/legal need is approved |
| city | varchar | nullable |
| province | varchar | nullable |
| country_code | char(2) | default PH |
| source_id | bigint FK recruitment_sources | nullable |
| consent_at | DATETIME | privacy consent |
| status | varchar | ACTIVE / ARCHIVED / BLOCKED |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Indexes should support normalized email/mobile duplicate review, but records must not be auto-merged.

## 6. Applications

### `applications`
One applicant applying to one job opening.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_no | varchar unique | public tracking reference |
| applicant_id | bigint FK applicants | |
| job_opening_id | bigint FK job_openings | |
| manpower_request_id | bigint FK manpower_requests | denormalized/reference for reporting |
| client_id | bigint FK clients | scope/reporting snapshot |
| current_stage_id | bigint FK recruitment_stages | |
| assigned_recruiter_id | bigint FK users | nullable |
| screening_score | numeric | nullable, advisory only |
| applied_at | DATETIME | |
| last_stage_changed_at | DATETIME | |
| status | varchar | ACTIVE / CLOSED |
| rejection_reason_id | bigint FK | nullable |
| withdrawn_at | DATETIME | nullable |
| version | int | optimistic locking |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Recommended duplicate policy:
- prevent accidental duplicate active application to the exact same job unless authorized override;
- allow the same applicant to apply to different jobs.

Indexes:
- `(current_stage_id, last_stage_changed_at)`
- `(assigned_recruiter_id, current_stage_id)`
- `(client_id, current_stage_id)`
- `(job_opening_id, current_stage_id)`
- `(applicant_id, applied_at desc)`

### `application_stage_history`
Immutable history of every stage movement.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK | |
| from_stage_id | bigint FK | nullable for first stage |
| to_stage_id | bigint FK | |
| reason_code | varchar | nullable |
| comment | text | nullable |
| changed_by | bigint FK users | nullable for public/system event |
| changed_at | DATETIME | |

No UPDATE/DELETE after insertion except controlled legal/privacy operations approved by policy.

## 7. Screening

### `screening_reviews`
- `id`
- `application_id`
- `reviewer_id`
- `recommendation` — PROCEED / HOLD / REJECT
- `score` nullable
- `summary`
- `completed_at`
- timestamps

If AI is used, keep AI output in a separate traceable field/table rather than overwriting human review.

### `application_ai_assessments` optional
- `id`
- `application_id`
- `assessment_type`
- `provider`
- `model`
- `model_version`
- `score` nullable
- `result_json`
- `created_at`
- `reviewed_by` nullable
- `reviewed_at` nullable

## 8. Interviews

### `interviews`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK | |
| interview_type_id | bigint FK | |
| scheduled_start | DATETIME | |
| scheduled_end | DATETIME | |
| timezone | varchar | default Asia/Manila display context |
| mode | varchar | ONSITE / PHONE / VIDEO |
| location_or_link | text | protected as appropriate |
| status | varchar | SCHEDULED / COMPLETED / CANCELLED / NO_SHOW / RESCHEDULED |
| scheduled_by | bigint FK users | |
| completed_at | DATETIME | nullable |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### `interview_panelists`
- `interview_id`
- `user_id`
- `role` nullable

### `interview_feedback`
- `id`
- `interview_id`
- `reviewer_id`
- `rating` nullable
- `recommendation`
- `comments`
- `submitted_at`

Unique reviewer submission per interview unless versioning is intentionally supported.

## 9. Endorsements & Client Review

### `endorsements`
Creates a formal client-visible candidate submission.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK | |
| client_id | bigint FK | |
| endorsement_no | varchar unique | |
| endorsed_by | bigint FK users | |
| endorsed_at | DATETIME | |
| summary | text | |
| snapshot_json | JSON | approved client-visible snapshot at send time |
| status | varchar | SENT / RETURNED / CLOSED |

### `client_reviews`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| endorsement_id | bigint FK | |
| reviewer_user_id | bigint FK users | |
| decision | varchar | APPROVE / DECLINE / REQUEST_INTERVIEW / RETURN |
| comments | text | nullable |
| decided_at | DATETIME | |
| created_at | DATETIME | |

Client scope must match `endorsements.client_id`.

## 10. Offers

### `offers`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK | |
| offer_no | varchar unique | |
| version_no | int | |
| employment_type | varchar | |
| salary_amount | numeric | nullable |
| currency | char(3) | |
| proposed_start_date | date | nullable |
| status | varchar | DRAFT / APPROVED / SENT / ACCEPTED / DECLINED / EXPIRED / WITHDRAWN |
| valid_until | DATETIME | nullable |
| sent_at | DATETIME | nullable |
| decided_at | DATETIME | nullable |
| created_by | bigint FK users | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

Use child/version records if offer revision complexity grows. Never overwrite accepted historical terms without history.

## 11. Recruitment Documents

Use the shared `documents` service/table with a link table rather than storing binary blobs directly in Recruitment.

### `application_documents`
- `id`
- `application_id`
- `document_id`
- `document_category` — RESUME / ID / CLEARANCE / MEDICAL / CONTRACT / OTHER
- `visibility` — INTERNAL / CLIENT_ALLOWED / APPLICANT
- `requirement_status` nullable
- `created_at`

## 12. Deployment

### `deployments`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK unique for active deployment | |
| client_id | bigint FK | |
| branch_id | bigint FK | deployment site |
| offer_id | bigint FK | accepted offer |
| target_date | date | |
| actual_deployment_date | date | nullable |
| status | varchar | PREPARING / READY / DEPLOYED / CANCELLED |
| coordinator_id | bigint FK users | nullable |
| notes | text | nullable |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### `deployment_requirements`
- `id`
- `deployment_id`
- `requirement_code`
- `requirement_name`
- `status` — MISSING / SUBMITTED / VERIFIED / WAIVED
- `document_id` nullable
- `verified_by` nullable
- `verified_at` nullable
- timestamps

## 13. Employee Conversion

### `recruitment_employee_conversions`
Provides traceability between Recruitment and Employee Master.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| application_id | bigint FK unique | |
| deployment_id | bigint FK | |
| employee_id | bigint FK employees unique | |
| converted_by | bigint FK users | |
| converted_at | DATETIME | |

The Employee Master owns the employee record after conversion. Recruitment keeps the historical link.

## 14. Notes & Activity

### `application_notes`
Internal notes only unless explicit visibility is added.

- `id`
- `application_id`
- `author_user_id`
- `note_text`
- `visibility` — INTERNAL / RECRUITMENT_MANAGER
- `created_at`
- `updated_at` only if edit policy allows; edits must be auditable

Business events should not be duplicated as notes. Use stage history/audit/activity records.

## 15. Suggested Permission Codes

```text
recruitment.dashboard.view
recruitment.manpower_requests.view
recruitment.manpower_requests.create
recruitment.manpower_requests.edit
recruitment.manpower_requests.approve
recruitment.jobs.view
recruitment.jobs.create
recruitment.jobs.edit
recruitment.jobs.publish
recruitment.applicants.view
recruitment.applicants.create
recruitment.applicants.edit
recruitment.applications.view
recruitment.applications.assign
recruitment.applications.transition
recruitment.interviews.view
recruitment.interviews.schedule
recruitment.interviews.feedback
recruitment.endorsements.create
recruitment.client_reviews.decide
recruitment.offers.create
recruitment.offers.approve
recruitment.offers.send
recruitment.deployments.manage
recruitment.employee_conversion.execute
recruitment.reports.view
recruitment.reports.export
recruitment.config.manage
```

## 16. Relationship Summary

```text
clients 1---* manpower_requests *---1 positions
manpower_requests 1---* job_openings

applicants 1---* applications *---1 job_openings
applications *---1 recruitment_stages
applications 1---* application_stage_history
applications 1---* screening_reviews
applications 1---* interviews 1---* interview_feedback
applications 1---* endorsements 1---* client_reviews
applications 1---* offers
applications 1---0..1 active deployment
applications 1---* application_documents *---1 documents

deployments 1---* deployment_requirements
applications 1---0..1 recruitment_employee_conversions ---1 employees
```

## 17. Important Constraints

- `requested_headcount > 0`
- `openings > 0`
- accepted/deployed states require corresponding business records, not only stage text
- client review must reference an endorsement to the same client
- deployment must reference an accepted offer unless an authorized exception workflow is implemented
- application current stage changes only through the transition service
- employee conversion is unique per application and employee
- audit/stage history is append-only
- all public references are unique and non-sequential exposure should be considered for public tracking

## 18. Tables Not to Reuse as Recruitment Domain Tables

Do **not** repurpose these HR Service Desk concepts as Recruitment entities:
- `tickets`
- `ticket_messages`
- `ticket_status_history`
- `ticket_assignments`

Their generic workflow capabilities may inspire reusable services, but Recruitment requires its own domain schema above.

---

## 2026-09-24 - Employee Portal Role

The `roles` reference data includes `EMPLOYEE` with portal value `employee`.

Existing local databases should run:

`database/migrations/20260924_role_based_ui.sql`

This migration safely inserts the Employee role and the local demo account used for UI review if they do not already exist. Future Employee Management tables will be added separately so the recruitment schema remains backward-compatible during phased HRIS development.

## 14. Phase 1 Foundation — Implemented Tables

The executable schema currently uses **one primary role per user** through `users.role_id`. Earlier planning references to `user_roles` are future options, not current executable tables.

### `permissions`

- `id`
- `code` unique stable permission identifier
- `name`
- `module`
- `description`
- `sort_order`
- `created_at`

Examples: `dashboard.hr.view`, `recruitment.manage`, `organization.manage`, `users.manage`, `audit.view`.

### `role_permissions`

Composite primary key: `(role_id, permission_id)`.

Connects each role to its allowed function codes.

### `positions`

- `id`
- `department_id` nullable FK → `departments.id`
- `code` unique
- `name`
- `active`
- timestamps

This becomes the shared position master for Employee Master and Recruitment references.

### `employment_types`

- `id`
- `code` unique
- `name`
- `active`
- `sort_order`
- timestamps

Seeded values include Regular, Probationary, Contractual, Project-Based, Fixed-Term, Part-Time and Intern.

### `user_sessions`

- `id`
- `user_id` FK → `users.id`
- `session_token_hash` unique
- `ip_address`
- `user_agent`
- `created_at`
- `last_activity_at`
- `revoked_at`

Only a hash of the generated authentication-session token is persisted. `revoked_at IS NULL` identifies a session that has not been server-revoked.

### Current role model

`roles.portal` determines the primary experience:

- `employee`
- `hr`
- `admin`
- `client`

Current system roles include `EMPLOYEE`, `HR_USER`, Recruitment roles, `HRIS_ADMIN`, `SUPER_ADMIN`, and `CLIENT_USER`.
