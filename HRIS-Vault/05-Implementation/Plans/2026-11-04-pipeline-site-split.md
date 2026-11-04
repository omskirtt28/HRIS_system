---
title: Pipeline split by Head Office / Retail (saved view)
type: plan
status: done
tags: [plan, recruitment, pipeline, site-split]
updated: 2026-11-04
---

# Pipeline split by Head Office / Retail (saved view)

Status: done (local). Built 2026-11-04, migration 036 applied to localhost; not on live hosting, no commit.
Date: 2026-11-04
Session: [[Session-Log]]
Spec: `SPEC-pipeline-head-office-retail.md` (repo root, v2, decisions approved by John 2026-11-04)

## Context
Mixed ang Head Office at Retail applicants sa Pipeline. Gusto ni John: walang "All"; bawat HR may saved view (Head Office HR = Head Office; Retail HR = sa mga branch na pinili niya); puwedeng mag-switch sa kabila (both access); Retail may branch filter. Pipeline lang muna.

Approved answers: Q-A switch allowed both ways; Q-B Applicants links carry the filter (most feasible: shared `applicationFilter`, no Applicants UI); Q-C migration 036 OK; Q-D first-time default = Head Office. "Other" tab dropped, only an "N unassigned" notice.

Verified facts (read-only): 5 HR users, all `employees.branch_id` NULL, so default must be saved per user. Site rule = `siteTypeOf` (RecruitmentRepository L348). Job branches = `job_opening_branches` else manpower request branch (L579). Jobs now: 7 and 8 NULL site_type (resolve via branch), 9 Retail. Last migration is 035, so this is 036.

## Mga gagawin
**T1 Migration 036** (additive): `user_pipeline_view(user_id PK, site ENUM HEAD_OFFICE|RETAIL, updated_at)` + `user_pipeline_view_branches(user_id, branch_id, PK both)`. Down guarded (refuses when rows exist). Scratch cycle up / re-run / down / up. Skills: `backend-design:migration-safety`, `data-modeling-discipline`.
**T2 Repository** (`app/RecruitmentRepository.php`): private site SQL expression; `applicationFilter` gets `site` + `branch_ids`; `boardFilterOptions` limited to the chosen view; `pipelineView($userId)` / `savePipelineView(...)`; `unassignedCount()` for the notice.
**T3 Pipeline screen** (`index.php` hr-pipeline L1688, `public/assets/app.css`, `?v=` in `app/View.php`): Head Office | Retail control, Retail branch picker (checkbox, All / Clear, search for ~40 branches), saved view loaded when no query string, POST `pipeline_save_view` (CSRF, `recruitment.view`, whitelist site, int + real-Retail-branch check), unassigned notice. Skills: `frontend-ui-engineering`, `design:design-system`.
  - **Checkpoint A:** stop here for John to try it on scratch/localhost before T4.
**T4 Links + tests:** "View all" and "Deployed (N)" pass `site` + `branch[]` into `hr-applicants` (filter only, no UI). New `tests/pipeline_site_split_test.php`; re-run `pipeline_board_test`, `hiring_review_visibility_test`.
**T5 Verify + apply + vault:** browser check at 1535px and 390px (`run-hris-system`); fresh mysqldump + restore verified; apply 036 to localhost `pmbsi_hris` per standing workflow only if all checks pass; confirm no business rows changed.

Fix sweep: grep other places that list applicants/jobs without the site split (Dashboard, Reports) and LIST them only, not changed (John: Pipeline only).

## Critical files
- `database/migrations/20261104_036_pipeline_view.sql` / `.down.sql`
- `app/RecruitmentRepository.php` (L72 filter, L92 options, L348 site rule, L579 branches)
- `index.php` (hr-pipeline L1688, hr-applicants L1677, new POST action)
- `public/assets/app.css`, `app/View.php`
- `tests/pipeline_site_split_test.php` (new)
- Vault: Recruitment-and-Onboarding, Data-Model, Screens, API-Surface, Business-Rules, Progress, Session-Log, CHANGELOG, Decision-Log

## Verification
- `php -l` on changed PHP; `php scripts/check.php task` (full with `HRIS_TEST_DB`).
- Each view shows only its site; Head Office + Retail + unassigned = every candidate once.
- Branch filter; a 2-branch job under each branch; saved views independent per HR; first-time = Head Office.
- Bad `site`, non-Retail branch id, missing CSRF = safe default or error, no SQL error.
- Migration up / re-run / down / up; down blocked when rows exist.
- No existing business rows changed after apply.

## Boundaries
No git push/deploy, no data deletion, no stage / Hiring Review / endorse / permission / payroll changes, no Dashboard or Reports changes.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-04-pipeline-site-split.md (spec: SPEC-pipeline-head-office-retail.md). Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration 036 up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Stop sa Checkpoint A para sa review ni John. Vault sync bago ang final report. Walang git push."
```
