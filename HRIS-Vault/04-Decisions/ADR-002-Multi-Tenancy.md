---
title: ADR-002 Multi-Tenancy
type: adr
status: proposed
tags: [adr, decision, data-model]
updated: 2026-10-17
---

# ADR-002: Single company vs multi-company

## Status

proposed. Decider: John Joshua. Answers Q1 in [[Open-Questions]]. Must be decided **before schema is written** (SLICE-01).

## Context

The existing codebase already has `clients` and `branches`, hinting at an agency/manpower-style business where employees are deployed to client sites. That is a different concept from multi-tenancy:

- **Deployment dimension** (employee works at client X): a placement attribute, needed regardless.
- **Legal-entity dimension** (separate employer with its own SSS/TIN/payroll): affects statutory filings and payroll runs.
- **Software tenancy** (several unrelated customers on one install): affects security isolation.

The modules currently carry `company_id` on org masters ([[Org-Structure]]) but not on every table.

## Options

| Option | For | Against |
|---|---|---|
| **A. Single company, no tenant key** | Simplest; fastest to MVP | Adding a second legal entity later means touching every table and every query |
| **B. Single install, `company_id` on all tenant-owned tables, one company active at first** | Cheap now, expensive-to-retrofit later is avoided; supports several legal entities under one owner; scope filter already exists (BR-113) | Slight query and test overhead; discipline needed |
| **C. Database-per-tenant** | Hard isolation | Operational burden for a solo developer; migrations x N; reporting across entities is hard |

## Decision drivers

1. Cost of reversal (high for A).
2. Solo-developer operability.
3. Statutory correctness per employer (each legal entity files its own).
4. Not selling to unrelated customers (assumed; confirm).

## Recommendation

**B.** Add `company_id` (the legal employer) to every table that owns people, pay or time data, enforce it through the same scope filter as other scopes (a company is a scope dimension in `user_scopes`), seed one company, and keep `clients`/sites as a **placement** dimension, not a tenant. Do not build tenant-switching UI until needed. If John will sell to unrelated customers, revisit: C may then be warranted.

## Consequences

- Positive: second entity is a data change, not a migration; statutory reports naturally per employer ([[Reports-and-Analytics]]); fits [[Auth-and-RBAC]].
- Negative: composite unique keys (e.g. `employee_no` unique per company), more test fixtures.
- Rule: a repository method without a company filter is a defect, extending BR-113.

## Revisit when

A prospective customer is a separate business, or data-residency needs differ per entity.

## Related

[[Data-Model]] · [[Org-Structure]] · [[Auth-and-RBAC]] · [[Decision-Log]] · [[Security-and-Compliance]] · [[Roadmap]]
