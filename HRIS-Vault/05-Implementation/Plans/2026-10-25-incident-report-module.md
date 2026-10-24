---
title: Incident Report module
type: plan
status: executing
tags: [plan, incident, tickets, ui, ux]
updated: 2026-10-25
---

# Incident Report (IR): hiwalay na module na may case thread

Status: executing, I1 done (John answered the three rule questions, 2026-10-25; design and architecture delegated to Claude)
Session: [[Session-Log]] · Hub: [[Employee-Tickets]] · UI rules: [[Design-System]] · Rules: [[Business-Rules]] (BR-726, BR-744, BR-761)
Depends on: [[2026-10-25-ticketing-ui-ux-redesign]] P1 (shared components) at ang Design-System; hindi kailangang hintayin ang P2.

## Context
Ngayon ang IR ay isang ticket type sa "File an HR Request": walang approval steps, restricted na feed, at isang flat na comments list (ALL / STAFF_ONLY). Gusto ni John na gawin itong sarili niyang module: may mga kasama sa case (CC), may thread na may usapan ang bawat isa, may laman ang bawat case (ano ang nangyari), at si HR ang magmamarka ng status hanggang ma-solve. Gusto rin niya ng napakagandang UI/UX na pare-pareho sa buong HRIS. Tatanggalin ang Incident Report sa listahan ng File an HR Request.

## Desisyon ni John
1. **Visibility**: ang nag-file, si HR, ang mga isinama ni HR, at ang mga CC na pinili ng nag-file lang ang nakakabasa ng case at thread. Walang ibang nakakakita (kasama ang viewers; BR-726 nananatili).
2. **Sino ang puwedeng mag-file**: lahat ng user at account.
3. **Participants**: pinipili ng nag-file kung sino ang kasama (CC, sangkot, witness); si HR ay puwedeng magdagdag o magtanggal.
4. **Status flow**: 5 hakbang, imamarka ni HR.

## Mga desisyong ako ang pumili
- **Panatilihin sa ticket engine** (type `INCIDENT_REPORT`) para magamit ang numbering, audit, attachments, protected files at notifications; magdagdag ng mga bagong table at hiwalay na pages at sidebar entry. Hindi bagong engine.
- **Status**: `SUBMITTED` → `UNDER_REVIEW` → `IN_PROGRESS` → `RESOLVED` → `CLOSED`, at `DISMISSED` na may kailangang dahilan. Si HR lang ang nagpapalit; may remarks sa bawat pagbabago. Puwedeng i-reopen ng HR ang Resolved o Closed na may remarks. Puwedeng i-cancel ng nag-file ang `SUBMITTED` pa lang (umiiral na).
- **Participant roles** (label sa chip): Reporter, CC, Involved, Witness, HR Handler. Hindi nakikita ng isang participant ang role label ng iba kung HR lang dapat (halimbawa "Involved"): ipakita ang pangalan na walang label sa mga hindi HR, maliban sa Reporter at CC. Pinal na rule ay ilalagay sa Business-Rules bilang assumption na ipapakita kay John.
- **Thread**: mga mensahe ng lahat ng participants (visibility `ALL` = lahat ng nasa case) at **Internal note na HR-only** (`STAFF_ONLY`, umiiral na). Ang remarks ni HR sa pagbabago ng status ay lumalabas bilang "HR update" sa thread. Walang pag-edit o pag-delete ng mensahe; audit lang.
- **Laman ng case**: pamagat, kailan nangyari (petsa at oras), saan (branch/lugar), kategorya, ano ang nangyari, at attachments. Ang kategorya ay controlled list (hal. Workplace conduct, Safety, Property, Attendance-related, Other); ang listahan ay ipapakita kay John.
- **UI** ay gagamit ng shared tk- components (header card, stepper, timeline, avatar, chip, composer) para pare-pareho sa Ticketing redesign. Ilalagay sa [[Design-System]] ang bagong "Case thread" pattern para magamit din ng ibang module.
- **Mga hindi ginagalaw**: BR-744/761 (walang IR sa queue, report at CSV maliban sa count) ay nananatili; ang Ticketing Report at HR dashboard ay count lang pa rin.

## Mga screen
| Screen | Para kanino | Laman |
|---|---|---|
| Sidebar: **Incident Reports** (papalit sa "Incident Feed") | lahat ng user | My Incidents, File an Incident; HR: Incident Cases |
| **File an Incident** | lahat | maikling form sa isang pahina: ano ang nangyari, petsa at oras, lugar, kategorya, participants picker (hanapin ang empleyado, piliin ang role: CC, Involved, Witness), attachments, paalalang confidential |
| **My Incidents** | nag-file at participants | mga case na kasali ako, status chip, huling update, bilang ng bagong mensahe |
| **Case page** | participants at HR | header (case no., status, stepper na 5 hakbang), thread na pangunahing bahagi, participants rail (avatar, pangalan, role), buod ng case, attachments, timeline. Sticky composer. HR panel: palitan ang status na may remarks, magdagdag o magtanggal ng participant, internal note |
| **Incident Cases (HR)** | HR | tabs ayon sa status na may bilang, search, filter ayon sa kategorya, branch at petsa, aging |

Aalisin ang Incident Report sa listahan ng uri sa File an HR Request (`TicketTypes`, `ticket_new_page`/`ticket_service_select`), at ang lumang `hr-incidents` feed ay papalitan; ang mga lumang link ay magre-redirect.

