# Claude agents and skills

Updated 2026-11-18 (John + Claude). Pinalitan ang lumang roster (planner, backend-engineer, ux-designer, patch-tester, researcher, simplify-gatekeeper — wala na sa `.claude/agents/` mula 4 Nov).

## Roster (`.claude/agents/`, lahat sonnet, lahat ponytail full)
| Agent | Gamit | Skills | Hindi gagawin |
|---|---|---|---|
| Jarvis (main) | Harness: nagbabato ng trabaho, vault, checkpoint, Graphify, commit | lahat ng kailangan | — |
| friday | Spec + Plan + bug/screenshot triage | spec-driven-development, planning-and-task-breakdown, debugging-and-error-recovery | Walang edit, walang DB write |
| edith | Builder | incremental-implementation, test-driven-development, impeccable + frontend-ui-engineering (UI), hris-patch | Commit/push/deploy, vault, pmbsi_hris |
| karen | Simplify + Constraints gate | code-simplification, constraint-driven-development, ponytail-review | Proposals lang maliban kung "John approved apply" |
| vision | Test + Debug | test-driven-development, debugging-and-error-recovery, run-hris-system | hris_clean writes, pmbsi_hris, pag-skip ng tests |

## Flow
- **Screenshot/bug:** friday muna (check + root cause + plan) -> ipapakita ni Jarvis ang plano -> "go" ni John -> edith -> vision. Hindi na diretso fix (John: redundant ang trabaho kapag hindi muna chineck). "diretso" = skip ng wait.
- **Medium/multi-file feature:** friday -> John -> edith -> vision; karen bago commit o kapag hiniling.
- **Maliit (1-2 files, walang screenshot):** Jarvis na lang, walang agent.

## Token rules
- Sonnet para sa lahat ng agents (mas tipid sa 5h/7d kaysa Opus; Haiku hindi pinili dahil mahina sa payroll/permission logic).
- Packet <=400 words, exact files + line ranges; agents hindi nagbabasa ng Brief/Build-Plan/Progress/Session-Log.
- Max 2 agents sabay, depth 1. Returns <=12-15 lines.

## Iba pang agents (installed, hindi pinangalanan)
Plugin: agent-skills:code-reviewer, security-auditor, test-engineer (overlap kay vision), web-performance-auditor. Built-in: Explore, Plan, general-purpose.

## Tungkol kay John
Tingnan ang [[Conventions]] at [[Shared Project Handoff]]: Taglish chat, English-only UI, auto vault sync, "same as" = copy AS IS, Fix sweep, walang push/deploy/delete nang walang utos.
