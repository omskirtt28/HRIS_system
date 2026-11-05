## 2026-11-04 - Pipeline scale, paging and board filters (local)
- Board reads counts plus 8 newest cards per stage from SQL; Applicants list paged 25/page; board search, job and recruiter filters, Hiring flow / Parked groups, days-in-stage chip. No migration.

## 2026-11-04 - Pipeline board polish (local)
- Deployed candidates leave the Pipeline board (button opens them in Applicants), 5x2 stage grid, accent colour per stage, "+N more" link for long columns. UI only, no migration.


## 2026-11-04 - Endorse card: set reviewer role (local)
- HR can set a listed Sales Captain / Management Trainee account to its role from the Endorse card (new permission recruitment.set_reviewer_role, migration 035). OIC stays Super Admin only. Needs migration 035 on hosting.
## 2026-11-04 - Endorse card explains who cannot be endorsed (local)
- Hiring review Endorse card now lists staff in a reviewer position (Sales Captain, OIC, MT, ADL) at the job's branches who still cannot be picked, with the reason: no portal account, inactive account, or account role is not a reviewer role. Nothing saved changes.

## 2026-11-04 - Login: show/hide password eye (local)
- index.php login form: eye button on Password (aria-pressed, Show/Hide label); hris-modern.css `.login-pw`.
- Fix: stale CSS cache version bumped (View.php); .login-pw full width.

## 2026-11-04 - Forms keep typed values on refresh (local)
- public/assets/hris-app.js: sessionStorage draft for POST forms; cleared on successful save or Cancel. View.php asset version bumped.

## 2026-11-04 - Department and Position pickers (local)
- Add Employee, Employee 201 and Convert to Employee now show only Manpower Planning, Inventory Management, Finance, HR, Retail, Marketing & Creatives and Online as Department, and Position lists only that department's positions grouped by team (like the Directory). A mismatch such as Retail Manager under another department can no longer be picked. 'Online Shop' is now called 'Online'. Nothing already saved changes.

## 2026-11-04 - Consultant status (local)
- Employee Status now includes Consultant (Employee 201 and anywhere the status list is used). Treated like Regular: no probation or term dates.

## 2026-11-04 - Retail Manager heads the ADLs (local)
- HR can now create a Retail Manager (new position and role). In Employee 201 an ADL's "Reporting To" lists Retail Managers only, and the Retail Manager approves for and sees the tickets of their ADLs. The Retail Manager has no recruitment access. Existing ADLs stay Unassigned until HR picks their Retail Manager.

## 2026-11-04 - Retail endorse list and scorecard (local)
- The Endorse card now names Retail staff (Sales Captain, OIC, MT, ADL) who have no portal account, and no longer tells HR to set branches when they are already set. A saved interview scorecard shows as Saved and is edited through "Edit my scorecard".

## 2026-11-04 - Public pages stay light (local)
- The public website (home, careers, about, contact, track application) always shows in the light look, even if someone chose Dark in the employee portal on the same computer.

## 2026-11-03 - Pipeline board redesign (local)
- The Recruitment Pipeline board now fits the screen without a scrollbar, with a colour accent per stage, rounded counts and a clear empty state.

## 2026-11-04 - Public pages stay light (local)
- The public website (home, careers, about, contact, track application) always shows in the light look, even if someone chose Dark in the employee portal on the same computer.

## 2026-11-03 - Deployment hire date (local)
- The Deployment "Scheduled date" is now labelled "Hire date" (defaults to the offer start date). Convert to Employee pre-fills Hire date from it, then offer start date. No schema change.

## 2026-11-03 - Job form branch picker (local)
- On a new or edited job, the Retail area and branches list now shows only when Site type is Retail. It lists every area as a card with All/Clear, a filter box and selected counts, with no inner scroll box.

## 2026-11-03 - Screening first, no Applied step (local)
- New applicants now land in Screening right away, and the progress bar no longer shows Applied (7 steps, Screening to Deployed). HR continues straight to Interview. Old applicants who were still at Applied are shown as Screening. Database: Applied stage hidden (migration 029).

## 2026-11-03 - Screening rating, Site type and MT role (local)
- HR can now rate an applicant Negative, Fair or Outstanding on the applicant page; the Applicant Database shows the colour badge and the Recruiter (the first HR user who rates). A low rating never rejects anyone, HR decides.
- Managers, TCs (head office) and ADLs, OICs, SCs, MTs (retail) can rate only applicants that went through them. Job Opening form has a new Site type choice. Convert to Employee now needs a rating. New role: MT (Management Trainee). Database migration 028 applied to localhost.


## 2026-11-02 - My Profile photo view and crop (local)
- Click your avatar to view the photo large (soft edges, name, role), or pick a new one, drag and zoom it into a square crop, then save. Stylesheet version bumped so browsers load the new look.


## 2026-11-02 - My Profile photo upload (local)
- Anyone with a My Profile page (employee, HR, admin) can now click their avatar to upload their own photo (JPG/PNG/WEBP, 3 MB). No database change.

## 2026-11-02 - Soft Pink palette (local)
- HR Appearance now also offers Soft Pink (the pink column from your reference). No database change.

## 2026-11-02 - HR Appearance palettes (local)
- HR users now choose Light, Dark, Soft Beige, Earth Tones or Soft Pastel from Appearance; it is saved to their account and applied before the page paints. Other roles keep the one-click Light/Dark toggle. HR users start on Light; a Dark choice saved only in the browser is no longer used for HR.

## 2026-11-02 - HR appearance palettes, stylesheet (local)
- Added the three palette colour sets (Soft Beige, Earth Tones, Soft Pastel) with a contrast check. Not switched on for any page yet.

## 2026-11-02 - HR appearance palettes, save endpoint (local)
- HR users can now save a palette through a new endpoint (palette-save); Admin and other roles are refused. Migration 027 (users.ui_palette) applied to localhost. No screen change yet.

## 2026-11-02 - HR appearance palettes, groundwork (local)
- Added users.ui_palette (migration 027, not yet applied to localhost) and the UserPalette service that limits palette choice to the HR portal role. No screen change yet; the Appearance list comes in Task 4.

## 2026-11-02 - Convert to Employee: automatic portal account (local)
- Converting an applicant now always creates the Employee portal account (email from the Company: 2LOG or PMBSI) and requires a Company that matches the Department. The Convert form now shows Employer PMBSI, a Company select filled from the Department and a live email preview; the old Account setup card is removed.

## 2026-11-02 - Offer rate type
- Offer card: Working days per month (22 Mon-Fri / 26 Mon-Sat, default 26) drives the Monthly / Daily / Hourly maths. Migration 026 (offers.working_days) applied to localhost.
- Offer card: Rate type (Monthly / Daily / Hourly, default Monthly) with a peso rate and live equivalents; the monthly amount is computed with 26 days x 8 hours (to verify). Migration 025 (offers.rate_type, rate_amount) applied to localhost.

## 2026-11-02 - Endorse to Manager review
- HR endorses a candidate to one or more Manager / ADL / SC / TC users (checkbox list with name filter, requester pre-ticked). Reviewers get Candidates for review in the employee sidebar with only their own candidates, the resume, a decision (Proceed, Not suitable, Needs another interview) and an interview scheduler. Stage label Client Review is now Manager Review (label only). Offer form uses the Job Openings employment types.
- Migration 023 application_reviewers plus permission recruitment.review (MANAGER, ADL, SC, TC); applied to localhost pmbsi_hris after backup, up/re-run/down/up and used-data guard. Not run: browser, push, deploy.

