---
name: hris-patch
description: Use for ANY change to the PMBSI HRIS repo (C:\xampp\htdocs\HRIS_system) - a patch, bug fix, feature, migration, permission or role change, screen, report or docs/rules edit. Enforces vault-first work, read-only checks, backup and restore verification, isolated test database, migration up/re-run/down/up, apply to localhost pmbsi_hris only after tests pass, mandatory Obsidian vault sync, and short final reports. Invoke it yourself at the start of the task without being asked.
---

# hris-patch

The standing workflow for every HRIS change. CLAUDE.md in the repo root is the short version; this is the checklist. Pair it with `run-hris-system` for the scratch database and browser checks (`php scripts/ticket_stageb_database.php prepare|cycle|apply`).

## Rule 1: the vault is the only source of truth

The Obsidian vault is the ONLY source of truth. Vault sync is MANDATORY before the final report of every patch: Progress.md, the matching 02-Modules note, Data-Model.md for every migration, Business-Rules.md, Screens.md/API-Surface.md for new screens or routes, Decision-Log.md, Open-Questions.md and CHANGELOG.md. The final report MUST list the vault files changed. A patch with no vault files listed is not finished. Never add content to the repo-root SCHEMA/ARCHITECTURE/RULES/DESIGN docs.

## Steps

1. **Vault first.** Read `HRIS-Vault/05-Implementation/Progress.md` (last entries) and repo `CHANGELOG.md`, then only the notes the task touches (start from `02-Modules/Employee-Tickets.md` for anything ticket-related, then `03-Design/Data-Model.md`, `Business-Rules.md`). Anything marked NEEDS VERIFICATION is unconfirmed: do not hard-code it as fact.
2. **Read-only checks first.** Reproduce and find the cause with SELECT/SHOW and code reading only. No writes, no migrations, no data fixes yet. State the verified cause in one or two lines.
3. **Backup and restore check.** Take a fresh mysqldump of `pmbsi_hris` and restore it into a scratch database; fingerprint-compare the tables. No fresh verified restore, no apply.
4. **Isolated test DB.** All tests and browser runs use the throwaway copy (`hris_slice15_test_*`), never live. New or changed behaviour needs a test that fails without the change. A failing test is shown with proof it is unrelated, or it blocks the apply.
5. **Migration up, re-run, down, up.** On the scratch copy: up, up again (idempotent), down (guarded: refuses when used data exists), up. Migrations are additive, numbered, never edit a deployed one, ledger tables record what the migration owns. Use `backend-design:migration-safety` and `backend-design:data-modeling-discipline` on the file before it runs.
6. **Apply to localhost only after tests pass.** Only to localhost `pmbsi_hris`, only when steps 3-5 all passed. Then re-check that no existing business rows changed (before/after counts), and commit locally.
7. **Vault sync (Rule 1)** before the report, including a Progress.md entry with date, scope, checks and what was not run.
8. **Short final report.** No pasted logs. State: cause, what changed, checks run and result, what was not run, vault files changed, skills used, commit hash.

## Never without the user's explicit word

`git push`, deploy to hosting, deleting data or roles, history purge, or applying anything that fails a check. If a check fails: stop and report.

## Also

- UI, privacy and permission rules: `HRIS-Vault/03-Design/Design-System.md`, BR-720..767 in Business-Rules.md.
- No PII or credentials in the vault, commits or reports (names, employee numbers, passwords).
- Name every skill you used in the final report.
