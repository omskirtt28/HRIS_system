# Spec: Recruitment stage flow, Screening first, then Interview

Status: DRAFT, awaiting John's approval. No code written.

## Assumptions I'm making (correct me now)
1. "Lilito si HR" means the 8-dot tracker is too long, and "Applied" is a wasted step: the applicant has already applied, so HR's first real work is Screening.
2. Scope is the tracker display and where new applications start. The later stages (Endorsed, Manager Review, Offer, Deployment, Deployed) stay as they are.
3. The stage code `APPLIED` is NOT deleted. Old applications and `application_stage_history` rows point at it, and deleting data needs your word.
4. The applicant-facing status page (Careers "track my application") should follow the same stage list, since it reads the same `stages()`.
5. English-only UI. Spec text is Taglish, but UI labels stay English.

## Objective
New applicants land in **Screening** immediately and HR moves them straight to **Interview**. The tracker has no "Applied" dot.
Target user: HR (Human Resources role), plus the applicant who views their own status.

Today: `RecruitmentRepository::submitApplication` (line 39) inserts at stage `APPLIED`. `stages()` (line 23) returns every active stage. The Update to dropdown uses `nextStageCode()` (line 95), which picks the next active stage with sequence <= 80.

## Acceptance criteria
- A new Careers-site application is created at stage `SCREENING`. Its first history row reads "Application submitted through Careers site", with `to_stage_id` = Screening.
- The tracker on the Applicant Profile shows: Screening, Interview, Endorsed, Manager Review, Offer, Deployment, Deployed (7 dots). The first dot is the current one for a new applicant.
- At Screening, the Update to default is "Continue to Interview".
- Existing applications that are still at `APPLIED` are shown and handled as Screening: tracker position, `nextStageCode()` result, stage filters and counts. Their data is unchanged unless you approve a data fix (see Open Questions).
- Application history rows that mention Applied still render. A missing stage never produces a blank label or a PHP warning.
- The dashboard and stage counts (`stageCounts()`, line 149) no longer show an empty "Applied" bucket. Counts add up to the same total as before.
- Rejected, Withdrawn, On Hold and Returned behave exactly as before.
- Scheduling an interview from Screening still moves the candidate to Interview (`moveToInterviewOrNote`).

## Tech stack
PHP (no framework), MySQL/MariaDB (`pmbsi_hris`) on XAMPP. Plain PHP test scripts in `tests/`. Repository class `app/RecruitmentRepository.php`, views in `app/`, routes in `index.php`.

## Commands
- Task check (required before handing back): `php scripts/check.php task`
- Full check (before shipping, needs `HRIS_TEST_DB`): `php scripts/check.php full`
- Single test: `php tests/<name>_test.php` (existing ones: `rec_endorse_manager_test.php`, `interview_invite_test.php`, `interview_chain_test.php`)

## Project structure (the parts this touches)
- `app/RecruitmentRepository.php`: `stages()`, `submitApplication()`, `nextStageCode()`, `stageCounts()`, `moveStage()`
- `app/functions.php`: `stage_label()` and the stage badge colour map (lines 47 and 51)
- Applicant Profile tracker view: the file that renders the dots (to be located during Plan)
- `tests/`: new `rec_screening_first_test.php`
- Vault: `HRIS-Vault/02-Modules` recruitment note, `Business-Rules.md`, `Decision-Log.md`, `CHANGELOG.md`, `05-Implementation/Progress.md`, `Session-Log.md`

## Code style
The repo uses compact one-line PHP with a docblock on public static methods. Example from the codebase:

```php
/** The next normal stage for the Update button. Only before the offer. */
public static function nextStageCode(int $id): ?string { ... }
```
New code follows the same style: parameterised PDO, `RuntimeException` for user-facing errors, `audit()` on writes.

## Testing strategy
Plain PHP scripts against the isolated test DB (never the live `pmbsi_hris` before the checks pass).
- Test: a new application starts at SCREENING with the correct history row.
- Test: `stages()` and the tracker list exclude Applied.
- Test: a legacy application at APPLIED is treated as Screening. `nextStageCode` returns INTERVIEW.
- Test: `stageCounts()` totals are unchanged by the change.
- Re-run the existing interview and endorse tests, and the Fix sweep for other places that assume `APPLIED` (`HrDashboard*.php`, the Careers status page).

## Boundaries
- Always: use the isolated DB for tests, keep the English-only UI, update the vault, list the vault files changed in the final report.
- Ask first: any migration that changes `recruitment_stages` rows or `applications.current_stage_id` (data change), and any change to later stages.
- Never: delete the `APPLIED` stage row or history, `git push`, deploy, or touch the live DB before a verified dump and passing tests.

## Open Questions (I need your decision)
1. **Hide vs. migrate.** Option A (recommended, no data risk): keep the `APPLIED` row in the DB but set `active=0` and treat legacy APPLIED applications as Screening in code. Option B: a migration that moves all existing APPLIED applications to SCREENING and adds history lines. Option B is cleaner but changes business rows, so it needs your explicit OK.
2. Should the Careers applicant status page show "Screening" right away after they apply? Recommended yes.
3. Should HR also be able to skip Screening and go straight to Interview? It's allowed today by scheduling an interview. I'd keep that as is.
4. Does "Endorsed" stay in the tracker? Your message only mentions Screening and Interview, so I'm leaving it.
