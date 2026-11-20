# design.md: UI conventions for the MOH Medication Procurement System

Version 0.7.3 (2026-11-18). Source of truth for how screens look and are built.
Read this before touching any React page, layout or component.

Changes in 0.7.3: (1) the progress strip shows WHERE THE USER IS on the form (position), not what is complete; completion lives in each section's state pill. (2) No "(optional)" suffix on field labels. (3) Title Case for UI labels, headings, buttons and nav; sentence case for helper text and messages. (4) Sidebar items pop on hover (section 6). (5) The sidebar has rounded right corners and rounder items (sections 5 and 6). Section 4 (copy conventions), section 6 and the progress strip row changed.

Changes in 0.7.2: Kyle ruled that the reference image wins over earlier style bans. Gradients are allowed where the image uses them (the sidebar surface and the hero icon tile); the primary BUTTON uses the deeper blue --primary-deep; the type scale follows the sizes measured from the image; the sidebar watermark is required; the workspace background is #f0f4fc; inputs get a very light fill. Accessibility rules are NOT relaxed (see section 11, pale input borders).

Changes in 0.7.1 (this file is v0.6 and v0.7 MERGED into one complete document; the v0.7 draft referred to v0.6 sections, so v0.6 must not be lost): the "Navy & Healthcare Blue Enterprise Theme" replaces the Layered Surface shell. Navy sidebar, white application header, light blue-grey workspace. The L1 canvas card and the brand stripe are retired. The active nav item is a solid MOH-blue pill. New: case hero card (section 7), restyled progress strip, section card header with number badge and state pill. Sections 2, 4, 5, 6, 7, 11, 12 and 13 changed. Sections 1, 3, 8, 9 and 10 are carried over from 0.6 with small additions.

Visual reference: `reference-v0.7.png` in the repo root (untracked), the Medication Procurement Request screen supplied 2026-11-18. It is a generated mockup, NOT a spec. Take layout, hierarchy, colour and depth from it. Section 11 lists what must not be copied. For STYLE (colour, size, depth, layout) the image wins over any earlier rule in this file; for data truth, accessibility and security, this file wins.

Older reference (retired for the shell): the Claude Design canvas "NIPDEC UI - Layered Surface Theme". Its form sections, entry rows and type cards still guide those components.

## 1. Stack decisions (settled, do not re-litigate)

| Area | Decision |
|---|---|
| Transport | Inertia (server-driven). No Next.js, no separate SPA or API layer. |
| UI kit | shadcn/ui on Tailwind CSS 4.0.8 (CSS-first config in resources/css/app.css), Radix primitives, lucide-react icons. |
| Not allowed | DaisyUI, any second component kit, new icon sets, emoji as icons. Do not use @headlessui/react in new work. |
| Theme install | Copy the token values from section 2 into resources/css/app.css by hand. Do NOT run `npx shadcn add` against a third-party theme registry. |
| Dark mode | Light only for launch. Leave the `.dark` variables untouched and unmaintained, and hide the appearance setting. Do not add dark values to the status tokens. |
| Fonts | Roboto (all UI text; variable font, latin subset, weights 400 to 700) and JetBrains Mono (reference numbers in tables, file numbers), SELF-HOSTED. Merriweather is reserved for long-form text (letters, help pages) when those exist. No third-party font CDN or other third-party runtime request on a public-facing system. |
| Packages | Never install a package without asking Kyle. `npx shadcn add` of a component counts, because it can install Radix packages. Approved font packages: @fontsource-variable/roboto and @fontsource-variable/jetbrains-mono. |
| Brand | The Ministry of Health palette (section 2) plus the navy application-shell colour. |

## 2. Colour tokens

Colours exist ONLY as CSS variables in resources/css/app.css and Tailwind token classes (`bg-card`, `text-muted-foreground`, ...). No hex values in components. Keep the existing variable format in app.css (oklch or hsl); the hex values below are the design values to convert from. Do not remove working variables and do not duplicate a token that already serves the same purpose.

### Brand palette

