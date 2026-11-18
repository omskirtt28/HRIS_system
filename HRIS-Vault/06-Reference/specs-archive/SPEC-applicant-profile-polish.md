# Spec: applicant-profile-polish (draft, awaiting approval)

Saved as `SPEC-applicant-profile-polish.md` (not `SPEC.md`, which already exists) to follow the repo convention `SPEC-<id>.md`. Single capability, so no capability map.

## Objective
Three fixes on the HR Applicant Profile (`index.php` page `hr-applicant`), requested by John 2026-11-03:
1. **Layout**: the page uses only about 1000px and leaves a large empty area on the right. Rebuild it as a proper two-column layout that looks designed, not generated.
2. **Stage name**: "Manager Review" is wrong because reviewers are not always managers (TC, Area Development Leader, Sales Captain, HR hiring personnel). Rename it to **Hiring Review**.
3. **Interview scoring**: the Screening rating (Negative / Fair / Outstanding) is the screening-stage advice. The interview needs its own, different rating: per-criteria 1-5 plus an overall recommendation.

Users: HR / recruitment staff (`recruitment.manage`); interviewers and reviewers who are routed to the applicant (they see only their own limited view, BR-861).
Success: John opens an applicant, sees no dead space, reads "Hiring Review" everywhere, and an interviewer can record a 1-5 scorecard that HR sees on the profile.

## Decisions (John, 2026-11-03)
- D1. Label: **Hiring Review** (label only; stage code `CLIENT_REVIEW` unchanged, as in SPEC-rec-endorse-manager Q4).
- D2. Layout: John left it to the assistant ("kung ano mas maganda, advanced UI/UX, hindi parang AI"). Chosen: two columns (see Design).
- D3. Interview scale: per-criteria 1-5 plus overall recommendation. Needs a new table, so a migration (ask-first item, see Boundaries).
- D4. Screening rating stays Negative / Fair / Outstanding, unchanged, advice only.

## Scope

### 1. Layout (no schema, no logic)
- Wide screens (>= 1100px): main column on the left (about 65%) with the header card, stepper, Stage & actions, Schedule interview, Resume / Application / Interviews tabs. A sticky right sidebar (about 35%) holds the Screening rating, the Interview scorecards summary, Endorse to Hiring Review, and the Timeline.
- Below 1100px: one column, sidebar cards stack after the main column. No horizontal scroll at 375px.
- Respect `HRIS-Vault/03-Design/Design-System.md`: existing card, spacing, radius and badge tokens only. No new colours, no gradients or decorative icons. Hover, focus, disabled and empty states on every control touched.
- Fix sweep: other HR pages that cap width the same way (`hr-applicants`, review pages) get the same check; list, do not redesign.

### 2. Rename to Hiring Review (labels only)
Replace the display text, keep codes. Known places: `app/functions.php:47` (`stage_label`), SQL `IF(code="CLIENT_REVIEW","Manager Review",...)` in `app/RecruitmentRepository.php` (lines ~23, 60, 62, 81, 149 and the long lines 65 and 78), `index.php:140` flash text, `app/CandidateReviewViews.php` (lines 4, 31, 48: "Endorse to Manager review", "Manager review"), the stepper, dashboards and the applicant-facing status page. Final pass: grep `Manager Review|Manager review|manager review` and decide each hit (the person-role "Manager" stays).
Tests that assert the old text must be updated in the same patch, not loosened: `tests/rec_endorse_manager_*`, `interview_chain_*`, `interview_invite_test.php`.

### 3. Interview scorecard
- Who: an interviewer who is routed to the applicant (same routing rule as screening scorers; server-enforced) and HR. Applicant must be at Interview or later.
- Criteria (1 = poor, 5 = excellent), proposed: Communication, Job knowledge, Attitude, Culture fit, Overall impression (average is shown, never auto-decides). Plus a recommendation: Proceed / Hold / Do not proceed, and an optional note.
- Advice only: never moves a stage, never auto-rejects (same as D4 and BR for screening).
- Shown on the profile as a compact card per interviewer (criteria bars, recommendation badge) and a one-line summary in the stepper header ("Interview 4.2 / 5"). Interview rating is visually distinct from the Screening badge (numeric score, not Negative/Fair/Outstanding).
- Data: new table `interview_scorecards` (application_id, interview_id NULL, scorer_id, one row per criterion or five SMALLINT columns, recommendation, note, timestamps). Existing unused `interviews.rating DECIMAL(5,2)` is left alone. TO VERIFY before design: nothing else reads or writes it.
- Audit entry and timeline line on save, same pattern as `Screening rating saved.` (`index.php:145`, `RecruitmentRepository.php:258`).

