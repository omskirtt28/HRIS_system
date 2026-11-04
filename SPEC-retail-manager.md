# Spec: retail-manager (Retail Manager as the head of the ADLs)

Status: APPROVED by John 2026-11-04 (decisions below). Plan: `HRIS-Vault/05-Implementation/Plans/2026-11-04-retail-manager.md`. No code, no DB change yet; waiting John's go.

## Decisions (John, 2026-11-04)
1. NEW position "Retail Manager" (Retail dept) AND NEW role `RETAIL_MANAGER` (grants cloned from MANAGER, no new permission). For an ADL, Reporting To lists Retail Managers only.
2. One Retail Manager now, but flexible: no hard-coded user/ID; any number of Retail Managers is allowed.
3. NO recruitment / Hiring Review role for the Retail Manager. ADLs file a ticket when they need something.
4. Existing ADLs are assigned by hand by HR in Employee 201. No bulk change (0 ADLs have a manager today).
(The assumptions and open questions below are kept for history; the decisions above supersede them.)

## Objective
ADLs (Area Development Leaders) currently have no real head: in Employee 201 an ADL at Head Office shows **Reporting To = Unassigned** because the list only offers Head Office department Managers. John wants a **Retail Manager** position and user who handles all ADLs, so that:
- HR can create a Retail Manager employee (position + portal user) like any other.
- An ADL's Reporting To can be set to a Retail Manager.
- The Retail Manager can see and approve for the ADLs under them (same tickets/approvals a Manager gets for their reports).

Users: HR (creates/assigns), Retail Manager (approves, views ADL team), ADL (the report).

## Facts found (from code/DB, 2026-11-04)
- No "Retail Manager" position exists. Retail positions: ADL (3 duplicates across Retail / Ho Retail And Development / Retail & Development), Retail Team Coordinator, Sales Captain, OIC, MT, etc.
- Roles: ADL, MANAGER ("Manager / Immediate Head"), SC, OIC, TC, MT exist. Active users: 1 ADL role, 2 MANAGER role.
- Approver kind comes from the employee's site: Head Office -> `MANAGER` (`payroll.approve_manager`), branch -> `ADL` (`payroll.approve_adl`) (`EmployeeApproverAssignmentService::kindFor`, line 23). An ADL sits at Head Office, so they route to **MANAGER** candidates = any active user with `payroll.approve_manager`. No Retail-specific filtering today.
- Retail employees' Reporting To already filters to ADLs only (screenshot hint text); ADL placement/areas live in `directory_area_ids` (`EmployeeDirectoryPlacement`).
- Directory role is automatic from position (`ADL_POSITIONS` list); a Retail Manager position would need a rule to land in a sensible group.

## ASSUMPTIONS I'M MAKING (correct me now)
1. **Position**: one new position "Retail Manager" in department **Retail** (data row, added by a guarded migration, not hand-inserted).
2. **Role**: reuse the existing **MANAGER** role (no new role, no new permission). A Retail Manager account = MANAGER role, so they can already approve as Manager.
3. **Reporting To for an ADL** lists only Retail Managers (like Retail staff list only ADLs), so HR cannot pick a Finance Manager by mistake. Existing ADL assignments are not changed automatically.
4. **Directory**: Retail Manager appears under "Department Managers" and is also shown as the head above the ADL group; no new directory section.
5. **Retail Manager sees all Retail ADLs' tickets** (read-only team view like `ticket.view_team`), not branch staff directly.
6. English-only UI labels; modern `.ap-sec` look for any new block.

## Open questions (need John)
1. **Role**: reuse MANAGER (recommended, zero permission change) or create a new RETAIL_MANAGER role? A new role means a permissions change = your explicit approval.
2. **How many Retail Managers**? One for all ADLs, or one per region/area (Metro South, Mindanao, MM1... )? If per area, we need an area link, otherwise one head for all is enough.
3. **Hiring Review (Retail)**: should the Retail Manager be a reviewer for Retail jobs (like ADL/OIC/SC/MT), or not involved in recruitment?
4. **Existing ADLs**: when the Retail Manager exists, should HR assign each ADL by hand in Employee 201 (recommended), or bulk-set all ADLs to one Retail Manager?
5. **Duplicate ADL positions** (ids 9, 45, 50): leave as is (recommended, out of scope).

