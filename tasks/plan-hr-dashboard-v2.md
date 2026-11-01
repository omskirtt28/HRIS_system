# Plan: HR dashboard v2 (UI only)

Status: DRAFT for John's approval. Spec: `../SPEC-hr-dashboard-v2.md`. Named separately because `tasks/plan.md` still holds the open My Profile plan (not overwritten).
Rules: one slice, vault sync before the final report, no commit/push/deploy, no DB write, no browser testing unless John says so.

## Dependency graph
```
T1 .hd2 scope + tokens/CSS skeleton --> T2 header + filter bar --> T3 KPI tiers
T1 --> T4 requests panels --> T5 right column cards
T1 --> T6 recruitment/interviews/quick actions
T3,T5,T6 --> T7 dark/responsive/a11y polish --> T8 test + vault + package
```
Each task leaves the page working; markup and CSS change together per task.

## Tasks
- [x] T1 Scope wrapper + CSS base
  - Acceptance: page wrapped in `.hd2`; new block in `hr-ops.css` uses tokens only; other portals unaffected
  - Verify: php -l; grep that `hris-modern.css` is unchanged
  - Files: app/HrDashboardViews.php, public/assets/hr-ops.css
- [x] T2 Header and filter bar
  - Acceptance: slim greeting, 2 buttons, Company select + Period segmented control (GET, no JS needed), short caption
  - Verify: php tests/hr_dashboard_test.php; params unchanged
  - Files: app/HrDashboardViews.php, hr-ops.css
- [x] T3 KPI tiers
  - Acceptance: Needs action (3 large) + Overview (3 compact), icon tile, delta chip with arrow+text, same values/links
  - Verify: hr_dashboard_test.php; compare hrefs old vs new
  - Files: app/HrDashboardViews.php, hr-ops.css
- [x] T4 Requests by type and wait-time panels
  - Acceptance: thinner bars, chip legend, muted zero rows, compact empty states, links as text links
  - Verify: test + keyboard focus rules present
  - Files: app/HrDashboardViews.php, hr-ops.css
- [x] T5 Right column: Oldest pending, Incident Reports, Setup health
  - Acceptance: three separate small cards, count chips, "All clear" state, same hrefs
  - Verify: test
  - Files: app/HrDashboardViews.php, hr-ops.css
- [x] T6 Recruitment pulse, interviews, quick actions
  - Acceptance: no bars for 0 counts, empty state, 2x2 quick-action tiles
  - Verify: test; zero and non-zero data both render
  - Files: app/HrDashboardViews.php, hr-ops.css
- [x] T7 Polish: dark mode, 320/768/1024/1440, focus-visible, reduced motion
  - Acceptance: spec criteria 2-6
  - Verify: static test; optional screenshots on John's word
  - Files: hr-ops.css
- [x] T8 Test, constraints, vault, package
  - Acceptance: `tests/hr_dashboard_ui_test.php` registered, floor raised; `php scripts/check.php task` OK; fix-sweep report
  - Files: tests/hr_dashboard_ui_test.php, scripts/check.php, CONSTRAINTS.md, vault notes

## Checkpoints
- After T3: KPI zone fits the first screen, values identical to before.
- After T6: every panel present in all three states (empty, normal, many rows).
- After T8: `check.php task` OK, John reviews screenshots.

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Shared classes leak to other portals | High | New `.hd2` scope, shared CSS untouched |
| Existing `hr_dashboard_test.php` / `hr_ops_browser.cjs` match old markup | Med | Run both; keep ids, hrefs, headings |
| Layout not verified in a browser | Med | Report as not verified; screenshots on John's word |
| Dark-mode contrast | Low | Tokens only, check pairs in the test |

## Vault sync (at the end)
Progress.md, Screens.md (HR dashboard), Design-System.md (dashboard tiers pattern), CHANGELOG.md, Session-Log.md, plan status. No Data-Model/Business-Rules change (UI only).
