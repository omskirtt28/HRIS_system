---
name: hris-review
description: Review an HRIS patch against its plan, security checks, same-pattern sweep, and project rules; read-only QA before an authorized localhost release.
---

# HRIS patch review

Paths are relative to the repository root. Follow AGENTS.md and HRIS-Vault/00-Meta/Codex-Agent-Workflow.md. Never edit repository files during review.

- Read the plan, exact changed files/diff, and adjacent affected flow. Verify scope, assigned ownership, CRLF preservation, and that unrelated user work remains intact.
- Check server validation, CSRF, role/scope checks, escaping, prepared queries, UI/POST consistency, failure paths, and the planned same-pattern sweep from source.
- Run php -l only on changed PHP and node --check only on changed JavaScript. Do not run the app, browser, database tests, migrations, or deployment commands.
- Inspect at most 3 planned scenarios once; distinguish source reasoning from executed checks. For config/docs-only changes inspect syntax/references within the main session's authorized validation scope.
- Return PASS / FAIL / PASS WITH NOTES with command results, file:line findings, MISSED SWEEP items, the engineer responsible for failures, and Not verified. Main controls any fix loop, maximum 2 rounds.
- Confirm main has recorded the actual changes/checks in Obsidian before final delivery. Missing runtime evidence must remain explicit; passing lint alone does not prove a localhost flow.
- Report optional simplifications as proposals; never apply them during review. QA never authorizes DB writes, commits, pushes, or deployment.
