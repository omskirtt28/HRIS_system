---
title: Claude Code Brief
type: plan
status: draft
tags: [plan, implementation, claude-code]
updated: 2026-10-17
---

# Claude Code Brief

## Purpose

A self-contained prompt John can paste into Claude Code to start building. It points at the vault instead of repeating it. Update the "Decisions" block before pasting.

## How to use

1. Open the vault folder (or copy `HRIS-Vault` into the code repo under `docs/vault`).
2. Fill the **Decisions** block from [[Open-Questions]] and [[Decision-Log]].
3. Paste the brief below. Start with SLICE-01 only.
4. After each slice, tick [[Progress]] and commit.

## Brief (copy from here)

````markdown
You are building an HRIS for a Philippine company (~300 employees). The specs are an Obsidian vault in ./docs/vault (plain markdown). I am a solo developer; you are my pair.

## Read first, in this order
1. 00-Meta/Start-Here.md, 00-Meta/Conventions.md
2. 01-Product/Vision-and-Scope.md
3. 05-Implementation/Build-Plan.md (we work one SLICE at a time)
4. The module notes named in the current slice, plus 03-Design/Data-Model.md and 03-Design/Business-Rules.md
5. 04-Decisions/ADR-001, 002, 003 and 00-Meta/Decision-Log.md for status

## Decisions (edit before pasting)
- Stack: <Laravel 11 / PHP 8.3 / MySQL 8 | keep vanilla PHP | other>   (ADR-001)
- Tenancy: <single company with company_id on tenant tables>           (ADR-002)
- Statutory rates: effectivity-dated rate tables, never constants       (ADR-003)
- Pay frequency / cut-off: <answer to Q4>
- Attendance source: <answer to Q5>

## Hard rules
- Brownfield: an existing PHP/MySQL app is in ./HRIS_system. Before each slice, inventory what exists and tell me keep/adapt/replace. Do not rewrite working code without saying why.
- Do not build on any ADR whose status is `proposed`, except stack-neutral work in the slice. Ask me.
- Modular monolith. Each module owns its tables; cross-module access through service interfaces. Business rules server-side in a framework-free domain layer. PayrollCalculator and DtrDeriver are pure functions (no DB, no clock).
- Money is DECIMAL(12,4) and a decimal library, never float. Timezone Asia/Manila set explicitly.
- NEVER invent statutory numbers (SSS, PhilHealth, Pag-IBIG, BIR, OT/night multipliers, de minimis). Create the mechanism and an unverified placeholder row; I supply verified values. Mark them `verified_at = NULL`.
- Temporal data (compensation, status, placement, schedule) is dated history, never overwritten. Balances are ledgers. Raw punches and audit_log are append-only.
- Every endpoint: deny-by-default permission, scope filter in the repository, server-side validation, audit row for state changes, no sensitive values in logs or notifications.
- Each BR-### you implement gets tests named test_BR_###_<what>. Payroll uses golden cases I hand-check.
- Migrations only; seeders use fake Filipino data. Never copy real PII into the repo.

## Working method
1. State the slice, list the FR/BR/SCR IDs in scope, and list the questions blocking it. Stop and ask if a blocker is open.
2. Plan the tasks, then implement test-first for domain logic.
3. Commit per task with clear messages. Run the full test suite before saying done.
4. At the end: verify each "Done when" in the slice, update 05-Implementation/Progress.md, and list anything you assumed.
5. If the vault is wrong or ambiguous, propose an edit to the vault note; do not silently diverge.

## Start now
Begin SLICE-01 (walking skeleton). First reply with the inventory of the existing app, the plan, and any blocking questions.
````

## Notes for John

- Keep the brief short; the vault carries the detail. If Claude Code starts guessing, point it back to the specific note.
- Ask it to quote the BR or FR ID it is implementing in commit messages. That makes [[Progress]] auditable.
- For payroll slices, give it the payroll officer's spreadsheet cases as fixtures. That is the real acceptance test.

## Related

[[Build-Plan]] · [[Progress]] · [[Start-Here]] · [[Conventions]] · [[Architecture]] · [[Decision-Log]]
