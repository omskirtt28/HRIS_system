---
title: Team Requests lead notification and Follow up
type: plan
status: done
tags: [plan, tickets, notifications]
updated: 2026-10-31
---

# Team Requests lead notification and Follow up

Status: done (built 2026-10-31, migration 019 applied to localhost)
Date: 2026-10-31
Session: [[Session-Log]]

## Context
Manager (Head Office) and ADL/SC/OIC (Retail) only saw requests by opening Team Requests. John decided: notify them on submit and every status change, no "Noted" button, Follow up button sends a nudge to HR only (24h limit), new table for follow-ups.

## Mga gagawin
Full task list: `tasks/plan-request-followup.md`; spec: `SPEC-request-followup.md`.
1. Migration 019 2. Lead recipients in TicketNotifications 3. TicketLeadFollowup + route 4. UI "New" badge + Follow up button 5. Tests + vault sync.

## Critical files
app/TicketNotifications.php, app/TicketViewerService.php (read), app/TicketLeadFollowup.php (new), app/TicketViews.php, index.php, database/migrations/20261031_019_*, tests/ticket_lead_followup_test.php.

## Verification
`php scripts/check.php task`; scratch DB migration up/re-run/down/up; regress viewer + COE tests.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-10-31-request-followup.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
