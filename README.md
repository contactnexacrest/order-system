# NexaCrest Order System — CRM

Internal order-management CRM covering NexaCrest International's complete
export order lifecycle, start to end: from the moment a Zoho-qualified buyer
is sent a quotation-stage form link, through all 9 pipeline stages, to final
document despatch and order closure. Lead capture/nurture stays in Zoho and
is out of scope here.

Two parallel, always-in-parity stacks:

- `PHP_MySQL_Version/` — PHP + MySQL, deployable on Bluehost shared hosting
  today (DOMPDF/PHPWord/Twig, no Composer step needed to run it).
- `NODE_MySQL_Version/` — Node + MySQL, VPS-ready so the org can shift to it
  at any time without redevelopment (Puppeteer-based PDF generation).

Both stacks share the same MySQL schema and business rules, and every
feature is built and verified in both before being considered done.

Where to look:

- **Setup and deployment:** each stack's own `README.md`.
- **Architecture:** `PHP_MySQL_Version/docs/ARCHITECTURE.md` and
  `NODE_MySQL_Version/docs/ARCHITECTURE.md` (same content, each with its
  own file names), and the diagram-led overview of both stacks in
  `ARCHITECTURE.html` (open it in a browser).
- **Upgrading a live database:** `docs/migrations/README.md` (run order,
  checks), with the read-only checks in `docs/checks/`.
- **Staff manual (SOP):** `docs/SOP/` (copied into each stack's
  `docs/SOP`, where the in-app SOP reads it).
- **Order workflow:** `docs/workflow/` — the workflow specification and the
  staff step-by-step checklist (HTML), kept in step with the order page.
- **Scheduled jobs:** each stack's README — "Bluehost cron setup" (PHP,
  `app/cron/`) and "Background jobs" (Node, `src/jobs/`).
- **Zoho Books integration:** the "Zoho Books" follow-up section of each
  stack's README, the `.env` switches in each `app/.env.example`, and SOP
  chapter `ca-03-zoho-sync.md`. Every change made in Zoho is logged in
  `docs/zoho/zoho-change-log.csv`.
- **QA test plan:** each stack's `docs/QA/TEST_PLAN.md`; the unit and
  integration tests are in each stack's `app/tests/` (PHPUnit for PHP, Jest
  for Node), and the browser suite is in `e2e/` (see `e2e/README.md`).
- **Reference documents and spec:** `reference_templates/` (not read by the
  app at runtime).
