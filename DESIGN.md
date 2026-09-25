# HRIS Design System & UX Rules

**Status:** Baseline v1  
**Initial visual source:** supplied PMBSI Recruitment/RWMS prototype

## 1. Design Goal

The HRIS must look and behave like one product even when many modules are added. Recruitment establishes the first design language. New modules reuse the shell, components, spacing, typography, states, and interaction patterns.

## 2. Visual Direction

The existing Recruitment prototype uses a strong PMBSI visual identity:

```text
Primary amber     #FDAE04
Amber hover       #E39A00
Ink / dark text   #1C1D1F
Slate text        #676B6E
Border            #E4E4E5
Panel             #FAFAFA
Panel secondary   #F4F4F5
Blue/info         #0B5FA5
Green/success     #1B8A5A
Red/error         #C0392B
```

Use semantic tokens rather than hardcoding colors directly inside page-specific CSS.

## 3. Typography

Preferred UI font: **Poppins** or an approved local/system fallback.

Hierarchy:
- Page title: 24–30px, semibold
- Section title: 15–18px, semibold
- Body: 13–14px
- Small/meta: 11–12px
- IDs/reference codes: monospace

Do not use more than two font families in the application shell.

## 4. Application Shell

Internal HRIS modules use one consistent shell:

```text
+----------------+--------------------------------------+
| Sidebar        | Top Bar                              |
|                +--------------------------------------+
| Home           | Page title / breadcrumbs / actions   |
| Recruitment    |                                      |
| Employees      | Main content                         |
| Timekeeping    |                                      |
| ...            |                                      |
+----------------+--------------------------------------+
```

### Sidebar
- module groups are stable;
- active item has obvious highlight;
- icons are paired with text labels;
- badges only show actionable counts;
- responsive drawer behavior on small screens;
- no duplicate navigation item for the same destination.

### Top bar
- global search only if search is truly implemented;
- notifications;
- account/profile menu;
- optional module-level quick action;
- no fake buttons or decorative controls in production.

## 5. Page Anatomy

Every module page follows:

```text
Breadcrumb
Page Title                         Primary Action
Short contextual description       Secondary Action
--------------------------------------------------
Filters / tabs if required
Main data/content
Pagination / summary if required
```

Primary action count should normally be one per page.

## 6. Core Components

Reuse shared components for:
- Button
- Input
- Select
- Textarea
- Date/time picker
- Search field
- Card
- KPI card
- Badge/status pill
- Table
- Pagination
- Tabs
- Modal/drawer
- File uploader
- Timeline/activity feed
- Empty state
- Skeleton/loading state
- Error state
- Toast
- Confirmation dialog
- Kanban column/card

No module should create a second visual implementation of the same component unless the base component cannot meet the requirement.

## 7. Button Hierarchy

### Primary
Used for the main page action, such as:
- Add Applicant
- Create Manpower Request
- Endorse Candidate
- Send Offer

### Secondary
Used for non-destructive supporting actions.

### Danger
Used only for destructive or irreversible actions and requires explicit confirmation.

Never use color alone to convey meaning.

## 8. Recruitment Status Design

Canonical recruitment stages should have stable visual semantics:

| Stage | Intended visual role |
|---|---|
| Applied | Neutral |
| Screening | Information |
| Interview | Attention |
| Endorsed | Accent |
| Client Review | Review |
| Offer | Positive |
| Deployment | In progress |
| Deployed | Success |
| Rejected | Error |
| Withdrawn | Muted |
| On Hold | Warning |

Labels may be configured, but colors must remain accessible and consistent.

## 9. Recruitment Pipeline

Desktop may use Kanban for stage overview. Mobile must use a list/grouped alternative rather than forcing wide horizontal scrolling as the only usable interaction.

Each pipeline card should show only high-value data:
- candidate name;
- position;
- client;
- application/reference number;
- age/time in stage or SLA indicator;
- recruiter/owner where useful.

Do not place sensitive personal data directly on Kanban cards.

## 10. Applicant Profile

Applicant profile uses clear sections:

```text
Header summary
- Name
- Application reference
- Position / client
- Current stage
- Owner / recruiter

Tabs or sections
- Personal Information
- Applications
- Resume & Documents
- Screening
- Interviews
- Endorsements
- Offers
- Deployment Requirements
- Activity Timeline
```

