
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
