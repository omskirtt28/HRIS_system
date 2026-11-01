# Plan: Team Requests lead notification + Follow up (SPEC-request-followup.md, approved 2026-10-31)

Skills: hris-patch, think-before-coding, auth-and-authorization, security-discipline, migration-safety, testing-with-discernment, frontend-ui-engineering (badge/button), design-system.

## Dependency order
1. T1 migration 019 (ticket_lead_followups) -> 2. T2 recipients -> 3. T3 followUp service + route -> 4. T4 UI -> 5. T5 tests/vault.

## Tasks
- [ ] T1 Migration `20261031_019_ticket_lead_followups.sql` + `.down.sql`: table(ticket_id, user_id, created_at, FK ticket/users, KEY ticket_id,user_id,created_at). Down guarded: refuses if rows exist.
  - Verify: fresh dump + restore check, scratch DB up / re-run / down / up. Files: 2 sql (+ Data-Model.md).
- [ ] T2 `TicketNotifications::enqueue()` (non-incident branch): add lead user ids from `TicketViewerService::viewersFor(subject_employee_id)` = manager + sc + oic + adls where `can_view`, skip requester/actor, skip `manager_filer`, skip INCIDENT_REPORT and DRAFT. Fires on every event already passed to enqueue (submit + each transition). Generic body for non-requester stays (no concern text).
  - Verify: test Head Office employee -> Manager; Retail -> ADL + SC/OIC; no account -> skipped, filing still OK; dedupe. Files: app/TicketNotifications.php.
  - Check first: comment/attachment events also call enqueue (TicketService 338/360); decide to skip leads for COMMENT_ADDED/ATTACHMENT_ADDED (John said status changes only) .
- [ ] T3 `app/TicketLeadFollowup.php`: `followUp(int $ticketId)`: `TicketRepository::find` must be viewer_only, status PENDING_APPROVAL/PENDING_HR/IN_PROGRESS/SUBMITTED, not incident; 24h limit via table; then NotificationService::create to each HR reviewer (reuse hrReviewerIds, make it public/shared) with ticket no only, dedupe_key `lead-followup:{ticket}:{lead}:{date}`. Route `hr-request-followup` (POST, CSRF) in index.php.
  - Verify: non-viewer refused, second press in 24h refused, HR gets notification, no text leak. Files: new service, index.php, TicketNotifications.php.
- [ ] T4 UI (TicketViews.php + css): detail page, viewer_only: "Follow up with HR" button (disabled + "Followed up <time>" when limited). Team list: "New" badge (text, not colour only) when lead has unread notification whose link is that ticket. Check that opening the ticket marks notification read; if not, mark read on open for viewers.
  - Verify: static UI test, English only, aria-label, 4 states (default/hover/focus/disabled). Files: TicketViews.php, assets css.
- [ ] T5 Tests `tests/ticket_lead_followup_test.php` (RED first), register in scripts/check.php + CONSTRAINTS floor; run `php scripts/check.php task`; regress ticket_ho_manager_viewer_test, ticket_coe_test.
  - Vault: Progress, Employee-Tickets, Data-Model, Business-Rules, Screens, API-Surface, Decision-Log, Open-Questions, CHANGELOG, Session-Log.

## Risks
- Retail branch with no ADL/area -> nobody notified (already a known problem in the viewer report); not fixed here, listed.
- Viewer notification link goes to a view-only page; fine.
- Notification volume: every status change x up to 3 leads. Accepted by John.
- No browser check unless asked; apply to pmbsi_hris only after all gates pass (standing workflow), no push/deploy.

## Sweep list
Other ticket types (COE, Payroll Concern, OIC, Accounting) share enqueue(); confirm leads only for types shown in Team Requests; confirm same "view only" copy consistency.
