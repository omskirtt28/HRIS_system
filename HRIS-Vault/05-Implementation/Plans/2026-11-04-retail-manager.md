---
title: Retail Manager (head of the ADLs)
type: plan
status: done
tags: [plan, employees, roles, adl]
updated: 2026-11-04
---

# Retail Manager (head of the ADLs)

Status: done (local). T1-T5 built; migration 032 applied to localhost pmbsi_hris 2026-11-04 after verified backup (126 tables). Not committed, not browser-verified.
Date: 2026-11-04
Session: [[Session-Log]]
Spec: `SPEC-retail-manager.md` (repo root)

## Context
Ang ADL (Head Office site) ay "Unassigned" sa Reporting To kasi walang tamang head. Gusto ni John ng **Retail Manager** na nag-hahawak ng mga ADL.
Desisyon ni John (4 Nov 2026):
1. Bagong **position "Retail Manager"** (Retail dept) at bagong **role RETAIL_MANAGER**. Kapag ADL ang pinili sa Employee 201, ang Reporting To list ay Retail Manager lang.
2. Isa lang ngayon, pero **flexible**: walang hard-coded na user/ID; kahit ilan ang Retail Manager pwede.
3. **Walang recruitment/Hiring Review** para sa Retail Manager. ADL ay magti-ticket na lang kung kailangan.
4. Existing ADLs: HR mismo ang mag-assign sa Employee 201, walang bulk update (0 ADL ang may manager ngayon, kaya walang conflict).

## Facts (verified, read-only)
- ADL sa Head Office -> approver kind MANAGER -> resolver kumukuha ng `employees.manager_employee_id` -> user na may `payroll.approve_manager` (`EmployeeApproverAssignmentService::validApprover`). Kaya ang role na may `payroll.approve_manager` ay gagana agad sa payroll routing, walang routing code change.
- `EmployeeRepository::validateImmediateManager` (hindi-retail branch) ay humihingi ng active na linked account + `manager_ready` (approve_manager o approve_adl). Walang ADL-specific rule ngayon.
- Ticket visibility ng head: `TicketViewerService::managedHeadOffice` = Head Office employees na ang Reporting To ay account ng viewer; kailangan ng `ticket.view_team` sa role; nilalaktawan ang role code `MANAGER` (Manager filer). RETAIL_MANAGER ay hindi nalalaktawan kaya makikita niya ang tickets ng ADL niya nang walang bagong code.
- MANAGER role = 21 permissions, subset ng ADL (29). Walang perm ang MANAGER na wala sa ADL. Clone ng MANAGER grants ang pinakasimple at flexible.
- `isManagerFiler` (`EmployeeApproverAssignmentService.php:26`) at `TicketViewerService.php:96` ay naka-hard-code sa role code `MANAGER`; kailangang isama ang RETAIL_MANAGER (Retail Manager mismo ay nagfa-file diretso sa HR, walang viewer sa taas).
- Directory role ay automatic mula sa position (`EmployeeDirectoryPlacement.php:78`); ang Retail Manager ay kailangang mapunta sa Department Managers.

## Dependency graph
```
T1 migration 032 (position + role + grants)
   |
   +-- T2 Reporting To rule for ADL (server validation + dropdown)   needs T1 (role/position exist)
   +-- T3 Directory placement + manager-filer sweep                  needs T1
          |
          +-- T4 end-to-end routing/visibility test                  needs T2 + T3
                 |
                 +-- T5 vault sync + fix sweep + final report
```
Vertical: bawat task ay may sariling test at gumagana nang mag-isa.

## Mga gagawin

### T1. Position at role (S, 3 files)
Migration `20261104_032_retail_manager.sql` + `.down.sql`: idempotent na magdagdag ng position "Retail Manager" (Retail dept) at role `RETAIL_MANAGER` ("Retail Manager", portal employee, not system) na kinopya ang grants ng MANAGER role (INSERT ... SELECT, hindi hard-coded na listahan). Kung may RETAIL_MANAGER na, hindi ginagalaw (kapareho ng pattern ng migration 011, may "owned" seed record). Down: tinatanggal lang ang sarili nitong role/position kapag walang user/employee na gumagamit; kung may gumagamit, humihinto at nagre-report.
- Acceptance: role at position lumalabas; grants == MANAGER grants; re-run walang duplicate; down na may gumagamit = refused; down na wala = malinis.
- Verify: scratch DB up -> re-run -> down -> up; `php scripts/check.php task`.
- Files: `database/migrations/20261104_032_retail_manager.sql`, `.down.sql`, `tests/retail_manager_test.php`.

### T2. ADL -> Retail Manager lang sa Reporting To (M, 4 files)
- Server: `validateImmediateManager` (hindi-retail branch): kung ang empleyado ay ADL (`self::isAdl`), ang pinili ay dapat may active account na role `RETAIL_MANAGER`; mensahe sa English: "ADLs report to a Retail Manager. Choose a Retail Manager with an active HRIS account." Unassigned ay pwede pa rin. Hindi apektado ang Retail staff (ADL lang ang pipiliin) at Head Office staff (Manager).
- UI: sa `index.php` Reporting To select, `data-rm="1"` sa Retail Manager options; para sa ADL na empleyado, Retail Managers lang ang ipinapakita (extend ang existing script na nagfi-filter para sa Retail), at ang help text ay nagpapaliwanag. Walang bagong screen.
- Acceptance: ADL form -> Retail Managers lang; subukang i-POST ang ibang Manager -> tinatanggihan; Retail staff at HO staff list ay hindi nagbago; pagpalit ng Reporting To ay naka-audit gaya ng dati.
- Verify: extend `tests/adl_reporting_test.php` (a)-(d); browser check via run-hris-system.
- Files: `app/EmployeeRepository.php` (+ `managerCandidates` flag `is_retail_manager`), `index.php`, `tests/adl_reporting_test.php`.
- Dependencies: T1.

