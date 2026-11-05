---
title: HR account role change and Retail Manager parity
type: plan
status: approved
author: Codex
updated: 2026-11-05
---

# Implementation plan: HR account roles and Retail Manager parity
Spec: SPEC-hr-user-role-change.md, approved by John 2026-11-05 (both module ids and subtitle). Plan APPROVED ("lock in") by John 2026-11-05, including the My Team roster revision; build authorization is still separate. No application edits, permission grants, DB execution or implementation tasks completed.

## Goal and boundaries
Two slices: hr-user-role-change, then retail-manager-parity (including approved shared hero copy). Main session only, sequential. No new dependencies, automatic commit/push/deploy or data cleanup. Current project rules allow syntax/static inspection only; any DB/app/browser verification or migration execution needs separate authorization. Do not convert Rom's role to MANAGER or infer a grant defect from collapsed menus.

## Source evidence and reach
- index.php:62 global POST CSRF; :355 change_user_role users.manage guard; :1988 User Accounts; :2083 legacy Change role; :2150-2230 existing inline Users UI/menu/modal handlers; :967 shared hero subtitle; :1255 manager-approvals permission gate.
- app/FoundationRepository.php: roles/assignableRoles, managedUser, roleAssignmentError and changeUserRole. Existing boolean subset bypass is used by RecruitmentRepository::fixReviewerRole for Endorse SC/MT and must retain that restricted behavior.
- app/View.php:102 Team Approvals, :129 Team Requests, :171 nav visibility; shared employee portal, no MANAGER-only nav gate found here.
- app/EmployeeApproverAssignmentService.php:isManager already includes MANAGER and RETAIL_MANAGER. app/TicketViewerService.php managedHeadOffice excludes both Manager filer codes. Preserve these scopes.
- Migration 032 creates Retail Manager with Manager grants except recruitment.*, only when role newly created. Current DB grants and employee linkage are unknown. Latest migration filename found is 036; reserve 037 only after rechecking immediately before implementation.
- Existing tests impacted: tests/ticket_lead_roles_test.php, tests/ticket_file_access_test.php, tests/hiring_review_visibility_test.php, tests/retail_manager_test.php. These call shared role/visibility behavior; preserve current expectations except an explicitly new dedicated HR policy path.

## Dependency order and vertical slices
1. Narrow authorization policy + migration artifact -> safe endpoint -> row action/dialog.
2. Independently inspect Retail Manager parity -> minimal proven source repair, if any -> subtitle copy.
3. Static security sweep, regression assertions authored, documentation and delivery receipt.
No parallel agents needed. Checkpoint A after HR role UI/policy; Checkpoint B after parity/copy. Stop at any newly discovered permission/routing expansion outside this plan.

## Slice A: HR changes an employee account role
Backend:
- Add narrow permission users.assign_employee_role, grant HR_USER only; Super Admin retains existing bypass. Do not grant users.manage or copy SC/OIC/Manager grants into HR_USER.
- Tentative files database/migrations/20261105_037_hr_employee_role_assignment.sql and .down.sql plus a small README. Additive permission/ledger only; record exactly which permission/grant was introduced, idempotent re-run, guarded rollback preserving pre-existing items. README explicitly selects intended DB and separates scratch cycle up/re-run/down/up from manual production/local apply. Write migration artifacts only; do not execute under current rules.
- FoundationRepository: add an authorization entry point for the dedicated Users & Access operation, checking permission, managed target and exact destination portal=employee. Include explicit source portal=employee, not merely the existing managedUser HR/Admin exclusion (which otherwise permits client targets).
- Derive dropdown eligibility from that same policy. General users.manage callers retain current roleAssignmentError subset guards; HR-specific assignment may bypass subset only AFTER dedicated permission plus employee target/destination checks. Never accept posted ignoreSubset. Keep Endorse fixed-role branch restrictions intact.
- Validate positive integer target/role IDs, re-read target and role, protect own/removed/Super Admin and all non-employee targets, preserve activation state. Existing same-role no-op remains no-op. Session revocation and audit must succeed with the update; review existing transaction behavior and do not leave a partially applied role change on an error. No password/employee link/position change.
- index.php change_user_role: authorize via the selected server policy; existing global CSRF applies. Preserve default admin-users redirect and sanitized errors. Hide controls when permission setup is absent; backend fails closed.
Frontend:
- Add Change role to eligible rows' existing menu. Reuse users modal styling/inline Users controller in index.php. One dialog shows target name/email, current role, server-produced role options, explicit sign-out notice, Cancel/Save. Keyboard focus, Escape, focus return and unique labels match existing modal patterns. Disable/no-op same selection without relying on UI for enforcement.
- Keep current Super Admin standalone form behavior; do not unnecessarily redesign/remove it. New HR control does not expose general management actions.
- public/assets/hris-modern.css only if existing modal/select styles cannot cover the dialog; app/View.php CSS cache token only if stylesheet changes. Do not extract all inline Users JavaScript to a new file.

