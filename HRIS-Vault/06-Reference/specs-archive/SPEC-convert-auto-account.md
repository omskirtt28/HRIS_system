# Spec: Convert to Employee — automatic portal account

Status: DRAFT, waiting for John's approval. No code written.

## Objective
On **Recruitment → Applicants → Convert to Employee** (`index.php` ~L1759-1766, handler L178-204, `RecruitmentRepository::convertToEmployee` L460-), remove the "Employee portal access → Account setup" dropdown (Link account later / Link existing / Create now). Converting always generates the portal account automatically, exactly like Create Employee / bulk accounts already do (`EmployeeAccountService`).

User: HR staff converting a deployed applicant. Success = one click on "Create 201 File" gives an employee row **and** a linked login `firstnamelastname@<domain>`, with no account choices to make.

## Assumptions (correct me now)
1. "Employer" = **PMBSI**, shown automatically and read-only (the recruitment source card already prints PMBSI as the client). It is not a dropdown.
2. The missing piece is **Company (work location): 2LOG or PMBSI** — a new required select on the Employment assignment card. It is saved to `employees.work_company` and decides the email domain (2LOG → `2log.com`, PMBSI → `pmbsi.com`) via the existing `EmployeeAccountService::DOMAINS`. Convert currently never sets `work_company`.
3. Role is always **Employee** (no role picker; HR accounts are still made from Create Employee only).
4. The **Company email** input becomes a read-only preview that updates as first name, last name and Company change (reuse the preview JS at `index.php` ~L1400). The posted value is ignored; the server builds the email (same rule as Create Employee D1/D2).
5. Password is auto-generated, `must_change_password=1`, HR can view it via the existing credential reveal (`users.view_credentials`). Temporary-password and account-email inputs are removed.
6. "Link existing account" and "Link later" are removed from this screen. An already-existing user with the same email blocks the conversion with a clear message (no silent takeover).
7. No schema change: `work_company`, `user_id`, `password_enc`, `must_change_password` already exist.

## Tech Stack
PHP 8, MySQL (XAMPP), single-file `index.php` + `app/` classes, plain-PHP test scripts.

## Commands
- Task check: `php scripts/check.php task`
- Full check before ship: `HRIS_TEST_DB=<isolated db> php scripts/check.php full`
- Single tests: `php tests/employee_portal_account_test.php`, `php tests/employee_portal_account_http_test.php`

## Project Structure (files touched)
- `index.php` — form card (Company select, Employer display, preview, remove account block + its JS at ~L1789), POST handler (read `work_company`, drop account_mode/user_id/account_email/temporary_password).
- `app/RecruitmentRepository.php` — `convertToEmployee`: remove the existing/create/later branches, set `work_company`, call `EmployeeAccountService::issue` inside the same transaction; fail closed if credential key missing (`self::key()`).
- `tests/` — new/updated convert test (recruitment).
- Vault: `HRIS-Vault/02-Modules/Recruitment-and-Onboarding.md`, `Business-Rules.md`, `Screens.md`, `Progress.md`, `Session-Log.md`, `Decision-Log.md`, `CHANGELOG.md`.

## Code Style
Match existing compact PHP, `RuntimeException` with plain English message, English-only UI text:
```php
$company=trim((string)($data['work_company']??''));
if($company==='') throw new RuntimeException('Choose a Company.');
$account=EmployeeAccountService::issue($employeeId,$first,$last,$company,'EMPLOYEE');
```

## Testing Strategy
Isolated test DB only (never `pmbsi_hris`). Cases:
1. PMBSI convert → user created, `employees.user_id` linked, email `…@pmbsi.com`, `work_company='PMBSI'`, `must_change_password=1`.
2. 2LOG convert → `…@2log.com`.
3. Duplicate name → `…2@domain`.
4. Missing Company → rejected, nothing saved.
5. Credential key missing → rejected, no employee, no user (rollback).
6. Posted `account_mode`, `user_id`, `company_email` are ignored (HTTP test).
7. Second convert of same application still blocked; documents transfer unchanged.
8. Existing test files that post `account_mode` updated, not deleted or weakened.

## Boundaries
- Always: CSRF + `employees.create` permission stay; one transaction (employee + user + link); audit `AUTO_CREATE_USER`; vault sync; sweep the same pattern (other places that still offer account link choices).
- Ask first: any migration, role/permission change, making HR-role accounts creatable from Convert.
- Never: weaken `scripts/check.php`/CONSTRAINTS, touch live `pmbsi_hris` without the standing test-then-apply gate, push/deploy, show the password in logs or flash messages, commit without John's word.

## Success Criteria
- The Account setup dropdown, Existing-account and Temporary-password fields no longer render on Convert.
- Employer shows PMBSI automatically; Company (2LOG/PMBSI) is required and drives the preview and final email domain.
- After "Create 201 File" the employee profile shows the generated email and a linked account; HR can reveal the password with the existing permission.
- All cases in Testing Strategy pass; `php scripts/check.php task` passes.

## Decisions (John, 2026-11-02)
- D1: Company is **required**, defaults from the selected Department's company, editable (John unsure, took the recommendation).
- D2: Employer "PMBSI" is a read-only label only. PMBSI is the agency; the companies under it (e.g. 2LOG = Silverworks and Ocampos) are the Company choices.
- D3: Password is **not** shown on the success screen; HR uses the existing reveal button on the employee profile (always available, no time limit).

## Open Questions
1. Which companies go in the Company list? Today only **PMBSI** and **2LOG** exist in `EmployeeAccountService::DOMAINS`. John mentioned **Silverworks** and **Ocampos** too. Is Silverworks just the name of 2LOG, and is Ocampos a separate third company? If Ocampos is separate, what is its email domain (e.g. `ocampos.com`)? Adding it touches `DOMAINS`, the Company selects in Create/Edit Employee and departments (not just Convert), so it would be a separate small slice unless you want it in this one.
