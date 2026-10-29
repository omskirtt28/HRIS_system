---
name: hris-patch
description: Plan and implement HRIS changes with the targeted planning, static QA, same-pattern sweep, and mandatory Obsidian updates; includes configuration and documentation changes.
---

# HRIS patch

Paths are relative to the repository root. Follow the current project rules and reuse context already read.

1. Read only the relevant vault note or section and reuse session context. Task requests authorize relevant source reads and requested scoped edits; respect explicit Obsidian-only or plan-only requests.
2. Plan the goal, scope, exact files, sweep, risks and up to 3 scenarios before implementation. For plan-only requests, record the plan and stop.
3. Main handles small tasks; delegate only when needed and permitted, using bounded task packets and non-overlapping ownership.
4. Read before editing; preserve CRLF, existing work, and neighboring conventions. Fix the small same-class sweep items; list risky or large findings without changing them.
5. Main verifies the diff and static QA, at most 3 scenarios once; use a tester only when needed and permitted. Allowed runtime-code checks are php -l and node --check only; no browser, app, DB, or migration execution.
6. Main syncs Progress.md, Session-Log.md, root CHANGELOG.md, and the matching module/rules note before the final report. Update Data-Model.md only for an authorized model change; other vault notes only when affected.
7. Report Fixed / Found and also fixed / Found but left alone / Not verified, changed vault files, and skills used. QA does not authorize commit, push, or deployment.

Codex and Claude instructions are independent; do not load Claude configuration as part of the Codex workflow. List schema, permission/data, payroll/payment, or delete risks for the main session; do not improvise a migration or data fix.
