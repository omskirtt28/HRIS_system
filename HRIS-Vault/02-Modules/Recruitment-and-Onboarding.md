---
title: Recruitment and Onboarding
type: module
status: draft
tags: [module, hris, v1]
updated: 2026-10-24
---

# Recruitment and Onboarding

## Purpose

Move a person from applicant to productive employee without re-typing their data or losing track of what has been done. Two halves with a hard boundary between them:

- **Recruitment** — requisition to offer acceptance. The subject is an **applicant**, not an employee.
- **Onboarding** — offer acceptance to day-30. The subject is now an **employee**, and the job is a checklist.

The boundary is the conversion event, and it is the module's most important feature: hiring is where HR currently re-keys an entire 201 file from a résumé.

> [!note] Partially built already
> The existing codebase has a working recruitment pipeline with configurable stages. Treat this note as the spec to reconcile against, not a greenfield build. The gap worth closing first is conversion (US-802) and the onboarding checklist (US-803).

## Scope

### In scope

- Job requisitions with approval
- Job postings and application intake
- Applicant records and documents
- Configurable pipeline stages with terminal outcomes
- Interview scheduling and evaluation capture
- Offer management and acceptance
- Applicant-to-employee conversion
- Onboarding checklists with owners, due dates and dependencies
- Probationary period tracking handing over to [[Employee-Records]]
- Recruitment metrics: time-to-fill, pipeline conversion, source effectiveness

### Out of scope

- Employee data after conversion → [[Employee-Records]]
- Account creation mechanics → [[Auth-and-RBAC]]
- Payroll enrolment → [[Payroll]]
- Performance review of the new hire → [[Performance-Management]]
- External job-board integrations → later

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `job_requisitions` | Approved authority to hire. | `id`, `position_id`, `department_id`, `branch_id`, `headcount`, `employment_status_id`, `salary_range_min`, `salary_range_max`, `justification`, `status` (`DRAFT`/`PENDING`/`APPROVED`/`REJECTED`/`FILLED`/`CANCELLED`), `requested_by`, `approved_by`, `target_start_date` |
| `job_postings` | A published advertisement for a requisition. | `requisition_id`, `title_snapshot`, `description`, `posted_at`, `closes_at`, `channels`, `is_internal` |
| `applicants` | The person applying. **Never an employee row.** | `id`, `last_name`, `first_name`, `middle_name`, `email`, `mobile`, `birth_date`, `address`, `source`, `resume_path`, `consent_given_at`, `consent_expires_at` |
| `applications` | One applicant against one posting. | `id`, `applicant_id`, `job_posting_id`, `current_stage_id`, `status`, `applied_at`, `rejection_reason`, `rating` |
| `recruitment_stages` | Configurable pipeline. | `code`, `name`, `sequence_no`, `stage_type` (`NORMAL`/`TERMINAL_SUCCESS`/`TERMINAL_FAIL`/`HOLD`), `applicant_visible`, `active` |
| `application_stage_history` | Dated stage movement. | `application_id`, `from_stage_id`, `to_stage_id`, `moved_by`, `moved_at`, `remarks` |
| `interviews` | Scheduled interviews. | `application_id`, `round_no`, `interviewer_employee_id`, `scheduled_at`, `mode`, `status` |
| `interview_evaluations` | Scored feedback. | `interview_id`, `criteria_json`, `overall_score`, `recommendation`, `comments`, `submitted_at` |
| `job_offers` | The offer and its outcome. | `application_id`, `offered_position_id`, `offered_salary`, `offered_allowances_json`, `proposed_start_date`, `status` (`DRAFT`/`SENT`/`ACCEPTED`/`DECLINED`/`EXPIRED`/`WITHDRAWN`), `sent_at`, `responded_at`, `approved_by` |
| `onboarding_templates` | Reusable checklist definitions per position or department. | `id`, `name`, `position_id`, `department_id`, `active` |
| `onboarding_template_items` | The tasks in a template. | `template_id`, `sequence_no`, `task_name`, `owner_role`, `due_offset_days`, `is_blocking`, `category` |
| `onboarding_checklists` | An instance for one new hire. | `id`, `employee_id`, `template_id`, `started_at`, `target_complete_at`, `status` |
| `onboarding_tasks` | Instantiated tasks. | `checklist_id`, `task_name`, `owner_employee_id`, `due_on`, `completed_at`, `completed_by`, `status`, `remarks` |

## Core Flows

### Flow 1 — Requisition to posting

1. A department head raises a requisition citing position, headcount, employment status, salary range and justification.
2. Routes for approval; the chain depends on headcount and salary range.
3. On approval, HR creates a posting from the requisition, snapshotting the title so a later position rename does not rewrite history.
4. Posting is published internally, externally, or both.

### Flow 2 — Application intake and pipeline

