# Plan: My Profile (read-only Employee Self-Service)

Status: DRAFT for John's approval. Spec: `../SPEC-my-profile.md` (approved by John 2026-10-31). Mode: plan only, no code changed.
Rules: one slice per session, vault sync before the final report, no commit/push/deploy, no DB write without John's word, no browser testing by Claude.

## What exists (read from code)
- `app/View.php:101` nav item `['My Profile','#','user',true,'employees.view_self']` (4th value true = disabled/Soon). Enable by pointing it at page `employee-profile` and setting `false`.
- `index.php:838`: every `employee-*` page already requires login and the employee portal (SUPER_ADMIN/HRIS_ADMIN also pass). `employee-profile` inherits this; we still add `Auth::can('employees.view_self')`.
- Own row: `PayrollRepository::currentEmployee()` (user id -> employee with department, position, branch, area, manager). Reuse it; do not add a second lookup. `EmployeeRepository::find($id)` only if a column is missing.
- Reusable reads: `governmentIds`, `emergencyContacts`, `documents`, `document`, `history`, `profilePhoto`, `fullName`.
- Patterns to copy: `hr-employee-photo` / `hr-employee-document` streams (index.php ~446: `basename()` + stored dir), `audit()` in `app/functions.php:59`, `csrf_field()`, `render_portal_header('employee',$active,$title)` + `page_head()`.
- Shortcut targets that exist: `employee-request-new`, `employee-requests`, `hr-requests-my` (HR Concerns), `employee-leave`, `employee-attendance`.
- `employees.view_self` is seeded in `database/migrations/20261024_011_lead_roles.sql`. Not verified: whether the EMPLOYEE role actually has it in `role_permissions` on localhost/live (Auth::can only falls back to `*.view_self` when no permissions are loaded).
- No `tasks/` folder existed, so nothing was overwritten.

## Dependency graph
```
T1 guard + route + nav + hero --+--> T2 tabs content --> T3 IDs masked/reveal
                                +--> T4 own photo + documents
                                +--> T5 shortcut strip + print
T2..T5 --> T6 polish --> T7 tests + vault + package
```
Each task is a vertical slice that leaves a working page.

## Files
New:
- `app/EmployeeProfileService.php`: `forCurrentUser(): ?array` (one read model; `maskId()`; document filter).
- `app/EmployeeProfileViews.php`: hero, tab panels, shortcut strip, empty state.
- `public/assets/employee-profile.css` (with print rules) and `employee-profile.js` (tabs, reveal).
- `tests/employee_profile_test.php`.

Edited:
- `index.php`: routes `employee-profile`, `employee-profile-photo`, `employee-profile-reveal` (POST), `employee-profile-document`.
- `app/View.php`: enable nav item, load CSS/JS with `?v=20261031-my-profile-v1`, title/crumb.
- Vault: Progress, Session-Log, 02-Modules/Self-Service-Portal, Screens, API-Surface, Business-Rules, Decision-Log, Open-Questions, CHANGELOG.

## Tasks

### T1. Guarded page shell + nav + hero (click My Profile, see own hero)
- Enable nav item. Route calls `Auth::can('employees.view_self')`, `currentEmployee()`, renders hero via Views.
- No linked employee gives an empty state. No `id` parameter is read anywhere.
- Accept: active sidebar state; hero shows name, position, department, company/branch, status pill, employee no., start date/tenure, initials avatar. `php -l` clean.
- Verify: `php -l`; grep shows no `$_GET['id']` in the route; acting-as resolves through `currentEmployee()` (effective user).

### T2. Tabs: Personal, Contact and Address, Employment
- Accessible tablist (roles, arrow keys, `#hash` deep link; without JS all panels stack).
- Fields per spec 1.6-1.8, "Not provided" for empties, emergency contact via `emergencyContacts`, history via `history`. Rate and available leave are NOT rendered (pending John).
- Accept: every old-page field is present or listed as consciously omitted in the vault note; long values wrap.
- Verify: `php -l`; every value wrapped in `e()`.

### T3. Masked government IDs + audited reveal
- Service returns only masked strings (`••••1234`, short numbers fully masked). The full number is never in page HTML.
- `POST employee-profile-reveal`: CSRF, ownership check (id belongs to the current employee), JSON response with `Cache-Control: no-store`, `audit('Employees','REVEAL_GOV_ID','employee',$empId,['id_type'=>...])` without the number. JS re-masks after 30 s.
- Accept: GET gives 405, missing CSRF 403, another employee's id 404.
- Verify: test scenario 3; rendered HTML contains no unmasked number.

### T4. Own photo + documents
- `employee-profile-photo`: no id param, current employee's photo only (copy the `hr-employee-photo` stream, add `nosniff`).
- Documents list + `employee-profile-document?id=` through `EmployeeRepository::document($ownEmployeeId,$id)` so the query enforces ownership.
- Default filter hides type `MEMO` (HR-internal) pending John's answer.
- Accept: another employee's document id gives 404; missing file 404.
- Verify: test scenario 1.

### T5. Shortcut strip + Print Summary
- Real links: Print Summary (`window.print()` button), Send Request -> `employee-request-new`, Send DTR Adjustment -> `employee-requests`, HR Concerns -> `hr-requests-my`, Leave -> `employee-leave`. Payslip, Deductions, Additional, Downloads, FAQs are disabled with the `Soon` style.
- `@media print`: A4, hide sidebar/topbar/shortcuts/tab chrome, show all panels, IDs stay masked.
- Accept: each enabled shortcut resolves to a page the viewer is allowed to open (hide or disable it if the viewer lacks that page's permission).
- Verify: grep that each target exists in `index.php`; `php -l`.

### T6. Polish (no new features)
- Responsive at 360 / 768 / desktop, light and dark tokens from `hris-modern.css`, focus-visible, contrast, reduced motion, compatible with `sidebar-fold.css`, empty and error states.
- Accept: no horizontal scroll by CSS inspection (`min-width:0` on grid children, `overflow-wrap:anywhere`), touch targets >= 44px. Visuals are disclosed as unverified; John tests.
- Bump `?v=` on every changed asset.

### T7. Tests, vault sync, delivery
- `tests/employee_profile_test.php`, 3 scenarios only: own-data-only (including acting-as and another employee's photo/document/reveal), unlinked or unauthorised, masked + reveal. Runs only on an isolated DB (`HRIS_TEST_DB`); if not provided, report "not run".
- Vault sync per CLAUDE.md. Delivery per HRIS project rules; no commit/push/deploy.

## Checkpoints
- After T1: page opens, guard proven by code reading. Stop if the nav/guard differs from the plan.
- After T3: security read of reveal, document and photo routes before continuing.
- After T5: static pass (`php -l`, unescaped output, dead links).
- After T7: final report (Fixed / Found / Not verified) and vault file list.

## Risks and open questions
1. Daily/hourly rate and available leave: hidden by default. Need John's yes/no.
2. Documents: which types may the employee see? Default hides MEMO only; MEDICAL/CONTRACT may also be HR-internal.
3. `employees.view_self` may be missing for the EMPLOYEE role on localhost/live; the menu would then disappear. Check with a read-only query during T1; a fix needs a migration (ask first).
4. `employee_government_ids.id_number` appears to be stored plain; masking is display-level only. Encryption at rest is out of scope.
5. Print relies on the browser; no PDF generation.
6. Admin "acting as" relies on `Auth::user()` being the effective user; confirm while reading in T1 and test in T7.