## Slice B: Retail Manager parity and shared subtitle
- First compare the already shared nav gates, team-read policy and manager approval service gates. Under current rules use exact sources only. Actual Rom grants, active employee linkage, assigned ADLs and approver records remain a separately authorized read-only DB verification prerequisite before alleging a data repair.
- Reuse existing employee portal, Team Approvals, HR Request Inbox and Team Requests. Source repair only where a proven Manager-only gate wrongly excludes Retail Manager, with own-team/assigned-approver checks preserved. Candidate files: app/View.php, index.php, app/TicketViewerService.php, app/EmployeeApproverAssignmentService.php. If none is wrong, leave these unchanged apart from hero copy.
- No speculative permission repair migration. If actual grants are missing, document the exact missing non-recruitment permissions and request a bounded plan amendment before authoring/applying repair. Never blindly synchronize MANAGER grants or widen payroll routing.
- Replace only index.php shared hero subtitle with: View your attendance, leave balances, and requests in one place. Keep component/styles/spacing; the greeting remains outside scope.
- Preserve RETAIL_MANAGER code/name, ADL Reporting To rules and no recruitment access. Do not broaden Recruitment reviewer queries or change the client/HR portals.

## Risks and mitigation
- HR assigns approval-capable roles it does not hold: explicitly approved delegation, bounded by separate permission, employee portal target/destination and audit. Future employee-portal custom roles are eligible under the approved dynamic-list requirement; their creation remains an admin responsibility.
- Shared helper regression: inspect every caller before editing; keep existing users.manage and Endorse policies distinct and protect server entry points, not just menus.
- Stale role/session or write failure: re-read IDs/status; preserve session revocation and audit with atomic update where needed. No-op produces no misleading success audit.
- Screenshot is insufficient to diagnose parity: mark grant/linkage/team setup unverified rather than adding access speculatively.
- Migration collision/rollback: recheck filename/ledger naming before writing; no overwrite of 035/036 or applying anything to pmbsi_hris.

## Mandatory sweep (scoped)
Search changeUserRole, roleAssignmentError, assignableRoles, ignoreSubset, users.manage and change_user_role callers in app/, index.php, tests/. Check validation, CSRF, server permissions, escaped role/name data and prepared updates. Search MANAGER literals in relevant nav/viewer/approver paths only. Small same-class fixes may be included; unrelated permissions, schema/data fixes, payroll routing and delete logic are listed only. Hardcoded Good morning is recorded, not changed.

## Verification: at most three scenarios, once
1. Static trace authorized HR employee->employee assignment: shared policy/options/endpoint agree; audit/session/no-op preserved, no underlying grants copied. Author focused assertions for later authorized scratch execution.
2. Static rejection trace for unauthorized actor, invalid IDs/CSRF, self/removed/protected/non-employee target and non-employee destination, plus unchanged general users.manage/Endorse behavior. Author assertions; do not execute app/DB tests.
3. Static Manager/Retail Manager gate comparison, assigned-team/approval guards, no recruitment access and approved subtitle. Actual account grants/visual behavior stay unverified.
Allowed commands after the approved build:
```powershell
& 'C:\xampp\php\php.exe' -l index.php
& 'C:\xampp\php\php.exe' -l app/FoundationRepository.php
```
Lint any other changed PHP files and authored PHP tests once. Inline JS: extract only changed script block to a workspace temporary .js file and node --check it; no app execution. git diff --check and scoped git diff --stat. Do not run scripts/check.php, browser, integration suite, SQL cycle or DB connection under current rules. Static checks do not establish acceptance of session/audit/approval behaviors.

