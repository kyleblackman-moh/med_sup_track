# Slice V2-4b (Phase 1): hero, title, button, stepper position, labels

Follow CLAUDE.md and design.md v0.7.3 (repo root, untracked). Replaces the short V2-4b text sent earlier.

RUN STRAIGHT THROUGH. Kyle is away and cannot answer questions. Do not ask any: take the most reasonable reading, say which in the report, and continue. One commit per numbered item. ONE report at the end. If an item cannot be done, skip it, say why, continue.

## Rules

- Tests: Pest with DatabaseTransactions ONLY. Never suggest or run migrate:fresh, migrate:reset, db:wipe or RefreshDatabase. Never edit .env, push, build, deploy, or install packages.
- Targeted tests only. Pint on changed PHP files only. tsc, eslint and Prettier clean on changed frontend files.
- Stage by explicit path. design.md, adhoc-form-spec.md, reference-v0.7.png and every *-prompt.md file stay untracked and unstaged.
- No business logic, validation, permission, workflow or Inertia contract change beyond what an item says. No hex in React. Shared Inertia `can` gets no new keys.

## Items

### 1. One h1 per page, owned by the hero

On the vendor form page and the vendor request page, remove the separate "Request" page heading. The CaseHero title becomes the single h1 on each page (visual style unchanged). On the request page the h1 text is the item description again, falling back to "New Request" when there is none.

### 2. Hero text when there is no reference and no item description

CaseHero shows "New Request" (string from the server) as the large text instead of "Draft". The status pill still says Draft.

### 3. Sidebar active item

Confirm in the markup that the active item has the 3px white left indicator bar and `aria-current="page"`. If the bar is missing or clipped by overflow or radius, fix it, and say which it was.

### 4. Default button colour

Make the default Button variant use `--primary-deep` (hover `--primary-strong`). Links, the active nav pill, filter chips, progress markers and focus rings stay MOH blue. Check login, settings and dialogs still look consistent and say what changed.

### 5. Favicon and git status

If `public/favicon.ico` is tracked and 0 bytes, `git rm` it and confirm the blade icon link is the only favicon reference. Then run `git status` and report any deleted tracked files.

### 6. Progress strip shows position, not completion (design.md section 7, "Progress strip")

Kyle wants the strip to show WHERE THE USER IS on the form, not what is complete.

1. Restyle the existing ProgressStrip in place. Remove the check marks and every completion visual. Completion is already shown by each section's state pill.
2. Markers: current = solid `--primary` circle at 40px with the number, label bold in `--primary`, `aria-current="step"`; sections already passed = solid `--primary` circles with their number (no check); sections ahead = tinted circles with the number in `--muted-foreground`. The connector is `--primary` up to the current marker and the `--border` family after it.
3. The current section is local UI state in the browser: an IntersectionObserver (or an equivalent small scroll handler) on the `id="section-key"` elements. A section becomes current when its top passes the line just under the header (account for the 72px header). At the bottom of the page the last section is current. With no scroll, or without JavaScript, the first section is current. No browser storage. Keep the logic in the strip or one small hook. No new package.
4. Clicking a marker or label still jumps to the section (plain anchor) and the strip updates when the page lands there. Respect `prefers-reduced-motion`: no animated fills or smooth scrolling when reduced motion is requested.
5. Remove the `current` boolean from the section DTO and from its tests (it was "first incomplete" and is now dead). Section order, count and labels still come only from the server.
6. Below md only markers show, plus the current label.

### 7. Remove "(optional)" from labels

1. Remove the visible "(optional)" suffix from every field label, table column header and dropzone label, wherever it is rendered (React label component, server labels, tests).
2. Do NOT change validation or what is required. Confirm every required input still carries `required` or `aria-required="true"`; add it only where missing and say which. Validation messages are unchanged.
3. The section state pill "Optional" stays (it describes a whole section with no required content).

### 8. Title Case for labels (design.md section 4, "Copy conventions")

Kyle wants the first letter of the words capitalised. The reference image uses standard Title Case ("Item and Tender Details", "Review and Submit", "Request Type"). Follow that: capitalise every word except small words (a, an, and, as, at, but, by, for, in, of, on, or, the, to, with) unless the small word is first. Acronyms (DIN, CMO, PS, CAB) stay uppercase. Do NOT use CSS text-transform: change the strings themselves.