## 2026-10-29 - Shared shell search, sidebar scroll and unread badges
- Removed shared portal top search; page-local search retained. Sidebar scroll persists per user/portal with storage fallback.
- Added accessible sidebar badges for all unread existing HR request/incident and employee reminder notifications using existing metadata/access. Bell/sidebar update together after confirmed reads; failures/stale GETs do not clear displayed counts. Malformed read IDs rejected.
- PHP/JS syntax, source/static checks and scoped diff passed. Mapping regression scenarios added but not executed. Browser/app/DB/live counts unverified; no migration, recipients change or new transaction notifications.

## 2026-10-29 - Codex HRIS low-usage alignment
- Aligned Codex agent/skill context routing and project vault rules; no application behavior changed. Fresh-session discovery and usage savings unverified.

## 2026-10-29 - Incident case compact panel
- Compact expandable case rail with participant and attachment counts, latest visible timeline event, and clearly labelled HR-only notes. Status action remains visible. Browser and live behavior unverified.

## 2026-10-29 - Incident case page cleanup
- HR actions no longer has "Include someone"; status form always visible, duplicate Update status button removed. Composer is no longer sticky. The original incident report is shown on the page (PDF or read-only sheet of the submitted answers) with download kept. UI only.

## 2026-10-29 - Incident handler: CC-only HR may handle
- `TicketIncident::isHandler` now excludes only the reporter and HR named Involved/Witness; CC-only HR sees status controls. BR-775 updated.

## 2026-10-27 - Incident Others submission correction
- Nature specification now optional, including Others, with max5000 instead of200. UI/backend agree; supporting Others specification max5000 with existing conditional requirement retained. Both changed PHP files pass php -l; runtime acceptance by John, no further review/deployment.

## 2026-10-27 - Dashboard cleanup and official Incident Report (source patched; acceptance by John)
- Dashboard action strip removed, three setup warnings link to focused/expanded viewer reports, existing handler-only status form is reachable beside the pipeline.
- Six-section form uses verified filer details and separate free-text names/access picker; exact immutable master, frozen answers, transactional DOCX, optional local PDF and reporter/eligible-handler document GET/retry implemented. New documents use validated external private storage, default `C:/xampp/hris-private/incident_documents`. Existing case conversation/participants/history retained.
- Initial lint: PHP 12/13 passed, dashboard transition failed then corrected; three extracted inline JS checks passed. QA round 1 also corrected external storage and PDO handling. John stopped further review/recheck and owns acceptance; tester interrupted, simplify skipped. Final fix lint, runtime/browser/DB/converter/rendered fidelity unverified. No commit/push/deploy; period picker/Download Report deferred. Vault and usage-limit handoff decision synced.

## 2026-10-27 - Codex HRIS agents and coding skills
- Six project Codex roles share existing Claude role bodies; four skills cover patching, debugging, UI and review.
- Planner-first changes, static tester, simplify proposals, exact file ownership and automatic Obsidian sync after patches/decisions.
- Configuration/docs only; no application, database, permission or payroll change, commit or deployment.

## 2026-10-26 · Light theme text meets AA contrast (no database change)
- Green, blue, red and amber chips, badges, alerts and links, plus grey secondary text (tabs, counts, table headers, breadcrumbs), are now 4.5:1 or better on the light theme. Dark mode is unchanged.

## 2026-10-26 · Incident Cases queue for HR (no database change)
- HR's Incident Cases page now has status tabs with counts, search, category, branch and date filters, a case age column and phone-friendly cards. Dismissed and cancelled cases no longer crowd the default Open view.
- The HR dashboard's Open incident number now equals the Open tab (Submitted, Under review, In progress) and only counts cases that HR user may read; its links open the matching tab.
- The Case page's Back button returns to the same tab and filters.

## 2026-10-26 · Dark-mode status text is readable (no database change)
- Chips, pills, badges, flash banners and HR queue ages and notes were hard to read in dark mode (about 3:1); they now measure 6:1 or better. Light theme: gray chip, page crumb and eyebrow are easier to read.
- The people search on an incident case runs one query less per keystroke; results are the same.

## 2026-10-24 · Head Office Manager can now see their team's requests
- Fixed: a Head Office employee's Manager (Reporting To) saw "No HR requests" in Team Requests. Head Office has an empty branch code, which made the system think the employee had no Manager. Routing now needs only the branch, so this also lets a Head Office Payroll Concern reach the Manager's HR Request Inbox.
- The only viewer of a Head Office employee's request is their direct Manager (Reporting To), checked live; a Manager change moves access, old requests included. It no longer needs the Manager to hold the approve permission.
- A Manager's own requests go straight to HR, with no viewer above; Employee 201 shows "Not applicable" for them and a red warning when a Head Office employee's Manager has no account or cannot view team requests.
- No database change. Incident Reports stay hidden; viewers still cannot comment, attach, approve or get notifications.

## 2026-10-25 · HR dashboard and HR Request Queue polish (no database change)
- HR dashboard: "Needs your action today" strip, Company and Period filters, six KPI cards that open the filtered queue, requests-by-type bars, a pending-age chart, a Requires attention panel (oldest pending, Incident count and latest title for HR only, setup warnings) and a smaller recruitment pulse.
- HR Request Queue: status tabs with counts, one search box, collapsible filters shown as chips, colour-coded age, preview drawer, quick In Progress / Served / Cancel, bulk Served or Cancel for ATM, ID and Uniform only (confirmation with optional shared remarks and a result summary), uniform size summary per branch, CSV export, mobile cards.
- Every action still goes through the existing ticket service one ticket at a time, so permissions, audit and events are unchanged.

## 2026-10-25 · Ticketing Report
- New HR page (Requests, Ticketing Report): requests by type and status, new this week, average time to Served, what has waited over 5 days and the oldest, where requests come from (company, area, branch, branches that filed nothing), per-type detail (uniform pieces per size per branch, payroll concerns by cutoff, manpower, transfers, COE purposes, OIC), team throughput per day, an Incident Report count (no details) and setup health.
- Filters: period, company, branch, request type. Export CSV (name and branch only) and a one-page printable summary for management.
- Migration `20261025_015_ticket_report.sql` adds only the permission; applied to the localhost database after a verified backup and clean up/re-run/down/up tests.

## 2026-10-25 · Certificate of Employment request and template
- New request type in File an HR Request: Certificate of Employment (purpose, needed-by date, delivery). It goes straight to HR; Served is never done in bulk.
- HR uploads a Word .docx template (HR Request Setup, COE Template) with placeholders for name, position, hire date, company, purpose and date issued; versions are kept. Until then a simple built-in layout is used. Signatory and company name are HR settings.
- HR sees a preview of the exact values (and decides whether to include salary) before confirming Served. The employee and HR download the same Word file from the ticket; nobody else can see it. Every certificate generated and downloaded is logged.
- Migration `20261025_014_coe_request.sql` (rollback refuses once any COE exists). Applied to the localhost database after a verified backup and clean up/re-run/down/up tests.

