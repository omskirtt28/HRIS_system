# Spec: interview-invite (email the applicant when an interview is scheduled)

Status: APPROVED by John 2026-11-02 (Q4 to Q8 answered yes; greeting uses first name; sending switch starts off). No code written. Module of SPEC-interview-map.md (first in build order).
Date: 2026-11-02. Author: Claude.

## Objective
When HR or a reviewer (Manager/ADL/TC) schedules an interview, the applicant automatically gets an email with the interview type, date and time, and the location or Meet link. The address comes from the application (`applicants.email`); nobody retypes it and reviewers never see it. Success: scheduling an interview from either form sends one email, a failure never blocks scheduling, and HR can resend.

Decisions (John, 2026-11-02): Q1 send automatically plus a Resend button. Q4 reschedule email is out of scope (no reschedule action exists). Q5 short English text from "PMBSI HR", no salary or internal notes. Q6 tracker does not show the link in this slice. Real Google Calendar/Meet creation is out of scope; the scheduler still pastes the Meet link.

## Tech stack
PHP (existing app, no framework), MySQL `pmbsi_hris` (tests use an isolated DB), `app/Mailer.php` (SMTP, Gmail App Password in `config/config.local.php` 'mail'). No new dependency, no migration (result is recorded on the application timeline via `timelineNote` and the audit log).

## Commands
```
Check (before handing back): php scripts/check.php task
Full check (before shipping): HRIS_TEST_DB=<isolated db> php scripts/check.php full
New test:                     php tests/interview_invite_test.php
HTTP test (reviewer path):    php tests/interview_invite_http_test.php
```
Test file names mirror existing `tests/rec_endorse_manager_test.php` style.

## Project structure
```
app/RecruitmentRepository.php   scheduleInterview() and reviewerSchedule() call the new sender; new resendInterviewInvite()
app/InterviewInvite.php         (new, small) builds subject/body, looks up the applicant email, calls Mailer::send, never throws
app/CandidateReviewViews.php    reviewer form: text hint "The applicant is emailed this link" (no email shown)
index.php                       HR action resend_interview_invite (recruitment.manage, CSRF); button on HR applicant page
tests/                          interview_invite_test.php (+ http test)
HRIS-Vault/                     Progress, Employee/Recruitment module note, Business-Rules, CHANGELOG, Session-Log
```

## Behaviour
1. On successful insert into `interviews`, call `InterviewInvite::send($interviewId)`.
2. Skip silently (with a timeline note "Invite not sent: email is not set up") when `Mailer::configured()` is false or the applicant has no valid email.
3. Send is wrapped in try/catch; on failure write a timeline note "Invite email failed" and audit `INTERVIEW_INVITE_FAILED`; the interview row stays.
4. On success: timeline note "Invite emailed to j***@gmail.com" (masked) and audit `INTERVIEW_INVITE_SENT`.
5. HR applicant page lists each interview with its invite state (from the latest timeline/audit note) and a Resend button (recruitment.manage). Resend is rate limited to 1 per interview per 60 seconds.
6. Email body: "Hello {first name}, your {type} for {job title} is scheduled on {date, time (Philippine Time)}. Location / link: {value}. Reply to this email if you cannot attend. PMBSI HR". Subject: "Interview schedule: {job title}". Header injection guarded (Mailer already strips CR/LF); link is plain text.
7. Mail sending is behind a config switch `mail.send_interview_invites` (default false) so no real email goes out until John turns it on.

## Code style
Match the file: short static methods, early `throw new RuntimeException('English message')` for user errors, prepared statements, `audit()` after the action.
```php
public static function send(int $interviewId): string {   // returns 'sent' | 'skipped' | 'failed'
    if (!Mailer::configured() || !(cfg('mail')['send_interview_invites'] ?? false)) return 'skipped';
    // ... look up applicant email, build body, try { Mailer::send($to,$subject,$body); return 'sent'; } catch (Throwable $e) { return 'failed'; }
}
```

## Testing strategy
- Unit (`interview_invite_test.php`, isolated DB, Mailer stubbed): sent when configured and email valid; skipped when not configured or switch off; failed does not remove the interview row; body contains type/date/link and no salary or notes; address masked in the timeline note; Resend refused inside 60 seconds; Resend needs recruitment.manage.
- HTTP: reviewer schedules, page HTML never contains the applicant email; HR resend needs CSRF.
- No real SMTP in any test. Manual check of one real send only after John turns the switch on.

## Boundaries
- Always: CSRF, permission check on resend, English text, mask the address in UI/logs, audit sent/failed, tests first on the scratch DB, vault sync.
- Ask first: a migration (e.g. an invite log table), turning real sending on, any new permission, adding a mail library.
- Never: show applicant email or phone to reviewers, commit mail credentials, send real email from tests, push or deploy, touch live `pmbsi_hris` before test-then-apply.

## Success criteria
1. Scheduling from the HR form and the reviewer form each produce exactly one stubbed email to the applicant's stored address.
2. With sending off, not configured, or failing, the interview is still saved and HR sees why on the timeline.
3. Resend works for HR only, once per minute per interview.
4. Reviewer pages never contain the applicant email.
5. `php scripts/check.php task` passes with no floor lowered.

## Open questions
- **Q7:** Should the applicant's name in the email be the first name only (default) or full name?
- **Q8:** OK that the config switch starts off, so the first real send is a test with your own email before applicants get any?
