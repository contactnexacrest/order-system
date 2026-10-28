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
  own file names).
- **Upgrading a live database:** `docs/migrations/README.md` (run order,
  checks), with the read-only checks in `docs/checks/`.
- **Staff manual (SOP):** `docs/SOP/` (copied into each stack's
  `docs/SOP`, where the in-app SOP reads it).
- **QA test plan:** each stack's `docs/QA/TEST_PLAN.md`; the browser suite
  is in `e2e/` (see `e2e/README.md`).
- **Reference documents and spec:** `reference_templates/` (not read by the
  app at runtime).
