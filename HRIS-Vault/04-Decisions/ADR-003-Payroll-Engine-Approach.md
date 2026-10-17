---
title: ADR-003 Payroll Engine Approach
type: adr
status: proposed
tags: [adr, decision, payroll, ph-statutory, needs-verification]
updated: 2026-10-17
---

# ADR-003: Payroll engine approach

## Status

proposed. Decider: John Joshua. Answers Q3 in [[Open-Questions]].

## Context

Philippine payroll depends on frequently revised statutory tables (SSS, PhilHealth, Pag-IBIG, BIR withholding) and labor-standards multipliers. Payroll must be **reproducible**: a released run recomputes identically years later ([[Payroll]]). Hard-coded constants make past runs unreproducible after a change; live reads of mutable data do the same.

> [!warning] NEEDS VERIFICATION
> This ADR chooses a mechanism. It asserts no rate or bracket. Every table value must be sourced from the agency circular and recorded in `rate_tables.source_reference`.

## Options

| Option | For | Against |
|---|---|---|
| **A. Hard-coded constants and formulas in code** | Fastest to write | Every circular means a deploy; old runs break or silently change; fails reproducibility |
| **B. Effectivity-dated rate tables as data + pure calculator code** | Rates change without deploy; each run stores `rate_table_version_set`; calculator is a pure function of (snapshot, tables) and trivially unit-testable | Needs a table admin screen and verification workflow; formulas (e.g. withholding mechanism) still live in code |
| **C. Fully configurable rule/DSL engine (formulas editable in UI)** | Maximum flexibility | Large build, hard to test, high risk of invalid formulas reaching a payslip; overkill for one company |

## Decision drivers

1. Reproducibility.
2. Testability with worked examples.
3. Safe handling of rate changes by a non-developer.
4. Build cost for a solo developer.

## Recommendation

**B.** Design:

1. `rate_tables` + `rate_table_rows` with `effective_from/to`, `source_reference`, `verified_by`, `verified_at`. A version is unusable until verified by someone other than its creator (BR-114/BR-115/BR-514).
2. `PayrollCalculator` is a pure domain class: `compute(EmployeeSnapshot, DtrSummary, RateTableSet, RunContext): PayLines`. No database, no clock, no globals.
3. A run stores a snapshot of every input and the exact `rate_table_version_set`. Release freezes both.
4. `recompute-check` re-runs the calculator from the snapshot and diffs against stored lines; expected diff is empty. This is a standing regression test and an audit feature.
5. Formulas that are legal mechanisms (e.g. proration, divisor choice, taxable base) are code with named rule IDs from [[Business-Rules]]; only parameters are data.
6. Rounding policy is a single configurable function applied at defined points, documented per line type.
7. Multipliers (OT, rest day, holiday) and the night window are rows in a `pay_rules` table with the same effectivity and verification.

## Consequences

- Positive: reproducible, auditable, testable; a circular becomes a data entry task.
- Negative: more tables and an admin UI before first payroll; seed data must come from verified sources.
- Harder later: if a circular changes a *formula*, not just a parameter, code change is still needed; that is acceptable and versioned by BR ID.

## Revisit when

A second legal entity with different pay policies needs formula-level variation (see [[ADR-002-Multi-Tenancy]]).

## Related

[[Payroll]] · [[Business-Rules]] · [[PH-Labor-and-Payroll-Notes]] · [[Data-Model]] · [[Decision-Log]] · [[ADR-001-Tech-Stack]] · [[Build-Plan]]
