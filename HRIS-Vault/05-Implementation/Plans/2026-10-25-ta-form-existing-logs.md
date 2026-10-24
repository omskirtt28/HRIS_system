---
title: TA form existing logs
type: plan
status: done
tags: [plan, attendance, ta, bugfix]
updated: 2026-10-25
---

# File TA form: gamitin ang existing logs, dalawang missing lang ang tatanungin

Status: done 2026-10-25 (approved by John: "eto yung need i fix", 2026-10-25)
Session: [[Session-Log]] · Related: [[Attendance-and-Timekeeping]], [[Business-Rules]], [[Cutoff-Checklist]]

## Context
Sa Check Attendance (Oct 12, employee 17845) ipinapakita ang Time In 12:17 PM at Break Out 5:08 PM, at Missing ang Break In at Time Out. Pero pagbukas ng File TA form para sa parehong araw, ang "Existing logs" ay Missing lahat at apat na time input ang blangko. Dapat dalawang input lang (Break In, Time Out) ang hihingin at hindi na kailangang i-type ulit ang may log na.

## Sanhi (mula sa code, hindi pa nasubukan sa data)
- Ang **Check Attendance** ay gumagamit ng display-only na `AttendanceSlotDisplay::assign()` kapag AMBIGUOUS ang punches (`app/EmployeeAttendanceService.php` ~L190-197), kaya lumalabas ang 12:17 at 5:08.
- Ang **File TA form** ay tumatawag ng `EmployeeAttendanceService::requestTimes()` (L10-37). Ito ay `attendance_daily.original_*` lang o `PayrollCalculator::assign()['original']` ang binabasa. Ang `assign()` ay naglalagay ng `null` sa slot na may higit sa isang posibleng mapping (`PayrollCalculator.php` L63-64). Ang dalawang punch na `IN`/`OUT` ay puwedeng tumugma sa maraming slot, kaya null ang lahat.
- Resulta: `$taContext['values']` ay walang laman, kaya "Missing" ang lahat sa form (`index.php` ~L937-946), at hindi hide ang mga input.
- Mapanganib din ito sa submit: `PayrollRepository.php` ~L202-206 ay gumagamit ng parehong `requestTimes()`; ang times na nasa context lang ang binabalewala.

## Gagawin
1. **Reproduce muna** sa isolated DB (`run-hris-system`): gumawa ng employee na may dalawang punch (12:17 IN, 17:08 OUT) sa isang araw. Kumpirmahin na iyon ang sanhi bago mag-code. Kung ibang sanhi ang lumabas, i-update ang plano.
2. **Isang source of truth** para sa existing logs: gawing gumagamit ang `requestTimes()` ng parehong placement na nakikita ng employee sa Check Attendance (`AttendanceSlotDisplay`), pero **para sa display at sa pagtukoy kung aling slot ang missing lang**. Walang times na iimbentuhin at walang isusulat sa `attendance_daily`.
3. **Form** (`index.php` ~L935-950 at ang JS na nagre-refresh ng context): ipakita ang existing times, itago at i-disable ang mga input na may log, at ang missing lang ang required. Ang mensahe ay "Add missing Break In, Time Out."
4. **Submit guard** (`PayrollRepository.php` ~L202-206): gamitin ang parehong context para hindi ma-overwrite ng TA ang device punch at para tumanggi kung wala nang missing.
5. **Payroll safety**: walang pagbabago sa `PayrollCalculator::assign()`, snapshot, o finalized attendance. Kung kailangang baguhin ito, huminto at magtanong muna.
6. **Tests** (`backend-design:testing-with-discernment`): ambiguous na dalawang punch (ang kaso ni Oct 12), tatlong punch, isang punch, apat na punch (walang TA), walang punch, at araw na may approved TA. Patunayan na hindi nagbabago ang computed original at payroll.
7. **UI check**: screenshot ng form bago at pagkatapos (Employee, 1440px at 390px).

## Open question (para kay John)
- Ang display ay naglalagay ng 5:08 PM sa **Break Out**. Posibleng Time Out talaga ito. Itong plano ay sinusunod ang kasalukuyang display para magkapareho ang dalawang screen at hindi manghula. Kung gusto mong baguhin ang placement rule (halimbawa, ang huling punch ay Time Out), sabihin muna dahil nakakaapekto ito sa payroll. Ilagay sa [[Open-Questions]].

## Guardrails
- Hindi ginagalaw ang live `pmbsi_hris` hangga't hindi pasado ang lahat ng check sa CLAUDE.md (dump at restore, isolated tests). Walang migration inaasahan; kung kailanganin, sundin ang migration up/re-run/down/up.
- Walang git push.

## Execute prompt (i-paste sa PowerShell)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute ang plano sa HRIS-Vault/05-Implementation/Plans/2026-10-25-ta-form-existing-logs.md. Basahin muna ang CLAUDE.md, ang plano, Session-Log.md at ang Attendance-and-Timekeeping at Cutoff-Checklist notes. Gamitin ang hris-patch, backend-design:debugging-discipline, backend-design:testing-with-discernment, backend-design:security-discipline at run-hris-system skills. Una, i-reproduce ang bug sa isolated DB (hindi ang live pmbsi_hris) at kumpirmahin ang sanhi bago mag-code. Ayusin para ang File TA form ay gumamit ng parehong existing logs na nakikita sa Check Attendance, ipakita ang existing times, itago ang mga input na may log, at ang missing lang ang hingin. Walang imbentong oras, walang sulat sa attendance_daily, at walang pagbabago sa PayrollCalculator::assign o finalized attendance. Maglagay ng tests para sa 0, 1, 2 (ambiguous), 3 at 4 na punches at sa araw na may approved TA. Mag-screenshot bago at pagkatapos. Sundin ang test-then-apply workflow ng CLAUDE.md at huwag mag-apply sa pmbsi_hris kung may fail. Vault sync (Progress, Attendance-and-Timekeeping, Business-Rules, Open-Questions, CHANGELOG, Session-Log), i-tick ang plano, at ilista ang mga vault file at skill na ginamit sa final report. Lokal na commit lang, walang git push."
```
