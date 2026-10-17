# CLAUDE.md

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
