# Spec: My Team modern UI/UX

Status: APPROVED by John 2026-11-05; BUILT locally (static checks only, see Session-Log). Codex draft, 2026-11-05. Single capability; Phase 1 only.

## Objective
Modernize the existing employee-team roster shown in John's screenshot, following HRIS Design-System. Current index.php uses class field for the whole search form, corresponding to the stretched controls in the screenshot.

## Assumptions for review
Retain PMBSI branding/shared shell, read-only roster, current permissions/team scope and existing server search/order/25-row paging. This patch covers My Team; future authorized screen updates include scoped UI/UX cleanup without a separate request. No new backend feature.

## Proposed experience and success criteria
- Clear My Team heading and description: View the people assigned to your team.
- One roster panel with a compact count badge; while searching label the existing filtered total as matching results, not overall team size.
- Visible search label, optional decorative line icon, input, primary Search button and conditional Clear link. Desktop controls align in one toolbar; mobile stacks cleanly. Enter submits. Clear removes q and resets paging.
- Retain Employee code, Name, Position, Area / Branch, Reporting to. Name gets strongest emphasis; codes remain easy to scan. No fabricated status/avatar/metrics or case conversion of stored names.
- Readable 13-14px body text, balanced columns, consistent row/panel padding, restrained borders/shadows, existing semantic tokens. Normal desktop content fits without routine horizontal scrolling; long content wraps naturally.
- Around 360px: labeled stacked roster rows using the same data, no page overflow or duplicated accessible content. Preserve semantic label/value relationships.
- Distinct no-assignment and no-search-result messages; contact HR for missing assignments, Clear search for zero matches. Missing optional fields use a consistent dash.
- Existing light/dark themes, keyboard access, visible focus, associated labels and usable touch targets around 44px. Reuse shared components.
- Keep pagination query persistence and identical own-team scope for count/rows/search. No edit/full-201 links, exports, assignment controls or private HR fields.

## Tech stack / project structure
Existing PHP server templates and shared CSS; no framework/dependency added. Verify runtime declarations before proposing any minimum version.
- index.php: employee-team view/controller, currently around 1254-1272.
- public/assets/hris-modern.css: scoped page styles only for verified shared-style gaps.
- app/View.php: stylesheet asset version only if required by CSS change; retain shell.
- app/EmployeeTeamService.php: inspect existing roster scope/search contract; no backend edit proposed.
- tests/employee_team_test.php: existing roster coverage, not executed under static-only rules.
- HRIS-Vault/03-Design/Design-System.md, 02-Modules/Self-Service-Portal.md and continuity notes: shared requirements/status.
These are continuation candidates, not an implementation plan.

## Commands
After implementation, once per applicable changed file:
```powershell
& 'C:\xampp\php\php.exe' -l index.php
& 'C:\xampp\php\php.exe' -l app/View.php
# Only if a JS file is actually changed; none proposed:
node --check <exact-changed-js-file>
git diff --check
git diff --stat
git diff -- index.php public/assets/hris-modern.css app/View.php
```
No build/dev-server, browser/app/DB test or migration command is authorized by this spec. Skip lint for unchanged files.

## Code style
Targeted edits, preserve CRLF, surrounding template conventions, escaped dynamic output:
```php
<td><?=e((string)$m['employee_no'])?></td>
```
Reuse panel/btn components and semantic tokens. Scope new styles to My Team; avoid global generic-field/table changes.

## Testing strategy
Static inspection only, max three scenarios once: (1) populated roster, long values and missing optional fields; (2) search/clear/query-preserving paging; (3) empty/no-result states, mobile CSS and keyboard semantics. Inspect server permission guard and unchanged own-team service contract. No coverage percentage claimed. Rendered layout, device, assistive technology and runtime behavior remain unverified unless separately requested.

## Boundaries
- Always: read exact source/callers; preserve escaping, prepared queries, server permission and own-team scope; sweep same-class patterns; sync vault/checkpoint. Include small proven equivalents in the reviewed plan.
- Ask first: this spec, then technical Plan/Tasks approval per the invoked workflow. Additional dependencies, schema, permissions or data changes require explicit review.
- Never: expose other teams, fake metrics, whole-file rewrites, unauthorized browser/app/DB checks, deploy/commit/push/delete.
- List only: schema/auth/payments/deletes or broad shared redesign found by the sweep.

