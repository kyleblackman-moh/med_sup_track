# Slice V2-4 (Phase 1): Navy and Healthcare Blue theme (design.md v0.7.2)

Supersedes the earlier V2-4 draft and V2-2f. Two stages in this one file. Stage A is the foundation (tokens and shell). Stage B is the vendor form pieces.

RUN STRAIGHT THROUGH: do Stage A, then Stage B, in one pass, with no stop between them. Kyle is away and cannot answer questions. Do not ask any; take the most reasonable reading, say which one you took in the report, and carry on. Send ONE report at the very end covering both stages. Before starting Stage B, the Stage A targeted tests must be green: fix anything this slice broke first. If a step cannot be completed, skip it, record why, and continue. Never edit .env, never push, never deploy.

## 0. Rules

- Follow CLAUDE.md and design.md v0.7.2 (repo root, untracked). Where this file and design.md disagree, design.md wins.
- Tests: Pest with DatabaseTransactions ONLY. Never suggest or run migrate:fresh, migrate:reset, db:wipe or RefreshDatabase. Never edit .env, force push, or install packages.
- Targeted tests only while building. Kyle runs the full suite. No parallel test runs.
- Pint on changed PHP files only. tsc, eslint and Prettier clean on changed frontend files.
- Stage by explicit path. design.md, adhoc-form-spec.md, reference-v0.7.png and every *-prompt.md file stay untracked and unstaged.
- Do not deploy. Do not run npm run build (the dev server is running).
- No business logic, Inertia prop contract, permission or workflow change. Do not replace app.css and do not remove working variables. No hardcoded colours in React. Gradients only where design.md section 2 allows them (sidebar surface, hero icon tile). No new packages. Shared Inertia `can` gets no new keys.
- One commit per numbered step. If a step cannot be done as written, skip it, say why in the report, and carry on.
- The reference image is `reference-v0.7.png` in the repo root. Open it with the Read tool before starting each stage. It is a generated mockup: take layout, hierarchy, colour and depth; design.md section 11 lists what NOT to copy.

## STAGE A: foundation

### A0. Inventory (write this at the top of your report, then carry on without waiting)

List the exact files and components that need changes, and for each one what changes. At minimum check and name:

- `resources/css/app.css`: existing variables, the `@theme inline` block, the sidebar variables, how colours are encoded (oklch or hsl or hex), where `--shadow-card` is defined.
- The app sidebar component and its nav item components, the sidebar layout and provider, the user menu in the sidebar footer.
- The application header or top bar component and the breadcrumbs component, if any.
- The layout components that render the L0/L1 canvas, the gutter and the BrandStripe.
- The tests that pin the shell (ThemeGuardTest and any nav or layout test).
- Whether the sidebar already supports collapse-to-icons and off-canvas below md.
- Whether the header already shows a bell or notification control, and whether `nav.section` is still shared.

### A1. Tokens (app.css only)

1. Compare every variable in app.css with design.md sections 2 and 5. Report what exists, what is missing, what differs.
2. Add the shell tokens: `--navy`, `--navy-deep`, `--navy-foreground`, `--navy-muted`, `--navy-border`, `--navy-hover`, `--nav-active`, `--nav-active-foreground`, `--header`, `--header-foreground` with the design.md values, converted to the file's colour format. Expose them in `@theme inline` so `bg-navy`, `text-navy-muted`, `bg-nav-active`, `bg-header` and similar classes exist.
3. Map the shadcn sidebar variables per design.md section 2: `--sidebar`, `--sidebar-foreground`, `--sidebar-primary`, `--sidebar-primary-foreground`, `--sidebar-accent`, `--sidebar-accent-foreground`, `--sidebar-border`, `--sidebar-ring` (white). They are currently transparent or light; they become the navy theme.
4. Keep `--primary` #006fba. Keep all status, validation, typography, card, shadow and spacing tokens and `.dark` untouched. Do not duplicate a token that already serves the same purpose (for example reuse `--card`, `--border`, `--foreground`; only add `--header` and `--header-foreground` as aliases).
5. Also add `--sidebar-from`, `--sidebar-to`, `--primary-deep`, `--tile-from`, `--tile-to` and `--input-bg` with the design.md values, and set `--background` to #f0f4fc. No other new tokens.
6. Update ThemeGuardTest only as far as the new tokens need.

Commit: "ui: navy shell tokens and sidebar variable mapping".

### A2. Sidebar

