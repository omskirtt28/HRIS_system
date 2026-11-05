# Spec: HR changes existing account roles in Users & Access

Status: APPROVED by John, 2026-11-05, including both capability slices, HR assignment boundary and recommended hero subtitle. Author: Codex. Plan phase authorized; implementation and DB execution not yet approved.

## Objective
HR can change an existing account's role directly from Users & Access without opening another module. John confirmed: offer existing Employee portal roles only, using the system's role list.

## Confirmed scope and assumptions
- Approved requirement: HR_USER can choose existing roles whose portal is employee. Load options from roles, not a hardcoded dropdown. Do not offer HR, Admin or Client portal roles.
- Source-evidenced examples: Employee, Manager / Immediate Head, Area Development Leader (ADL), Sales Captain (SC), OIC, Team Coordinator (TC), Management Trainee (MT), Retail Manager. This is not a live DB inventory; use actual saved role names/options at runtime.
- Approved target boundary: existing employee-portal accounts only; exclude own account, removed accounts, Super Admin, HR/Admin/Client portal accounts. Keep activation status unchanged.
- Approved UX: Change role in each eligible row's existing actions menu, opening an accessible dialog with account name/email, current role, New role selector, Cancel and Save. Explain before Save that changing the role signs the account out.
- One account per save; no automatic change based on position and no bulk updates.

## Current source evidence
- index.php change_user_role requires users.manage; the separate Change role form also requires it. HR directory visibility alone does not enable editing.
- FoundationRepository::roleAssignmentError rejects HR/Admin destinations and roles with permissions the actor does not hold. Merely exposing the form would still refuse several employee roles.
- FoundationRepository::managedUser protects own/removed accounts and HR/Admin targets; changeUserRole additionally protects Super Admin, revokes sessions and audits CHANGE_USER_ROLE. Same-role saves return without a write.
- Migration 035 grants recruitment.set_reviewer_role for the Endorse card's narrow SC/MT action, not general Users & Access role assignment.

## Required behavior and permission boundary
- HR needs narrowly scoped authority to assign existing employee-portal roles even when those roles contain permissions HR does not personally hold. This is an explicit permission-flow expansion proposed for this feature, including OIC/Manager approval access. Do not grant HR those underlying permissions or general users.manage merely to enable the dropdown.
- Reuse current role update, session revocation and audit behavior with a narrowly checked policy. Both UI options and POST must enforce the same actor, target and destination rules; never accept a posted bypass flag.
- Validate IDs and re-read actor/target/role on save. Missing CSRF, unauthorized actor, removed/self/protected target, invalid role or non-employee destination must refuse without changing the account.
- Preserve existing Super Admin behavior, Endorse SC/MT restrictions, employee links, password, status, position, department and payroll data. Do not edit role permission sets or approval routing.
- Same role is a harmless no-op. On success return to Users & Access with a clear message. Errors retain useful context without exposing SQL or secrets.

## Tech stack and project structure
Plain PHP with existing PDO/prepared queries, session authentication, server-rendered HTML and current Users & Access styling. No new dependency.
- index.php: Users & Access table, role form/dialog and POST handler.
- app/FoundationRepository.php: roles, target guards, role assignment, sessions and audit.
- public/assets/hris-modern.css and existing Users UI handler: only if needed; verify actual handler path during approved planning.
- tests/: existing role/access suites, including ticket_lead_roles_test.php; new focused assertions in an approved later build.
- database/migrations/: an additive narrow-permission grant may be needed; number and schema approach are undecided until planning. Do not reuse or overwrite applied migration 035.
- HRIS-Vault/: Auth-and-RBAC, Data-Model, Progress, Session-Log; root CHANGELOG.md.

## Commands and verification limits
Allowed static checks for a later approved patch:
```powershell
& 'C:\xampp\php\php.exe' -l index.php
& 'C:\xampp\php\php.exe' -l app/FoundationRepository.php
git diff --check
git diff --stat
```
If a standalone JS file changes, run node --check with its verified exact path. No build command is needed. No browser, app or DB tests/writes in this session under current project rules. Integration scenarios below are acceptance requirements for separately authorized execution, not claimed results.

