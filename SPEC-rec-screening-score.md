# Spec: rec-screening-score (draft, awaiting approval)

## Findings (verified 2026-11-03)
- `applications.screening_score` (decimal 5,2) and `applications.assigned_recruiter_id` exist and are READ by `hr-applicants` (index.php ~1658), `hr-applicant`, and the dashboard lists. **No code writes either column.** So Score is always "—" and Recruiter always "Unassigned", for every applicant.
- Table `screening_reviews` (recommendation PROCEED/HOLD/REJECT, score, summary, completed_at) is in `database/schema.sql` but no app code uses it (only AdminDataResetService).
- Interviews and manager review exist (SPEC-interview-*, SPEC-rec-endorse-manager) but the interviewer's Proceed/Not suitable answer is not a score. `interview_evaluations` (overall_score) is in the vault module note but not built.
- Stage "Converted" is `applications.status='CONVERTED'` (RecruitmentRepository.php:511), set by the employee conversion. A converted applicant can therefore have no score at all, because nothing ever asks for one. This is the pipeline hole.

## Objective
Give HR a way to record a screening score (and optionally assign a recruiter) so the Score and Recruiter columns carry real data, and make the score visible in the applicant list, the applicant page and the dashboard.
Users: HR / recruitment staff with `recruitment.manage`.
Success: after HR screens an applicant, the list shows the score badge and recruiter name; a converted applicant keeps the score they had.

## Proposed scope (smallest version)
1. **Screening card on `hr-applicant`** (stages APPLIED, SCREENING, INTERVIEW): score 0-100, recommendation (Proceed / Hold / Reject), short summary. Saves a row in `screening_reviews` (history) and sets `applications.screening_score` to the latest score. Timeline line + audit entry.
2. **Assign recruiter** dropdown on `hr-applicant` (active HR/recruitment users) writing `assigned_recruiter_id`.
3. **List** shows score badge (green at 85+, amber below, "—" if unscored; keeps current rule) and recruiter name.
4. **Converted/terminal stages**: score and summary shown read-only; no editing after CONVERTED.
5. Score is advisory only (SCHEMA.md): it never moves a stage by itself.

Out of scope: AI scoring (`application_ai_assessments`), interviewer evaluation scorecards (`interview_evaluations`), auto-ranking, any rule that blocks conversion when unscored (see Open Questions).

## Commands
- Check: `php scripts/check.php task` (full with `HRIS_TEST_DB` before shipping)
- Syntax: `php -l app/RecruitmentRepository.php`; `php -l index.php`
- Isolated-DB tests via the `run-hris-system` skill.

## Project structure
- Logic: `app/RecruitmentRepository.php` (new `saveScreening()`, `assignRecruiter()`)
- Routes/views: `index.php` (POST actions near line 90-175; `hr-applicant`, `hr-applicants` ~1654)
- Schema: `database/migrations/` only if `screening_reviews` is missing columns in the live DB (verify first)
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, Business-Rules (next BR after BR-876), Data-Model, Progress, Session-Log, CHANGELOG

## Code style
PDO prepared statements, `e()` on output, `audit()` and CSRF as in neighbouring handlers, English-only UI text, existing `badge` classes.

## Testing strategy
- Repository test: save score, then list returns it; second save keeps history and updates latest; score outside 0-100 or bad recommendation rejected; non-`recruitment.manage` user refused; CONVERTED row refuses edit.
- Browser check on isolated DB: score badge and recruiter name appear in the list.

## Boundaries
- Always: permission + CSRF check, validate range/enums, audit entry, vault sync.
- Ask first: any migration, role/permission change, making the score mandatory.
- Never: write the live `pmbsi_hris` before backup + isolated tests pass; auto-change stage from a score; show scores to manager reviewers (BR-861 keeps them to their own limited view).

## Success criteria
- Applicant with a saved score shows it in `hr-applicants`, `hr-applicant` and the dashboard list.
- Recruiter assignment shows the name instead of "Unassigned".
- Existing applicant rows unchanged until HR scores them (no backfill).
- `php scripts/check.php task` passes.

## Decisions (John, 2026-11-03)
1. Who scores (revised 3): eligible roles. Retail: HR, ADL, OIC, SC, MT. Head office: Manager, TC, HR. But only people the applicant actually went through (endorsed or interview chain) may score; if only ADL handled it, only ADL (plus HR) scores. Others never see the form. HR keeps the final decision.
2. Scale (John 2026-11-03): three ratings, Negative / Fair / Outstanding, instead of 0-100. Badges: red / amber / green. Still advice only, no auto-reject.
3. Conversion needs a score. A low score never auto-rejects: HR can Return or continue. Score is advice, HR decides.
4. Recruiter assignment: not understood yet; explained in plain words and re-asked (Open Question A).
5. Test: score the existing applicant and walk the score-then-convert flow so John can see how it works.

## Rules from the decisions
- Gate: HR cannot convert an applicant with no saved score. No pass mark, no auto-reject; a low score only shows amber.
- Retail vs head office must come from existing data (job/client/location). TO VERIFY which field. The scorer list is enforced on the server, not only hidden in the UI.
- A scorer must be in the applicant's endorsement/interview recipients (BR-861 filter) AND hold an eligible role for the retail/head-office type; server-enforced.

## Decisions round 2 (John, 2026-11-03)
- A. Recruiter: John did not follow "dropdown". Decided by assistant (simplest): no pick-list. Recruiter is set automatically to the HR user who saves the first score; no extra UI. Revisit if John wants manual assign.
- B. Test with a NEW applicant on the isolated DB (score, then convert). John Joshua (already Converted) stays untouched.
- C. Scorer roles: retail ADL, OIC, SC, MT; head office Manager, TC; HR both, limited to the people the applicant was routed to. TO VERIFY: OIC, SC, MT role codes exist live; any role added needs John OK.

## Open Questions
- Which field says retail vs head office (job, client or location)? Verify in code, then confirm with John.

## Rating change (2026-11-03)
- Score = rating NEGATIVE / FAIR / OUTSTANDING (replaces 0-100 and the 85 threshold). Stored as `screening_rating` (nullable enum) on `applications` and on each `screening_reviews` row, added by the same migration as the MT role and job site type. `screening_score` stays unused. Every place that shows the score badge (hr-applicants, hr-applicant, dashboard lists) switches to the rating.
- Negative does not block conversion or reject; HR decides. Convert gate = a rating must exist.
