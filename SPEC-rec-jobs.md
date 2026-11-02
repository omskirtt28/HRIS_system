# Spec: rec-jobs (HR posts, edits, closes and unpublishes job openings; public Careers shows only live ones)

Status: v2 APPROVED by John 2026-11-02 ("okay lahat", PMBSI only on public) plus new decision D5. BUILT 2026-11-02 (local). No migration.
Date: 2026-11-02. Author: Claude. Parent: `SPEC-recruitment-map.md` (module 2 of 5, depends on `rec-manpower-intake`, which is built locally).
Already approved by John: D3 (all HR users post, edit and close jobs, no new role or permission) and D4 (HR approves the request first, then clicks Post job; nothing is public automatically).
Note: the 5-module map itself was never explicitly approved. This spec assumes it stands.

## Objective
HR turns an approved Manpower Request (or a walk-in vacancy) into a public job post, controls when it is live, and closes it when done. The public sees only live jobs on Careers.
User: any HR user (staff side); anonymous visitors (read side only).
Done means: HR can go from "Approved" to a live Careers listing in one screen, edit or close it later, and a closed or unpublished job disappears from Careers, Home and Apply on the next page load.

## Decisions (John, 2026-11-02)
- Q-J1: public job cards and job page show "PMBSI" only, never the client or employer name. Q-J2 to Q-J6: defaults accepted (no auto-close, same HR user may approve and publish, closes_at optional, salary range only when both set, featured toggle free).
- D5 (new): HR does not file manpower requests; HR creates the hiring (job posting) directly. So the "+ Create new manpower request" form on HR > Manpower Requests and the "+ New manpower request" button on Pipeline are removed. Manpower Requests becomes ticket-only: requests filed by Managers and ADLs, which HR approves or declines. A walk-in client vacancy is entered through "+ New job". This supersedes the module 1 assumption that the manual create form stays (history kept in the Decision-Log, not erased).

## Verified facts (from code and schema)
- `job_openings` already has everything needed: `manpower_request_id` NOT NULL FK, `client_id` NOT NULL FK, `department_id`, `job_code` UNIQUE, `slug` UNIQUE, `title`, `description` NOT NULL, `requirements`, `location_text`, `employment_type`, `salary_min/max`, `openings`, `status` (default DRAFT), `is_featured`, `published_at`, `closes_at`, `created_by`. No migration should be needed.
- Nothing in the app inserts `job_openings` (only `schema.sql` seed). Careers shows 0 (G1).
- Public reads (`RecruitmentRepository::publishedJobs`, `jobBySlug`, `jobById`, index.php `careers`/`job`/`apply`/home) filter on `status="PUBLISHED"` only. `closes_at` is stored but never checked.
- `job_card()` (View.php:210) shows the employer name (`clients.name`) publicly.
- Module 1 shipped: HR Manpower Requests list with Approve / Decline; Approve creates the `manpower_requests` row (status APPROVED) with employer, priority and `notes`. `filled_headcount` is raised only by `rec-pipeline` (Deployed).
- HR_USER already holds `recruitment.view` and `recruitment.manage` (checked read-only on 2026-11-02). Use these; seed nothing.
- Live data today: manpower, jobs and applications are all 0.

