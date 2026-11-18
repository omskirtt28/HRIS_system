# SPEC: directory-field-labels

Status: DRAFT, awaiting John's confirmation. No code written.

## 1. Objective
Two HR form fields are both called "section", and HR cannot tell what they do. Relabel and explain; no logic change.

Evidence: "Directory section" is `directory_role` (index.php ~1400). It overrides ADL/Department Manager/Employee and feeds `EmployeeDirectoryPlacement::isAdlSql` and Reporting To. "Section (Ocampo's Head Office)" is `directory_section_id` (`DirectorySectionViews::field`), a sub-group only for Ocampo's Head Office. Neither is redundant.

Acceptance criteria
1. "Directory section" becomes "Directory role"; blank option becomes "Automatic (from position)". Helper: "Decides where this person appears in the Employee Directory and Reporting To. Leave on Automatic unless the position is wrong."
2. "Section (Ocampo's Head Office)" becomes "Head Office team"; "No section" becomes "No team". Helper: "Only for Ocampo's Head Office employees. Leave on No team if none applies."
3. Field names, values, POST keys, DB values and audit keys are unchanged.
4. Comment at `EmployeeDirectoryPlacement.php:17` and any audit/report label that says "Directory section" are updated to match (grep all titles, headings, tabs, notifications).
5. Text fits at 360px, no overflow; "Assigned areas for ADL" fieldset unchanged.

## 2. Commands
`php -l index.php app/DirectorySectionViews.php app/EmployeeDirectoryPlacement.php`, `php tests/oc_head_office_sections_test.php`, `php tests/adl_reporting_test.php`

## 3. Project structure
`index.php`, `app/DirectorySectionViews.php`, `app/EmployeeDirectoryPlacement.php` (comment), tests that assert the old labels.

## 4. Code style
Plain English labels, one-line `<small>` helpers like the neighbouring fields.

## 5. Testing strategy
Update label assertions; run the two existing tests above unchanged otherwise.

## 6. Boundaries
- Always: English only, keep field names.
- Ask first: removing either field, changing how the directory groups people.
- Never: schema or data change.

## Open questions
None.
