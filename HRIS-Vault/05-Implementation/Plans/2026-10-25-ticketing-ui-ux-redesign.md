---
title: Ticketing UI/UX Redesign
type: plan
status: approved
tags: [plan, ui, ux, tickets]
updated: 2026-10-25
---

# Ticketing UI/UX Redesign

Status: approved (John delegated the design decisions, 2026-10-25)
Session: [[Session-Log]] · Module: [[Employee-Tickets]] · Rules: [[Design-System]], [[Screens]]

## Context
Pangit at walang dating ang ticket screens (screenshots 2026-10-25 02:21 at 02:34). Gusto ni John na user friendly at high-end ang UI/UX para sa LAHAT ng may access sa ticketing: Employee, Approver (Manager/ADL), HR, Viewer (ADL/SC/OIC/HO Manager), Accounting, HR Admin.

### Nakita sa code at screenshots
1. **Payroll Concern detail** (`app/TicketViews.php` `ticket_detail_page`) ay luma pa: walang header card, stepper, HR response card o chat bubbles. Iba ang itsura kumpara sa ibang types (`app/TicketServiceViews.php` `ticket_service_detail`).
2. **Stepper bug** (`ticket_stepper`): kapag nilaktawan ang In Progress (Pending diretso sa Served) lumalabas ang "3" na parang hindi tapos. Sa ibang kaso check lahat kahit nilaktawan.
3. **Hindi pare-pareho ang spacing** ng cards at headings; malaking blangko sa ilalim ng ilang card.
4. **Raw na text**: approval chain ay `ATM · name · PENDING`; history ay `2026-10-25 02:04:00`.
5. Plain list ang approval chain; walang icon, avatar o empty state na maayos.
6. Ang mga listahan (`tbl`) at File form ay basic din.

## Design decisions (locked)
- **Dalawang column ang detail page**: kaliwa ang header, stepper, HR response, request details, comments; kanan (sticky) ang status, approval chain timeline, attachments, history, at HR action panel. Isang column sa tablet/mobile, may action bar sa baba.
- **Isang layout para sa lahat ng ticket types**, kasama ang Payroll Concern (`ticket_detail_page` ay gagamit na ng parehong components).
- **High-end na itsura** ayon sa [[Design-System]]: puting surface, off-white background, charcoal nav, PMBSI amber bilang accent lang, 12-16px radius, mahinang shadow, Inter, pare-parehong 20-24px padding, line SVG icons (walang emoji), light at dark mode gamit ang tokens.
- **Stepper**: states na `done`, `current`, `skipped` (dash, hindi bilang), `stop`, `warn`, `todo`. Served ticket na nilaktawan ang In Progress ay "skipped", hindi "3".
- **Approval chain** bilang vertical timeline (avatar initials, role, pangalan, status chip, petsa, remarks). Walang raw status codes; gumamit ng `TicketTypes::label()` / `stage_label()`.
- **Petsa** laging `ticket_when()` ("Oct 25, 2026 2:04 AM"), may relative time na tooltip. Walang raw timestamps.
- **Comments**: chat bubbles may avatar initials, composer na sticky sa baba ng card, malinaw na "Staff only" chip.
- **HR response card**: soft amber tint, hindi malakas na dilaw; internal note ay kitang HR-only (lock line icon, dashed border).
- **Empty at loading states** sa bawat card (walang blangkong card).
- **Mga listahan**: status tabs na may bilang, search at filter rail, maayos na table sa desktop at cards sa mobile, status chips, server-side pagination (walang pagbabago sa query logic).
- **File form**: type picker bilang cards na may maikling paliwanag, sticky na action bar, label at error sa tabi ng field, nananatili ang input kapag may error.

## Hindi gagalawin (guardrails)
- Walang pagbabago sa permissions, routing, BR-720..767, notifications, o privacy (internal note HR-only, viewer-only, Incident Report restrictions).
- Walang migration at walang pagbabago sa database. Views at CSS lang (`app/Ticket*Views.php`, `app/TicketViews.php`, `public/assets/tickets.css`, `ticket-report.css`, `hr-ops.css`).
- Panatilihin ang mga string at ids na ina-assert ng tests (hal. "HR response"); kung kailangang baguhin, i-update ang test kasama ng patch at ipakita.
- I-bump ang `tickets.css?v=` na cache-buster.

## Mga phase (isang slice kada session)
- [x] **P1 Foundation** (done 2026-10-25): bagong shared components sa `tickets.css` (header card, status chip, stepper states, key-value grid, timeline, avatar, empty state, composer), date at label helpers, dark mode tokens. Ayusin ang stepper bug.
- [ ] **P2 Detail page**: dalawang column na layout para sa lahat ng types; ilipat ang Payroll Concern sa parehong layout; HR action panel; responsive na action bar.
- [ ] **P3 Employee screens**: My HR Requests (tabs, search, cards/table) at File a Request form (type picker cards, sticky actions).
- [ ] **P4 HR at approver screens**: HR Request Queue, Payroll Concern Queue, HR Request Inbox, Team Requests (viewer), Accounting Follow-up, Incident Feed.
- [ ] **P5 Report, polish at QA**: Ticketing Report at print view, COE screens, dark mode, accessibility pass, final screenshots ng lahat ng role.

## Skills na gagamitin
`hris-patch`, `design:design-system`, `design:accessibility-review` (bagong screens), `dataviz` (P5 report), `run-hris-system` (screenshots), `backend-design:testing-with-discernment` (tests), `backend-design:security-discipline` (bawat diff), `code-review` bago mag-commit.

## Verification (bawat phase)
1. `run-hris-system` sa isolated test DB (hindi ang live `pmbsi_hris`): screenshots ng bawat apektadong screen bilang Employee, Approver, HR, Viewer, Accounting; light at dark; 1440px, 768px, 390px.
2. Patakbuhin ang ticket tests (`tests/ticket_*`, `hr_queue_*`, `payroll_concern_test`, `ticket_hr_response_test`) sa isolated DB. Ipakita ang kahit anong failing test na may patunay na hindi related.
3. Walang horizontal scroll sa 390px; keyboard focus at contrast ay pasado; walang console errors.
4. Walang laman ng database ang nagbago (views at CSS lang). Hindi kailangan ng dump o migration. Walang git push; lokal na commit lang kapag pasado.
5. Vault sync bago ang final report: Progress, Screens, Design-System, Employee-Tickets, Decision-Log, CHANGELOG, Session-Log; ilista ang mga vault file na binago.

## Execute prompt (i-paste sa PowerShell, isang phase kada session)

```powershell
cd C:\xampp\htdocs\HRIS_system
claude "Execute phase P1 (palitan ang P1 ng P2..P5 sa susunod na session) ng plano sa HRIS-Vault/05-Implementation/Plans/2026-10-25-ticketing-ui-ux-redesign.md. Basahin muna ang CLAUDE.md, ang plano, Session-Log.md at Design-System.md. Gamitin ang hris-patch, design:design-system at run-hris-system skills. Views at CSS lang: walang pagbabago sa permissions, routing, privacy o database. Mag-screenshot sa isolated DB bago at pagkatapos, patakbuhin ang ticket tests, at ipakita ang failing tests na may patunay kung unrelated. Isang phase lang bawat session. Vault sync (Progress, Screens, Design-System, Employee-Tickets, CHANGELOG, Session-Log) at i-tick ang phase sa planong ito bago ang final report. Lokal na commit lang, walang git push."
```