The activity timeline is generated from real events and audit-friendly actions, not free-form duplicate status notes.

## 11. Tables

All major tables must support as applicable:
- server-side pagination;
- server-side filtering;
- sort;
- empty states;
- loading states;
- permission-aware actions;
- stable row IDs;
- CSV export only when authorized.

Large datasets must never be rendered entirely into the browser.

## 12. Forms

Rules:
- label every field;
- mark required fields clearly;
- show validation next to the field;
- preserve user input after validation failure;
- do not ask for information already available from a selected master record;
- group related fields into sections;
- use controlled values for statuses and masters;
- avoid free-text duplicates of clients, branches, departments, and positions.

## 13. File Upload UX

Recruitment uploads may include resumes and pre-employment documents.

UI must show:
- allowed file types;
- maximum file size;
- upload progress;
- virus/security processing state if enabled;
- document category;
- who uploaded it;
- upload date;
- current version.

Do not expose raw storage paths.

## 14. Responsive Rules

Required breakpoints should support:
- desktop operations;
- tablet review;
- mobile applicant portal;
- mobile HR quick review where practical.

Minimum requirements:
- no clipped actions;
- tables degrade to scroll/card patterns;
- forms remain single-column on narrow screens;
- tap targets remain usable;
- dialogs fit the viewport.

## 15. Accessibility

- keyboard-accessible interactive elements;
- visible focus states;
- sufficient contrast;
- form error text connected to fields;
- semantic headings;
- status not communicated through color alone;
- modal focus trapping;
- meaningful button/link names.

## 16. Dark Mode

Dark mode is optional and must use semantic tokens. A module must not manually define its own unrelated dark palette.

## 17. Copy Rules

Use clear English labels in the system UI.

Preferred:
- Applicant
- Application
- Manpower Request
- Job Opening
- Screening
- Interview
- Endorsement
- Client Review
- Offer
- Deployment

Avoid using multiple terms for the same concept, such as switching between “candidate record,” “applicant file,” and “profile” when they mean the same entity.

## 18. UI Truthfulness

Production screens must not contain:
- hardcoded KPI counts presented as live data;
- fake search fields;
- fake toggles;
- buttons that only show demo toasts;
- sample applicants mixed with real records;
- placeholder AI scores represented as factual decisions.

Any AI-generated output must be identified as assistance and remain reviewable by authorized staff.

---

## 2026-09-24 - Modern HRIS Experience Standard

The HRIS must present as a modern enterprise SaaS application, not a traditional PHP administration panel.

### Shared visual system
- PMBSI orange is an accent, not the dominant page color.
- Neutral off-white application background, white surfaces, dark charcoal navigation, restrained shadows, 10-16px radii, and strong whitespace.
- Inter is the preferred UI typeface with system-font fallbacks.
- Use line-style SVG icons, status chips, contextual actions, skeleton/loading states, confirmation modals, drawers, and toast feedback where appropriate.
- Avoid dense Bootstrap-style panels, heavy borders, excessive cards, emoji navigation icons, and browser `alert()` UI.

### Role-specific experience
- **Employee**: self-service first; personal schedule, attendance, leave, requests, documents, performance, training, notifications.
- **HR**: operational workspace; workforce attention items, employee records, attendance, leave, recruitment, employee relations, performance, training, reports.
- **HR Admin**: administration and governance; users, roles, permissions, organization settings, audit logs, security, active sessions, system configuration.
- **Client**: scoped recruitment collaboration only.

The same design tokens and component system are shared by every role, but dashboard density, navigation, quick actions, and information hierarchy change by role.

### Responsive behavior
- Desktop: persistent 264px sidebar + sticky topbar.
- Tablet/mobile: sidebar becomes a drawer; dashboard grids collapse predictably; tables remain horizontally scrollable when necessary.
- User actions must remain reachable without hover-only behavior.

### Meeting preview
`public/meeting-preview.html` is a design-only presentation surface using sample data. It is not a production business-data screen.


## Public Home Page

The public landing page is part of the HRIS product experience and must use the same modern PMBSI design system. It serves three audiences: prospective applicants, current employees, and company/partner visitors.

