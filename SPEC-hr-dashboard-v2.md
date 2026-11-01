# SPEC: hr-dashboard-v2

Status: DRAFT 2026-10-31 for John's approval. Plan only, no code changed.
Skills used: agent-skills:frontend-ui-engineering, spec-driven-development, planning-and-task-breakdown.

## 1. Objective
Upgrade the HR user dashboard (`?page=hr-dashboard`, `hr_dashboard_page()` in `app/HrDashboardViews.php`) so it looks as modern as the new sidebar (charcoal + amber, icon tiles, rounded 12-16px, calm density). UI/UX only: the numbers, queries, links, permissions and period/company filters stay exactly as they are.

What looks old in the screenshots (2026-10-31)
1. Every card has the same weight: 6 equal KPI tiles with a faded orange corner glow; nothing says what needs action first.
2. Recruitment pulse draws 11 identical amber blobs when every count is 0 (8px minimum height), so zero looks like data.
3. "View pipeline / Open feed / Open queue" are tinted pill buttons; they compete with the real primary button.
4. The filter row is two plain selects plus one long grey sentence.
5. "Requires attention" mixes three unrelated things (oldest requests, incident summary, setup warnings) in one narrow card; setup warnings are large cards.
6. Empty states are tall blank areas ("No requests were filed in this period.").
7. Hero is large (greeting + subtitle + 2 buttons) and pushes the KPIs below the fold.
8. Quick actions repeat the sidebar in big tiles.

Target design (matches the sidebar language)
- Header: slim greeting row, one primary button (Open HR Request Queue) and one secondary (Employee directory). Date/role as small meta line.
- Filter bar: Company select + Period as a segmented control (same period list as today), one short grey caption with the date range. Still a GET form, still works without JS.
- KPI zone in two tiers: "Needs action" (Pending requests, In progress, Incident Reports) as larger cards with an icon tile like the sidebar group tiles and a status accent; "Overview" (Served, Recruitment, Active employees) as compact cards. Delta shown as a chip with arrow AND text (not colour only).
- Requests by type + How long requests waited: keep the data, restyle bars (thinner track, legend as chips, zero rows muted), compact empty state.
- Right column split into three small cards: Oldest pending requests, Incident Reports, Setup health (compact rows with count chips; green "All clear" when 0).
- Recruitment pulse: bars only for stages above 0; when all are 0 show one compact empty state with the stage list muted.
- Today's interviews + Quick actions: compact rows; quick actions become a 2x2 icon-tile grid.
- Panel links become plain "Label ->" text links (no tinted pill).

Success criteria (testable)
1. Same data on screen as before: every KPI value, link href, count and permission branch (`is_hr`, incident, setup) unchanged; `tests/hr_dashboard_test.php` still passes unchanged.
2. First screen at 1440x900 shows header, filter bar and the full KPI zone without scrolling.
3. Layouts hold at 320, 768, 1024, 1440 px with no horizontal page scroll.
4. Light and dark mode both use the `--hris-*` tokens only (no raw hex in new rules); text contrast >= 4.5:1.
5. Keyboard: every card/link is reachable with a visible `:focus-visible`; period control is a real radio group or links with `aria-current`; reduced motion respected.
6. No colour-only meaning: deltas, statuses and age buckets carry text or an icon.
7. All text English. No hard-coded numbers.
8. Other portals' dashboards (Employee, Client, Admin) render exactly as before.

## 2. Commands
```
php -l app/HrDashboardViews.php
php tests/hr_dashboard_test.php
php tests/hr_dashboard_ui_test.php        (new, static)
php scripts/check.php task
HRIS_TEST_DB=pmbsi_hris_test php scripts/check.php full   (before shipping)
```
Browser check (run-hris-system, isolated DB, John's rule: Claude does not browse unless he says so): screenshots at 1440 and 390, light and dark.

## 3. Project structure
- `app/HrDashboardViews.php` markup only (wrap in `.hd2` so old and new styles cannot leak).
- `public/assets/hr-ops.css` (`hd-` rules; new `.hd2 ...` block, bump `?v=`); new classes only, shared `.metric-card`, `.panel`, `.dashboard-hero`, `.quick-action` in `hris-modern.css` are NOT edited (other portals use them).
- `tests/hr_dashboard_ui_test.php` new; register in `scripts/check.php` and `CONSTRAINTS.md` (raise floor, never lower).
- No change: `app/HrDashboard.php` (data), routes, permissions, nav_config, migrations.

## 4. Code style
Match the file: compact PHP views, `e()` on every output, `icon_svg()`, existing helpers (`hq_age`, `hq_pill`, `attention_item`). CSS tokens only, 4px spacing scale, radii 12/16. One small helper `hd_kpi()` gets a `tier` argument instead of a second function.

## 5. Testing strategy
Static test (like `sidebar_fold_test.php`): the `.hd2` wrapper exists, no raw hex in the new CSS block, tokens used, `:focus-visible` rules, `prefers-reduced-motion`, asset `?v=` bumped, period control has `aria-current`/labels, empty-state strings present, `hr_dashboard_page` still renders every href the old test expects. Existing `hr_dashboard_test.php` is the regression guard. Browser screenshots only on John's word.

## 6. Boundaries
- Always: English UI, tokens, vault sync, fix sweep list, keep server checks.
- Ask first: new queries or sparklines (needs data-layer work), changing the period list, moving the quick actions out.
- Never: change `HrDashboard.php` numbers, permissions, routes, shared CSS used by other portals, commit/push/deploy, DB writes.

## 7. Fix sweep (list only, not in this slice)
Employee, Client and Admin dashboards use the same old `metric-card` / `panel-link` look. After John approves this look, a follow-up slice can move the shared styles. Not touched now (risk: three portals at once).

## 8. Open questions for John
- Q1 Keep all 6 KPIs but in two tiers (recommended), or fewer tiles?
- Q2 Period control: segmented buttons (recommended) or keep the dropdown?
- Q3 Quick actions: keep as compact 2x2 tiles (recommended) or remove because the sidebar has them?
- Q4 Recruitment pulse: bars only for non-zero stages plus empty state (recommended), or switch to a horizontal funnel?
- Q5 Any section you want first (e.g. incidents above requests)?
