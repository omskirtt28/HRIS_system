# Spec: Job form - Retail branch picker (show/hide + advanced UI)

Module: rec-jobs (follow-up to hiring-review-pass-visibility, migration 031). Status: approved by John 2026-11-03 (blank and Other company also hide the block; always-expanded cards). Built, awaiting browser check.

## Objective
On HR > Job Openings > New/Edit job, the "Retail area and branches" block must follow Site type:
- Head office / Other company / (blank) -> block hidden, no branch can be submitted.
- Retail -> block shown, with a better picker that shows ALL areas and branches at once (no cramped 220px inner scroll box).
User: HR (Kristian Acosta). Why: the block is noise for Head office jobs, and the scroll box hides areas (Ocampos, MM3, ...).

## Assumptions (correct me now)
1. Pure UI change: no migration, no schema, no new route. Backend already ignores `branch_ids` unless site_type=RETAIL (`app/JobOpenings.php:138`).
2. Blank "Use the request branch" = hidden too (only explicit Retail shows it). See Open Question 1.
3. Existing selections are kept in the page while toggling Site type (hidden, not cleared) and are dropped server-side if saved as non-Retail.
4. English-only UI, existing design tokens (`HRIS-Vault/03-Design/Design-System.md`), no new dependency, small vanilla JS inline like other forms.

## Proposed UI (Retail)
- Area cards in a responsive grid (auto-fill, ~2-3 columns), all visible, page scrolls, no inner scroll.
- Each card: area name, "x / n selected" counter, "All" / "Clear" per area (same pattern as Charges-style All/Clear picker).
- Branches as checkable chips (whole chip clickable, >=36px tall, visible focus + checked state).
- Top bar: search/filter box ("Filter branches"), "Select all" / "Clear all", live total "12 branches selected".
- Empty hint (not blocking): "No branch selected - only HR can pass Retail applicants on."
- Works in light/dark theme, phone width, keyboard (Tab/Space), and with JS off falls back to plain checkboxes (block visible).

## Commands
- Check: `php scripts/check.php task` (before handing back); `full` with `HRIS_TEST_DB` set before shipping
- Tests: `php tests/hiring_review_visibility_test.php` (existing 21 asserts must still pass)
- Syntax: `php -l app/JobOpeningsViews.php`
- Browser check: `run-hris-system` skill on isolated scratch DB

## Project Structure
- `app/JobOpeningsViews.php` `job_openings_form()` lines ~50-65: markup + tiny inline script/CSS. Only file expected to change.
- `app/JobOpenings.php:138`: untouched (already guards).
- Vault: Progress.md, 02-Modules/Recruitment-and-Onboarding.md, Screens.md, CHANGELOG.md, Session-Log.md.

## Code Style
Match the file: PHP echo strings, `e()` for output, `.field`/`.split` classes, short inline style only where no class exists.
```php
echo '<fieldset class="field" id="jf-branches" data-site="RETAIL">...';
```
JS: `site.addEventListener('change', sync); sync();` toggling `hidden` and `disabled` on the fieldset inputs so hidden checkboxes are never posted.

## Testing Strategy
- Existing PHP test stays green (server guard unchanged).
- Add one assertion-style test: the form HTML for site HEAD_OFFICE renders the fieldset hidden/disabled; RETAIL renders it visible with prior `$on` branches checked.
- Manual/browser: switch Site type back and forth, All/Clear, filter, save Retail with branches, save Head office after Retail (branches dropped), mobile width, dark theme.

## Boundaries
- Always: CSRF/form_token untouched, escape output, keep the `branch_ids[]` field name, keep checked state on edit.
- Ask first: any schema/permission change, changing who can pass applicants on, adding a JS library.
- Never: touch `RecruitmentRepository::targetsFor()` logic, delete `job_opening_branches` rows outside the existing save path, push/deploy.

## Success Criteria
1. Site type = Head office -> block not visible and no `branch_ids[]` in the POST.
2. Site type = Retail -> block visible; every active non-HO area and branch visible without an inner scrollbar.
3. Edit of an existing Retail job opens with its saved branches checked and counters correct.
4. Per-area All/Clear, Select all/Clear all, and filter work; counters update live.
5. Keyboard and screen reader usable (fieldset/legend, labelled checkboxes, visible focus); no horizontal overflow at 375px.
6. `scripts/check.php task` and the existing 21 recruitment tests pass.

## Open Questions
1. Blank option "Use the request branch": hide the block (my assumption) or show it? (Retail-from-request jobs may want it.)
2. Should "Other company" also hide it? (Assumed yes, HR only.)
3. Do you want a collapsed summary (e.g. "North: 4 of 7") when the picker is long, or always fully expanded? (I propose always expanded, per your "nakalabas na lahat".)
