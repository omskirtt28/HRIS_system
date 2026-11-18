# Capability Map: Recruitment (Job Street style careers + HR pipeline)

Status: PROPOSED, waiting for John's review. No module spec, plan or code yet.
Date: 2026-11-02. Author: Claude.

## What John asked for (2026-11-02)
1. HR can post available positions (hiring), like a job board. Public can search them.
2. Applicant picks a job, fills the application form (it existed before, was lost on data reset), submits.
3. Applicant gets a tracker to see the progress of the application.
4. HR updates the status / moves the applicant along the hand-drawn "Stage & Action" flow.
5. Manpower Request tickets from Managers and ADL must land in HR > Recruitment > Manpower Requests.

## What already exists (verified in code)
- Public pages: `careers` (search by text, department, type), `job`, `apply` (basic form + resume), `track` (ref + email, stage history).
- HR pages: `hr-manpower` (manual create form, Super Admin / Recruitment Manager only), `hr-applicants`, `hr-applicant`, pipeline, endorsements.
- Tables: `manpower_requests`, `job_openings`, `applicants`, `applications`, `recruitment_stages`, `application_stage_history`, interviews, endorsements, offers, deployments.
- Stages in DB today: Applied, Screening, Interview, Endorsed, Client Review, Offer, Deployment, Deployed, Rejected, Withdrawn, On Hold, Returned.

## Gaps found (why the pages are empty)
- G1: Nothing in the app creates a `job_openings` row. Only `database/schema.sql` seeds them, so after the data reset Careers shows 0 positions. HR has no "post a job" screen.
- G2: Manpower tickets live in `tickets` + `ticket_manpower_requests`. HR `hr-manpower` reads a different table, `manpower_requests`. They are not connected.
- G3: The stage list does not match the flowchart (flowchart has For Review, Requirement, Pre-Deployment; no Client Review/Deployed wording yet).
- G4: Apply form is minimal (name, email, mobile, position, resume, why-fit). John says the old one had more fields.

## Modules

| Module id | Responsibility | Depends on |
|---|---|---|
| rec-manpower-intake | Manpower Request tickets (Manager/ADL) appear in HR Manpower Requests; HR approves or declines; filled count. | existing ticket engine |
| rec-jobs | HR posts, edits, closes and unpublishes job openings (from an approved manpower request or standalone); public Careers search stays. | rec-manpower-intake |
| rec-pipeline | Stage set and actions per the hand-drawn flow, HR move/modal actions, reject, on hold, history. | rec-jobs (needs applications to exist) |
| rec-apply | Public application form (full fill-up fields, resume, consent), reference number, duplicate guard. | rec-jobs |
| rec-track | Applicant-facing tracker: current stage, timeline, requirements to submit, offer response. | rec-pipeline, rec-apply |

Build order: rec-manpower-intake -> rec-jobs -> rec-apply and rec-pipeline (independent of each other) -> rec-track.

## Reading of the flowchart (to be confirmed, see questions)
Applicant (PMBSI Careers) -> Applied -> HR user/Recruiter -> Screening (Initial Interview) -> Interview (Final Interview; fail = Rejected) -> Endorsed (Client Interview; fail = Rejected) -> For Review (schedule interview modal, remarks) -> Offer (Accept or Decline; attachment modal) -> Requirement (applicant sends docs; On Hold if incomplete) -> Pre-Deployment.

## Boundaries (apply to every module)
- Always: vault sync, English-only UI, CSRF + permission checks, tests first, scratch DB only.
- Ask first: migrations, changing existing stage codes (live applications may use them), new permissions.
- Never: push, deploy, delete applicant data, show applicant data to the public beyond their own reference + email.

## Decisions from John (2026-11-02)
- D1: Last stage is Pre-Deployment (after Requirement).
- D2: Client Review is replaced by For Review (no extra stage). Needs a data-safe rename/remap because live applications may use CLIENT_REVIEW.
- D3: All HR users can approve manpower requests and post/edit/close jobs. No new role or permission.
- D4: Manpower ticket goes to HR Manpower Requests first; HR approves, then clicks Post job. Nothing is public automatically.