1. An application arrives via form, email or manual entry by HR.
2. System checks for an existing applicant by email or mobile and offers to link rather than duplicate.
3. **Data privacy consent is captured at intake**, with a retention expiry. An applicant record without consent is a compliance problem — see [[Security-and-Compliance]].
4. Application enters the first stage.
5. HR moves it through stages; every move writes `application_stage_history` with actor, timestamp and remarks.
6. A terminal-fail stage requires a rejection reason, which feeds the metrics and any applicant communication.

### Flow 3 — Interview and evaluation

1. HR schedules an interview round, assigning interviewer employees.
2. Interviewers are notified with the applicant's documents attached, scoped so they see only what the round requires.
3. After the interview the evaluation is submitted against defined criteria with a recommendation.
4. Advancing past a round that requires an evaluation is blocked until all evaluations are submitted — otherwise decisions get made verbally and the record is empty.

### Flow 4 — Offer

1. HR drafts an offer with position, salary and allowances. Salary outside the requisition range requires an explicit override and an approver.
2. Offer is approved, then sent, with an expiry.
3. Applicant accepts or declines; the response and timestamp are recorded.
4. On acceptance the application moves to a terminal-success stage, which **enables** conversion. Conversion is deliberately a separate, explicit action.

### Flow 5 — Convert applicant to employee

The feature that pays for the module.

1. HR triggers **Convert to employee** on an accepted application.
2. System pre-fills a draft employee form from the applicant and offer: names, birth date, contact, address, position, department, branch, employment status, salary, start date.
3. HR reviews and completes what recruitment does not hold: government IDs, dependents, bank account, schedule.
4. On confirm, in one transaction: a new `employees` row with `source_application_id`; initial status, placement and compensation history rows; the requisition's filled count incremented; and an onboarding checklist instantiated from the matching template.
5. **The applicant record is neither deleted nor mutated.** It remains the recruitment-side history.
6. Optionally a user account is created and the `EMPLOYEE` role granted.

### Flow 6 — Onboarding checklist

1. The checklist instantiates with each task's due date computed from the start date plus the template's offset.
2. Tasks are owned by role — HR, IT, the hiring manager, the employee themselves — and resolved to actual people.
3. Owners are notified; overdue tasks escalate. See [[Notifications]].
4. `is_blocking` tasks — signed contract, government IDs captured, bank account recorded — gate payroll readiness. An unblocked checklist is how a new hire ends up unpaid or incorrectly paid.
5. On completion the checklist closes, and probation tracking begins in [[Employee-Records]].

## Business Rules

- **BR-801** — An applicant and an employee are separate entities. Conversion creates a new employee row and never mutates the applicant.
- **BR-802** — An application may only be converted from a terminal-success stage with an accepted offer.
- **BR-803** — Applicant personal data is retained only for the consented retention period, after which it is anonymised or deleted. See [[Security-and-Compliance]].
- **BR-804** — A stage move to a terminal-fail stage requires a rejection reason.
- **BR-805** — An offer above the requisition's approved salary range requires an explicit override with an approver recorded.
- **BR-806** — Blocking onboarding tasks must be complete before the employee is payroll-ready, in addition to BR-202.
- **BR-807** — A requisition's filled count may not exceed its approved headcount without re-approval.
- **BR-808** — Interviewers see only the applicant data their round requires, not the full file.

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-801 | The system shall maintain job requisitions with an approval workflow and headcount tracking. | Should |
| FR-802 | The system shall maintain job postings linked to requisitions, snapshotting the position title. | Should |
| FR-803 | The system shall maintain applicant records separate from employee records. (BR-801) | Must |
| FR-804 | The system shall detect a likely duplicate applicant by email or mobile and offer linking. | Should |
| FR-805 | The system shall capture data privacy consent at application intake with a retention expiry. (BR-803) | Must |
| FR-806 | The system shall move applications through configurable stages, recording every move with actor, timestamp and remarks. (US-801) | Should |
| FR-807 | The system shall require a rejection reason on a terminal-fail stage move. (BR-804) | Should |
| FR-808 | The system shall schedule interviews and capture structured evaluations with a recommendation. | Should |
| FR-809 | The system shall block advancement past a round until required evaluations are submitted. | Could |
| FR-810 | The system shall manage offers with approval, expiry and recorded response. | Should |
| FR-811 | The system shall require an override with an approver for an offer outside the requisition salary range. (BR-805) | Should |
| FR-812 | The system shall convert an accepted application into a new employee record, pre-filled from applicant and offer data, without mutating the applicant record. (US-802, BR-801) | Must |
| FR-813 | The system shall instantiate an onboarding checklist from a template on conversion. (US-803) | Should |
| FR-814 | The system shall compute onboarding task due dates from the start date and the template offset. | Should |
| FR-815 | The system shall resolve role-owned onboarding tasks to actual assignees and notify them. | Should |
| FR-816 | The system shall treat blocking onboarding tasks as a precondition to payroll readiness. (BR-806) | Should |
| FR-817 | The system shall restrict interviewer access to only the applicant data their round requires. (BR-808) | Should |
| FR-818 | The system shall anonymise or delete applicant data when the consented retention period expires. (BR-803) | Must |
| FR-819 | The system shall report time-to-fill, stage conversion rates and source effectiveness. | Could |
| FR-820 | The system shall audit all stage moves, offer changes and conversions. | Must |