## 2026-10-24 · Automatic ticket viewers and OIC Authorization
- Who may read an employee's HR requests is now worked out automatically: the covering ADL, the Sales Captain and the OIC of the employee's branch (same-branch employees by position, with an active account), or the Manager for Head Office. Nothing is assigned by hand; the old branch-lead and extra-viewer entries are retired and no longer give access.
- Employee 201 Employment tab shows "Who can view this employee's tickets" with each person's account status and red PROBLEM lines (no Sales Captain, Sales Captain without an account, no covering ADL, more than one Sales Captain). Ticket Viewers is now a read-only problem report.
- New request type OIC Authorization: an ADL or Sales Captain asks HR to let an employee of their branch/area act as OIC for a date range. HR serves it and gives the OIC role in Users; after the end date the access ends and the account goes back to its previous role automatically.
- Migration `20261024_013_oic_authorization.sql` (rollback `.down.sql` refuses once any authorization exists). Applied to the localhost database after a verified backup and clean up/re-run/down/up test; no existing business rows changed.

## 2026-10-24 · HR Request Setup sidebar group moved up
- The HR Request Setup group (Ticket Approvers, Ticket Viewers, Accounting Members, Printable HR Forms) was the last group of the HR sidebar, below the fold. It now sits third, under People, for the HR and Admin portals.
- No permission or database change; each entry still needs its existing permission.

## 2026-10-24 · Ticket viewers on localhost and the Employee 201 viewer line
- Applied migration 012 (read-only ticket viewers) to the localhost database after a verified backup and clean up/re-run/down/up test; no existing business rows changed.
- Employee 201 Employment tab now shows a read-only "Ticket viewers (view only)" line (branch leads and covering ADL, or the Manager for Head Office, or "No viewer set").
- Fixed two tests that depended on live data (lead roles, ADL reporting) so they build their own fixtures.

## 2026-09-26 · Manager approval history
- Added persistent manager decision history for Payroll & Timekeeping and Leave approvals.
- Added summary counts for pending, approved, returned/rejected, and total manager decisions.
- Preserved completed approval records for audit/reference instead of disappearing from the manager UI.


## 2026-09-26 · HR Timekeeping manager approval visibility
- Added explicit manager approval proof to the HR Timekeeping queue.
- HR can now see the employee reporting manager, approval status, approving manager, and approval timestamp before opening the request.
- Preserved the full approval timeline and existing review/decision workflow.


## 2026-09-26 · Manager review employee avatar alignment fix
- corrected the employee initials avatar on Payroll & Timekeeping manager review
- narrowed the employee subtitle CSS selector so text styling no longer overrides the avatar display/grid centering
- preserved responsive employee identity layout and review functionality


## 2026-09-26 · Manager request review UI polish
- rebuilt the review summary typography using explicit semantic fact blocks so labels and values no longer run together
- reduced Employee information to the essentials a manager needs for approval context
- added compact employee identity and assignment context instead of a dense seven-field card
- preserved request details, supporting documents, timeline, and approval actions

## 2026-09-26 · Payroll/Timekeeping approver review detail
- fixed post-submit request detail redirect compatibility
- manager, HR Timekeeping, and Payroll now review the full request before deciding
- added complete employee/request/evidence context and approval actions on the review page
- Return/Reject decisions now require remarks

## 2026-09-26 · Payroll request post-submit redirect fix
- corrected the employee Payroll & Timekeeping submit redirect from the obsolete `employee-request` route to the actual `payroll-request` detail route
- added a backward-compatible route alias so older links/bookmarks do not fall through to the public Recruitment 404 page
- preserved the successful request save, approval routing, attachments, cutoff matching, and request history behavior

## 2026-09-26 · OB/POB Reason visibility fix
- fixed the generic Reason field remaining visible for Official Business and Unpaid Official Business
- preserved Purpose as the required OB/POB justification field and supporting attachment requirement
- scoped the visibility fix to the Payroll & Timekeeping request form so other request types keep their Reason field


## 2026-09-26 · Official Business form refinement
- removed generic Reason from OB/POB employee filing UI
- made Purpose required for OB/POB
- made required attachment state explicit in the form while retaining backend enforcement
- kept existing database compatibility and approval routing

## 2026-09-26 · HR reporting manager assignment hardening
- clarified HR-owned Immediate Manager / Reporting To assignment in Employee 201 File → Employment
- manager selector now separates approval-ready accounts from employees who still need account/manager access
- backend prevents self-reporting, inactive managers, unlinked accounts, and managers without approval access
- existing manager-driven Payroll & Timekeeping / OB / TA / Leave routing remains unchanged


## 2026-09-26 · Phase 3A — Payroll & Timekeeping + Leave Credits
- Added employee Payroll & Timekeeping Requests with dynamic TA / OB / OT / Day-Off fields.
- Added reporting hierarchy and Manager → HR Timekeeping approval routing.
- Added Manager Team Approvals, HR Timekeeping queue, and Payroll processing queue.
- Added Employee Leave Credits, leave ledger/history, leave filing, Manager → HR Leave approval, and HR credit adjustments.
- Replaced hardcoded employee leave balances and recent activity with live Phase 3A data.
- Added secure supporting-document uploads and request audit/history records.


## 2026-09-25 · Users & Access header readability fix
- rebalanced the User Accounts column architecture so the Department header is fully readable instead of showing `DEPART...`
- preserved Password/Actions alignment and responsive card behavior
- added a header-readability guard to prevent important labels from being silently truncated

## 2026-09-25 — Users & Access structural table fix
- Rebuilt the User Accounts column sizing with an explicit `colgroup` instead of stacked percentage overrides.
- Added fixed action slots so Reset password / Current account and the three-dot menu align identically on every row.
- Consolidated all Users & Access CSS into one production block and removed the previous layered hotfix rules.
- Preserved password access, reset modal triggers, row actions, search, filters, permissions, and backend handlers.


## 2026-09-25 · Users & Access action spacing hotfix
- widened and rebalanced the Actions column on Users & Access
- prevented overlap between masked password, eye icon, Reset password button, and the three-dot menu
- kept the layout clean without bringing back the horizontal slide / awkward overflow issue

# Conversion Notes — v1

## Converted from prototype to PHP/MySQL

- Removed hardcoded `JOBS`, `APPS`, `USERS`, `CLIENTS` and stage counts as the production data source.
- Public Careers pages now read published jobs from MySQL.
- Application submission now creates Applicant + Application records and stage history.
- Resume uploads are validated and stored with randomized filenames.
- Application tracking validates both reference number and applicant email.
- HR portal now uses real database counts, applicants, stages, interviews and manpower requests.
- Client portal is restricted to the signed-in client's formally endorsed candidates.
- HR endorsement creates an endorsement record and moves the candidate to Client Review.
- Client approval moves a candidate to Offer; decline returns the candidate to HR.
- Offer, deployment scheduling, deployment completion and manpower fill count are database-backed.
- Authentication now uses MySQL users, PHP sessions and `password_hash()`.
- Write actions use CSRF protection and audit logging.
- Existing UI styling from the supplied prototype is retained in `public/assets/app.css`.

## Deliberately not merged

The supplied `pmbsi-hrsd-handover.zip` is an HR Service Desk/ticketing backend, not a Recruitment backend. It is treated as a reference for future shared HRIS concepts only. Ticket tables were not reused as applicant/application records.


