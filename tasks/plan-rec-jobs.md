# Plan: rec-jobs (module 2 of the recruitment map)

Spec: `SPEC-rec-jobs.md` (v2, approved by John 2026-11-02, plus D5). Date: 2026-11-02. Author: Claude. Plan only: no code, no migration.

## Goal
HR creates hirings (job posts) directly, from an approved ticket request or from scratch. Public sees only live jobs, employer shown as "PMBSI". HR no longer files "manpower requests" (D5).

## Split
- Backend: `app/JobOpenings.php` (new), `app/RecruitmentRepository.php`, `app/ManpowerIntake.php` (Open list hides HR-posting bridge rows), `index.php` handlers.
- Frontend: `app/JobOpeningsViews.php` (new), `hr-jobs` page, Post job button on `hr-manpower`, form removal, Pipeline button, public job card text.
- No migration: `job_openings` already has every column. The live DB only gets data through the app later, so the dump/cycle/apply workflow does not apply. If a migration turns out to be needed, stop and ask.

## Order (tests first, each step green before the next)
1. T1 service + visibility fragment (unit tests red, then green).
2. T2 public reads use the fragment; PMBSI-only text.
3. T3 D5 removals and the module 1 test rewrite.
4. T4 `hr-jobs` list, form, POST actions, menu.
5. T5 Post job on approved request rows.
6. T6 sweep.
7. T7 checks, constraints, vault sync.

## Risks
- R1 Removing `create_manpower` breaks module 1 HTTP test section E (2 checks). Replace them with 2 checks that the route is refused, so the PASS floor (19) is not lowered.
- R2 Bridge rows (`ticket_id` NULL) currently show as "Manual" in every list filter. Hide them from Open and label them "HR posting" under All, or HR sees phantom requests.
- R3 `job_card` and the job page share markup with Home (index.php:509-599); change the text in one helper and grep all callers.
- R4 `jobById` is used by Apply; a closed job must refuse an application (guard only; the form stays rec-apply's).
- R5 Slug and code collisions under double submit: unique keys plus a retry inside a transaction.

## Test scenarios
1. Approved request -> Post job prefilled (openings = requested - filled - live) -> publish -> visible on Careers as PMBSI.
2. Standalone job creates one APPROVED bridge row, hidden from Open, shown under All as HR posting.
3. Close / unpublish / past `closes_at` -> gone from Careers, Home, job page (404), and Apply refuses.
4. Non-HR user 403 on every job action; bad CSRF rejected; illegal state move rejected.

## Approval gate
Waiting for John's go. The plan touches no roles or permissions, no deletes, no payroll.
