---
title: ADR-000 Template
type: adr
status: final
tags: [adr, template, decision]
updated: 2026-10-17
---

# ADR-000: Template

Copy this note, rename to `ADR-###-Short-Title.md`, fill every section, add a row to [[Decision-Log]]. Keep it to one page. If it needs more, the decision is probably two decisions.

```markdown
---
title: ADR-### Short title
type: adr
status: proposed   # proposed | accepted | rejected | superseded
tags: [adr, decision]
updated: YYYY-MM-DD
---

# ADR-###: Short title

## Status
proposed. Decider: John Joshua. Supersedes: none.

## Context
What forces are at play? What is true today? What is the constraint (time, team of one, compliance)?

## Options
| Option | For | Against |
|---|---|---|
| A | | |
| B | | |

## Decision drivers
Ranked list. The first item wins ties.

## Recommendation
One option, with the reason in two or three sentences.

## Consequences
Positive, negative, and what becomes harder later.

## Revisit when
The concrete trigger that reopens this decision.

## Related
[[Open-Questions]] · [[Decision-Log]]
```

## Rules

1. Status stays `proposed` until John says otherwise in writing (here or in [[Decision-Log]]).
2. Claude Code must not build on a `proposed` ADR except where [[Build-Plan]] marks the work as stack-neutral.
3. Never edit an accepted ADR's decision; write a new ADR that supersedes it.

## Related

[[Conventions]] · [[Decision-Log]] · [[Start-Here]]
