# Spec: Generate portal accounts for existing employees (one click + bulk)

Status: APPROVED by John 2026-11-02 and built (local only). Open questions answered.

## Objective
Imported employees have no login. Today HR must pick one by hand from the "User account" dropdown (Employee 201 > Employment > Employment information), and the Create New User form is gone, so there is no way to make the account. John wants:
1. On the 201 file, replace the "User account" dropdown with a **Generate account** button (one employee, one click).
2. A **Generate accounts for all employees without one** action (bulk), so HR does not do it one by one.
Both reuse the existing auto-account rules: email `firstnamelastname@<company domain>`, generated password, forced change on first login, HR can reveal it with the eye icon, audited.

## ASSUMPTIONS I'M MAKING (correct me now)
1. Reuse `EmployeeAccountService::issue()` unchanged for the account itself (email, password, encryption, audit, `employees.user_id`/`company_email` update). No migration.
2. Default role is **Employee**. HR role is NOT assigned in bulk; HR accounts stay a deliberate choice (per-employee button offers HR/Employee, HR choice needs `users.view_credentials`, same as Create Employee).
3. Bulk covers employees with `user_id` NULL/0 and status Active (and Probationary/Regular-type active states). Inactive/terminated/archived are skipped. Not sure about Inactive: see Q2.
4. Employees already linked to a login (including old hand-linked ones) are never touched. The old dropdown goes away for them too; to see/reveal the password HR uses Users & Access as now. Re-linking to a different existing account is removed from this screen (see Q3).
5. Who can run it: same gate as creating an HR-managed account (HR directory permission). Bulk requires `users.view_credentials` as well, because it mints many revealable passwords.
6. An employee missing first or last name, or work company, cannot get a valid email: it is skipped and listed in the result, not guessed.
7. Plain-text passwords are shown only through the existing reveal (eye) path; the bulk result lists names and emails, never passwords.

## Tech Stack
PHP (no framework), MySQL/XAMPP, `index.php` routes, `app/*` services. No new dependency, no schema change.

## Commands
- Task checks: `php scripts/check.php task`
- Full before shipping: `HRIS_TEST_DB=<scratch> php scripts/check.php full`
- Tests: `php tests/employee_portal_account_test.php`, `php tests/employee_portal_account_http_test.php`, plus new `tests/employee_bulk_account_test.php`
- Scratch DB: `php scripts/ticket_stageb_database.php prepare|cycle|apply` (run-hris-system skill)

## Project Structure
- `app/EmployeeAccountService.php` add `issueForExisting(int $employeeId,string $roleKey)` and `issueMissing(string $roleKey='EMPLOYEE'): array` (created / skipped with reason).
- `index.php` ~L1429 Employment form: replace the User account select with status text + Generate account button; new POST actions `generate_employee_account` (one) and `generate_missing_accounts` (bulk, placed on the Employees directory / Users & Access header).
- `tests/employee_bulk_account_test.php` (new), `HRIS-Vault` notes per vault sync.

## Code Style
Match the service: static methods, `RuntimeException` with plain English messages, everything inside one transaction per employee, `audit()` per account.
```php
public static function issueForExisting(int $employeeId,string $roleKey='EMPLOYEE'): array
{
    $e=EmployeeRepository::find($employeeId) ?: throw new RuntimeException('Employee not found.');
    if((int)($e['user_id']??0)>0) throw new RuntimeException('This employee already has an account.');
    return self::issue($employeeId,(string)$e['first_name'],(string)$e['last_name'],(string)$e['work_company'],$roleKey);
}
```
UI text English only, CSRF on both POSTs, buttons follow Design-System.md (confirm dialog for bulk showing the count).

## Testing Strategy
- Unit/service (isolated DB): one employee gets Employee account, linked, must_change=1, password decryptable by reveal; second click errors; duplicate names get `...2`; missing name/company skipped with reason; HR role refused without `users.view_credentials`; terminated skipped; already-linked untouched (row counts before/after).
- Bulk: N without account -> N created, rerun creates 0; one failing row does not roll back the others (per-employee transaction) and is reported.
- HTTP test: HR can POST both actions; non-HR gets 403; missing CSRF rejected.
- Not run by default: browser screenshots (needs John's go).

## Boundaries
- Always: transaction per employee; audit row per account; CSRF + permission check; show a result summary (created / skipped + reason); fresh dump + verified restore before applying to localhost.
- Ask first: assigning HR role in bulk; touching already-linked employees; creating accounts for inactive/archived employees; any schema change.
- Never: edit or delete existing accounts, passwords or roles; print passwords in the bulk result, logs or vault; push or deploy.

## Success Criteria
1. Employment tab shows "Generate account" (not the dropdown) for an unlinked employee; one click creates and links the account and shows the email.
2. Linked employee shows the email and the reveal path, no dropdown.
3. Bulk action creates accounts for all eligible employees in one click, reports skipped ones, and is safe to run twice.
4. Existing accounts and unrelated business rows unchanged (counts before/after).
5. Directory notice lists active employees without an account and clears at 0.
6. New tests fail without the change; `check.php task` passes; constraint floors not lowered.

## Fix sweep list
Other places that still offer "link an existing user" (index.php L1644, 1843-1902, `linkableEmployees()`); labels "Link later"; Users & Access banner "N active accounts are not linked to an employee" wording.

## Decisions from John (2026-11-02, answers)
- Q1 Bulk lives on the **Employees directory**. It must also **notify**: a visible notice/pop-up on the directory ("N employees have no portal account", with names and a Generate all accounts button) so no one is missed and HR can hand out the logins right away. The notice disappears at 0.
- Q2 **Active employees only.**
- Q3 **Remove "link existing account" completely** from the 201 file. Creating an employee already creates the account (D2); this patch covers the ones created before.
- Q4 Bulk = **Employee role only**. HR role is never given in bulk; it is chosen per employee on the 201 file (needs `users.view_credentials`). Default unless John says otherwise.
- Q5 **Skip and report** when a hand-made account already uses the same email. Nothing is linked or changed automatically; the result shows "email already used by an existing account" so HR can decide. Safest: never attaches a login to the wrong person.

## Added requirements from the answers
- Directory notice shows the missing-account count and a names list (name + employee number only); visible to roles with the HR directory permission.
- After a bulk run: result panel with Created (name + email) and Skipped (name + reason). Passwords stay behind the eye icon.
- Fix sweep includes deleting the link-existing handlers/UI and the unlinked-accounts banner wording.

## Open Questions
None blocking. Next after approval: plan (tasks/plan + todo), then build.
