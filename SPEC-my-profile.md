# SPEC: My Profile (Employee Self-Service, read-only)

Status: DRAFT, awaiting John's confirmation. No code written yet.
Date: 2026-10-31. Author: Claude. Saved as `SPEC-my-profile.md` because the root `SPEC.md` still holds the collapsible-sidebar spec (not overwritten).

## 1. Objective
Turn the sidebar item "My Profile" (now `Soon`, `app/View.php:101`) into a real page where an employee sees their own 201 record, redesigned from the old HRIS page (photo, personal, contact, address, employment, company, government IDs, plus the old top links).

Decisions from John (2026-10-31)
- Employees cannot edit anything. HR users (anyone with an HR role) maintain all data in Employee 201 in the Employee Directory. No change-request flow, no `employee_change_requests` table.
- Bring over everything the old page had, with shortcuts to existing modules.
- Advanced UI/UX; Claude chooses the layout.
- Privacy: self only, government IDs masked by default.

Users: any logged-in user whose account is linked to an employee row (`EmployeeRepository::findByUserId`) and who holds `employees.view_self`. Effective role is used, including "System Admin acting as ..." (an acting admin sees the acted employee's own profile).

### Acceptance criteria
1. Sidebar "My Profile" is enabled, links to page `employee-profile`, and shows the active state. The Soon pill goes away. The HR/Admin sidebars are unchanged.
2. Page loads only the viewer's own employee row. No `id` parameter is accepted; there is no way to open another employee's profile (guard is server-side, not just a hidden menu).
3. Account with no linked employee: friendly empty state ("Your account is not linked to an employee record yet. Contact HR."). No error, no data.
4. Hero card: photo (or initials avatar), full name, position, department, company/branch, status pill, employee no., start date and tenure.
5. Tabs (hash-deep-linkable, keyboard accessible, no full reload): Personal, Contact and Address, Employment, IDs and Documents.
6. Personal: employee ID, bio/lee code, last/first/middle name, birth date, birth place, gender, civil status, religion, mother's maiden name, blood type, number of children.
7. Contact and Address: mobile, tel, fax, email, preferred contact, address lines, city, province, zip; emergency contact (name, relationship, phones, email).
8. Employment: company, branch, department, position, immediate manager, status/type, start date, end date, active flag, employment history timeline (`EmployeeRepository::history`). Daily/hourly rate and available leave are NOT shown here (see section 6, ask first).
9. IDs and Documents: SSS, TIN, PhilHealth, Pag-IBIG (and other stored types) masked as `••••1234`. Reveal is a per-ID button, fetched on demand (not present in the page HTML while masked), audited. Document list shows type, name, date; download goes through an authenticated route that checks ownership.
10. Empty fields show a muted "Not provided" (never a blank box). A read-only notice explains "To update your details, contact HR" with a link to Requests / HR Concerns.
11. Shortcut strip replaces the old top links: Print Summary, Payslip, Deductions, Additional, Send Request, Send DTR Adjustment, Downloads, FAQs. Links that map to an existing module (Requests, DTR/Attendance adjustment, HR Concerns) go there. Modules that do not exist yet (Payslip, Deductions, Additional, Downloads, FAQs) render as disabled with `Soon`, per the sidebar convention. Print Summary is real (see 12).
12. Print Summary: print-optimised view of the page content (A4, no sidebar/topbar, masked IDs stay masked). Implemented with `@media print` on the same page, no PDF library.
13. Layout works at 360px, 768px and desktop; no horizontal scroll; light and dark mode like Home; focus states, labels and contrast per WCAG AA; no content clipped (long email/address wraps).
14. All visible text is English (rule: english-only-ui).

## 2. Commands
```
php -l app\EmployeeProfileViews.php
php -l index.php
php -l app\View.php
node --check public\assets\hris-app.js        # only if the JS file is touched
php tests\employee_profile_test.php           # new; needs HRIS_TEST_DB (isolated DB only)
```
Local app: http://localhost/HRIS_system/ (XAMPP). No browser testing by Claude (project rule); John tests on localhost.

## 3. Project structure (proposed, minimal)
```
app/EmployeeProfileService.php   read-only assembler: own row, masked IDs, history, docs, emergency contact
app/EmployeeProfileViews.php     markup for hero, tabs, shortcut strip, print block
public/assets/employee-profile.css   new stylesheet (?v= bump), print rules
public/assets/employee-profile.js    tabs + reveal-ID fetch (or small block in hris-app.js)
index.php                        routes: employee-profile, employee-profile-photo, employee-profile-reveal, employee-profile-document
app/View.php                     enable My Profile nav item (line 101), page title map
tests/employee_profile_test.php  new
HRIS-Vault/...                   Progress, Session-Log, 02-Modules/Self-Service-Portal, Screens, API-Surface, Business-Rules, Decision-Log, CHANGELOG
```
Reuse as-is: `EmployeeRepository::findByUserId, governmentIds, emergencyContacts, documents, history, profilePhoto, fullName`. No change to those write methods.

## 4. Code style
Match the surrounding PHP: procedural `index.php` page blocks, `e()` for every output, `url()` for links, `db()` prepared statements, `csrf_field()` on POST, compact one-line style in repository code, `Views` classes returning markup. Class names follow existing `.employee-*` CSS prefix (`.myp-*` for new rules to avoid collisions).

## 5. Testing strategy
Syntax and static checks always. Behavioural test script on an isolated DB (never `pmbsi_hris` without John's word), limited to 3 scenarios:
1. Linked employee sees only their own data; another employee's id/photo/document/reveal request is refused (403/redirect), including acting-as.
2. Unlinked account gets the empty state; role without `employees.view_self` is blocked at URL level.
3. Masked ID output contains no full number in the page HTML; reveal endpoint returns it for the owner only, with an audit row, and rejects GET/missing CSRF.
Visual acceptance is John's (disclosed as unverified).

## 6. Boundaries
Always
- Server guard on effective role for every new route (page, photo, reveal, document download).
- Prepared queries, escaped output, CSRF on the reveal POST, audit entry on reveal.
- Additive only; no schema change is planned. If a needed column/table turns out missing, stop and report.
- Vault sync and patch folder + zip on the Desktop with only changed files, README with "Kailangan muna" if dependent, `?v=` bump (per project delivery rules).

Ask first
- Showing daily/hourly rate and available leave on the profile (the old page showed them, empty). Proposal: leave balance links to the Leave page; rate is hidden until John approves, since it is payroll-sensitive.
- Any DB migration, new permission key, or change to the role matrix.
- Whether HR/Admin get a "view as employee" link (not in scope now).

Never
- Employee-side edit controls, change requests, uploads or deletes.
- Exposing another employee's data, full ID numbers in static HTML, or document files by direct path.
- Redesigning other modules, touching HR Employee 201 write logic, committing, pushing or deploying.

## 7. Open points (will default if John does not object)
- Tabs vs single page: default tabs + hero.
- Reveal timeout: re-masks after 30 seconds.
- Photo for self: new own-only route, not reusing `hr-employee-photo`.
