# Spec: Talent Pool (pull-back) + Deployed leave the board

Status: DRAFT. Plan written: HRIS-Vault/05-Implementation/Plans/2026-11-04-pipeline-scale-talent-pool.md (supersedes defaults here; waiting John go). Module id: `talent-pool` (single capability, no capability map needed).

## Objective
HR Pipeline (`hr-pipeline`) keeps ALL stages as they are today. Two changes:
1. **Deployed candidates disappear from the board.** They are employees now. They go into a "Deployed" holding list (view-only, link to the employee record).
2. **Talent Pool ("pulling").** Rejected / Withdrawn / On Hold / Returned candidates stay on their stage columns, but HR also gets a Talent Pool page where they can search these past candidates and **pull** one into a different job opening. The pulled candidate returns to the pipeline (Screening) under the new job. Nothing is deleted.

Users: HR recruiters (`recruitment.view`; pulling needs the same permission used for moving stages).

## Assumptions (correct me)
1. "Wala tayo aalisin" = no stage is removed or renamed; only Deployed cards are hidden from the board.
2. Deployed holding list lives in the same Talent Pool page as a separate tab, view-only (no pull; link to employee/201).
3. Pull = create a NEW application for the same applicant on the chosen open job, starting at Screening. The old application keeps its stage and history (audit trail). No schema change if `applications` already allows multiple rows per applicant (to verify).
4. Pull happens by manual button, no timing/auto-move.
5. Only jobs with status Open are pull targets.

## Tech stack
PHP (plain, `index.php` routes + `app/*` services), MySQL `pmbsi_hris`, existing `.ap-sec` modern UI, English-only UI text.

## Commands
- Check: `php scripts/check.php task` (full: `HRIS_TEST_DB` set, `php scripts/check.php full`)
- Run/verify UI: `run-hris-system` skill (isolated scratch DB)

## Project structure
- `index.php` ~L1673 `hr-pipeline` (filter out DEPLOYED), new route `hr-talent-pool` (+ POST `hr-talent-pool-pull`)
- `app/RecruitmentRepository.php`: new `talentPool()` query and `pullToJob()` (reuses stage-history insert pattern, L107)
- `app/View.php` L109: sidebar item under Recruitment
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, Screens.md, API-Surface.md, Business-Rules.md, Progress.md, Session-Log.md, CHANGELOG.md, Decision-Log.md

## Code style
Match existing repository style: static methods in `RecruitmentRepository`, prepared statements, `e()` escaping, CSRF on POST, `flash()` + `redirect()`.

## Testing strategy
- Unit/DB test in isolated DB: pull creates new application at Screening, writes `application_stage_history`, leaves old application unchanged; blocked when job is not Open; blocked when applicant already has an active application on that job.
- Board test: Deployed candidates absent from `hr-pipeline`, present in Deployed tab.
- Permission test: user without `recruitment.view` gets no access to the routes.

## Boundaries
- Always: CSRF + permission check on pull, record who pulled and when, prepared statements.
- Ask first: any migration/new table or column (only if multiple applications per applicant are not allowed today), changing stage rules.
- Never: delete applicants/applications, change payroll or roles/permissions, push or deploy.

## Success criteria
1. Pipeline board shows no Deployed column cards; other 10 stages unchanged.
2. Talent Pool page lists Rejected, Withdrawn, On Hold, Returned with search (name, ref, email) and last-stage/date; Deployed tab is view-only.
3. Pulling a candidate to an Open job creates a Screening application, visible on the board, with history "Pulled from Talent Pool".
4. Old application and all history remain intact.
5. `php scripts/check.php task` passes.

## Open questions
1. Is Deployed tab inside Talent Pool OK, or do you want a separate page?
2. Should a pulled candidate carry over the old screening score/documents, or start fresh? (Default: start fresh, old record linked.)
3. Should Rejected candidates with a "do not rehire" reason be blocked from pulling? (Default: no such flag today, so no.)