## Edge Cases

| Case | Expected handling |
|---|---|
| Applicant applies to two postings | One applicant, two applications. Do not duplicate the person. |
| Rehired former employee applies | Links to the previous employee record via `previous_employee_id` on conversion; a new employee row is still created. |
| Applicant accepts then does not show up on day one | Offer accepted but conversion not completed, or employee created then immediately separated with reason `NO_SHOW`. Pick one and be consistent; the second keeps better metrics. |
| Offer declined after the requisition was marked filled | Decrement the filled count and reopen the requisition. |
| Two applicants hired against a single-headcount requisition | BR-807 blocks it; requires re-approval. |
| Internal applicant already an employee | Allowed; the application links to the existing employee, and conversion becomes a transfer rather than a new employee row. Distinct flow — specify it before building. |
| Applicant withdraws consent | Honour it: anonymise the record and stop processing. RA 10173 obligation, not a courtesy. |
| Start date moves after conversion | Recompute onboarding due dates and the compensation effectivity date. |
| Government IDs not yet available on day one | Expected. Blocking task stays open; employee is not payroll-ready until it closes. |
| Conversion attempted twice | Idempotency guard on `source_application_id`. |
| Interviewer separates mid-process | Reassign the round; the submitted evaluation stays attributed to them. |

## As built: two separate "Manpower" requests (2026-10-24)

- **Recruitment manpower request** (`manpower_requests`, Recruitment module): unchanged.
- **HR ticket "Manpower Request"** (`ticket_manpower_requests`, Slice-15 ticket engine): since migration 010 it is filed by roles holding `ticket.file.manpower` (ADL, SC, OIC, TC, Manager, HR) and routed **HR_ONLY** — no Manager/ADL approval step; HR sets the status and Served records the date. Open tickets filed under the old chain keep their frozen steps. The two are not linked; see [[Self-Service-Portal]] for the filing matrix.

## As built: recruitment domain rules (moved from the retired root RULES.md and ARCHITECTURE.md, 2026-10-25)

- One applicant can have many applications; one application belongs to one job opening. The current stage must have matching stage history; stage changes go through the transition service only and invalid jumps are rejected server-side. Client Review is possible only after endorsement; client users see only applications endorsed to their client, with field minimisation. Offer and deployment are separate records; an accepted offer is required before normal deployment. Duplicate applicants are flagged, never silently merged. Rejected or withdrawn applicants stay reportable subject to retention.
- Canonical stages: APPLIED, SCREENING, INTERVIEW, ENDORSED, CLIENT_REVIEW, OFFER, DEPLOYMENT, DEPLOYED; side outcomes REJECTED, WITHDRAWN, ON_HOLD, RETURNED. Labels are configurable, codes are not.
- Conversion to employee (Phase 2C): only a hired/deployed candidate, once per application; the converted recruitment record is then locked; duplicate employee email is checked; portal account creation or linking is optional (an available employee account or a hashed temporary password); recruitment documents may be copied into Employee Documents while originals stay; the application keeps `converted_employee_id`, `converted_at`, `converted_by`.
- Portals: applicant (browse, apply, track, withdraw), recruitment/HR, client (endorsed candidates only, approve/decline/request interview/return), system admin. Admin privileges never grant business actions unless a permission says so.
- Event names: `recruitment.application.created`, `.application.stage_changed`, `.interview.scheduled`, `.interview.completed`, `.endorsement.sent`, `.client_review.decided`, `.offer.sent`, `.offer.accepted`, `.offer.declined`, `.deployment.ready`, `.deployment.completed`, `.employee_conversion.completed`.
- Critical path test: manpower request, approved/open job, application, screening, interview, endorsement, client decision, offer, deployment, employee conversion.
- Do not use the ticket tables as recruitment tables ([[Employee-Tickets]]).

## Open Questions

- [ ] Does the existing recruitment implementation match these entities, or is reconciliation needed first?
- [ ] Is requisition approval real, or does HR post directly?
- [ ] Applicant data retention period, and is it stated in the application form's consent text?
- [ ] Do applicants get a portal to track their application?
- [ ] Which onboarding tasks are genuinely blocking for payroll?
- [ ] Is the internal-applicant-as-transfer flow needed in v1?
- [ ] In an agency model, is recruitment for the company or for client deployments? → [[ADR-002-Multi-Tenancy]]

## Related

[[Employee-Records]] · [[Org-Structure]] · [[Auth-and-RBAC]] · [[Notifications]] · [[Payroll]] · [[Offboarding-and-Clearance]] · [[Performance-Management]] · [[Security-and-Compliance]] · [[Data-Model]] · [[Screens]] · [[API-Surface]] · [[Business-Rules]] · [[ADR-002-Multi-Tenancy]]
