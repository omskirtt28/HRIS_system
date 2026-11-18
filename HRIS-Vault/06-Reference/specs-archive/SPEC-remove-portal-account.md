# Spec: Remove portal account (Users & Access)

Status: APPROVED by John 2026-11-02 and BUILT (local, migration 021 applied to localhost). Built scope: HR may remove HR and employee accounts; Super Admin never; Admin-portal only by Super Admin; users.remove granted to HR_USER only.

## Decisions from John (2026-11-02)
- Q1 **All HR users** can remove accounts (every user with the HR role, not only `users.view_credentials` holders). Still protected: Super Admin/Admin accounts, self, last active Super Admin.
- Q2 Soft removal. Only the **account** goes; employee data stays.
- Q3 **Yes** to a Removed accounts view (with Restore).
- Q4 OK: additive `users.removed_at`.
- Q5 **Yes** clear `employees.company_email` when it matches. Main use case: a wrong account was generated (e.g. company not set, email ended up `@pmbsi...`), HR removes it, sets the 201 file correctly, then clicks Generate account again and gets the right email.
(Saved as `SPEC-remove-portal-account.md`, not `SPEC.md`, because the repo already uses one `SPEC-<feature>.md` per feature.)

## Objective
HR can remove a portal account from the Users & Access list (`admin-users`, row kebab menu). After removal the person can no longer sign in and the account is gone from the list. The **Employee 201 file is untouched** (profile, documents, attendance, payroll, leave history all stay); the employee simply shows "no portal account" again and can get a new one later through Generate account.

Today the kebab menu only offers Activate/Deactivate, and only for `users.manage`. HR (as in the screenshot) sees "No available actions".

## Key finding that shapes the design
`users.id` is referenced by many tables with **no ON DELETE rule**: `audit_logs.user_id`, `leave_requests.created_by`, `material/job/offer/interview/endorsement/review/deployment` tables, ticket tables, `ticket_lead_followups`. A hard `DELETE FROM users` fails (or would erase audit history) for anyone who ever did anything. `employees.user_id` is `ON DELETE SET NULL`, so the employee link is safe either way.
So "remove" must be a **soft removal**: the row stays for history, but the account is dead and disappears from every list.

## ASSUMPTIONS I'M MAKING (correct me now)
1. **Soft removal, no schema-breaking delete.** Account is marked removed: status `REMOVED` (or `removed_at` timestamp, additive column), password invalidated, active sessions signed out, `employees.user_id` set to NULL and `employees.company_email` cleared only if it equals the account email. History rows (audit, tickets, approvals) keep pointing at the user row and still show the name.
2. **The email is freed**, so a new account for the same person can be generated (`firstnamelastname@domain`) without the "email already used" skip. Implementation: rename the stored email to a tombstone (e.g. `removed+<id>+<original>`), keep the original in the audit row.
3. **Who can remove:** HR with `users.view_credentials` may remove **Employee-role (and HR-role?) accounts**, same scope rule as reset password. Only Super Admin may remove HR, Admin, Super Admin, Client, Manager accounts. See Q1.
4. **Cannot remove:** your own account, the last active Super Admin, anything you cannot manage by the scope rule above.
5. **Confirmation dialog** names the person and says the employee record is kept. A removal needs no typed phrase.
6. **Removal is not undone from the UI.** Recovery = Generate account again (new login, new password). The old login is not restored. See Q2.
7. Removed accounts are hidden from Users & Access and from the "N accounts not linked to an employee" banner. Not shown in a trash view (Q3).
8. Audit row written: who removed whom, role, email, employee id. No passwords in the audit.
9. Applies to **portal accounts** of every type including the "Not linked to an employee" ones in the screenshot (John Wayne, Rafael Villamor, Winston Cruz), subject to rule 3/4.

## Tech Stack
PHP (no framework), MySQL/XAMPP, `index.php` routes, `app/FoundationRepository.php`, `app/EmployeeAccountService.php`. No new dependency. At most one additive migration (status value or `removed_at`), see Q4.

## Commands
- Task check: `php scripts/check.php task`
- Full before shipping: `HRIS_TEST_DB=<scratch> php scripts/check.php full`
- Tests: new `tests/remove_account_test.php` (service) and `tests/remove_account_http_test.php` (route/permission/CSRF)
- Scratch DB and migration cycle: `php scripts/ticket_stageb_database.php prepare|cycle|apply` (run-hris-system skill)