## v1.1 - Clean testing baseline
- Removed seeded sample manpower requests, jobs, applicants, applications, interviews, endorsements, offers, deployments, and audit history.
- Replaced named demo users/companies with clearly labeled generic testing access.
- Added `database/reset_to_clean.sql` for databases already imported from v1.0.
- Public homepage metrics now come from real database records; removed the fixed `2,400+` value.
- Added empty-state messaging when there are no published jobs.
- Locked next HRIS phase: Employee 201 File + Employee Master + Organization Structure.

## 2026-09-25 — Production Account / Demo UI Cleanup
- Removed prefilled test emails and passwords from the live login screen.
- Removed the visible demo-environment and UI-preview labels from live HRIS pages.
- Removed Employee/HR demo-account seeding from fresh schema and migrations.
- Added a safe migration to retire legacy seeded demo accounts without breaking historical foreign-key references.
- Kept recruitment presentation data unchanged for the scheduled short presentation; that data can be cleaned separately afterward.


## 2026-09-25 — Super Admin Password Reset
- Added Super Admin-only password reset controls in Users & Access.
- Existing passwords remain securely hashed and are never displayed or stored in plaintext.
- Super Admin can show the temporary password while creating/resetting it, and resetting a password revokes the target user’s active sessions.


## 2026-09-25 — Super Admin password UI v2
- Kept Last Login timestamps on one line in Users & Access.
- Replaced text Show/Hide controls with eye-icon password toggles.
- Existing passwords remain non-recoverable because they are securely hashed.
- After a Super Admin password reset, the new temporary password can be revealed/copied once on the redirected Users & Access page and is not stored as plaintext in the database.


## 2026-09-25 — Users & Access Final UI/UX
- Rebuilt the Users & Access presentation to match the approved production reference while preserving existing account-management backend handlers.
- Replaced inline expanding password-reset forms with a centered modal using the existing reset-password POST action and CSRF protection.
- Added working client-side user search, role/department/status filters, compact row actions, responsive mobile cards, and a no-horizontal-scroll desktop table.
- Preserved hashed-password storage; current passwords remain unrecoverable while newly reset temporary passwords are shown once using the existing secure session reveal flow.


## 2026-09-25 — Users & Access Layout Refinement
- Rebalanced the User Accounts table so Password and Actions no longer collide or clip.
- Gave the Actions column enough width for its header and three-dot menu while keeping the table inside the card.
- Moved the current-account indicator into a compact secondary line under the password mask to prevent overlap.
- Preserved reset-password modal, search, filters, account actions, permissions, and backend behavior.


## 2026-09-25 — Users & Access Password / Actions Refinement
- Rebalanced Password and Actions columns to remove cramped masked-password controls and prevent Reset password from visually spilling into neighboring columns.
- Moved Reset password into the Actions column where it semantically belongs, while keeping the eye control in Password.
- Replaced the intrusive password-protection toast with a centered Password Access modal.
- Preserved one-way password hashing: existing passwords remain unrecoverable; a freshly reset temporary password can be revealed/copied only on the immediate Super Admin response page.

## 2026-09-25 — Phase 2C Recruitment → Employee Conversion
- Added a controlled one-time conversion from a hired/deployed recruitment application into Employee Management / 201 File.
- Added a review screen for identity, organization assignment, employment details, portal-account setup, and recruitment-document transfer.
- Added duplicate protection, source traceability, audit records, and a lock that prevents further recruitment changes after conversion.
- Employee profiles now show their originating recruitment application when applicable.
- Applicant records show a clear Converted state and link to the resulting 201 File.


## 2026-09-25 — Login portal badge cleanup
- Removed the redundant Unified Login / portal badge from the login card.
- Preserved unified role-based authentication and automatic dashboard routing.

## 2026-09-26 · Phase 3A cutoff auto-match and missed filing
- Payroll cutoff now auto-matches the selected affected date.
- Employees can select previous cutoff periods for missed/back-filed TA, OB, OT and related requests.
- Backend validates that the affected date actually belongs to the selected cutoff.
- Historical cutoff periods are generated safely from the existing cutoff calendar rules; no schema change required.

## 2026-09-29 — Phase 3B.0 Organization Master Data
- Rebuilt active Department, Position, and Branch / Site masters from the HR Employee Roster.
- Added Business Units / Brands (`SilverWorks`, `Ocampo's Fine Jewellery`) and Companies / Legal Entities.
- Preserved existing referenced legacy masters as retired records rather than deleting them.
- Added roster lineage/mapping status to Branch / Site records and surfaced data-quality items requiring HR review.
- Kept Area masters separate because the roster does not contain Area assignments.

## 2026-10-12 — Attendance export: stale logs and missing-source visibility
- The "For Review" Excel / PDF now refreshes attendance for open cutoffs before exporting, so employees with imported punches (e.g. Stock Management, Production, Online) no longer show "Waiting for files".
- Imported rows whose biometric ID now equals an exact Employee Code are matched automatically (audited as BIOMETRIC_REMATCH); no name or numeric guessing.
- Days for employees with no punches in any imported file are labelled "No biometric logs received for this employee" instead of "Waiting for files".
- Added a "Source Summary" tab (Excel) and summary pages (PDF) listing each group's employees with / without logs, plus the employees without logs, so missing department files (e.g. Creatives, MIS) are obvious.
- The export review panel shows how many employees have no biometric logs.

## 2026-10-12 — Employee Archive, Set inactive and Terminated
- Added an **Employee Archive** (People menu and Employees page) for Inactive, Resigned and Terminated employees, with search, status filter, effective date and remarks.
- Added **Set inactive**, **Terminated** and **Restore** on the Employee 201 File (Employment tab). Each needs an effective date (not in the future) and remarks, writes Employment History and the audit log, and Terminated needs a confirmation tick.
- Setting an employee inactive or terminated deactivates their linked login account and ends their sessions; Restore can reactivate it. Super Admin accounts and your own record are protected.
- An employee who is someone's Reporting To cannot be archived until those reports are reassigned; pending tickets / leave are shown as warnings.
- The main Employees directory no longer lists archived employees; the generic status dropdown can no longer move people in or out of the archive.
- Payroll cutoffs no longer keep an archived employee just because of an empty "waiting for files" row; they stay only when the cutoff holds real attendance, requests or leave for them.

## 2026-10-12 — Employee Status replaces Employment type
- "Employment type" is now **Employee Status** everywhere (Employee 201, Add Employee, recruitment conversion, Organization Setup, directory filter and column, history). Choices: Regular, Probationary, Seasonal, Resign, Contractual, Part Time, Returnee-Probationary, Maternity Leave, On Leave (Project-Based and Fixed-Term remain; Intern retired).
- HR now picks one status. The internal Active / Probationary / On Leave status used by payroll, approvals and login is derived automatically (`EmployeeStatusPolicy`); the separate "Employee status" dropdown was removed.
- Choosing **Resign** moves the employee to the Employee Archive as Resigned (effective date and remarks required, login deactivated). Restore returns the employee to their previous Employee Status.
- Probationary / Returnee-Probationary → Regular is recorded as a Regularization and fills the regularization date when empty.
- Employment History shows "Employee Status: A → B". Migration: `database/migrations/20261012_employee_status.sql`.

