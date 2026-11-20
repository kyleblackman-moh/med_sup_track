# MASTER.md: Vendor Applicant design system

Scope: the vendor (Vendor Applicant) screens: `/requests` list, the request form (`requests/ad-hoc/edit`, and the older tabbed form `requests/edit` kept for old drafts), the request page (`requests/show`) and the printable receipt (`requests/receipt`).

**Source of truth.** `design.md` v0.7.3 (repo root, Kyle's file) is the app's design system and wins on any conflict. This file does not add a style: it is the vendor section's digest of design.md and `resources/css/app.css`. It picks ONE value where the code used several, names the tokens to use, and fills the states design.md leaves open. Change design.md first, then this file.

Stack: Laravel 12, Inertia, React (TypeScript), Tailwind CSS 4 (CSS-first: the theme lives in `resources/css/app.css`, there is no tailwind.config), shadcn/ui on Radix, lucide-react icons.

## 1. Colour (tokens only, never hex or the raw Tailwind palette)

| Use | Token class | Notes |
|---|---|---|
| Page background | `bg-background` | #f0f4fc |
| Cards, inputs' surface | `bg-card`; inputs `bg-input-bg` | |
| Section panel (L2), tracks | `bg-muted` | |
| Table header, upload cell, add-row strip | `bg-subtle` | |
| Body text | `text-foreground` | 17.39:1 on white |
| Secondary text, helper text | `text-muted-foreground` | 5.85:1 on white |
| Links, focus ring, markers | `text-primary`, `ring-ring` | MOH blue |
| Primary button | Button default variant (`bg-primary-deep`) | one per view |
| Error TEXT and error icons | `text-destructive-text` | **canonical** (6.47:1). `text-destructive` (#dc2626, 4.83:1) is for destructive BUTTON fills and borders only. |
| Destructive button / outline | `bg-destructive`, `border-destructive` | |
| Invalid field | `border-destructive` + `ring-3 ring-destructive-wash` | |
| Field border | `border-input` | 3.47:1; never lighten |
| Decorative borders | `border` (= --border), dividers `divide-divider` | |
| Status tints | `bg-status-{neutral,info,success,warning,danger}` with `text-status-*-foreground` | 5.24 to 6.80:1; tone comes from the server |

Canonical choices for values that were duplicated:
- Error text: `text-destructive-text`, because design.md section 5 names it for error text and it has the larger contrast margin (6.47 against 4.83).
- Info and warning tints in the older form: `bg-status-info` / `bg-status-warning` with their foregrounds instead of `blue-50/800` and `amber-50/900`, because they are the app's measured status pairs and dark mode is not maintained (design.md section 1).
- Error lists in the older form: `text-destructive-text`, not `red-600/700/800`: the same token as the new form.

## 2. Type (Roboto; JetBrains Mono for reference numbers in tables)

| Role | Class | Size / weight |
|---|---|---|
| Helper text, meta, state pills, file meta | `text-helper` | 13px (token `--text-helper`) |
| Field label (form) | `text-label font-semibold` | 15px/600 (token `--text-label`) |
| Body, inputs, buttons | `text-sm` | 14px |
| Table headers, list meta (list page only) | `text-xs` | 12px; never on the form |
| Section title | `text-xl font-bold` | 20px/700 |
| Hero title | `text-hero-title font-semibold` | 22px/600 (token) |
| Hero reference number | `text-hero-ref font-bold tabular-nums` | 30px/700 (token) |
| Hero caption | `tracking-caption` | .08em (token) |
| Page title | `text-xl font-semibold tracking-title` | 20px, -.01em (token) |

Canonical: 13px for every helper and meta line on the form (design.md section 4: nothing on the form is under 13px); the list page keeps 12px only for table headers and the search hint, as design.md's general table allows. Numbers, dates and money use `tabular-nums` and come formatted from the server.

## 3. Spacing rhythm

| Between | Value |
|---|---|
| Page padding | `p-4 md:p-6` (PageContent) |
| Sections | 24px (`space-y-6`) |
| Section title and its card | 12px (`space-y-3`) |
| Card padding | `p-4 md:p-5` (16 / 20px) |
| Fields in a FieldGrid | 16px vertical, 20px horizontal |
| Blocks inside a card | 16px (`gap-4`) |
| Label and input | 8px (`gap-2`) |

Canonical: the FORM-COMPACT-1 values (20/16px card padding, 16px field gaps), because they are what the form now ships and design.md section 5's table already names 20px form-card padding.

## 4. Shape and depth

| Element | Radius | Depth |
|---|---|---|
| Section panel, hero, action bar | `rounded-2xl` (16px) | panel flat; hero and bar `shadow-card` |
| Inner cards, upload cells, entry tables | `rounded-xl` (12px) | `shadow-xs` or flat |
| Controls (inputs, buttons) | `rounded-md` / `rounded-lg` (shadcn) | |
| Hero icon tile | `rounded-tile` (20px, token `--radius-tile`) | gradient allowed (design.md section 2) |
| Pills, chips | `rounded-full` | |

## 5. Widths

One scale for every field: `resources/js/components/ui/field-width.ts` (xs numbers and dates, sm short codes, md names, lg long text, full). Stacked fields go in `FieldGrid`; entry rows use `ENTRY_WIDTH`. No width classes of their own in a section (FormCompactGuardTest).

## 6. Components (reuse before creating)

| Need | Use |
|---|---|
| Page header | `CaseHero` on the form and the request page; `PageHeader` on the list |
| Section | `FormPanel` (stacked `SectionPanel`, number badge, state pill) |
| Text input, text area, radio group, checkbox, server figure | `TextField`, `TextAreaField`, `RadioGroupField`, `CheckboxField`, `Figure` (ad-hoc/fields.tsx) |
| Editable rows | `EntryTable` + `EntryRow`, or `EntryCardRow` for two-line cards |
| Buttons | shadcn `Button`: default (primary), `outline` (secondary), `ghost`, `destructive`; Delete Draft is the destructive outline |
| Form actions | `StickyActionBar`, never the header |
| Progress | `ProgressStrip` (position, not completion) |
| Uploads | `SupportingDocuments` cells |
| Status | `StatusPill` with the server's tone |

## 7. States (filled in where design.md is silent)

| State | Rule |
|---|---|
| Focus | Every control: 2px `ring-ring` with 2px offset, or the field's 3px `ring-ring-wash` halo. Never `outline: none` without a replacement. |
| Error, field | `aria-invalid`, red border and halo, a message in `text-destructive-text` with a 16px `CircleAlert`, linked by `aria-describedby`. |
| Error, table or section level | The same message style (icon + `text-destructive-text`), `role="alert"` only where it appears after an action. |
| Required | `aria-required` (no asterisk, no "(optional)"); validation messages say what is missing. |
| Loading (button) | Keep the label, show "…ing" text or a 16px `LoaderCircle` (`motion-safe:animate-spin`), `disabled` and `aria-busy` while the request runs. |
| Loading (list) | `aria-busy` on the region, 60% opacity (live search). |
| Empty | Centred 14px muted text with one next action (design.md section 7). |
| Upload | Empty: "No file yet." Uploading: `role="progressbar"` + %. Checking: spinner + "Checking". Done: `CircleCheck` + "Ready" (only on a clean scan). Error: icon + message + Retry. Announced with `aria-live="polite"`. |
| Disabled | `--muted` background, `--border` border, muted text (shadcn `disabled:` styles). |

## 8. Responsive and accessible

- Works from 360px (design.md section 6); grids collapse to one column; nothing scrolls sideways except a wide read-only table in its own box.
- Targets: 44px below md, at least 24px everywhere (buttons are 32 to 44px).
- WCAG 2.2 AA: text 4.5:1, UI boundaries 3:1, focus never hidden behind the sticky strip or bar (scroll-padding, app.css).
- Motion: 200ms or less, off under `prefers-reduced-motion`.
