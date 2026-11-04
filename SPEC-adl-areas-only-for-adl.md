# Spec: adl-areas-only-for-adl ("Assigned areas for ADL" shows only for ADLs)

Status: DRAFT, waiting for John's approval. Single capability, so no capability map (Phase 0 skipped). Not implemented yet.

## Objective
In Employee 201 (edit form), the **Assigned areas for ADL** checkbox box must appear only when the employee is an ADL (Area Development Leader). It must not appear for Retail Staff, Sales Captain, OIC, MT, or any other position (screenshot 2026-11-04 19:52).
Users: HR (edits Employee 201). Indirect: ticket routing, which reads these areas for ADLs only.

## Facts found (code, 2026-11-04)
- The fieldset is printed unconditionally at `index.php:1552` whenever `EmployeeDirectoryPlacement::ready()`.
- "Is ADL" already has one rule: Directory role first, else position name in `ADL_POSITIONS` (`ADL`, `SR ADL`, `JR ADL`, `AREA DEVELOPMENT LEADER`, `SENIOR/JUNIOR AREA DEVELOPMENT LEADER`, `SR. ADL`, `JR. ADL`). See `EmployeeDirectoryPlacement::isAdlSql()` (line 24). Directory role = ADL forces ADL; Employees / Department Managers forces not-ADL.
- Only an ADL's areas have any effect (ticket routing, probation, directory filter). A non-ADL's areas are ignored, but open question Q37 already records a stale case: employee 1651 (Sales Captain) holds Ocampos.
- The form's `position_id` and `directory_role` selects can both change before saving, so the visibility has to follow them live.
- `EmployeeDirectoryPlacement::save()` (line 96) saves `directory_area_ids` for anyone; no ADL check on the server.

## Assumptions (correct me now)
1. "ADL" = the existing rule above (Directory role, else position name list). I reuse it; no new rule and no new position list.
2. Hide/show is live in the form: changes when the Position or the Directory role select changes.
3. When hidden, the checkboxes are **disabled** (not submitted), so a hidden box cannot silently post areas.
4. Server side: when the employee is not an ADL after the save, the stored `directory_area_ids` are **kept as they are** (hiding the UI must not erase data without HR's word). Alternative is clearing them (see Open question 1).
5. No migration, no schema change, no new permission. English-only labels.
6. Localhost only; live hosting applied manually by John later.

## Behaviour
| Position | Directory role | Areas box |
|---|---|---|
| ADL / SR ADL / JR ADL / Area Development Leader (see list) | Automatic | Shown |
| Retail Staff, Sales Captain, OIC, MT, anything else | Automatic | Hidden |
| any position | Area Development Leaders (ADL) | Shown |
| ADL position | Employees or Department Managers | Hidden (role overrides position) |

## Commands
```
Syntax:  php -l index.php
         php -l app/EmployeeDirectoryPlacement.php
Task:    php scripts/check.php task
Full:    HRIS_TEST_DB=<scratch> php scripts/check.php full
Tests:   php tests/department_picker_test.php   (existing form tests, must still pass)
         php tests/adl_areas_visibility_test.php   (new)
Browser: node tests/adl_areas_visibility_browser.cjs   (new, scratch DB via run-hris-system)
```

## Project structure
```
index.php                               fieldset at ~1552: add data attributes + small live-toggle script
app/EmployeeDirectoryPlacement.php      expose the ADL position list for the script (a public helper, no rule change)
public/assets/employee-directory.css    only if a [hidden] style is needed for .directory-assigned-areas
tests/adl_areas_visibility_test.php     PHP test: isAdl helper + rendered form markup
tests/adl_areas_visibility_browser.cjs  browser: change position / Directory role, box shows or hides
HRIS-Vault/ (Progress, Session-Log, Decision-Log, CHANGELOG, 02-Modules/Org-Structure, Open-Questions Q37, Screens if needed)
```

## Code style
Match the inline PHP/JS in `index.php`: `e()` for output, `data-*` attributes read by a small IIFE, no new dependency.
```php
<fieldset class="field full directory-assigned-areas" data-adl-areas data-adl-positions="<?=e(json_encode(EmployeeDirectoryPlacement::adlPositionNames()))?>"<?=$isAdlNow?'':' hidden'?>>
```
```js
function sync(){var r=role.value,p=(pos.options[pos.selectedIndex]||{}).text.trim().toUpperCase(),
  on=r==='ADL'||(r===''&&names.indexOf(p)>-1);box.hidden=!on;
  box.querySelectorAll('input').forEach(function(i){i.disabled=!on;});}
```

## Testing strategy
- PHP assert test (isolated scratch DB): an ADL-position employee and a Directory-role ADL render the box visible; Retail Staff, Sales Captain, OIC, MT render it `hidden` with disabled inputs; ADL position with role Employees renders hidden.
- Save test: posting a non-ADL form with no areas leaves `directory_area_ids` unchanged; an ADL's areas still save and still write the `CHANGE_ADL_AREAS` audit.
- Browser check (`run-hris-system`, scratch DB): open a Sales Captain, box hidden; change Position to ADL, box appears; set Directory role to Employees, box hides; 360px width shows no overflow.
- `php scripts/check.php task` must pass; no floor lowered, no test skipped.

## Boundaries
- Always: reuse the existing ADL rule; vault sync; English-only text; keep the `CHANGE_ADL_AREAS` audit; fix-sweep the same pattern (other places that show ADL-only fields).
- Ask first: clearing existing stored areas of non-ADLs (e.g. employee 1651); any migration; changing `ADL_POSITIONS`.
- Never: delete or bulk-edit data, change who counts as ADL for routing, touch live hosting, `git push`/deploy, weaken a test.

## Success criteria
1. Sales Captain, Retail Staff, OIC, MT and any non-ADL position: no "Assigned areas for ADL" box on the edit form.
2. ADL positions, or Directory role = ADL: box shown with their current checks intact.
3. Changing Position or Directory role in the form shows or hides the box immediately, without reload.
4. A hidden box posts no `directory_area_ids[]`; existing stored areas are not erased by hiding.
5. Ticket routing, probation and directory filters are unchanged.
6. `php scripts/check.php task` and the new tests pass.

## Open questions (need John)
1. Employee 1651 (Sales Captain) still has the Ocampos area stored (Q37). Keep it hidden and untouched (my default), or clear stale areas of non-ADLs when saved?
2. Is "ADL" here exactly the existing rule (Directory role, else the ADL position names above)? Or should only the position count, ignoring Directory role?
3. Should the box also be hidden on the Add Employee form for non-ADLs? (My default: yes, same behaviour everywhere it appears.)
