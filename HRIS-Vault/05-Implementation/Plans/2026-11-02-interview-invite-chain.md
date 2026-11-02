---
title: Interview invite + chain
type: plan
status: done
tags: [plan, recruitment, interview]
updated: 2026-11-02
---

# Interview invite email + interview chain (Initial -> Mid -> Final -> HR)

Status: done (2026-11-02, Slice A and B built, migration 024 applied to localhost)
Date: 2026-11-02
Session: [[Session-Log]]
Specs: SPEC-interview-map.md, SPEC-interview-invite.md, SPEC-interview-chain.md (repo root)

## Context
Pag nag-schedule ng interview (HR o Manager/ADL), walang email na lumalabas sa applicant. Gusto ni John: gamitin ang email sa application at ipadala ang Meet link (Gmail later). Gusto rin niya ng chain: ang reviewer ay puwedeng mag-pass sa susunod na interviewer, may Final na tao, at pag nag-Proceed ang Final babalik kay HR (hindi auto-Offer).
Decisions ni John: Q1 auto email + Resend, Q2 reviewer puwedeng mag-pass, Q3 Final Proceed = ready for HR, Q4 reschedule email later, Q5 short English email, Q6 applicant steps sa hiwalay na slice, Q7 first name, Q8 sending off by default, Q9 non-final Proceed = opinion lang, Q10 default (legacy Proceed = ready for HR, hindi auto-Offer), Q11 HR sees full chain.

## Mga gagawin
Slice A, interview-invite (walang migration):
1. `app/InterviewInvite.php`: send($interviewId) -> sent | skipped | failed, never throws, masked address.
2. Tawagin sa `scheduleInterview()` at `reviewerSchedule()` pagkatapos ng insert; timeline note + audit.
3. HR Resend button (`resend_interview_invite`, recruitment.manage, CSRF, 1/min).
4. Config switch `mail.send_interview_invites` (default false).
5. Tests: stubbed Mailer, no real SMTP.
Slice B, interview-chain (may migration 024, kailangan ng go):
6. Migration `20261102_024_interview_chain`: `application_reviewers.is_final`, `passed_by`; guarded down.
7. `passOn()`, `markFinal()`, `endorse()` with final ids; `decide()`: Proceed never auto-Offer.
8. UI: "Pass to next interviewer" card, Final badge/tick, HR "Final interview done, next: Offer" card, chain timeline.
9. Update `rec_endorse_manager_test` (Proceed no longer auto-Offer); new chain tests.
Sweep: other places that call moveStage to OFFER, reviewer pages must not show applicant email, button states.

## Critical files
- app/RecruitmentRepository.php, app/InterviewInvite.php (new), app/CandidateReviewViews.php, app/Mailer.php (read only), index.php
- database/migrations/20261102_024_*, tests/interview_invite_test.php, tests/interview_chain_test.php (+ http), scripts/check.php, CONSTRAINTS.md

## Verification
- Slice A check: `php scripts/check.php task`; both schedule paths send one stubbed email; reviewer HTML has no applicant email; failure keeps interview.
- Slice B: migration up/re-run/down/up on isolated DB; backup + restore verified before apply to `pmbsi_hris`; no non-HR path to Offer; `full` check with HRIS_TEST_DB.
- Not tested: real Gmail send (only after John turns the switch on with his own email), browser.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-interview-invite-chain.md. Gamitin ang hris-patch skill. Gawin muna ang Slice A (interview-invite), tapos Slice B (interview-chain). Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
