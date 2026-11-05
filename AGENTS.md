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

## Shared context and Graphify
Follow global Codex AGENTS.md for shared checkpoint, scoped Graphify and context-budget rules. Project ID: `hris`.
Project rule/current handoff: `C:/xampp/htdocs/HRIS_system/HRIS-Vault/00-Meta/Shared Project Handoff.md`; startup index: `C:/xampp/htdocs/HRIS_system/HRIS-Vault/05-Implementation/Progress.md`.

