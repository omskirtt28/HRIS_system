# PMBSI HRIS

PMBSI Human Resources Information System built with **PHP + MySQL**, using a modern role-based UI for Employee, HR and HR Admin users. Recruitment is the first live business module; the HRIS Core Foundation is now implemented as the shared base for future Employee Management, Attendance, Leave, Requests, Employee Relations, Performance, Training and Offboarding.

## Stack

- PHP 8.2+
- MySQL 8 / MariaDB-compatible SQL for local XAMPP use
- PDO prepared statements
- HTML5 / modern CSS / vanilla JavaScript
- Secure PHP sessions
- Role-Based Access Control (RBAC)
- CSRF protection
- Audit logging

## Preferred Local URL

The project is designed to run locally at:

`http://localhost:3000`

From the HRIS project root in PowerShell:

```powershell
C:\xampp\php\php.exe -S localhost:3000
```

Keep MySQL running in XAMPP. Application links are relative, so localhost is not hardcoded into production navigation.

## Installation — New Database

1. Place the project in your HRIS folder, for example `C:\xampp\htdocs\HRIS_system`.
2. Start MySQL in XAMPP.
3. Import `database/schema.sql` in phpMyAdmin.
4. Copy `config/config.local.example.php` to `config/config.local.php` if local DB credentials differ.
5. Run the PHP local server on port 3000.
6. Open `http://localhost:3000`.

`database/schema.sql` already includes the Phase 1 Foundation tables and demo records.

## Upgrade — Existing Database

If you already imported the earlier HRIS Recruitment database, **do not re-import the full schema**. Run these migrations in order as needed:

1. `database/migrations/20260924_role_based_ui.sql`
2. `database/migrations/20260924_phase1_foundation.sql`

The Phase 1 migration adds:

- permissions and role-permission mapping;
- positions;
- employment types;
- HR operational role;
- server-side tracked sessions;
- demo HR account;
- initial permission grants for existing roles.

## Demo Accounts

Local/demo password: **demo1234**

| Experience | Email |
|---|---|
| Employee | `employee.demo@pmbsi.com` |
| HR | `hr.demo@pmbsi.com` |
| HR / Recruitment Manager | `a.domingo@pmbsi.com` |
| HR Admin / Super Admin | `winston.cruz@pmbsi.com` |
| Client Portal | `ops@primelogistics.com` |

Remove all demo accounts before production deployment.

## Phase 1 Foundation — Implemented

### Authentication and access
- Unified login.
- Automatic dashboard routing by portal/role.
- Server-side portal protection.
- Permission-code authorization through `Auth::can()` and `Auth::requirePermission()`.
- Session ID regeneration on login.
- Tracked authenticated sessions with revocation support.
- CSRF protection on write actions.

### HR Admin foundation
- User account list and account creation.
- Activate/deactivate user accounts.
- Roles and permission matrix.
- Custom role creation.
- Department master.
- Position master.
- Branch/site master.
- Employment type master.
- Security/session center.
- Audit logs.

### Recruitment
- Manpower Requests.
- Careers Site / Job Openings.
- Applicants and Applications.
- Pipeline stages and stage history.
- Interviews.
- Endorsements and Client Review.
- Offers / Deployments data model.
- Resume upload metadata.
- Recruitment reporting.

## Role Experiences

`Employee Login → Employee Dashboard`

`HR Login → HR Dashboard / Recruitment Workspace`

`HR Admin Login → HR Admin Dashboard / Access Control / Organization / Security`

The UI is role-specific, but the project uses one shared authentication system, design system and database.

## Important Files

- `index.php` — front controller and routes
- `app/Auth.php` — authentication, portal checks, RBAC and tracked session handling
- `app/FoundationRepository.php` — users, roles, permissions and organization master operations
- `app/RecruitmentRepository.php` — recruitment domain operations
- `app/View.php` — shared role-based shell and reusable UI components
- `database/schema.sql` — clean-install database
- `database/migrations/20260924_phase1_foundation.sql` — existing-database Phase 1 upgrade
- `public/assets/hris-modern.css` — current HRIS design system
- `public/assets/hris-app.js` — shell interactions
- `public/home-preview.html` — meeting preview for the public home page
- `public/meeting-preview.html` — meeting preview for role dashboards
- `ARCHITECTURE.md`, `DESIGN.md`, `RULES.md`, `SCHEMA.md` — project source-of-truth documentation

## Security Baseline

- Passwords use PHP password hashing APIs.
- SQL writes use PDO prepared statements.
- Authorization is enforced server-side; hiding a menu item is never treated as security.
- Permission changes and organization changes are audited.
- Client records remain client-scoped.
- Upload handling validates size and MIME type.
- Direct Apache access to private storage is blocked.

## Before Production

- Set `debug=false`.
- Remove demo users and sample recruitment records.
- Enforce HTTPS.
- Use a dedicated least-privilege MySQL user instead of `root`.
- Configure backups.
- Review role permissions using least privilege.
- Move private uploads outside the public document root where hosting permits.
- Add rate limiting / account lockout policy for authentication before public internet deployment.


### PMBSI Branding
The approved PMBSI corporate logo and favicon set are bundled with the system under `public/assets/branding/`. The public site, login screen, HRIS sidebar, meeting previews, and browser tab branding use these assets.
