# Claude agents and skills (token budget)

John, 2026-10-28: sessions ran out of context because every subagent re-read the vault and code. Fixed in `.claude/agents/*` (READ BUDGET) and CLAUDE.md "Token budget".

## Roles (`.claude/agents/`)
planner (read-only plan), backend-engineer, ux-designer, patch-tester (last, no repo edits), researcher (haiku lookups), simplify-gatekeeper (proposals only, on request).

## Rules
- Main reads Brief, Build-Plan and the latest Session-Log entries once; agents receive exact paths/line ranges and never reopen them.
- Grep first, then Read offset/limit. No whole-file or whole-repo reads. Progress.md is large: grep or tail only.
- Spawn agents only for migrations, permissions, payroll rules, deletes or multi-file work; otherwise main does it.
- Obsidian-only scope ([[Conventions]]) takes precedence when John invokes it.
- Skills: `hris-patch` (workflow), `run-hris-system` (scratch DB and browser). Load only when the task needs them. [[Codex-First]] is superseded; [[Codex-Agent-Workflow]] is the Codex side.

## Tungkol kay John (read this instead of asking him again)
- Si John ang owner ng PMBSI HRIS. Chat: Taglish, maikli, diretso. **Lahat ng user-visible text sa app (labels, buttons, empty states, errors, README) ay English lang**, walang Tagalog ([[Conventions]]).
- Hindi siya nag-uulit ng utos: kapag may sinabi/napagkasunduan, kusang i-update ang vault (module note, Progress, Session-Log, Decision-Log, CHANGELOG, Open-Questions) at ilista ang vault files sa final report.
- "Same as" ibang module = kopyahin AS IS, huwag magtanong ng detalye (magtanong lang kung may risk sa permission/data/schema).
- Kapag tinuro niya ang isang UI/backend issue: fix + sweep ng parehong pattern (Fix sweep sa project CLAUDE.md). Hindi redesign.
- Dashboard: Monthly/Quarterly/Yearly checkbox period picker (All/Clear), default Monthly/All, i-mirror sa Download Report. Charts: iisang Charges-style 3D bar design, label sa itaas, axis Q1-Q4 / January...
- Bawal nang walang malinaw na utos niya: `git push`, deploy, delete ng data/roles, pag-apply ng migration na bagsak sa check, live `pmbsi_hris` na walang verified dump. Commit ay local lang at kapag hiniling.
- Ayaw ng "kailangan pang i-download" na flow: ipakita agad ang laman (may download pa rin bilang option). Ayaw ng sticky/overlapping na UI at ng duplicate na button/seksyon na nakakagulo.
- Permissions/payroll/migration: ipakita muna ang plano; ang iba (maliit na UI/docs) gawin na agad.

## Sino gumagawa ng ano (buod)
| Agent | Gamit | Hindi gagawin |
|---|---|---|
| planner | Plano: split, files, risks, 3 test scenarios | Walang edit |
| backend-engineer | PHP/MySQL, permissions, handlers, SQL | UI layout |
| ux-designer | Pages, CSS, forms, a11y, responsive | Backend logic |
| patch-tester | Huling hakbang: lint, tests sa isolated DB, critique | Hindi nag-e-edit ng repo, hindi hahawak sa live DB |
| researcher | Mabilis na "where is X" | Walang code |
| simplify-gatekeeper | Proposals lang, kapag hiniling | Hindi nag-a-apply |
Main session = orchestrator; subagents hindi nagtatawag ng subagents. Skills: `hris-patch` (lahat ng pagbabago), `run-hris-system` (scratch DB, browser checks).
