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
