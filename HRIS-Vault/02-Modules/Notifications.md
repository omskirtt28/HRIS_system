---
title: Notifications
type: module
status: draft
tags: [module, hris, v1]
updated: 2026-10-17
---

# Notifications

## 2026-10-29 - Shared shell search, sidebar scroll and unread badges
- Implemented in NotificationService/NotificationModule/View.php/hris-app.js/notifications.css. GET and successful read responses include sidebar_counts; header and bell share the same all-unread summary, independent of the latest 10 items.
- Existing ticket-event:<event_id>:<recipient_user_id> dedupe keys resolve event/ticket/type metadata. Each row maps once: own request -> HR Concerns; own incident -> My Incidents; other readable incident -> Incident Cases for an HR reviewer, otherwise My Incidents for an accessible participant; completed NetSuite follow-up -> Accounting Follow-up for members; assigned Manager/ADL approval -> HR Request Inbox; remaining HR review -> Payroll Concerns for PAYROLL_CONCERN, otherwise HR Request Queue. Ownership wins. Assigned-step history includes older unread updates: these are unread-record badges, not pending-ticket totals.
- Existing probation, seasonal and maternity kinds -> Probation & Seasonal when that menu is accessible. Unavailable/unmapped destinations remain in the bell total. Existing incident read/mine clauses, accounting membership and permissions are reused; no title guessing, new recipients, event types or schema.
- Zero hidden, >99 displays 99+, full accessible count label. Opening a sidebar menu never marks read. Confirmed individual/all reads synchronize both badges; failures retain displayed counts with a retry message. Stale GET responses cannot overwrite newer read counts. Malformed IDs are rejected rather than cast to mark-all.
- PHP/JS syntax, three source-only scenarios, sweep and diff checks passed. tests/notification_sidebar_test.php adds three mapping regression scenarios (syntax checked only, not executed). Browser/app/DB behavior, query performance, actual scroll and rendered accessibility remain unverified. No migration, commit or deployment.

## Purpose

Tell the right person the one thing they need to act on, with a link straight to it. Nothing else.

A notification system in an HRIS fails in one of two directions, and both are fatal:

- **Too few** — approvals sit for a week, HR approves on the manager's behalf, and the audit trail becomes fiction.
- **Too many** — everything is noise, the emails are filtered, and the system is trusted less than a chat message.

The design response: notifications are **event-driven, deduplicated, digestible and deep-linked**, with a small fixed catalogue rather than a configurable firehose.

## Scope

### In scope

- Event catalogue: a closed, documented list of notifiable events
- In-app notification centre with read state
- Email delivery with a queue, retries and a dead-letter state
- Deep links that authenticate and land on the target item
- Digest and batching to prevent floods
- Deduplication and suppression rules
- Per-user preferences within policy limits
- Escalation and reminders for overdue approvals
- Scheduled reminders: probation ending, contract expiry, document expiry, DTR lock approaching, filing deadlines
- A delivery log for audit and troubleshooting

### Out of scope

- SMS and push delivery → later, and SMS cost in PH makes it a deliberate decision
- Chat integrations → later
- Marketing or announcement broadcasts → a different feature with different consent rules
- The approval logic itself → the owning module
- Sending sensitive documents as attachments → forbidden, BR-708

## Event catalogue

| Event code | Trigger | Recipient | Channel | Mandatory |
|---|---|---|---|---|
| `LEAVE_FILED` | Leave request submitted | Approver | In-app + email | Yes |
| `LEAVE_DECIDED` | Leave approved or rejected | Employee | In-app + email | Yes |
| `LEAVE_CANCELLED` | Approved leave cancelled | Approver, employee | In-app | No |
| `LEAVE_REMINDER` | Leave pending beyond SLA | Approver, then their manager | Email | Yes |
| `OT_FILED` | Overtime filed | Approver | In-app + email | Yes |
| `OT_DECIDED` | Overtime decided | Employee | In-app | No |
| `DTR_CORRECTION_FILED` | Correction requested | Approver, payroll | In-app | Yes |
| `DTR_CORRECTION_DECIDED` | Correction decided | Employee | In-app | No |
| `DTR_LOCK_APPROACHING` | Configurable days before lock | All employees with attendance in the cut-off | In-app + email digest | Yes |
| `DTR_LOCKED` | DTR locked | Payroll officer | In-app | No |
| `PAYSLIP_RELEASED` | Payroll run released | Each paid employee | In-app + email (link only) | Yes |
| `PAYROLL_RUN_READY` | Run computed and awaiting review | Payroll officer, reviewer | In-app | No |
| `CHANGE_REQUEST_FILED` | Employee requests a sensitive field change | HR admin | In-app | Yes |
| `CHANGE_REQUEST_DECIDED` | HR decides | Employee | In-app | Yes |
| `PROBATION_ENDING` | Configurable lead time before probation ends | HR admin, manager | Email | Yes |
| `CONTRACT_EXPIRING` | Lead time before a fixed-term contract ends | HR admin, manager | Email | Yes |
| `DOCUMENT_EXPIRING` | Lead time before a 201 document expires | HR admin, employee | Email digest | No |
| `ONBOARDING_TASK_DUE` | Task due or overdue | Task owner | Email | Yes |
| `CLEARANCE_ITEM_PENDING` | Clearance item awaiting sign-off | Signatory | Email | Yes |
| `FINAL_PAY_SLA_WARNING` | Statutory final pay window approaching | HR admin, payroll | Email | Yes |
| `INTERVIEW_SCHEDULED` | Interview booked | Interviewer | Email | No |
| `OFFER_RESPONSE` | Offer accepted or declined | HR admin | In-app | No |
| `RATE_TABLE_UNVERIFIED` | A run blocked by an unverified rate table | Sys admin, payroll | In-app + email | Yes |
| `LOGIN_FROM_NEW_DEVICE` | Login from an unrecognised device | The user | Email | Yes |
| `PASSWORD_CHANGED` | Password changed or reset | The user | Email | Yes |
| `ACCOUNT_LOCKED` | Lockout threshold hit | The user, sys admin | Email | Yes |

