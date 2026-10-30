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
│   ├── cron/                       (scheduled jobs: dispatch_deferred_emails.php, auto_confirm_oc_acknowledgments.php,
│   │                                check_alerts.php, zoho_sync.php; run by hand: zoho_cleanup_test_records.php,
│   │                                reorganize_order_files.php)
│   ├── vendor/                     (Composer packages)
│   ├── composer.json
│   └── .env                        (never committed; DB creds, SMTP creds, STORAGE_BASE_PATH —
│                                     PHP's native session needs no app-level secret key)
│
├── storage/                        ← OUTSIDE web root — exactly the structure in spec Section 3:
│   ├── assets/{logos,signatures,seals,watermarks,email_headers}/
│   ├── products/{product_code}/images/
│   ├── clients/{client_unique_number}/{order_reference}/NN-step-name/{generated,received}/   (01-quotation … 13-closure — see helpers OrderStorage)
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

## 7. Additions since v1.0 (owner decisions, 27–29 Oct 2026)

Both stacks carry the same additions (parity rule). Schema sections are in `docs/schema.sql`; each has a guarded, re-runnable migration in the repository's `docs/migrations/` (information_schema + PREPARE, MySQL 8 safe). The run order for a live site is in `docs/migrations/README.md`.

| Area | Data | Code |
|---|---|---|
| Vendor bills — supplier advance/balance (Steps 7e / 10a) and logistics partner bills | `order_vendor_bills` (Section CE); `payment_kind` = `advance` / `final` for logistics bills (Section CG, migration `2026-10-27g`) | `VendorBillController`, `OrderExpenseController`, `OrderVendorBillRepository`, `OrderExpenseRepository`. A logistics Final bill on CFR/CIF is payable only after Step 9d; an Advance any time. GST is a rate from `dropdown_options('gst_rate')`, recomputed on the server |
| Order expenses — actual, with receipt or reason | `order_expenses` (Section CE, migration `2026-10-27e`); upload contexts `vendor_bill`, `expense_receipt` | Actual travel replaces the provisional travel % in profitability; FY lock respected |
| CAFIN — internal financial annexure | `documents` row of type `CAFIN` (category `internal`); permission `view_cafin` (migration `2026-10-28a`) | `app/src/Services/CaFinancialSnapshotService.php` builds the pre-formatted snapshot; template `templates/CAFIN/ca_financial_annexure.html.twig`. Generated from the CAFIN box at the top of the order page at any stage (no per-order enable, not behind the order lock), and generated automatically at **Mark Fully Settled**. Seeing the box, CAFIN rows in document lists and reports, and every download of it needs `view_cafin` (`/documents/{id}/download` accepts `download_pdf` or `view_cafin`, then checks CAFIN separately). Never reachable by the client |
| Document date (Commercial Invoice) | `documents.document_date` (Section CF, migration `2026-10-27f`); NULL = the day generated | The CI's DATE and the COO prep sheet's CI date use it, including the approval re-render |
| Supplier PO with 0% advance / 0% GST | `order_supplier_po.advance_pct` / `advance_amount_inr` and `gst_rate_pct` / `gst_amount_inr` keep 0 (only blank is NULL) | Step 7e completes when no advance is planned; a 0% GST Supplier PO prints "0.00% — 0.00" |
| Vendor bills — proforma / tax invoice and part payments (Option B, 28 Oct) | Section CH (migration `2026-10-28e`): `order_vendor_bills.doc_kind` (`proforma` / `invoice`), `requested_amount_inr`, `replaces_bill_id`; new table `order_vendor_bill_payments` (one row per payment, `leg` = `advance` / `balance` for supplier payments). The bill's own `paid_at` / `paid_amount_inr` are kept as a summary (settled date, total paid) | `VendorBillController`, `OrderVendorBillRepository` (`forOrder()` enriches each bill: payments, credited, remaining, settled, cost), `OrderProfitabilityService`, `OrderLedgerService`, `CaFinancialSnapshotService`. A proforma is not a cost; a tax invoice can replace an earlier proforma from the same vendor (what was paid on it counts towards the invoice); overpayment is refused; a 7e tax invoice still owed is shown in 10a. Cost = tax invoices + payments on proformas not yet replaced; the ledger has one line per payment; CAFIN prints each bill as "Proforma …" / "Tax invoice …" with every payment |
| Payment-terms approval before the Quotation | `orders.payment_terms_approved_by` / `payment_terms_approved_at`; permission `approve_payment_terms` (migration `2026-10-28c`) | `PaymentTermsApprovalService`, `OrderController::approvePaymentTerms`, route `POST /orders/{id}/payment-terms/approve`. An order on a payment preset marked "Requires MD approval" needs its terms approved (default Admin, MD, ED) before its Quotation can be generated or sent; the preset flag is read live |
| CA role can open orders read-only | `role_permissions`: CA gets `view_orders` and `view_cafin` (migrations `2026-10-28a`, `2026-10-28d`) | CA-page order links show only where the user can open the order |
| PI / CI payment wording (owner decisions 28 Oct, confirmed twice on previews) | — | `DocumentDataAssembler` (`bank_charges_text`, `cleared_funds_text`, `has_advance`, `payment_method`), `DocumentGenerationService::clauseTextReplacements()`, `Docx/DocxDocumentBuilder`; templates `PI/proforma_invoice.html.twig`, `CI/commercial_invoice.html.twig`. PI: amber **ADVANCE PAYABLE NOW** box (advance, due date, balance "not payable now", bank charges) or, with no advance, **NO ADVANCE PAYABLE — … payable by Letter of Credit**; cleared-funds sentence only when there is an advance; CFR "Freight is paid…", CIF "Freight & insurance are paid…". CI: Bank Charges row. Word QT/PI/OC/FDN buyer deadlines in Calendar Days; FDN due date = today + 3 calendar days |
| Order access, links and validation (QA Round 5) | — | `OrderAccessService` (one order-access guard on every `/orders/{id}/*` route), `OrderEditGuard`, `Helpers/PermittedLinks` (links only where the viewer can open them), `Helpers/FieldValidator` (server checks matching the browser ones), `Helpers/DocumentLabel`, `View::asset()` (adds `?v=` to app.js / app.css so a deploy is picked up at once) |
| Tests added in QA Round 5 / 28 Oct | — | `tests/Integration/RouteMatrixTest.php`, `UngatedRoutesTest.php`, `PaymentTermsWordingTest.php`, `PaymentTermsScenariosTest.php`, `VendorBillProformaTest.php` (every route is covered; the e2e critical path downloads the generated PI and CI and checks their wording) |
| Shared browser behaviour | — | `public_html/assets/js/app.js`: `initGstCalc()` (GST % → amount and total) and `initAutoReasons()` (Not Applicable / Not Eligible fill their reason), `initShowWhen()` (`data-show-when` shows a field only for one choice, e.g. Amount asked now for a proforma), alongside the sitewide form validation (`fieldError()`, including `data-match` and `data-not-more-than`) |
| Zoho safety switches (29 Oct) | `.env`: `ZOHO_INTEGRATION_ENABLED`, `ZOHO_ENVIRONMENT` (`test` / `production`), `ZOHO_EINVOICE_ENABLED` — always written out; a missing or unknown value counts as off / test / off | `ZohoGuard` — every Zoho request goes through `assertRequestAllowed()`. Off: no Zoho call is made. `test`: only customers named `TEST-…` may be sent. GST e-invoice / e-way bill / GST filing only with the integration on, `production` and `ZOHO_EINVOICE_ENABLED=true`. Read from `.env` only, so no screen can change it. Zoho Mail is not used to send (`ZohoMailService::IN_USE = false`; all mail goes through SMTP) |
| Zoho Books sync (Stages 1–5, 29 Oct) | Settings `zoho_books_*` (credentials, `zoho_books_enabled`, go-live date `zoho_books_sync_start_date`, `zoho_books_restore_checked_at`; migrations `2026-10-29d`, `2026-10-30a`); `zoho_sync_log`; `order_zoho_invoices` (Section CI, `2026-10-29a`); Books ids on `clients`, `suppliers`, `logistics_partners`, `order_vendor_bills`, `order_vendor_bill_payments`, `order_expenses`, `order_cost_entries`, `order_lc_details`, `ca_export_benefits`, `order_payment_status` (Sections CI, CJ, CL) | `ZohoBooksService` (OAuth self-client, `zoho.in` by default; on only when `ZohoGuard` allows it and `zoho_books_enabled` = 1), `ZohoInvoiceData` (the CI / Debit Note as an export invoice, at the shipping bill's customs rate), `CaSyncService::runFullSync()` (revenue: invoices and payments applied to them; purchases: vendors, bills, vendor payments, order expenses; expense import from Books; export benefits as journals), `ZohoRestoreService` (the app is the source of truth for what it sends: deleted in Books → sent again, changed → put back; a locked FY is logged for the CA instead; scheduled runs check once per 23 hours, Sync Now always). Run from Sync Now (`POST /ca/zoho-sync/run`) or `cron/zoho_sync.php` (hourly); "Test Books connection" is `POST /settings/test-zoho-books`. Only records dated on or after the go-live date are sent; in production nothing until it is set |
| App vs Zoho check (29 Oct) | — | `OrderZohoMatchService`, route `GET /orders/{id}/zoho-check` (`ca_module_manage`). Read only: match / not sent / before go-live / different / missing in Books / only in Books |
| Zoho test clean-up (29 Oct) | — | `cron/zoho_cleanup_test_records.php` — removes every `TEST-` record from Books (dry run unless `--apply`; `--from-sync-log` refused in production); `--log` writes the rows of `docs/zoho/zoho-change-log.csv` |
| Shipping bill and reverse charge (29 Oct) | `order_shipping.shipping_bill_no` / `shipping_bill_date` / `customs_exchange_rate`, `order_vendor_bills.gst_reverse_charge` (Section CK, migration `2026-10-29e`) | `OrderController::recordShippingBill`, route `POST /orders/{id}/shipping-bill` (`manage_shipping`). The CI and Debit Note go to Books at the customs rate; a reverse-charge transport bill goes with 5% GST as reverse charge |
| LC payment received (29 Oct) | `order_lc_details.lc_received_*`, `lc_inr_credited`, `lc_bank_charges_inr`, `lc_zoho_*` (Section CL, `2026-10-29f`); `lc_firc_reference` / `lc_firc_received_at` (Section CM, `2026-10-30a`) | `OrderFinancialsController::recordLcReceived` / `recordLcFirc`, routes `POST /orders/{id}/lc-received`, `POST /orders/{id}/payment/lc/firc`. Its own leg in the INR Settlement Register; CAFIN (`CaFinancialSnapshotService`) counts it as money received, and also counts expenses booked in Books and linked to the order (except a possible duplicate of a cost entered in the app) |
| 2-digit year in reference numbers (29 Oct) | `document_types.ref_format` and the order / tracking formats use `{YY}` (migration `2026-10-29c`) | `ReferenceNumberService` — `SC/CI/26/2910001` (a GST invoice number may have at most 16 characters); numbers already issued keep `2026`; `{YYYY}` still works in a format set by hand |
| Tests added 29 Oct | — | `tests/Integration/ZohoServerGuardTest.php`, `ZohoBooksStage1Test.php` … `ZohoBooksStage5Test.php`, `CafinLcAndZohoExpensesTest.php`, `ShippingBillTest.php` — every Zoho request goes to a fake Zoho (`ZohoBooksService::setTransport()`), never the real one |
| Team incentives and associates (30 Oct) | Tables `incentive_settings`, `user_incentive_roles`, `order_incentives`, `order_incentive_people`, `incentive_payouts`, `associates`; `clients.associate_id` / `associate_registered_on`; `order_cost_entries.incentive_kind` (migration `2026-10-30b`); permissions `manage_incentives` (Admin, MD), `pay_incentives` (Admin, MD, Accounts Executive), `view_own_incentives` | `IncentiveService` (fixed when the buyer has fully paid; the associate's commission is deducted first; team reward from the reward table or the amount set on the order, split by role; incentive month by the cut-off day), `IncentiveRepository`, `AssociateRepository`, `IncentiveController`; Step 12d on the order page. Each becomes a `commission` cost entry and goes to Zoho Books as Commission Expense only once paid (`ZohoPurchaseRepository::costEntriesToSync`) |
| CAFIN export (30 Oct) | — | `CafinExportService` (summary and detail CSV for a financial year and status, the same calculation as the CAFIN PDF, test/sample orders left out), `CaController::cafinExport`, route `GET /ca/cafin-export` (`view_cafin`); every download audit-logged. `Helpers/Csv` lets a plain negative number through unquoted |
| Stage document notice; CA sees all orders (30 Oct) | `role_permissions`: CA gets `view_all_orders` (migration `2026-10-30c`) | `DocumentGenerationService::stageDocumentGate()` — each gating button (4d, 5e, 6c, 7d, 9d, 10f, 11d) says what the stage's documents still need and stays disabled until then (the rule `stageDocumentBlockedBy()` enforces) |
| Associate agreement on file; buyer PO received date (30 Oct) | `associates.agreement_file_id` (upload context `associate_agreement`), `orders.buyer_po_received_on` (migration `2026-10-30d`) | `IncentiveController::saveAssociate` / `downloadAgreement`, route `GET /associates/{id}/agreement` (`manage_incentives`; the general `/file-store/{id}/download` refuses it to anyone else); `OrderController::recordBuyerPo` records the date (Step 4d), `BuyerActionService` the day the buyer uploads; `IncentiveService::withinFirstTenDays()` counts the associate's 10 days from the client's first PO received |
| Tests added 30 Oct | — | `tests/Integration/TeamIncentiveTest.php`, `CafinExportTest.php`, `StageDocumentGateNoticeTest.php`, `AssociateAgreementAndPoDateTest.php` |
| Correcting the buyer PO received date (30 Oct) | `orders.buyer_po_received_on` | `OrderController::correctBuyerPoReceivedDate`, route `POST /orders/{id}/buyer-po/received-date` (`manage_orders`): the date and a reason, audit-logged `BUYER_PO_RECEIVED_DATE_CORRECTED` |
