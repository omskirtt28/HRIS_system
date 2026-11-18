# Spec: "My Team" for Sales Captain and OIC (and who approves when the Sales Captain is away)

Status: DRAFT, waiting for John. No code changed. Separate slice from `SPEC-endorse-inline-role-fix.md` (do that one first).

## Objective
A Sales Captain (SC) or OIC logs into the Employee portal and sees the retail staff of their own branch, like a Manager sees a team. Later, when the Sales Captain is away, the OIC can approve those staff's requests.
Users: SC and OIC accounts (Employee portal). Success: SC/OIC open "My Team" and see only their own branch's active staff.

## John's answers (2026-11-04)
- Team = same branch (no new data, no migration): active employees of the viewer's branch, except the viewer.
- Part 1 is a team list. John also wants: if the Sales Captain is not there, the OIC approves the retail staff's approvals. He does not know how to define "not there".
- Separate spec from the role fix.

## Assumptions (correct me now)
1. Branch comes from the viewer's linked employee (`employees.branch_id`), the same derivation as BR-728 (SC/OIC derived from the branch, never assigned).
2. Part 1 is view-only: name, position, employee no., status. No attendance, pay, or 201 details.
3. A new sidebar item "My Team" in My Workspace, shown by a new permission `team.view` is NOT assumed: `team.view` is already granted to ADL/SC/OIC/TC (migration 011) but I found no code using it. Verify what it currently does before reusing it.
4. English-only UI.

## Part 1: My Team (list only) - proposed
- New page `my-team` in the Employee portal; sidebar entry in `app/View.php` My Workspace, shown only when the account can `team.view` AND is derived SC/OIC of a branch.
- Query: active employees where `branch_id` = the viewer's branch, `id` <> the viewer's employee id, paged.
- Same modern list style as the rest of My Workspace (`.ap-sec`), empty state "No one else is assigned to your branch yet."

## Part 2: OIC approves when the Sales Captain is away - needs John's choice
How to know the SC is "away". Options:
- **A. The existing OIC Authorization (Recommended).** BR-734/735 already make OIC access date-bound: someone files an OIC Authorization (ADL or SC) with a start and end date, HR serves it, and the OIC is active only inside that window. So "SC away" = "an OIC authorization is in effect for this branch today". No new absence detection, no guessing, auditable.
- B. Detect leave: SC has approved leave covering today. Needs a leave-to-approval hook and fails for sudden absences.
- C. Detect attendance: SC not clocked in. Unreliable (late, day off, biometric gaps).
- D. OIC always approves alongside SC. Simple, but the OIC then has permanent power, which BR-735 deliberately avoids.

Open points before Part 2 can be specced:
1. Who approves retail staff requests today, and which request types (leave, OT, TA, OB)? Currently SC has no approver permission (migration 011) and OIC has `payroll.approve_manager`. I need to read the current routing before touching it.
2. Does the SC become the first approver for retail staff (new), or does the ADL stay first approver and SC/OIC only see them? This is a payroll/permission rule change and needs John's explicit approval and its own migration-safety review.

## Commands
- `php scripts/check.php task`, `php scripts/check.php full` with `HRIS_TEST_DB` set before shipping
- New test: `tests/my_team_http_test.php` (isolated scratch DB, via `run-hris-system`)

## Project structure
`index.php` (route `my-team`), `app/View.php` (sidebar), one repository method (branch staff query, probably `EmployeeRepository`), a small view function, vault notes (Screens.md, Business-Rules.md, Auth-and-RBAC.md, Progress.md, Session-Log.md, CHANGELOG.md).

## Testing strategy (Part 1)
SC sees only own-branch staff; not other branches; not themselves; inactive/removed employees hidden; OIC outside their authorization window loses access (matches BR-735); a plain Employee gets 403 and no sidebar item; HR/Manager unaffected.

## Boundaries
- Always: derive branch server-side, deny by default, `e()` all output, no pay/attendance data, audit nothing extra (read-only).
- Ask first: any approver or payroll rule change (Part 2), new permission code, migration.
- Never: let the viewer pass a branch id in the URL, show staff of another branch, give the OIC standing approval power.

## Success Criteria (Part 1)
SC and OIC see "My Team" with only their branch's active staff; everyone else does not; tests pass; check.php task passes.

## Open Questions
1. Part 2 option A, B, C or D? (A recommended.)
2. Current approval routing for retail staff, to be read before Part 2.
3. Does the existing `team.view` permission already drive something I must not break?