## Data (kailangan ng migration; sundin ang migration-safety)
- `incident_details` (ticket_id PK/FK, incident_at, location_text, branch_id nullable, category) para hindi na nasa `payload_json` ang mga field na ito.
- `incident_participants` (ticket_id, user_id, employee_id nullable, role, added_by, added_at; unique(ticket_id,user_id); index sa user_id para sa "My Incidents"). Walang soft delete: ang pagtanggal ay delete na may audit event.
- Dagdagan ang mga bagong status sa enum/lookup ng `tickets.status` kung enum ito (i-verify sa `TicketPolicy::TRANSITIONS` at schema); update ang `TicketPolicy` at `TicketTypes::label` para sa IR lang.
- Access: ang `TicketRepository::find()` at listing (L48, L126) ay dadagdagan ng participant check para sa IR; viewers (`TicketViewerService`) ay mananatiling hindi kasama. Walang gagamit ng `ticket.sensitive.view` bilang gate ng mga participant. Isara ang [[Open-Questions]] Q51 ayon dito.
- Data na umiiral: i-check muna (read-only) kung may lumang IR sa live DB at i-migrate ang laman nito sa bagong table nang walang pagbabago sa orihinal na row.

## Phase (isang slice kada session)
- [x] **I1 Design at data** (done 2026-10-25, migration 017 applied; 111/111 incident tests; see [[Progress]]): isulat sa vault ang rules (bagong BR numbers), Data-Model, Screens, API-Surface, Design-System "Case thread" pattern; migration (up/re-run/down/up, down guarded), TicketPolicy at access, participants at status service, notifications (walang private text), tests kasama ang access matrix (reporter, CC, HR, hindi kasali, viewer, accounting) at privacy.
- [ ] **I2 Employee UI**: sidebar, My Incidents, File an Incident (participants picker), alisin ang IR sa File an HR Request at i-redirect ang lumang feed.
- [ ] **I3 Case page**: thread, participants rail, stepper, HR panel (status, participants, internal note), responsive at accessibility.
- [ ] **I4 HR Incident Cases at polish**: HR queue, dashboard at report count na tumutugma, final screenshots ng bawat role, dark mode, a11y pass.

## NEEDS VERIFICATION
- Retention, confidentiality at due-process na patakaran para sa incident at disciplinary records (batas at policy ng kompanya): hindi ko ito ituturing na tiyak. Ilagay sa Open-Questions para kay John at HR; walang hard-code na panahon ng retention.
- Kung puwedeng makita ng isang CC na tinanggal na ng HR ang mga lumang mensahe: ipinapalagay na hindi na makakabasa pagkatanggal. Ipakita kay John sa Decision-Log.

## Skills
`hris-patch`, `backend-design:think-before-coding`, `backend-design:data-modeling-discipline`, `backend-design:migration-safety` at `backend-design:review-migration`, `backend-design:auth-and-authorization`, `backend-design:security-discipline` (bawat diff), `backend-design:testing-with-discernment`, `design:design-system`, `design:accessibility-review`, `run-hris-system`, `code-review`.

## Verification (bawat phase)
- Test-then-apply ng CLAUDE.md: bagong dump at verified restore, isolated DB tests, migration up/re-run/down/up; apply sa `pmbsi_hris` lamang kapag pasado ang lahat; huwag mag-apply kung may fail; walang git push.
- Access matrix test: ang hindi kasali, viewer, accounting at ibang empleyado ay hindi nakakabasa ng case, thread, attachment o file. Ang internal note ay HR lang. Ang notification ay walang laman ng case.
- Screenshots sa isolated DB: Reporter, CC, HR at hindi kasali; light at dark; 1440px at 390px.
- Vault sync bago ang final report.

## Execute prompt (i-paste sa PowerShell, isang phase kada session)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute phase I1 (palitan ng I2, I3, I4 sa susunod na session) ng plano sa HRIS-Vault/05-Implementation/Plans/2026-10-25-incident-report-module.md. Basahin muna ang CLAUDE.md, ang plano, Session-Log.md, Employee-Tickets.md, Design-System.md, ang BR-726, BR-744 at BR-761 sa Business-Rules.md, at Open-Questions Q51. Gamitin ang hris-patch, backend-design:think-before-coding, data-modeling-discipline, migration-safety, review-migration, auth-and-authorization, security-discipline, testing-with-discernment, design:design-system at run-hris-system skills. Panatilihin ang Incident Report sa ticket engine at magdagdag ng incident_details at incident_participants. Visibility: nag-file, HR, mga isinama ni HR, at mga CC na pinili ng nag-file lang; viewers, accounting at ibang empleyado ay hindi. Status: SUBMITTED, UNDER_REVIEW, IN_PROGRESS, RESOLVED, CLOSED, at DISMISSED na may dahilan; HR lang ang nagpapalit. Mag-test ng access matrix at privacy sa isolated DB (hindi ang live pmbsi_hris). Sundin ang test-then-apply workflow ng CLAUDE.md: bagong mysqldump na may verified restore, migration up/re-run/down/up na may guarded down, apply sa pmbsi_hris lamang kapag pasado ang lahat, at i-check na walang nagbagong business rows. Huwag i-hard-code ang anumang retention o legal na panuntunan; ilagay sa Open-Questions bilang NEEDS VERIFICATION. Isang phase lang bawat session. Vault sync (Progress, Business-Rules, Data-Model, Screens, API-Surface, Design-System, Employee-Tickets, Decision-Log, Open-Questions, CHANGELOG, Session-Log), i-tick ang phase sa planong ito, at ilista ang mga vault file at skill na ginamit sa final report. Lokal na commit lang, walang git push."
```