**Mandatory** events cannot be switched off in preferences. Security events and anything that gates pay are mandatory on purpose.

## Key Entities

| Entity | Purpose | Notable fields |
|---|---|---|
| `notification_events` | The closed catalogue. | `id`, `code`, `name`, `default_channels`, `is_mandatory`, `template_code`, `category`, `active` |
| `notifications` | One notification to one recipient. | `id`, `event_code`, `recipient_user_id`, `subject_entity_type`, `subject_entity_id`, `title`, `body`, `deep_link_path`, `created_at`, `read_at`, `dedupe_key`, `digest_group` |
| `notification_deliveries` | One delivery attempt per channel. | `notification_id`, `channel` (`IN_APP`/`EMAIL`), `status` (`QUEUED`/`SENT`/`FAILED`/`SUPPRESSED`/`DEAD`), `attempts`, `last_attempt_at`, `error_detail`, `provider_message_id` |
| `notification_preferences` | Per-user opt-outs for non-mandatory events. | `user_id`, `event_code`, `channel`, `enabled` |
| `notification_templates` | Subject and body templates. | `code`, `channel`, `subject_template`, `body_template`, `locale` |
| `scheduled_reminders` | Date-driven reminders, idempotent per subject. | `id`, `event_code`, `subject_entity_type`, `subject_entity_id`, `due_on`, `lead_days`, `fired_at`, `dedupe_key` |

## Core Flows

### Flow 1 — Event to delivery

1. A module completes a business transaction and emits an event **after** the transaction commits — never inside it. A notification must not be able to roll a payroll release back, and a committed release must not fail to notify.
2. The dispatcher resolves recipients from the event and its subject: the approver of this request, the employees in this run, the owner of this task.
3. Dedupe check against `dedupe_key`. If an identical notification for the same subject and recipient already exists within the window, suppress it.
4. Preference check for non-mandatory events.
5. An in-app notification row is created immediately — in-app is the reliable channel.
6. Email deliveries are queued. A worker sends with bounded retries and exponential backoff; terminal failure marks `DEAD` and is visible to the sys admin rather than disappearing.
7. Every attempt is logged.

### Flow 2 — Digest and batching

1. Events marked for digest do not send individually.
2. They accumulate in a `digest_group` and send as one email on a schedule — for example, one onboarding-task digest per owner per day, one document-expiry digest per week.
3. A payroll release for 300 employees produces 300 individual notifications (each person needs their own) but **one** summary to the payroll officer, not 300.

### Flow 3 — Escalation

1. A pending approval past its SLA fires a reminder to the approver.
2. Past a second threshold, it escalates to the approver's manager with both named.
3. Escalation is capped — it stops at the configured level rather than reaching the CEO, and it never auto-approves.

### Flow 4 — Deep link

1. Every notification carries a `deep_link_path` to the specific item, not to a dashboard.
2. An unauthenticated click authenticates first, then lands on the target.
3. If the item is already resolved, the target page says so plainly — "this request was already approved by X" — rather than erroring.

### Flow 5 — Scheduled reminders

1. A daily job scans for date-driven conditions: probation ending, contract expiring, document expiring, DTR lock approaching, final pay SLA.
2. Each is idempotent per `(event_code, subject, due_on)` so a re-run never re-notifies.
3. Reminders stop once the condition is resolved.

## Business Rules

- **BR-710** — Events are emitted after the business transaction commits, never within it.
- **BR-711** — Notification delivery failure must never fail or roll back the underlying business action.
- **BR-712** — Mandatory events cannot be disabled by user preference.
- **BR-713** — Notifications are deduplicated by `dedupe_key` within a configurable window.
- **BR-714** — Sensitive documents are never attached to an email; the email links to the portal. BR-708.
- **BR-715** — Notification bodies contain no salary figures, government ID numbers, medical details or leave reasons — only that an item exists and awaits action.
- **BR-716** — Scheduled reminders are idempotent per subject and due date.
- **BR-717** — Escalation never auto-approves and is capped at a configured level.
- **BR-718** — Every delivery attempt is logged with its outcome; terminal failures are visible to an administrator.
- **BR-719** — Recipients are resolved at send time from current reporting lines and delegations, so a stale cached recipient is never used.