## Ongoing UI/UX rule requested by John
Every future authorized HRIS screen/module update includes scoped UI/UX inspection/cleanup of the touched screen: spacing, hierarchy, readable forms/tables, responsiveness, accessibility and truthful states. Include this in each plan; unrelated screens stay separate. Preserve explicit plan-only restrictions.

## Open questions / next gate
No blocking functional questions. John reviews the visual direction, including mobile stacked rows. Spec approval advances to technical Plan in a later turn, not directly to code.

## Baseline and limits
Latest actual application work: Claude 2026-11-05 HR role assignment + Retail Manager parity/My Team, migration 037 recorded applied localhost in Progress/Session-Log; hosting not deployed. Browser and Rom real grants/team setup unverified. Older handoff entries referencing 036 are superseded by those newer records. No DB status revalidated now. This turn is documentation only; no application patch/package/dependency. Graph query stale/pending; exact sources inspected. Graph registration/extraction/refresh/export pending outside writable workspace; no freshness claim.

## Addendum v3 - "more modern" pass (Claude, 2026-11-05, APPROVED + BUILT locally, static checks only)
Trigger: John screenshot after polish v2 still looks plain (flat toolbar, uppercase-heavy header, text-only rows). Skill input: ui-ux-pro-max (priority 1-6: accessibility, touch, style consistency, layout, type/color). Presentation only; same data, same scope/search/paging, no new backend, no fabricated data.

Proposed (all tokens-only, scoped to `.myteam`, light + dark):
1. Toolbar: search field and Search button in one soft rounded bar (field 48px, 12px radius, subtle inset border, brand focus ring); count badge moves beside the title as a pill; Clear becomes a ghost button (not underlined link).
2. Name cell: initials avatar circle (derived from the existing name, decorative, aria-hidden) + bold name; employee code shown as a small muted mono-style chip. No photo, no status, no invented metrics.
3. Table: header sentence-case 12px semibold muted (drop heavy uppercase tracking), taller rows (min 56px), softer row dividers, hover tint + subtle left brand accent, Area/Branch shown as a quiet tag when both exist ("MM3 / SM Angono" stays one string, no case conversion).
4. Panel: larger radius + soft shadow consistent with `.ap-sec`; footer line "Showing X-Y of N" if existing pagination exposes it (no new query).
5. <=720px: stacked rows become cards (avatar + name header, label/value pairs below), 44px touch targets kept.
6. Motion: 120-150ms hover/focus transitions only, disabled under prefers-reduced-motion.
7. Empty states: add a line icon above the existing two messages (copy unchanged).

Success: contrast >= 4.5:1 both themes; no horizontal overflow at 360px and 1280px; keyboard/focus unchanged; tests/my_team_ui_test.php updated test-first (avatar aria-hidden, no duplicate accessible text, dash blanks) and green; php -l, check.php task, git diff --check.
Files (<=4): index.php (employee-team markup ~1264-1271), public/assets/hris-modern.css (.myteam block), app/View.php (asset version), tests/my_team_ui_test.php. Vault sync after build.
Open question: avatar initials OK, or keep text-only names?

### v3 skill evidence (ui-ux-pro-max search ran 2026-11-05, py -3)
- Style match: "Minimalism & Swiss Style" (enterprise/dashboards; light+dark; risk low) -> adopt: grid-based, spacious, high contrast, essential only. Effects: subtle hover 200-250ms (use 150-200ms), clear type hierarchy.
- UX rules: no hover-only info; tables on mobile -> card layout or overflow-x wrapper (we use cards); SVG icons only; cursor-pointer on clickables; visible focus; reduced-motion; test at 375/768/1024/1440.
- Rejected (project tokens win): returned palette (#1E3A8A blue/purple), Outfit/Work Sans, "Hero+Features+CTA" landing pattern (not applicable). Keep PMBSI amber brand tokens and current app font.