| Name | Hex | Rule |
|---|---|---|
| MOH blue | #006fba | Leads: links, focus ring, active nav item, progress markers. The primary BUTTON uses --primary-deep (#0049aa) to match the reference. White text on it 5.27:1. |
| Navy | #09254b | Application shell: the sidebar. Never replaces MOH blue as an action colour. White on it 15.24:1. |
| Navy deep | #0a1f3d | Sidebar user card and subtle depth inside the sidebar, if needed. |
| MOH sky | #20c4f4 | FILL ONLY: chart segments, stage bars. Never as text or behind text (white on it 2.05:1). |
| MOH lime | #92c83e | FILL ONLY: chart segments, stage bars. Never as text or behind text (white on it 1.99:1). |
| MOH forest | #436718 | Success and secondary actions. White text on it 6.58:1. |

### Token table

| shadcn variable | Hex | Use |
|---|---|---|
| --background | #f0f4fc | workspace background (light blue-grey, sampled from the reference; must stay visibly different from white cards) |
| --card / --popover | #ffffff | cards, form cards, menus, header |
| --muted | #eef3f8 | section panels, tab tracks, progress tracks, disabled field background |
| --foreground | #0d1b2a | body text |
| --muted-foreground | #5a6675 | secondary text (5.85 on white, 5.24 on muted) |
| --border | #dde5ee | card and divider borders (decorative) |
| --input | #7f8b99 | form-field borders (3.47:1 on white). Do NOT lighten to match the mockup's pale input border. |
| --primary | #006fba | primary buttons, links, active indicators |
| --primary-foreground | #ffffff | text on primary |
| --primary-strong | #005a99 | primary button border, link hover, info text (white on it 7.19) |
| --ring | #006fba | focus ring on light surfaces (2px with 2px offset) |
| --ring-wash | #e6f1fa | 3px halo on a focused field |
| --secondary | #eef6e3 | secondary button background |
| --secondary-foreground | #436718 | text on secondary (5.93) |
| --accent | #e4f7fd | sky tint: icon squares, dropzone icon |
| --accent-foreground | #00658a | text and icons on accent (5.89) |
| --selected | #f2f8fd | selected card and selected row |
| --subtle | #f8fafd | table header, dropzone, "Add row" strip |
| --strip | #f6f9fc | stat tile footer strip |
| --divider | #e8eef4 | row dividers inside tables |
| --destructive | #dc2626 | danger buttons (white text 4.83) |
| --destructive-foreground | #ffffff | |
| --destructive-text | #b91c1c | error text (6.47 on white) |
| --destructive-wash | #fee2e2 | 3px halo on an invalid field |
| --chart-1 to --chart-5 | #00658a, #20c4f4, #92c83e, #436718, #0a3d66 | charts and stage bars, in this order |
| --radius | 0.75rem | see section 5 |

### New shell tokens (0.7.1)

| Token | Hex or value | Use |
|---|---|---|
| --navy | #09254b | sidebar background |
| --navy-deep | #0a1f3d | sidebar user card |
| --navy-foreground | #ffffff | text and icons on navy (15.24) |
| --navy-muted | #b4c4dc | secondary sidebar text (8.61 on navy) |
| --navy-border | #2a4a7a | sidebar dividers (decorative, 1.71) |
| --navy-hover | #1d3659 | hover fill on a nav item (white 12.17 on it) |
| --nav-active | var(--primary) | active nav item background |
| --nav-active-foreground | #ffffff | active nav text and icons (5.27) |
| --header | #ffffff | application header surface |
| --header-foreground | var(--foreground) | header text and icons |
| --sidebar-from | #071f42 | top of the sidebar gradient |
| --sidebar-to | #0f3768 | bottom of the sidebar gradient (white on it 11.9, --navy-muted on it 6.7) |
| --primary-deep | #0049aa | primary BUTTON fill (white on it 8.3) |
| --tile-from | #0e77cf | hero icon tile gradient, top |
| --tile-to | #0056bb | hero icon tile gradient, bottom |
| --input-bg | #f4f7fd | input fill (the border stays --input) |

