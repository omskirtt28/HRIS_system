# CLAUDE.md

## Codex project setup (John, 2026-10-27)
Codex project roles live in `.codex/agents/` and patch/debug/UI/review skills in `.agents/skills/`. Routing: AGENTS.md and `HRIS-Vault/00-Meta/Codex-Agent-Workflow.md`. Shared Claude role bodies and Obsidian stay the knowledge source. This requested setup authorizes skill creation for this task; routine patches do not install skills. For Codex, planner first includes docs/config and small fixes; main owns vault sync. Current syntax/static-only, no-browser, no-runtime/DB-test/write, no-commit/push/deploy rules in AGENTS override older shared actions. Historical Codex-First withdrawal stays recorded; Claude session mode is unchanged.


## HRIS spec vault

The product and design specs for this HRIS live in the Obsidian vault at `HRIS-Vault/` (plain markdown). Note: `Claude-Code-Brief.md` refers to the vault as `./docs/vault`; in this repo it is `./HRIS-Vault`.

### Before starting any work
1. Read `HRIS-Vault/05-Implementation/Claude-Code-Brief.md`.
2. Read `HRIS-Vault/05-Implementation/Build-Plan.md`.
3. Then read whatever those two point to for the current slice (module notes, `03-Design/Data-Model.md`, `03-Design/Business-Rules.md`, the ADRs in `04-Decisions/`).

### How to work
- Work on **one slice per session**. Do not start the next slice until the current one is done and recorded.
- After finishing a slice, update `HRIS-Vault/05-Implementation/Progress.md` (tick the slice, add the date and a one-line note of what changed).
- Treat anything marked **NEEDS VERIFICATION** in the vault (statutory rates, legal rules, etc.) as unconfirmed. Do not hard-code it as fact; flag it in code comments or ask before relying on it.
- The existing PHP app in this folder is the current system. Read `README.md`, `ARCHITECTURE.md`, `RULES.md` and `SCHEMA.md` before changing it, and do not rewrite working modules unless the slice calls for it.

### Standing workflow: test then apply
After Stage B, apply to the localhost database (`pmbsi_hris`) right away, without asking again, when **all** of these hold: (1) a fresh mysqldump was taken and its restore verified; (2) the isolated-DB tests passed, and any failing test is shown with proof it is unrelated; (3) the migration ran up, re-run, down, up cleanly and its down script is guarded against used data. Then re-check that no existing business rows changed, update the vault and commit locally.
**Never without John's explicit word:** `git push`, deploy to hosting, deleting data or roles, history purge, or applying anything that fails a check. If a check fails, stop and report.

### Vault sync (mandatory)
The Obsidian vault is the ONLY source of truth. Vault sync is MANDATORY before the final report of every patch: Progress.md, the matching 02-Modules note, Data-Model.md for every migration, Business-Rules.md, Screens.md/API-Surface.md for new screens or routes, Decision-Log.md, Open-Questions.md and CHANGELOG.md. The final report MUST list the vault files changed. A patch with no vault files listed is not finished. Never add content to the repo-root SCHEMA/ARCHITECTURE/RULES/DESIGN docs.

### Plan and session log (mandatory)
When John says "plan" (or plan mode is used), write the plan to `HRIS-Vault/05-Implementation/Plans/<YYYY-MM-DD>-<slug>.md` from `Plans/_Template.md` (including the ready-to-paste PowerShell `claude "..."` execute prompt) and add an entry to `HRIS-Vault/05-Implementation/Session-Log.md` BEFORE calling ExitPlanMode. After execution, set the plan's Status to done and log it in Session-Log.md. At the start of a session, read the latest Session-Log.md entries so John does not have to re-explain. Plans and logs are written in Taglish like the conversation; keep them short.

Repo-root SCHEMA.md, ARCHITECTURE.md, RULES.md and DESIGN.md are frozen pointers to the vault. The hub note for the ticket engine is `HRIS-Vault/02-Modules/Employee-Tickets.md`.

## Skills
Invoke the matching skill yourself, without being asked, as soon as the task fits, and name every skill used in the final report. Only skills that exist are listed (project `.claude/skills`, user plugins `backend-design`, `fullstack-dev-skills-bundle` (a bundle that duplicates most of them; prefer the `backend-design:` name), `design`, and built-ins).

| Task | Skill |
|---|---|
| Any change, patch, migration, fix, permission or docs/rules edit in this repo | `hris-patch` |
| Run the app, screenshots, browser checks, isolated test DB | `run-hris-system` |
| Writing, reviewing or running a database migration | `backend-design:migration-safety` (plus `backend-design:review-migration` before the file is committed) |
| Schema design: new table, column, constraint, entity | `backend-design:data-modeling-discipline` |
| Auth, roles, permissions, sessions, portal gates, scope | `backend-design:auth-and-authorization` |
| Any new endpoint, form handler or access change (every diff) | `backend-design:security-discipline`; also `security-review` for a diff review |
| Slow query, new list or report query, pagination, N+1 | `backend-design:query-discipline` (`backend-design:explain-this-query`, `backend-design:hunt-n-plus-one` when asked to analyse) |
| UI or screen change | `design:design-system` (match `HRIS-Vault/03-Design/Design-System.md`); `design:accessibility-review` for new screens |
| Report, dashboard, chart, KPI tile | `dataviz` |
| A new server-side component before coding | `backend-design:think-before-coding` |
| Writing or reviewing tests | `backend-design:testing-with-discernment` |
| Something broken or wrong in the data | `backend-design:debugging-discipline` |
| Review a diff before commit | `code-review` |

