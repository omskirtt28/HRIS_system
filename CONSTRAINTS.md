# CONSTRAINTS

Quality bar for this repository (PHP 8.2 on XAMPP, no framework, plain-PHP test scripts, no CI yet).
Written 2026-10-31. Agents and people read this before changing code and never weaken it to make a change pass; a lower bar goes in the Exceptions table with an owner and an expiry date.

Detected: `composer.json` (PHP >= 8.2, one runtime dependency), no PHPUnit, linter, static analyser or CI workflow, no coverage driver (no Xdebug/PCOV), tests are standalone scripts in `tests/`. Gitleaks, Semgrep and osv-scanner are not installed, and project rules forbid installing tools as part of a patch.
Defaults chosen (no interview, John asked for a fast chain): block on a failing check, hold today's numbers, checks must finish quickly.

## Commands
```
php scripts/check.php fast     # seconds: syntax + secrets on files changed in the working tree
php scripts/check.php task     # fast + tests that need no database (about 3 s)
php scripts/check.php full     # syntax + secrets on all files, then all suites (about 15 s without the database suites)
HRIS_TEST_DB=pmbsi_hris_test php scripts/check.php full   # also runs the scratch-database suites
```
Scratch copy for database suites: see the header of `tests/employee_profile_db_test.php`. Never point HRIS_TEST_DB at `pmbsi_hris`; the suites refuse it.

## Floor (every change)
| Rule | Command | Why |
|---|---|---|
| Every PHP file parses | `php -l` (run by `check.php`) | A parse error takes a whole page, or the whole site, down on shared hosting |
| Every JS file parses | `node --check` (run by `check.php`) | A broken script silently disables the feature that uses it |
| No credentials in the repo | pattern scan in `check.php` (private keys, AWS keys, Gmail app passwords, literal `password` config values; `*.example.php` placeholders allowed; matches are never echoed) | Mail and DB credentials live only in the git-ignored `config/config.local.php` |
| No suppression to get green | review (`/constraints guard`) | Skipped or deleted tests and lowered floors hide regressions |