- Use PMBSI orange as an accent, not as the full-page background.
- Use a dark modern hero with generous whitespace, strong typography, and a product/workforce visual.
- Primary public actions are **Explore opportunities**, **Employee Login**, and **Apply Now**.
- Show PMBSI workforce services in modern cards: Contractual Staffing, Project-Based Staffing, One-Time Placement, and Outsourcing Services.
- Introduce the role-based HRIS experience on the home page so users understand that Employee, HR, and HR Admin have different workspaces.
- Keep the public site visually consistent with the authenticated SaaS interface.
- The home page must be fully responsive and usable on desktop, tablet, and mobile.

## Phase 1 Administration UI Standard

The Foundation administration screens establish the pattern for future HRIS configuration pages:

- use a two-column workspace when a compact create/edit form accompanies a larger data table;
- use searchable/scrollable master lists instead of oversized dashboard cards;
- roles use a left selection rail and a permission matrix on the right;
- status is represented with compact chips, not large banners;
- secondary actions use subtle controls while the primary save/create action remains visually dominant;
- master-data screens must remain usable at tablet/mobile widths by collapsing to a single column;
- do not expose database IDs as the primary visible label; show human-readable code + name combinations;
- destructive deletion is avoided for shared master data; activation/inactivation is the standard control.


## Official PMBSI Branding Assets (Locked · 2026-09-24)
- Full corporate logo: `public/assets/branding/pmbsi-logo-transparent-v2.png`
- HRIS/app mark: `public/assets/branding/pmbsi-mark.png`
- Browser favicon: `/favicon.ico` plus PNG favicon sizes under `public/assets/branding/`
- Apple touch icon: `public/assets/branding/apple-touch-icon.png`
- Use the full corporate logo on the public website and login identity areas. Use the compact PMBSI mark inside the authenticated sidebar and small UI surfaces.
- Do not replace the official mark with text-only `P` placeholders in production UI.

## Full wordmark header rule
The public website header must use the complete PMBSI corporate wordmark at a readable size. The browser favicon and app icon now use the complete PMBSI wordmark scaled inside a square canvas, per the approved branding direction. The compact PMBSI mark remains available for collapsed navigation and other optional compact UI surfaces.


## Logo Consistency Rule
Use the full PMBSI wordmark across the public site, login screens, app sidebars, and footer areas unless a technical size constraint explicitly requires a smaller icon. Avoid mixing boxed, glow-heavy, or mark-only variants inside the main application UI.


## Approved Login Experience
The official HRIS login screen uses a split layout: a dark PMBSI brand/benefits hero on the left and a light sign-in card on the right. The four feature cards must include icons, soft glassy dark surfaces, and warm amber accents consistent with the approved PMBSI visual.


## Approved PMBSI Logo Asset
Use the metallic silver-circle PMBSI wordmark (`public/assets/branding/pmbsi-logo-transparent-v2.png`) as the canonical logo for the HRIS. Do not substitute the earlier flat gray logo. Login placement uses the larger wordmark treatment shown in the approved mockup.



## Official Logo Asset Update
Use `public/assets/branding/pmbsi-logo-transparent-v2.png` as the single approved PMBSI full logo across the live interface. Preserve transparency, keep proportions intact, and avoid adding wrapper backgrounds, glow, or non-original shadows.


## Phase 2A Employee UX

The Employee Directory follows the HRIS panel/table language: compact metric cards, a single filter rail, identity-first employee rows, and a dedicated profile view. Add Employee uses a two-column desktop form with a sticky action card, collapsing to one column on smaller screens. The profile tab rail previews the 201-file information architecture while unfinished tabs stay visibly marked as `Soon`.


## Phase 2B 201 File UX

The Employee Profile tab rail is now functional: Overview, Personal Info, Employment, Government IDs, Emergency Contact, Documents, History, and Audit Trail. Editing stays inside the employee context instead of opening unrelated screens. Employment changes require an effective date and optional remarks so the resulting history is understandable. Document rows keep actions compact and profile/photo controls avoid disrupting the main information hierarchy.

## Phase 2C UX
The recruitment-to-employee conversion uses a review-first workflow: source recruitment summary, employee identity, employment assignment, portal-access option, document transfer, and a final confirmation card. Converted applications become read-only for recruitment actions and expose a direct link to the Employee 201 File.

