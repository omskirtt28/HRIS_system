# CLAUDE.md

## Quality constraints (2026-10-31)
Read `CONSTRAINTS.md` before changing code and run `php scripts/check.php task` before handing work back (`full` with `HRIS_TEST_DB` set before shipping). Never weaken it (lower a floor, skip or delete a test, add a suppression) to make a change pass; use its Exceptions table with an owner and expiry.

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

## Token budget (John, 2026-10-28; overrides the pipeline and session-start rules above)
- Only the main session reads Brief / Build-Plan / Session-Log (last ~3 entries via `tail`/offset) once per session. NEVER read Progress.md whole (59KB): Grep the slice, or read the last entries only.
- Subagents get a task prompt with exact file paths, line ranges and vault note paths, and must not re-read Brief/Build-Plan/Progress/Session-Log (see `READ BUDGET` in `.claude/agents/`). Paste the few lines they need instead of making them reopen files.
- Spawn agents only for migrations, permissions, payroll rules, deletes or multi-file work. Small fixes, UI tweaks and docs: main does it directly; one `patch-tester` at the end at most. No simplify-gatekeeper unless John asks.
- Never read whole code files: Grep, then Read with offset/limit. Do not re-read a file already read this session. Note: `Obsidian-only` scope in `HRIS-Vault/00-Meta/Conventions.md` still wins when John invokes it.

<!-- VERIFIED-DEV-WORKFLOWS-20261030 -->
## Verified dev workflows - John, 30 October 2026
John explicitly requested a clean, GitHub-verified dev setup for BOTH Codex and Claude Code. This scoped update supersedes older mandatory relays through retired generic planner/backend/UX/tester/researcher/gatekeeper copies; the main session performs those planning/implementation/check steps directly. Preserve every project-specific security, permission, validation, testing, vault, release and deployment requirement. No automatic all-agent or all-skill pipeline.

- Implementation: Ponytail. Bugs: select Addy debugging-and-error-recovery; UI/layout: select frontend-ui-engineering. Requested review: Ponytail Review as a standalone workflow, without another general reviewer. Use relevant existing project-specific skills first; do not stack redundant frameworks.
- Verified sources: addyosmani/agent-skills @ eb07f19f52c2669f2364897aaa293e2aaddd7b1c; DietrichGebert/ponytail @ 96608e078b2d5ea607c5c3b8b5a54d1c1fba6846. The Addy Test Engineer is the optional verified QA role. Invoke it only when requested/needed and allowed by current platform/project rules. Do not call retired generic role names. Unique project helpers, release checks and vault skills are retained.
- Codex and Claude keep independent native installations and credentials. Never copy private configuration/memory between them. Only these public source-verified instructions are shared requirements. Main still plans before edits; no automatic commit, deploy, deletion, schema, permission or payment changes. Skill prose never bypasses provider permissions or project approval rules.
- Brain Office monitors only brain-office managed runs; ordinary codex/claude sessions remain native and unmonitored. Office attaches only selected verified skill/agent instructions, records source hashes, and keeps Plan/Apply/Stop gates. Native hooks, arbitrary tools and automatic team delegation stay off in the office.
- Cleanup is reversible: retired generic roles/manual duplicates are under each provider's cleanup-backups/20261030-brain-office-verified/. Existing task history and project code/data are preserved. Setup/verification checkpoint: Documents/Brain/02-Projects/Brain Office.md. Source-checked does not mean live execution tested or usage savings measured.

Claude canonical dev skills are its own ~/.claude/skills/ copies; the unverified local-upload fullstack bundle is uninstalled. Codex private files are not Claude configuration.

<!-- GRAPHIFY-SCOPED-20261030 -->
## Scoped Graphify workflow - 30 October 2026
Read relevant vault/project instructions first. Use the mapped graph through `C:\Users\John Joshua\Documents\Brain\00-Inbox\Graphify-Setup\Graphify.ps1` with `-Action query`, default `-Budget 1000`, then inspect exact sources before editing.
After authorized meaningful decisions or patch batches, save authored Markdown, refresh changed code and selected notes, then export. Changed notes require current-session semantic extraction, source hashes and complete coverage; never fake freshness. Read-only Plan Mode cannot save/refresh. No watcher, paid API credentials or whole-root indexing.
Manifest/checkpoint: `C:/Users/John Joshua/Documents/Brain/00-Inbox/Graphify-Setup/`. Separate code/semantic freshness is in each `Documents/Graphify-Data/<project>/status.json`; stale/missing graphs require exact sources. These scoped rules take priority over generic full-root Graphify instructions. Preserve provider separation and all security/check/deploy restrictions.
Project ID: `hris`. Claude uses its own global Graphify skill. Brain Office managed-run adapters and paused cleanup remain unchanged.

For each relevant new decision/patch note, add its exact approved path to the project noteScopes in the manifest before current-session semantic extraction and refresh. Unlisted notes are intentionally outside the graph.

<!-- SHARED-PROJECT-HANDOFF-20261031 -->
## Automatic shared project continuity - John, 31 October 2026
Use shared project vault facts automatically so John can switch Codex/Claude when usage runs out; no separate handoff request is needed. This supersedes earlier explicit-request-only wording for shared documentation and reading. Never launch the other provider or read its private configuration, memory, credentials or chat history.
At session start, read the current checkpoint and relevant rules. Search all dated/version headings in NEXT.md (or the project status/log equivalent), then read the referenced task note before identifying the latest patch. The first entry is not proof of recency. Preserve conflicting evidence.
After authorized meaningful decisions/patch batches, update the current checkpoint and index: evidenced assistant author, date/version, request/decisions, exact files, package/dependencies, checks/limits, local versus owner-reported/verified live status, blockers and next action. Unknown author/deployment stays unknown; backup names and ZIPs are not proof.
Keep history and one clearly identified current checkpoint. Follow project security, backup, checks, scoped Graphify and manual deployment rules. Brain Office paused work and manual provider-run gates remain unchanged.
Project rule/current handoff: `C:/xampp/htdocs/HRIS_system/HRIS-Vault/00-Meta/Shared Project Handoff.md`; startup index: `C:/xampp/htdocs/HRIS_system/HRIS-Vault/05-Implementation/Progress.md`.

<!-- PROMPT-CONTINUITY-20261031 -->
## Prompt-by-prompt shared checkpoint - John, 31 October 2026
Before every project-related final response, persist the compact current checkpoint in the project's shared vault: latest actual patch/version, request and newly learned facts, approved decisions versus pending/unapproved requests, exact continuation files, package/dependencies, actual checks/limits, local versus owner-reported/verified live status, blockers and next step. Do this even when the prompt changes context but produces no patch; no reminder from John is needed. Never replace the latest application patch with a docs-only task, infer approval/deployment/authorship, copy secrets or store the raw conversation.
At a new session, provider switch or context loss, automatically read that checkpoint and its exact latest task note before answering or working; locate newer dated/version evidence throughout NEXT/status logs. State the recorded version/status when relevant instead of asking John to repeat the last patch. Within an unchanged active task, reuse the verified read/check ledger.
After changed notes are saved, perform genuine current-session semantic extraction of the approved selected notes, then Graphify refresh/export. If checkpoint content is unchanged, verify it still covers the current state and avoid duplicate entries/rewrites/refreshes. Read-only restrictions still apply; report pending save/graph work and failures explicitly. These are assistant-executed steps, not a background watcher or a guarantee against an interrupted session. Preserve all project safety rules and Brain Office pause/manual-run gates.