## Enforced numbers
| Metric | Floor | Reason |
|---|---|---|
| `tests/employee_profile_test.php` PASS count (pure, no DB) | 85, 0 FAIL | My Profile service and views behavior |
| `tests/notification_sidebar_test.php` | passes (3 scenarios) | Sidebar badge mapping that My Profile and the folded sidebar share |
| `tests/sidebar_fold_test.php` PASS count (pure, no DB) | 16, 0 FAIL | Folded rail contracts: full logo, group-icon flyout, hidden scrollbar, tooltip on hover/focus, a11y labels, asset versions |
| `tests/hr_dashboard_ui_test.php` PASS count (pure, no DB) | 20, 0 FAIL | HR dashboard v2: .hd2 scope, tiers, segmented period control, tokens only, focus-visible, reduced motion, responsive, shared CSS untouched |
| `tests/employee_profile_db_test.php` PASS count (scratch DB) | 14, 0 FAIL | Own-data-only access, audited reveal, unlinked account |
| `tests/employee_profile_http_test.php` PASS count (scratch DB) | 55, 0 FAIL | Real login and routes for employee, HR, client, anonymous |
| `tests/lee_code_db_test.php` PASS count (scratch DB) | 8, 0 FAIL | Lee code save, 30-character limit, blank clears, duplicate message, audit |
| `tests/profile_content_http_test.php` PASS count (scratch DB) | 51, 0 FAIL | Downloads, FAQs, pay pages, HR-only writes, client/admin access, upload and FAQ edge cases, HR form labels |
| `tests/ticket_lead_followup_test.php` PASS count (scratch DB) | 30, 0 FAIL | Team Requests leads: notified on filing and status changes, Follow up to HR once per 24h, New badge, no concern text, Incident Reports excluded |
| `tests/employee_portal_account_test.php` PASS count (scratch DB) | 102, 0 FAIL | Auto-generated portal account: generators, AES-256-GCM copy (fails closed), atomic create, change-own-password, reveal/reset permissions, forgotten-password ticket, removed Create New User |
| `tests/employee_portal_account_http_test.php` PASS count (scratch DB, php -S) | 23, 0 FAIL | End to end over HTTP: HR creates an employee, views the password, the employee is forced to change it, HR sees the new one, employee cannot reveal |
| `tests/employee_bulk_account_test.php` PASS count (scratch DB) | 35, 0 FAIL | Accounts for existing employees: one click, bulk, skip rules (missing name, no company, hand-made email, archived), role limits, Legal entity fallback, 201 save keeps the link, link-existing removed |
| `tests/employee_bulk_account_http_test.php` PASS count (scratch DB, php -S) | 19, 0 FAIL | Over HTTP: directory notice, Generate account on the 201 file, CSRF and permission refusals, bulk result shown once |
| `tests/remove_account_test.php` PASS count (scratch DB) | 30, 0 FAIL | Remove portal account: soft removal keeps history and the 201, email freed and reusable, login dead, scope guards (self, Super Admin, Admin-portal only by Super Admin), restore keeps the pre-removal status, restore and its refusals, removed accounts hidden from other lists |
| `tests/remove_account_http_test.php` PASS count (scratch DB, php -S) | 17, 0 FAIL | Over HTTP: Remove account in the menu, CSRF and permission refusals, removed person cannot sign in, regenerate, restore |
| `tests/rec_manpower_intake_test.php` PASS count (scratch DB) | 28, 0 FAIL | Manpower intake: tickets listed for HR, approve creates one linked request and completes the ticket, decline needs a reason (requester sees it) and creates no row, the ticket screens cannot serve a manpower ticket without it, non-HR refused, employers added or reused, double approve refused, manual rows and filled counts untouched |
| `tests/rec_manpower_intake_http_test.php` PASS count (scratch DB, php -S) | 19, 0 FAIL | Over HTTP: HR sees awaiting tickets with Approve and Decline, CSRF and permission refusals, approve with a new employer, double submit, decline filter, the old create_manpower action is gone (D5) |
| `tests/rec_jobs_test.php` PASS count (scratch DB) | 52, 0 FAIL | Job openings: create from an approved request (prefill math, only APPROVED/PARTIALLY_FILLED, employer from the request), walk-in job with one silent bridge request, validation, Draft/Published/Closed state machine, expiry, edit never moves code/slug/request/employer, one public-visibility rule, no delete |
| `tests/rec_jobs_http_test.php` PASS count (scratch DB, php -S, port 8096) | 51, 0 FAIL | Over HTTP: HR create form removed (D5), Post job and Job Openings page, 403/CSRF, publish/unpublish/close/reopen, the public sees PMBSI only (no employer on home, careers, job, apply, track or search), expiry, walk-in job |
| `tests/rec_convert_auto_account_test.php` PASS count (scratch DB) | 18, 0 FAIL | Convert to Employee always creates the portal account: PMBSI and 2LOG email domains, numbered duplicates, work_company saved, forged account fields ignored, missing or unknown Company and missing credential key save nothing, hand-made user never taken over, one convert per application, Company must match the Department |
| `tests/rec_convert_auto_account_http_test.php` PASS count (scratch DB, php -S, port 8103) | 22, 0 FAIL | Over HTTP: Convert form has no account choices, Employer label, required Company, read-only email preview; CSRF and permission refusals, missing or mismatched Company saves nothing, forged account fields ignored, 2LOG and PMBSI accounts, one convert, HR reveal and forced first password change |
| `tests/rec_endorse_manager_test.php` PASS count (scratch DB, migration 023) | 37, 0 FAIL | Endorse to Manager / ADL / SC / TC: recruitment.review grants, Manager Review label, who can be picked, ticket-requester default, several recipients, scope (no email/phone, other users get nothing), decisions and round rules, reviewer scheduling never moves the stage back, endorse only at the card's stages, offer types |
| `tests/rec_endorse_manager_http_test.php` PASS count (scratch DB, php -S, port 8097) | 33, 0 FAIL | Over HTTP: HR card and forged ids, CSRF and permission refusals, each reviewer sees only their own candidate and resume, decisions, notifications, reviewer-scheduled interview |
| `tests/rec_screening_first_test.php` PASS count (scratch DB, migration 029) | 11, 0 FAIL | New applications start at Screening, no Applied dot in the tracker, a legacy Applied row behaves as Screening (board, filter, counts, next step) |
| `tests/interview_invite_test.php` PASS count (scratch DB, Mailer stubbed) | 17, 0 FAIL | Interview invite email: sent to the stored applicant address from the HR and reviewer forms, masked timeline note, never blocks scheduling (off, failed, no address), Resend permission and 1-minute limit |
| `tests/interview_invite_http_test.php` PASS count (scratch DB, php -S, port 8101) | 12, 0 FAIL | Over HTTP: schedule hint, timeline note when email is off, Resend button, CSRF and permission refusals, reviewer pages never show the applicant email |
| `tests/interview_chain_test.php` PASS count (scratch DB, migrations 023 + 024) | 29, 0 FAIL | Interview chain: final interviewer ticks, pass-on rules and scope, Proceed never moves to Offer, ready-for-HR (final and legacy), mark final, chain shown to HR, re-endorse reset |
| `tests/screening_rating_db_test.php` PASS count (scratch DB, migration 028) | 18, 0 FAIL | Screening rating: enum validation, who may rate by site type and routing, recruiter slot, convert gate, converted lock |
| `tests/screening_rating_http_test.php` PASS count (scratch DB, php -S, port 8098) | 14, 0 FAIL | Over HTTP: rating card, CSRF and forged-rating refusals, routed Manager vs un-routed ADL, Site type on the job form |
| `tests/interview_chain_http_test.php` PASS count (scratch DB, php -S, port 8102) | 19, 0 FAIL | Over HTTP: pass-on card, CSRF and permission refusals, new interviewer scope, final interviewer page, HR Final interview done card and Move to Offer |
| `tests/hr_palette_test.php` PASS count (scratch DB, migration 027) | 32, 0 FAIL | HR appearance palettes: html attributes, labels, allowed values, HR-portal-only gate (Admin and employees refused), save and read back, forged or unknown values change nothing, default light |
| `tests/hr_palette_http_test.php` PASS count (scratch DB, php -S, port 8104) | 21, 0 FAIL | Over HTTP: HR page renders the saved palette on the html tag and a 5-option radio list, Employee and Admin pages keep the one-click toggle, unknown stored value renders Light, HR saves a palette, unknown or injection values 422, missing or wrong CSRF 419, GET 405, Employee and Admin 403, signed-out refused |
| `tests/hr_palette_css_test.php` PASS count (no DB) | 21, 0 FAIL | Palette stylesheet: four palettes with every token, rules scoped to data-palette (Light and Dark untouched), brand and status colours never overridden, text and sidebar text >= 4.5:1 in each palette, legacy tokens follow |
| PHP files that must parse | all (182 at 2026-11-02, after the manpower intake patch) | Floor above |
| JS files that must parse | all (5 at 2026-10-31) | Floor above |

