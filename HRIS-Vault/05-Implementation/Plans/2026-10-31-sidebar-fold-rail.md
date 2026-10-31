---
title: Sidebar fold rail polish
type: plan
status: done
tags: [plan, ui, sidebar]
updated: 2026-10-31
---

# Sidebar fold rail polish

Status: done (built 2026-10-31, John chose recommended options: all 3 fixes, JS fixed tooltip, toggle stays in topbar)
Date: 2026-10-31
Session: [[Session-Log]]

## Context
Naka-fold na sidebar (88px rail) mukhang off sa screenshot ni John: logo dikit/putol sa kaliwa, may scrollbar sa loob ng rail, magkakahawig na icons na walang label. Spec: `SPEC-sidebar-fold-rail.md` (repo root).

## Mga gagawin
1. `sidebar-fold.css`: hide scrollbar sa rail (`scrollbar-width:none`), ayusin ang logo centering/padding.
2. `View.php`: `data-tip`/`title` sa nav items at logout (label na ng `.nl`), bump asset `?v=`.
3. Tooltip on hover + focus-visible sa rail (CSS fixed-position o maliit na JS kung ma-clip ng overflow).
4. Sweep: ibang rail elements (badge, Soon, profile avatar, logout) sa parehong state.
5. Walang pagbabago sa open sidebar, mobile drawer, permissions, routes.

## Critical files
- public/assets/sidebar-fold.css
- app/View.php (~l.68, 166-181)
- public/assets/hris-app.js (applySideFold / toggleSideFold, l.76-81) kung JS tooltip

## Verification
- php -l, node --check, `php scripts/check.php task`
- Browser (run-hris-system, isolated DB): folded rail sa 1440 at 1024, hover/focus tooltips, dark mode, <=900px drawer.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-10-31-sidebar-fold-rail.md using SPEC-sidebar-fold-rail.md. UI-only patch. Run php scripts/check.php task. Vault sync bago ang final report. Walang commit/push."
```