1. Consume the sidebar tokens only (no hex, no new inline colours). Vertical gradient from `--sidebar-from` to `--sidebar-to` (a token-based class or an app.css utility; no inline colours), flush to the left edge, full height, 208px wide, no outer gutter. Keep collapse-to-icons on desktop and the off-canvas sheet below md exactly as they work today.
2. Header block: crest or fallback (A4), "Ministry of Health" 16px/600 white, "Trinidad & Tobago" 13px `text-navy-muted`, then a `border-navy-border` divider.
3. Nav items: 44px high, 8px radius, 20px lucide icon, 14px/500 label. Inactive: `text-navy-muted` or white. Hover: `bg-navy-hover`. ACTIVE: `bg-nav-active`, white text and icon, a 3px white indicator bar on the left edge, semibold, and `aria-current="page"` on the link. Keep `nav.section` for the active state and keep every existing item and its permission gating. Add no nav item whose page does not exist.
4. Group labels per design.md section 4, rendered only when the viewer has items in the group. A vendor never sees a CMO group.
5. Count badge (if the sidebar has one): `--primary` pill with a white ring.
6. Footer: user card on `bg-navy-deep` (initials avatar, name 14px/600, subline 12px `text-navy-muted`, chevron to the existing profile menu) and a Logout row using the existing logout route. Subline: vendors show their organisation name, staff show their role display name, both supplied by the server (extend the existing shared auth user data only if the label is not already available; report if you had to).
7. Visible keyboard focus on every sidebar control: 2px white ring with offset (`--sidebar-ring`).
8. Watermark (required): medical cross and heartbeat line, inline SVG, `aria-hidden`, token class, about 10% opacity, lower sidebar above the user card, never behind nav text.

Commit: "ui: navy sidebar (flush, active indicator, footer user card)".

### A3. Application header and shell

1. White header, 72px, `bg-header`, 1px `border-border` bottom, soft shadow (`shadow-xs`). Left: the existing sidebar toggle (keep its mobile behaviour and its `aria-label`), then the product title from the app name config, 22px/700. Never hard-code "Laravel". Right: the user menu (40px circular initials avatar, name, chevron, existing menu items: profile, settings, logout). No bell and no notification control (Phase 2).
2. Workspace: `bg-background`, 24px padding (16px below md), fluid. Retire the L1 canvas card, the 12px gutter and the BrandStripe rendering. Leave the BrandStripe component file in place and list it under cleanup.
3. Breadcrumbs row below the header (home icon, crumbs 14px, links `text-primary` 600, current crumb muted). The last crumb is the request reference or "New request", never the document title. Only on detail and form pages as today.
4. Page titles: confirm every page title uses the config app name suffix, never "Laravel". Report what the browser tab shows when APP_NAME is unset.
5. Add a skip-to-content link if one does not exist.

Commit: "ui: white application header and workspace shell".

### A4. Crest, favicon

1. Crest: shared Inertia prop `brand.logo` = the public URL of `public/brand/coat-of-arms.png` if that file exists, else null (`brand` is a new top-level prop, not a `can` key). The sidebar header uses it when present and falls back to the existing shield-plus tile. Do NOT draw or recreate an emblem. Kyle supplies the file.
2. Favicon: lucide shield-plus drawn as an SVG on a rounded square in the MOH blue, `public/favicon.svg`, linked in the root Blade template with `rel="icon" type="image/svg+xml"`; remove the Laravel default icon links. If ThemeGuardTest flags hex inside the SVG, exclude only public/favicon.svg in the guard, with a comment.

Commit: "ui: crest prop, favicon".

### A5. Verify, then STOP

1. Contrast: compute (throwaway script under /tmp, not committed) and report the ratio for each new pair: white on `--navy`, `--sidebar-from` and `--sidebar-to`; `--navy-muted` on `--navy` and on `--sidebar-to`; white on `--primary-deep`; white and `--navy-muted` on `--navy-hover`; white on `--nav-active`; sidebar group label on navy; header text on white; header icon on white; user card text on `--navy-deep`; white focus ring on navy. Text must be 4.5:1 or better, UI parts 3:1 or better. Fix a failing token and report the change. Expected: the active pill against navy is about 2.89:1, which is why A2 adds the indicator bar and `aria-current`.
2. Static checks by test (add to existing guard tests, small): no hex in the sidebar and header components; the nav link for the current section carries `aria-current`; the header title comes from config; no frontend code names NOT_SELECTED.
3. Things a Pest test cannot prove: sidebar collapse, off-canvas navigation, hover and focus rings. Do not claim they are tested. List them under "Kyle checks by eye" in the report.
4. Render checks through feature tests: vendor requests list, vendor request page, vendor form, staff dashboard, staff case list, staff case page, settings, login, forced password change all return 200 inside the new shell for the right users.
5. Targeted tests: ThemeGuardTest, RequestFormViewTest, RequestPagesTest, DashboardTest, AuthenticationTest, plus any nav or layout test found in A0.

