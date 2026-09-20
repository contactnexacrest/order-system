# NexaCrest Export Operations Webapp — Architecture Proposal (v1.0 draft)

Per Section 18 of the spec: this is Step 5–6 (schema + architecture). No application code has been written. Waiting for your confirmation before Step 8.

## 1. Confirmation of inputs read

- Full spec (`NexaCrest_WebApp_Complete_Prompt_v3.txt`), all 19 sections, read completely.
- All 42 files across the 8 ZIP folders were opened and their content extracted in the prior analysis pass (Tier 1 buyer-facing set, Internal set, Process/SOP set). The `04_RealOrder_Gabor` real-filled example referenced in Section 18 Step 3 has **not** been provided yet in any of the three ZIPs you sent — flagged as an open question below.

## 2. Tech stack decision

You said the stack must be platform-independent and run identically on Bluehost shared hosting and on your local machine. That constraint drives every choice below.

| Layer | Choice | Why |
|---|---|---|
| Language/runtime | PHP 8.1+ | Spec-mandated for Bluehost; runs unmodified on any OS locally (built-in `php -S`, XAMPP/MAMP/Laragon, or Docker) — no compiled binaries, no native modules. |
| Database | MySQL 8 / MariaDB | Bluehost standard; identical locally. |
| Dependency management | Composer | Vendor libraries download once, run anywhere — no build step, no native compilation. |
| PDF engine | **DOMPDF** | Pure PHP, renders HTML/CSS to PDF, zero external binaries. This is the deciding factor for your "must work everywhere" requirement: Puppeteer needs a Chromium binary + process-spawning rights, which most Bluehost shared plans block outright and which behaves differently across OSes; TCPDF works everywhere too but its layout API is coordinate-based (draw at x,y) rather than CSS-based, which makes matching your exact tables, amber rows, and navy bars far more error-prone. Your source documents are already table-heavy — DOMPDF's HTML/CSS model is the closest fit to "pixel-perfect replica." |
| DOCX engine | PHPWord | Pure PHP, same portability logic, spec-approved. |
| Templating | Twig | Pure PHP, Composer-installable. One template file per document type, driven entirely by DB data (`document_sections`, `tc_clauses`, `payment_presets`, etc.) — no HTML hardcoded with business values. |
| Routing | A small hand-rolled front controller (or `league/route`, ~20KB, pure PHP) | No framework lock-in. Laravel/Symfony-full assume things Bluehost shared hosting often doesn't give you cleanly (artisan queue workers, Redis, long-lived processes). A thin custom MVC avoids that entirely and is trivial to run locally with nothing but PHP + MySQL installed. |
| Email | PHPMailer | SMTP via Bluehost or SendGrid, both spec-listed. Also carries email-based 2FA codes — no extra dependency. |
| SMS (2FA only) | Third-party SMS gateway API (e.g. Twilio or MSG91) — **optional, not a hard dependency** | Checked at runtime via `.env` (`SMS_GATEWAY_API_KEY` present or not) rather than assumed available. If configured: users can choose email or SMS for 2FA. If not configured (no signup yet, or a deliberate choice not to): only email 2FA is offered, and the app runs exactly the same otherwise — no code change needed either way, no broken feature if SMS isn't set up. Email 2FA (via PHPMailer, already in the stack) has no external cost or dependency, so it always works regardless of SMS gateway status. |
| Sessions | Native PHP sessions, HTTP-only secure cookies | Spec-mandated; needs nothing extra. |
| Env vars | `vlucas/phpdotenv` reading a `.env` file **outside** web root | Matches Section 2's "never in source code, never in DB" rule for DB credentials, SMTP creds, session/encryption keys, storage base path. |

**Nothing here needs Node, a bundler, or a background worker daemon.** A cron-driven PHP script (Bluehost gives cron jobs on shared hosting) handles the scheduled jobs: deferred email sends, LUT/RCMC expiry alerts, balance-due day-N follow-ups/escalations, FDN overdue checks.

## 3. Folder structure

Bluehost shared hosting only exposes one web root (typically `public_html`); everything else in your hosting account is reachable by PHP but not by a URL. The same shape works identically on a local machine — only the absolute path changes.

```
<account root>/                     (e.g. /home/nexacrest on Bluehost, or your project folder locally)
│
├── public_html/                    ← WEB ROOT — only this is URL-reachable
│   ├── index.php                   (front controller — routes everything)
│   ├── .htaccess                   (deny directory listing, force HTTPS, route to index.php)
│   └── assets/                     (UI css/js/images for the app's own screens — NOT business files)
│
├── app/                            ← OUTSIDE web root
│   ├── src/
│   │   ├── Config/                (bootstrap, DB connection, env loader)
│   │   ├── Controllers/
│   │   ├── Models/                (one class per table, thin — query logic in Repositories)
│   │   ├── Repositories/
│   │   ├── Services/               (StageGateService, DocumentGenerationService, PaymentPresetService,
│   │   │                            PermissionService, AuditService, NotificationService,
│   │   │                            WatermarkService, FileStoreService, AmendmentService)
│   │   ├── Middleware/             (SessionAuth, PermissionCheck, CsrfCheck)
│   │   └── Helpers/
│   ├── templates/                  (Twig — one subfolder per document_type code: QT/, PI/, OC/, ... )
│   ├── cron/                       (scheduled jobs: deferred_send.php, expiry_alerts.php, balance_followup.php)
│   ├── vendor/                     (Composer packages)
│   ├── composer.json
│   └── .env                        (never committed; DB creds, SMTP creds, SESSION_SECRET_KEY,
│                                     APP_ENCRYPTION_KEY, STORAGE_BASE_PATH)
│
├── storage/                        ← OUTSIDE web root — exactly the structure in spec Section 3:
│   ├── assets/{logos,signatures,seals,watermarks,email_headers}/
│   ├── products/{product_code}/images/
│   ├── clients/{client_unique_number}/{order_reference}/stage_N_slug/{generated,received}/
│   ├── internal/{checklists,sops,process_refs}/
│   └── temp/{session_id}/
│
└── docs/                           (this proposal, schema.sql, seed.sql — not deployed to the server)
```

