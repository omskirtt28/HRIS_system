---
name: hris-patch
description: Plan and implement HRIS changes with the project agent pipeline, static QA, same-pattern sweep, and mandatory Obsidian updates; includes configuration and documentation changes.
---

# HRIS patch

Paths are relative to the repository root. Read AGENTS.md and HRIS-Vault/00-Meta/Codex-Agent-Workflow.md first.

1. Read HRIS-Vault/05-Implementation/Claude-Code-Brief.md, latest Session-Log.md entries, Progress.md, and only relevant module/rule notes.
2. Main calls hris-planner before implementation; use its exact file ownership and frontend/backend split. Plan-only requests are saved to Plans/ and Session-Log.md, then stop.
3. Main delegates the relevant work to hris-backend-engineer and/or hris-ux-designer; parallel editing is allowed only for non-overlapping files. Main remains responsible for the result.
4. Read before editing; preserve CRLF, existing work, and neighboring conventions. Fix the small same-class sweep items; list risky or large findings without changing them.
5. Main calls hris-patch-tester for static QA, at most 3 scenarios once, and at most 2 fix rounds. Allowed runtime-code checks are php -l and node --check only; no browser, app, DB, or migration execution.
6. Main syncs Progress.md, Session-Log.md, root CHANGELOG.md, and the matching module/rules note before the final report. Update Data-Model.md only for an authorized model change; other vault notes only when affected.
7. Report Fixed / Found and also fixed / Found but left alone / Not verified, changed vault files, and skills used. QA does not authorize commit, push, or deployment.

.claude/skills/hris-patch/SKILL.md is shared historical workflow context. Do not inherit its automatic DB apply, browser testing, or local commit clauses against current AGENTS.md. List schema, permission/data, payroll/payment, or delete risks for the main session; do not improvise a migration or data fix.
