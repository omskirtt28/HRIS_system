---
title: I4 HR Incident Cases, Q62 contrast, attendance tests, housekeeping
type: plan
status: executing
tags: [plan, incidents, i4]
updated: 2026-10-26
---

# I4 HR Incident Cases, Q62 contrast, attendance tests, housekeeping

Status: executing
Date: 2026-10-26
Session: [[Session-Log]]

## Context
Susunod sa Session-Log: I4 ng [[2026-10-25-incident-report-module]]. Ang `hr-incidents` ay plain 4-column table pa rin, at hindi tugma ang dashboard count sa queue. Kinumpirma ni John ang Q61 (HR dismiss lang, nakikita ang pangalan ng HR) at Q62 (gawin ang darker light tokens). Tapos na pala ang attendance export fix (`2fc3e71`); test coverage na lang ang kulang.

## Mga gagawin
1. **Patch 1 I4 queue:** status tabs na may counts, search, filter (category, branch, date), aging, count parity sa dashboard (shared "open" definition), back-link na nagdadala ng queue state, empty/mobile states. Walang migration.
2. **Patch 2 Q62:** darker light `--hris-success/info/danger/warning`, hiwalay na commit, sukatin sa browser.
3. **Patch 3:** tests para sa `rematchImportedRows`, `currentReport`, `export_day_label`, `sourceGaps`. Walang behavior change.
4. **Patch 4:** commit ng docs/agents, Progress para sa I1-I4, isara ang Q61.

## Critical files
- `app/TicketModule.php`, `app/TicketRepository.php` (listing feed), `app/TicketIncident.php`
- `app/IncidentCaseViews.php`, `app/TicketServiceViews.php`, `public/assets/tickets.css`
- `app/HrDashboard.php`, `app/HrDashboardViews.php`
- `tests/incident_case_test.php`, `tests/hr_dashboard_test.php`, `tests/incident_browser.cjs`

## Verification
Isolated DB: incident_case, incident_case_ui, incident_ui, ticket_core, services, hr_dashboard, incident_browser, hr_ops_browser, bagong queue tests. Contrast >= 4.5:1 sa browser. Screenshots: Reporter / CC / HR / not-involved, light + dark, 1440 + 390.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the plan in HRIS-Vault/05-Implementation/Plans/2026-10-26-i4-hr-incident-cases.md: Patch 1 first, then 2, 3, 4 as separate local commits. Gamitin ang hris-patch skill at agent pipeline, isolated DB, vault sync bago ang final report. Walang git push."
```