Apply to: field labels, section titles and stepper labels (server RequestSections), buttons (Delete Draft, Save Draft, Review and Submit and the others), nav item labels and group labels, table column headers, card and tile titles, page titles and h1s, hero captions and meta labels ("Request Type", "Last Saved", "Submitted On"), tab labels, dialog titles, breadcrumb crumbs, entry-row labels ("Supplier Row 1", "Order Row 1", "Batch 1", "Quotation Row 1"), and procurement type labels (for example "Ad Hoc - Tender Unable to Supply", "Ad Hoc - Not Awarded", "Tender - Excess").

Leave in sentence case: helper text, notices, error and validation messages, empty states, descriptions, toasts, status pills and state pills ("Not complete", "Under review", "Hardcopy due"), and anything the user typed or the database holds as user data.

Procurement type labels live in `procurement_types` rows. Update ProcurementTypeSeeder. If the seeder is idempotent (updateOrCreate by code), say so and tell Kyle to run `php artisan db:seed --class=ProcurementTypeSeeder`. If it is not idempotent, add one small idempotent data migration that updates only the label column by code. Never use migrate:fresh. Do not run the seeder or migration yourself; give Kyle the exact command.

Update every test that pins an old string, changing only the expected text. Add one small guard test (Pest dataset) asserting the section labels and the five type labels are Title Case by the rule above, so it does not drift. Where this item meets item 7, both apply (no "(optional)" and Title Case).

### 9. Sidebar hover animation (design.md section 6)

Kyle wants nav items to pop up when the mouse hovers over them.

1. On hover, each sidebar nav item: fill `--navy-hover`, lifts with `translateY(-1px)`, gains a soft shadow, and its icon scales to 1.08. 150ms ease-out. Use transform and shadow only, so there is no layout shift and no clipping at the sidebar edge. Define the shadow as a token in app.css (for example `--shadow-nav-hover`), no inline colours.
2. Apply the same to the Logout row and the user card, and to keyboard `focus-visible` (together with the existing white focus ring).
3. The active item keeps its blue pill; it may lift too but must keep its white indicator bar and contrast.
4. Respect `prefers-reduced-motion`: under reduced motion there is no transform and no scale, only the fill change (use the `motion-reduce:` variants).
5. When the sidebar is collapsed to icons, keep the existing tooltips; the lift applies to the icon button.
6. Add one small static guard: the sidebar nav item classes include a `motion-reduce:` variant, so the animation can never ship without it.

### 10. Sidebar roundness (design.md sections 5 and 6)

Kyle wants some roundness on the sidebar.

1. The sidebar stays flush to the left, top and bottom edges, but its RIGHT corners (top-right and bottom-right) are rounded at 24px, through a token (`--radius-sidebar` in app.css, exposed in `@theme`). The gradient and watermark must be clipped to that shape (overflow hidden on the sidebar surface) so nothing pokes out of the corners.
2. Nav items 12px radius (was 8px), the user card 14px radius, the Logout row and the hover lift follow the same 12px. Keep the white active indicator bar visible inside the rounder pill (inset it so the radius does not cut it off).
3. Keep it correct in collapsed-to-icons mode and in the off-canvas sheet below md: rounded outer edge in both, no clipped tooltips, no clipped focus ring (the white ring must stay fully visible on rounded items).
4. Rounded corners reveal the workspace background behind them. Confirm that background is `--background` (not white or transparent artefacts) and report what you see in the markup.

## Tests

RequestPagesTest, RequestFormViewTest, ThemeGuardTest, AuthenticationTest, ShellRenderTest, AdHocFormTest, UnselectedTypeTest, RequestSummaryTest, DashboardTest, StaffCasesTest, NavSectionTest, plus any test your string changes touch. Fix failures this slice caused.

## Final report

Per commit: hash, files, tests run. Then: item 3 finding (bar present or not, and what you fixed); which buttons changed colour in item 4; the exact seeder command for Kyle; any required input that lacked `required` or `aria-required`; how many test strings you changed in item 8; anything skipped and why; "Kyle checks by eye": the stepper while scrolling, clicking a marker, the last section at the page bottom, Title Case on the staff pages, the sidebar hover pop (including collapsed mode and with the OS reduced-motion setting on), and the sidebar's rounded corners (expanded, collapsed and the mobile sheet, with the focus ring and indicator bar not clipped).
