# SLICE-15 Step 2 — 2026-10-20

**Complete and stopped at the requested Step 2 checkpoint.**

## Employee and reviewer flows

- Employee Home adds **HR Concerns** / “Payroll concern and other HR requests”. The four existing tiles and their destinations remain intact. The sidebar adds **Requests → HR Concerns**. Recent requests merges the two engines with explicit “HR Concern” and “Payroll & Timekeeping” labels and the appropriate detail links.
- **My HR Requests** (`hr-requests-my`) → **File an HR request** (`hr-request-new`): choose Payroll Concern; server-prefilled Employee Code, Name, Branch/Section, company snapshots; required Date and Explanation. Save draft, edit, submit, return/resubmit and cancel use audited transitions. One cutoff per ticket; a filed concern's date/cutoff cannot be moved.
- **HR Request Inbox** (`hr-request-inbox`) serves the named current Manager/ADL. **Payroll Concern Queue** (`hr-payroll-concerns`) serves authorized HR, with status/company-name/branch/period filters. Generic detail is `hr-request`.
- Normal chain: assigned Manager or ADL → HR. Missing valid approver blocks normal filing with an ask-HR message. FINALIZED, deadline-expired and calendar-CLOSED concerns are flagged late and route directly to HR, including employees without an approver. No original finalized data is edited.
- HR may complete with **No change**, or record a unique **manual-processing instruction** for the next later OPEN cutoff. Target state/deadline is rechecked under transaction locks. If no later open cutoff exists, leave the ticket in progress until one is available. No pay computation or automatic payroll application is performed.
- Comments and attachments have scoped access, server validation and audit events. Attachments are private (five per ticket, 10 MB each; extension plus detected MIME); downloads reauthorize the ticket. Updates use optimistic versions; initial submission keys and the unique effect key prevent duplicate work.
- In-app notifications contain no concern text or email recipient. Durable outbox retries run through the existing `scripts/run_notifications.php` job; `scripts/run_ticket_notifications.php` is an in-app-only retry command. Failure cannot roll back a committed business action.

## Results

| Check | Result |
|---|---|
| Pre-test mysqldump restore | PASS: all 85 table definitions and row hashes matched |
| Migration 003 up/re-run/down/up | PASS: original definitions and row hashes restored, identity counters may advance |
| Down with used ticket data | PASS: refused destructive reversal; all definitions/data preserved |
| Fresh pre-apply mysqldump restore | PASS: all 85 table definitions and row hashes matched |
| Local migration 003 | PASS: new tables/type; 84 other existing tables unchanged |
| Payroll Concern integration | 71 passed, 0 failed |
| Ticket core | 97 passed, 0 failed |
| Approver assignments | 40 passed, 0 failed |
| Old Payroll Ticket Review / TA / OB / OT / leave flows | 16 passed, 0 failed |
| Existing ADL / HO sections / directory / attendance | 30 / 44 / 18 / 23 passed; 0 failures |
| Payroll Concern browser | 23 passed, 0 failed |
| Assignment-screen browser regression | 11 passed, 0 failed |
| Existing notification CLI integration | PASS on isolated database; external email disabled |
| PHP / JavaScript syntax | PASS |
| Apache smoke checks | New authenticated pages redirect to login; new CSS 200; private attachment/backup paths 403 |

Browser coverage: existing Home tile destinations, new tile/subtitle/sidebar/type selector/prefills, upload validation with retained explanation, real multipart upload/protected download, comments, labeled recent requests, mobile Home/detail, HO and branch approval chains, HR queue filters, late filing/manual outcome, completion replay, ticket/attachment IDOR, HR-only queue, CSRF, and hidden self-approval actions. Desktop/mobile screenshots are in private ignored storage. Tests use synthetic accounts in the isolated DB and a copy of the actual front controller with only the database/email configuration substituted; real application modules/views/assets are used. No test accounts or tickets were added to localhost.

Integration coverage additionally proves frozen chains and company snapshots, server-derived identity, illegal/terminal transitions, returned-ticket history, self-approval (including HR), inactive-session rejection, finalized snapshot/attendance preservation, targets locked after selection, audit append-only behavior, and durable/deduplicated notifications under an injected database failure.

## Backup, rollback and reproduction

- Fresh local backup: `storage/slice15-private/before_step2_apply_20261020_200104.sql`. SHA256, restore verification and application time are recorded in `step2-manifest.json`. Backups/fixtures/screenshots and uploaded test files are ignored by Git and denied by Apache.
- Migration: `20261020_003_payroll_concern.sql`; reverse: `20261020_003_payroll_concern.down.sql`. The reverse works before use; it deliberately refuses once tickets/submission keys/outbox data exist. Preserve filed records and append-only audit history by rolling back application code while retaining schema, or use an explicitly approved verified-backup restoration plan. Never silently delete filed concerns to force a rollback.
- Set `HRIS_TEST_DB` to the isolated database and run `php tests/payroll_concern_test.php`. Run existing PHP tests separately with the same isolated DB (and a writable private session directory).
- Browser: `php tests/payroll_concern_browser_setup.php`; start `php -S 127.0.0.1:8896 tests/payroll_concern_browser_router.php`; run `node tests/payroll_concern_browser.cjs`. Uses existing Playwright/Edge; `HRIS_PLAYWRIGHT_MODULE` can select an existing module. External mail is disabled in the test copy. No dependency installation or live-data fixtures.

HR still needs to resolve the existing missing approver assignments for normal open-cutoff filing through **Ticket Approvers → Unresolved report**. The late HR-only path remains available. No statutory rates, payroll formulas, employee company assignments, or old ticket routes were changed.
