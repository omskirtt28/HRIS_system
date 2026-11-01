# SPEC: profile-nav-dedupe

Status: DRAFT, awaiting John's confirmation. No code written.

## 1. Objective
Employees see two links to the same page: sidebar "My Profile" (`app/View.php:101`) and topbar dropdown "Profile" (`app/View.php:171`). HR/Admin have only the dropdown. Remove the duplicate so each portal has exactly one entry.

Acceptance criteria
1. Employee portal: sidebar "My Profile" stays; dropdown no longer shows "Profile" (Appearance stays).
2. HR and Admin portals: dropdown "Profile" stays; no sidebar change.
3. Rule is by portal (effective role, including "System Admin acting as ..."), not by hard-coded role code.
4. Page access is unchanged: `EmployeeProfileService::canView` and `requireAccess` still guard `employee-profile` on the server.
5. No dead ends: the profile page is reachable from every portal that could reach it before.
6. Sweep: grep other "Profile"/"My Profile" links (notifications, breadcrumbs, Home cards) for the same duplication.

## 2. Commands
`php -l app/View.php`, `php tests/notification_sidebar_test.php`, `php tests/employee_profile_test.php`

## 3. Project structure
`app/View.php` only (plus a test assertion). Folded sidebar rail (root `SPEC.md`) must still work: do not touch its markup.

## 4. Code style
Keep the existing inline ternary style in `View.php`; one condition on `$kind`/portal.

## 5. Testing strategy
Static render check per portal: employee has 1 profile link, HR 1, Admin 1.

## 6. Boundaries
- Always: server guard unchanged, English text.
- Ask first: moving the profile into a different menu group.
- Never: change permissions or the route.

## Open questions
None.