## Scope (proposed minimum)
1. Migration: add position "Retail Manager" (Retail dept), idempotent, down script guarded (only deletes if no employee uses it).
2. Employee 201 Reporting To: when the employee is an ADL, list only Retail Managers (active, linked account with `payroll.approve_manager`); same hint style as the Retail "Only ADLs are listed" note.
3. Directory placement: Retail Manager -> Department Managers group.
4. Ticket visibility: Retail Manager sees tickets of the ADLs who report to them (read-only), reusing the existing viewer lookup.
Not in scope: new role, new permissions, recruitment changes, bulk reassign, duplicate-position cleanup, payroll rules.

## Commands
```
Syntax/static + constraints:  php scripts/check.php task
Before shipping (isolated DB): set HRIS_TEST_DB=<scratch db> ; php scripts/check.php full
Single test:                  php tests/adl_reporting_test.php
Lint one file:                php -l app/EmployeeApproverAssignmentService.php
```

## Project Structure
```
database/migrations/            -> new <date>_NNN_retail_manager.sql (+ .down.sql)
app/EmployeeApproverAssignmentService.php -> candidates()/validApprover filter for ADL reports
app/EmployeeDirectoryPlacement.php        -> directory group rule for the new position
index.php (~1549-1580)          -> Reporting To select + hint text for ADL
app/TicketViewerService.php     -> read-only view of ADL reports' tickets
tests/adl_reporting_test.php    -> extend with Retail Manager cases
HRIS-Vault/                     -> Progress, 02-Modules/Employees, Data-Model, Business-Rules, Decision-Log, CHANGELOG, Session-Log
```

## Code Style
Match the surrounding PHP: PDO prepared statements, `static` service methods, terse one-line guards, `RuntimeException` with a plain English message, `e()` on every output.
```php
if($kind==='MANAGER' && $isAdl) $sql.=' AND p.name="Retail Manager"'; // Retail Manager heads ADLs only
```

## Testing Strategy
- Isolated scratch DB only (never `pmbsi_hris` until the standing "test then apply" conditions hold).
- Extend `tests/adl_reporting_test.php`: (a) ADL's candidate list contains only Retail Managers, (b) a non-Retail Manager is rejected by `validApprover` for an ADL report, (c) Retail staff still list only ADLs, (d) Head Office staff still list Managers, (e) migration up, re-run, down, up; down refuses when an employee holds the position.
- Manual browser check (run-hris-system skill): Employee 201 of an ADL shows the Retail Manager picker; Directory shows the position under Managers.

## Boundaries
- Always: mysqldump + verified restore before applying; isolated-DB tests; vault sync; fix-sweep of the same pattern (other Reporting To branches); English-only UI.
- Ask first: new role or permission, schema change beyond the position row, bulk-updating existing ADLs' Reporting To, recruitment reviewer changes, `git push`/deploy.
- Never: change payroll approval rules, delete data or roles, weaken a test or `CONSTRAINTS.md`, touch the live DB before checks pass.

## Success Criteria
1. HR can create an employee with position "Retail Manager" and link a portal account (MANAGER role).
2. In an ADL's Employee 201, Reporting To lists only active Retail Managers with a linked account; saving works and is audited.
3. Retail staff and Head Office staff Reporting To lists are unchanged (tests prove it).
4. A Retail Manager's directory entry appears under Department Managers; ADLs show them as their head.
5. A Retail Manager sees, read-only, the tickets of ADLs reporting to them and nobody else's.
6. `php scripts/check.php task` passes; migration up/re-run/down/up clean; no existing business rows changed.
