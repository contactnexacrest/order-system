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

Three separate sign-ins, never mixed:

- **Staff** (`/login`) — departments (Management, Sales & Export, Procurement,
  Logistics, Accounts & Finance, Export Compliance, Audit, Admin), roles and
  permissions decide what each person sees and does (SOP chapters 21, 25).
- **Buyers** (`/client`) — the client portal: their own orders, documents,
  payments and feedback (SOP chapter 12).
- **Associates** (`/associate`) — outside agents who introduce buyers: their
  own clients, orders, commission and payments, never prices, costs or
  documents (SOP chapter 24).

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
- **Owner decisions:** `docs/decisions/owner-decisions-register.md` — every
  decision already taken (standing rules, decisions by area, every test finding
  with its outcome, open points). Check it before raising any test finding.
- **Order workflow:** `docs/workflow/` — the workflow specification and the
  staff step-by-step checklist (HTML), kept in step with the order page.
- **Scheduled jobs:** each stack's README — "Bluehost cron setup" (PHP,
  `app/cron/`) and "Background jobs" (Node, `app/src/jobs/`).
- **Zoho Books integration:** the "Zoho Books" follow-up section of each
  stack's README, the `.env` switches in each `app/.env.example`, and SOP
  chapter `ca-03-zoho-sync.md`. Every change made in Zoho is logged in
  `docs/zoho/zoho-change-log.csv`.
- **QA test plan:** each stack's `docs/QA/TEST_PLAN.md`; the unit and
  integration tests are in each stack's `app/tests/` (PHPUnit for PHP, Jest
  for Node), and the browser suite is in `e2e/` (see `e2e/README.md`).
  GitHub runs PHPUnit and Jest on every push against MySQL 8
  (`.github/workflows/tests.yml`).
- **Standing rules for anyone changing the code:** `CLAUDE.md` (parity,
  tests on both stacks, ask before pushing, no document change without the
  owner's double confirmation, Standard/Kanban parity, browser-side
  validation, placeholders and labels on every input, money descriptions,
  Zoho change log).
- **Reference documents and spec:** `reference_templates/` (not read by the
  app at runtime).
- **Guides to print or share:** `docs/guides/` (also in `docs/SOP/guides/`
  and the in-app Reference Library) — the *Supplier & Logistics Payments
  Guide* (PDF + Word): supplier and logistics partner advance and balance
  payments, step by step with screenshots.
- **Who sees which money:** SOP chapter 21; the automatic proof that no
  amount reaches a role that may not see it is `e2e/tests/12-money-matrix.spec.js`.
