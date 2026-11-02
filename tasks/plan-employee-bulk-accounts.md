# Plan: Generate portal accounts for existing employees
Spec: SPEC-employee-bulk-portal-accounts.md (approved 2026-11-02, Q1-Q5 answered). Status: draft, waiting for John's go. No migration needed.

## Verified before planning
- `EmployeeRepository.php:664` reads `$data['user_id']??0` -> null. If the dropdown is removed and the update is not changed, EVERY 201 save would UNLINK the account. T1 must make update keep the old `user_id` (ignore the posted one).
- `index.php:1429` is the dropdown; `link_user_employee` handler `index.php:287` and form ~1862 (Users & Access) is the second link UI; `linkableEmployees()` FoundationRepository:453.

## Tasks (order = dependency)
- T1 Service + guard (backend). `EmployeeAccountService::issueForExisting(id,role)` and `issueMissing()` (Active only, no user_id, one transaction per employee, skip + reason for: missing first/last/company, email already used by an existing account (never link), error). `EmployeeRepository::update` never changes `user_id` from POST. Tests first (TDD): `tests/employee_bulk_account_test.php`.
- T2 Actions. `generate_employee_account` (role EMPLOYEE default, HR only with `users.view_credentials`) and `generate_missing_accounts` (EMPLOYEE only, needs `users.view_credentials`), CSRF, redirect with flash + result panel (Created: name+email, Skipped: name+reason, no passwords). Route gate same as employee HR actions. HTTP test.
- T3 201 file UI. Replace dropdown with: linked -> email + "Reveal in Users & Access" hint; unlinked -> role select (Employee default; HR option only for who may) + Generate account button. Design-System.md, English text, a11y labels.
- T4 Directory notice. On Employees directory (employee_directory_render): alert "N active employees have no portal account" + names/employee no (cap list at ~10 with "and N more") + Generate all accounts button with confirm showing the count. Hidden at 0 and for users without permission.
- T5 Remove link UI (Q3). Delete the Users & Access "Save employee link" form, `link_user_employee` handler + gate entry, `linkableEmployees()`, unlinked-accounts banner wording, `employee_users` dropdown data if unused. Grep every caller first. Existing accounts untouched.
- T6 Sweep + vault. Fix-sweep greps (Link later, user_id POST), vault: Progress, Employee module note, Business-Rules, Screens/API-Surface, Decision-Log, Open-Questions, CHANGELOG, Session-Log, shared checkpoint.

## Risks
- Unlink-on-save (above) = highest. Test: save 201 of a linked employee, user_id unchanged.
- Bulk mints many revealable passwords: HR-credential permission required, audit row per account.
- Same-name duplicates: existing `uniqueEmail` numbers them.
- Skipped hand-made-email collisions need HR follow-up (listed, not auto-fixed).

## Verification
`php scripts/check.php task`; new service test + HTTP test; scratch DB (hris_slice15_test_*) only; before/after counts of users/employees show only the new rows; browser screenshots NOT run unless John says. Apply is data creation (accounts) on localhost pmbsi_hris only after a verified dump/restore; ask John before the live bulk run since it creates real logins.
