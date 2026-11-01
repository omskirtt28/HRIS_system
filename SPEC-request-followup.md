# Spec: Team Requests - lead notification, Noted badge, follow-up

Status: DRAFT, awaiting John's approval. No code written. (Root `SPEC.md` is the sidebar spec, so this one has its own name.)

## Capability map
Single capability (one flow: notify the lead -> lead marks Noted -> lead follows up). No decomposition needed.

## Assumptions I'm making (correct me now)
1. "Immediate head / manager" for a Head Office employee = the viewer already computed by `TicketViewerService::viewersFor($employeeId)`: Head Office Manager (e.g. Charles Chan). For a Retail employee = Sales Captain/OIC of the branch and the ADL covering the branch's area. Same people who can already see the request in Team Requests, so no new access is created.
2. "Notif" = the existing in-app notification (bell + sidebar badge) via `ticket_notification_outbox` -> `NotificationService`. No email.
3. The notification is generic like the existing viewer line ("An HR request was updated...") and carries NO concern text, remarks or attachments. Incident Reports are never notified or shown (existing rule).
4. Notify on: new request submitted (not draft), and status changes (Pending -> In Progress -> Served/Rejected etc.). Not on every comment.
5. "Noted" = the lead taps a button on the request detail to say "I've seen this". It is per lead per request, shows as a badge in the Team Requests row, and does NOT approve, comment on, or change the ticket.
6. "Follow up" = a lead button that sends a short in-app nudge notification to the HR reviewers (same recipients as `hrReviewerIds()`) with the ticket number. Rate-limited to once per 24h per lead per ticket. No free text (keeps the page truly "view only" for content).
7. Team Requests stays "view only" for approve / comment / attach. Noted and Follow up are the only two new actions.
8. Needs one new table (migration 019) for Noted and follow-up records. Per project rules this needs your word before it is applied.

## 1. Objective
Leads (Head Office Manager; Retail ADL and Sales Captain/OIC) currently only see a request if they open Team Requests. They should hear about it, mark it Noted, and nudge HR when a request is stuck.

Users: Manager / Immediate Head, ADL, Sales Captain/OIC (viewers), HR reviewers (receive nudges), employees (unchanged).

Acceptance criteria
- AC1: Employee Joshua files a request -> Charles Chan (his Head Office Manager) gets an unread bell notification linking to the request. Retail staff files -> their ADL (and SC/OIC where `viewersFor` includes them) get it.
- AC2: A lead with no usable account or no `ticket.view_team` permission is skipped silently (no error, no notification); the filing still succeeds.
- AC3: Notification text contains the ticket number only, never concern content. Incident Reports produce no lead notification.
- AC4: Status changes notify the lead again with the new status label. Duplicates are prevented by the existing `dedupe_key`.
- AC5: Team Requests rows show a badge: "Not noted" (neutral) or "Noted" (green, with date). Request detail shows a Noted button for leads only (viewer_only tickets); pressing twice is harmless.
- AC6: Follow up button appears for a lead on Pending/In Progress requests; sends one notification to HR reviewers; a second press within 24h shows "Already followed up" and sends nothing.
- AC7: A lead cannot Noted/Follow up a ticket they cannot view (access check goes through `TicketRepository::find`); CSRF checked; non-leads get "Ticket not found or access denied."
- AC8: The requester, approvers and HR flows are unchanged (existing tests still pass).
- AC9: UI text in English, matches `Design-System.md`, keyboard reachable, badge not colour-only.

## 2. Tech stack
PHP 8 (strict_types), MySQL/MariaDB (XAMPP), PDO via `db()`, server-rendered views, vanilla JS/CSS in `public/assets`. No new dependencies.

## 3. Commands
```
Quick gate:   php scripts/check.php task
Full gate:    HRIS_TEST_DB=<scratch db> php scripts/check.php full
Single test:  php tests/ticket_lead_followup_test.php
Syntax:       php -l app/TicketNotifications.php
```
Isolated test DB only (skill `run-hris-system`); never run tests against `pmbsi_hris`.

