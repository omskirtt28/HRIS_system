---
title: rec-endorse-manager plan
type: plan
status: done
tags: [plan, recruitment]
updated: 2026-11-02
---

# rec-endorse-manager: endorse a candidate to Manager / ADL / TC / SC

Status: done (2026-11-02, built and applied locally; not committed, pushed or deployed)
Date: 2026-11-02
Session: [[Session-Log]]
Spec: `SPEC-rec-endorse-manager.md` (repo root). Decisions Q1-Q6 are recorded there.

## Context
"Client endorsement" on the HR applicant page writes `endorsements` by client, but the employer is always PMBSI. The people who interview and decide are the Manager / ADL / TC (and the Sales Captain in retail) who asked for the hire. HR endorses to one or more of them; they see the candidate in their own portal, can schedule an interview, and give a decision. HR can always continue on their own.

## Data design (changed after review: do NOT alter `endorsements`)
`endorsements` has UNIQUE(application_id, client_id) and `endorse()` skips when a row exists, so a second recipient is impossible, and the client-portal filter would leak these candidates to a CLIENT_USER while that portal still exists. Use one standalone table:

`application_reviewers(id, application_id FK, reviewer_user_id FK users, endorsed_by FK users, note TEXT NULL, endorsed_at, decision VARCHAR(30) NULL, decision_comment TEXT NULL, decided_at DATETIME NULL, UNIQUE(application_id, reviewer_user_id))`.
Decisions: PROCEED, NOT_SUITABLE, ANOTHER_INTERVIEW. Re-endorsing the same person resets their decision (reuse the row). Down script refuses when the table has rows. Client path untouched.

Migration `20261102_023_application_reviewers.sql` (+ `.down.sql`), idempotent: the table; permission `recruitment.review` granted to MANAGER, ADL, SC, TC only (never `recruitment.view`, so they cannot see Applicants or Job Openings).

## Mga gagawin
1. Migration + down (above). Stage label: map CLIENT_REVIEW to "Manager Review" in `stage_label()` (not the DB row; badges use the function). Grep tests/flash text for "Client Review".
2. `RecruitmentRepository`: `reviewerChoices()` (active users with MANAGER/ADL/SC/TC), `defaultReviewers($appId)` (only when `manpower_requests.ticket_id IS NOT NULL` and `requested_by` has an allowed role; walk-in bridge rows set requested_by to HR so no default), `endorse($appId,$note,$reviewerIds)` (server validates every id; moves to CLIENT_REVIEW; notifies each), `reviewQueue($userId)`, `reviewerApplication($appId,$userId)` (null unless a reviewer row exists), `decide(...)`, `reviewerSchedule(...)`.
3. Decision rules: a decision moves the stage only while the application is still CLIENT_REVIEW: PROCEED to OFFER, NOT_SUITABLE to RETURNED only when no other reviewer has PROCEED or is still pending... (kept simple: NOT_SUITABLE returns to HR when every recipient has declined). Any later decision is recorded on the timeline and notifies HR, no stage change. HR Update/Continue stays available (a silent manager never blocks).
4. Reviewer scheduling an interview must not push the stage backwards: only advance when the current stage is earlier than Interview.
5. HR applicant page: replace the Client endorsement card (shown at Interview, Endorsed, Manager Review, Returned) with a checkbox list of reviewers with a name filter, default recipient pre-checked, note, "Endorse", and a list of who was endorsed and what they answered.
6. Reviewer portal (employee nav, permission `recruitment.review`): `review-candidates` list and `review-candidate&id=` detail (name, job, HR note, resume, screening answer, interviews, decision form, schedule form). Every query filtered by reviewer_user_id. Extend the resume route to `recruitment.view` OR (`recruitment.review` AND a reviewer row for this user).
7. Notifications: new kind (e.g. `CANDIDATE_REVIEW`) mapped in `NotificationService::sidebarPage()` so the badge shows; HR is notified on every decision.
8. Sweep: Offer form employment type still offers only Full-time/Contract; switch to `JobOpenings::TYPES`.

## Critical files
`app/RecruitmentRepository.php`, `app/functions.php` (stage_label), `app/NotificationService.php`, `app/View.php` (employee nav), `index.php` (endorse action, hr-applicant card, resume route, new review pages), a new `app/CandidateReviewViews.php`, `database/migrations/20261102_023_*`, `tests/`, `scripts/check.php`.

## Verification
- Fresh dump of `pmbsi_hris` restored to a fresh scratch copy and fingerprint-compared.
- Migration up, re-run, down (refuses with data), up on the scratch copy.
- New HTTP test: HR endorses to two reviewers; each sees only their own candidate; a third Manager sees none; resume allowed only for the endorsed reviewer; decisions and stage rules; scheduling does not move the stage backwards; employee without the permission gets 403; posted bad reviewer id refused.
- `tests/ticket_lead_followup_test.php` (22/30 ADL badge cases) must be proven unrelated on a FRESH scratch copy (compare against HEAD) before any apply, because this slice edits the same notification/sidebar code.
- `php scripts/check.php task` and `full` with `HRIS_TEST_DB`.
- Apply to localhost `pmbsi_hris` only when all pass, then confirm no existing business rows changed. No push, deploy, role deletion.

## Out of scope (own slices)
rec-remove-client-portal; rec-track-steps (applicant-facing steps with initial / mid / final interview rounds).

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-rec-endorse-manager.md and SPEC-rec-endorse-manager.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check sa FRESH scratch DB, migration up/re-run/down/up, patunayan na unrelated ang ticket_lead_followup_test failure, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push, deploy o delete ng roles."
```
