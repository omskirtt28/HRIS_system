---
title: Bulk portal accounts for existing employees
type: plan
status: draft
tags: [plan, accounts]
updated: 2026-11-02
---

# Bulk portal accounts for existing employees

Status: done (built 2026-11-02, local only)
Date: 2026-11-02
Session: [[Session-Log]]

## Context
Na-import na mga employee walang login. Generate account button sa 201 file + isang click Generate all sa Employees directory (may notice), Active lang, Employee role lang sa bulk, walang "link existing". Spec: SPEC-employee-bulk-portal-accounts.md.

## Mga gagawin
T1 service + update na hindi nag-a-unlink; T2 actions; T3 201 UI; T4 directory notice; T5 tanggal link UI; T6 sweep + vault. Detalye: tasks/plan-employee-bulk-accounts.md.

## Critical files
app/EmployeeAccountService.php, app/EmployeeRepository.php (L664), index.php (L287, 1429, ~1862), app/EmployeeDirectoryModule.php, app/FoundationRepository.php (L453), tests/.

## Verification
check.php task, bagong service + HTTP tests, scratch DB lang. Walang push/deploy.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-employee-bulk-accounts.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