## Code style
Keep CRLF and targeted edits; reuse Auth permission checks, csrf_field(), e(), prepared queries and existing audit conventions.
```php
Auth::requirePermission('users.manage');
FoundationRepository::changeUserRole((int)($_POST['user_id']??0),(int)($_POST['role_id']??0));
```
This is the current route pattern, not a sufficient HR authorization fix. A dedicated permission policy must be reviewed in Plan; do not replace it with an unguarded ignoreSubset=true call.

## Testing strategy and success criteria (maximum three scenarios)
1. HR changes another existing employee-portal account to a different existing employee-portal role, including a role whose permissions HR lacks. Options match database portal=employee; role persists, sessions revoke, one audit entry records actor/target/from/to, linked employee data/status stay unchanged. Saving the same role makes no change.
2. Reject forged/missing-CSRF requests, non-HR without authority, self/removed/protected targets, invalid IDs and HR/Admin/Client destination roles. Hidden controls alone are not protection; failed saves leave role/sessions/audit unchanged.
3. Regression: Super Admin assignment guards and Endorse SC/MT rules remain intact; keyboard labels, dialog focus/close and narrow-screen layout follow existing components. Static checks alone do not prove these behaviors.

## Boundaries
- Always: server authorization, CSRF, prepared queries, escaped output, session revocation, audit, matching UI/POST policy, minimal diff and shared checkpoint sync.
- Ask first: spec approval, implementation plan approval, any migration/permission grant or DB/app/browser execution outside current authorization, expanding targets beyond employee accounts.
- Never: assign Super Admin, edit the HR actor's own role, change role permissions/payroll data automatically, expose secrets, commit/push/deploy or silently run migrations.

## Spec approval record
- John approved the full draft after reviewing both slices and subtitle. Employee-portal targets only; own/removed/Super Admin/HR/Admin/Client targets remain excluded.
- Narrow HR authority to assign ALL existing employee-portal roles, including approval-capable OIC/Manager/ADL/TC, is approved as a requirement. Exact permission migration and policy implementation require plan approval; no grants applied.

## Continuation
After John approves this spec, move to Plan in a later turn; do not implement now. Latest actual application patch remains pipeline-site-split, migration 036, recorded applied to localhost on 2026-11-04 by Claude; no hosting deployment or commit recorded. No package or new dependency for this docs-only task.
Graphify query ran with stale/pending warning; exact source inspection is authoritative. Updated-note semantic extraction, noteScopes registration and refresh/export remain pending; do not claim graph freshness.

## Added scope - Retail Manager parity (John confirmed, 2026-11-05)
John selected the recommended boundary: Retail Manager uses the same Manager menus, My Team / Team Requests visibility and approval capabilities, excluding all Recruitment / Hiring Review access. RETAIL_MANAGER stays a distinct employee-portal role; preserve its ADL hierarchy and assigned-team scope. Do not change the account to MANAGER or hardcode Rom's user ID.

### Capability map for review
| Module id | Responsibility | Depends on |
|---|---|---|
| hr-user-role-change | HR assigns existing employee-portal roles in Users & Access | existing account/role policy |
| retail-manager-parity | Retail Manager receives Manager team navigation and approval behavior, excluding Recruitment | existing Manager/team policy |
These are independently verifiable slices. Proposed order: hr-user-role-change, then retail-manager-parity. John approved this map and combined spec on 2026-11-05; implementation plan is pending review.

### Evidence and assumptions
- Screenshot: Rom is signed in as Retail Manager; My Workspace is collapsed. It does not prove that Team Approvals is missing or that account permissions are wrong.
- app/View.php employee navigation already contains Team Approvals gated by payroll.approve_manager. Source migration 032 seeds Retail Manager from MANAGER grants minus recruitment.* only when it creates that role. This is source evidence, not verification of current account grants.
- Existing Retail Manager plan explicitly excludes recruitment and preserves ADL Reporting To. John confirmed that exclusion again in this session.
- Same as Manager means equivalent team tools for the Retail Manager's own assigned employees/ADLs, not access to another manager's team. Keep existing routing and self-approval protections.

### Success criteria for this slice
1. Compare Manager and Retail Manager navigation under equivalent eligible/team setup: same personal workspace, team requests and approval entry points; Retail Manager has no Candidates for review or other Recruitment entry/access. Reuse existing screens, no homepage redesign required.
2. Retail Manager can read and act only on requests assigned/visible through existing Manager rules for their own team, including assigned ADLs. Forged IDs, another manager's team and own-request approval remain refused. Ordinary Employee gains no manager access.
3. HR role switching and existing Manager/ADL routing remain correct; role label stays Retail Manager and reporting links/history stay intact. Missing employee linkage, role grants or team setup must be distinguished from a UI gate issue before changing code or data.
These are requirements for later authorized checks, not executed tests. Maximum three scenarios per slice; current session remains static-only.

