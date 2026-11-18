# Capability Map: Interview scheduling and hand-off (Candidates for review)

Status: APPROVED by John 2026-11-02 (Q1 yes, Q2 yes, Q3 yes; Q4-Q6 take the defaults). SPEC-interview-invite.md drafted; SPEC-interview-chain.md comes after its review. No plan or code yet.
Date: 2026-11-02. Author: Claude. Named SPEC-interview-map.md because the repo already has SPEC-rec-*.md files.

## What John asked for (2026-11-02, screenshots of "Schedule an interview")
1. The applicant's email is already typed on the application, so it should be used automatically: the Google Meet link entered in "Location or meeting link" is sent to the applicant. Later this connects to Gmail.
2. Multi-stage interviews: after e.g. Mid Interview the candidate can be passed on to someone else, like Endorse to Manager, with a named person for the final interview. When that manager marks the candidate Final, HR takes over and continues the next process.

## What already exists (verified in code)
- `app/Mailer.php`: small SMTP sender for Gmail (App Password in `config/config.local.php` 'mail', never in git). `Mailer::configured()` says if it is set up. So "connect to Gmail" is mostly already there.
- Schedule form: `CandidateReviewViews.php:86` (Manager/ADL side) and the HR form (`index.php` action `schedule_interview`). Types are a fixed list: Initial / Mid / Final Interview. Saved in `interviews` (application_id, interview_type, scheduled_at, location_or_link, interviewer_id, status, notes). Nothing is emailed today.
- Applicant email lives in `applicants.email`; the reviewer view deliberately hides it (no email/phone for reviewers).
- Endorse to reviewers: `RecruitmentRepository::endorse` / `application_reviewers` (several recipients, each with own decision PROCEED / NOT_SUITABLE / ANOTHER_INTERVIEW). PROCEED currently jumps the stage straight to OFFER; all NOT_SUITABLE returns to HR.
- Reviewers (Manager/ADL) can already schedule interviews themselves (`reviewerSchedule`) but cannot pass the candidate on to another person; only HR endorses.

## Gaps
- G1: No email goes out when an interview is scheduled, from either form.
- G2: A reviewer cannot hand the candidate to the next interviewer, and nothing marks who the final interviewer is.
- G3: "Final" is not a decision. PROCEED goes to Offer without HR's say, and the chain "Initial -> Mid -> Final -> HR continues" is not enforced anywhere.

## Modules

| Module id | Responsibility | Depends on |
|---|---|---|
| interview-invite | When an interview is scheduled (HR or reviewer), email the applicant the type, date/time, location or Meet link, using the applicant's stored email, via `Mailer`. Log sent/failed. | existing Mailer, interviews |
| interview-chain | A reviewer can pass the candidate to another Manager/ADL/TC (name chosen, optionally marked final interviewer). Marking Final hands the candidate back to HR with the next-step prompt (Offer). | interview-invite (so each new round also emails the applicant), existing endorse/reviewers |

Build order: interview-invite -> interview-chain.

## Assumptions I am making (correct me now or I will proceed with these)
1. The email goes out from the one company Gmail account in `config.local.php` (App Password over SMTP, as Mailer works now). A real Google Calendar/Meet invite via the Google API is a later, separate step.
2. The reviewer never sees the applicant's email; the server looks it up and sends. The HR page shows "Invite sent to a***@gmail.com" or "Not sent".
3. If Mailer is not configured or sending fails, the interview is still saved; HR sees a visible "Email not sent" note and can resend. A failure never blocks scheduling.
4. "Auto-fill" means: the email address is taken from the application, not typed again. The Meet link itself is still pasted by the scheduler (we do not create Meet links in this slice).
5. Sending an email to an outside person is outward-facing, so there is a switch: sending is off until you turn it on in config.

## Questions for John (answers change the specs)
- Q1: Send the email automatically on Schedule, or show a "Send invite to applicant" button first (safer, one extra click)? Default: automatic, with a Resend button.
- Q2: Who can pass a candidate on: HR only (as today), or also a Manager/ADL who is interviewing? Your message says the manager passes it on, so default: reviewers can add another reviewer.
- Q3: "Final" = the manager chooses "Final interviewer / this is the final round" when passing on, and the last reviewer's Proceed means "ready for HR". Then the candidate lands on HR's page with a "Final interview done, next: Offer" card instead of auto-jumping to Offer. OK?
- Q4: Should the applicant also get an email when the interview is rescheduled or cancelled? Default: yes for reschedule, out of scope for now (no reschedule action exists yet).
- Q5: Email text: short, English, from "PMBSI HR", no salary or internal notes. OK?
- Q6: Does the applicant tracker (rec-track-steps, Initial / Mid / Final) show the scheduled date and Meet link too? Default: not in this slice.

## Boundaries (both modules)
- Always: CSRF, server-side scope (reviewer sees only candidates endorsed to them), English-only UI and email text, audit entries, tests first on the scratch DB, vault sync.
- Ask first: any migration (e.g. an `interview_notifications` log table, a `is_final` flag on `application_reviewers`), any new permission, turning real sending on, adding a dependency.
- Never: expose applicant email/phone to reviewers, commit mail credentials, push or deploy, send real email from tests (tests use a stub), touch live `pmbsi_hris` before the test-then-apply checks.

## Next step
Reply with approval of the map (or changes) and answers to Q1-Q3. Then I write `SPEC-interview-invite.md` first, stop for review, then `SPEC-interview-chain.md`.
