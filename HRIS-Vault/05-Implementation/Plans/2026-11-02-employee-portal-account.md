---
title: Employee portal account auto-generate
type: plan
status: draft
tags: [plan, auth, employees, tickets]
updated: 2026-11-02
---

# Employee portal account auto-generate

Status: draft (spec approved by John 2026-11-02, build not yet approved)
Date: 2026-11-02
Session: [[Session-Log]]

## Context
Today HR creates the employee, then a Super Admin creates and links a user. John wants the account made automatically when the employee is created: email `firstnamelastname@<company domain>` (PMBSI -> pmbsi.com, 2LOG -> 2log.com), generated password, role chosen on Create Employee (HR or Employee), employee can change the password, every HR user can view it with the eye icon, forgotten password goes through HR Requests. Create New User form and "Link later" are removed. Full detail: `SPEC-employee-portal-account.md` (repo root), `tasks/plan-employee-portal-account.md`, `tasks/todo-employee-portal-account.md`.

## Mga gagawin
1. T1 Migration 020: users.password_enc, must_change_password, password_changed_at; permissions users.view_credentials, users.view_directory, ticket.file.portal_account; ticket type PORTAL_ACCOUNT_REQUEST.
2. T2 EmployeeAccountService: email + password generator, AES-256-GCM copy (openssl; walang sodium sa XAMPP PHP).
3. T3 Create Employee: Role field, read-only email preview, atomic employee+account, remove "Link later".
4. T4 Bagong Change password page (wala pa nito ngayon) + forced change sa first login.
5. T5 Eye icon reveal (audit, rate limit) + read-only Users & Access para sa HR.
6. T6 Forgotten-password ticket sa HR Requests.
7. T7 Alisin ang Create New User form/handler, sweep.
8. T8 Tests, checks, vault sync.

## Critical files
index.php, app/EmployeeRepository.php, app/FoundationRepository.php, app/Auth.php, app/TicketTypes.php, config/ticket_types.php, config/config.local.example.php, database/migrations/20261102_020_*, tests/employee_portal_account_test.php, scripts/check.php, CONSTRAINTS.md.

## Risks
- Reversible password copy = exception sa BR-117; key sa config.local.php, fail closed kapag walang key.
- HR can reveal Super Admin password (John's decision D11): audit + rate limit lang ang bantay.
- Permissions grant sa Human Resources: insert-only migration, before/after diff.
- Existing 20 users walang copy hanggang ma-reset; walang bulk backfill.
- Role clean-up (HR Leave/Timekeeping/Recruiter/Payroll) = hiwalay na patch (D12).

## Verification
`php scripts/check.php task`, `full` sa scratch DB, migration up/re-run/down/up, permission diff, no existing business rows changed. Browser checks only if John asks. Walang push/deploy.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-employee-portal-account.md and tasks/todo-employee-portal-account.md, T1 to T8 in order. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
