---
title: Decision Log
type: moc
status: draft
tags: [meta, decision]
updated: 2026-10-17
---

# Decision Log

Index of Architecture Decision Records. One ADR per decision that is expensive to reverse. Use [[ADR-000-Template]] for new ones.

## Index

| ID | Decision | Status | Date | Supersedes |
|---|---|---|---|---|
| ADR-001 | [[ADR-001-Tech-Stack]] — language, framework, database | proposed | 2026-10-17 | — |
| ADR-002 | [[ADR-002-Multi-Tenancy]] — single company vs multi-company | proposed | 2026-10-17 | — |
| ADR-003 | [[ADR-003-Payroll-Engine-Approach]] — rules as config vs hardcoded | proposed | 2026-10-17 | — |

## Status meanings

| Status | Meaning |
|---|---|
| proposed | Written, awaiting John's decision. Do not build on it. |
| accepted | Decided. Code must conform. |
| rejected | Considered and declined; kept for the reasoning. |
| superseded | Replaced by a later ADR, named in the row above. |

## Decisions still needing an ADR

- [ ] ADR-004 — Attendance ingestion: live biometric device integration vs scheduled file import. See [[Attendance-and-DTR]].
- [ ] ADR-005 — Approval workflow engine: generic configurable workflow vs per-module hardcoded approval chains. See [[Leave-Management]].
- [ ] ADR-006 — Document storage: filesystem vs database blobs vs object storage, with retention. See [[Security-and-Compliance]].
- [ ] ADR-007 — Payslip delivery: portal only, email PDF, or both; and whether payslips are encrypted at rest. See [[Self-Service-Portal]].
- [ ] ADR-008 — Audit trail depth: which tables get row-level change history, and for how long. See [[Auth-and-RBAC]].
- [ ] ADR-009 — Payroll reversal model: void-and-reissue vs adjusting entries in the next period. See [[Payroll]].

> [!note] When to write an ADR
> Write one if reversing the choice later would mean a data migration, a rewrite of more than one module, or a conversation with the client. Otherwise just decide it in the module note.

Related: [[Start-Here]] · [[Conventions]] · [[Architecture]] · [[Open-Questions]]
