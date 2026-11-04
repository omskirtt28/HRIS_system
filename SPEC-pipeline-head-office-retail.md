# Spec: Pipeline split by Head Office / Retail (saved view per HR user)

Status: v3 APPROVED and BUILT (local, 2026-11-04): Q-A switch both ways allowed; Q-B Applicants links carry the filter; Q-C migration 036 OK; Q-D default Head Office. Plan: HRIS-Vault/05-Implementation/Plans/2026-11-04-pipeline-site-split.md (done). Module id: `pipeline-site-split` (one capability, no capability map needed). Named root spec like the other SPEC-*.md files.

## Objective
The Recruitment Pipeline (`hr-pipeline`) mixes Head Office and Retail candidates. There is NO "All" view. Each HR user has a **saved view** that opens by default:
- an HR at Head Office stays on **Head Office**;
- an HR on Retail stays on **Retail**, filtered to the **branches they picked** (e.g. only SM Marilao + SM Naga);
- either can switch to the other site when they want (optional); the switch is remembered.

Users: HR recruiters (`recruitment.view`). Reviewers (SC/OIC/ADL/Manager/TC) are unchanged. This is a **view default, not a permission lock** (see Q-A).

## Decisions from John
1. Separate from the start, no automatic All. Per-HR saved site.
2. Retail must be filterable by branch (e.g. "this is the SM Marilao branch").
3. "Other" tab: John did not understand it, so it is removed from the UI (explained below).
4. Pipeline only for now (Dashboard, Reports, Applicants page UI untouched).

## Assumptions (correct me)
1. Site of a candidate = site of its job opening, same rule as Hiring Review (`RecruitmentRepository::siteTypeOf` L348): `job_openings.site_type`, else the manpower request branch type (RETAIL_STORE -> Retail, HEAD_OFFICE -> Head Office). Reused in SQL.
2. Branch of a Retail candidate = the branches on the job (`job_opening_branches`), else the manpower request branch (same as `RecruitmentRepository` L579). A job with 2 branches shows under both.
3. HR users have no branch on their employee record (checked: all 5 HR users `employees.branch_id` NULL), so the default cannot be derived; it must be **saved per user** -> new table (migration, "ask first").
4. First visit with nothing saved: opens **Head Office**; a one-line hint says "Showing Head Office. Switch to Retail to pick branches". No All.
5. Choosing the site tab or applying the branch picker saves it (POST + CSRF, then redirect to the page). Opening Pipeline with no query string loads the saved view; `?site=&branch[]=` in the URL still works for links.
6. **"Other / Unassigned" (what it meant):** a job whose site is neither Head Office nor Retail (the job form has an "Other" site type) or cannot be worked out. Today there are none. So: no tab. If such candidates ever exist, a small "N unassigned" notice appears above the board with a link, so nobody is invisible.
7. Links out of the board ("+N more · View all", "Deployed (N)") open Applicants carrying the same site + branch filter, so those lists are not mixed either. Applicants gets NO new tabs or UI (same shared filter only). See Q-B.
8. English-only UI. Stages, stage moves, Hiring Review, Talent Pool, Parked group unchanged.

## Tech stack
Plain PHP (`index.php`, `app/RecruitmentRepository.php`), MySQL `pmbsi_hris`, existing Pipeline v3 board (`.kanban`, `.ktools`), Charges-style checkbox picker with All / Clear (standing UI rule).

## Data (needs approval: schema)
Migration 036 (next free number after 035), additive only:
- `user_pipeline_view(user_id PK FK users, site ENUM('HEAD_OFFICE','RETAIL') NOT NULL, updated_at)`
- `user_pipeline_view_branches(user_id FK, branch_id FK, PK(user_id,branch_id))` (empty = all Retail branches)
- Down script guarded: refuses to drop if rows exist (same pattern as migration 032).
Skills at build time: `backend-design:migration-safety`, `backend-design:data-modeling-discipline`.

## Commands
- Check: `php scripts/check.php task` (full with `HRIS_TEST_DB` set: `php scripts/check.php full`)
- Lint: `php -l app/RecruitmentRepository.php`, `php -l index.php`
- Test (scratch DB only): `php tests/pipeline_site_split_test.php` (new), re-run `tests/hiring_review_visibility_test.php`
- Migration cycle on scratch: up, re-run, down, up
- Browser check: `run-hris-system` skill

