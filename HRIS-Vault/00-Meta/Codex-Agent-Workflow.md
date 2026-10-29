---
title: Codex Agent Workflow
type: rule
status: active
tags: [rules, codex, agents]
updated: 2026-10-27
---

# Codex Agent Workflow

> [!important] 2026-10-29: See the current Codex-only, low-usage rules below. Older pipeline and shared-role instructions in this note are historical and do not govern the current workflow.

John requested project-scoped HRIS Codex subagents and coding/debug skills on 2026-10-27. Both assistants use the existing Claude role bodies and HRIS Obsidian vault. The historical [[Codex-First]] withdrawal stays recorded; this setup does not switch Claude's selected execution mode or change global settings.

## Historical pipeline (superseded for Codex)

1. Read Claude-Code-Brief, latest Session-Log and Progress, then relevant notes only. Follow AGENTS.md and CLAUDE.md; current Codex restrictions override stale shared workflow actions.
2. Call `hris-planner` first for change requests, including docs/config and small fixes. Return goal, frontend/backend split, exact files, order, sweep, risks, unknowns and at most 3 check scenarios. Pure questions stay in main. For plan-only requests main saves Plans/ and Session-Log, then stops before implementation.
3. Main owns coordination and vault edits. Delegate needed work to `hris-backend-engineer` and/or `hris-ux-designer`, both 10-year senior roles. Assign exact file ownership, preserve others' edits, parallelize only disjoint files. `hris-researcher` is read-only targeted tracing. Only main spawns agents; no nested delegation.
4. `hris-patch-tester` reviews plan/diff/sweep and runs `php -l` for changed PHP / `node --check` for changed JS. Current checks are syntax/static only, max 3 scenarios once; config-only work uses format/reference checks. No browser, app execution, runtime/DB tests, DB writes or deployment. Return PASS / FAIL / PASS WITH NOTES with evidence. Static PASS does not prove runtime acceptance.
5. Send failures to the owning engineer, maximum 2 fix rounds. If unresolved, report SWITCH-TO-CLAUDE with completed/remaining work; Claude takeover requires John's approval. `hris-simplify-gatekeeper` then reviews read-only; extra simplifications stay proposals until authorized.
6. Main syncs Obsidian before reporting every patch, and records agreed decisions without reminders. Update Progress, Session-Log, root CHANGELOG and affected module/rule note. Migrations require Data-Model; business/screen/API changes require matching specs. Decisions go in Conventions + Decision-Log and a Start-Here link. Configuration-only work does not need unrelated business/schema edits.

No automatic commit/push/deploy, deletion, or permission/data/schema/payment change. Older Claude migration instructions are references, not authorization under the current Codex checks-only policy.

## Historical role setup (superseded for Codex)

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

## 2026-10-28 - Obsidian-only and minimal agent context
John: Obsidian-only muna, pati lahat ng agents. Main passes only the exact task, constraints and relevant vault note paths; no inherited full conversation (fork_turns=none). Read only the relevant note/section, reuse what was already read in the session, and do not read the whole vault/repo or repeat context reads. No source/code/tests/config reads or edits until John explicitly authorizes leaving Obsidian. This newer instruction takes precedence over the earlier automatic agent pipeline for this scope.

- 2026-10-28: John clarified: skills follow the same Obsidian-only scope as main and agents. Use only the relevant vault note/section and reuse session context. Do not open external SKILL.md files, auto-run skill workflows, spawn agents or expand reads beyond Obsidian unless John authorizes that scope. See [[Codex-Agent-Workflow]].

## 2026-10-29 - Independent assistants and low usage
- Codex and Claude operate independently: each uses its own instructions, agents, skills and memory. Share only relevant project facts, approved requirements, patch status and checks through this vault. Claude handoff requires John's explicit request.
- Main handles small tasks directly; no automatic agent or skill pipeline. Read only relevant notes and source, reuse context, and follow the 2026-10-29 task authorization update below.
- For later authorized coding work, use the Codex Ponytail skill appropriate to the task, keeping solutions small without dropping security or required verification. No automatic six-skill pipeline. Six Codex Ponytail skill files exist and are listed in this session; their version and fresh-session discovery remain unverified.
- Older mandatory role relay and shared Claude role-body instructions above are superseded for Codex. Existing project security, approval, verification and vault-checkpoint rules still apply. Usage savings have not been measured for this project.

## 2026-10-29 - Low-usage alignment checkpoint
Align Codex AGENTS, six agent definitions, four skill instructions and this vault routing: main handles small tasks, passes bounded context when delegation is needed, and reads only relevant material. Preserve security, static checks, existing decisions and historical notes. Verify TOML parsing, references and targeted diff; runtime behavior and actual token savings remain unmeasured.

- Applied alignment to Codex project instructions, six agent definitions and four skill notes. Agents use bounded task packets without automatic project or Claude role rereads; main handles small tasks and delegates only when needed and permitted. Historical decisions remain recorded. Static validation only; usage savings and fresh-session discovery remain unverified.

- 2026-10-29 verification: Fresh local Codex app-server skills/list discovered all four HRIS skills; config/read found no HRIS agent registrations initially. Added project .codex/config.toml with six role entries; a fresh config/read returned all six. TOML and target references passed. No agent was spawned; behavior, model usage and savings remain unverified.

- 2026-10-29 agent smoke test: Fresh normal read-only Codex CLI session spawned hris-planner and received its bounded hypothetical plan. The prompt supplied the Ponytail smallest-change principle; the agent applied it in its answer. This does not establish automatic Ponytail skill loading. Ephemeral CLI spawn failed with no rollout found. Actual savings and other agents remain untested.


## 2026-10-29 - Task authorization and low usage
John requested removal of repeated permission prompts. A request to investigate, plan or fix authorizes relevant source and configuration reads; a request to edit or fix authorizes scoped edits. Do not ask again for routine access. Explicit Obsidian-only or plan-only instructions still apply. Keep security, static checks, no automatic agents, and no deploy, commit or destructive actions without authorization. This supersedes older Codex Obsidian-only access restrictions above. Actual token savings are unmeasured.

- 2026-10-29: HRIS project config sets Plan mode reasoning to medium; global settings remain unchanged. Fresh-session behavior and usage savings remain unverified.