shadcn sidebar variables map to the shell tokens: `--sidebar` = --navy, `--sidebar-foreground` = --navy-foreground, `--sidebar-primary` = --nav-active, `--sidebar-primary-foreground` = --nav-active-foreground, `--sidebar-accent` = --navy-hover, `--sidebar-accent-foreground` = --navy-foreground, `--sidebar-border` = --navy-border, `--sidebar-ring` = white (the MOH-blue ring is nearly invisible on navy; white on navy is 15.24). Expose the new tokens in the Tailwind `@theme inline` block so classes like `bg-navy` and `text-navy-muted` exist. The sidebar surface is a vertical gradient from --sidebar-from to --sidebar-to (measured from the reference); --navy stays the flat fallback and the value used in most contrast checks, and the lighter end (--sidebar-to) must pass too.

Active item boundary: the MOH-blue pill against navy is only 2.89:1, so the active item must NOT rely on colour alone: it also carries a 3px white indicator bar on its left edge, semibold text, and `aria-current="page"`.

Shadows: `shadow-xs` = 0 1px 2px rgba(13,27,42,.04) for cards, tiles and outline buttons. The primary button adds 0 1px 2px rgba(0,111,186,.3) and an inset 0 1px 0 rgba(255,255,255,.18). `--shadow-card` stays as defined in section 5.

Theme rules: MOH blue leads; navy is structure; sky and lime are fills only; forest carries success; gradients ONLY where the reference uses them: the sidebar surface and the hero icon tile, nowhere else (a half-filled progress segment is still two elements); depth comes from layered surfaces, a 1px border and a short shadow. All new token pairs are checked for WCAG 2.1 AA before release.

## 3. Status badge tones

The SERVER decides the tone. DTOs send `status_label` and `status_tone`; React maps tone to classes and never interprets status codes or role names.

| Tone | Background | Text | Contrast | Used for (internal statuses) |
|---|---|---|---|---|
| neutral | #eef3f8 | #5a6675 | 5.24 | DRAFT, CLOSED, WITHDRAWN |
| info | #e6f1fa | #005a99 | 6.27 | SUBMITTED, CMO_INTAKE, IN_REVIEW, READY_FOR_FILE, FILE_PREPARED, WITH_LEGAL, WITH_PS |
| success | #eef6e3 | #3d5e16 | 6.73 | NON_OBJECTION_GRANTED, LETTER_ISSUED |
| warning | #fef3c7 | #92400e | 6.37 | RETURNED_TO_CMO (and "Hardcopy due") |
| danger | #fee2e2 | #991b1b | 6.80 | REJECTED_FINAL |

Pills are 24px high, full radius, 12px/500, with a 6px dot in the text colour. In a column of status pills every pill shares one min-width (96px) so the columns line up. Badges always carry a text label; colour is never the only signal. Vendors see the vendor label and group (CaseStatus::vendorLabel/vendorGroup), never internal statuses. One shared StatusPill is used for staff and vendors; vendors get their tone from the server (vendorTone / status_tone), so a colour never reveals an internal stage. The redesign must not reveal internal workflow stages through colour or presentation.

## 4. Typography

| Role | Size | Weight |
|---|---|---|
| Group label (sidebar) | 11px, uppercase, tracking .06em | 600 |
| Meta, captions, table headers | 12px | 400 or 500 |
| Secondary text | 13px | 400 |
| Body, nav, buttons, inputs | 14px | 400, 500 for labels and buttons |
| Section label (L2) | 14px | 600 |
| Card title | 16px | 600 |
| Page title (h1) | 20px, tracking -.01em | 600 |
| Application header title | 22px | 700 |
| KPI number | 28px, tracking -.02em, tabular-nums | 600 |
| Reference and file numbers in tables | JetBrains Mono 12px | 500 |

