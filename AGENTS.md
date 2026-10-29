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