## Project structure
- `database/migrations/20261104_036_pipeline_view.sql` + `.down.sql`
- `app/RecruitmentRepository.php`: `applicationFilter()` (L72) gets `site` and `branch_ids`; `boardFilterOptions()` (L92) limited to the chosen site/branches; new `pipelineView($userId)` / `savePipelineView($userId,$site,$branchIds)`; one private SQL site expression.
- `index.php`: `hr-pipeline` (L1688) reads saved view or `?site=`; whitelist site to `HEAD_OFFICE|RETAIL`, cast branch ids to int and keep only real Retail branches; new POST action `pipeline_save_view` (CSRF, `recruitment.view`). `hr-applicants` (L1677) only passes the two keys into the shared filter.
- `public/assets/app.css` (+ bump `?v=` in `app/View.php`): segmented Head Office | Retail control and branch picker.
- Vault: `02-Modules/Recruitment-and-Onboarding.md`, `03-Design/Data-Model.md` (new tables), `Screens.md`, `API-Surface.md` (new POST), `Business-Rules.md` (site/branch rule), `Progress.md`, `Session-Log.md`, `CHANGELOG.md`, `Decision-Log.md`.

## Code style
Static repository methods, prepared statements, ints and whitelisted strings only.

```php
$site = in_array($in, ['HEAD_OFFICE','RETAIL'], true) ? $in : 'HEAD_OFFICE';
$branchIds = array_values(array_filter(array_map('intval', (array)($_POST['branch'] ?? []))));
// SQL gets only the site code and ? placeholders for ids, never raw input
```

## Testing strategy (scratch DB)
Jobs: Head Office via `site_type`; Head Office via NULL `site_type` + HQ branch; Retail with 1 branch; Retail with 2 branches; Retail via manpower request only. One application each.
- Head Office view lists only Head Office candidates; Retail view only Retail; no candidate in both, none in neither (except an unresolvable job, shown by the "unassigned" notice).
- Retail + branch filter: only those branches; a 2-branch job appears under each of its branches, counted once per view.
- Saved view: HR A (Head Office) and HR B (Retail, 2 branches) each reopen their own view; switching saves; another HR is unaffected.
- Stage counts match cards; "+N more" and Deployed links carry the filter.
- Bad input (`site=x`, branch id of a non-Retail branch, SQL-like string, missing CSRF) = safe default or 4xx, no error.
- Migration up / re-run / down / up; down blocked when rows exist.
- Browser at 1535px and 390px: control and ~40-branch picker usable, keyboard focus.

## Boundaries
- Always: whitelist inputs, CSRF on the save POST, keep counts equal to cards, reuse the site rule, update the vault, `check.php task`, backup + verified restore before applying the migration.
- Ask first: applying migration 036 to `pmbsi_hris`, making the view a real restriction, Applicants/Dashboard/Reports changes.
- Never: change stages, Hiring Review or endorse logic, touch payroll/roles/permissions, delete data, push or deploy.

## Success criteria
1. Pipeline always shows exactly one site (Head Office or Retail), never a mixed board.
2. Head Office HR opens on Head Office; Retail HR opens on their picked branches; both can switch and it is remembered.
3. Retail branch filter works with All / Clear, default = the saved branches (empty = all Retail).
4. No candidate is silently hidden (unassigned notice).
5. Existing Pipeline behaviour and tests unchanged; new tests + `check.php task` pass.

## Open questions
- **Q-A:** Is a Retail HR allowed to switch to Head Office (and vice versa)? I assumed yes, because the view is only a default. If Retail HR must never see Head Office candidates, that is a permission change and needs your word first.
- **Q-B:** OK that "View all" / "Deployed" links open Applicants already filtered to the same site/branches (no Applicants UI change)? If not, those links stay unfiltered and will show mixed lists.
- **Q-C:** Do you approve the 2 small new tables (migration 036)? Without them the saved view can only live in the browser, so it would not follow the HR to another PC.
- **Q-D:** For first-time users with nothing saved: Head Office default OK?
