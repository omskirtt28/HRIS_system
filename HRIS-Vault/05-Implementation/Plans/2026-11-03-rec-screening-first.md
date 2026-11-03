---
title: Recruitment flow - Screening first
type: plan
status: done
tags: [plan, recruitment]
updated: 2026-11-03
---

# Recruitment flow: Screening first, no "Applied" dot

Status: done (2026-11-03)
Date: 2026-11-03
Session: [[Session-Log]]
Spec: `SPEC-stage-flow.md` (repo root)

## Context
Masyadong mahaba ang tracker para kay HR. Dapat Screening agad pagkatapos mag-apply, tapos Interview. Option A: itatago ang APPLIED stage (active=0), hindi buburahin; ang lumang applications na nasa APPLIED ay tratong Screening. Walang business rows na babaguhin.

## Mga gagawin
1. **Migration (config row only):** `UPDATE recruitment_stages SET active=0 WHERE code='APPLIED'`. Down: set active=1 (guarded, hindi galawin ang applications/history). Up, re-run, down, up.
2. `RecruitmentRepository::submitApplication` (line 39): stage lookup `code="SCREENING"` imbes na APPLIED; history row same text.
3. Legacy APPLIED rows: ang `stages()` ay hindi na magbabalik ng APPLIED, kaya i-map sa SCREENING ang `stage_code`/`stage_name` sa queries na naglo-load ng applications (`applications()`, `application()`, `stageCounts()`, dashboard counts). Isang SQL expression, `IF(s.code="APPLIED","SCREENING",s.code)`, hindi paulit-ulit na logic. Kung may helper na, gamitin.
4. `nextStageCode()` (line 97): APPLIED tinatrato bilang SCREENING, kaya INTERVIEW ang susunod.
5. Tracker view (hanapin gamit ang grep sa index.php ~line 1696, `$stages` ang pinagkukunan): 7 dots na. Kung hardcoded ang Applied, alisin.
6. Careers status page (`RecruitmentRepository.php` line 60-62): ipakita ang Screening; history rows na may Applied ay dapat may label pa rin (LEFT JOIN, huwag mag-blank).
7. Fix sweep: grep ng `APPLIED` / `'Applied'` sa recruitment-related files lang (functions.php:47-51 badge map ay okay; ang Payroll/Attendance na APPLIED ay ibang bagay, huwag galawin).
8. Test: `tests/rec_screening_first_test.php`.
9. Vault sync: Recruitment module note, Business-Rules.md, Decision-Log.md, CHANGELOG.md, Progress.md, Session-Log.md, Data-Model.md (stage active flag), Open-Questions.md.

## Critical files
- app/RecruitmentRepository.php (lines 23, 39, 60-62, 95-100, 149)
- index.php (~1660-1700 hr-applicant, ~2337 dashboard stage list)
- app/functions.php:47-51 (labels only, likely no change)
- migrations/ (new file, one UPDATE)
- tests/rec_screening_first_test.php (new)

## Risks
- Kanban (index.php:1668) groups by `stage_code`; kung hindi namap ang legacy APPLIED, mawawala sila sa board. Test na ito.
- Stage counts total dapat pareho bago/pagkatapos.
- Migration ay gumagalaw ng recruitment_stages row, kaya need ng fresh dump + restore check bago i-apply sa pmbsi_hris (hris-patch rules).

## Verification
- Test: bagong application -> SCREENING, history row tama.
- Test: stages()/tracker walang Applied; legacy APPLIED app -> Screening sa board, counts, at nextStageCode = INTERVIEW.
- Test: stageCounts total unchanged.
- Re-run: interview_invite_test, interview_chain_test, rec_endorse_manager_test.
- `php scripts/check.php task`; browser check ng Applicant Profile at Careers status sa isolated DB (run-hris-system).
- Walang git push, walang deploy.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute the approved plan in HRIS-Vault/05-Implementation/Plans/2026-11-03-rec-screening-first.md. Gamitin ang hris-patch skill. Sundin ang CLAUDE.md: backup at restore check, isolated test DB, migration up/re-run/down/up, apply sa pmbsi_hris kapag pasado lahat. Vault sync bago ang final report. Walang git push."
```