## 4. Project structure
```
app/TicketNotifications.php      -> add lead recipients in enqueue() (non-incident)
app/TicketViewerService.php      -> reuse viewersFor(); no change expected
app/TicketLeadFollowup.php (new) -> markNoted(), followUp(), notedMap() (small service)
app/TicketViews.php              -> Noted/Follow up buttons on detail; badge in list
index.php                        -> two POST routes (hr-request-noted, hr-request-followup)
database/migrations/20261031_019_ticket_lead_followup.sql / .down.sql (new, ask first)
tests/ticket_lead_followup_test.php (new)
HRIS-Vault/...                   -> Progress, module note Employee-Tickets, Data-Model, Business-Rules, Screens, API-Surface, Decision-Log, Open-Questions, CHANGELOG, Session-Log
```

## 5. Code style
Match surrounding compact style: `final class`, static methods, prepared statements, `RuntimeException` for refusals, `check('...', cond)` in tests.
```php
public static function markNoted(int $ticketId): void
{
    $t = TicketRepository::find($ticketId);               // access check first
    if (empty($t['viewer_only'])) throw new RuntimeException('Ticket not found or access denied.');
    db()->prepare('INSERT IGNORE INTO ticket_lead_notes(ticket_id,user_id,kind) VALUES(?,?,"NOTED")')
        ->execute([$ticketId, (int)Auth::user()['id']]);
}
```

## 6. Testing strategy
Plain PHP tests in `tests/`, run by `scripts/check.php` on the scratch DB; write failing test first.
- Unit/integration: recipients for a Head Office employee (Manager) and a Retail employee (ADL/SC); no recipient when lead has no account/permission; Incident Report -> none; dedupe; Noted idempotent; follow-up 24h limit; access refusal for non-viewer; CSRF.
- Static UI test: badge + buttons present, English text, `aria` labels (like `hr_dashboard_ui_test.php`).
- Regression: `ticket_ho_manager_viewer_test.php`, `ticket_coe_test.php`, existing notification tests.
- Not covered: real browser/screenshot unless you ask.

## 7. Boundaries
- Always: use `viewersFor` (no new access rules), prepared statements, CSRF, generic notification text, vault sync, isolated DB, sweep the same pattern in other ticket types.
- Ask first: the migration 019 (new table), applying to `pmbsi_hris`, adding any free-text to Follow up, notifying on comments, emailing.
- Never: show Incident Reports to leads, put concern text in notifications, let leads approve/comment/attach, push/deploy, delete data, weaken `CONSTRAINTS.md`.

## 8. Success criteria
AC1-AC9 pass on the scratch DB; `php scripts/check.php task` OK; migration up / re-run / down / up clean with a guarded down; no existing business rows changed.

## 9. Decisions so far (John, 2026-10-31)
1. Retail: ADL plus Sales Captain/OIC, everyone `viewersFor` returns. DECIDED.
2. Notify on every status change. DECIDED.
3. Follow up goes to HR only (HR is the only one who can progress the request). DECIDED.
4. NO "Noted" button or record. Leads (Manager, ADL, SC/OIC) just see the request and get the notification. This overrides assumption 5, AC5 (Noted part) and the Noted tests above. DECIDED.
   Team Requests badge is kept as display only: "New" on a row while the lead's notification for that ticket is still unread (no extra table needed, derived from `notifications`). Open to drop if John prefers no badge.
5. New table (migration 019): YES, now only for Follow up records (ticket_id, user_id, created_at; 24h limit). DECIDED. Applied only after fresh dump + verified restore, isolated tests, up/re-run/down/up.

Effective scope: (a) notify leads on submit and every status change, (b) read-only "New" badge, (c) Follow up button to HR with 24h limit. `markNoted()`/`notedMap()` and the hr-request-noted route are dropped.

## 9b. Original open questions (kept for history)
1. Retail: notify ADL only, or ADL + Sales Captain/OIC too? (Default: everyone `viewersFor` returns.)
2. Notify on every status change, or only on new request and final status (Served/Rejected)? (Default: every status change.)
3. Follow up: who receives it - HR reviewers only, or also the request's current approver? (Default: HR reviewers.)
4. Is "Noted" meant to be visible to HR/the employee too, or only to the leads? (Default: only leads; employee not told.)
5. OK to add one new table (migration 019)? (Default: yes, after fresh dump and restore check.)
