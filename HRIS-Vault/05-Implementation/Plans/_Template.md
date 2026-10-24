---
title: Plan Template
type: plan
status: template
tags: [plan, template]
updated: 2026-10-25
---

# <Plan title>

Status: draft | approved | executing | done
Date: YYYY-MM-DD
Session: [[Session-Log]]

## Context
Bakit ginagawa ito, ano ang nag-trigger, ano ang inaasahang resulta.

## Mga gagawin
1. ...

## Critical files
- ...

## Verification
- ...

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/<petsa>-<slug>.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
