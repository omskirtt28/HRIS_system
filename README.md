# PMBSI HRIS — Recruitment Module

Converted from the supplied Recruitment HTML/JavaScript prototype into a working **PHP + MySQL** module.

## Stack

- PHP 8.2+ (tested for syntax with PHP 8.4)
- MySQL 8.0+
- PDO prepared statements
- HTML/CSS/vanilla JavaScript
- PHP sessions + CSRF protection
- XAMPP/Apache compatible

## Current Working Scope

The module now stores real records for:

- Clients, branches, departments, roles and users
- Manpower Requests
- Job Openings / Careers Site
- Applicant Master
- Applications
- Recruitment stages and immutable stage history
- Screening score / applicant answer
- Interviews
- Endorsements
- Client Reviews / Decisions
- Offers and Deployments database structure
- Resume metadata/uploads
- Audit Logs

Recruitment flow:

`Manpower Request → Job Opening → Applied → Screening → Interview → Endorsed → Client Review → Offer → Deployment → Deployed`

## XAMPP Installation

1. Copy the folder into your XAMPP web root, for example:
   `C:\xampp\htdocs\PMBSI_HRIS_Recruitment`
2. Start **Apache** and **MySQL** in XAMPP.
3. Open **phpMyAdmin**.
4. Import `database/schema.sql`.
   - The script creates the database `pmbsi_hris` automatically.
5. Copy:
   `config/config.local.example.php`
   to:
   `config/config.local.php`
6. Edit the MySQL connection if your local credentials are different.
7. Open:
   `http://localhost/PMBSI_HRIS_Recruitment/`

If the folder name is different, use that folder name in the URL.

## Seeded Demo Accounts

All seeded accounts use password: **demo1234**

| Portal | Email |
|---|---|
| Super Admin | `winston.cruz@pmbsi.com` |
| HR / Recruitment Manager | `a.domingo@pmbsi.com` |
| HR / Recruiter | `r.villamor@pmbsi.com` |
| Client — Prime Logistics | `ops@primelogistics.com` |
| Client — ABC Retail | `hr@abcretail.com` |

Remove demo accounts/passwords before production use.

## Important Files

- `index.php` — front controller and module routes
- `app/Database.php` — PDO connection
- `app/Auth.php` — authentication / RBAC
- `app/RecruitmentRepository.php` — Recruitment domain/data operations
- `app/View.php` — shared UI layout/components
- `database/schema.sql` — MySQL schema + demo seed
- `public/assets/app.css` — design converted from supplied prototype
- `storage/resumes/` — private resume storage
- `ARCHITECTURE.md`
- `DESIGN.md`
- `RULES.md`
- `SCHEMA.md`

## Security Already Included

- PDO prepared statements
- `password_hash()` / `password_verify()`
- Session regeneration on login
- CSRF protection on write forms
- Server-side role checks
- Client-level application scope
- Resume MIME/size validation and randomized filenames
- Audit logging for sensitive actions
- Storage `.htaccess` blocking direct Apache access

## Before Production

- Create a dedicated MySQL user instead of `root`.
- Set `debug` to `false` in `config/config.local.php`.
- Enable HTTPS and secure session cookie settings.
- Remove demo users and seed applicant data.
- Put uploads outside the public document root if hosting configuration permits.
- Add scheduled MySQL backups.
- Configure official company contact information.
- Add SMTP/SMS integrations only through centralized services, not directly inside Recruitment pages.

## Architecture Decision

This Recruitment module is the first business module of the larger HRIS. Shared future functionality such as Employee Master, Time & Attendance, Leave, Payroll integration and Employee Relations should reuse the HRIS Core rather than duplicating authentication, clients, branches, users or audit tables.