> [!warning] Content restraint is a privacy control
> BR-715 exists because notification emails pass through mail servers, phone lock screens and shared inboxes. "Your payslip for 1–15 October is available" is acceptable. The net pay in the subject line is a data breach waiting for a shoulder to look over. See [[Security-and-Compliance]].

## Functional Requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-730 | The system shall define a closed catalogue of notifiable events with default channels and a mandatory flag. | Must |
| FR-731 | The system shall emit events after the originating transaction commits. (BR-710) | Must |
| FR-732 | The system shall provide an in-app notification centre with read state per user. | Should |
| FR-733 | The system shall deliver email notifications through a queue with bounded retries and a terminal failed state visible to administrators. (BR-718) | Should |
| FR-734 | The system shall not fail or roll back a business action because a notification failed. (BR-711) | Must |
| FR-735 | The system shall deduplicate notifications by key within a configurable window. (BR-713) | Should |
| FR-736 | The system shall support digest batching for designated events. | Should |
| FR-737 | The system shall include a deep link to the specific subject item in every notification. (US-710, FR-720) | Should |
| FR-738 | The system shall notify approvers when a request requires their action. (US-710) | Should |
| FR-739 | The system shall notify employees of leave decisions with the decision remarks. (US-711) | Should |
| FR-740 | The system shall notify employees when a payslip is released, without including any pay figure. (US-712, BR-715) | Should |
| FR-741 | The system shall send scheduled reminders for probation ending, contract expiry and document expiry. (US-713) | Should |
| FR-742 | The system shall notify employees before the DTR lock date for a cut-off they have attendance in. | Should |
| FR-743 | The system shall escalate overdue approvals to the next level, capped, and shall never auto-approve. (BR-717) | Should |
| FR-744 | The system shall allow users to disable non-mandatory notifications per event and channel. (US-714, BR-712) | Could |
| FR-745 | The system shall exclude salary figures, government ID numbers, medical details and leave reasons from notification content. (BR-715) | Must |
| FR-746 | The system shall never attach a payslip or 2316 to an email. (BR-714) | Must |
| FR-747 | The system shall resolve recipients at send time from current reporting lines and active delegations. (BR-719) | Must |
| FR-748 | The system shall make scheduled reminders idempotent per subject and due date. (BR-716) | Must |
| FR-749 | The system shall provide administrators a delivery log searchable by recipient, event and status. | Should |
| FR-750 | The system shall notify a user of a password change, an account lockout and a login from an unrecognised device. | Should |

## Edge Cases

| Case | Expected handling |
|---|---|
| Employee has no email address | In-app only. The in-app row still exists so the audit trail is complete. |
| Approver on leave | Delegation resolves the recipient at send time. If no delegate, escalate after the SLA rather than leaving it unseen. |
| Payroll released for 300 employees | 300 individual employee notifications, one summary to the officer. Queue them; do not send synchronously during release. |
| Email server down for hours | Queue retains and retries; in-app already delivered. Nothing is lost and nothing blocked. |
| Bounced address | Marked `DEAD` after retries and surfaced to HR so the address gets fixed, rather than silently discarded. |
| Employee notified then the request is cancelled | Prior notification stands as history; a cancellation notification follows. Never rewrite the earlier one. |
| Same leave edited and resubmitted three times | Dedupe window collapses them into one approver notification. |
| Reminder job re-run after a crash | Idempotency keys prevent duplicate sends. |
| Separated employee still receiving notifications | Account disabled on separation stops delivery. Check recipient status at send time. |
| Notification references an item the recipient can no longer see | Deep link lands on a clear "no longer available to you" page, not a permission error stack. |
| Manager with 40 pending items | Digest with a count and a link, not 40 emails. |
| Clock skew causing a reminder a day early | Reminders compute in `Asia/Manila` explicitly, not in server-local time. |

## Open Questions

- [ ] Is there an SMTP account or transactional email service available? → Q16 in [[Open-Questions]]
- [ ] Which events warrant email versus in-app only, given the company's actual email habits?
- [ ] Approval SLA before a reminder, and before escalation?
- [ ] Is SMS worth the cost for field employees with no email?
- [ ] Should notifications be in English or Tagalog, or configurable?
- [ ] Who monitors dead-lettered deliveries?
- [ ] Is a background worker available, or must the queue be driven by cron?

## Related

[[Leave-Management]] · [[Attendance-and-DTR]] · [[Payroll]] · [[Employee-Records]] · [[Self-Service-Portal]] · [[Auth-and-RBAC]] · [[Recruitment-and-Onboarding]] · [[Offboarding-and-Clearance]] · [[Security-and-Compliance]] · [[Architecture]] · [[Business-Rules]] · [[Data-Model]] · [[Screens]]

Hub for HR requests: [[Employee-Tickets]] · UI rules: [[Design-System]]
