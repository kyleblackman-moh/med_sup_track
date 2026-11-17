# NIPDEC Non-Objection Request System

MOH (Trinidad and Tobago) web app that digitises the non-objection process for
medical procurement: vendor → CMO → Technocrats + CAB → Legal → PS → letter.
Laravel 12, Inertia, React (TypeScript), Tailwind 4, shadcn/ui, SQL Server, Pest.

Full specification: docs/spec.md (state machine, permission matrix, data model,
open questions). UI rules: design.md. Read the relevant section before changing
workflow, permissions or screens.

## Hard rules (never break these)
- Tests: Pest with DatabaseTransactions only. There is no test database; do not suggest one. Never run or suggest migrate:fresh, migrate:reset, db:wipe or RefreshDatabase.
- Never run migrate:rollback unless `php artisan migrate:status` shows the target migration as Ran. Prefer `--pretend`.
- SQL Server: no restrictOnDelete() (default NO ACTION is correct); no hard deletes and no cascades.
- Never edit .env, never push with force, never install a package without asking Kyle.
- Out-of-scope case access returns 404, never 403. (A missing permission or a terminal status returns 403.)
- User-typed text (reasons, notes, instructions) never goes into an audit payload. Store flags and lengths; the text lives in its own table.
- No SLA UI (target days, "Over target", lateness colours) until sla_policies holds values.
- Stage commits by explicit path. Never git add -A or git add . Leave package-lock.json and design.md unstaged unless Kyle says otherwise.
- PowerShell commands given to Kyle: plain single-line, no angle-bracket placeholders, no "PS C:\>" prefix.
- Shared Inertia props: never add keys under `can`; page props named `can` override shared ones.

## Rules for every change
- Vendors are data (`organisations.type = VENDOR`). Never hard-code a vendor name, id or prefix.
- Every status change goes through `CaseTransitionService`; rules live in
  `app/Workflow/TransitionRules.php`. No controller sets `status` directly.
- Server code checks permissions, never role names. React never checks roles or
  `user_type`; shared `can` flags only show or hide UI. Every endpoint authorises itself.
- Access = permission + case status + assignment (`CasePolicy::act`). Vendor users are
  scoped to their org; staff never see DRAFT cases; external IDs are ULIDs.
- Vendors never receive internal content (messages, documents, notification text).
- `case_transitions` and `audit_logs` are insert-only. Cases are never deleted (no hard deletes, no soft deletes). Closed and withdrawn are statuses.
- Store UTC; display `America/Port_of_Spain`.
- Hardcopy received means `hardcopy_received_on IS NOT NULL`, read through
  `ProcurementCase::hardcopyReceived()`. The three hardcopy columns are all set or all null.
- Gate (IN_REVIEW → READY_FOR_FILE): technical favourable AND funding confirmed AND hardcopy received.
- Statuses marked [Assumption] or open questions (spec §18) need the CMO's Office: don't decide them in code.

## UI
- Read design.md first. Theme tokens only: no hex, font or radius values in components.
- Badge tone comes from the server (`status_tone`); React maps tone → classes.
- Page props may be named `can` for page-level action flags (existing convention). Never add keys to the shared `can`; nav-only flags live under `nav`.

## Working rules
- Ask Kyle before installing any package, including `npx shadcn add`.
- Before finishing: `php artisan test` (or the relevant `--filter`), `npx tsc --noEmit`,
  `npx eslint resources/js`, `npx prettier --check resources/`, and run Pint only on
  files you changed: `vendor/bin/pint path/to/file.php`.
- `npm run build` deletes `public/hot`; restart `npm run dev` afterwards.
