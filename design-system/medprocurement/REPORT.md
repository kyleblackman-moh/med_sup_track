# REPORT.md: design system for the Vendor Applicant screens

Branch `design-system-vendor-applicant`, 8 commits on top of main (6b591c7 + FORM-COMPACT-1). Nothing is pushed or merged.

## Summary

- The app already had a design system (design.md v0.7.3). MASTER.md is the vendor section's digest of it: one canonical value per duplicate, the tokens to use, and the states design.md left open. design.md still wins (DECISIONS D1).
- 7 type, tracking and radius tokens were ADDED to app.css; the vendor files use them instead of bracket values (the compiled CSS is identical).
- 11 audit issues: 7 fixed, 4 deferred because they need a shared component changed or a product choice (AUDIT.md).
- Build, tsc, eslint and Prettier clean; 997 targeted tests passed (33 test files, run one at a time, DatabaseTransactions).

## Commits

| Commit | Phase | What |
|---|---|---|
| de4c2ae | 1 | MASTER.md, AUDIT.md, DECISIONS.md |
| b4e5258 | 2 | CLAUDE.md: Design section |
| 61d9a7e | 3 | Tokens added to app.css; vendor files use them |
| f2a0f71 | 4 | Request form |
| a17b2a1 | 4 | Request page (submit panel) |
| 43afc8d | 4 | Receipt |
| 265a5bd | 4 | Older tabbed request form |
| this one | 5 | AUDIT statuses, DECISIONS D13 and D14, REPORT.md |

## Files changed (git diff main --stat)

- Docs: CLAUDE.md, design-system/medprocurement/{MASTER, AUDIT, DECISIONS, REPORT}.md
- Theme: resources/css/app.css (11 lines added in `@theme`, nothing renamed or removed)
- Vendor pages: pages/requests/ad-hoc/edit.tsx, pages/requests/edit.tsx, index.tsx, receipt.tsx
- Vendor components: components/requests/ad-hoc/{awarded-supplier-section, existing-arrangement-section, fields, form-panel, quotation-section, request-type-section, row-controls, save-chip, stock-section, type-cards, variation-section}.tsx; components/requests/{case-hero, field-input, form-actions, form-tabs, row-list, submit-panel, supporting-documents, upload-progress, vendor-queries}.tsx
- Test: tests/Feature/FormHelperTextTest.php (the helper class it pins is now `text-helper`, D13)
- No shared component (components/ui, layouts, page-header, flash-messages, new-request-button, request-summary, requisition-view, requests/fields.ts), route, controller, prop, field name, validation or rule was changed. No package added, no file deleted.

## What changed visually, page by page

| Page | Change |
|---|---|
| All vendor pages | None visible from the token swap (same sizes, same CSS). |
| Request form (`/requests/{id}/edit`) | Error text is the darker error red everywhere (6.47:1, was 4.83:1 in 11 places). Table messages such as "Add at least one quotation." now carry the same alert icon as field errors. Review and Submit greys out and shows a spinning icon while it saves and checks; it cannot be pressed twice. |
| Request page (`/requests/{id}`) | The Submit Request panel is a white card like the rest of the page; its "not submitted" and "cannot be submitted yet" lists use the error red token. Confirm and Submit reports busy to screen readers while submitting. |
| Receipt (`/requests/{id}/receipt`) | "Back to Request" is MOH blue with a visible focus ring. Print is unchanged. |
| Older tabbed form (old drafts only) | Tab colours come from the status pairs: complete tabs light blue with dark blue text, tabs that need information light amber with brown text and a white count badge; the save alert uses the danger pair; row errors the error red; Delete Draft the same red outline as the new form. Dark-mode colours removed. |
| Requests list (`/requests`) | No visible change (already consistent, D14). |

## Decisions (DECISIONS.md, in short)

D1 MASTER.md digests design.md, does not replace it. D2 no ui-ux-pro-max skill on this machine; audited against design.md and WCAG 2.2 AA. D3 scope = requests.* screens; five components there are shared and untouched. D4 no scoped copies of shared components (design.md forbids duplicates). D5 Tailwind 4 config is app.css; additive only. D6 which values became tokens. D7 `border-[1.5px]` and `max-h-[90vh]` stay. D8 no step wizard (one-page form). D9 builds ran; public/hot survived here, restart `npm run dev` if pages look stale. D10 targeted tests only. D11 commits by explicit path; no WIP commit was needed. D12 the Review and Submit busy state. D13 one test file updated. D14 the list needed no phase 4 change.

## Manual test checklist (Kyle)

Sign in as the dev vendor. Restart `npm run dev` first if styles look stale.

1. Requests list: tiles, search (type, see the count update), a row link, Delete on a draft, the empty state on an organisation with no requests.
2. New Request: lands on the form. Pick each type card; the 1.5px blue border and halo on the chosen card.
3. Form errors: press Review and Submit on an empty draft. The button greys with a spinner, then the error summary appears. Each section's "things to fix" list and each table message ("Add at least one ...") is the darker red with an icon. Click a summary link: the field is focused and not under the progress strip or the bar.
4. Rows: add and remove a supplier, an order, a batch, a quotation, a variation; row errors show in the strip under the row.
5. Save Draft and the save chip; break the network (DevTools offline) and check the chip's red Retry link.
6. Documents: upload, Checking, Ready; a bad file with Retry; Replace; Remove with the reason box.
7. Submit: Review and Submit on a complete draft, the summary, Submit, the confirm dialog.
8. Request page: the Submit Request card on a draft that cannot be submitted yet (red list), and on one that can (Confirm and Submit, busy while submitting).
9. Receipt: Tab to "Back to Request" (blue focus ring), Print preview.
10. Older form: open an old AD_HOC_PHARM or AD_HOC_NON_PHARM draft if one exists (none are created any more): tab colours, the count badge, a save error alert, Delete Draft.
11. At 375px wide: every page above, no sideways scroll, buttons at least 44px tall.
12. Keyboard only: Tab through the form and the request page; every control shows a ring.

## Known issues and next steps

- Deferred (AUDIT 6, 7): the phone progress strip can grow tall, and FlashMessages still uses the raw palette. Both are shared components; with your go-ahead they are small in-place changes (no copies).
- Deferred (AUDIT 10): the older tabbed form is still a wizard. It serves old drafts only; retire it once none remain.
- Deferred (AUDIT 11): decide whether the Documents category examples should show again (they are screen-reader only since FORM-COMPACT-1).
- design.md section 5 still lists the pre-FORM-COMPACT spacing (24px card padding, 20px between fields); MASTER.md section 3 has the shipped values. Update design.md when you next edit it, and add the new tokens to its tables.
- The checks were static (source and compiled CSS); nothing was looked at in a browser.
