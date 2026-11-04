---
title: Department picker follows Directory
type: plan
status: done
tags: [plan, employees, department]
updated: 2026-11-04
---

# Department picker follows Directory

Status: done 2026-11-04 (slices 1-6 built; migration 034 applied to localhost; see Progress.md)
Date: 2026-11-04
Session: [[Session-Log]]
Spec: `SPEC-department-picker.md` (repo root)

## Context
Department dropdown lists ~25 stored departments and Position lists all ~55. John wants Department = Manpower Planning, Inventory Management, Finance, HR, Retail, Marketing & Creatives, Online; Position = only that department's teams/positions (like Directory). Also removes the Retail Manager "does not belong to the selected department" error. No schema change; `employees.department_id` stays the team row.

## Mga gagawin (vertical slices, test after each)
1. **Mapping + test (no UI)**: add RETAIL, HR to `EmployeeDirectoryHierarchy` (ONLINE exists) + `picker()` (group -> teams -> positions from DB). Test: every active dept in exactly one group; every active position reachable; Directory labels unchanged.
2. **Server rule**: `EmployeeRepository.php:696` and `:330` derive `department_id` from the position; reject out-of-group with both names; PMBSI skip unchanged. Test: derive, reject, no-op save unchanged for all employees.
3. **Add Employee UI**: shared partial + JS (group select, optgroup positions); post group key, server derives. Browser check.
4. **Employee 201 + Convert**: same partial. Pre-select current group/position (no change until edited).
5. **Migration 034**: rename department id 19 "Online Shop" -> "Online" (guarded, idempotent, down restores only if name still "Online"). Apply only after dump + restore check + scratch tests.
6. **Sweep + vault**: grep other `name="department_id"`/`position_id` pairs; vault sync.

## Critical files
- app/EmployeeDirectoryHierarchy.php, app/EmployeeRepository.php (:330, :696), index.php (:1412, :1413, :1535, :1584, :1777), public/assets/hris-app.js, tests/department_picker_test.php, database/migrations/20261104_034_online_department_name.sql (+ .down.sql)

## Verification
- `php scripts/check.php task`; new test; existing adl_reporting, retail_manager, employee_profile tests stay green.
- No-op save of all employees on a scratch copy: department_id, position_id, Directory group, payroll export department unchanged.
- Browser (run-hris-system, scratch DB): three forms, Manpower Planning -> Administration / Information System / MIS / PMMD, Retail shows Retail Manager.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-04-department-picker.md with SPEC-department-picker.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
