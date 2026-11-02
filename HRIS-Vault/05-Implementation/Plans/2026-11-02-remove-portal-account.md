---
title: Remove portal account
type: plan
status: done
tags: [plan, accounts]
updated: 2026-11-02
---

# Remove portal account

Status: done (built 2026-11-02, migration 021 applied to localhost only)
Date: 2026-11-02
Session: [[Session-Log]]

## Context
HR kailangan ng "Remove account" sa Users & Access. Soft removal (`users.removed_at`), employee 201 hindi nagagalaw, email lalaya para makapag-Generate ulit (halimbawa maling company ang nagamit). Lahat ng HR users puwede. May Removed accounts view + Restore. Spec: SPEC-remove-portal-account.md.

## Mga gagawin
T1 migration; T2 removeUser/restoreUser; T3 routes; T4 UI; T5 sweep; T6 tests + vault. Detalye: tasks/plan-remove-portal-account.md.

## Critical files
app/FoundationRepository.php (L71, L453), index.php (L302, L438, L1854), app/EmployeeAccountService.php, database/migrations/, tests/.

## Verification
check.php task, bagong service + HTTP tests, scratch DB lang. Walang push/deploy.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-02-remove-portal-account.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
