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

## Database / code naming (carried from the existing codebase)

- Tables: `snake_case`, plural. PK `id`. FK `<entity>_id`. Timestamps `created_at`, `updated_at`, actors `created_by`, `updated_by`.
- Stable domain codes are UPPERCASE (`APPROVED`, `TERMINAL_FAIL`), never display strings.
- Money: `DECIMAL(12,4)` internally, rounded to 2 only at presentation and payment. Never `FLOAT`.
- Dates in PH local time (`Asia/Manila`); store `DATETIME` with an explicit application timezone, never rely on server default.

Related: [[Start-Here]] · [[Business-Rules]] · [[Data-Model]] · [[Decision-Log]]
