---
title: Progress
type: plan
status: draft
tags: [plan, progress]
updated: 2026-10-17
---

# Progress

Tick as work lands. Add the commit hash or PR next to a ticked item. Slice definitions live in [[Build-Plan]].

## Decisions gate

- [ ] Q1 company model answered, [[ADR-002-Multi-Tenancy]] accepted
- [ ] Q2 stack answered, [[ADR-001-Tech-Stack]] accepted
- [ ] Q3 rate tables as config, [[ADR-003-Payroll-Engine-Approach]] accepted
- [ ] Q4 cut-off calendar answered
- [ ] Q5 attendance source answered, ADR-004 written

## Slices

- [ ] SLICE-01 Walking skeleton
  - [ ] Repo, CI, test harness
  - [ ] Migrations and seeders
  - [ ] Login, sessions, lockout
  - [ ] Roles and scope
  - [ ] Audit log append-only
  - [ ] Department and employee create
  - [ ] Deployed from clean checkout
- [ ] SLICE-02 Org and 201 file
- [ ] SLICE-03 DTR capture
- [ ] SLICE-04 DTR review, corrections, lock
- [ ] SLICE-05 Leave
- [ ] SLICE-06 Rate tables and pay rules
- [ ] SLICE-07 Payroll compute
- [ ] SLICE-08 Register, payslip, release, reversal
- [ ] **MVP exit criteria met** (see [[Roadmap]])
- [ ] SLICE-09 Self-service portal
- [ ] SLICE-10 Notifications
- [ ] SLICE-11 Statutory reports
- [ ] SLICE-12 Recruitment to onboarding
- [ ] SLICE-13 Offboarding, final pay, 13th month
- [ ] SLICE-14 Performance

## Brownfield inventory (fill per slice)

| Slice | Existing code found | Keep / adapt / replace | Note |
|---|---|---|---|
| 01 | | | |

## Log

| Date | Entry |
|---|---|
| 2026-10-17 | Vault created; no code written. |
| 2026-10-17 | Patch (not a slice): Ocampo's roster import. Migration `20261017_lee_code_payroll_group.sql` adds `employees.lee_code` (unique) and `payroll_group`; roster parser reads the Ocampo's list (Bio Code = Employee Code, PMBSI code kept in `roster_original_code`, NBSP cleaning). John imported the 36 rows through the UI; DB backup taken before the migration. SLICE-01 stays paused. |
| 2026-10-17 | Patch (not a slice): Ocampo's Head Office sections. Migration `20261017_oc_head_office_sections.sql` adds `directory_sections` (scoped by company_group + site_type) with Inventory Management, Ocampos Production and Goldsmith for OC + HEAD_OFFICE only, and nullable `employees.directory_section_id`. The Section field on the Employment tab shows only for Ocampo's + a Head Office site; the server rejects out-of-scope values and clears the section when the employee leaves OC Head Office. Shown in the directory row. Departments and positions untouched; the shared Head Office branch (id 63) is kept. DB backup taken first, migration applied to live (re-run verified). `tests/oc_head_office_sections_test.php` 41/41 and `adl_reporting_test.php` 30/30 on a scratch copy. Dry-run (`scripts/oc_head_office_section_dryrun.php`) suggests a section for 3 of 8 OC Head Office employees; **none assigned**. Position-to-section hints are NEEDS HR CONFIRMATION (Inventory Management staff positions). Position near-duplicate merging already exists in `EmployeeRosterParser::LEE_POSITIONS` (earlier patch), not touched here. |
| 2026-10-17 | Follow-up to the Ocampo's Head Office sections patch, decided by John: only **Ocampos Production** is a section. Inventory Management is a shared department (Sw Head Office uses it) and Goldsmith is a position under Ocampos Production, so both sections were retired (removed if unused, deactivated if held). Migration also moves the `Goldsmith` position from Inventory Management to Production (only while it is still there) so the dropdown reads "Goldsmith · Production"; the roster parser maps Goldsmith to Production for future imports. Employees untouched: the Goldsmith employee (id 351) still has department Inventory Management, HR to decide. DB backup taken first, applied to live twice. Tests 44/44 (includes upgrade from the 3-section state) and ADL 30/30. Dry-run: 3 of 8 OC Head Office employees suggest Ocampos Production; none assigned. |
| 2026-10-17 | Patch (not a slice): Retail "Reporting To" = ADL only. Server-side rule in `EmployeeRepository` (Retail = department code RETAIL; ADL = same rule as the directory), dynamic dropdown on the Employment tab, Ocampo's auto-fill from the ADL who holds the Ocampos area (data-driven, no name hardcoded), HR-only change (`employees.manage`). `tests/adl_reporting_test.php` 30/30 on a scratch copy. **Backfill not applied**: dry-run shows 268 Retail employees unassigned, 0 settable until an ADL is given the Ocampos area in Employee 201. ADLs have no login yet. |
| 2026-10-17 | Patch (not a slice): Head Office team grid no longer shows the synthetic "<Department> employees" card ("Employees assigned directly to …") when 0 employees are assigned directly. Applies to every main department and both groups (Finance, Inventory Management, Manpower Planning; Marketing/Online have no team cards). The card still appears when the direct count is above 0 so those employees stay reachable. One condition in `EmployeeDirectoryService` (team-card loop); the "N teams" badge counts cards so it follows automatically. No DB or data change. `tests/directory_direct_card_test.php` 18/18 and `adl_reporting_test.php` 30/30 on a scratch copy; All employees stays 363. Not touched, for HR to decide: 3 Department Managers (Finance, Manpower Planning, Inventory Management) and the Goldsmith (OMOA, department Inventory Management) are direct-assigned but are listed under Managers / OC Retail, not in these cards. |

## Related

[[Build-Plan]] · [[Claude-Code-Brief]] · [[Open-Questions]] · [[Decision-Log]]