A suite may gain checks. It may not lose them: the runner fails when a suite's PASS count drops below its floor. Raise the floor in this file in the same change that adds checks (`/constraints ratchet`).

## Measured only (reported, not gated)
| Metric | Today | Note |
|---|---|---|
| Line coverage | not measurable | No Xdebug/PCOV driver installed. Do not invent a number; add a gate only after a driver exists. |
| Other suites in `tests/` | not part of the floor | `hr_dashboard_test`, `incident_*`, `payroll_*`, etc. need their own isolated databases; run them when you touch their area. `directory_direct_card_test` passed 18/0 against a scratch copy on 2026-10-31. |

## Dimensions considered and dropped
| Dimension | Decision | Why |
|---|---|---|
| Code scanning (Semgrep) | dropped | Not installed; not installing tools in patches. Security review stays a manual step (`security-review` on the diff). |
| Dependency audit (osv-scanner) | dropped | Not installed; one runtime dependency (smalot/pdfparser) in `vendor/`. Check it by hand before upgrades. |
| Accessibility (axe-core) and web vitals (Lighthouse) | dropped | No tooling installed. Accessibility and performance are checked by review (`design:accessibility-review`, `/webperf`) and John's visual acceptance. |
| Architecture boundaries (dependency-cruiser) | dropped | PHP project, no module boundary tool; the `app/*Service`, `*Views`, `*Repository` split is by convention. |
| Mutation testing (Stryker) | dropped | JS/TS tool; no PHP equivalent installed. |

## Exceptions
| Rule | What is excused | Owner | Expires | Reason |
|---|---|---|---|---|
| (none) | | | | |

## Where each check runs
Edit loop (seconds): `check.php fast`. Task end (under 90 s): `check.php task`, plus the scratch-database suites when you touched My Profile. Before shipping: `check.php full` with `HRIS_TEST_DB` set. There is no CI; running these is the gate.

## Project rules that stay above this file
No commit, push or deploy without John's word; scratch databases only for tests; English-only UI; vault sync per patch (see `CLAUDE.md`).
