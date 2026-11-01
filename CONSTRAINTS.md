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
| `tests/employee_profile_test.php` PASS count (pure, no DB) | 84, 0 FAIL | My Profile service and views behavior |
| `tests/notification_sidebar_test.php` | passes (3 scenarios) | Sidebar badge mapping that My Profile and the folded sidebar share |
| `tests/sidebar_fold_test.php` PASS count (pure, no DB) | 16, 0 FAIL | Folded rail contracts: full logo, group-icon flyout, hidden scrollbar, tooltip on hover/focus, a11y labels, asset versions |
| `tests/hr_dashboard_ui_test.php` PASS count (pure, no DB) | 20, 0 FAIL | HR dashboard v2: .hd2 scope, tiers, segmented period control, tokens only, focus-visible, reduced motion, responsive, shared CSS untouched |
| `tests/employee_profile_db_test.php` PASS count (scratch DB) | 14, 0 FAIL | Own-data-only access, audited reveal, unlinked account |
| `tests/employee_profile_http_test.php` PASS count (scratch DB) | 55, 0 FAIL | Real login and routes for employee, HR, client, anonymous |
| `tests/lee_code_db_test.php` PASS count (scratch DB) | 8, 0 FAIL | Lee code save, 30-character limit, blank clears, duplicate message, audit |
| `tests/profile_content_http_test.php` PASS count (scratch DB) | 51, 0 FAIL | Downloads, FAQs, pay pages, HR-only writes, client/admin access, upload and FAQ edge cases, HR form labels |
| `tests/ticket_lead_followup_test.php` PASS count (scratch DB) | 30, 0 FAIL | Team Requests leads: notified on filing and status changes, Follow up to HR once per 24h, New badge, no concern text, Incident Reports excluded |
| PHP files that must parse | all (163 at 2026-10-31, after the portal content patch) | Floor above |
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
