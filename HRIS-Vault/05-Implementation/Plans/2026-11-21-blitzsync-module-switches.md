---
title: BlitzSync module switches (slice 1)
type: plan
status: draft
tags: [plan, blitzsync, modules]
updated: 2026-11-21
---

# BlitzSync module switches

Status: draft (hintay sa sagot ni John sa open questions; hindi pa approved)
Date: 2026-11-21
Session: [[Session-Log]]

## Context
John: gawing modular ERP (BlitzSync) ang HRIS; modules naka-on/off per client, one DB per client. Friday audit (read-only) done. Direction: [[BlitzSync-ERP-Direction]] (00-Meta).

## Mga gagawin (slice 1, walang migration)
1. config/config.php: `'modules'=>['hr'=>true,'payroll'=>true,'accounting'=>true,'tickets'=>true]`; override sa config.local.php (bootstrap.php:5-10 na ang nagmemerge).
2. app/functions.php: `module_on(string $m): bool`.
3. app/Auth.php:117 `can()`: bago ang SUPER_ADMIN bypass, prefix->module map (accounting./payroll./ticket(s)./recruitment.); false kapag off.
4. index.php:33-58: i-wrap ang ticket/incident/accounting/payroll handlers sa `module_on()`; off = 404.
5. app/View.php:126-156: null-permission nav items (HR Request Inbox, Accounting Follow-up, My Incidents, Requests/Incident groups, HR Request Setup) -> `module_on('tickets')`.
## Slice 2
Payroll->Accounting post guard (PayrollRun.php:369, PayrollFinalPay.php:190, PayrollThirteenth.php:119 add `&& module_on('accounting')`), HR dashboard ticket tiles (HrDashboard.php:102-114), sweep ng natitirang null-perm links.
## Slice 3 (optional) admin read-only "Enabled modules" panel. Slice 4 Inventory/POS later.

## Module map
Core: router, Auth, View, admin-*, users/roles/permissions/audit. HR: Employee*, HrDashboard, recruitment files. Payroll: Payroll*.php, payroll-*, mig 045-047/050/051. Accounting: Accounting*, AP/AR/CashBank/FixedAssets/Remittances, acct_*, mig 039-044/048/049. Tickets: Ticket*, Incident*, mig 001-019.

## Risks
- Switch hides screens only; lahat ng client tatakbo ng lahat ng migrations (payroll lists LEFT JOIN acct_journal_entries).
- Auth::can security-critical: test all-on (no change) AT accounting=false (404 + menu hidden).
- Hindi pa na-check: client-portal routes, employee/admin dashboard cross-links, attendance HR vs Payroll, PAYROLL_CONCERN ticket split.

## Open questions kay John
1. Config switch per deploy OK, o gusto ng admin UI toggle (settings table + migration)?
2. Accounting off: payroll release skip GL post o block?
3. Recruitment hiwalay na module o bahagi ng HR?
4. SUPER_ADMIN ba-block din kapag off? (plan: oo)

## Verification
Tests all-on + accounting=false; php scripts/check.php task.

## Execute prompt
Hindi pa; isusulat kapag approved.

## Update 2026-11-21: subscription direction (John, hindi pa approved ang build)
John: kapag built na lahat, ibebenta bilang subscription na may tiers (Basic / Advanced / Pro style; screenshot ng SIMSO pricing ang reference, structure lang, huwag kopyahin ang text/presyo/claims). Pagbabago sa dating one-time + annual care ng BlitzSync Price List: pending ang kumpirmasyon ni John.
Implikasyon sa design: tier = bundle ng modules + limits (user seats, branches). Config switch pa rin ang base; dagdag na `plan`, `seat_limit`, `branch_limit`, `valid_until` sa config (slice 1b). Billing/payment collection hiwalay (manual muna).
Open: (5) hosted ba ng BlitzSync (kayo ang nagpapatakbo, kayo mag-on/off) o sariling hosting ng client? (6) sino ang magse-set ng tier: kayo lang (config) o self-service upgrade?

## Sagot ni John 2026-11-21 (design decisions; "go" para sa build ay hindi pa sinasabi)
1. Config switch per deploy: OO (walang admin toggle, para hindi ma-unlock ng client ang sariling module).
2. Accounting off: payroll release SKIP ang GL post (rekomendasyon ni Jarvis, tinanggap).
3. Recruitment: bahagi ng HR.
4. SUPER_ADMIN: bina-block din kapag off ang module.
5. BlitzSync ang magho-host (SaaS), kaya kailangan maghanap ng hosting + domain. Rekomendasyon ni Jarvis: VPS (Singapore region), isang vhost/subdomain + isang DB per client, daily offsite backup. Pending desisyon ni John.
6. Tier: BlitzSync lang ang nagse-set (walang self-service).
Flag: payroll data = personal data; Data Privacy Act (RA 10173) / NPC registration, NEEDS VERIFICATION bago mag-live sa client.
