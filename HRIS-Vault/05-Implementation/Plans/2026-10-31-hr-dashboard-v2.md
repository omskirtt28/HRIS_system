---
title: HR dashboard v2
type: plan
status: done
tags: [plan, ui, dashboard]
updated: 2026-10-31
---

# HR dashboard v2

Status: built 2026-10-31 (defaults for Q1-Q5); browser check pending
Date: 2026-10-31
Session: [[Session-Log]]

## Context
Luma na ang dating ng HR user dashboard (screenshots 2026-10-31): magkakapareho ang timbang ng 6 KPI cards, flat amber blobs sa Recruitment pulse kahit 0, tinted pill links, mahabang filter caption, siksik ang "Requires attention". Gusto ni John na i-upgrade sa parehong style ng bagong sidebar. UI/UX lang; walang bagong query, permission o route. Spec: `SPEC-hr-dashboard-v2.md` (repo root). Task list: `tasks/plan-hr-dashboard-v2.md` (hindi ginalaw ang `tasks/plan.md`, may bukas pang My Profile plan).

## Mga gagawin
1. `.hd2` scope sa `HrDashboardViews.php` + bagong block sa `hr-ops.css` (tokens lang).
2. Slim header + filter bar (period segmented control).
3. KPI sa dalawang tier: Needs action (3 malaki) at Overview (3 compact), delta chip.
4. Requests by type / wait-time bars restyle, compact empty states.
5. Kanang column hinati: Oldest pending, Incident Reports, Setup health.
6. Recruitment pulse walang bar sa 0; interviews at quick actions compact.
7. Dark mode, responsive, a11y polish; static test; vault sync.

## Critical files
- app/HrDashboardViews.php
- public/assets/hr-ops.css
- tests/hr_dashboard_ui_test.php (bago), scripts/check.php, CONSTRAINTS.md
- Hindi gagalawin: app/HrDashboard.php, hris-modern.css (shared sa ibang portals), routes, permissions

## Verification
- php -l, `php tests/hr_dashboard_test.php`, bagong UI test, `php scripts/check.php task`
- Browser screenshots (1440, 390, light/dark) kapag sinabi ni John

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-10-31-hr-dashboard-v2.md using SPEC-hr-dashboard-v2.md and tasks/plan-hr-dashboard-v2.md (answers to Q1-Q5 are in the spec). UI-only patch, use agent-skills:frontend-ui-engineering. Run php scripts/check.php task. Vault sync bago ang final report. Walang commit/push."
```
