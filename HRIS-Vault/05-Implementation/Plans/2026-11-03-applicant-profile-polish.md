---
title: Applicant profile polish
type: plan
status: draft
tags: [plan, recruitment, ui]
updated: 2026-11-03
---

# Applicant profile polish

Status: done (T1-T3 built and applied to localhost 2026-11-03; not committed)
Date: 2026-11-03
Session: [[Session-Log]]
Spec: `SPEC-applicant-profile-polish.md` (approved by John 2026-11-03)

## Context
Tatlong request ni John sa HR Applicant Profile: (1) malaking walang laman na space sa kanan, (2) "Manager Review" ay hindi tama kasi hindi lang manager ang reviewer, gawing **Hiring Review**, (3) hiwalay na interview scorecard (per-criteria 1-5 + recommendation), iba sa Screening rating (Negative/Fair/Outstanding).
Open Questions 1-4 ng spec ay hindi pa nasasagot. Defaults na gagamitin: 5 criteria (Communication, Job knowledge, Attitude, Culture fit, Overall impression); routed interviewers + HR lang ang makaka-score; scorecard optional (walang block sa Offer); applicant status page ay "Hiring Review" din.

## Mga gagawin (isang slice bawat task, ayon sa order)
1. **T1 Rename to Hiring Review (labels lang, walang schema).**
   - Palitan: `app/functions.php:47`, SQL `IF(...,"Manager Review",...)` sa `RecruitmentRepository.php` (~23, 60, 62, 65, 78, 81, 149), `index.php:140`, `CandidateReviewViews.php` (4, 31, 48), stepper at status page.
   - I-update ang mga test na nag-a-assert ng lumang text (`rec_endorse_manager_*`, `interview_chain_*`, `interview_invite_test.php`). Hindi luluwagan ang test.
   - Verify: `grep -ri "manager review"` = role-name uses na lang; `php scripts/check.php task`.
2. **T2 Two-column layout ng `hr-applicant`.**
   - Main column kaliwa (header card, stepper, Stage & actions, Schedule interview, Resume/Application/Interviews). Sticky sidebar kanan (Screening rating, Interview scorecard, Endorse to Hiring Review, Timeline). One column sa < 1100px. Design-system tokens lang.
   - Fix sweep: tingnan ang parehong max-width cap sa `hr-applicants` at review pages; ilista lang kung malaki.
   - Verify: screenshots 1440 / 1024 / 375, light at dark, walang horizontal scroll, tab order (`run-hris-system`).
3. **T3 Interview scorecard** (STOP muna sa migration para sa OK ni John).
   - Migration `20261103_030_interview_scorecards.sql` + guarded `.down.sql`; mysqldump + restore check muna; up, re-run, down, up sa isolated DB.
   - `RecruitmentRepository::saveInterviewScorecard()` (1-5 validation, recommendation enum, routed-scorer check server-side, Interview stage o mas huli, update-not-duplicate, timeline + audit, hindi gumagalaw ng stage).
   - Card sa sidebar + "Interview 4.2 / 5" sa header; POST handler sa `index.php` (CSRF + permission).
   - Tests: `interview_scorecard_test.php`, `interview_scorecard_http_test.php`.
4. **T4 Vault sync + final report** (Fix sweep format).

## Critical files
- `index.php` (`hr-applicant` ~1695-1725, POST ~90-180)
- `app/CandidateReviewViews.php`, `app/RecruitmentRepository.php`, `app/functions.php`
- `database/migrations/` (030, bago)
- `tests/` (updated + 2 bago)
- Vault: `02-Modules/Recruitment-and-Onboarding.md`, `03-Design/Data-Model.md`, `Business-Rules.md`, `Screens.md`, `Progress.md`, `Session-Log.md`, `Decision-Log.md`, `Open-Questions.md`, `CHANGELOG.md`

## Risks
- Rename: may mga SQL string at test na hardcoded; i-grep lahat bago at pagkatapos. Stage code hindi gagalawin.
- Layout: sticky sidebar vs mahabang resume viewer; i-check sa 1024px.
- Scorecard: pinakamalaking panganib ay ang scorer-routing rule; i-reuse ang screening scorer check, huwag mag-imbento ng bago.
- Migration: ask-first; walang apply sa `pmbsi_hris` hangga't walang OK ni John at pasado ang lahat ng check.

## Verification
- `php -l` sa lahat ng binago; `php scripts/check.php task` (full kapag may `HRIS_TEST_DB`).
- Isolated-DB tests, browser screenshots, migration cycle.
- Walang pagbabago sa existing business rows pagkatapos ng apply.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-03-applicant-profile-polish.md (spec: SPEC-applicant-profile-polish.md). Gamitin ang hris-patch skill. Gawin T1 at T2 muna, tapos T3. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
