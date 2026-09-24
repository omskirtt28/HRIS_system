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

## 2026-09-24 — Phase 1 Foundation

- Added permission-code RBAC and role-permission management.
- Added HR operational role and HR demo account.
- Added positions and employment types master data.
- Added tracked authenticated sessions.
- Added HR Admin Users, Roles & Permissions, Organization Setup, Security & Sessions and enhanced Audit pages.
- Updated sidebar visibility to honor permissions.
- Changed recruitment write authorization from hardcoded role lists to the `recruitment.manage` permission.
- Updated project documentation and database schema for the HRIS Core Foundation.

## 2026-09-24 — Official PMBSI Branding
- Integrated approved PMBSI logo across public, login and authenticated HRIS surfaces.
- Added browser favicon and app icon asset set.
- Removed active text-only placeholder branding.

- Enlarged and standardized the complete PMBSI wordmark in the public navigation header; compact PMBSI mark remains the favicon/app icon for small-format readability.

## 2026-09-24 — Login Logo Cleanup
- Removed the extra white login-logo container while preserving the full PMBSI wordmark.


## 2026-09-24 — Consistent PMBSI Logo System
- Standardized the full PMBSI wordmark across the public home page, login page, portal sidebars, and preview screens.
- Removed mixed logo treatments so branding stays consistent throughout the HRIS.


## 2026-09-24 — Login Visual Patch
- Updated the login page UI to match the approved split-screen visual mockup.
- Added icon-based benefit cards, refined spacing, and aligned the login form styling with the approved PMBSI HRIS presentation.


## 2026-09-24 — Login Visual V2
- Forced the exact approved PMBSI login visual onto the system with updated spacing, layout ratios, hero styling, and card proportions.
- Added a new CSS cache-busting version to ensure the browser loads the new design.


## 2026-09-24 — Exact Metallic PMBSI Logo
- Replaced the old flat PMBSI image asset with the metallic silver-circle/orange wordmark used in the approved login visual.
- Increased the login-page logo to match the approved visual proportions.
- Regenerated favicon/app branding assets from the same metallic source.


## 2026-09-24 — Official PMBSI Transparent Logo Integration
- Replaced older logo usage with the approved cleaned PMBSI transparent PNG as the standard logo asset.
- Applied the same official logo consistently to the public header/footer, login screen, portal/sidebar branding, dashboard branding areas, and favicon assets.
- Removed wrapper styling and filter effects that could introduce white boxes, borders, clipping, glow, or extra shadows around the logo.


## 2026-09-24 — PMBSI Logo Transparency / White Box Fix
- Added a new cache-busted approved transparent logo asset: `public/assets/branding/pmbsi-logo-transparent-v2.png`.
- Updated the home page, login page, internal sidebar/dashboard branding, and supporting previews to use the same approved transparent logo file.
- Strengthened branding CSS to remove any wrapper/background styling that could create a white box effect around the logo.


## 2026-09-24 — Phase 2A Employee Management
- Added the central `employees` master table and migration.
- Added HR Employee Directory with search, department, branch, and status filtering.
- Added Add Employee workflow with personal/contact, organization assignment, employment status, hire dates, and optional Employee portal account linking.
- Added employee profile overview and 201-file roadmap tabs.
- Connected the HR sidebar Employees item and HR dashboard quick access to Employee Management.
- Added audited `EmployeeRepository` business logic and updated architecture/rules/schema documentation.


## 2026-09-24 — Phase 2B Complete Employee 201 File
- Activated functional Employee Profile tabs for Personal Info, Employment, Government IDs, Emergency Contact, Documents, History, and Audit Trail.
- Added editable employee personal/employment records with duplicate and organization validation.
- Added Employee portal account link/unlink in Employment settings.
- Added secure profile photo upload and secure HR document upload/view/download/delete workflow.
- Added Government ID and Emergency Contact records.
- Added automatic employment history for assignment/status changes with effective dates and remarks.
- Added employee-specific audit trail and Phase 2B responsive UI layer.
