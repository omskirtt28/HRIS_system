---
title: HR appearance palettes
type: plan
status: approved
tags: [plan, ui, hr]
updated: 2026-11-02
---

# HR appearance palettes

Status: approved
Date: 2026-11-02
Session: [[Session-Log]]

## Context
John: Appearance in the top-right menu should offer colour palettes (Soft Beige, Earth Tones, Soft Pastel + Light/Dark), HR portal only, saved per user. Spec: `SPEC-hr-appearance-palettes.md`. Full plan and tasks: `tasks/plan-hr-appearance-palettes.md`, `tasks/todo-hr-appearance-palettes.md`.

## Mga gagawin
1. Migration 027 `users.ui_palette` + `UserPalette` service.
2. Session load + `set_palette` POST (CSRF, HR only, whitelist).
3. `palettes.css` + contrast test (>= 4.5:1).
4. Appearance list, server-rendered attributes, JS.
5. Sweep, browser check, vault sync.

## Critical files
- `app/UserPalette.php`, `app/Auth.php`, `app/View.php`, `index.php`
- `public/assets/palettes.css`, `public/assets/hris-app.js`
- `tests/hr_palette_test.php`

## Verification
- php -l, node --check, `php scripts/check.php task`, then `full` with `HRIS_TEST_DB`; migration up/re-run/down/up; browser check by John.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute tasks in tasks/todo-hr-appearance-palettes.md one task at a time (spec SPEC-hr-appearance-palettes.md). Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
