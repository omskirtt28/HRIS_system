---
name: hris-ui
description: Implement or repair HRIS screens, forms, tables, CSS, responsive layout, and accessibility using the existing design system; excludes backend logic.
---

# HRIS UI implementation

Paths are relative to the repository root. Follow AGENTS.md and HRIS-Vault/00-Meta/Codex-Agent-Workflow.md; use hris-patch for planning and delivery.

- Read HRIS-Vault/03-Design/Design-System.md, the applicable module/screen note, and neighboring markup/CSS/JS before editing.
- Work as hris-ux-designer on the frontend files assigned by main. Reuse existing tokens/components, preserve CRLF and others' edits, and keep the requested scope.
- Review labels, event handlers, tab/focus order, semantic controls, contrast tokens, responsive structure, overflow, and loading/empty/error/disabled states by source.
- Sweep matching components with rg; fix small same-class defects. Report large layout changes and backend/permission dependencies without expanding the patch.
- For dashboard work, follow the applicable agreed period-picker rule and Download Report parity in the current project instructions; do not retrofit unrelated dashboards.
- Do not start the app or use browser/screenshots. Hand to hris-patch-tester for php -l/node --check where applicable, and explicitly leave rendered layout and interaction unverified.
- Main updates affected Screens.md/module/Design-System notes plus Progress.md, Session-Log.md, and root CHANGELOG.md before reporting.
