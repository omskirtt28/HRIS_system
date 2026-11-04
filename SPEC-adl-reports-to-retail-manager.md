# Spec: adl-reports-to-retail-manager (ADL's Reporting To must list the Retail Manager)

Status: APPROVED by John 2026-11-04 (Rom = Retail Manager; fix + hint). Done on localhost. Single capability, so no capability map (Phase 0 skipped). Follows approved `SPEC-retail-manager.md` (decisions 1-4); this only fixes why the list is empty.

## Objective
In Employee 201, when an employee's position is ADL (Area Development Leader), the **Reporting To / Manager or ADL** dropdown must show the Retail Manager so HR can pick them. Today it shows only "Unassigned" (screenshot 2026-11-04 19:43, ADL with Ocampos area).
Users: HR (assigns Reporting To), Retail Manager (head of ADLs), ADL (the report).

## Facts found (localhost `pmbsi_hris`, 2026-11-04)
- Retail Manager employee: id 386, no. `RETAIL CONSULTANT 001`, Rom Dela Cuesta, status ACTIVE, position "Retail Manager", linked user id 30, user ACTIVE.
- User 30's account **role is `EMPLOYEE`**, not `RETAIL_MANAGER`. Role `RETAIL_MANAGER` exists. User 30 has no `payroll.approve_*` permission.
- Why it is empty: `EmployeeRepository::managerCandidates()` (line 182) sets `is_retail_manager` only when the account role code is `RETAIL_MANAGER`; the form JS (`index.php`, Reporting To script) shows only `data-rm="1"` options for an ADL; the save check `validateImmediateManager()` (line 218) also requires role `RETAIL_MANAGER` + active account. So the code is working as the approved spec says; the **data** (role on user 30) is wrong.
- The page also hides non-ready people as disabled options, so HR gets no hint why Rom is missing.

## Assumptions (correct me now)
1. A Retail Manager = an active employee whose linked HRIS account has role `RETAIL_MANAGER` (approved decision 1). I keep this rule; no position-only shortcut, because ticket routing needs the role's Manager permissions.
2. Fix = (a) data: set user 30's role to `RETAIL_MANAGER`; (b) small UI hint so a Retail-Manager-position employee with the wrong role shows disabled as "Account role must be Retail Manager" instead of vanishing.
3. No migration, no schema change, no new permission, no change to other users.
4. Localhost only; live hosting is applied manually by John later.

## Commands
```
Syntax:   php -l index.php
Task:     php scripts/check.php task
Full:     HRIS_TEST_DB=<scratch> php scripts/check.php full
Test:     php tests/retail_manager_test.php
Role fix: mysqldump first (pmbsi_hris), then UPDATE users SET role_id=(SELECT id FROM roles WHERE code='RETAIL_MANAGER') WHERE id=30;
```

## Project structure
```
index.php                           Employee 201 form + Reporting To script (UI hint only)
app/EmployeeRepository.php          managerCandidates(), validateImmediateManager() (no rule change expected)
tests/retail_manager_test.php       add: wrong-role Retail Manager is listed disabled with reason; correct-role is listed
HRIS-Vault/ (Progress, Session-Log, Decision-Log, CHANGELOG, 02-Modules/Org-Structure or Employee-Records)
```

## Code style
Match the existing inline PHP/JS in `index.php`: one-line `<?php foreach ... ?>` blocks, `e()` for output, English-only labels, `data-*` attributes read by the form script.
```php
<option value="<?=$m['id']?>" disabled><?=e($mn)?> · <?=e($m['employee_no'])?> · Account role must be Retail Manager</option>
```

## Testing strategy
- PHP assert tests in `tests/` (isolated scratch DB, never live): ADL + user with role RETAIL_MANAGER is accepted; same person with role EMPLOYEE is rejected with the existing message; candidate list marks only the RETAIL_MANAGER-role person `is_retail_manager=1`.
- Manual/browser check (run-hris-system skill) on scratch DB: open an ADL's Employee 201, Reporting To lists Rom; a Retail Manager with the wrong role shows disabled with the reason.
- `php scripts/check.php task` must pass; no floor lowered.

## Boundaries
- Always: mysqldump + verified restore before the role update; confirm no other user's role changed; vault sync; English-only text.
- Ask first: the role change on user 30 (permission change); any new permission or role; touching live hosting.
- Never: bulk-change roles, auto-assign managers to ADLs, delete data, `git push`/deploy, weaken a test.

## Success criteria
1. ADL's Reporting To lists Rom Dela Cuesta (selectable) after the role fix; saving stores `manager_employee_id=386`.
2. Retail-Manager-position employees without the role appear disabled with "Account role must be Retail Manager".
3. Non-ADL employees' Reporting To lists are unchanged.
4. Only `users.id=30` differs in the DB afterwards (row counts of employees/users/roles unchanged).
5. `php scripts/check.php task` passes.

## Open questions (need John)
1. Is Rom Dela Cuesta (emp 386, user 30, "RETAIL CONSULTANT 001") the real Retail Manager, or a test account? OK to set his role to `RETAIL_MANAGER` on localhost?
2. Add the "Account role must be Retail Manager" disabled hint (assumption 2b), or just fix the data?
3. Should the role be assigned from the Users/Accounts screen by HR in the future (already exists?), or is a one-off DB update fine?
