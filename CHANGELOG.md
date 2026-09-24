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
