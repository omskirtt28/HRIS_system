---
title: Pipeline scale + advanced board UI + Talent Pool
type: plan
status: executing (S1+S2 done, Checkpoint A)
tags: [plan, recruitment, pipeline, talent-pool]
updated: 2026-11-04
---

# Pipeline scale + advanced board UI + Talent Pool

Status: executing. S1 + S2 DONE locally 2026-11-04, stopped at Checkpoint A. S3 (Talent Pool) not started.
Date: 2026-11-04
Session: [[Session-Log]]
Spec: `SPEC-talent-pool.md` (repo root, draft; this plan supersedes its "Deployed tab" assumption only where noted)

## Context
John: pag sobrang dami ng applicants, hindi dapat bumagal o humaba ang Pipeline board. Gusto niya ng advanced UI/UX (skill: frontend-ui-engineering) at isang "Talent Pool" kung saan itinatabi muna ang mga applicant (Rejected, Withdrawn, On Hold, Returned) at kapag may bagong job/need, doon sila "hinihila" (pull). Deployed ay wala na sa board (done sa pipeline-polish).

Facts na na-verify (read-only):
- `applications` table: `UNIQUE(applicant_id, job_opening_id, status)` -> pwede ang MAraming application per applicant sa iba't ibang job. **Walang migration para sa pull**; ang same-job duplicate ay hinaharang na ng DB.
- Resume ay naka-attach sa application (`application_documents`, type RESUME), kaya ang pull ay kokopya ng document row (same stored file) sa bagong application.
- `RecruitmentRepository::applications()` walang LIMIT; `hr-applicants` walang pagination; board kumukuha ng lahat tapos 8/column lang ang pinapakita.
- Stage moves ay rule-driven (`nextStageCode`, interview/offer/deployment auto-advance), kaya **walang drag-and-drop** (lalampasan ang rules).

## Default decisions (John, i-correct kung mali)
1. "Itabi" = ang 4 existing stages (Rejected/Withdrawn/On Hold/Returned) ang shelf. Walang bagong stage, walang inalis.
2. Pull = bagong application sa Open job, nagsisimula sa Screening; luma ay hindi ginagalaw (audit trail). Start fresh (walang score carry-over); naka-link ang luma sa history comment.
3. May ibang active application ang tao: **babala lang, pwede pa rin** i-pull. Same job: bawal.
4. Pagination 25/page. Board: 8 cards/column, "+N more" papunta sa Applicants.
5. Walang "do-not-rehire" flag (wala sa schema; hiwalay na desisyon kung kailangan).

## Mga gagawin (vertical slices)

### S1 - Board scales (data side), no UI change
- `RecruitmentRepository`: `boardColumns(array $filters)` = count per stage (GROUP BY) + top 8 per stage (LIMIT query bawat stage, hindi fetchAll); `applications()` tumatanggap ng `limit`/`offset`; `applicationsCount()`.
- `hr-applicants`: pagination 25/page, nagpapanatili ng `q` at `stage`; "Showing x-y of N".
- Acceptance: 10k seeded applications -> `hr-pipeline` mas mababa sa ~300 ms query time at HTML < 150 KB; counts sa headers tama kahit >8; "+N more" tama; Deployed hindi kasama.
- Verify: unit/DB test sa scratch DB (count vs cards, page boundaries, filters preserved), `php scripts/check.php task`.

### S2 - Board UI v3 (frontend-ui-engineering; isang frontend skill lang)
Design contract: Job = scan at i-triage ang pipeline; primary action = buksan ang candidate; hindi nagbabago ng stage dito.
- Toolbar sa taas: search (name/ref/email), filter Job opening, filter Recruiter, "Clear". GET form, server-side (shareable URL), kasama sa "+N more" link.
- Card: avatar initials, pangalan, job, **days in stage** (mula `last_stage_changed_at`; amber >7d, red >14d, may text hindi kulay lang), rating badge, ref. Buong card ang link, focus ring.
- Columns: grupo na "Hiring flow" (Screening...Deployment) at "Parked" (Rejected, Withdrawn, On Hold, Returned) na mas banayad ang itsura; sticky header habang nag-s-scroll; empty state may maikling hint.
- A11y: landmark per column (tapos na), `aria-live` sa result count kapag nag-filter, keyboard Tab order, contrast >= 4.5:1 sa light AT dark, reduced-motion.
- Responsive: 1440 -> 5x2, 1024 -> 3, 768 -> 2, 320 -> 1; walang horizontal page scroll.
- Sweep: gamitin ang `Design-System.md`, `.ap-sec`/modern style; English-only UI text.
- Verify: `run-hris-system` + screenshots sa 320/768/1024/1440, light/dark; empty, 1, 8, 9+, 200 cards sa isang column; overflow ng mahabang pangalan/job title.

