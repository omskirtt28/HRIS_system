# Plan: HR appearance palettes

Spec: `../SPEC-hr-appearance-palettes.md` (approved by John 2026-11-02, "go na, HR portal lang") | Todo: `todo-hr-appearance-palettes.md`
Decision: HR portal only (`role_portal='hr'`); Admin keeps Light/Dark (spec Q1 closed).

## Overview
Add 3 colour palettes (Soft Beige, Earth Tones, Soft Pastel) next to Light/Dark in the Appearance menu for HR users. Saved per user in `users.ui_palette`, applied server-side on `<html>` so there is no flash. Everyone else keeps the one-click Light/Dark toggle.

## Dependency order
migration + service -> save endpoint -> palette CSS (+ contrast test) -> menu UI/JS/server render -> sweep, browser, vault.
After Task 2 an HR user can already save a palette (API); after Task 4 the full path works.

## Architecture decisions
- One column `users.ui_palette VARCHAR(16) NOT NULL DEFAULT 'light'` holds light|dark|beige|earth|pastel. Additive migration 027, no existing row changes.
- `app/UserPalette.php` owns the whitelist and HR gate; index.php stays thin (one POST action `set_palette`).
- Palettes are token overrides under `:root[data-palette="x"]` in new `public/assets/palettes.css` (loaded after `hris-modern.css` and `app.css`); components untouched. Hard-coded colours that ignore tokens are fixed in the sweep only when small.
- Light/Dark keep `data-theme`; beige/earth/pastel use `data-theme="light"` plus `data-palette`. Non-HR never get `data-palette`.
- localStorage `hris-theme` stays only for non-HR; for HR the DB value wins.

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Estimated hex fails contrast | Med | Automated ratio test (Task 3) blocks; adjust hex, never lower the floor |
| Page CSS with raw hex ignores tokens, looks patchy | Med | Sweep grep in Task 5; list large ones, fix small ones |
| Session caches old palette after save | Low | Update `$_SESSION['user']['ui_palette']` in the handler |
| Migration on localhost | Low | Standing workflow: dump + verified restore, scratch up/re-run/down/up, guarded down |

## Checkpoints
After Task 2 (save works, tests green), after Task 4 (UI complete), then browser check by John before commit.
