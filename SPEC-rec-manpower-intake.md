# Spec: rec-manpower-intake (Manpower Request tickets land in HR > Recruitment > Manpower Requests)

Status: BUILT 2026-11-02 (local). Spec v2 plus Claude decisions recorded in Decision-Log.md and the Handoff checkpoint. Open: Q72-Q76.
Date: 2026-11-02. Author: Claude. Parent: `SPEC-recruitment-map.md` (module 1 of 5, no dependencies).
Decisions already approved by John: D3 (all HR users approve and post, no new role or permission) and D4 (ticket goes to HR Manpower Requests first, HR approves, then Post job; nothing is public automatically).

## Objective
When a Manager (HO) or ADL (Retail) files a Manpower Request ticket, HR sees it in HR > Recruitment > Manpower Requests, approves or declines it there, and sees how many of the requested heads are filled. Approved requests are what `rec-jobs` later turns into a job posting.
User: any HR user. Done means: a filed ticket shows up for HR with no manual re-typing, one decision updates both the ticket and the recruitment list, and the requester sees the outcome in their ticket.

## Verified facts (from code)
- Ticket side: `tickets` + `ticket_manpower_requests` (ticket_id, section_branch, position_code, employment_type Seasonal|Probationary, request_type Replacement|Additional, quantity, replacement_of_employee_id, job_description_qualifications, area_department, date_served). Routing `BRANCH_ADL_THEN_HR` / `HO_MANAGER_THEN_HR`. Completing the ticket only stamps `date_served` (TicketTypes.php:74-75).
- Recruitment side: `manpower_requests` (request_no, client_id NOT NULL FK, position_title, requested_headcount, filled_headcount, employment_type, priority, status, approved_by/at). `job_openings.manpower_request_id` is NOT NULL, so every job needs a `manpower_requests` row.
- `hr-manpower` (index.php:1554) lists `manpower_requests` and has a manual create form for Super Admin / Recruitment Manager only. The menu item needs `recruitment.view`; the create action needs `recruitment.manage`.
- `filled_headcount` is raised only when a candidate is marked Deployed (RecruitmentRepository.php:187, index.php:124). The stage rename (D1/D2) belongs to `rec-pipeline`, not here.
- `clients` is seeded with five demo external clients (also live); there is no screen to add or edit a client yet.

## Assumptions (correct me now or I proceed)
1. Keep the ticket engine as the place where requesters file and see status; do not build a second filing form.
2. Bridge, not merge: add a `manpower_requests` row linked to the ticket, instead of rewriting `job_openings` and `applications` to point at ticket tables.
3. The ticket's HR step and the recruitment "Approve / Decline" are ONE decision made on the recruitment page, so there is no double entry.
4. The manual "+ Create new manpower request" form stays for walk-in client requests (Super Admin / Recruitment Manager), unchanged.
5. Nothing is auto-posted publicly (D4).

