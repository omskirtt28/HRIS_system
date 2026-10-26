---
title: Design System
type: design
status: built
tags: [design, ui, branding]
updated: 2026-10-25
---

# Design System

## Purpose

UI and branding rules for every HRIS screen. Moved here from the retired repo-root `DESIGN.md` on 2026-10-25 (that file now only points here). Screen inventory and layout rules: [[Screens]]. Ticket screens: [[Employee-Tickets]].

## Principles

- One product, one shell: sidebar + sticky top bar, page anatomy (breadcrumb, title and one primary action, short description, filters/tabs, content, pagination). Reuse shared components (button, input, select, KPI card, status pill, table, tabs, modal/drawer, uploader, timeline, empty/loading/error states, toast, confirmation dialog); no second visual implementation of the same component.
- Modern SaaS look, not a PHP admin panel: neutral off-white background, white surfaces, dark charcoal navigation, restrained shadows, 10-16px radii, whitespace. PMBSI orange is an accent, never the page colour. No dense Bootstrap panels, emoji icons or browser `alert()`; use line SVG icons, chips, drawers, toasts.
- UI truthfulness: no hard-coded KPIs presented as live, no fake search/toggles/buttons, no demo toasts, no sample records mixed with real ones, no placeholder AI scores. `public/meeting-preview.html` is design-only sample data and never a data source.
- Server is the control: hide unavailable actions for UX but enforce on the server (BR-113, BR-721). Destructive actions need confirmation; every save shows success or error; validation keeps the user's input.

## Tokens

| Token | Value |
|---|---|
| Primary amber | `#FDAE04` (hover `#E39A00`) |
| Ink / slate text | `#1C1D1F` / `#676B6E` |
| Border / panel / panel 2 | `#E4E4E5` / `#FAFAFA` / `#F4F4F5` |
| Info / success / error | `#0B5FA5` / `#1B8A5A` / `#C0392B` |

Use semantic tokens, never raw colours in page CSS. Dark mode is optional and must reuse the tokens (the ticket screens support light and dark). Type: Inter preferred with system fallbacks (verified in `public/assets/*.css`: Inter, with "Plus Jakarta Sans" in one sheet; Poppins in the old DESIGN.md is not used); body 13-14px, meta 11-12px, IDs/reference codes monospace; at most two families in the shell. Never convey meaning by colour alone.

## Shell and responsive rules

Desktop: persistent 264px sidebar + sticky top bar. Tablet/mobile: sidebar becomes a drawer, grids collapse, tables scroll or become cards, forms are single column, tap targets stay usable, no hover-only actions, dialogs fit the viewport. Sidebar: stable module groups, icon + label, badges only for actionable counts, no duplicate destinations. Tables: server-side pagination/filter/sort, empty and loading states, stable row IDs, CSV only when authorised. Forms: label every field, mark required, error next to field, do not ask for what a master record already knows, controlled values for statuses and masters. Accessibility: keyboard reachable, visible focus, contrast, semantic headings, error text tied to fields, modal focus trap, meaningful names.

## Role experience

Employee: self-service first. HR: operational workspace. HR Admin: users, roles, permissions, organisation, audit, security. Client: scoped recruitment collaboration only. Same tokens and components for all; density and navigation differ. Admin screens: two-column workspace (compact form + table), roles as left rail + permission matrix, status as compact chips, no database IDs as visible labels, inactivate rather than delete shared master data.

## Recruitment screens

Stages have stable visual roles (Applied neutral, Screening info, Interview attention, Endorsed accent, Client Review review, Offer positive, Deployment in progress, Deployed success, Rejected error, Withdrawn muted, On Hold warning). Pipeline: Kanban on desktop, grouped list on mobile; cards show candidate, position, client, reference, time in stage, owner; no sensitive personal data on cards. Applicant profile sections: summary header, personal information, applications, documents, screening, interviews, endorsements, offers, deployment requirements, activity timeline built from real events. Upload UI shows allowed types, max size, progress, category, uploader, date, version; never raw storage paths. Copy: one term per concept (Applicant, Application, Manpower Request, Job Opening, Screening, Interview, Endorsement, Client Review, Offer, Deployment).

## Branding (locked 2026-09-24)

Use only assets in `public/assets/branding/`: full wordmark `pmbsi-logo-transparent-v2.png` (the metallic silver-circle logo; keep transparency and proportions, no wrapper backgrounds or glow) on the public site, login, sidebars and footers; `pmbsi-mark.png` only where space forces a compact mark; favicon `/favicon.ico` and PNG sizes, `apple-touch-icon.png`. Never reintroduce text-only "P" placeholders. Login: split layout, dark brand/benefits hero left with four icon cards, light sign-in card right. Public home: dark hero, actions Explore opportunities / Employee Login / Apply Now, service cards (Contractual Staffing, Project-Based Staffing, One-Time Placement, Outsourcing Services), explains the role-based workspaces, fully responsive.

## Employee screens (Phase 2)

Directory: compact metric cards, one filter rail, identity-first rows, profile view; Add Employee is a two-column form with a sticky action card. Profile tab rail: Overview, Personal Info, Employment, Government IDs, Emergency Contact, Documents, History, Audit Trail; employment changes need an effective date and optional remarks. Recruitment-to-employee conversion is review-first (source summary, identity, assignment, portal-access option, document transfer, final confirmation); converted applications become read-only and link to the employee.