## Tech stack
Plain PHP + PDO (XAMPP), MySQL `pmbsi_hris`, server-rendered views in `index.php` and `app/*Views.php`, CSS in the existing shared stylesheet. No new dependency.

## Commands
- Syntax: `php -l index.php`; `php -l app/CandidateReviewViews.php`; `php -l app/RecruitmentRepository.php`; `php -l app/functions.php`
- Quality gate before handing back: `php scripts/check.php task` (`full` with `HRIS_TEST_DB` set before shipping)
- Isolated-DB and browser checks: the `run-hris-system` skill (never the live `pmbsi_hris`)

## Project structure
- `index.php` page `hr-applicant` (~1695-1725) and POST actions (~90-180)
- `app/CandidateReviewViews.php` (cards), `app/RecruitmentRepository.php` (logic, new `saveInterviewScorecard()`), `app/functions.php` (labels, badges)
- `database/migrations/NNN_interview_scorecards.sql` with a guarded down script (next number after 029)
- `tests/` (new `interview_scorecard_test.php`, `interview_scorecard_http_test.php`; updated label assertions)
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, `03-Design/Data-Model.md`, `Business-Rules.md`, `Screens.md`, `05-Implementation/Progress.md`, Session-Log, Decision-Log, CHANGELOG

## Code style
Match neighbours: prepared statements, `e()` on all output, CSRF + permission check + `audit()` in every handler, English-only UI text.
```php
// Same shape as the screening handler (index.php:143)
$scores = array_map(fn($k) => (int)($_POST['c_'.$k] ?? 0), self::CRITERIA);
if (min($scores) < 1 || max($scores) > 5) throw new RuntimeException('Each score must be 1 to 5.');
```

## Testing strategy
- Repository tests: valid scorecard saves and averages; score 0, 6 or non-numeric rejected; bad recommendation rejected; user not routed to the applicant refused; applicant before Interview refused; second save by the same scorer updates instead of duplicating; stage never changes.
- Label tests: no remaining "Manager Review" text in rendered HR, reviewer and applicant pages; stage code still `CLIENT_REVIEW`; existing endorse and interview-chain tests pass with the new label.
- Browser (isolated DB, `run-hris-system`): screenshots of `hr-applicant` at 1440, 1024 and 375px, light and dark; no horizontal scroll; keyboard tab order through the sidebar.
- Migration: up, re-run, down, up, with the down guarded against used data; mysqldump taken and restore verified first.

## Boundaries
- Always: server-side permission and CSRF, input validation, audit entry, English-only UI, design-system tokens, vault sync, the fix-sweep report format.
- Ask first: the migration for `interview_scorecards` (and applying it to `pmbsi_hris`), any role or permission change, making the scorecard mandatory before Offer, changing the criteria list.
- Never: rename the `CLIENT_REVIEW` code or touch existing application rows, auto-change a stage from a score, show scorecards to reviewers beyond BR-861, weaken or delete tests to pass, `git push` or deploy, write the live DB before backup plus isolated tests pass.

## Success criteria
- No empty right-hand area at 1440px; one column with no horizontal scroll at 375px.
- `grep -ri "manager review"` over views, repositories and rendered pages returns only deliberate role-name uses; the stepper shows "Hiring Review".
- An interviewer saves a 1-5 scorecard; HR sees it on the profile; a non-routed user cannot save or see it.
- Existing applicants unchanged; `php scripts/check.php task` passes; migration cycle clean.

## Suggested build order (Plan phase, after approval)
1. Rename (labels, tests), smallest and independent.
2. Layout two-column.
3. Scorecard: migration (after approval), repository, card, tests.

## Open Questions
1. Are the five criteria right (Communication, Job knowledge, Attitude, Culture fit, Overall impression), or does HR use others? Different criteria for retail and head office?
2. Who may score: only interviewers routed to the applicant plus HR (same as screening)? Or also the Hiring Review reviewers?
3. Should an Offer be blocked until at least one interview scorecard exists (like the screening gate), or stay optional?
4. Should the applicant-facing status page say "Hiring Review" too? Assumed yes.