## Checkpoints and delivery
- Plan approval -> Tasks phase (separate task list), then explicit build authorization. No tasks/todo.md edits now.
- Checkpoint A: review complete HR path, fail-closed migration absence and protected accounts.
- Checkpoint B: record whether parity required a real source change; list DB verification/setup blockers honestly.
- Update Progress, Session-Log, Auth-and-RBAC, approved spec/plan, root CHANGELOG; Data-Model/API-Surface/Screens only for their actual affected contracts. Preserve one current shared checkpoint and latest actual app patch.
- Scoped Graphify: register exact authored vault note paths before genuine current-session extraction; refresh/export only with complete selected-note coverage. Existing pending semantic graph is not proof of freshness.
- Deliver changed-file list and static check results; no package requested. DB apply/testing remains pending separate authorization and standing backup/restore workflow.

## Plan revision - actual My Team roster (John, 2026-11-05)
This supersedes treating existing Team Requests/Approvals alone as sufficient parity. Build a read-only roster of assigned people, reachable as My Team, while retaining those existing tools.

Backend scope: add a small app/EmployeeTeamService.php only if no existing personnel-roster helper fits after bounded inspection. Retail Manager/Manager direct reports query employees.manager_employee_id against the viewer's verified active linked employee record; do not use TicketViewerService::managedHeadOffice unchanged because it has request-specific Head Office, account-role and filer exclusions. Area/branch leads reuse established assignment resolution (EmployeeDirectoryPlacement/TicketViewerService scope concepts) with explicit person visibility rules, not arbitrary posted area/branch IDs. Do not expose a public employee directory or infer membership from matching role alone. Avoid altering ticket/approval scope to make the roster work.
Frontend: index.php new employee-team route; app/View.php My Team navigation; use existing table/search/paging styles, public/assets/hris-modern.css only for a proven gap. Show employee code/name, position and area/branch/reporting head; no HR edit/full 201 links. Shared scope used in data rows/count/search/paging; no account required for a team member to appear. Empty state explains missing assignments without offering all employees.
Authorization: existing ticket.view_team alone is not proof of a general personnel permission. Propose employees.view_team granted to the specific existing team-lead roles MANAGER, RETAIL_MANAGER, ADL, SC, OIC, TC and MT, with mandatory linked/assigned scope (no assignment yields no people). This roster-only grant does not add approvals or recruitment. Include this additional permission grant in the reviewed additive migration artifact and ledger, or a separate numbered artifact if the first is already authored/applied by another session; recheck before build. No grant executed now. No employee assignment/data backfill. A role with the permission but no valid assignment has an empty roster, never global access.

Revised dependency order: narrow HR role slice -> roster scope/service and read-only entry point -> navigation/view + approved subtitle -> static sweep/documentation. Separate small implementation tasks (at most five code files each) to be written only after plan approval. Service plus route first; nav exposes it only once guarded route exists.
Revised three parity scenarios: (1) Two Retail Managers with different ADLs and a Manager with direct reports get independent rosters even with zero requests/no subordinate login. (2) Area/branch heads get assigned people only; forged IDs/search/page values, missing linkage and inactive/absent assignments never leak another team or sensitive profile fields. (3) Existing requests/approvals/routing remain scoped, ordinary employee gets no roster authority, Retail Manager gets no recruitment, and subtitle is the approved copy. Static inspection/authored assertions only under current rules; real DB/browser scenarios need separate authorization.
Checkpoint B must now assess the roster implementation explicitly, not just existing menu parity. Data-Model documents the proposed grant ledger/permission contract without claiming migration execution; Screens/API-Surface document the actual route only after implementation.

## Build log
- 2026-11-05 Task 1 (Slice A backend) done, uncommitted. Remaining: Task 2 dialog UI, then Slice B + My Team roster.

- Build 2026-11-05: Slice A + B + roster built, static only. Not verified: scratch-DB test run, migration up/re-run/down/up, browser, real Rom grants. Next: scratch DB tests, then apply per standing workflow.
