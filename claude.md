# NIPDEC Non-Objection Request System

MOH (Trinidad and Tobago) web app that digitises the non-objection process for
medical procurement: vendor → CMO → Technocrats + CAB → Legal → PS → letter.
Laravel 12, Inertia, React (TypeScript), Tailwind 4, shadcn/ui, SQL Server, Pest.

Full specification: docs/spec.md (state machine, permission matrix, data model,
open questions). UI rules: design.md. Read the relevant section before changing
workflow, permissions or screens.

## Rules for every change
- Vendors are data (`organisations.type = VENDOR`). Never hard-code a vendor name, id or prefix.
- Every status change goes through `CaseTransitionService`; rules live in
  `app/Workflow/TransitionRules.php`. No controller sets `status` directly.
- Server code checks permissions, never role names. React never checks roles or
  `user_type`; shared `can` flags only show or hide UI. Every endpoint authorises itself.
- Access = permission + case status + assignment (`CasePolicy::act`). Vendor users are
  scoped to their org; staff never see DRAFT cases; external IDs are ULIDs.
- Vendors never receive internal content (messages, documents, notification text).
- `case_transitions` and `audit_logs` are insert-only. No soft-deleting cases.
- Store UTC; display `America/Port_of_Spain`.
- Hardcopy received means `hardcopy_received_on IS NOT NULL`, read through
  `ProcurementCase::hardcopyReceived()`. The three hardcopy columns are all set or all null.
- Gate (IN_REVIEW → READY_FOR_FILE): technical favourable AND funding confirmed AND hardcopy received.
- Statuses marked [Assumption] or open questions (spec §18) need the CMO's Office: don't decide them in code.

## UI
- Read design.md first. Theme tokens only: no hex, font or radius values in components.
- Badge tone comes from the server (`status_tone`); React maps tone → classes.
- Don't name a page prop `can` (it overwrites the shared nav flags).

## Working rules
- Ask Kyle before installing any package, including `npx shadcn add`.
- Before finishing: `php artisan test` (or the relevant `--filter`), `npx tsc --noEmit`,
  `npx eslint resources/js`, `npx prettier --check resources/`, `vendor/bin/pint`.
- `npm run build` deletes `public/hot`; restart `npm run dev` afterwards.