## 2026-10-12 — Company Group → Company Name; Employer is PMBSI
- Employee 201 (Employment tab) now asks for **Company Group** first (Sw, Ocampo's, Urban Farm), then **Company Name**, which lists only that group's companies. "Brand / Client" is now **Company Name**; **Employer** is automatic (PMBSI, read-only).
- Company names come from the 2LOG Company Directory (Corporate Name sheet): Sw = 2L.O.G., INC., Cortona, Greco, Montepulciano, Orvieto, Perugia, Positano, Sterling, Vicenza; Ocampo's = Almandite, Ocampo's Ventures, Cryssoberyl, Artchic, and "Ocampo's" (Head Office); Urban Farm = Urban Farm. Sw Head Office staff are 2L.O.G., INC.
- Every branch has a default company from the RETAIL DIRECTORY NEW sheet (66 store codes checked against the DB: no conflicts). Choosing a Branch / Site pre-fills Company Group and Company Name; HR can still change them. New employees get the branch's company automatically.
- Existing employees were moved from the old short brands (CORTONA, VISCENZA…) to the official company rows and got their Company Group; the old rows are retired. Urban Farm has its own directory tab.
- Directory and payroll exports show the Company Name (e.g. "Cortona Trading Corporation", Head Office "2L.O.G., INC.").
- Organization Setup: "Business Units / Brands" is now **Company Names** (with Company Group). Migration: `database/migrations/20261012_company_name.sql`.

## 2026-10-12 — Automatic probation (6 months) and reminders
- Choosing **Probationary** (or Returnee-Probationary) now counts 6 months by itself: probation starts on the hire date (Returnee: the date HR sets it) and ends the day before the 6-month anniversary. Editing the hire date recalculates it; any other Employee Status clears it. Employee 201 shows the dates and days left; no typing or counting.
- **Reminders:** at 5 months the ADL (retail) or Manager (Head Office) the employee is under, HR, and the employee are notified; when the 6 months end, HR, the employee and the ADL / Manager are notified so HR can follow up. Not auto-regularized — HR sets Regular. Who is "under": Reporting To, else the ADL of the branch's area, else the department Manager.
- The bell now works (unread badge, latest notifications, mark as read) and reminders are also emailed through Gmail when `config/config.local.php` has the mail settings. New **Probation Watch** page lists everyone on probation with days left, who they are under, and reminder status.
- The check runs once a day when anyone opens HRIS and can be scheduled with `scripts/run_notifications.php`. Migration: `database/migrations/20261012_probation_notifications.sql`.

## 2026-10-12 — Regularization date fills itself
- On Add Employee, Employee 201 and the recruitment conversion form, picking Probationary (or Returnee-Probationary) and a hire date fills the **Regularization date** automatically (6 months after the hire date, e.g. Oct 12, 2026 → Apr 12, 2027) and locks the field. Other statuses keep it editable.
- The server sets it too, so it stays correct when the hire date changes; when a probationary employee becomes Regular, the regularization date becomes the effective date of that change. Existing probationary employees were updated.

## 2026-10-12 — Seasonal: 3-month contract, counted automatically
- Choosing **Seasonal** and entering the hire date now fills the contract end by itself: 3 months from the hire date (Oct 12, 2026 → ends Jan 11, 2027). Seasonal has no regularization date (the field stays blank and locked).
- The **Hire date** on Add Employee is now blank so HR types it in; the end date appears once it is entered.
- Reminders work like probation: the ADL / Manager and HR are told one month before the end (2 months in), and HR, the employee and the ADL / Manager when it ends. **Probation Watch** is now **Probation & Seasonal** and lists both. Existing Seasonal employees were given their dates.

## 2026-10-12 — Maternity Leave box
- Choosing **Maternity Leave** on Employee 201 shows a box: **Type** (Live birth — 105 days, Early termination of pregnancy — 60 days) and **Start date**. The **End date** and **Return to work** date appear by themselves (return = the day after the last leave day).
- Saving records the leave, sets the status to Maternity Leave and makes sure payroll sees approved leave days: an overlapping Maternity leave the employee already filed is linked, otherwise HR gets an approved Maternity leave record (no leave credit is deducted).
- The employee must give HR her **actual medical certificate** before returning: HR records it (date + optional scan, kept as a MEDICAL document), then uses **Return to work**, which restores the previous Employee Status. Return is blocked until the certificate is recorded. A mistaken entry can be cancelled.
- Reminders: 14 days before the leave ends, HR, the employee and her ADL / Manager are told to expect the medical certificate; on the return date HR is reminded if it is still missing. **Probation & Seasonal** also lists everyone on maternity leave.
- Maternity Leave can no longer be set or cleared from the plain Employee Status dropdown, and is hidden when adding an employee. Migration: `database/migrations/20261012_maternity_leave.sql`.

## 2026-10-24 — Role-based HR request types and role/user administration
- **File an HR Request** now shows only the request types your role may file. Staff: ATM, ID, Incident Report, Uniform, Payroll Concern. ADL / SC / OIC / TC / Manager: those plus Manpower and NetSuite Concern. Branch Transfer: HR/Admin only. The server re-checks the type on every save, not just the dropdown.
- Each type is a permission (`ticket.file.atm`, `.id`, `.incident`, `.uniform`, `.payroll_concern`, `.manpower`, `.netsuite`) that HR ticks per role in **Roles & Permissions** — no code change needed. Existing roles kept their current access.
- **Manpower Request** now goes straight to HR (no Manager/ADL approval step); HR sets the status. Tickets already open keep their original approvers. Payroll Concern still goes Manager/ADL then HR. TA/OB/OT/leave, Payroll Ticket Review and Recruitment are unchanged.
- **Users & Access**: change an existing user's role, link or unlink a user to an employee, and see which active accounts are not linked (they cannot file HR requests). A role change signs the account out.
- Safety rules: the Super Admin role cannot be assigned or edited from the screen; only a Super Admin can create or change HR/Admin portal accounts; you cannot edit your own role or grant permissions you do not hold. Role creation, permission changes, role changes and employee links are audit-logged.
- HR can create further roles on the Roles screen (ADL, SC, OIC and TC are now seeded; see the next entry). A role given the ADL or Manager approval permission appears automatically in Ticket Approvers.
- Migration: `database/migrations/20261024_010_role_ticket_access.sql` (rollback: `.down.sql`, refuses once the new permissions are in use).

## 2026-10-24 — Lead roles ADL, SC, OIC and TC
- New roles on the employee portal: **Area Development Leader (ADL)**, **Sales Captain (SC)**, **OIC** and **Team Coordinator (TC)**. They appear in the Users role dropdown. A role code that already exists is left as HR set it.
- Each can file the five staff request types plus Manpower and NetSuite, and has the same self-service access as Manager (no HR or admin access).
- Approvals: ADL and TC are ADL approvers, OIC is a Manager approver, SC approves nothing. Their users show up in **Ticket Approvers** accordingly.
- The existing **Coordinator** role (HR portal, Recruitment) is unchanged; it is not TC.
- Migration: `database/migrations/20261024_011_lead_roles.sql` (rollback: `.down.sql`, refuses once a new role has users or edited permissions).

## 2026-10-24 — Employee portal access follows the role's portal
- ADL, SC, OIC, TC and any role HR creates on the employee portal can now open the Employee Self-Service pages (dashboard, requests, leave). Before, only Employee and Manager could, even though the new roles could already file HR requests.
- HR-portal roles (e.g. Coordinator, HR User) and client accounts are still refused there. Super Admin and HRIS Admin keep access.
- What a role can do on those pages is still set by its permissions on the Roles screen. No database change.

## 2026-10-24 — Read-only ticket viewers
- Branch leads (Sales Captain / Team Coordinator), the ADL covering the branch's area, and a Head Office employee's Manager can now read that employee's HR requests from a new **Team Requests** page. Strictly read-only: no approve, return, reject, cancel, complete, comment or attachments, no notifications; Incident Reports and drafts are never shown.
- HR: new **Ticket Viewers** screen to assign branch leads in bulk by area or branch (several per branch), add an extra viewer for one employee, and see who has no viewer (employees, branches without a lead, ADLs without an account or area, retail branches without an area).
- ADL coverage uses "Assigned areas for ADL" in Employee 201; changing it is now written to the audit log.
- New permission "View team HR requests (read-only)", given to ADL, SC, OIC, TC and Manager only.
- Migration: `database/migrations/20261024_012_ticket_viewers.sql` (rollback `.down.sql` refuses once leads exist). Not applied to the live database yet.

## 2026-10-25 · Payroll Concern can always be filed
- Fixed: filing a Payroll Concern for a date in a cutoff that was not yet marked Open (e.g. 10/14/2026, cutoff 10/04–10/18 still "Upcoming") showed "This cutoff is not open for filing". It no longer blocks.
- Open cutoff: unchanged (Manager/ADL, then HR). Closed, locked, past-deadline, not-yet-open, or no cutoff for the date: the request goes straight to HR, flagged late, with the cutoff shown as "No cutoff found" when none exists.
- No database change. Locked payroll and attendance are never touched; HR still records a manual instruction for a later open cutoff.

## 2026-10-25 · Employees can read HR's response on their request
- Fixed: when HR marked a request In Progress, Served or Cancelled and wrote remarks, the employee could not see them. The ticket page now shows an "HR response" card with the remarks, who (HR) and when.
- HR can add an optional internal note that only HR sees; it is never shown to the employee, viewers, approvers or Accounting and is not in notifications.
- The ticket page is redesigned: summary card with status, a progress stepper, details, a chat-style comment thread and a collapsed History. The bell now tells the employee when HR changes their request.
- Migration `20261025_016_ticket_hr_notes.sql` adds one table; applied after a verified backup and clean up/re-run/down/up tests.

## 2026-10-25 · Vault is now the single source of truth (documentation only)
- The HRIS-Vault notes now describe every table, permission, rule, screen and route added by migrations 001-016, with a new hub note for HR tickets and a UI design-system note.
- SCHEMA.md, ARCHITECTURE.md, RULES.md and DESIGN.md are no longer maintained and point to the vault; their unique facts were moved into it.
- Every future patch must update the vault before its final report; the rule is in CLAUDE.md and in the new `hris-patch` skill, and CLAUDE.md maps the installed skills to task types.
- No code, database or setting changed.

## 2026-10-25 · Ticket screens: shared look and a stepper fix (no database change)
- Fixed: a request served straight from Pending showed a "3" on the In Progress step as if it was still to do. It now shows a dash and is read as "skipped, not needed".
- Dates in History and Comments now read "Oct 25, 2026 2:04 AM" (hover shows how long ago) instead of raw timestamps; history shows the readable status, and the Approval chain on service requests is a timeline with initials, role, name and status.
- New shared pieces for the next screens (header card, key-value grid, empty state, comment box) and equal 22px card padding. Looks and wording only: permissions, routing and privacy are unchanged.

## 2026-10-25 · File TA form shows the logs you already have
- Fixed: the Time Adjustment form showed all four logs as Missing (and four empty boxes) on days where Check Attendance already showed a Time In and Break Out, such as one or two punches. It now shows the same times, hides those boxes and asks only for what is missing, for example "Add missing Break In, Time Out."
- Submitting can no longer replace a time that is already logged. If nothing is missing, the request is refused.
- No database change. No time is invented, attendance records are not changed, and payroll computation and closed cutoffs are untouched.

## 2026-10-25 · Incident Reports become confidential cases (step 1 of 4: rules and data, no screens yet)
- An Incident Report now has a when, where, category and a short list of people (CC, Involved, Witness). Only the person who filed it, the HR reviewer handling it, people HR includes, and CCs the filer picked can read it, its messages or its files; viewers, Accounting and other employees cannot. HR no longer needs the old "sensitive tickets" permission, and that permission no longer opens a case.
- HR sets the case status (Submitted, Under Review, In Progress, Resolved, Closed, or Dismissed with a reason) with remarks every time; an HR reviewer who is part of the case cannot handle it. HR can include or remove people; HR-only internal notes stay hidden from everyone else. Notifications say only that a case was updated.
- Migration `20261025_017_incident_cases.sql` adds two tables and the new statuses; applied to the localhost database after a verified backup and clean up/re-run/down/up tests. No existing request changed. The screens (My Incidents, File an Incident, case page, HR queue) come in the next three steps; until then HR cannot change a case status from a screen.

## 2026-10-25 · Incident Reports: My Incidents and File an Incident (step 2 of 4)
- New sidebar group Incident Reports: My Incidents (the reports you filed or that were shared with you, with status and last update) and File an Incident (one page: title, category, what happened, when, where, branch, people, attachments). HR also sees Incident Cases (the old feed) there. Incident Report is no longer in File an HR Request or My HR Requests, and old links go to the new page.
- People can be added while filing: search by name, then choose CC, Involved or Witness. A CC can read the report; an Involved or Witness person is recorded for HR but cannot read it unless HR adds them. Search shows names and branches of active employee, HR and admin accounts only.
- Six categories (Workplace conduct, Harassment or discrimination, Safety and health, Property or equipment, Attendance-related, Other). If your account is not linked to an Employee 201 record, the page tells you to ask HR.
- No database change in this step. HR still cannot change a case status from a screen; that comes with the case page (step 3).

## 2026-10-25 · Incident Report case page
- Opening an incident now shows a case page: status and a five-step progress bar, a conversation everyone on the case can read, the people on the case, a summary, attachments and a timeline. Messages are sent from a composer that stays at the bottom of the screen.
- HR reviewers handling the case can change its status (remarks are required and everyone on the case sees them), add an optional note only HR sees, include or remove people, reopen a resolved or closed case and add internal notes. The person who filed can withdraw a report until HR starts reviewing it.
- Nobody new can read a case: the access rules are unchanged. No database change.

## 2026-10-28 - Employee work company
- Source patch: 2LOG / PMBSI work assignment in Add and Edit, conditional fields and 2LOG HR position filtering; legacy records remain unassigned until HR selects. PMBSI remains legal employer.
- Additive migration and guarded rollback prepared; not applied. PHP/JS syntax checked; runtime, database and browser acceptance not verified. No deployment or commit.


## 2026-10-28 - PMBSI Human Resource position restriction
- Employee Add/Edit PMBSI choices now limited to existing exact HR aliases, with matching server validation. 2LOG excludes HR variants. Missing active HR configuration is shown; incompatible saved choices require explicit correction. Unassigned stays optional. No database changes or deployment; runtime acceptance remains unverified.
- Verification: PHP lint passed EmployeeRepository.php and index.php; both affected inline scripts passed node --check. Static QA PASS WITH NOTES, three source scenarios reviewed. Runtime/browser/DB and active HR master availability not verified.

## 2026-10-28 - Work company handoff verification
- Migration cycle verified on isolated DB, not applied to live. Cleanup of Add/Edit form whitespace and a misplaced docblock. See Q64.

## 2026-10-28 - Work company migration applied (localhost)
- employees.work_company added to pmbsi_hris; no existing rows changed.

## 2026-10-28 - Organization Setup friendly add forms
- No manual Code on master add forms (auto-generated, unique), duplicate-name error, Retire/Reactivate labels, HR empty-state link in Employee Add/Edit. No migration.

## 2026-10-29 - Employee account linking and position
- Employee and Users linking clarity and server validation; Human Resource PMBSI position aliases supported. PHP lint passed; runtime and browser acceptance pending. No migration or DB write.

## 2026-10-29 - PMBSI departments (prepared)
- Prepared PMBSI department master and Employee 201 company filtering/validation with additive department classification migration. Static checks passed with notes; migration and runtime not verified.

- 2026-10-29: Organization Setup positions split by existing Employee Add/Edit eligibility into 2LOG, PMBSI and Needs classification. Company add validates name and optional active company department; shared records and eligibility unchanged, no schema or data migration. PHP lint passed; browser, application and DB behavior unverified.

- 2026-10-29: Organization Setup hides Needs classification and adds position default-department editing with company eligibility, active department validation, permission, CSRF and audit. Existing assignment and history data are unchanged; changing a default can affect later employee/recruitment validation. Human Resources Assistant removal remains blocked pending database reference verification; no DB access or write performed. PHP syntax checked; runtime and browser unverified.

- 2026-10-29: Organization Setup: improve row actions and position department editor; use Deactivate and Inactive wording. Static verification only.

## 2026-10-29 - Organization Setup side-by-side UI/UX

- Organization Setup UI/UX: preserve side-by-side desktop cards; name/status headings, separate metadata/actions, labeled rounded Add fields, wider Position name, expandable department panel, consistent focusable actions and responsive branch forms. Updated stylesheet cache marker. PHP lint and static checks passed; rendered/browser/app/DB behavior unverified.

## 2026-10-31 - My Profile (read-only)
- My Profile (page employee-profile): sidebar item for employees, and the top-right Profile menu for every internal account (employee, HR, admin). Hero, quick-link strip (Print Summary, Send Request, Send DTR Adjustment, Leave, Attendance; Payslip/Deductions/Additional/Downloads/FAQs as Soon), tabs Personal / Contact and Address / Employment (job, dates, pay rate, leave credits, history) / IDs and Documents (masked government IDs with audited Show, scanned documents grid with preview, filter and download). Employees cannot edit; HR maintains data in Employee 201. Old-page fields with no employees column are omitted. Visual and print acceptance pending.

- 2026-10-31: CONSTRAINTS.md and scripts/check.php added (quality gate); see [[Session-Log]].

- 2026-10-31: My Profile hardened after ship review (no-cache scans, EXIF thumbnails, memory guard, safe headers, reveal JS fix); tests 79/14/55.

- 2026-10-31 Sidebar rail v2: full logo, group-icon flyout, interactive group headers (UI only; see Session-Log).

## 2026-10-31 - HR dashboard v2 (UI only)
- Restyled the HR dashboard: Needs action / Overview KPI tiers, segmented Period control, three right-column cards, compact empty states, no bars for zero recruitment stages. `app/HrDashboardViews.php`, `public/assets/hr-ops.css` (`.hd2`), new `tests/hr_dashboard_ui_test.php` (floor 20). No data, permission, route or shared CSS change.

## 2026-10-31 - Team Requests lead notification and Follow up (Claude)
- Head Office Manager (Reporting To) and Retail ADL / Sales Captain / OIC now get an in-app notification when a request is filed and on every status change (not drafts, comments, attachments or Incident Reports). Generic text, ticket number only.
- Team Requests rows show a "New" badge until the lead opens the request; the sidebar badge for these notifications counts on Team Requests. Read-only leads get a "Follow up with HR" button (once per 24h per request) that notifies HR reviewers.
- Migration 019 `ticket_lead_followups` (applied to localhost pmbsi_hris 2026-10-31 after verified dump/restore). New suite tests/ticket_lead_followup_test.php (30 checks). Not pushed or deployed; browser not checked.

## 2026-11-02 - Auto-generated employee portal account
- Create Employee creates the portal account (email from name plus Company domain, generated password, Role HR or Employee); Create New User and "Link later" removed.
- Change password page and forced change at first sign-in; HR password eye (audited, 10 per minute); HR read-only Users and Access; HR reset for employee accounts.
- New HR Request type: Portal account / forgotten password. Migration 020. Config key security.credential_key required in config.local.php.

## 2026-11-02 - Portal accounts for existing employees (Claude)
- Employee 201 > Employment: the User account dropdown is replaced by Generate account (role Employee, or HR for who may). Employees directory: notice listing active employees without an account and a Generate all accounts button (Employee role only, result panel shows created and skipped with reasons).
- Removed link-existing-account (Users and Access form, link_user_employee handler, linkableEmployees). Saving Employment information no longer touches user_id (it would have unlinked accounts).
- No migration. New suites employee_bulk_account_test (35) and employee_bulk_account_http_test (19).

## 2026-11-02 - Remove portal account (Claude)
- Users and Access: Remove account (HR) and Removed accounts with Restore. Soft removal: login dead, email freed, employee unlinked, 201 untouched, history kept. Restore keeps the earlier status.
- Migration 021 (users.removed_at, removed_email, removed_employee_id, removed_status, permission users.remove) applied to localhost pmbsi_hris after a verified dump; must run before deploying this code elsewhere.
- Tests remove_account_test (30) and remove_account_http_test (17) pass; check.php full OK. Not run: browser, commit, push, deploy.


## 2026-11-02 - rec-manpower-intake (Claude)
- HR > Recruitment > Manpower Requests now lists Manpower Request tickets (Awaiting HR) next to manual requests, with an Open/Declined/All filter. HR reviewers Approve (employer, priority, optional title) or Decline (reason required). Approve creates one linked request and completes the ticket; Decline rejects the ticket and the requester sees the reason.
- Ticket screens refuse to mark a Manpower Request Served without its recruitment request. The existing ticket_manpower_cases were updated for this intended change (11 pass).
- Migration 022 (manpower_requests.ticket_id, unique, FK) applied to localhost pmbsi_hris after a verified dump; it must run before deploying this code elsewhere. Demo clients removed from live by scripts/remove_demo_clients.php (dry-run first, verified dump, row checks); 2 branches and 2 users unlinked, the active demo client login deactivated.
- The manual create form is open to every recruitment.manage user with an inline new employer; the applicant stage actions card uses recruitment.manage. Tests rec_manpower_intake_test (28) and rec_manpower_intake_http_test (19) pass; check.php full OK. Not run: browser, commit, push, deploy.

## 2026-11-02 - rec-jobs: HR posts jobs, public shows PMBSI only
- New `hr-jobs` (HR > Recruitment > Job Openings): list with All/Draft/Published/Closed tabs and search; plain form for create and edit; publish, unpublish, close, reopen (jobs are never deleted). Post job on an approved request row prefills the form; + New job covers walk-in vacancies and silently creates one APPROVED bridge request with no ticket. New `app/JobOpenings.php`, `app/JobOpeningsViews.php`. No migration.
- D5: removed the HR "Create new manpower request" form, the `create_manpower` action and `RecruitmentRepository::createManpowerRequest`; Pipeline now offers + New job. Manpower Requests lists tickets; no-ticket rows show only under All as HR posting.
- Public Careers, Home, job page, Apply select and the applicant tracker show PMBSI instead of the employer, and the employer name no longer matches a Careers search. One shared visibility rule (Published and not past closes_at) is used by every public reader, so Apply refuses closed or expired jobs.
- Tests rec_jobs_test (50) and rec_jobs_http_test (38); module 1 tests updated for D5 with floors unchanged. check.php full OK on a scratch copy. Not run: browser, commit, push, deploy.

## 2026-11-02 - Job form polish
- New job form: employer is automatic (PMBSI), Department removed, Employment type now Regular / Probationary / Seasonal / Contract / Part-time / Project-based / Fixed-term / Internship. No migration.

## 2026-11-02 - Apply page and HR applicant profile
- Apply: position comes from the clicked job (no dropdown); Apply Now goes to Careers. HR applicant page: JobStreet-style header, stage tracker, tabs, Job details, resume view/download (new HR-only route). Applicants list shows type and location. No migration.
- Applicant stage: normal stages advance automatically; HR sets only Reject / Withdraw / On Hold / Return (Resume at to go back). Start screening button at Applied.
- Applicant Update button continues to the next step by default (before Offer); Reject / Withdraw / On Hold / Return are optional choices.

## 2026-11-02 - Interview invite email and interview chain
- Scheduling an interview (HR or reviewer) emails the applicant the type, date/time and Meet link (off until `mail.send_interview_invites` is true); timeline note, Resend invite button; HR scheduling no longer pushes a later stage back.
- Reviewers can pass a candidate to the next interviewer and name a Final interviewer; the final Proceed hands the candidate back to HR (Final interview done, next: Offer). Proceed no longer moves a candidate to Offer by itself.
- Migration 024 (application_reviewers.is_final, passed_by) applied to localhost.

- 2026-11-03 applicant-profile-polish T1+T2 (Claude): the stage label "Manager Review" is now "Hiring Review" (label only, code CLIENT_REVIEW unchanged); hr-applicant is a two-column layout (main + sticky side column with Screening rating, Endorse to Hiring review, Timeline). Scorecard (T3) waits for John OK on migration 030.

- Interview scorecard: 1-5 per criterion (Communication, Job knowledge, Attitude, Culture fit, Overall impression) plus Proceed / Hold / Do not proceed, advice only. New table interview_scorecards (migration 030, applied to localhost). Tests interview_scorecard_test (21) and _http_test (15) added to the check floors.

## 2026-11-03 - Pass to next interviewer follows Head Office / Retail (local)
- Head Office candidates now go HR -> TC -> Manager only; Retail candidates go to the ADL, then the OIC, SC and MT of the area and branches HR sets on the job opening. Managers and TC never appear for Retail, and ADL, OIC, SC and MT never appear for Head Office. Database: new table job_opening_branches (migration 031).

## 2026-11-03 - Applicant page declutter (local)
- Sections collapse by stage; an accepted offer is locked; deployment branch is pre-filled from the job opening (other branches still selectable as an exception).

## 2026-11-04 - Reporting To box removed (local)
- Employee 201 employment form no longer shows "Reporting To / Manager or ADL". Existing assignments are kept.

## 2026-11-04 - Reporting To hidden for Consultant (local)
- Employee 201 form hides "Reporting To / Manager or ADL" only when Employee Status is Consultant.

## 2026-11-04 - ADL Reporting To lists the Retail Manager (local)
- Retail Manager account now has the Retail Manager role, so ADLs can pick them in Employee 201. A Retail Manager with the wrong account role shows disabled with the reason.

## 2026-11-04 - Pipeline split by Head Office / Retail (local)
- The Recruitment Pipeline now shows Head Office or Retail, never mixed. Each HR has a saved view and can switch; Retail can be limited to chosen branches. Migration 036 (two small tables). Applicants opened from the board follows the same filter.

- 2026-11-05 (Codex): SPEC ONLY, SPEC-hr-user-role-change.md draft. John confirmed existing employee-portal role options in Users & Access. Narrow HR assignment authority proposed; no permission/code/data change. Spec approval pending; latest app patch remains pipeline-site-split / migration 036 (local per recorded evidence). Static source inspection only; no runtime checks. Graphify note extraction/refresh/export pending.

- 2026-11-05 (Codex), spec addendum only: John confirmed Retail Manager = Manager workspace/team tools and approvals, excluding Recruitment/Hiring Review. Preserve RETAIL_MANAGER role, ADL hierarchy and assigned-team scope. Added independent slice/map to SPEC-hr-user-role-change.md; combined approval pending. No code/schema/grants/data changes or runtime checks; screenshot's My Workspace is collapsed, actual account grants unverified. Latest app patch still pipeline-site-split / 036 local. Graph semantic refresh/export pending.

- 2026-11-05 (Codex), SDD subtitle addendum only: John requested replacing the circled employee-dashboard subtitle. Recommendation pending review: View your attendance, leave balances, and requests in one place. Source index.php:967; preserve shared hero styling and natural wrapping. One exact occurrence found. Hardcoded Good morning at 6:23 PM recorded separately, left outside scope. SPEC-hr-user-role-change.md still draft; no app/DB changes or runtime checks. Graph note extraction/refresh/export pending.

- 2026-11-05 (Codex): John APPROVED SPEC-hr-user-role-change.md (HR employee-portal assignment, Retail Manager parity excluding Recruitment, approved subtitle). Plan saved: 05-Implementation/Plans/2026-11-05-hr-role-change-retail-parity.md, DRAFT awaiting review. Proposes dedicated users.assign_employee_role and migration artifact 037 (number recheck required), no execution. No Plan implementation/tasks/code/DB/browser/app tests. Existing tasks/plan.md and todo.md belong to My Profile and were preserved. Graph semantic registration/extraction/refresh/export pending.

- 2026-11-05 (Codex), John clarification: My Team includes a read-only PEOPLE roster, not just requests/approvals. Retail Manager sees assigned ADLs via reporting hierarchy; other heads their own assigned team. SPEC-hr-user-role-change.md and 2026-11-05-hr-role-change-retail-parity plan extended. Plan proposes employees.view_team plus mandatory own-team scope; no grant/data change. Zero requests or missing subordinate login must not hide members; no all-employees fallback/private HR fields. Revised plan approval pending; no code/runtime checks. Graph refresh pending.

## 2026-11-05 (Claude)
- Added: Users & Access row menu "Change role" dialog for HR (users.assign_employee_role); My Team roster page employee-team (employees.view_team, read-only, searchable, paged); employee dashboard subtitle now "View your attendance, leave balances, and requests in one place." Migration 037 authored, not applied. Not committed.
