---
title: HRIS Vault README
type: moc
status: draft
tags: [hris, vault, readme]
updated: 2026-10-17
---

# HRIS Planning Vault

This is an [Obsidian](https://obsidian.md) vault for planning the **PMBSI HRIS** — a Philippine-context Human Resource Information System. It is the thinking layer that sits beside the code: specs, modules, data model, decisions and build plan. The code lives separately (the existing PHP/MySQL app under `HRIS_system`); this vault says *what* to build and *why*, so Claude Code can be handed a clear brief instead of vibes.

## How to open it

1. Install Obsidian.
2. `Open folder as vault` → select this `HRIS-Vault` folder.
3. The `.obsidian/` folder already enables the graph view, backlinks and outgoing links. Open **[[Start-Here]]** first.
4. Press `Ctrl/Cmd + G` for the graph — the module cluster should be densely linked.

## Map of content

| Area | Note | What it is for |
|---|---|---|
| Meta | [[Start-Here]] | Dashboard; links everything |
| Meta | [[Conventions]] | Naming, ID schemes, status values |
| Meta | [[Decision-Log]] | Index of ADRs |
| Meta | [[Glossary]] | HR + PH payroll vocabulary |
| Product | [[Vision-and-Scope]] | Problem, goals, non-goals, metrics |
| Product | [[Personas]] | Who uses it |
| Product | [[User-Stories]] | US-### backlog with MoSCoW |
| Product | [[Roadmap]] | MVP → v1 → later |
| Product | [[Open-Questions]] | What John still has to decide |
| Modules | [[Employee-Records]], [[Org-Structure]], [[Attendance-and-DTR]], [[Leave-Management]], [[Payroll]] | Core HRIS |
| Modules | [[Recruitment-and-Onboarding]], [[Performance-Management]], [[Offboarding-and-Clearance]] | Employee lifecycle |
| Modules | [[Reports-and-Analytics]], [[Self-Service-Portal]], [[Auth-and-RBAC]], [[Notifications]] | Cross-cutting |
| Design | [[Data-Model]], [[Architecture]], [[API-Surface]], [[Screens]], [[Security-and-Compliance]], [[Business-Rules]] | How it is built |
| Decisions | [[ADR-001-Tech-Stack]], [[ADR-002-Multi-Tenancy]], [[ADR-003-Payroll-Engine-Approach]] | Open architectural calls |
| Implementation | [[Build-Plan]], [[Claude-Code-Brief]], [[Progress]] | Turning specs into slices |
| Reference | [[PH-Labor-and-Payroll-Notes]] | Statutory background |

## The one rule about numbers

> [!warning] NEEDS VERIFICATION
> Every statutory rate, bracket, ceiling and percentage in this vault is written as a **mechanism**, not as authoritative fact. SSS, PhilHealth, Pag-IBIG and BIR figures change by circular and by year. Before any of it is coded, verify against the current issuance and record the source + effectivity date in [[PH-Labor-and-Payroll-Notes]]. Where a figure is needed but not confirmed, the note carries a `NEEDS VERIFICATION` callout. Do not let one of those slip into a migration.

## Para kay John (short note)

Josh — ito ang planning vault, hindi code. Dito muna natin ayusin ang module scope, data model at business rules bago tayo mag-code sa Claude Code. Rule of thumb:

- Bago mag-implement ng module, basahin muna ang module note at [[Business-Rules]].
- Kapag may desisyon na hindi pa malinaw (stack, multi-company, payroll engine), huwag i-hardcode — ilagay sa [[Open-Questions]] o gumawa ng ADR.
- Ang mga rate ng SSS/PhilHealth/Pag-IBIG/BIR: lahat `NEEDS VERIFICATION` muna. Config table, hindi constant sa code.
- Kapag nagbago ang plano, i-update ang note at ang `updated:` date. Ang vault ang single source of truth ng *intent*.

Related: [[Start-Here]] · [[Conventions]] · [[Claude-Code-Brief]]