## Ticket components (P1, 2026-10-25)

Shared in `public/assets/tickets.css` (prefix `tk-`, tokens only, light and dark): `tk-card` (22px padding, 16px radius, soft shadow), `tk-chip` / `tk-pill` tones green, red, amber, blue, gray; stepper `tk-step` states `is-done`, `is-current`, `is-skipped` (dashed, dash mark), `is-stop`, `is-warn`, `is-todo`; `tk-kv` key-value grid; `tk-timeline` with `tk-avatar` initials; `tk-empty`; `tk-composer`. PHP helpers in `app/TicketServiceViews.php` return escaped HTML: `ticket_time` (readable date, relative time as tooltip), `ticket_status_chip`, `ticket_timeline`, `ticket_avatar`, `ticket_empty`, `ticket_kv`. Rules: never print a raw status code or timestamp; a step a request never went through is "skipped", never a number; timeline remarks and acting-HR names stay off unless the screen already shows them (view-only users must not see the HR response).

## Case thread pattern (defined in I1, built in I2 to I3 of the Incident Report plan)

A reusable layout for a confidential case with a conversation: a header card (case number, status chip, 5-step stepper `tk-step`), the thread as the main column (messages from every reader, HR updates as a distinct `tk-response` card, internal notes as a separate amber `tk-internal` block visible only to the handling reviewer), a participants rail (avatar, name; the role chip is shown for Reporter and CC only, other labels for the handling reviewer only), a case summary card (when, where, category, branch), attachments, a timeline, and a sticky composer. Every private-by-role element must be absent from the markup for other readers, not hidden with CSS. Uses only the shared `tk-` components and tokens; no new colors. Rules for who sees what: BR-769 to BR-775. Built in I2 (lists, filing) and I3 (the case page, `app/IncidentCaseViews.php`).

### Incident screens, I2 (2026-10-25)

Built on the shared components with tokens only, in `public/assets/tickets.css` (v=20261025-18): `tk-case-list` / `tk-case` (a case as a `tk-card` with title link, meta line, status chip and a gray `tk-chip` for "You reported this" or "Shared with you"), `tk-confidential` (amber note, role=note), `tk-form`, and the people picker `tk-picker` with `tk-results` / `tk-result-btn` (44px targets) and `tk-people` / `tk-person` (name, role select, Remove). Accessibility rules used: every control has a label, the search status is a polite live region, the picker works from the keyboard (Enter on a result adds the person and moves focus to their role select), removing announces itself, focus rings use the brand outline, and nothing scrolls sideways at 390px. The picker is built with DOM text nodes, never innerHTML.

### Case page, I3 (2026-10-25)

Tokens only, in `public/assets/tickets.css` (v=20261025-20): `tk-case-grid` (conversation column plus a 340px rail, one column under 900px), `tk-thread` / `tk-post` (avatar, bordered body, own messages in the info tone, a Reporter, CC or HR chip), `tk-post-update` wrapping the shared `tk-response` (HR update) and `tk-internal` (HR-only note), `tk-sticky` composer (sticky at the bottom; under 650px the help text becomes screen-reader-only and the box shrinks), `tk-rail-person`, `tk-hr-panel` with `tk-hr-block` sections, `tk-sub`. Rules: every control labelled, 44px targets, the conversation is a `role=log` list, the stepper keeps `aria-current` and a screen-reader status per step, the HR search results are DOM text nodes (no innerHTML), removing a person asks for confirmation. Full-page screenshots must switch the sticky composer to static or it paints over the thread.

## Delivery rule for every feature patch

Ship the user-facing UI with the function, in simple English, clear next actions, readable spacing, narrow-screen layouts; keep employment status separate from missing-detail notices; badges stay on one line. Report honestly when visual testing was not done. The repo-root `UI_UX_PATCH_GUIDELINES.md` named in the old RULES.md does not exist (unverified whether it was ever committed).

## Related

[[Screens]] · [[Employee-Tickets]] · [[Conventions]] · [[Recruitment-and-Onboarding]] · [[Employee-Records]] · [[Auth-and-RBAC]]

## Dark-mode status text (2026-10-26)
In dark mode the solid hue tokens (`--hris-success/info/danger/warning`) stay as fills; text on chips, pills, badges, alerts and HR queue notes uses lighter tints (green #3fc78a, red #ff8590, amber #e0a43a, blue #7aa2f7), at least 4.5:1 on the dark soft fills and surfaces, scoped under `:root[data-theme="dark"]`. Light theme (Q62, 2026-10-26): `--hris-success #137a50`, `--hris-info #2560d6`, `--hris-danger #c2364a`, `--hris-warning #8f5a00` (4.8:1 or better on their soft fills and white text) and `--hris-muted #676c74` (4.7:1 or better on page, surface and soft fills). The dark block re-declares the original solid hues so dark is unchanged (verified: 0 computed-style differences on 13 pages). Sidebar group label `#7b8290`, "Soon" chip `#8f96a0`.