- VENDOR REQUEST FORM AND CASE HERO (measured from the reference, 0.7.2): field labels 15px/600; helper and secondary text 13px; section titles 20px/700; progress strip labels 14px/500; inputs 14px text at 40px high. Case hero: the reference number is Roboto 30px/700 tabular-nums (NOT mono), the title 22px/600, the "REQUEST" caption 12px/500 uppercase with tracking .08em, meta values 18px/600, meta labels 14px/500. Nothing on the form is under 13px. Other screens keep the table above.
- All of it is Roboto (variable). Dense tables, forms and navigation are never set in a serif. No decorative typography.
- Roboto is loaded through a single latin-only @font-face in resources/css/app.css that points at the package's woff2 file. The font token is --font-sans. FontHostGuardTest stays.
- Numbers, dates, money and counts use `tabular-nums`. Money and dates are formatted by the server. No client-side maths or formatting of amounts or dates.
- COPY CONVENTIONS (0.7.3): Title Case for field labels, section titles, stepper labels, buttons, nav items, group labels, table column headers, card titles, page titles and hero captions ("Item and Tender Details", "Review and Submit", "Ad Hoc - Tender Unable to Supply"). Small words (a, an, and, as, at, but, by, for, in, of, on, or, the, to, with) stay lowercase unless first; acronyms (DIN, CMO, PS, CAB) stay uppercase. Do NOT use CSS text-transform for this: the strings themselves are Title Case, in the server DTOs, seeders and React. Sentence case stays for helper text, notices, error messages, empty states, descriptions, status pills and state pills ("Not complete", "Under review") and anything the user typed. No "(optional)" suffix on field labels; required fields stay marked for assistive technology (`required` or `aria-required`) and by validation messages; a section whose whole content is optional still shows the "Optional" state pill.

## 5. Surfaces, shape and spacing

| Layer | Token | Shape | Spacing |
|---|---|---|---|
| Workspace | --background | none | page padding 24px (16px below md) |
| Sidebar | --navy | flush to the left, top and bottom edges, ROUNDED right corners (24px, token --radius-sidebar, content clipped to the shape), no outer gutter | 208px wide |
| Application header | --header | 72px high, 1px --border bottom, soft shadow | padding 0 24px |
| Section panel (L2) | --muted | rounded-2xl (16px) | padding 16px |
| Card / form card / hero (L3) | --card | 1px --border, rounded-2xl (16px) for the hero, action bar and section cards, rounded-xl (12px) for inner form cards and tiles, shadow-card | hero padding 24px 28px; form card padding 20px |

- The L1 canvas card and the brand stripe are RETIRED. Content sits directly on the workspace background. Avoid unnecessary nested WHITE containers: a white card inside a white card is wrong; a white form card inside a tinted section panel is correct.
- --radius is 0.75rem. Panels and hero 16px, inner cards 12px, controls `rounded-lg` (8px), nav items 12px, the sidebar's right corners 24px, the sidebar user card 14px, pills, chips and avatars full radius.
- Inputs 40px high; action-bar buttons 44px; touch targets 44px below md everywhere. Filter chips 30px. Icon squares 28 to 32px, rounded 8 to 9px, tinted.
- Primary button: solid --primary-deep, white text (8.3:1), hover --primary-strong. Outline: white, --border, shadow-xs. Secondary: --secondary and --secondary-foreground. Ghost: transparent. Destructive outline (Delete Draft): white fill, 1px --destructive border, --destructive-text, trash icon. One primary action per view.
- Inputs: --input-bg fill, 1px --input border, 8px radius. Focus: border --primary plus a 3px --ring-wash halo. Invalid: border --destructive, 3px --destructive-wash halo, error text in --destructive-text with an icon, linked by `aria-describedby`. Disabled: --muted background, --border border, muted text.
- List and table rows: 1px --divider between rows; table header on --subtle, 12px/500 muted.
- Depth: cards, the hero, type cards and the action bar use `--shadow-card` = `0 1px 2px rgba(13,27,42,.06), 0 3px 10px rgba(13,27,42,.05)` (defined once in app.css). Selected type card adds the 3px --ring-wash halo. Section panels (L2) are flat. Do not use heavy shadows to compensate for poor surface contrast.
- Form spacing: card padding 24px (20px below md), 20px between fields, 24px between sections, 8px between a label and its input.

## 6. Layout

