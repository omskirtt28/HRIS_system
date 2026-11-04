# Spec: Department + Position picker follows the Directory (department-picker)

Status: BUILT 2026-11-04 (all three forms, migration 034 applied to localhost). Plan: `HRIS-Vault/05-Implementation/Plans/2026-11-04-department-picker.md`.

## Decisions (John, 2026-11-04)
1. Form shape: Department (main) -> Position grouped by team. No extra Team field.
2. HR: "HR lang sila, tas PMBSI HR". Interpreted as HR group = the existing PMBSI "HR" department (id 30), no data change; "Human Resources Assistant" stays under Admin. Re-confirm with John if he meant something else.
3. "Online Shop" becomes **"Online"** (rename, no "Shop"). It is the 7th Department choice. Rename of stored department id 19 name only (code unchanged), by guarded migration 034.
4. Scope: all three forms (Add Employee, Employee 201, Convert to Employee). Hard rule: **nothing existing may break** (no-op save of every employee leaves department, position, Directory team, payroll export and approver unchanged; existing tests stay green).
(Open Questions below are kept for history; the decisions above supersede them.)
Supersedes Module 1 (`position-dept-guard`) of `SPEC-form-keep-input.md`. Module 2 (`form-keep-input`) of that file stays a separate, later slice.

## Capability map (Phase 0)
One capability, so no split.

| Module id | Responsibility | Depends on |
|---|---|---|
| department-picker | Department dropdown shows only the main departments; Position dropdown shows only that department's positions, grouped by team, same as Directory | none |

## Objective
HR picks a short, clean Department list in Add Employee (and the same field pair on Employee 201 and Convert to Employee), and Position shows only what belongs to that department. This also removes the Retail Manager "does not belong to the selected department" error, because an impossible pair can no longer be built.

User (John, screenshots 2026-11-04): Department today lists ~25 items (Accounting, Admin, Audit, Ho Billing And Collection, ...). Wanted: Manpower Planning, Inventory Management, Finance, HR, Retail, Marketing & Creatives. Position today lists ~55 items for any department. Wanted: like the Directory, e.g. Manpower Planning -> Administration, Information System / MIS, PMMD; Administration -> Admin Staff, Project Coordinator.

## Facts found (code and DB, 2026-11-04)
- The Directory already groups the stored departments into main groups in code: `EmployeeDirectoryHierarchy::groups()` (FINANCE, INVENTORY, MANPOWER, MARKETING, ONLINE) and `::department($label)` with `EmployeeDirectoryService::departmentLabel()` (strips "Ho/Sw" prefix, alias MIS/IT -> MIS). The stored `departments` rows are the "teams". Nothing is stored for the main group, so **no schema change is needed**: `employees.department_id` keeps the team row.
- Retail and HR are NOT in `groups()` today (Retail comes from workplace/branch, HR is not a group). They must be added to the picker mapping.
- Current mapping of active departments (id: name -> main group):
  - **Manpower Planning**: 17 Manpower Planning, 9 Admin ("Administration"), 16 MIS / IT ("Information System / MIS"), 20 PMMD
  - **Inventory Management**: 15 Inventory Management, 21 Production, 23 Stock Control, 24 Supply & Demand, 28 Ho Supply And Demand
  - **Finance**: 14 Finance, 8 Accounting, 11 Billing & Collection, 27 Ho Billing And Collection, 10 Audit, 25 Treasury ("Cash Management")
  - **Marketing & Creatives**: 18 Marketing, 13 Digital Marketing, 12 Creative ("VM & Creatives")
  - **Retail**: 2 Retail, 22 Retail & Development, 29 Ho Retail And Development
  - **HR**: 30 HR (company PMBSI). The 2LOG "Human Resources" (id 7) is inactive; "Human Resources Assistant" sits under Admin (Manpower).
  - Not in the six: 19 Online Shop (6 employees, Directory group "Online"), 26 PMBSI Office (inactive, 1 employee), inactive legacy rows (1,3-7).
- Positions per department are in `positions.department_id` (55 active). One active position has no department (id 25 "Marketing / Creatives Consultant", 2 employees). One employee (no. 14391) has department Inventory Management but position Goldsmith (Production): both Inventory group, so harmless.
- Server check today: `app/EmployeeRepository.php:696` (create/update) and `:330` (convert) reject a position whose `department_id` differs from the posted `department_id`. PMBSI company skips the check.
- UI: Department select `index.php:1412` (Add Employee), `:1535` and `:1584` (Employee 201), `:1777` (Convert); Position select `index.php:1413`, and JS at `:1432`/`:1592` that filters by Work Company only.