Commit any test changes: "test: shell guards for navy theme".

Do not stop here. Keep the Stage A notes for the final report (A0 inventory; per commit hash, files, tests run; the contrast table; "Kyle checks by eye" list; anything changed that this file did not list; anything skipped and why; cleanup list), then go straight on to Stage B.

## STAGE B: vendor form

Use the shared components. Restyle in place; do not create duplicates. Follow design.md section 7.

### B1. Case hero (server-built)

1. New `App\Http\Resources\Requests\CaseHero::for(case, viewer)` returning: `reference` (string or null), `title` (item description or null), `type` (label and the server-chosen icon name, or null when unselected), `status` (label and tone via the existing vendorTone/status_tone logic), `date` (label and server-formatted value via App\Support\Display; "Last saved" on a draft, "Submitted on" once submitted).
2. Check how reference numbers are assigned. If a draft has none yet, `reference` is null and the hero shows "Draft" in its place. Do NOT change numbering rules. Report which is true.
3. Add `hero` to the draft form DTO and the vendor request page DTO. No frontend code names NOT_SELECTED: an unselected draft returns `type: null` and the hero shows the server-supplied "Not chosen yet".
4. New React `CaseHero` per design.md section 7 (do not name it RequestSummary; that name is taken by the review summary). Icon tile is a gradient from `--tile-from` to `--tile-to`. Sizes per design.md section 4 (number 30px/700, title 22px/600, meta values 18px/600). Use on the vendor form page and the vendor request page only.
5. Tests in RequestPagesTest: draft hero (label "Last saved", no "Submitted on"); unselected draft (type null, the string NOT_SELECTED absent from the response); submitted request ("Submitted on").

Commit: "ui: case hero on vendor form and request pages".

### B2. Progress strip restyle

Restyle the existing ProgressStrip in place per design.md section 7: circular 36px markers, 3px connectors, labels below, `current` marker. Add a boolean `current` to each section in the form DTO (first visible incomplete section; at most one; none when all complete). Markers and labels are plain `<a href="#section-key">`. Give each section `id="section-key"` and a `scroll-mt` that clears the header. Not-done number text uses `--muted-foreground`. Below md only markers show, plus the current label. No client-side readiness logic. Section order, counts and labels come only from the server.

Commit: "ui: progress strip restyle with current marker".

### B3. Section cards, inputs, number badge, state pills (V2-2f items 1 and 2)

Per design.md section 7: L2 panel with a header row (32px solid `--primary` number badge, 20px/700 title, state pill with a 16px lucide icon, `aria-hidden`, text kept) above the white L3 card. Labels 15px/600, "(optional)" 400 muted, helper 13px, inputs 40px at 14px on `--input-bg` with the existing `--input` border (kept for accessibility). Field order, field names and validation are untouched. Error halo and error text stay distinct from the Not complete pill.

Commit: "ui: section cards, number badge, state pills".

### B4. Action bar

StickyActionBar becomes the white card bar per design.md section 7: Delete Draft (destructive outline, left), Save Draft (outline) and Review and Submit (solid `--primary-deep`, arrow icon, right), 44px buttons, 15px/600. Same actions, pending-disable states, flags and delete-confirm flow. It must not cover the last field or message (bottom padding on the content area). Wraps cleanly below sm.

Commit: "ui: action bar".

### B5. Sweep and report

1. Re-run the render checks from A5 and the targeted tests.
2. Contrast table for new Stage B pairs (state pill text and icon on tint, stepper numbers, hero meta text).
3. Send the single final report covering BOTH stages, in the Stage A format, plus: do drafts have a reference number; any page you could only check by tests; the full list of commits with hashes; and the list "Kyle checks by eye" (sidebar collapse, off-canvas mobile navigation, hover and focus rings, the hero and stepper at narrow widths).

Commit: "ui: Stage B sweep and contrast fixes".