### Checkpoint A (John): tingnan ang board at Applicants paging bago ang S3.

### S3 - Talent Pool page + pull (walang migration expected)
- Route `hr-talent-pool` (needs `recruitment.view`), sidebar item sa Recruitment (`View.php` ~L109).
- Lista = **isang row per tao** (pinakabagong application) na nasa Rejected/Withdrawn/On Hold/Returned at walang active na non-parked application (o may, na may badge). Columns: pangalan, position na inaplayan, huling stage + petsa, dahilan (galing sa stage-history comment), rating. Search, filter (position/job title, huling stage, petsa), pagination 25.
- Tab 2 "Deployed": view-only, link sa Employee 201 (kung converted) o sa applicant.
- Row action **Pull to job**: dialog na may listahan ng Open jobs (match muna sa kaparehong position/department), optional note. POST `hr-talent-pool-pull`: `recruitment.manage` + CSRF; validate job Open, hindi pa may application sa job na yon; transaction: bagong application sa Screening + stage history ("Pulled from Talent Pool by <name>: <note>") + kopya ng resume document row + audit log; luma hindi ginagalaw. Flash + redirect sa bagong applicant page.
- Optional (S4, John decide): sa Job Opening page, "Suggested from Talent Pool" (parehong position) para hindi na maghanap.
- Acceptance: 1) bagong app lalabas sa board sa Screening ng bagong job; 2) luma + history buo; 3) same-job at non-Open job ay refused; 4) walang permission = 403; 5) naka-pull na tao ay wala na sa shelf list kung active na ang bagong app.
- Verify: DB test (pull ok / same job refused / closed job refused / old unchanged / resume copied / audit row), HTTP permission test, browser check, `check.php task`.

## Hindi kasama / Ask first
- Anumang migration (halimbawa: tags/skills, do-not-rehire, shelf reason column). Kapag kailangan, titigil at magtatanong.
- Drag-and-drop paglipat ng stage; auto-expiry ng shelf; email sa naka-pull na applicant; payroll/role/permission changes (walang bagong permission, gamit ang existing `recruitment.view`/`recruitment.manage`).

## Open questions (default sa itaas kung walang sagot)
1. OK ba ang pag-pull kahit may ibang active application ang tao (babala lang)?
2. Gusto mo ba ng S4 (suggested from Talent Pool sa Job Opening)?
3. Kailangan ba ng "do-not-rehire" (migration, hiwalay na slice)?

## Critical files
- `index.php` (`hr-pipeline`, `hr-applicants`, new `hr-talent-pool`, POST pull handler)
- `app/RecruitmentRepository.php` (`boardColumns`, `applications` limit/offset, `talentPool`, `pullToJob`)
- `app/View.php` (sidebar item, asset version), `public/assets/app.css` (board v3)
- Vault sync: Progress, Recruitment-and-Onboarding module note, Screens, API-Surface, Business-Rules (pull rules), Decision-Log, Open-Questions, CHANGELOG, Session-Log

## Verification (buong plan)
`php scripts/check.php task` bawat slice; `full` na may `HRIS_TEST_DB` bago ship; browser screenshots; walang commit/push/deploy nang walang sabi ni John.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-04-pipeline-scale-talent-pool.md, one slice at a time (S1, S2, stop at Checkpoint A, then S3). Gamitin ang hris-patch skill at frontend-ui-engineering para sa S2. Sundin ang CLAUDE.md: isolated test DB, vault sync bago ang final report. Magtanong bago ang anumang migration. Walang git push."
```
