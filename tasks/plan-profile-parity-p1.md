# Plan: Profile parity, patch 1 (3 small modules)

Status: DRAFT for John's approval. Specs approved by John 2026-10-31: `SPEC-hr-lee-code-field.md`, `SPEC-profile-nav-dedupe.md`, `SPEC-directory-field-labels.md` (map: `SPEC-capability-map.md`).
Rules: no commit/push/deploy, no DB write, no browser test by Claude, English UI, existing `tasks/plan.md` (My Profile) is left alone. Patch is delivered per HRIS delivery rules; vault sync before the final report.

## Order (all three are independent; smallest risk first)
1. **directory-field-labels**: label/helper strings only.
   Files: `index.php:1400` (Directory section), `app/DirectorySectionViews.php:24-26`, comment `app/EmployeeDirectoryPlacement.php:17,21`, test text `tests/adl_reporting_test.php:72`.
   Verify: `php -l`, `php tests/oc_head_office_sections_test.php`, `php tests/adl_reporting_test.php`; grep no old label left.
2. **profile-nav-dedupe**: hide dropdown "Profile" when the effective portal is employee.
   Files: `app/View.php:171`; test assertion in `tests/notification_sidebar_test.php` or `tests/employee_profile_test.php`.
   Verify: `php -l app/View.php`, render check: employee 1 link, HR 1, Admin 1; also acting-as case.
3. **hr-lee-code-field**: input in employment form (near `index.php:1385`), read in POST (`index.php:361`), validate (max 30, trim, blank to NULL), UPDATE + audit in `EmployeeRepository::updateEmployment` (`:373`).
   Verify: `php -l`, new cases in `tests/employee_profile_test.php`/`_db_test.php` on scratch DB: save, clear, 31 chars rejected, non-HR rejected.

## Risks
- `updateEmployment` has several UPDATE branches; lee_code must be saved on every path (one separate UPDATE like `work_company`, same transaction).
- `lee_code` column might be missing on an old DB: guard with `FoundationRepository::columnExists('employees','lee_code')` and hide the field if absent.
- Cache-bust `?v=` only if CSS/JS changes (none expected).

## Checkpoints
After each task: syntax + its test. After all three: full list above, then Fix sweep (same-pattern grep) and final report: Fixed / Also fixed / Left alone / Not verified.