### Exact continuation sources and limits
Inspect app/View.php navigation filters, index.php manager-approvals and team screens, app/TicketViewerService.php, app/EmployeeApproverAssignmentService.php, and database/migrations/20261104_032_retail_manager.sql in the later Plan. Sweep literal MANAGER checks only where they control these tools; list unrelated rules rather than refactor them. No blind copy of all Manager grants; recruitment remains excluded. If a permission repair/migration is needed, describe the exact change in the approved plan first; no DB grants or role data changed now.

Spec/map and Users & Access target/UX/narrow-authority boundary approved 2026-11-05. Retail Manager excludes Recruitment. Implementation plan approval remains pending.

## Added UI copy requirement - Employee portal hero (2026-11-05)
John asked to include the circled dashboard subtitle in this SDD and requested a balanced UI/UX recommendation. Copy approved by John on 2026-11-05:
- Current: Here is your personal HR workspace for today.
- Recommended: View your attendance, leave balances, and requests in one place.
- Source verified: index.php:967, employee-dashboard dashboard_hero() call.

Keep this inside the retail-manager-parity slice as a small shared employee-dashboard copy change; no new module. The subtitle describes the personal cards visible on this page for Employee, Manager and Retail Manager. Team tools remain in their permission-gated navigation; do not promise team controls in personal-dashboard copy.
Preserve existing hero typography, muted subtitle color, width and spacing; allow natural wrapping on small screens. No new card, banner, button, role-specific subtitle or homepage redesign.
Acceptance: the shared employee hero displays the approved sentence using the existing dashboard_hero component; no data, layout, navigation or authorization changes from this copy edit. Include source/static confirmation within the existing parity verification, not extra runtime scenarios.
Same-pattern source sweep found one exact occurrence of the old subtitle in app/ and index.php. Screenshot also shows Good morning at 6:23 PM; greeting is hardcoded at the same call. This separate issue is recorded only, not included in the requested circled-subtitle change unless John asks.
Status: wording approved, spec approved; no application edit or implementation approval inferred.

## Confirmed clarification - My Team includes people (John, 2026-11-05)
My Team must show an actual employee roster, not only Team Requests / Approvals. Retail Manager sees every ADL assigned under that Retail Manager; other team heads similarly see their own assigned people. This requirement is confirmed by John; it is an extension of retail-manager-parity, not a general employee directory grant.
- Use the actual reporting/assignment hierarchy, not role name alone. Retail Manager and Head Office Manager direct reports follow employees.manager_employee_id. For existing area/branch leads, use their established assigned area/branch scope, with eligibility verified server-side. The same scope must apply to roster queries and any direct detail links.
- All ADLs means all ADLs under the signed-in Retail Manager, not every ADL company-wide when there are multiple heads. No hardcoded Rom ID and no automatic reassignment/backfill.
- Roster is independent of whether a person has filed a request, has a portal account or has a pending approval. Include the assigned active team even when requests are zero. Deduplicate people and exclude the viewer; follow existing active employment policy rather than inventing a status list.
- Show only Employee code, Name, Position, Area/Branch and Reporting To where useful. Read-only, searchable and paged using existing house patterns. No salary, government IDs, passwords, private employee documents or HR-edit links.
- Keep My Team roster, Team Requests and Team Approvals distinct entries/actions inside the existing team workspace. Existing approval authority is unchanged by roster visibility.
- Missing reporting/area/branch assignments show a clear empty/setup message; never fall back to all employees or all people with the ADL role. HR maintains assignments using existing Employee 201 controls.
- Acceptance: Retail Manager A sees A's ADLs, Retail Manager B sees B's ADLs; Manager/area/branch heads see only their own established team; team list is present with zero requests; hand-posted/query IDs cannot expand the scope. These cases replace/extend the existing maximum-three parity scenarios.
Status: requirement recorded; prior spec requirements remain approved. Updated plan awaits review; no build or permission/data changes authorized by this clarification.
