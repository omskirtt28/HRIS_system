---
name: hris-debug
description: Trace and fix an HRIS bug from source evidence, including PHP handlers, services, and frontend event flows; use for defects rather than feature design.
---

# HRIS debugging

Paths are relative to the repository root. Follow AGENTS.md and HRIS-Vault/00-Meta/Codex-Agent-Workflow.md; use hris-patch for the full patch lifecycle.

- Collect the reported trigger, expected/actual result, and available error text. Read only the owning entry point, handler/service, and applicable module note.
- Trace inputs, validation, CSRF/role/scope gates, state changes, and rendered output. Name the root cause with file:line and distinguish verified evidence from inference.
- Use read-only code evidence; do not query/change the database, run migrations, start the app, or use a browser. If evidence is insufficient, state what is missing rather than inventing behavior.
- Ask main for explicit file ownership before editing. Make the smallest root-cause fix; preserve CRLF and other work. Sweep the same pattern with rg and fix only small, clearly same-class items within scope.
- List schema, permission rules, payroll/payment, deletion, or large findings without changing them. Stop after 2 failed fix rounds and hand main the evidence and remaining work.
- Hand to hris-patch-tester: changed files, cause, sweep findings, and at most 3 scenarios. Only php -l/node --check are allowed code-execution checks; label source-reviewed behavior as unverified at runtime.
