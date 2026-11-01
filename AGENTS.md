# HRIS: rules for Codex (read first, keep short)

## Codex agents and skills (current low-usage workflow)
- Follow `HRIS-Vault/00-Meta/Codex-Agent-Workflow.md`. Main handles small tasks; no automatic agent or skill pipeline.
- Read only the relevant vault note or section and reuse session context. A request to investigate, plan or fix authorizes the relevant source and configuration reads; an edit request authorizes the scoped edits. Do not ask again for routine access. Respect explicit Obsidian-only or plan-only requests.
- Delegate only when needed and allowed; pass the exact task, constraints and relevant paths. Agents do not automatically reread project context or Claude configuration.
- For authorized changes, plan first, preserve security and required checks, and sync affected vault checkpoints.
- Syntax/static-only checks; no browser, app, DB tests or writes. No automatic commit, push, deployment, deletion or permission/data/schema/payment changes.

Language: Taglish, short. Plan before patching. Do not rush.

## Token saving
- Start with the relevant vault note or section and reuse session context; do not automatically load Brief, Session-Log or Progress.
- Do not read the whole repo; open only the files you need and use `rg` to search. Do not read .git, vendor, uploads, storage, backups or any generated folder.
- Max 3 test scenarios, run once, unless asked otherwise. Final answer: what changed and the file list only.

## How to edit
- Files are CRLF: no whole-file rewrite; targeted edits only. Afterwards run `git diff --stat` to confirm a small diff and no line-ending churn.
- Fix sweep (mandatory): when John points at one issue, fix it, then grep the same pattern across the project (UI: spacing/size/states/overflow/handlers/labels; backend: validation, CSRF, permission/role check, escaping, prepared queries, UI vs POST/AJAX mismatch). Fix small, clearly same-class items; only LIST (do not touch) schema, auth/permission rules, payments, deletes and anything large.
- Project rules: vault sync is mandatory (Progress.md, module note, Data-Model.md, CHANGELOG.md, Session-Log.md); migrations only per the standing workflow (up, re-run, down, up; guarded down); never apply to the localhost DB `pmbsi_hris` unless every standing-workflow check passed; no push, deploy or data/role deletes.
- Every SQL file: clear note to select the database first, additive only, with a verification step in the README.
- No browser/UI testing. After an edit run `php -l` (PHP) / `node --check` (JS) only.
- Do not deploy, push or commit. Do not change permission/data/schema/payments unless the plan says so; stop and report instead.
- Final report: Fixed (file:line) / Found and also fixed / Found but left alone (why) / Not verified.

<!-- VERIFIED-DEV-WORKFLOWS-20261030 -->
## Verified dev workflows - John, 30 October 2026
John explicitly requested a clean, GitHub-verified dev setup for BOTH Codex and Claude Code. This scoped update supersedes older mandatory relays through retired generic planner/backend/UX/tester/researcher/gatekeeper copies; the main session performs those planning/implementation/check steps directly. Preserve every project-specific security, permission, validation, testing, vault, release and deployment requirement. No automatic all-agent or all-skill pipeline.

- Implementation: Ponytail. Bugs: select Addy debugging-and-error-recovery; UI/layout: select frontend-ui-engineering. Requested review: Ponytail Review as a standalone workflow, without another general reviewer. Use relevant existing project-specific skills first; do not stack redundant frameworks.
- Verified sources: addyosmani/agent-skills @ eb07f19f52c2669f2364897aaa293e2aaddd7b1c; DietrichGebert/ponytail @ 96608e078b2d5ea607c5c3b8b5a54d1c1fba6846. The Addy Test Engineer is the optional verified QA role. Invoke it only when requested/needed and allowed by current platform/project rules. Do not call retired generic role names. Unique project helpers, release checks and vault skills are retained.
- Codex and Claude keep independent native installations and credentials. Never copy private configuration/memory between them. Only these public source-verified instructions are shared requirements. Main still plans before edits; no automatic commit, deploy, deletion, schema, permission or payment changes. Skill prose never bypasses provider permissions or project approval rules.
- Brain Office monitors only brain-office managed runs; ordinary codex/claude sessions remain native and unmonitored. Office attaches only selected verified skill/agent instructions, records source hashes, and keeps Plan/Apply/Stop gates. Native hooks, arbitrary tools and automatic team delegation stay off in the office.
- Cleanup is reversible: retired generic roles/manual duplicates are under each provider's cleanup-backups/20261030-brain-office-verified/. Existing task history and project code/data are preserved. Setup/verification checkpoint: Documents/Brain/02-Projects/Brain Office.md. Source-checked does not mean live execution tested or usage savings measured.

Codex canonical Ponytail source is the installed ponytail@ponytail plugin; duplicate ~/.codex/skills/ponytail* copies are retired. Addy UI/debug remain under ~/.codex/skills/.

<!-- GRAPHIFY-SCOPED-20261030 -->
## Scoped Graphify workflow - 30 October 2026
Read relevant vault/project instructions first. Use the mapped graph through `C:\Users\John Joshua\Documents\Brain\00-Inbox\Graphify-Setup\Graphify.ps1` with `-Action query`, default `-Budget 1000`, then inspect exact sources before editing.
After authorized meaningful decisions or patch batches, save authored Markdown, refresh changed code and selected notes, then export. Changed notes require current-session semantic extraction, source hashes and complete coverage; never fake freshness. Read-only Plan Mode cannot save/refresh. No watcher, paid API credentials or whole-root indexing.
Manifest/checkpoint: `C:/Users/John Joshua/Documents/Brain/00-Inbox/Graphify-Setup/`. Separate code/semantic freshness is in each `Documents/Graphify-Data/<project>/status.json`; stale/missing graphs require exact sources. These scoped rules take priority over generic full-root Graphify instructions. Preserve provider separation and all security/check/deploy restrictions.
Project ID: `hris`. Codex uses its own global Graphify skill. Brain Office managed-run adapters and paused cleanup remain unchanged.

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