## Proposed behaviour
1. Intake: when a MANPOWER_REQUEST ticket reaches the HR step, create one linked `manpower_requests` row (status SUBMITTED). One row per ticket, enforced by a unique link, idempotent on retry.
2. Field mapping: quantity -> requested_headcount; position_code -> position_title (readable name); employment_type mapped (Probationary/Seasonal); section_branch/area_department -> branch/department when they resolve, else kept in notes; job_description_qualifications -> notes (copied into the job draft later); Replacement/Additional and the replaced employee shown in the detail; requester name from the ticket.
3. List: Manpower Requests shows ticket-sourced and manual rows together with a Source column (Ticket #id or Manual), plus status filter. Filled/Requested shown as `filled / requested`.
4. Decide: HR opens a request and clicks Approve or Decline (decline needs a remark). This calls the existing ticket service transition so the ticket status, audit trail and requester notification stay correct, then mirrors APPROVED / DECLINED into `manpower_requests` (approved_by, approved_at).
5. Filled count: read-only here, displayed from `filled_headcount`; `rec-pipeline` owns the increment. Status FILLED / PARTIALLY_FILLED keeps existing logic.
6. Requester view: the ticket shows the HR decision and remark; no recruitment details exposed to the requester.
7. Backfill: existing MANPOWER_REQUEST tickets already at or past the HR step get a linked row once (migration or one-off script, verified on the scratch DB first).

## Commands
Check: `php scripts/check.php task` (full: `php scripts/check.php full` with `HRIS_TEST_DB` set).
New tests (names to be created): `php tests/rec_manpower_intake_test.php`, `php tests/rec_manpower_intake_http_test.php` on the isolated scratch DB only, never on `pmbsi_hris`.

## Project structure (planned, no files written yet)
- `app/RecruitmentRepository.php`: list with source, approve/decline wrapper (reuse, do not copy ticket logic).
- Small new class, e.g. `app/ManpowerIntake.php`: ticket -> manpower_requests bridge and field mapping.
- `index.php`: `hr-manpower` list/detail, POST `decide_manpower` with CSRF and permission check.
- `database/migrations/<date>_022_manpower_ticket_link(.down).sql`: additive link column plus unique key; down guarded when linked rows exist.
- Tests in `tests/`; vault notes under `HRIS-Vault/` (Progress, Employee-Tickets, Recruitment-and-Onboarding, Data-Model, Business-Rules, Screens, API-Surface, Decision-Log, Open-Questions, CHANGELOG).

## Code style
Match the surrounding code: `RecruitmentRepository` static methods with PDO prepared statements, `Auth::requirePermission(...)` first in every POST handler, `csrf_field()`, `flash()` + `redirect()`, `e()` for all output, English-only UI text.

## Testing strategy
Unit (service): mapping, idempotent bridge, decline needs remark, status mirror, no change to filled count. HTTP (runtime): HR sees ticket-sourced rows; a non-HR user gets 403 on decide; CSRF missing is rejected; double click does not create a second row; requester sees the decision. Plus migration up / re-run / down / up with the guarded down, on the scratch DB after a verified dump, as in the standing workflow.

## Boundaries
- Always: tests first, scratch DB only, CSRF + permission on every POST, vault sync, English UI, sweep the same pattern in other handlers.
- Ask first: the migration (new link column), deleting the demo clients (R1), any change to ticket statuses.
- Never: push, deploy, apply to live without the verified-dump workflow, delete tickets or applicant data, auto-publish a job, touch stage codes (that is `rec-pipeline`).

## Success criteria
1. A newly filed Manager/ADL manpower ticket appears in HR Manpower Requests after it reaches HR, with correct headcount, position, type and requester, and no retyping.
2. Approve or Decline from that page changes the ticket status once, writes the audit/notification the ticket engine already writes, and the list row matches.
3. No duplicate rows from refresh, retry or double submit.
4. Any HR user can decide (D3); a non-HR user cannot (403 proven by test).
5. Existing manual requests, `job_openings`, applications and filled counts are unchanged (row-count comparison before/after).
6. `check.php full` passes; no floor lowered, no test skipped.

## Open Questions
## Decisions from John (2026-11-02, after the draft)
- Q-A: remove the 5 demo clients. HR users add the employers themselves (no pre-filled list). Chosen option: keep `client_id` required (no schema change); HR picks an employer from a list and can add a new one inline ("+ Add employer") on the Manpower Requests page. Internal employers (PMBSI, 2Log) are added by HR the same way, nothing seeded.
- Salary: shown on the job posting only when HR enters it. Optional, never required. 2Log (own hiring) normally leaves it blank; other employers may fill it. `job_openings.salary_min/max` are already nullable, so no schema change; the salary field belongs to `rec-jobs`.
- Q-B: checked live (read-only). HR_USER already holds `recruitment.view` and `recruitment.manage` (also COORDINATOR, HRIS_ADMIN, RECRUITER, RECRUITMENT_MANAGER, SUPER_ADMIN). No permission seed needed. The manual create form is still limited to Super Admin / Recruitment Manager in the view (index.php:1557): change it to `Auth::can('recruitment.manage')` so all HR users match D3.

## Demo client removal (gated: needs John's word before it runs on pmbsi_hris)
Live state, read-only: clients = 5 demo rows; manpower_requests 0, job_openings 0, applications 0, endorsements 0, client_reviews 0. Still referencing demo clients: branches 5 (client 1) and 4 (client 3); users 6 (`removed.6.hr@abcretail.com`, already a removed account) and 5 (`ops@primelogistics.com`, "Prime Logistics Client"). Plan: verified dump first, set those 2 branch links and 2 user links to NULL, delete the 5 clients, down script restores only from the dump. Also remove the same INSERT from `database/schema.sql` so fresh installs have no demo clients. See R1.

## Open Questions
- R1: OK to unlink the 2 branches and 2 users (ops@primelogistics.com is a demo client login) and delete the 5 demo clients, after a verified dump? Or deactivate instead of delete?
- Q-G: The ticket has no employer. Proposed: HR picks the employer in the Approve step (required). Or tell me a default such as PMBSI.
- Q-C: Ticket `position_code` -> readable `position_title`: I did not find the code list in what I read. I will confirm in the plan phase; if none exists, the title shows the code.
- Q-D: Employment type mapping (Seasonal/Probationary vs FULL_TIME/CONTRACT used in recruitment). Proposed: store the ticket value as-is in notes and map Probationary -> FULL_TIME, Seasonal -> CONTRACT. Confirm or give your own mapping.
- Q-E: Priority is not on the ticket. Default NORMAL; HR can raise to URGENT on the detail. OK?
- Q-F: Should a Declined ticket request stay visible in the list (greyed, with remark) or only under a "Declined" filter? Proposed: filter, hidden from the default view.
