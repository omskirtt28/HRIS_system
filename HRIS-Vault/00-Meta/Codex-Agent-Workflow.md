---
title: Codex Agent Workflow
type: rule
status: active
tags: [rules, codex, agents]
updated: 2026-10-27
---

# Codex Agent Workflow

> [!important] 2026-10-29: See the current Codex-only, low-usage rules below. Older pipeline and shared-role instructions in this note are historical and do not govern the current workflow.

John requested project-scoped HRIS Codex subagents and coding/debug skills on 2026-10-27. Codex and Claude now use separate configuration and share project facts through the HRIS vault. The historical [[Codex-First]] withdrawal stays recorded; this setup does not switch Claude's selected execution mode or change global settings.

## Local dev skills
Superseded mandatory pipeline/shared-role instructions removed from active routing on 30 October 2026; original note backed up outside the vault. Independent assistants and later authorization rules below remain in force.

Codex discovers instruction-only skills in `.agents/skills/`:
- `hris-patch`: vault-first planning, coding, sweep, static QA and vault sync for changes.
- `hris-debug`: trace root cause before targeted fixes; no DB mutations.
- `hris-ui`: reuse Design-System, responsive/accessibility and UI states; static checks only.
- `hris-review`: read-only diff/plan/security review and allowed lint.

Claude's `.claude/skills/` and plugins remain separate; do not assume Claude plugin tools/skills exist in Codex. The four local HRIS skills need no runtime dependency or paid service; the shared Addy installation below is separate.

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

<!-- DEV-SKILLS-20261030 -->
## Dev skills - 30 October 2026
Shared Codex skills: `C:/Users/John Joshua/.codex/skills/`. Installed Addy skills: `debugging-and-error-recovery` (bugs) and `frontend-ui-engineering` (UI); source revision `eb07f19f52c2669f2364897aaa293e2aaddd7b1c`, MIT. UI accessibility reference is included. Existing Ponytail remains for implementation/review.

Main handles small tasks. Load only the task-matching skill and reuse existing context; no automatic pipeline, meta-router, catalog rereads or copied SKILL.md files in the vault. Prefer a project skill when it already covers the task; do not stack equivalent workflows. Platform-required skills and explicit requests still apply.

Keep security, accessibility, required checks and project delivery rules. Upstream browser/commit/deploy instructions do not override project restrictions. Claude configuration remains separate. Source/frontmatter/resources checked; fresh discovery is recorded in the Brain installation receipt. Actual usage savings are unmeasured.

HRIS: retain `hris-patch`, `hris-debug`, `hris-ui` and `hris-review`; use these first when they cover the task, without stacking the matching generic Addy skill.
