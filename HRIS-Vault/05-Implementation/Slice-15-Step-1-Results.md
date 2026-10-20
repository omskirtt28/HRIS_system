# SLICE-15 Step 1 — 2026-10-20

**Complete at the Step 1 checkpoint. Step 2 requires John's OK.**

## Delivered

- New generic ticket tables, configurable routing profiles, status transition policy, immutable number/company snapshot guards, frozen approval identity, append-only events, effect uniqueness.
- Effective-dated Manager/ADL assignments with provenance. Valid Reporting To first, branch-only site fallback second; active matching account required and self-approval forbidden. New assignments do not overwrite old payroll reporting fields or old frozen chains.
- HR-only bulk screen: `index.php?page=hr-ticket-approvers` (HR Request Setup → Ticket Approvers). Filter by company group/name, branch and approver type; unresolved report; select up to 100 employees; preview then confirm. Pages show 50 employees. Preview expires after 15 minutes; stale/replayed previews reject. Dates may start today or later. End-date changes/same-day supersessions preserve history and write audit entries.
- `company_id` references `legal_entities`; `payroll_period_id` references `payroll_cutoffs`. Company Group/Company Name/business unit identity are frozen snapshots once filed. John confirmed OFJI = Ocampo's and PMBSI is the intended employer.

## Results

| Check | Result |
|---|---|
| Initial mysqldump restore | PASS: all 75 tables, schema and row hashes matched |
| Migrations 001/002 up → re-run → down → up | PASS: table definitions and row hashes restored; identity counters may advance normally |
| Fresh backup immediately before local apply | PASS: restored to a second isolated database; all 75 table schemas/data hashes matched |
| `ticket_core_test.php` | 97 passed, 0 failed |
| `ticket_assignments_test.php` | 40 passed, 0 failed |
| `ticket_legacy_regression_test.php` | 16 passed, 0 failed |
| `adl_reporting_test.php` | 30 passed, 0 failed |
| `oc_head_office_sections_test.php` | 44 passed, 0 failed |
| `directory_direct_card_test.php` | 18 passed, 0 failed |
| `attendance_slots_test.php` | 23 passed, 0 failed |
| Browser: two-employee preview/confirm/reload, replay, CSRF, self-approval, mobile, JS errors, employee read/write denial | 11 passed, 0 failed |
| PHP syntax | All 13 changed/new PHP files passed |
| Local apply | PASS: migrations 001/002; 72 legacy tables unchanged by schema + row hashes (permission additions and audit are separately expected) |

Browser tests used synthetic accounts in an isolated database and the same assignment module; no test users were added to localhost. Desktop/mobile screenshots and individual test outputs are in private ignored storage. Playwright used the already installed runtime and Edge; no packages installed. Legacy regression exercised filing/HR review/approvals; legacy attachment upload was not browser-tested, and its code was not changed. Payroll Concern completion/late-path browser tests belong to Step 2.

Browser reproduction: set `HRIS_TEST_DB`, run `php tests/ticket_browser_setup.php`, start `php -S 127.0.0.1:8895 tests/ticket_browser_router.php`, then run `node tests/ticket_assignments_browser.cjs`. `HRIS_PLAYWRIGHT_MODULE` may point to an existing Playwright module. The router refuses normal Apache/PHP-FPM execution and a non-test database name. The real Apache route was smoke-checked after apply: unauthorized access returned 403; direct backup access also returned 403.

## Current HR action

Backfill inserted **0** rows. **363 active employees need assignments**; six inactive employees are excluded. The two Reporting To records fail the active matching-account requirement; no branch-site approvers are configured. The unresolved report lists employees for HR. No names, positions, or directory areas were guessed into assignments.

## Backup and reversal

Fresh pre-apply backup: `storage/slice15-private/before_apply_20261020_192457.sql`; verified restore and SHA256 evidence are in the private manifest. Backups contain employee data: keep private and out of Git. The Apache rules deny this storage path.

For maintenance reversal, take and restore-verify a **new** mysqldump first. Run `20261020_002_ticket_approvers.down.sql` then `20261020_001_ticket_core.down.sql`. This removes Slice-15 tables (including their new records) and only migration-owned grants; existing audit logs remain. Keep the backup as historical evidence. Remove/disable the new dispatch/menu entry when rolling back application code. Never apply down migrations during active ticket use.

Reproduce integration tests with `HRIS_TEST_DB` set to the isolated `hris_slice15_test_YYYYMMDD_HHMMSS` database and run each PHP test. They refuse the configured live database. `scripts/slice15_database.php` provides guarded backup, migration-cycle, certification and apply commands; migration 002's proven-only backfill is performed by this companion runner, not by raw SQL import alone.
