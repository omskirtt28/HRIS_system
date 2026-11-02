# Plan: Auto-generated employee portal account
Spec (approved 2026-11-02): `SPEC-employee-portal-account.md`. Vault copy: `HRIS-Vault/05-Implementation/Plans/2026-11-02-employee-portal-account.md`. Status: draft, waiting for John's go-ahead to build.

## What I found in the code (changes the plan)
1. **No `sodium` in this XAMPP PHP** (only `openssl`). Encrypted copy uses `openssl_encrypt` AES-256-GCM (random 12-byte IV + 16-byte tag, base64 in one column). Spec's "libsodium" line is superseded.
2. **`users` has no `must_change_password`, no encrypted-copy column.** Migration 020 adds both (+ `password_changed_at`).
3. **There is NO employee-facing change-password screen today** (only the Super Admin reset modal). Requirement 4 means we must build a small "Change password" page, and a login gate that forces it when `must_change_password=1`.
4. **HR role has no `users.*` permission.** `users.view` / `users.manage` are Super Admin. We need new permissions granted to Human Resources (a roles/permissions change, approved in spec D8/D11).
5. `EmployeeRepository::create()` is not transactional and `FoundationRepository::createUser()` returns void and requires `roleAssignmentError()` to pass. Account creation must wrap both in one transaction and get the new user id back.
6. `FoundationRepository::managedUser()` blocks non-Super-Admins from HR/Admin accounts; that guard must stay for role change / reset / disable. Only the *reveal* is opened to HR (D11).
7. Company is a fixed list `['2LOG','PMBSI']` (`work_company`). `employees.company_email` is UNIQUE and currently typed by HR.
8. Ticket types are config + `ticket_types` row + `TicketTypes::FILE_PERMISSIONS` + permission (copy the NETSUITE_CONCERN pattern, migration `20261020_006_netsuite`).
9. Recruitment hire (`RecruitmentRepository` ~285) also creates users with a typed password.

## Decisions I am taking (shout if wrong)
- `employees.company_email` is auto-filled with the generated address and shown read-only on Create (it IS the login email). Edit form keeps it as is.
- Email local part: first+last name, lowercase, ASCII only (accents folded, spaces/punctuation dropped), suffix (Jr., III) dropped; collision -> `2`, `3`, ... checked against `users.email` AND `employees.company_email`.
- Generated password: 12 chars, `random_int`, no 0/O/1/l/I, guaranteed upper+lower+digit.
- Existing Change role / Reset password / Disable stay Super Admin for staff accounts; HR may Reset only Employee-role accounts (needed for the forgotten-password ticket).
- Employee changes own password -> must know current one, new one 10+ chars with letter+number, sessions of that user other than the current one are revoked, encrypted copy rewritten, audit row.

## Order (backend first, UI second; one migration)
T1 Migration 020 (columns, permissions, ticket type) -> T2 service -> T3 create-employee wiring -> T4 change-password + login gate -> T5 reveal + HR view -> T6 forgotten-password ticket -> T7 remove old UI/handlers + sweep -> T8 tests/vault.
Tasks, files, verify steps: `tasks/todo-employee-portal-account.md`.

## Frontend / backend split
- Backend: migration, `EmployeeAccountService`, `EmployeeRepository::create` hook, `FoundationRepository` (reveal, store copy, reset rewrites copy), `Auth` gate, ticket type config.
- Frontend (index.php views): Role field + read-only email preview on Create Employee, remove "User account / Link later" (create + edit + Phase 2C modal), Users & Access: remove Create New User card, working eye, HR read-only view; new Change password page; ticket form is config-driven.

## Risks and mitigations
- **Encryption key**: stored in `config/config.local.php` (git-ignored; example file gets a placeholder). Missing/invalid key -> account creation fails closed with a clear error; never falls back to storing plaintext. Lost key = copies unreadable, login unaffected, HR uses Reset.
- **Existing 20 users have no encrypted copy**: eye shows "Not available. Reset password to enable viewing" until a reset/change happens. No bulk backfill (can't, passwords are hashed).
- **Permissions change** (new perms to Human Resources): migration inserts only; down removes only those rows. Verify with before/after permission diff that no existing grant changed.
- **HR can reveal the Super Admin password** (John's D11): audit row per reveal, rate limit 10/min per HR user, reveal via POST only.
- Atomicity: employee+account in one transaction; roster import / recruitment paths unchanged except recruitment uses the same generator (T7, small).
- Role dropdown shows only Human Resources + Employee; server validates against that allow-list (not trusting the form).
- Role clean-up (HR Leave/Timekeeping/Recruiter/Payroll) is NOT in this plan (spec D12).

## Test scenarios (full list in the todo)
1. Create Juan Dela Cruz / PMBSI / Employee -> `juandelacruz@pmbsi.com`, linked, must_change=1, hash verifies, copy decrypts to same password.
2. Create a second Juan Dela Cruz / 2LOG -> `juandelacruz@2log.com`; third PMBSI one -> `juandelacruz2@pmbsi.com`.
3. Force an error after the employee insert -> neither employee nor user exists.

## Not verified / out of scope
Browser checks only if John asks. `check.php full` needs `HRIS_TEST_DB`. No push, no deploy. Applying migration 020 to `pmbsi_hris` follows the standing test-then-apply rule.