### T3. Directory + Manager-filer sweep (S, 3 files)
- `EmployeeDirectoryPlacement.php:78`: idagdag ang "RETAIL MANAGER" sa auto DEPARTMENT_MANAGER (kahit anong workplace) para lumabas sa Department Managers; ADL_POSITIONS hindi ginagalaw.
- `EmployeeApproverAssignmentService::isManagerFiler` at `TicketViewerService.php:96`: tanggapin ang `RETAIL_MANAGER` kasama ng `MANAGER` (`r.code IN ("MANAGER","RETAIL_MANAGER")`).
- Acceptance: Retail Manager nasa Department Managers; ang ticket niya ay diretso sa HR (walang "assign your Manager" error); hindi siya nakikita bilang "managed" ng ibang viewer.
- Verify: test sa `tests/retail_manager_test.php`.
- Files: `app/EmployeeDirectoryPlacement.php`, `app/EmployeeApproverAssignmentService.php`, `app/TicketViewerService.php`.
- Dependencies: T1.

### Checkpoint A (after T1-T3) - REACHED 2026-11-04
Build notes: the role copies MANAGER grants MINUS recruitment.review (a first version that copied everything broke the existing rec_endorse_manager test, which pins recruitment.review to MANAGER, ADL, SC, TC, OIC, MT). ADLs sit in the Retail department, so `updateEmployment` now treats ADLs and Retail Managers as not-Retail-staff (otherwise the old Retail rule forced ADLs to report to an ADL). Suite added: tests/retail_manager_test.php (25 PASS, registered in scripts/check.php and CONSTRAINTS.md).
- [ ] `php scripts/check.php task` OK; isolated-DB tests pasado
- [ ] Walang nagbago sa ibang role/permission (diff ng role_permissions bago/pagkatapos, maliban sa bagong role)
- [ ] Review kay John bago ang T4

### T4. End-to-end: routing at visibility (S, 1-2 files, test lang)
Sa scratch DB: gumawa ng Retail Manager (employee + account), ADL na naka-assign sa kanya, at isang payroll ticket ng ADL. Patunayan: (a) resolver -> Retail Manager ang approver; (b) Retail Manager ay nakakakita ng ticket ng sarili niyang ADL lang (read-only), hindi ng ibang ADL; (c) walang Hiring Review/recruitment access na nadagdag; (d) ADL na walang assignment ay Unassigned pa rin gaya ng dati. Kung may mabigo, maliit na fix lang sa shared code.
- Verify: `php tests/retail_manager_test.php`.
- Dependencies: T2, T3.

### T5. Vault sync, sweep at apply (S, docs)
- Fix sweep: i-grep ang iba pang role-code `MANAGER` comparisons (`PayrollAttendanceService`, `EmployeeAttendanceService`, `PayrollCutoffExportService` long lines) at ayusin ang maliliit na same-class; ilista ang malalaki.
- Apply sa localhost `pmbsi_hris` ayon sa standing workflow (fresh mysqldump + verified restore; tests pasado; migration up/re-run/down/up). I-check na walang nagbago na business rows.
- Vault: Progress.md, 02-Modules (Employees / Org-Structure), Data-Model.md (role + position), Business-Rules.md (BR-887), Decision-Log.md, Open-Questions.md, CHANGELOG.md, Session-Log.md; ang plan na ito ay `done`.
- Files: vault notes lang + maliliit na sweep fixes.
- Dependencies: T4.

### Checkpoint B (final)
- [ ] Lahat ng acceptance sa SPEC-retail-manager.md Success Criteria
- [ ] Final report: Fixed / Found and also fixed / Found but left alone / Not verified + vault files changed
- [ ] Walang `git push`/deploy; local commit lang kung sabihin ni John

## Critical files
- `database/migrations/20261104_032_retail_manager*.sql` (bago)
- `app/EmployeeRepository.php` (`validateImmediateManager`, `managerCandidates`, `isAdl`)
- `index.php` ~1549-1580 (Reporting To select + script)
- `app/EmployeeDirectoryPlacement.php:78`
- `app/EmployeeApproverAssignmentService.php:26-31`
- `app/TicketViewerService.php:96`
- `tests/adl_reporting_test.php`, `tests/retail_manager_test.php` (bago)

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Bagong role = permissions change | Med | Clone ng MANAGER grants lang, walang bagong permission; role na umiiral na ay hindi ginagalaw; down ay guarded. Inaprubahan ni John ang bagong role (4 Nov). |
| Strict rule para sa ADL ay humaharang sa ADL na may ibang manager | Low | 0 ADL ang may manager ngayon (nasuri); Unassigned pa rin ay pwede. |
| Role code `MANAGER` hard-coded sa ibang lugar | Med | T3 + T5 sweep; ilista ang iiwan. |
| Retail Manager na walang account ay hindi makapag-approve | Low | Kapareho ng ibang approver: kailangan ng active linked account (existing message). |

## Open (hindi hinaharangan ang build)
- Pangalan ng role sa HR screen: "Retail Manager" (default). Sabihin kung iba.
- Kapag may pangalawang Retail Manager sa future at gustong hatiin ayon sa area: hiwalay na slice (hindi kasama ngayon).

## Verification
- `php scripts/check.php task` bago ibalik; `full` na may `HRIS_TEST_DB` bago i-ship.
- Isolated-DB tests, migration up/re-run/down/up, restore check ng mysqldump.
- Browser: Employee 201 ng ADL (Reporting To = Retail Managers lang), Directory > Department Managers.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-04-retail-manager.md (spec: SPEC-retail-manager.md). Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
