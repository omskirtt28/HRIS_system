---
title: Conventions
type: reference
status: final
tags: [meta, conventions]
updated: 2026-10-17
---

# Conventions

Rules for this vault and for the specs it produces. Consistency here is what makes the vault machine-readable enough to hand to Claude Code.

## Note naming

- Filenames are `Title-Case-With-Hyphens.md`. No spaces, no folders in links.
- One concept per note. A module gets exactly one note.
- Links use the bare basename: `[[Payroll]]`, not `[[02-Modules/Payroll.md]]`.
- Folder prefixes (`00-`…`06-`) order the sidebar only; they never appear in links.

## Frontmatter

Every note starts with:

```yaml
---
title: Human readable title
type: moc | module | design | adr | reference | plan
status: idea | draft | review | final
tags: [list, of, tags]
updated: 2026-10-17
---
```

`status` means: **idea** = sketch, do not build from it · **draft** = usable, expect change · **review** = waiting on John's decision · **final** = stable, change via ADR.

## ID conventions

| Prefix | Meaning | Lives in | Example |
|---|---|---|---|
| `FR-###` | Functional requirement | module notes | FR-305 Payroll run must be reversible |
| `BR-###` | Business rule / computation | [[Business-Rules]] | BR-012 Night differential window |
| `US-###` | User story | [[User-Stories]] | US-042 Employee files a leave |
| `ADR-###` | Architecture decision | [[Decision-Log]] | ADR-001 Tech stack |
| `SCR-###` | Screen | [[Screens]] | SCR-210 DTR correction queue |
| `ENT-` | Entity | [[Data-Model]] | ENT-employees |
| `SLICE-##` | Build slice | [[Build-Plan]] | SLICE-03 DTR capture |

ID ranges are allocated per module so numbers stay readable:

| Range | Module |
|---|---|
| 100–199 | [[Org-Structure]], [[Auth-and-RBAC]] |
| 200–299 | [[Employee-Records]] |
| 300–399 | [[Attendance-and-DTR]] |
| 400–499 | [[Leave-Management]] |
| 500–599 | [[Payroll]] |
| 600–699 | [[Reports-and-Analytics]] |
| 700–799 | [[Self-Service-Portal]], [[Notifications]] |
| 800–899 | [[Recruitment-and-Onboarding]], [[Offboarding-and-Clearance]], [[Performance-Management]] |

IDs are **permanent**. Never renumber. If a requirement dies, mark it `WITHDRAWN` and leave the row.

## Module note sections

Every module note uses these sections in this order, so they are diffable against each other:

1. Purpose
2. Scope — In scope / Out of scope
3. Key Entities
4. Core Flows (numbered steps)
5. Business Rules (BR refs)
6. Functional Requirements (FR table: ID | requirement | priority)
7. Edge Cases
8. Open Questions
9. Related

## Priority (MoSCoW)

| Value | Meaning |
|---|---|
| Must | MVP cannot ship without it |
| Should | Painful to omit, but shippable |
| Could | Nice to have, cut under pressure |
| Won't | Explicitly out of this release |

## Tags in use

`#hris` `#module` `#payroll` `#ph-statutory` `#compliance` `#needs-verification` `#decision` `#mvp`

Apply `#needs-verification` to any note containing an unconfirmed statutory figure.

## Statutory figures

> [!warning] NEEDS VERIFICATION
> Write the *mechanism*, never an invented number. If a rate or bracket is required to express a rule, wrap it in a `> [!warning] NEEDS VERIFICATION` callout naming the issuing agency. All such figures must become rows in a config table, not constants in code — see [[ADR-003-Payroll-Engine-Approach]].

## Standing workflow: test then apply

After Stage B, the migration is applied to the localhost database (`pmbsi_hris`) without asking again when: (1) a fresh mysqldump was taken and its restore verified; (2) isolated-DB tests passed, and any failing test is shown with proof it is unrelated; (3) the migration ran up, re-run, down, up cleanly and its down script is guarded. After applying: re-check that no existing business rows changed, update the vault, commit locally.

**Never without John's explicit word:** `git push`, deploy to hosting, delete data or roles, history purge, or apply anything that fails a check. If a check fails: stop and report.

## Database / code naming (carried from the existing codebase)