Installed but not mapped (use only if the user asks): `backend-design:` boring-by-default, error-handling-as-design, idempotency-and-side-effects, observability-by-default, performance-and-scaling, audit, design; all `marketing:*`, `finance:*`, `engineering:*` (architecture, debug, deploy-checklist, documentation, incident-response, standup, system-design, tech-debt, testing-strategy), `design:` (design-critique, design-handoff, research-synthesis, user-research, ux-copy), `agentic-sdlc-kit:*`, the duplicate `fullstack-dev-skills-bundle:*` copies, `anthropic-skills:*` (docs, docx, pdf, pptx, xlsx, google-workspace, import-memory, morning, skill-creator), and the harness skills (update-config, keybindings-help, fewer-permission-prompts, loop, schedule, simplify, claude-api, claude-in-chrome, run, plugin-authoring, init, artifact-*). Do not install or edit skills as part of a patch.

## Fix sweep (mandatory)
When John points at one UI/UX or backend issue, do not fix only that. Fix it, then grep the same pattern elsewhere in the repo: same kind of button/component/layout, inconsistent spacing/size/colour against `HRIS-Vault/03-Design/Design-System.md`, overflow and responsive breakage, missing states (hover, focus, disabled, loading, empty, error), buttons with no handler or inconsistent labels; and on the backend the same bug in other handlers, missing validation, missing permission/scope/CSRF checks, error handling, and UI/POST/API mismatches. Fix the small, clearly same-class items in the same patch. List (do not touch) anything large or risky: migrations, roles/permissions, payroll rules, data deletion. No redesign and no unrelated features. The final report must include: Fixed (file:line) / Found and also fixed / Found but left alone (why) / Not verified, plus the vault files changed. "Sweep" alone means apply this checklist to the whole project (plan first if large). Details: `HRIS-Vault/00-Meta/Conventions.md` (Fix sweep).

## Agent pipeline (automatic, mandatory)
Every prompt that asks to build, fix, change or patch something goes through this pipeline. John does not have to ask for it. The main session is the orchestrator (subagents cannot call other subagents).

1. **Plan**: call `planner` first. It returns the goal, the frontend/backend split, exact files, order, the sweep list, risks and up to 3 test scenarios.
   If John says "plan" (or plan mode is on), write the plan to HRIS-Vault/05-Implementation/Plans/<YYYY-MM-DD>-<slug>.md from Plans/_Template.md and add a Session-Log.md entry, then STOP for John's go-ahead. Otherwise continue automatically unless the plan touches migrations, roles/permissions, payroll rules or deletes.
2. **Build**: call `backend-engineer` and `ux-designer` (both 10 years experience) with their part of the plan. Backend first when the UI depends on a new endpoint/contract; in parallel only when their file sets do not overlap (both edit the repo).
3. **Test + critique**: call `patch-tester` (plus the `code-review` skill on the diff before commit). It runs the checks, tries the scenarios, critiques against the plan, the project rules, security and the sweep, and returns PASS / FAIL / PASS WITH NOTES.
4. **Fix loop**: on FAIL or MISSED SWEEP, send it back to the right engineer, then test again. Maximum 2 rounds; after that report honestly what still fails.
5. **Finish**: vault sync is mandatory (Progress.md, the module note, Data-Model.md for migrations, Business-Rules.md, Decision-Log.md, Open-Questions.md, CHANGELOG.md, Session-Log.md) and the report lists the vault files changed. The final report follows the Fix sweep format.

Skip the pipeline for pure questions, docs-only work, and one-line fixes. Existing project rules above take priority over this section.

## Session start and auto-update (mandatory)
- At the start of every session read `HRIS-Vault/05-Implementation/Session-Log.md` (latest entries) and `Progress.md`, so John does not have to re-explain. Read HRIS-Vault/05-Implementation/Claude-Code-Brief.md first. Use grep + partial reads; do not scan the repo.
- After every patch AND after any agreed topic/decision in chat, update the vault on your own (module note, changelog/session log, status), without waiting for a reminder.
- When John says a feature should be "the same as" an existing module, copy it AS IS and decide on your own; do not ask for details. Ask only when there is risk to permission, data or schema.
- Optional `simplify-gatekeeper` (review only): PROPOSALS para kay John; huwag i-apply nang walang utos niya.
- If John says it is for `/plan`: plan only, no edits, no delivery folders (the plan goes in the vault).
