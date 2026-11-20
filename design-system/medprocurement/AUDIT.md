# AUDIT.md: Vendor Applicant screens

Audited 2026-11-19 against design.md v0.7.3, MASTER.md and WCAG 2.2 AA, in the order: accessibility, forms and validation feedback, interaction states, responsiveness, typography and colour. Static review of the source (no browser). The ui-ux-pro-max skill is not installed on this machine (see DECISIONS.md D2).

Severity: High (blocks or misleads a user), Medium (inconsistent or below the design rules, still usable), Low (maintainability).

| # | Severity | Area | Issue | Files | Status |
|---|---|---|---|---|---|
| 1 | Medium | Accessibility, colour | Error text uses `text-destructive` (#dc2626, 4.83:1, just over AA) instead of `text-destructive-text` (6.47:1, the design.md rule for error text). | ad-hoc/awarded-supplier-section.tsx:32, existing-arrangement-section.tsx:44, quotation-section.tsx:85, stock-section.tsx:69, variation-section.tsx:35, form-panel.tsx:66 and 74, type-cards.tsx:100, save-chip.tsx:42, pages/requests/ad-hoc/edit.tsx:344 | open |
| 2 | Medium | Forms, validation | Table-level messages ("Add at least one quotation.") are bare red text with no icon, unlike field and row errors (icon + text). | the five ad-hoc *-section.tsx files | open |
| 3 | Medium | Colour, theming | The older tabbed form uses the raw Tailwind palette with dark-mode variants (blue, amber, red) instead of the status tokens; dark mode is not maintained. | components/requests/form-tabs.tsx:111-138, row-list.tsx:151 and 266, submit-panel.tsx:35 and 50, pages/requests/edit.tsx:168 | open |
| 4 | Medium | Interaction states | Review and Submit has no busy state: while it saves and reloads, it can be pressed again and nothing shows that it is working. | pages/requests/ad-hoc/edit.tsx | open |
| 5 | Medium | Interaction states | Delete Draft on the older form is styled with `hover:bg-destructive/10` and `text-destructive`, not the shared destructive outline of the new form. | components/requests/form-actions.tsx:28 | open |
| 6 | Medium | Responsiveness | Below md the progress strip shows the current label in a narrow column; long labels wrap over several lines and make the sticky strip tall on phones. | components/ui/progress-strip.tsx (shared) | open |
| 7 | Medium | Colour, theming | Flash messages use the raw palette (green, red) with dark variants. | components/requests/flash-messages.tsx (shared with staff and admin pages) | open |
| 8 | Low | Typography | Arbitrary font sizes and letter spacing instead of named tokens: `text-[13px]` (26 uses), `text-[15px]` (5), `text-[22px]`, `text-[30px]`, `tracking-[0.08em]`, `tracking-[-0.01em]`. | ad-hoc/*.tsx, case-hero.tsx, supporting-documents.tsx, upload-progress.tsx, pages/requests/*.tsx | open |
| 9 | Low | Shape | Arbitrary sizes that Tailwind 4's scale already has: `ring-[3px]` (= `ring-3`), `border-b-[3px]` (= `border-b-3`); `rounded-[20px]` for the hero tile has no token. | ad-hoc/fields.tsx, case-hero.tsx, request-type or type-cards | open |
| 10 | Low | Forms | The older form is a tabbed wizard (Back / Next), which design.md section 11 rules out for the request form. It serves old drafts only. | pages/requests/edit.tsx, components/requests/form-tabs.tsx, form-actions.tsx | open |
| 11 | Low | Accessibility | The Documents category examples ("For example the RFQ, ...") are screen-reader only since FORM-COMPACT-1, so sighted users lose them. | components/requests/supporting-documents.tsx | open |

Checked and fine: one h1 per page; every input has a visible label above it; required fields carry `aria-required`; field and row errors are linked with `aria-describedby`; focus rings on all controls; 44px targets below md; the list search has a label and a hint; tables have captions and `th scope`; status pills carry text; uploads announce politely; no hex colours in the vendor files.
