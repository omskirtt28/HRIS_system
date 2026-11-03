---
title: Hiring Review pass-on visibility (Head Office vs Retail)
type: plan
status: draft
tags: [plan, recruitment]
updated: 2026-11-03
---

# Hiring Review pass-on visibility (Head Office vs Retail)

Status: done (2026-11-03, localhost only; not committed)
Date: 2026-11-03
Session: [[Session-Log]]
Spec: `SPEC-hiring-review-pass-visibility.md` (repo root, approved by John 2026-11-03)

## Context
Sa "Pass to next interviewer" at sa HR endorse picker, lahat ng Manager/ADL/SC/TC/OIC/MT ay nakikita kahit Head Office ang candidate. Rules (approved):
- **Head Office**: HR -> TC -> Manager. Walang retail role na lalabas. Direct manager (requester ng Manpower Request) na Proceed = lock. Direct manager na hindi Proceed = pwede ipasa sa ibang Manager o TC. Non-direct Manager = dulo.
- **Retail**: si HR ang maglalagay ng Area/Branch sa Job Opening. HR -> ADL ng area; kung walang ADL -> OIC/SC/MT ng branch. Pagkatapos, OIC/MT/SC (at ADL) lang sa scope ang nakikita. Walang Manager/TC.
- Unknown site type o Retail na walang scope = HR lang.

## Mga gagawin (vertical slices, isa-isa)
1. **T1 Discovery (read-only)**: alamin kung paano naka-link ang user -> employee -> branch/area (users table walang branch; hanapin ang employee link) at saan ang `branches.area_id`. Basehan ng matching. Kung walang maayos na link, STOP at tanungin si John.
2. **T2 Migration 031** (needs John OK): `job_opening_branches(job_opening_id, branch_id)` (unique pair; area = `branches.area_id`, hindi na ulit sine-save). Up/down, down guarded kapag may rows. Gawin muna: mysqldump + verified restore, isolated DB up/re-run/down/up. Skills: `backend-design:migration-safety`, `data-modeling-discipline`.
3. **T3 Job Opening form**: kapag Site type = Retail, Area + Branch checkboxes (kapag pumili ng area, lahat ng branch niya; pwede bawasan). Save/validate sa `JobOpenings.php` / `JobOpeningsViews.php` (English UI). HO = walang scope.
4. **T4 Shared filter** sa `RecruitmentRepository`: `PASS_TO` map (site + role ng nagpapasa -> allowed role codes) at `passTargets($applicationId,$fromUserId)`; HR endorse gamit `endorseTargets($applicationId)`. Retail: i-filter sa scope branches (ADL por area). HO: HR->TC; TC->MANAGER; direct-manager rule. `reviewerChoices()` mananatili para sa validation base.
5. **T5 Server validation**: `passOn()` at `endorseToReviewers()` (RecruitmentRepository.php ~310, ~338) tumatanggap lang ng ids sa allowed set; forged id = RuntimeException. Direct-manager lock: Proceed ng direct manager = walang pass card at `passOn` rejected.
6. **T6 Views**: `CandidateReviewViews.php:26` (HR endorse) at `:101-111` (pass card) gagamit ng bagong filter; empty-state text: "There is no one else to pass the candidate to."
7. **T7 Tests** (isolated DB): extend `tests/interview_chain_http_test.php`, `tests/rec_endorse_manager_test.php`; bagong scenarios sa ibaba. Pagkatapos `php scripts/check.php task`.
8. **T8 Sweep**: ibang caller ng `reviewerChoices()`, `canScore` consistency, notification links, HR applicant view (`reviewersFor`).
9. **Vault sync**: Recruitment-and-Onboarding.md, Data-Model.md (migration 031), Business-Rules.md (bagong BR), Decision-Log.md, Open-Questions.md, CHANGELOG.md, Progress.md, Session-Log.md.

Order: T1 -> T2 (hintay OK) -> T3/T4 -> T5 -> T6 -> T7 -> T8. Main session ang gagawa (small/medium, isang area); isang `patch-tester` sa dulo kung kailangan.

## Critical files
- `app/RecruitmentRepository.php` (reviewerChoices 192, scorer/site 233-247, endorseToReviewers ~302, passOn 327)
- `app/CandidateReviewViews.php` (26, 101-111)
- `app/JobOpenings.php` (~134), `app/JobOpeningsViews.php` (~51)
- `database/migrations/20261103_031_job_opening_branches(.down).sql`, `database/schema.sql` kung kailangan
- `index.php:158` (review_pass_on handler)
- `tests/interview_chain_http_test.php`, `tests/rec_endorse_manager_test.php`

## Verification (test scenarios)
1. HO candidate: TC nakikita lang ang MANAGER sa pass list; walang ADL/SC/OIC/MT.
2. HO direct manager Proceed -> walang pass card, forged `review_pass_on` rejected. Direct manager Not suitable -> pwede sa ibang Manager/TC.
3. Retail: walang MANAGER/TC sa HR picker at pass card. May ADL sa area -> ADL ang choice; walang ADL -> OIC/SC/MT ng branch.
4. Retail, ADL pass -> OIC/MT/SC sa scope lang; labas ng scope rejected sa server.
5. Retail na walang scope, o OTHER/unknown = HR lang, walang choices.
6. Migration 031 up/re-run/down/up; down tumatanggi kapag may used rows; walang nagbago sa existing business rows.

## Risks
- Walang malinaw na user->branch link (T1). Mitigation: stop at tanungin si John.
- Existing Retail jobs ay walang scope pagkatapos ng migration: HR lang muna hanggang mailagay ang area/branch. Ipapaalam sa final report.
- Existing endorsed rows hindi gagalawin.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-03-hiring-review-pass-visibility.md (spec: SPEC-hiring-review-pass-visibility.md). Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
