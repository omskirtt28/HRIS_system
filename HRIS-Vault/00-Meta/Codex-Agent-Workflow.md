---
title: Codex Agent Workflow
type: rule
status: active
tags: [rules, codex, agents]
updated: 2026-10-27
---

# Codex Agent Workflow

John requested project-scoped HRIS Codex subagents and coding/debug skills on 2026-10-27. Both assistants use the existing Claude role bodies and HRIS Obsidian vault. The historical [[Codex-First]] withdrawal stays recorded; this setup does not switch Claude's selected execution mode or change global settings.

## Pipeline

1. Read Claude-Code-Brief, latest Session-Log and Progress, then relevant notes only. Follow AGENTS.md and CLAUDE.md; current Codex restrictions override stale shared workflow actions.
2. Call `hris-planner` first for change requests, including docs/config and small fixes. Return goal, frontend/backend split, exact files, order, sweep, risks, unknowns and at most 3 check scenarios. Pure questions stay in main. For plan-only requests main saves Plans/ and Session-Log, then stops before implementation.
3. Main owns coordination and vault edits. Delegate needed work to `hris-backend-engineer` and/or `hris-ux-designer`, both 10-year senior roles. Assign exact file ownership, preserve others' edits, parallelize only disjoint files. `hris-researcher` is read-only targeted tracing. Only main spawns agents; no nested delegation.
4. `hris-patch-tester` reviews plan/diff/sweep and runs `php -l` for changed PHP / `node --check` for changed JS. Current checks are syntax/static only, max 3 scenarios once; config-only work uses format/reference checks. No browser, app execution, runtime/DB tests, DB writes or deployment. Return PASS / FAIL / PASS WITH NOTES with evidence. Static PASS does not prove runtime acceptance.
5. Send failures to the owning engineer, maximum 2 fix rounds. If unresolved, report SWITCH-TO-CLAUDE with completed/remaining work; Claude takeover requires John's approval. `hris-simplify-gatekeeper` then reviews read-only; extra simplifications stay proposals until authorized.
6. Main syncs Obsidian before reporting every patch, and records agreed decisions without reminders. Update Progress, Session-Log, root CHANGELOG and affected module/rule note. Migrations require Data-Model; business/screen/API changes require matching specs. Decisions go in Conventions + Decision-Log and a Start-Here link. Configuration-only work does not need unrelated business/schema edits.

No automatic commit/push/deploy, deletion, or permission/data/schema/payment change. Older Claude migration instructions are references, not authorization under the current Codex checks-only policy.

## Roles and skills

Project agents are standalone `.codex/agents/hris-*.toml` files. They inherit the parent's model, tools and permissions. They reuse matching `.claude/agents/` role bodies, ignoring Claude YAML model/tool names. Read-only is an instruction here, not a separate enforced sandbox.

| Codex agent | Shared Claude role | Work |
|---|---|---|
| hris-planner | planner | Read-only planning first |
| hris-backend-engineer | backend-engineer | PHP/MySQL coding and debugging |
| hris-ux-designer | ux-designer | UI, design-system and accessibility |
| hris-patch-tester | patch-tester | Read-only static QA and allowed lint |
| hris-researcher | researcher | Targeted read-only tracing |
| hris-simplify-gatekeeper | simplify-gatekeeper | Read-only proposals after QA |

If a running session cannot select a new role, use a general subagent given the exact HRIS definition and disclose it. Reopen the project/session if discovery has not refreshed. Do not fall back to global ORDESK instructions.

Codex discovers instruction-only skills in `.agents/skills/`:
- `hris-patch`: vault-first planning, coding, sweep, static QA and vault sync for changes.
- `hris-debug`: trace root cause before targeted fixes; no DB mutations.
- `hris-ui`: reuse Design-System, responsive/accessibility and UI states; static checks only.
- `hris-review`: read-only diff/plan/security review and allowed lint.

Claude's `.claude/skills/` and plugins remain separate; do not assume Claude plugin tools/skills exist in Codex. No downloads, runtime dependencies or paid service are needed.

## Sources and verification

Format follows [official custom-agent docs](https://learn.chatgpt.com/docs/agent-configuration/subagents) and [local-skill docs](https://learn.chatgpt.com/docs/build-skills). Static parsing and general-subagent use are separate from fresh local Codex discovery, which remains unverified until a fresh session loads these files.

Related: [[Conventions]], [[Decision-Log]], [[Start-Here]], [[Progress]], [[Session-Log]].
