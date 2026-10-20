# SLICE-15 Stage B - 2026-10-20

Approved specification: the user's Stage A/B conversation, including the approved Manpower table-name exception and Branch Transfer company-label rules. No dependency on Ticket-Master-Prompts.md.

## Delivered in order

1. ATM + ID (`e70e210`): separate types, required reason, own requests, duplicate-open warning without blocking, HR-only service lifecycle, no payroll/PII effects. This commit also tracks the existing untracked SLICE-15 engine dependencies needed to run the features.
2. Uniform (`5808e1e`): relational request record, configurable XS-3XL sizes, quantity 1-10, SW/OFJI labels, target branch and ID lace; company/branch/status queue filters.
3. NetSuite (`7fd5107`): HR final completion; later Accounting follow-up; explicit additive Accounting membership carrying ticket.accounting.view; separate authored/timestamped HR and Accounting remarks with retained revisions. No Accounting direct-URL, attachment, list, or comment access before HR completion unless independently authorized as the requester or HR.
4. Manpower (`0eafddf`): ticket_manpower_requests only; Branch ADL/HO Manager then HR; required/conditional fields; frozen approver identities on returned resubmissions; server date_served. Recruitment manpower_requests unchanged.
5. Branch Transfer (`45a5f70`): observed current placements, locked-cutoff checks, atomic idempotent BRANCH_TRANSFER effect, prior-day closure and non-overlapping new placement, manual destination approver selection. Future transfers stay In Progress until their effective date. Authorized filing for others keeps the filing account as requester and prohibits self-approval.
6. Incident + forms (final Stage B commit): restricted SUBMITTED feed without approval steps; ALL/STAFF_ONLY comments and event filtering; protected attachments; five blank print-only HTML forms. Final shared review also fixed Payroll Concern selection retention, type-aware list labels/dates, and HR-stage cancellation ownership, and enforced NetSuite's Pending/Complete-only filed lifecycle.

## Data and authorization boundaries

- Payroll date/cutoff belongs only to Payroll Concern. Every other type stores payroll_period_id NULL. Transfer effective dates independently reject FINALIZED cutoff runs.
- SW/OFJI are labels. OFJI means Ocampo's. Transfers do not change legal_entity_id, business_unit_id, or employee company/group fields. PMBSI remains the legal entity.
- employee_placements did not exist in the live database. Migration 008 seeded the observed current branch only. Unknown historical start dates are NULL with an observation timestamp; no historical start was invented. Later employees without placement history receive the same observed-current record during authorized transfer filing.
- A transfer whose source placement no longer matches Employee 201 is blocked for HR reconciliation. Completion requires employees.manage plus tickets.approvers.manage and a valid destination Manager/ADL selection.
- Requesters come from the account. Named approvers and HR retain scoped access. Sensitive viewers can read Incident Reports; this does not grant HR actions. Accounting membership does not replace the account's primary role and is rechecked on access.
- HR cannot return/reject while Manager/ADL approval is pending. Return/reject needs remarks. Only HR changes HR-stage service statuses. Events remain append-only; effects retain unique(ticket_id,effect_type).
- Existing TA/OB/OT/leave routes, business code, records and Payroll Ticket Review were not changed. Recruitment business records were not changed. Tests used isolated restored databases and synthetic accounts/tickets, never live test records.

## Accounting seed results

All seven exact employee-number seed attempts returned **MISSING_ACTIVE_ACCOUNT**:

16856, 18405, 17862, 18280, 18087, 17790, 18055.

No Accounting access was granted to these unlinked/inactive accounts. HR can resolve the active accounts and add/remove membership in **HR Request Setup > Accounting Members**. Jeniffer Dela Cruz Maglalang needs explicit manual HR account selection; no name-based account match was attempted.

## Validation

After each slice, focused service cases plus the existing Payroll Concern, core, assignment and legacy payroll/leave regressions passed. Browser coverage was expanded in the same order.

Final checks:

| Check | Result |
|---|---|
| Cumulative new service cases | 92/92 |
| Payroll Concern service regression | 71/71 |
| Ticket core | 97/97 |
| Approver assignments | 40/40 |
| Legacy TA/OB/OT/leave and Payroll Ticket Review | 16/16 |
| Payroll Concern full browser regression | 23/23 |
| New service/Incident/print browser journeys | 11/11 |
| Existing ADL / HO sections / directory / attendance regressions | 30/30, 44/44, 18/18, 23/23 |
| PHP syntax and diff whitespace checks | Passed |
| Migration 004-009 up/rerun/down | Original normalized definitions and row hashes restored for all six |
| Used-data down scripts | Refused rollback with SQLSTATE 45000; schema/data fingerprints unchanged |
| Printed Travel, Big IR, ATM Specimen, Turnover, Clearance | Each one A4 page; all five rendered pages visually inspected |

Browser checks include real multipart attachments, direct-URL/attachment denial, staff-only note filtering, Accounting completion gate, file-for-others without an HR employee record, self-approval denial, manual transfer approver assignment, and mobile filing.

## Backup and application evidence

Migrations 004-009 are applied to the configured local database. Before every migration, mysqldump backups were restored into isolated databases and compared against schema/data fingerprints. A second fresh verified dump/restore immediately preceded each local apply. Original business rows remained unchanged during each migration; the added comment visibility column defaults existing comments to ALL without altering their original fields.

Private backups, manifests, logs and browser/print evidence are in Apache-denied, Git-ignored `storage/slice15-private/`. Per-migration manifests: stageb-4.json through stageb-9.json. Uploaded ticket content stays outside Git in Apache-denied `storage/ticket_attachments/`; only the deny rules are tracked.

Reproduction entry points: `scripts/ticket_stageb_database.php`, `tests/ticket_services_test.php`, `tests/ticket_services_browser.cjs`, `tests/payroll_concern_browser.cjs`, and `tests/ticket_migration_roundtrips.php`. Tests require the isolated HRIS_TEST_DB guard. No remote deployment or push was performed.