- Shell, three parts: a NAVY sidebar on the left (full height), a WHITE application header across the top of the workspace, a light blue-grey workspace below it. Shared by staff and vendors; items and actions are permission-driven.
- Sidebar: vertical gradient from --sidebar-from to --sidebar-to (--navy is the flat fallback). Collapsible to icons on desktop, an off-canvas sheet below md. Header block: the Ministry crest (see section 11) or the shield-plus tile as fallback, "Ministry of Health" 16px/600 white and "Trinidad & Tobago" 13px --navy-muted, then a --navy-border divider. Group labels (section 4) shown only when the viewer has items in the group. Items: 44px high, 12px radius, 20px icon, 14px/500 label; inactive --navy-muted or white; hover --navy-hover AND the item pops: translateY(-1px), a soft shadow (token --shadow-nav-hover) and the icon scales to 1.08, 150ms ease-out, transform and shadow only (no layout shift), also on keyboard focus-visible and on the Logout row and user card, switched off under prefers-reduced-motion (fill change only) and kept as tooltips when collapsed; ACTIVE = solid --nav-active, white text and icon, plus the 3px white indicator bar, semibold, `aria-current="page"`. A count badge is a --primary pill on navy with a white ring. Visible keyboard focus: white 2px ring with offset. Footer: user card on --navy-deep with a 14px radius (initials avatar, name 14px/600, role or organisation line 12px --navy-muted, chevron to the profile menu) and a Logout row. Never expose roles or permissions through client-side nav logic. REMOVE the starter kit's "Repository" and "Documentation" links.
- Decorative watermark (medical cross and heartbeat line) in the lower sidebar at about 10% opacity, as in the reference. Inline SVG, `aria-hidden`, drawn with a token class, never behind text (it sits above the user card and below the nav items).
- Application header: white, 72px. Left: the sidebar toggle, then the application name from the app config (never hard-coded "Laravel"), 22px/700. Right: the notifications control ONLY once notifications exist (Phase 2; no dead bell and no fake count), then the user menu (circular initials avatar, name, chevron; profile, settings, logout). Visually lightweight.
- Breadcrumbs (detail and form pages): a row below the header on the workspace: home icon, then crumbs 14px; links in --primary 600, the current crumb muted. The last crumb is the request reference, or "New request" when there is none. NEVER the document title.
- Page header (in flow): h1 20px/600, a 13px muted subtitle, and on the right a status pill or the page's primary list action. Not sticky. Do not place form submission actions in the page header.
- Navigation items come from server-provided flags. Page-level flags are named `can`. Nav-only shared flags live under `nav`, NEVER under shared `can`. No role names in React.
- Dashboard (standard for every staff role): one page and one work-queue component for all staff roles; "Needs my action" is permission-driven (WorkQueue). Staff with queue steps land on /dashboard; vendors land on /requests.
- Forms: ONE scrolling page, not a wizard. Case hero, then the progress strip, then the sections, then the sticky action bar. Form actions are in the sticky action bar, never in the header.
- Content is fluid. At 360px nothing is cut off: grids collapse to one column; a wide read-only table scrolls inside its own bordered box. Editable entry rows wrap instead of scrolling.

## 7. Component map