## Proposed design (Ponytail-small, no migration)
1. **One mapping function** `departmentGroup(departmentRow)` reusing `EmployeeDirectoryHierarchy`/`departmentLabel`, extended with `RETAIL` and `HR` entries (and `ONLINE` if John says yes). Directory and picker share it, so they cannot disagree.
2. **Department dropdown** posts the main group key (e.g. `MANPOWER`), not a stored department id.
3. **Position dropdown** lists only positions of the stored departments inside the chosen group, as `<optgroup label="Administration">` (team) -> position. Same look as the Directory teams. Position is required before save when a Department is chosen.
4. **Server derives** `employees.department_id` from the chosen position's `department_id` and rejects a position outside the posted group (trust boundary stays server-side). No position chosen: save Department as the group's own stored row if it has one (Finance 14, Inventory 15, Manpower 17, Marketing 18, Retail 2, HR 30), else Unassigned.
5. **Existing employees**: the form pre-selects the group of their stored department and their stored position, so nothing changes until HR edits. No bulk update, no delete, no rename of stored departments.
6. Work Company filter keeps working (HR group is the PMBSI one; the other groups are 2LOG).

## Acceptance criteria
1. Department dropdown shows exactly the agreed groups (Q2/Q3), alphabetical, plus "Unassigned".
2. Choosing a Department shows only its positions, grouped by team; switching Department clears an out-of-group Position with the existing note.
3. Retail Manager appears only under Retail; no "does not belong" error is reachable from the UI. Forced POST with a mismatched pair still errors, naming both values.
4. After save, `employees.department_id` is the position's stored department; Directory, payroll export and approver routing show the same team as before for every existing employee (no change on a no-op save).
5. Same behaviour on Add Employee, Employee 201 employment tab and Convert to Employee (one shared partial/JS, not three copies).
6. Directory and `PayrollCutoffExportService` still resolve the same main/team labels (shared mapping, covered by a test).

## Tech Stack
PHP (router `index.php`, services in `app/`), MySQL `pmbsi_hris`, vanilla JS `public/assets/hris-app.js`, CSS `public/assets/app.css`. No new dependency, no migration.

## Commands
```
Syntax/static + constraints:  php scripts/check.php task
Before shipping:              set HRIS_TEST_DB=<scratch db> ; php scripts/check.php full
Lint:                         php -l index.php ; php -l app/EmployeeRepository.php ; php -l app/EmployeeDirectoryHierarchy.php
New test (to write):          php tests/department_picker_test.php
Browser check:                run-hris-system skill (isolated scratch DB, never pmbsi_hris)
```

## Project Structure
```
app/EmployeeDirectoryHierarchy.php -> add RETAIL/HR group + a picker() that returns groups with their stored departments and positions
app/EmployeeRepository.php         -> :696 / :330 derive department from position, clearer message
index.php                          -> the 4 Department/Position field pairs use one shared partial (:1412, :1535, :1584, :1777)
public/assets/hris-app.js          -> filter Position by group (replaces the :1432/:1592 inline scripts where they overlap)
tests/department_picker_test.php   -> new
HRIS-Vault/                        -> Progress, Session-Log, 02-Modules/Employee-Records + Org-Structure, Business-Rules, Decision-Log, CHANGELOG
```

## Code Style
Match the file: static methods, terse guards, `e()` on output, plain English messages.
```php
throw new RuntimeException('Position "'.$pn.'" belongs to '.$pg.', not '.$dg.'. Choose a position under the selected department.');
```

## Testing Strategy
- Scratch DB only. `tests/department_picker_test.php`: (a) every active department maps to exactly one group or is listed as "ungrouped" (guards future rows); (b) every active position is reachable under one group; (c) a position outside the posted group is rejected with both names; (d) saving derives `department_id` from the position; (e) a no-op save of every existing employee leaves `department_id` unchanged (read-only comparison); (f) Directory group labels unchanged (shared function).
- Browser (`run-hris-system`): screenshots of the three forms; Manpower Planning shows Administration / Information System / MIS / PMMD teams with their positions; Retail shows Retail Manager.
- `php scripts/check.php task` passes; no floor lowered, no test skipped.

## Boundaries
- Always: server-side validation; CSRF; English-only UI; modern `.ap-sec` look; fix sweep of every Department/Position pair; vault sync with the changed files listed in the final report.
- Ask first: reactivating or moving departments/positions in the database (HR, Online Shop, id 25 with no department); renaming stored departments; deleting duplicates (ADL x3, Billing and Collection Staff x3); any migration.
- Never: touch live `pmbsi_hris` rows; change payroll approver rules or permissions; remove the server-side check; commit or push without John's word.

## Success Criteria
1. The Department dropdown matches the agreed list and Position follows it, on all three forms.
2. Retail Manager can be saved without error under Retail.
3. No existing employee's Directory team, payroll export department or approver changes.
4. `php scripts/check.php task` and the new test pass on a scratch DB.

## Open Questions (need John)
1. **Form shape:** Department (main) -> Position grouped by team (recommended, matches your Directory screenshots and needs no extra field), or three fields Department -> Team -> Position?
2. **HR:** which rows does HR mean? Recommended: HR = the PMBSI "HR" department only (no data change); "Human Resources Assistant" stays under Admin/Manpower. Alternative: reactivate 2LOG "Human Resources" and move that position there (data change, your explicit approval).
3. **Online Shop** (6 employees, not in your six): add "Online" as a 7th choice (recommended), or put it under Marketing & Creatives?
4. **Scope:** Add Employee + Employee 201 + Convert to Employee together (recommended, one shared partial), or Add Employee only first?
