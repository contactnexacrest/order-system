# NexaCrest Order System — CRM

**Start here for the full picture: [docs/APPLICATION_HANDBOOK.md](docs/APPLICATION_HANDBOOK.md)**

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
  own clients, orders, commission and payments; what each one sees is set by
  their access level (Starter, Quoting, Full or Custom). Never costs, margins
  or supplier prices (SOP chapter 24).

What the app does at go-live (updated 6 Nov 2026), in brief — details in the SOP:

- The nine-stage export order with every document (PDF and Word), review and
  approval, and the client portal (SOP chapters 01–13).
- Money visibility by role: each person sees only the amounts their
  permissions allow, proven by an automatic test (SOP chapter 21).
- Procurement, logistics partner bills (Logistics enters, Accounts pays),
  coordination queues, Export Compliance (ECGC, COO, eBRC, export benefits),
  the Agreements register, associates with commission and TDS (chapters 22–26).
- Pricing from the supplier price (D-245): minimum price, staff and associate
  floors and quote prices; below the floor the MD approves, below the minimum
  nobody does; the Quotation and Proforma Invoice wait for it (chapters 01, 27).
- Associate commission on the invoiced FOB, full payment only; the team
  incentive per order, locked by the MD; profit shown estimated / locked / paid
  (chapters 20, 24).
- Remittances with "why is it different?" reasons and bank charges booked at
  receipt (chapters 03, 06, 08).
- Zoho Books: hourly sync (with optional client and product sync), a
  "Waiting for Zoho" count, call limits for this app and failure e-mails
  (CA chapter ca-03).
- Divisions: today's system is the Export division. Domestic Trade, Import &
  Distribution and Merchant Trade are built but stay off until switched on in
  Admin › Divisions (chapters 28–31).
- Product categories decide units, HS code, packing, quality documents and T&C;
  Natural Stone documents are unchanged (chapter 27).
- Team incentive eligibility (per person and designation) and caps; the
  company keeps what is not paid out (chapter 20).
- Associate access levels set by the MD (chapter 24).
- 10-year encrypted backups of the database and every document, with a
  monthly test-restore and Admin › Backups (chapter 32).

Where to look:

- **Setup and deployment:** each stack's own `README.md`.
- **Architecture:** `PHP_MySQL_Version/docs/ARCHITECTURE.md` and
  `NODE_MySQL_Version/docs/ARCHITECTURE.md` (same content, each with its
  own file names), and the diagram-led overview of both stacks in
  `ARCHITECTURE.html` (open it in a browser).
- **Database install and changes:** go-live = each stack's `docs/schema.sql` then
  `docs/seed.sql`, no migrations (D-241). How a change ships after go-live:
  `docs/migrations/README.md`. `docs/checks/` holds read-only checks for a
  database that already held data before an earlier release; the first live
  install starts empty, so they are not needed there.
- **Go-live day:** `docs/guides/GO_LIVE_CHECKLIST.md`; questions still open with
  the CA, AD bank and lawyer: `docs/external/`.
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
