---
title: rec-screening-score plan
type: plan
status: approved
tags: [plan, recruitment, screening]
updated: 2026-11-03
---

# rec-screening-score: screening score, recruiter, convert gate

Status: done (2026-11-03)
Date: 2026-11-03
Session: [[Session-Log]]
Spec: `SPEC-rec-screening-score.md` (repo root)

## Context
Applicant Database showed Score "—" and Recruiter "Unassigned" for a Converted applicant. Verified: nothing writes `applications.screening_score` or `assigned_recruiter_id`; `screening_reviews` exists live but unused. Score is advice only, HR decides, never auto-reject.

## Verified facts (2026-11-03, live DB read-only)
- Roles live: ADL, OIC, SC, TC, MANAGER, HR_USER exist. **MT is NOT a role**, it is the position "Management Trainee". Permissions: recruitment.manage / .review / .view.
- Retail vs head office: `applications.job_opening_id -> job_openings.manpower_request_id -> manpower_requests.branch_id -> branches.site_type` (RETAIL_STORE / HEAD_OFFICE). Job without a request (walk-in) has no site type.
- `application_reviewers` is the endorsement recipient table (BR-861 filter) = "who the applicant went through".
- Existing `REVIEWER_ROLES` = MANAGER, ADL, SC, TC (RecruitmentRepository.php:188).

## Decisions needed before building (ask John, no guessing)
1. DECIDED (John 2026-11-03): add a new role MT (Management Trainee, the position already exists) and add OIC and MT to the reviewer roles. New role = migration with guarded down script, needs the CLAUDE.md test-then-apply checks.
2. John: Job Opening form gets an option Retail / Head Office (so every job has a site type). Jobs for another company (client placement) = default HR-only scoring until John says otherwise. Needs a site-type column on job_openings (migration, same one as MT role).

## Mga gagawin (order)
1. **Backend** (`app/RecruitmentRepository.php`): `scorerRolesFor($appId)` from site type; `canScore($appId,$userId)` = HR (recruitment.manage) or an application_reviewers recipient with an eligible role/position; `saveScreening($appId,$score 0-100,$recommendation,$summary)` inserts `screening_reviews`, sets `screening_score`, sets `assigned_recruiter_id` once if empty and the saver is HR; refuses CONVERTED/terminal rows; timeline + audit.
2. **Convert gate**: conversion refused with a clear message when `screening_score IS NULL`; no pass mark.
3. **Routes + UI** (`index.php`): POST `save_screening` (CSRF, permission via canScore), Screening card on `hr-applicant` and on the reviewer page `review-candidate` (own candidates only); list/dashboard already read the columns (no change besides recruiter name join in `hr-applicants`).
4. **Tests**: repository test (range, enums, history, latest score, eligibility per site type, only-routed-people, converted locked, gate); HTTP/browser check on isolated DB with a NEW test applicant (score then convert). John Joshua row untouched.
5. **Sweep**: other Score/Recruiter displays (dashboard lists, hr-applicant), same bug class. List, do not touch, anything on permissions/migrations.
6. **Vault sync**: Recruitment-and-Onboarding note, Business-Rules (next BR after BR-876), Data-Model (no migration expected, state it), Screens, Progress, Decision-Log, Open-Questions, CHANGELOG, Session-Log.

## Critical files
- app/RecruitmentRepository.php (REVIEWER_ROLES ~188, convert ~511, list query line 65)
- index.php (POST actions ~90-175, hr-applicants ~1654, hr-applicant, review-candidate)
- tests under the existing test dir used by `scripts/check.php`

## Risks
- Adding OIC/MT to reviewers touches who can be endorsed (permission-adjacent): ask first.
- Gate blocks conversion of any unscored applicant: HR must score first, intended.
- Existing screening_reviews columns not yet checked against what the code needs; check before coding. A migration, if any, follows migration-safety (dump, restore check, up/re-run/down/up).

## Verification
- `php scripts/check.php task`, `full` with HRIS_TEST_DB before shipping.
- Isolated-DB run: new applicant, score as HR, score as routed ADL, a non-routed user refused, convert blocked without score then allowed, list shows badge and recruiter.
- No git push, no live apply without the CLAUDE.md test-then-apply conditions.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-03-rec-screening-score.md with SPEC-rec-screening-score.md. Gamitin ang hris-patch skill. Itanong muna kay John ang 2 decisions (MT, walk-in). Sundin ang CLAUDE.md: backup at restore check, isolated test DB, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```

## Update 2026-11-03: rating scale
Rating is Negative / Fair / Outstanding (not 0-100). Task 1 saves `screening_rating`; migration adds `applications.screening_rating`, `screening_reviews.rating` (if missing), `job_openings.site_type`, role MT. Task 3 swaps all score badges to the rating (red/amber/green). Tests: enum validation instead of range.
