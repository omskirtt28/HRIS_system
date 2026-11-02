---
title: Plan - rec-manpower-intake
type: plan
status: draft
tags: [plan, recruitment, manpower]
updated: 2026-11-02
---

# Plan: rec-manpower-intake (Manpower Request tickets -> HR Manpower Requests)

Status: done (built 2026-11-02, local; see Session-Log)
Date: 2026-11-02
Session: [[Session-Log]]
Spec: `SPEC-rec-manpower-intake.md` (v2). Tasks: `tasks/todo-rec-manpower-intake.md`. Parent map: `SPEC-recruitment-map.md` (module 1 of 5).

## Context
Manager (HO) / ADL (Retail) nag-file ng Manpower Request ticket, pero ang HR page `hr-manpower` ay `manpower_requests` lang ang binabasa kaya hindi nila nakikita ang ticket. John approved: remove demo clients, HR user ang magdadagdag ng employer, salary optional (belongs to rec-jobs), walang bagong permission (HR_USER ay may `recruitment.view/manage` na, live read-only check), at HR ang pipili ng employer sa Approve step (Q-G).

## Design (isang pagbabago kumpara sa spec v2, kailangan ng okay ni John)
Spec v2 said: gumawa ng linked `manpower_requests` row pagdating ng ticket sa HR. Pero `client_id` ay required at ang employer ay pipiliin pa lang sa Approve, kaya hindi pwedeng gumawa ng row nang walang employer nang hindi binabago ang schema. Mas simple na plan:
- Ang HR list ay UNION: (a) `manpower_requests` rows at (b) MANPOWER_REQUEST tickets na wala pang linked row (Awaiting HR decision / Declined).
- Row ay ginagawa lang sa APPROVE (kasama ang employer at priority), sa isang transaction kasama ang ticket step. Walang hook sa ticket engine, walang backfill, `client_id` nananatiling required.
- Decline: walang row; ticket lang ang nagiging REJECTED (may remark), lumalabas sa "Declined" filter mula sa ticket.
- Kung ayaw ni John ng ganito, fallback ay gawing nullable ang `client_id` (empty table, madali) at gumawa ng row sa intake.

## Mga gagawin
1. Migration 022 (additive): `manpower_requests.ticket_id BIGINT UNSIGNED NULL UNIQUE` + FK sa `tickets(id)`. Down: guarded, tumatanggi kapag may row na may ticket_id.
2. Service `app/ManpowerIntake.php`: `list(filters)` (union + Source column + status filter), `approve(ticketId, version, clientId, priority, remarks)` (transaction: lock ticket, validate HR + status PENDING_HR/IN_PROGRESS + walang existing link, insert row APPROVED with approved_by/at, then `TicketService::act(..., 'COMPLETE')`), `decline(ticketId, version, remarks)` (remark required, `TicketService::act(..., 'REJECT')`), `addEmployer(name)` (trim, max length, unique name/code), mapping ng fields (quantity -> headcount, position_code -> title, Probationary -> FULL_TIME, Seasonal -> CONTRACT, original value at job description sa notes, branch/department kapag mag-resolve).
3. Routes sa `index.php`: POST `approve_manpower`, `decline_manpower`, `add_employer` (CSRF, `Auth::requirePermission('recruitment.manage')`, return page `hr-manpower`). Palitan ang `Auth::is('SUPER_ADMIN','RECRUITMENT_MANAGER')` sa create form ng `Auth::can('recruitment.manage')` (D3).
4. UI `hr-manpower` (index.php ~1554): Source column (Ticket #id / Manual), Filled / Requested, status filter (default hides Declined), Approve dialog (Employer select + "+ Add employer", Priority, remark optional), Decline dialog (remark required), detail ng ticket (Replacement/Additional, replaced employee, requester, JD). English lang, ayon sa Design-System.md, keyboard/focus/disabled states.
5. Demo clients: migration 023 (data, gated): verified dump muna, i-NULL ang `branches.client_id` (ids 4, 5) at `users.client_id` (ids 5, 6), delete ang 5 demo clients by code; down ay nagbabalik lang ng 5 rows (hindi ang links, nasa dump). Alisin din ang demo INSERT sa `database/schema.sql`. Apply sa `pmbsi_hris` ayon sa standing workflow (R1 approved ni John: unlink + delete).
6. Sweep: lahat ng nagbabasa ng `clients` (RecruitmentRepository, careers/job pages, endorsements, branch list), `AdminDataResetService` (FK order dahil sa bagong ticket_id), at iba pang POST handler sa recruitment na may parehong permission/CSRF gap.
7. Tests + `php scripts/check.php task`/`full`, itaas ang constraint floors (huwag ibaba), vault sync, Session-Log, handoff checkpoint.

## Critical files
- `database/migrations/20261102_022_manpower_ticket_link(.down).sql`, `20261102_023_remove_demo_clients(.down).sql`, `database/schema.sql`
- `app/ManpowerIntake.php` (bago), `app/RecruitmentRepository.php`, `app/TicketService.php` (tawag lang sa `act`, walang edit), `index.php`, `public/assets/hris-modern.css` (kung kailangan)
- `scripts/check.php`, `scripts/ticket_stageb_database.php` (tingnan kung pinapayagan ang column add at ang data migration), `CONSTRAINTS.md`
- Tests: `tests/rec_manpower_intake_test.php`, `tests/rec_manpower_intake_http_test.php`

## Risks
- `TicketService::act` ay may version check at HR-step gate; kapag nag-fail ang ticket step, rollback ang row (isang transaction).
- Dalawang HR user na sabay mag-approve: unique `ticket_id` at ticket lock ang pipigil sa duplicate.
- Hindi pa sigurado ang code list ng `position_code`; kung wala, code ang ipapakita (Q-C).
- Ang `ticket_stageb_database.php` apply script ay maaaring hindi pa pumayag sa data migration (UPDATE/DELETE); aayusin lang kung kailangan at itatala.
- Live bago: manpower_requests 0, jobs 0, applications 0, kaya mababa ang data risk. Walang push/deploy.

## Verification
- Scratch DB lang: tests ng service at HTTP (HR approve/decline, non-HR 403, bad CSRF, double submit, refresh, requester nakakakita ng decision, Declined filter, Add employer validation).
- Migration 022 at 023: up / re-run / down / up, guarded down, verified dump at restore bago i-apply sa `pmbsi_hris`; row counts ng business tables bago/pagkatapos.
- `php scripts/check.php full` na may `HRIS_TEST_DB`. Hindi pa isasama ang browser screenshots maliban kung sabihin ni John.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-rec-manpower-intake.md using tasks/todo-rec-manpower-intake.md and SPEC-rec-manpower-intake.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
