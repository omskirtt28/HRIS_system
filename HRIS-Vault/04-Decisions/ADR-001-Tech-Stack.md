---
title: ADR-001 Tech Stack
type: adr
status: proposed
tags: [adr, decision, architecture]
updated: 2026-10-17
---

# ADR-001: Tech stack

## Status

proposed. Decider: John Joshua. Supersedes: none. Answers Q2 in [[Open-Questions]].

## Context

- A working PHP 8.2 / MySQL 8 modular monolith exists (recruitment, employee directory, attendance import, partial payroll), run locally on XAMPP. A separate Node/Express/PostgreSQL "HR Service Desk" reference codebase also exists with auth, RBAC, scope control, audit and notification patterns worth borrowing.
- One developer plus Claude Code. Roughly 300 employees, a handful of concurrent HR users, two payroll runs a month.
- Payroll correctness is the hard requirement; it needs cheap, numerous automated tests, migrations, and a job queue.
- Hosting is undecided (Q13). Shared-hosting-grade PHP and a small VPS are both plausible.

## Options

| Option | For | Against |
|---|---|---|
| **A. Keep vanilla PHP 8.2 + MySQL** | Zero migration, John knows it, XAMPP already works | Hand-rolled routing, migrations, queue, validation, test harness; each is a bug surface in a compliance system |
| **B. PHP 8.3 + Laravel 11 + MySQL 8** | Keeps language, DB and domain logic; migrations, queues, policies, validation, Pest/PHPUnit built in; big PH developer pool; Claude Code is strong with it | Refactor cost to move existing modules; framework conventions to learn |
| **C. TypeScript (Node + NestJS or Next.js) + PostgreSQL** | Strong typing, shared types front to back, can reuse patterns from the Service Desk codebase | Discards working PHP; new stack on payroll; XAMPP assumption breaks; two codebases to reconcile |
| **D. Python + Django + PostgreSQL** | Admin UI free, good ORM and migrations, `Decimal` ergonomics | Same rewrite cost as C; weaker fit for John's current code |

## Decision drivers

1. Payroll calculator testability (pure functions, fast tests, decimal-safe money).
2. Preserve working code and domain knowledge already encoded.
3. Time to first real cut-off run.
4. Operability by one person; boring deployment.
5. Availability of PH developers if John hires later.

## Recommendation

**B: Laravel on the existing MySQL schema, adopted incrementally.** Keep the domain classes (calculators, derivers) as framework-free PHP in `app/Domain` so they stay pure; let Laravel own HTTP, queue, migrations, auth scaffolding and tests. Port existing modules slice by slice (see [[Build-Plan]]) rather than big-bang. Money uses `DECIMAL(12,4)` columns and a decimal library (e.g. `brick/math`), never floats.

If time pressure is severe, A remains acceptable for slices 0-2, but introduce a migration tool and PHPUnit before the payroll slice either way. C and D are not recommended: rewriting a compliance-critical system in a new language while solo is the highest-risk path.

> [!note] Not blocking
> SLICE-01 in [[Build-Plan]] is mostly stack-neutral. Decide before the payroll calculator (SLICE-07).

## Consequences

- Positive: migrations and a queue from day one; deny-by-default policies map cleanly onto [[Auth-and-RBAC]]; scheduled jobs for accrual and reminders ([[Notifications]]).
- Negative: existing PHP modules need adapting to Laravel conventions; XAMPP local dev changes to `php artisan serve` or Docker (no package installs were done for this vault; John installs locally).
- Harder later: leaving Laravel. Mitigated by keeping `app/Domain` framework-free.

## Revisit when

Hosting forces a non-PHP runtime, or the Service Desk codebase is chosen as the single base for all HR tools.

## Related

[[Architecture]] · [[Decision-Log]] · [[Open-Questions]] · [[Claude-Code-Brief]] · [[Build-Plan]] · [[ADR-003-Payroll-Engine-Approach]]
