# Ad Hoc vendor form: field spec and decisions

Version 0.1 (2026-11-16). Phase 1 rework of the vendor submission form (replaces the Pharm / Non-Pharm split built in slices 8b to 8d). Source: NIPDEC's Ad Hoc Purchase letter templates (Pharm and Non-Pharm) plus Kyle's decisions below.

## Decisions (Kyle, 2026-11-16)

1. There is NO Pharm / Non-Pharm distinction. One Ad Hoc form. Price Increase and Tender Excess are separate forms, built later as explicit forms that share section components (Item, Documents). No form builder, no schema engine.
2. Totals and percentages are computed by the server and appear after each autosave (no live client-side maths, design.md section 8).
3. Quantity is typed in figures only. Quantity in words is derived by the server when the letter is generated.
4. The award table and the order history table show only when the tender outcome is "Partially awarded" or "Awarded, supplier unable to supply". They are hidden when nothing was awarded.
5. Each table has an add button at the side (add supplier, add order). Order rows pick the supplier from the award rows (typed once); one supplier can have several orders.
6. "% awarded" = quantity awarded / total awarded quantity (server computed).
7. Documents: three categories (Bids, Regulatory documents, Evidence), each accepting multiple files (drag and drop or a Choose files button). At least one Bids file is required to submit; the other two are optional. Which documents go in which category is NIPDEC's call; the zones show a short hint only and enforce nothing.
8. The clinical-importance note from the template stays as one optional text box in the Item section.
9. DIN is optional: digits only, no length rule until the CMO's office confirms the format.

## Layout

One page, collapsible sections, progress rail, autosave draft, read-only Review and submit summary. Save draft always works with partial data; only Submit validates and lists problems by section with jump links. Tables start with one row.

| # | Section | Vendor types | Shown when |
|---|---|---|---|
| 1 | Item | Description (required), strength, dosage form, DIN, quantity requested + unit, optional clinical-importance note | always |
| 2 | Tender background | Tender period / reference, outcome (not awarded, partially awarded, awarded but supplier unable to supply) | always |
| 2a | Award table | Supplier, manufacturer, brand, quantity awarded, unit cost | outcome is not "not awarded" |
| 2b | Order history | Supplier (from award rows), PO number, quantity ordered, date ordered, quantity delivered, delivery date / status, plus one text box for issues affecting the tender quantity | outcome is not "not awarded" |
| 3 | Stock | Location, average monthly consumption + unit, stock batches (quantity, expiry date); "No stock on hand" flag hides the batches | always |
| 4 | Quotations | RFQ sent to (all registered suppliers / specified suppliers), one row per quotation (supplier, manufacturer, brand, unit price, quantity offered, lead time, considered yes/no + reason if no), one recommended row, quantity to purchase | always |
| 5 | Price comparison (optional) | Tender price, previous price, "quoted price is acceptable" flag + note | collapsed until opened |
| 6 | Documents | Three drop zones: Bids, Regulatory, Evidence | always |

## Computed by the server (never typed)

Number of suppliers awarded, % awarded, line and total costs, quantity outstanding, stock-on-hand total, number of quotations received, price change %, quantity in words (at letter time).

## Document upload rules

- Multiple files per category, drag and drop or Choose files (the button is the real control; drop is an enhancement). Native browser drop events, no new package.
- One upload request per file, sent in sequence, so one bad file does not fail the batch. Per-file state: Uploading, Scanning, Ready, Rejected (with reason).
- Reuse the existing documents table, ClamAV scan and signed-URL rules. Limit types and size (25 MB, matching the php.ini plan).
- Before submit, "remove" marks the file removed; nothing is hard-deleted.

## Sub-slices (all Phase 1, before the CMO test session)

- FORM-0: read-only mapping of the current form to this spec (impact, migration plan, test count).
- F1: data model and rules. F2: vendor form sections 1 to 5. F3: document drop zones. F4: staff case page shows the new fields.

## Not in scope

Price Increase and Tender Excess forms, pre-fill from a previous request, live totals, form builder, letter generation (blocked until the CMO's letter template is obtained).

## Open questions for the CMO's office

- Which documents belong under Bids, Regulatory and Evidence (current guess: Bids = RFQ, comparison sheet, quotations; Regulatory = registration, specifications; Evidence = stock and usage, correspondence, sample reports). NIPDEC decides; hint text is editable.
- Is the DIN format the standard 8 digits? (Until confirmed: digits only, no length rule.)
- Is "Bids: at least one file" the right submission rule?
- Do Strength, Dosage form and DIN stay optional for non-drug items? (Assumed yes.)
