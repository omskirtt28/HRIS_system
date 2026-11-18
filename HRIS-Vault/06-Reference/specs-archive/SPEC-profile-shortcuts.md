# SPEC: profile-shortcuts-shell + profile-downloads + profile-faqs + profile-payslip/deductions/additional

Status: BUILT locally 2026-10-31 from John's "build everything" request (map: SPEC-capability-map.md). Not deployed. Decisions below were made by Claude within John's delegation; John can veto.

## 1. Objective
The five "Soon" quick links on My Profile become real pages, and HR gets the same pages with manage controls ("vice versa").
- One shared page per item, named `profile-downloads`, `profile-faqs`, `profile-payslip`, `profile-deductions`, `profile-additional`. Access: every internal account (same guard as My Profile, `EmployeeProfileService::requireAccess`). The portal chrome follows the viewer's kind (employee / hr / admin).
- Downloads: HR/Admin with `employees.manage` upload files and remove them; everyone else lists and downloads. Remove = hide (active=0), the file stays on disk.
- FAQs: same people add, edit and remove (hide) Q&A; everyone reads.
- Payslip, Deductions, Additional (bonus, allowance, 13th month pay and other extra pay): real pages with an honest empty state. No data entry now; payroll computation comes in a later patch. HR sees the same page plus a note.
- HR/Admin sidebars get two entries (Portal Downloads, Portal FAQs) for managing. Employees reach all five from the My Profile quick links.

Acceptance criteria
1. No quick link says Soon; usable ones keep their order.
2. Downloads upload: PDF/JPG/PNG/WEBP/DOC/DOCX/XLS/XLSX, max 10 MB, extension AND detected mime checked, random stored name in `storage/portal_downloads/` (blocked by .htaccess), original name kept for display. Title required (falls back to file name), max 180.
3. File download is by id through `profile-download-file` (login required, inactive files 404, attachment disposition, nosniff, filename header-safe).
4. All writes: POST + CSRF + `employees.manage` on the server (not only a hidden button), audited.
5. FAQ question max 300, answer max 4000 chars, sort order integer; all output escaped.
6. If the two tables are missing (migration not run): employees see an empty state, HR sees "Run the migration" notice. No fatal error.
7. English only; works at 360px; no new JS.

## 2. Commands
`php -l` on changed files; `php tests/profile_content_test.php` (pure); `HRIS_TEST_DB=pmbsi_hris_test php tests/profile_content_db_test.php` (scratch DB only); `php scripts/check.php task`.

## 3. Files
NEW `database/migrations/20261031_018_portal_content.sql` (+ `.down.sql`), `app/PortalContentService.php`, `app/PortalContentViews.php`, `tests/profile_content_test.php`, `tests/profile_content_db_test.php`. CHANGED `index.php` (routes), `app/EmployeeProfileService.php` (shortcuts), `app/View.php` (HR/Admin nav, css condition), `tests/employee_profile_test.php`, `CONSTRAINTS.md` (floor).

## 4. Boundaries
- Always: CSRF, permission on the server, prepared statements, escape output, additive migration with "select the database first" and a verify step.
- Ask first: running the migration on `pmbsi_hris`, real delete of files, new permission codes.
- Never: new role_permissions rows, payroll amounts or computation in this patch, serving files outside `storage/portal_downloads/`.

## Not in this patch
`hr-role-consolidation` (HR account gets payroll + recruitment access): permission change, needs a read-only `role_permissions` comparison and John's sign-off.