## Project Structure
- `app/FoundationRepository.php` new `removeUser(int $userId, int $actorId)` next to `toggleUserStatus`: one transaction, scope checks, unlink employee, tombstone email, kill sessions, audit.
- `index.php` ~L302 new POST action `remove_user` (CSRF + permission), ~L438 return page map, ~L1854 menu item "Remove account" with confirm dialog; query that lists users excludes removed ones.
- `app/EmployeeAccountService.php` generate/issue must ignore removed accounts when checking duplicate email.
- `database/migrations/<date>_remove_account.sql` + down script (only if Q4 = additive column).
- Vault: Progress, Employee-Records / Users module note, Business-Rules, Data-Model (if migration), Screens, API-Surface, Decision-Log, CHANGELOG, Session-Log.

## Code Style
Match existing handlers: static repository methods, `RuntimeException` with plain English message, one transaction, audit call.
```php
public static function removeUser(int $userId,int $actorId): void
{
    $u=self::userById($userId) ?: throw new RuntimeException('Account not found.');
    if($userId===$actorId) throw new RuntimeException('You cannot remove your own account.');
    // role scope + last-super-admin guard here
    Database::tx(function() use($u,$actorId){ /* unlink employee, tombstone email, invalidate password, end sessions, audit */ });
}
```
UI text English only. Menu item uses the existing `.users-row-menu` pattern, danger style per `HRIS-Vault/03-Design/Design-System.md`.

## Testing Strategy
Isolated DB only (never `pmbsi_hris` directly).
- Service: remove Employee account -> user can no longer log in, sessions ended, `employees.user_id` NULL, employee row otherwise identical (compare before/after), audit row exists, email reusable (Generate account works afterward).
- Guards: cannot remove self; cannot remove last Super Admin; HR cannot remove Admin/Super Admin; user with history (audit/ticket rows) removes without FK error; double-remove errors cleanly.
- HTTP: HR allowed, Employee role 403, missing CSRF rejected, removed account absent from list and from the "unlinked" banner.
- Migration (if any): up, re-run, down, up; down refuses while removed rows exist.
- No browser screenshots unless John says go.

## Boundaries
- Always: transaction, audit row, CSRF + permission check, confirm dialog, keep every employee/business row, fresh dump + verified restore before applying to localhost.
- Ask first: any hard `DELETE` of a user row, removing HR/Admin/Super Admin accounts as HR, adding the migration, restoring removed accounts.
- Never: delete or edit Employee 201 data, delete audit/ticket/approval history, push or deploy, apply to localhost if any check fails.

## Success Criteria
1. Kebab menu shows "Remove account" for allowed rows (including HR viewing Employee accounts); disabled/hidden for self and out-of-scope rows.
2. After confirm, the account disappears from Users & Access and cannot log in; active sessions are ended.
3. The employee's 201 file opens exactly as before and shows no portal account; Generate account works again.
4. Accounts with history remove without errors; history still shows the person's name.
5. Business-row counts before/after identical except the one user row and the one `employees.user_id` link.
6. New tests fail without the change; `check.php task` passes; constraint floors not lowered.

## Fix sweep list
Other lists that join `users` and must hide removed accounts (approver pickers, ticket assignee pickers, recruiter pickers, notifications recipients, `linkableEmployees()`); "No available actions" fallback wording; unlinked-accounts banner.

## Open Questions
1. **Q1 Who can remove?** Recommended: HR with `users.view_credentials` for Employee-role accounts; Super Admin for everything else. OK?
2. **Q2 Soft vs hard.** Recommended soft removal (above) because of the FKs. True hard delete would only be possible for accounts with zero history. Agree with soft?
3. **Q3 Need a "Removed accounts" view / restore?** Recommended no for now.
4. **Q4 Schema.** Recommended additive column `users.removed_at` (nullable) + keep `status` as is, so queries filter `removed_at IS NULL`. Alternative: new status value. Either needs a migration. OK to add one?
5. **Q5** Should removing also clear `employees.company_email`? Recommended yes when it matches the account email, so the 201 file does not show a dead address.