## Assumptions (correct me now or I proceed)
1. Job lifecycle uses the existing `status` text column with four values: `DRAFT` (not public), `PUBLISHED` (public), `CLOSED` (ended, kept for history). Unpublish = back to `DRAFT`. No hard delete, ever (applications may point at the job).
2. "Public" = `status='PUBLISHED'` AND (`closes_at` IS NULL OR `closes_at` >= now). Expiry hides the job without a cron; the stored status stays PUBLISHED until HR closes it (shown to HR as "Expired").
3. A job is created from an APPROVED manpower request through a Post job button (D4). Prefill: title, employer, department/branch location, employment type, openings = requested - filled - already open on other jobs (minimum 1), description/requirements from the request notes (the ticket's qualifications text).
4. Standalone job (no ticket, D5): HR fills the job form; the system silently creates the required `manpower_requests` row (APPROVED, approved by the same HR user) so `manpower_request_id` stays NOT NULL with no schema change. HR never sees or fills a "request" form. These bridge rows are labelled "HR posting" and appear only under the All tab of Manpower Requests, not under Open.
5. One request may have several job posts (e.g. two locations). The form warns, but does not block, when total live openings exceed the remaining headcount.
6. Editing a PUBLISHED job is allowed and goes live immediately. Changing title or openings does not touch existing applications.
7. Closing or unpublishing a job never deletes or changes its applications. Applicants with a reference keep tracking (rec-track).
8. Staff audit: create, publish, unpublish, close, edit each write an audit row, matching how other HR actions are audited.
9. UI text English only. Matches `HRIS-Vault/03-Design/Design-System.md` and the existing HR table/modal style from `hr-manpower`.

## Proposed behaviour
0. **Manpower Requests is ticket-only (D5)**: remove the create form and the `create_manpower` handler (index.php:93 and :1572) and change the Pipeline button (index.php:1587) to "+ New job". Approved-request rows keep Post job.
1. **HR > Recruitment > Job Openings** (`hr-jobs`): table of jobs with filter tabs (All, Draft, Published, Closed) and search by title. Columns: code, title, employer, location, type, openings, applications count, status badge (Draft / Published / Expired / Closed), closes date, actions.
2. **Create**: "Post job" on an approved request row in Manpower Requests (prefilled), and "+ New job" on Job Openings (standalone, assumption 4). Form fields: title, employer, department, location, employment type, openings, salary min/max (optional, blank allowed), description (required), requirements (one per line, as the job page already renders), closes on (optional date), featured (checkbox). Save as Draft or Save and publish.
3. **Publish / Unpublish / Close** are single POST actions with CSRF, `recruitment.manage`, and a state check (cannot publish a CLOSED job; Reopen = set back to Draft, then publish). Publishing sets `published_at` once, on first publish.
4. **Edit** any field of a Draft or Published job. Closed jobs are read-only except Reopen.
5. **Code and slug**: `job_code` = `JOB-<year>-<sequence>`; `slug` = slugified title plus the code, unique, generated once and never changed on edit so public links stay valid.
6. **Public side**: `publishedJobs`, `jobBySlug`, `jobById` get the closes_at rule (assumption 2) in one shared WHERE fragment so Careers, Home, job page and Apply cannot disagree. Unknown, draft or closed slug returns the existing 404 redirect. A closed job's page is not public.
7. **Manpower Requests link-back**: each approved request row shows how many jobs are live and a Post job button; status labels stay owned by module 1.
8. **Job card and job page**: salary range only when both min and max are set; the employer is always shown as "PMBSI" (Q-J1). The real employer stays in HR views only.

## Commands
Check: `php scripts/check.php task` (full: `php scripts/check.php full` with `HRIS_TEST_DB` set).
New tests (to be created): `php tests/rec_jobs_test.php`, `php tests/rec_jobs_http_test.php`, on the isolated scratch DB only, never on `pmbsi_hris`. Fixture style: copy `tests/rec_manpower_intake_http_fixture.php`.

## Project structure (planned, no files written yet)
- `app/JobOpenings.php`: create from request, standalone create, update, publish / unpublish / close / reopen, code and slug generation, public visibility WHERE fragment.
- `app/JobOpeningsViews.php`: list table, form dialog, status badges (same pattern as `ManpowerIntakeViews.php`).
- `app/RecruitmentRepository.php`: public queries use the shared visibility fragment.
- `index.php`: page `hr-jobs`, POST actions `save_job`, `publish_job`, `unpublish_job`, `close_job`, `reopen_job`, Post job button on `hr-manpower`, menu entry.
- `public/assets/hr-ops.css`: reuse existing classes before adding any.
- `scripts/check.php`, `CONSTRAINTS.md`: register the new tests as module 1 did.
- Vault: Progress, Recruitment-and-Onboarding, Screens, API-Surface, Business-Rules, Decision-Log, Open-Questions, CHANGELOG, Session-Log, Handoff checkpoint. Data-Model only if a migration turns out to be needed.

## Code style
Match the surrounding code: static methods with PDO prepared statements, `Auth::requirePermission('recruitment.manage')` first in every POST handler, `csrf_field()`, `flash()` + `redirect()`, `e()` on all output, transactions around multi-row writes.

```php
public static function publish(int $jobId): void {
    $st = db()->prepare('UPDATE job_openings SET status="PUBLISHED", published_at=COALESCE(published_at,NOW())
                         WHERE id=? AND status IN ("DRAFT")');
    $st->execute([$jobId]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Only a draft job can be published.');
}
```

## Testing strategy
Unit (service, scratch DB): code and slug uniqueness under repeat calls; prefill math (openings = requested - filled - live); standalone creates exactly one APPROVED manual request; state machine (Draft -> Published -> Draft; Published -> Closed; Closed -> Draft; illegal moves throw); `published_at` set once; expiry hides a job whose `closes_at` is past; salary optional.
HTTP (runtime): HR user can create, publish, edit, unpublish, close; non-HR user gets 403 on every action; missing or bad CSRF rejected; double click on Publish does not corrupt state; a Draft slug and a Closed slug return 404 on the public job page; Careers, Home and Apply list only live jobs; Apply to a closed job is refused.
Regression: existing `rec_manpower_intake_*` tests still pass.
No browser checks unless John asks (project rule).

## Boundaries
- Always: tests first, scratch DB only, CSRF + permission on every POST, state checks, vault sync, English UI, sweep the same pattern in other recruitment handlers.
- Ask first: any migration (none expected), new permission, auto-close when a request is filled.
- Never: push, deploy, apply to live without the verified-dump workflow, hard-delete a job or its applications, auto-publish, change stage codes (that is `rec-pipeline`), touch the Apply form fields (that is `rec-apply`).

## Success criteria
1. From an Approved request, HR clicks Post job, reviews the prefilled form, publishes, and the job appears on Careers and Home with no retyping.
2. Unpublish, Close or passing `closes_at` removes the job from Careers, Home, the job page and Apply, with one shared rule proven by test.
3. Standalone job works without a ticket and creates exactly one linked APPROVED manual request.
4. No path deletes a job or touches applications; Closed jobs keep their history.
5. Any HR user can do all of it (D3); a non-HR user cannot (403 by test).
6. `php scripts/check.php task` passes with the new tests registered; no existing floor lowered.

## Open Questions
None blocking. The plan confirms in code that nothing else reads `create_manpower` or Source "Manual" rows (grep: index.php and tests/rec_manpower_intake_http_test.php lines 130-132 only).