- Tables: `snake_case`, plural. PK `id`. FK `<entity>_id`. Timestamps `created_at`, `updated_at`, actors `created_by`, `updated_by`.
- Stable domain codes are UPPERCASE (`APPROVED`, `TERMINAL_FAIL`), never display strings.
- Money: `DECIMAL(12,4)` internally, rounded to 2 only at presentation and payment. Never `FLOAT`.
- Dates in PH local time (`Asia/Manila`); store `DATETIME` with an explicit application timezone, never rely on server default.

## Engineering rules (moved from the retired root RULES.md, 2026-10-25)

- **Architecture:** build by domain module, not by page; shared concerns live in core; business rules live on the server, never only in JavaScript; keep route/handler logic thin and put behaviour in services; use transactions for multi-record actions; every module has explicit permissions and scope rules. No files named `helpers2`, `misc`, `new`, `final`, `temp`.
- **Database:** `database/schema.sql` is the clean-install bootstrap; every later change is a numbered migration, in the same change as the code; never edit a deployed migration; real relationships get foreign keys; index foreign keys and frequent filters; JSON only for flexible snapshots/configuration, not for known queried fields; never hard-delete audit logs or stage history; timezone `Asia/Manila`, converted only at the application boundary if storage ever moves to UTC. Stable references use a unique `reference_no` or domain-specific number.
- **API:** resource-oriented, validate body/params/query, consistent error shape, no stack traces or SQL errors to clients, paginate collections, idempotency where a repeat would do harm, version checks where edits conflict.
- **Notifications:** the business service emits events, the notification service picks channels; no direct email from a business service; failure never rolls back a committed action; deduplicate with an idempotent key; deep links re-check authorisation.
- **Frontend:** see [[Design-System]]. No business-critical hard-coded sample data; no fake controls; every write form has CSRF and prepared SQL; local URLs come from helpers/config, never hard-coded `localhost`.
- **Testing minimum per module:** permissions, scope isolation, validation, state transitions, transactions/rollback, duplicate/idempotency, audit rows, notification emission, report scoping, critical end-to-end flow. New permissions need seed/config updates and tests.
- **Git/delivery:** no secrets, `.env` or real exports in Git; production must carry no debug accounts, sample data or localhost config; code passes tests before deployment.
- **Definition of done:** UI works, validation, permission, scope, migration if needed, audit where required, tests pass, errors handled, empty/loading states, vault synced (next section).

## Vault is the only source of truth (mandatory)

The Obsidian vault is the ONLY source of truth. Vault sync is MANDATORY before the final report of every patch: [[Progress]], the matching `02-Modules` note, [[Data-Model]] for every migration, [[Business-Rules]], [[Screens]]/[[API-Surface]] for new screens or routes, [[Decision-Log]], [[Open-Questions]] and the repo `CHANGELOG.md`. The final report MUST list the vault files changed; a patch with no vault files listed is not finished. Never add content to the repo-root SCHEMA/ARCHITECTURE/RULES/DESIGN docs (they are frozen pointers to this vault). The enforcing skill is `.claude/skills/hris-patch/SKILL.md`; the rule is also in CLAUDE.md.

Related: [[Start-Here]] · [[Business-Rules]] · [[Data-Model]] · [[Decision-Log]]

## Fix sweep (2026-10-26)
When John points at one UI/UX or backend issue: fix it, then sweep the same pattern across the project in the same patch (same buttons/components/layouts, spacing/size/colour vs [[Design-System]], overflow/responsive, missing states, handlerless buttons, inconsistent labels; backend: same bug in other handlers, missing validation/permission/scope/CSRF, error handling, UI/POST mismatches). Small and clearly same-class items are fixed; migrations, roles/permissions, payroll rules and data deletion are listed, not touched. Report: Fixed / Found and also fixed / Found but left alone / Not verified, plus vault files changed. No redesign. Set in CLAUDE.md "Fix sweep" and in `.claude/agents/`.

## Agent pipeline (2026-10-26)
Agent pipeline (26 Okt 2026): bawat prompt na build/fix/patch ay dumadaan sa planner -> backend-engineer + ux-designer (10 taon) -> patch-tester (test at kritika), hanggang 2 fix rounds. Ang main session ang nag-o-orchestrate; hindi nakakatawag ng subagent ang subagent. Laktaw ang pipeline sa tanong, docs-only, at one-line fix. Nasa CLAUDE.md ng project ang buong rule. Sa HRIS: kapag "plan" ang sinabi ni John, isulat sa Plans/ + Session-Log at huminto bago mag-execute. Ang mga agent ay inayos para tumuro sa HRIS-Vault (dating mga kopya ng ORDESK).
