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