`STORAGE_BASE_PATH` in `.env` points at `storage/` above; every path the app builds is `STORAGE_BASE_PATH . '/' . <components from DB>`, per Section 3's path-construction rule. Locally, `STORAGE_BASE_PATH` just points at a folder on your machine — same code, same logic, zero changes.

## 4. How the DB-driven rule is actually enforced (not just a policy)

Three concrete mechanisms so "everything from DB" isn't just a comment in the code:

1. **No business value ever appears in a Twig template as a literal.** Templates only reference variables passed in from a `DocumentDataAssembler` service, which pulls every field (company details, payment terms, RBI codes, T&C text, section names/order) from the DB tables in `schema.sql`. A code reviewer can grep the `templates/` folder for anything that looks like "40" or "P0103" or "Bengaluru" and it should return nothing outside of DB seed data.
2. **`company_settings` is a key-value table, not fixed columns.** Adding a new configurable value later (a new alert lead time, say) is a DB row, not a migration + code deploy.
3. **`payment_presets`** is the single source for the Tier 1 / Tier 2 wording split identified as broken in your source documents (Order Confirmation and Commercial Invoice had Tier-2 language sitting inside the Tier-1 folder). With one OC template and one CI template reading `balance_trigger_option` off the order's assigned preset, that class of bug becomes structurally impossible — there's no second copy of the wording to drift out of sync.

## 5. Judgment calls made in the schema — please confirm or correct

The spec's Section 17 lists 28 entities as a **minimum**. I designed 46 tables. The extras exist because the spec's own described behavior needs them to actually function — flagging each so you can veto or adjust:

- **Normalized stage data instead of JSON blobs** (`order_payment_status`, `order_production`, `order_packing`, `order_crates`, `order_freight`, `order_shipping`, `order_supplier_po`). The spec's dashboard and reports section (16) needs to query "overdue payments," "active orders by stage," etc. — that's not workable against an opaque JSON blob per stage. Each stage's fields are their own table instead.
- **`ports`, `incoterms`, `currencies`** as real tables rather than free text, since the spec explicitly calls these out as Admin-manageable dropdowns with defaults.
- **`dropdown_options`** is one generic table for the many small Admin-editable lists the spec mentions (COO type, container type, supplier type, dispute status, the upload-confirmation-popup document-type lists, "received from" list) rather than one dedicated table per list — fewer near-identical tables, same DB-driven behavior. Tell me if you'd rather have them fully separate.
- **`document_sections` and `tc_clause_documents`** as junction/config tables so section order and which T&C clauses apply to which document type are both DB-editable per Section 4's requirement, not hardcoded per document type in code.
- **`login_attempts`** for the failed-login-lockout and audit requirements in Section 14.
- **`dispute_documents`** and **`product_images`/`order_annexure_images`** as junctions, since a dispute or a product can have multiple linked files/images.

**RESOLVED (2026-09-18):**
1. **Saved report definitions** — building this in. Adding a `report_definitions` table (name, owner_user_id, filter criteria as JSON, column list, shared-or-private flag) so recurring reports (overdue payments, stage-wise pipeline, buyer history) are configure-once, run-repeatedly rather than rebuilt from scratch each time.
2. **2FA** — both email code and SMS supported, user's choice per account. Schema note: email-code 2FA needs nothing beyond what's already planned (PHPMailer is in the stack); SMS 2FA needs a third-party SMS gateway (e.g. Twilio, MSG91) which is an external paid dependency not yet in the tech-stack table — adding it there, and a `phone_number` + `two_fa_method` column set on `users`, plus the backup-codes table.

## 6. Open questions before I proceed to code

1. ~~`04_RealOrder_Gabor/` was never in any of the 3 ZIPs you sent.~~ **RESOLVED (2026-09-18):** not needed — this was only ever intended as a sample to show the team, not a build input. Proceeding without it.
2. ~~Non-USD currency buffer~~ **RESOLVED (2026-09-18):** advisory-only. The buffer is a warning shown to the person creating the quote; the price is not auto-adjusted — a human decides manually whether to apply it.
3. ~~DOMPDF confirmation~~ **RESOLVED (2026-09-18):** confirmed, proceeding with DOMPDF.
4. ~~Build order~~ **RESOLVED (2026-09-18):** going with my recommended 5-phase grouping below, no harder mid-build checkpoint requested.
   - **Phase A** — schema + seed data, auth, RBAC, company settings, asset management
   - **Phase B** — client/order creation, Stages 1–3 (Quotation → PI → OC/Production), document generation pipeline (Twig → DOMPDF/PHPWord) proven end-to-end on these first three document types
   - **Phase C** — Stages 4–9 (Packing → Freight → BL → CI → Balance/Endorsement → Closure) and their documents
   - **Phase D** — review/approval workflow, deferred email send, amendments, disputes, audit log viewer
   - **Phase E** — dashboard, reports, security hardening pass
   I'll show you working output at the end of each phase rather than after every sub-step. Confirm this grouping works for you, or tell me where you want a harder checkpoint (e.g., you may want to see Phase B's generated QT/PI PDFs against the ZIP before I touch anything else).
