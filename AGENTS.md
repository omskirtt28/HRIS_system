# HRIS: rules for Codex (read first, keep short)

## Codex agents and skills (John, 2026-10-27)
- Changes go through `hris-planner` first, including docs/config and small fixes. Pure questions stay in main; plan-only requests save to Plans/ + Session-Log and stop before implementation.
- Main coordinates and owns vault sync. Delegate needed work to `hris-backend-engineer` / `hris-ux-designer` (10-year senior roles), with exact file ownership and no reverting others' edits. Parallel only on disjoint files. `hris-researcher` is read-only. Only main may spawn agents.
- After edits: `hris-patch-tester` then read-only `hris-simplify-gatekeeper`; max 2 fix rounds. Extra simplifications are proposals only. Tester PASS does not authorize deployment or prove runtime acceptance.
- Definitions: `.codex/agents/hris-*.toml`, sharing `.claude/agents/` role bodies. If unavailable this session, give a general subagent the exact HRIS definition and disclose it; avoid global ORDESK roles.
- Skills: `.agents/skills/hris-patch/SKILL.md` for changes; add `hris-debug`, `hris-ui`, `hris-review` as relevant. Do not assume Claude plugins exist in Codex.
- Always sync Obsidian before the final patch report: Progress, Session-Log, root CHANGELOG and affected module/rule notes; Data-Model for migrations and other specs when affected. Decisions go in Conventions + Decision-Log, linked from Start-Here. Canonical workflow: `HRIS-Vault/00-Meta/Codex-Agent-Workflow.md`.
- Current Codex syntax/static-only, no-browser, no-runtime/DB-test/write, no-commit/push/deploy rules override stale actions in shared Claude references. This setup does not change Claude's selected session mode.


Language: Taglish, short. Plan before patching. Do not rush.

## Token saving
- Start from the vault: `HRIS-Vault/05-Implementation/Session-Log.md` (latest entries) and `Progress.md`. Read HRIS-Vault/05-Implementation/Claude-Code-Brief.md first.
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