| Need | Build with |
|---|---|
| Case hero (NEW; reusable) | Name the component `CaseHero` (the name "RequestSummary" is already the review-step summary). White card, 16px radius, border, --shadow-card. Left to right: 80px icon tile (gradient --tile-from to --tile-to, 20px radius, white file-text icon, soft shadow), divider, "REQUEST" caption, reference number, title (item description), divider, three meta cells (Request Type, Status, date), each with a 44px circular white icon holder. Status uses the shared StatusPill. The date label is "Last saved" on a draft and "Submitted on" once submitted. An unselected draft shows the type as "Not chosen yet" (string from the server). Data comes from a server-built `hero` prop (`CaseHero::for`); vendor-facing heroes expose nothing internal. Below lg the meta cells wrap, below md they stack. Used on the vendor form and the vendor request page; staff case headers are not changed in 0.7.1. |
| Section panel | L2 panel. VENDOR REQUEST FORM: every section is STACKED: a header row inside the L2 panel (number badge, title, state pill) ABOVE a full-width white L3 card. Number badge: 32px circle, solid --primary, white 14px/700, the number among VISIBLE sections. Title 20px/700. State pill on the right: 13px/500, light tint, 1px border, 16px lucide icon before the text (aria-hidden, text kept): Complete = circle-check in the success tone; Not complete = circle-alert in --destructive-text with muted text; Optional = minus in muted grey; Blocked = circle-alert in the warning tone. Red also means validation error, so the invalid-field halo and error text stay clearly different from this pill. Each section has `id="section-key"`. The two-column variant stays for short settings pages only. Sections are NOT collapsible (deferred, see section 11). |
| Stat tile (KPI) | L3 tile: 30px tinted icon square plus 13px/500 muted label, value 28px/600, optional footer strip on --strip with 12px neutral context text. No trend arrows, targets or SLA colouring. A clickable tile is a `<Link>` or `<button>`. |
| Progress strip (forms) | Position, not completion. Keep the existing ProgressStrip component; restyle it in place (no duplicate component). One column per VISIBLE section (3 to 6): a 36px circular marker, a 3px connector to the next marker, a 14px/500 centred label below. Meaning: it shows WHERE THE USER IS on the form, not what is complete. The section currently in view is CURRENT (solid --primary circle at 40px with the number, label bold in --primary, `aria-current="step"`); sections already passed are solid --primary circles with their number (NO check marks); sections ahead are tinted circles with the number in --muted-foreground (the mockup's lighter grey fails contrast). Connector: --primary up to the current marker, --border family after it. The current section is local UI state in the browser (IntersectionObserver on the `id="section-key"` elements; no storage): a section becomes current when its top passes the line just under the header; at the bottom of the page the last section is current; with no scroll, or without JavaScript, the first section is current. Completion is NOT shown in the strip: each section's state pill carries it (server-computed). Each marker and label is a plain `<a href="#section-key">`. Below md only markers show, plus the current label. No readiness or completion logic in the strip. |
| Type cards (request type) | A `<fieldset>` of native radio inputs, each styled as a card: 14px padding, 12px radius, 32px tinted icon square, a radio indicator, 14px/600 label. Selected: 1.5px --primary border, 3px --ring-wash, --selected background. Cards wrap in an auto-fit grid. The three Ad Hoc types sit under a small "Ad Hoc" group label. |
| Entry rows (editable tables) | Wide screens (lg and up): column headers once on --subtle, each row is one line of inputs, a row label at the start ("Supplier row 1"), a remove icon button at the end, a 1px --divider between rows. The "Add ..." text button sits at the BOTTOM of the card in a dashed top-border strip on --subtle. Below lg the row wraps to labelled fields. Every input has an accessible name that includes the row. |
| Data table (read-only) | shadcn Table on an L3 card; real `<th scope>`; header on --subtle; two-line identity cell (reference in JetBrains Mono, or name bold with a muted second line); several status columns where the gate matters; whole-row link through a stretched real link. |
| Filter chips | 30px pills with a count: active is --primary with white text; inactive is white with --border and a --muted count. Live search sits in the card header. |
| Status badge | one shared pill taking `tone` and `label` (section 3) |
| Sticky action bar | A white card bar (16px radius, border, --shadow-card, padding 16px 20px), `position: sticky; bottom: 0`, full content width. Left: Delete Draft (destructive outline). Right: Save Draft (outline, --primary text and icon) and Review and Submit (solid --primary-deep, arrow icon). 44px buttons, 15px/600 text. Same actions, pending states and flags as before. It must not cover the last field or a validation message (bottom padding on the content area). |
| Dropzone | Dashed 1.5px --input border, rounded-xl, --subtle background, 40px --accent icon square, instruction text, hint line. File rows: 32px type chip, name, size and time, a scan pill (Scanning, Scanned clean, Rejected), a remove button. Hardcopy reminder: warning-tone notice. |
| Empty state | centred 14px muted text with one clear next action where one exists |
| Alert and error | shadcn Alert, `role="alert"` for errors |
| Dialogs, side sheet | shadcn Dialog and Sheet (Radix: focus trap and Escape handled) |
| Initials chip | circle, 11px/600, tint with --primary-strong text; in the header 40px |
| Timeline (recent activity) | simple list with a 6px dot per event; no colour coding of elapsed time |

Update shared components through tokens and shared styles. Do not create duplicate components just to apply the theme. Components to add as needed (ask Kyle before any install): Tabs (@radix-ui/react-tabs), Progress (@radix-ui/react-progress).

## 8. Behaviour rules

- Server-driven: Inertia props only. No fetch or axios, no browser storage of case data, no client-side maths. Local UI state (open/closed, active tab, drag-over) is fine.
- Data reaches React only through DTO/resource classes, never raw models.
- Plain-English labels. Vendor screens use vendor-safe wording and never show internal statuses, internal notes, file numbers or technical review fields.
- Icons: lucide only, 16px in buttons and pills, 20px in nav and stat tiles.
- No SLA UI unless `sla_policies` holds values. Until then do NOT render target days, "Over target", late counts, trend arrows, or any colouring of time in stage. Show elapsed time as a plain number only.
- Gradients only where section 2 allows them (sidebar, hero icon tile). No emoji, invented statistics, or invented workflow stages. The stepper only shows server-supplied visible sections.
- Form actions are never in the header. Add buttons are at the bottom of their table.
- Motion is subtle (about 200 ms or less), respects `prefers-reduced-motion`, and never touches typing, form fields or the autosave chip.
- The redesign must not change business logic, permissions, workflow states, validation, server-side calculations or Inertia contracts.

## 9. Accessibility (WCAG 2.1 AA, public-facing government system)

Pairs below were computed on 2026-11-17 and 2026-11-18. Re-verify with a contrast checker if any value changes.

- Text contrast 4.5:1 (3:1 for 24px and larger). White on primary 5.27; white on forest 6.58; white on destructive 4.83; white on primary-strong 7.19; muted-foreground on white 5.85, on muted 5.24, on --subtle 5.59, on --strip 5.53; primary on white 5.27 and on muted 4.72; tone pairs in section 3 (5.24 to 6.80); accent-foreground on accent 5.89; secondary-foreground on secondary 5.93; destructive-text on white 6.47; foreground on white 17.39. NAVY: white on navy 15.24; --navy-muted on navy 8.61; white on --navy-hover 12.17; --navy-muted on --navy-hover 7.4 or better (verify). Sky and lime never carry text.
- Form-field borders and other UI boundaries need about 3:1 against the surface: use --input, not --border. The active nav pill against navy is 2.89:1, which is why it carries the white indicator bar and `aria-current`.
- Focus: every interactive element shows a visible focus ring. On light surfaces --ring (2px with offset); on the navy sidebar a white ring. Never `outline: none` without a replacement.
- Real elements only: `<button>`, `<a href>`, `<input>` with `<label>`. No clickable divs or spans. Type cards use native radio inputs.
- Icon-only buttons have `aria-label`. Decorative icons, the sidebar watermark and the state-pill icons are `aria-hidden`.
- Errors are linked to their fields (`aria-describedby`) and announced; never rely on colour alone.
- Tables use proper header cells. Page has one h1 and a skip-to-content link.
- Respect `prefers-reduced-motion`. Touch targets: 36px minimum on desktop, 44px below md.
- Browser autofill: inputs for names, emails and phone numbers carry correct `autocomplete` attributes.
- Also verify after the redesign: header icons and text, status indicators on their backgrounds, keyboard focus across sidebar and header, semantic HTML, screen-reader labels.

## 10. Print

- The submission receipt page prints cleanly: hide the sidebar, header, decorative elements and sticky bar, black on white, no shadows, reference number large, A4 or Letter portrait. Use `@media print` and a Print button calling `window.print()`.

## 11. Designs that must NOT ship

From the reference image (`reference-v0.7.png`):
- Status "Submitted" and "Submitted On" shown on a draft. The hero shows the REAL status and the matching date label.
- Three completed steps over an incomplete section. Stepper states come only from the server's section states.
- The notification bell with a "3" badge. The bell appears only when notifications exist (Phase 2).
- The invented sidebar items ("To Review", "CAB", "PS File", "Drafts") and the "Office of the CMO" group for a vendor. Keep the existing permission-gated nav; add no item whose page does not exist.
- Role text "Application Admin" under a vendor. Vendors show their organisation; staff show their role display name.
- Collapsible form sections: an addition outside agreed scope, deferred. The stepper and per-section state pills already show completion. Revisit after the CMO test.
- Sample values ("Panadol", "Medication", NR-2026-000006) and the breadcrumb text "Request - Laravel".
- The mockup's pale input borders: they fail the 3:1 non-text contrast rule, so inputs keep --input. This is the one place the image does not win, because the system is public-facing and accessibility is not a style rule.
- The coat of arms in the mockup is generated. Never draw or recreate an emblem. The crest is Kyle's supplied file `public/brand/coat-of-arms.png`; until it exists the shield-plus tile is the fallback.

From the older prototype (still true):
- The canvas loaded Google Fonts and used inline hex colours. Fonts are self-hosted and colours come from section 2 tokens.
- "Step 2 of 4 / Continue" and the four-step wizard: the real form is one page with a progress strip, one segment per visible section.
- The Cancel button in the action bar: the form autosaves; actions are Delete Draft, Save Draft and Review and Submit.
- The prototype's request types (Pharm, Non-pharm, Excess) and sample fields (tender reference format "NIP/T/YYYY/NNN", procurement method placeholders, "Recommended supplier", "Justification", simple items table). The real fields are in claude/adhoc-form-spec.md. "NIP/T/YYYY/NNN" is invented and must not be validated.
- "PDF or Word, up to 20 MB": uploads are PDF only, 25 MB.
- A two-column label-and-card layout for table sections.
- The type-card descriptions in the canvas (invented). Cards show the label only until the CMO's office supplies wording.
- CMO canvas "target days", "Over target" and coloured time-in-stage: there are no SLAs yet.
- Lanes ("six-lane process") must be derived from the state machine's statuses, never invented in React.
- The login screen design from the canvas (hex colours and inline fonts). Login uses token classes only and sits in the new theme.
- Case side panel ("Send to NIPDEC"), decided 2026-11-15: the panel IS built for internal destinations only. These parts do NOT ship: "Send in 24 hours" delayed send with cancel; the "Funding availability (CAB)" amount block; channel notes such as "Given via email"; NIPDEC as a destination and labels such as "Query: Legal to NIPDEC"; time-in-stage text with colour.

The reference is not permission to invent procurement fields, request types, workflow stages, statistics, upload formats or sizes, external font requests, inline hex colours, a second component library, or changes to permissions or business rules.

## 12. Screens: phase and slice map

| Screen | Phase | Slice |
|---|---|---|
| Vendor requests list and form | 1 | built (8b to 8d); one adaptive form in V2-2 |
| Staff case list and case view | 1 | 9a (built); new fields by request type in V2-3 |
| Staff actions (intake, hardcopy, review, file, Legal, PS, route, letter) | 1 | 9b to 10f (built) |
| "Needs my action" queue, all staff roles | 1 | 9c and BUNDLE-1 (built) |
| Theme foundation v0.7.1: tokens, navy shell (sidebar, header), shadcn sidebar variables | 1 | V2-4 Stage A |
| Vendor form restyle: case hero, progress strip, section cards, action bar | 1 | V2-4 Stage B |
| Vendor requests list: count tiles, live search, new columns | 1 | UI-3 and UI-4 (built) |
| CMO pipeline, gate tracker, KPI strip | 1 to 2 | after the internal test build |
| CMO "Where files are" (stage bars WITHOUT targets) and recent activity | 1 to 2 | with the CMO dashboard |
| Open queries panels and the notifications bell | 2 | queries and notifications |
| Request throughput chart | 3 | reports |
| Procurement Process Map | 4 | help page or documentation |

## 13. Foundation checklist (V2-4 Stage A)

1. Review the existing variables in resources/css/app.css against section 2. Do not replace the stylesheet or remove working variables.
2. Add the shell tokens and map the shadcn sidebar variables (section 2). Expose them in `@theme inline`.
3. Keep MOH blue as --primary. Keep every status, validation, typography, card, shadow and spacing token unless this file requires a change.
4. Make the sidebar navy and flush; add the white header; retire the L1 canvas and the brand stripe; apply the active-item rules (indicator bar, aria-current).
5. Update shared components through tokens only. No hex in React. No new packages.
6. Verify contrast for every new navy pair and report the ratios.
7. Check sidebar, active navigation, header, mobile navigation and focus states (static checks by test; visual checks by Kyle or by browser review).
8. Keep existing tests and static guard tests green. Preserve Inertia props, permissions, workflows and DTO contracts.
9. Stage B (runs straight after Stage A in the same pass, once the Stage A tests are green): case hero, progress strip restyle, section cards, action bar.

Implementation principle: update the design system centrally, then restyle screens through the shared components. Do not rebuild working workflows to achieve the appearance.
