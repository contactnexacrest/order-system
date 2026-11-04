# NexaCrest Order System — QA Test Plan

This is the master test plan for both stacks (`PHP_MySQL_Version` and
`NODE_MySQL_Version`), which are functionally identical ports of the same
application. Every fact below (route counts, table names, role grants) was
derived by reading the actual source — `public_html/index.php` /
`src/server.js`, `docs/schema.sql`, `docs/seed.sql`,
`StageGateService` — not estimated. The full route-by-route,
table-by-table listing lives in [`_inventory_raw.md`](./_inventory_raw.md);
this document is the plan built on top of it: what to test, in what order,
and why.

This plan does not separately re-cover the CA/Reports content-gap analysis
or the wet-signature-required flag — both have since been closed (new
reports/CA additions with their own PHPUnit/Jest coverage in
`ReportGapsTest`/`CaGapsTest` and their Jest equivalents; the flag with its
own `WetSignatureGuardTest`/`wetSignatureGuard.test.js`), and are exercised
through those dedicated suites rather than duplicated here.

## 1. Scope and application shape

- **2 stacks**, PHP/MySQL and Node/MySQL, route-for-route identical: **489
  routes each** — 165 GET, 324 POST (`public_html/index.php` /
  `src/server.js`; counts as of 29 Oct 2026).
- **119 database tables** (`docs/schema.sql`), 24 of which (at the time
  of the original inventory) hang directly off
  `orders.id` (the order-lifecycle-dependent tables — see
  `_inventory_raw.md` §3 for the full list).
- **8 roles, 63 permissions** (`docs/seed.sql`), plus two mechanisms that
  sit outside the role/permission matrix entirely and must be tested
  separately: per-user permission overrides (`user_permissions`) and the
  Super Admin tier (`users.is_super_admin`, `super_admin_delegations`),
  which bypasses permission checks altogether.
- **A 9-stage order pipeline** (`stages_master` / `StageGateService`) that
  every order moves through, plus standalone modules that hang off an
  order at any point: Amendments, Disputes, Order Comments/Chat, Order-Edit
  & Duplication, Reorder Requests.
- **A client-facing portal** (`ClientPortalController`, 18 routes) with its
  own authentication (`ClientAuth`, not `SessionAuth`) and its own
  data-isolation requirements (a client must never reach another client's
  order).
- **A CA/Accounts module** (23 routes on the CA controller, 6 tables) with a
  financial-year lock mechanism that must reject writes once a period is
  locked, except through the explicit override permission, and the Zoho
  Books sync, which must never reach Zoho unless `.env` allows it
  (`ZohoGuard`).
- **A Test Mode** that globally blocks client-facing routes and
  admin-settings writes while enabled — its own gate needs testing
  independent of anything else.

## 2. Roles and permissions

| Role | Permission scope |
|---|---|
| Admin, Managing Director, Executive Director | All permissions (wildcard grant in seed.sql), including `approve_payment_terms` and `view_cafin` — except that Executive Director does not hold `manage_incentives` or `pay_incentives` (team incentives: owner and MD; payouts also Accounts Executive, 2026-10-30), so Admin and MD hold 63 and Executive Director 61 |
| Export Executive (17) | manage_orders, manage_payments, manage_shipping, close_orders, generate_documents, download_pdf, view_reports, view_client_email_full, cross_verify_documents, override_buyer_acknowledgment, respond_to_disputes, respond_to_complaints, view/browse product catalog, view_product_pricing, view_archived_orders, view_own_incentives |
| Accounts Executive (22) | view_cafin, manage_orders, manage_payments, clear_payments, manage_supplier_payments, download_pdf, view_reports, view_all_orders, view_client_email_full, cross_verify_documents, override_buyer_acknowledgment, view/browse product catalog, view_product_pricing, view_archived_orders, ca_module_view, ca_module_manage, inr_actual_view, inr_actual_edit, ca_fy_lock_override, pay_incentives, view_own_incentives |
| Logistics Executive (11) | manage_orders, manage_shipping, close_orders, generate_documents, download_pdf, cross_verify_documents, override_buyer_acknowledgment, view/browse product catalog, view_archived_orders, view_own_incentives |
| Viewer / Auditor (6) | view_reports, view_audit_log, view_product_catalog, view_orders, view_clients, view_all_orders |
| CA / Chartered Accountant (5) | ca_module_view, inr_actual_view, view_cafin, view_orders, view_all_orders (read-only — no order-editing access; sees every order, owner decision 2026-10-30) |

63 permissions span 8 categories: admin (11), reports (3), orders (17),
documents (6), clients (3), catalog (11), ca (9), incentives (3: `manage_incentives`, `pay_incentives` — also Accounts Executive, `view_own_incentives` — also Export, Accounts and Logistics Executive). `_inventory_raw.md` §1
holds the original (pre-QA Round 5) grant list; `docs/seed.sql` is the
source of truth.

**Authorization test angle for every route**: a route's `PermissionCheck`
requirement is necessary but not sufficient — QA-1 already found one case
(stage-gate routes) where holding the permission wasn't enough on its own
to make an action valid; the *state* of the resource being acted on
matters too. Section 6 generalizes this.

## 3. Functional module inventory

Each module below: route count, primary tables, and its single biggest
testing risk (elaborated in Section 6's priority tiers).

| Module | Routes | Key tables | Biggest risk |
|---|---|---|---|
| Auth (staff) | 11 | users, login_attempts, password_reset_tokens, two_fa_backup_codes | Account lockout bypass, password-reset token reuse/expiry |
| Client Portal | 18 | clients, client_logins, client_password_reset_tokens | Cross-tenant IDOR — one client reaching another's order/documents |
| Clients (CRM) | 9 | clients | — |
| Orders — core pipeline | 61 | orders, order_stages, order_products, order_payment_status, order_production, order_supplier_po, order_packing, order_crates, order_freight, order_shipping | **Stage-gate integrity** (QA-1's class of bug) |
| Client/PI Intake + Review | 12 | client_intake_submissions, pi_intake_submissions | Public unauthenticated endpoints — input validation, token guessing |
| Reorder Requests | 4 + 2 client routes | order_reorder_requests, order_reorder_request_products | Staff review is not bypassable — a reorder request must never become a real order without approval |
| Annexure | part of Orders' 61 | order_annexure_products, order_annexure_images | File upload validation |
| Order Comments/Chat | part of Orders' 61 + 1 client route | order_comments, order_comment_attachments | Client must only see their own order's thread |
| CA / Accounts | 23 | ca_expenses, ca_bank_statement_lines, ca_fy_locks, ca_export_benefits, zoho_sync_log, order_zoho_invoices | **FY-lock enforcement** on every write path; nothing sent to Zoho unless `.env` allows it |
| Documents (generate/review/send) | 16 | documents, document_revisions, document_reviews, document_cross_verifications, file_store, email_log | Draft→review→approved→sent state machine; email approval gate |
| Amendments | 7 | amendments | MD-approval gate cannot be skipped |
| Disputes | 5 | disputes, dispute_documents | Working-day deadline math; client visibility toggle |
| Reports | 14 | report_definitions (+ reads across nearly every table) | Numeric correctness, role-scoped visibility (view_staff_reports) |
| Admin/Settings/RBAC | 82 | roles, permissions, role_permissions, users, user_permissions, + ~15 more settings tables | Permission-matrix edits must not lock out Admin itself |
| Test Mode | 5 | test_mode_settings | Global gate must actually block every client-facing route + admin write while enabled |
| Sample Data Playground | 3 | (writes across most tables) | Must never run against a database with real data |
| Product Catalog (internal) | 16 | catalog_products, catalog_product_images, catalog_product_suppliers, catalog_product_misc_charges | — |
| Audit Log | 2 | audit_log | Must be genuinely append-only (no update/delete anywhere in the app) |

## 4. The 9-stage order pipeline

| # | Stage | Gate-passing action | Route |
|---|---|---|---|
| 1 | Enquiry & Quotation | QT document generated | `POST /orders/{id}/documents/generate` |
| 2 | Buyer Purchase Order | Buyer PO ref recorded | `POST /orders/{id}/buyer-po` |
| 3 | Proforma Invoice | Advance payment cleared | `POST /orders/{id}/payment/advance/clear` |
| 4 | Order Confirmation | Buyer acknowledges OC (staff-recorded, client-portal, or 48h auto-confirm cron) | `POST /orders/{id}/oc-acknowledgment`, `POST /client/orders/{id}/acknowledge-oc` |
| 5 | Supplier Purchase Order | Supplier PO signed-ack recorded | `POST /orders/{id}/supplier-po/signed` |
| 6 | Freight Payment | Freight cleared (FOB orders auto-skip this stage) | `POST /orders/{id}/payment/freight/clear` |
| 7 | Packing & BL Instruction | BL issuance recorded | `POST /orders/{id}/bl-issued` |
| 8 | Commercial Invoice & Balance | Balance payment cleared | `POST /orders/{id}/payment/balance/clear` |
| 9 | Document Despatch & Closure | Order closed | `POST /orders/{id}/close` |

Every one of these 8 advancing actions (Stage 1 has no predecessor to
skip) calls `StageGateService::passAndUnlockNext()`, which QA-1 fixed to
refuse acting on any stage that isn't genuinely `in_progress`. **This is
the single most important state machine in the application** — Section 6
tier P0 test requirements apply to all 9 transitions plus the FOB
auto-skip of Stage 6 plus the 48h auto-confirm cron path for Stage 4.

## 5. Other workflows (brief — see `_inventory_raw.md` §5 for file pointers)

- **Amendments**: create → MD approve/reject → document generated →
  signed copy uploaded → activates, overriding that order's payment terms.
- **Disputes**: create → status transitions → resolved, with a working-day
  response deadline; optionally client-visible.
- **Client Portal chain**: public intake form → staff review/accept →
  `clients` row created → client login provisioned at Stage 3 → portal
  access (view order, reorder, self-report payment, acknowledge OC, raise
  dispute, chat, download documents).
- **Order-Edit / Duplication / Reorder**: post-confirmation edits gated by
  `edit_order_post_confirmation` + mandatory reason; product/order
  duplication; client-initiated reorder requests always land in a staff
  review queue, never auto-creating an order.
- **Test Mode**: global on/off toggle that blocks client-facing routes and
  admin-settings writes app-wide while enabled; distinct from the one-shot
  Sample Data Playground.
- **CA module**: Zoho Books sync (CI / Debit Note invoices and payments applied to them, the LC payment; vendors, bills, vendor payments, order expenses and paid cost entries; export benefits as journals; records deleted or changed in Books put back; the per-order "App vs Zoho" check) → expenses entered in Books (imported; Books owns them) →
  bank reconciliation (CSV import + manual matching) → FY lock/unlock with
  a logged override permission.

## 6. Risk-based test priority tiers

This tiering drives the order QA-4's test suites get written in.

### P0 — state-machine and authorization integrity (write first)

The category QA-1 came from. A test here answers: *"can this action be
taken when it shouldn't be, by hitting the endpoint directly, regardless
of what the UI would normally prevent?"*

1. **Stage-gate integrity** — for all 9 stages: (a) a locked stage's
   action is refused with zero DB mutation (QA-1's regression tests do
   this for Stages 1, 2, 7, 9 already — extend to 3, 4, 5, 6, 8); (b) an
   already-passed stage's action is idempotent, not double-applied; (c)
   the FOB auto-skip of Stage 6 fires exactly once and only for FOB
   orders; (d) the 48h auto-confirm cron only fires on orders genuinely at
   Stage 4 past the deadline.
2. **Cross-tenant isolation (client portal)** — a logged-in client hitting
   any `/client/orders/{id}/...` or `/client/documents/{id}/download`
   route for an order/document that isn't theirs must get refused, not a
   404-that-leaks-existence or a silent cross-tenant read.
3. **CA FY-lock enforcement** — every write path listed under CA/Accounts
   in Section 3 must refuse once the relevant financial year is locked,
   *except* through `ca_fy_lock_override`, which must itself be audit-
   logged.
4. **Amendment/approval gates** — an amendment cannot activate without MD
   approval; a document cannot be marked sent without passing through
   `document_reviews`; an email cannot dispatch without
   `approve_email_send` approval where required.
5. **Permission-check-vs-resource-state gaps generally** — for every
   `manage_*`-gated write route, ask "does holding the permission alone
   make this action valid, or does it also depend on the resource's
   current state?" QA-1's bug is the template for what to look for.

### P1 — financial data integrity

1. Payment amount/percentage math (advance/balance split, amendment
   overrides) never produces a total that doesn't reconcile to 100%.
2. CA settlement-figure calculations (assumed exchange rate, INR-actual
   entries, FIRC amounts) match hand-computed values.
3. Reports' numbers match direct SQL against the same data (the pattern
   already used to verify the 8 report additions in an earlier phase —
   extend it into an automated regression instead of a one-time manual
   check).
4. Audit log is genuinely append-only — no route anywhere issues an
   UPDATE or DELETE against `audit_log`.

### P2 — CSRF, IDOR beyond the client portal, input validation

1. Every state-changing POST route requires a valid CSRF token (spot-check
   a representative sample per module rather than all 200+ POST routes
   individually, since CSRF is enforced by shared middleware, not
   per-route logic — a middleware-level test is more valuable here than
   per-route repetition).
2. Staff-side IDOR: can a user with `manage_orders` reach another
   record type they shouldn't (e.g., a Logistics Executive editing CA
   figures via a crafted request) — cross-check route permission
   requirements in `_inventory_raw.md` §2 against the role grants in §1
   for any route whose permission a given role does *not* hold.
3. Public unauthenticated endpoints (`quotation-details`,
   `pi-details/{token}`, client intake) — token guessing/enumeration,
   and input validation on data that later flows into a real order.

### P3 — core CRUD business rules per module

Standard create/read/update/delete correctness for modules without a
state-machine or cross-tenant dimension: Clients, Product Catalog,
Reference Docs, HS Codes, Watermarks, Holidays, Email Templates,
Signatories. Lower urgency than P0-P2, but still owed coverage — the
user's "unit/integration test for every piece of code going forward" rule
applies here too.

## 7. Test types and tooling

Infrastructure (QA-2) is live on both stacks:

- **PHP**: PHPUnit 11, `composer test`. `tests/Integration/` runs against
  a disposable database (`nexacrest_phpunit_test`), rebuilt from
  `schema.sql` + `seed.sql` fresh on every run via `tests/bootstrap.php`.
  `tests/Unit/` is for dependency-free pure-logic classes (validators,
  calculators) — most of this codebase's classes are static services over
  a shared PDO connection, so **integration-style tests against the real
  schema are the primary tool here**, not mocking. A few tests read the
  generated PDFs themselves (page count, running footer, clause wording)
  and need `pdfinfo`/`pdftotext` from **poppler-utils** on the machine
  running the tests (`apt install poppler-utils`). Without them those
  tests fail with a message saying so; they are never silently skipped.
- **Node**: Jest 30 + Supertest, `npm test`. `tests/unit/` mocks
  repositories for pure service logic; `tests/integration/` uses
  Supertest against the real Express app plus a disposable database
  (`nexacrest_node_jest`), rebuilt the same way via `globalSetup.js`.
  Run it one file at a time (`npm test`, or `npx jest --runInBand`):
  the files share that database, so `globalSetup.js` refuses
  `--maxWorkers` above 1 (QA Round 7, R7-N05). Each file's pools are
  capped (DB_POOL_SIZE 10 as live, SESSION_DB_POOL_SIZE 2 in `testEnv.js`).
  Prefer a real Supertest HTTP round-trip over calling a controller
  function directly whenever the thing under test is authorization or a
  state transition — those are exactly the bugs that only show up at the
  HTTP boundary (QA-1 was found and is regression-tested this way).
- Both stacks' disposable test databases are never the same database as
  the interactively-used dev/demo databases (`nexacrest`,
  `nexacrest_node_test`), so running the test suite is always safe to do
  at any time without touching real or demo data.
- **Going forward** (per the standing instruction from here on): any new
  code change ships with a test in the same commit. A bug fix ships with a
  regression test reproducing the bug first (as QA-1 did). CI does not
  exist yet for this repo — `composer test` / `npm test` are run manually
  before considering a change complete, until CI is set up.

## 8. What QA-4 builds, in order

1. ✅ Extend QA-1's stage-gate regression coverage to all 9 stages (P0.1)
   — `StageGateServiceTest`/`stageGate.test.js`.
2. ✅ Client-portal cross-tenant isolation suite (P0.2) — all 10
   `/client/orders/{id}/...` and `/client/documents/{id}/download` routes,
   each hit with a real second client's real resource behind it (not a
   nonexistent id, so a removed ownership check would genuinely be caught):
   `ClientPortalCrossTenantTest.php` (direct controller invocation — PHP
   has no HTTP-level test harness) and `clientPortalCrossTenant.test.js`
   (real Supertest HTTP requests, the natural fit on Node). Both suites
   were verified to actually fail when the ownership check they pin was
   temporarily removed, then reverted.
3. ✅ CA FY-lock enforcement suite across all CA write routes (P0.3) —
   `CaFyLockGuardTest.php`/`caFyLockGuard.test.js` pin the single choke
   point (`CaFyLockGuard::allow()` / `caFyLockGuard.allow()`) all 18 CA
   write call sites delegate to: blocked with no override, allowed once
   the date isn't in a locked year, allowed-with-audit-log-and-warning
   through the override, and a null date never blocked. Each suite also
   drives one representative route (`recordAdvanceInrActual`) end to end
   — real controller/HTTP call, real database check — and both were
   verified to actually fail when that route's guard call was temporarily
   removed, then reverted.
4. ✅ Amendment/document-review/email-approval gate suite (P0.4) —
   `AmendmentDocumentEmailGatesTest.php`/`amendmentDocumentEmailGates.test.js`.
   Amendment: `generateDocument()`/`attachSignedCopyAndActivate()` both
   already refuse a not-yet-MD-approved amendment (pinned, not a gap).
   Document review: a document approves only once every required
   reviewer has approved with none pending/rejected, and a rejection
   reverts it to draft rather than approving.
   Email: `buildPreview()`/`requestSend()` already refuse a non-approved
   document — but a genuine gap was found and fixed here: `dispatch()`
   never re-checked the document's live status before sending, so a
   reviewer assigned to an already-approved/sent document who later
   rejects it (reverting it to `draft` without clearing `pdf_file_id`)
   left a stale approved PDF still reachable from any send already queued
   against it. Fixed on both stacks by re-checking `status === 'approved'`
   immediately before the actual send attempt in `dispatch()`; verified
   directly by reverting the fix and confirming the new regression test
   fails, then restoring it.
5. ✅ Systematic pass over every `manage_*`-gated write route asking
   Section 6's P0.5 question (P0.5) — `PermissionStateSweepTest.php`/
   `permissionStateSweep.test.js`. One candidate finding was investigated
   and deliberately left alone: `OrderController::closeOrder()`/
   `ordersController.closeOrder()` never checks `bl_originals_received_at`/
   `bl_endorsed_at` before closing an order, which looks QA-1-shaped, but
   `docs/SOP/09-stage9-despatch-closure.md` explicitly documents this as
   intentional ("the system trusts staff to follow the real-world process
   in the right order rather than enforcing it as a hard rule here") — not
   a bug, so not fixed. Three genuine gaps were found and fixed on both
   stacks:
   - `DisputeController::updateStatus()`/`disputeController.updateStatus()`
     took `status` straight from the request into the DB with no check
     against the `dispute_status` dropdown at all (`disputes.status` is a
     bare `VARCHAR(30)`, not a DB-level enum) — any string, including one
     that doesn't exist in the dropdown Admin manages, would silently save
     and desync every report/filter that matches on the exact option
     string. Fixed by validating against `dropdown_options` before writing.
   - `OrderController::saveSupplierPo()`/`ordersController.saveSupplierPo()`
     — the method that actually creates `order_supplier_po` and mints its
     reference number ahead of generating the SUPPO document — never
     checked Stage 5 was unlocked, unlike `confirmSupplierSigned()` right
     next to it. Holding `manage_orders` alone was enough to save/generate
     a Supplier PO for an order still at Stage 1, before a Buyer PO, PI or
     OC even existed. Fixed by adding the same `isUnlocked($orderId, 5)`
     gate `confirmSupplierSigned()` already had.
   - `AmendmentService::rejectAmendment()` never re-checked the amendment's
     current status, unlike every sibling transition (`approveByMd()`,
     `generateDocument()`, `attachSignedCopyAndActivate()`) —
     `AmendmentRepository::reject()` unconditionally overwrites status to
     `'rejected'` with no `WHERE`-clause guard of its own. Rejecting an
     already-`active` amendment (whose override was already applied to the
     order's payment terms) would silently relabel it `'rejected'` while
     the order kept the amended terms. Fixed by requiring status
     `'pending'`, mirroring `approveByMd()`'s guard.
   All three were verified by reverting each fix and confirming its
   regression test failed with the expected diagnostic, then restoring it.
6. ✅ Financial data integrity suite (P1) — `FinancialIntegrityTest.php`/
   `financialIntegrity.test.js`. All four formulas checked here were
   already correct — these are pinning tests against a hand-computed
   value, not bug fixes:
   - Amendment override reconciliation: `AmendmentService::
     attachSignedCopyAndActivate()` always derives `balance_pct = 100 -
     advance_pct` rather than trusting a separately-submitted balance
     percentage, so `advance_pct + balance_pct` sums to exactly 100 for
     every amendment activation (checked across 0%, 30%, 55.5%, 100%
     advance).
   - CA settlement register forex gain/loss (`CaRepository::
     settlementRegister()`): `expected_inr = foreign_amount *
     assumed_exchange_rate`, `forex_gain_loss = inr_actual -
     expected_inr` — checked for both a forex gain and a forex loss
     against hand-computed values.
   - Payments report aggregation (`ReportRepository::paymentsReport()`):
     `outstanding = invoiced - cleared`, per row and per currency —
     checked as a before/after delta (not an absolute total), since the
     disposable test DB accumulates USD-currency orders from every other
     Integration test class run in the same process, which would
     contaminate an absolute-total assertion but not a delta.
   - Audit log immutability: a static source-tree scan confirming no PHP/
     JS file anywhere issues an `UPDATE` or `DELETE FROM` against
     `audit_log` (the only writer is `AuditLogRepository::log()`'s single
     `INSERT`). Each pinning test was checked against a deliberately
     broken formula to confirm it actually fails before being confirmed
     correct against the real one.
7. ✅ CSRF/IDOR/input-validation suite (P2) — `IdorAndInputValidationTest.php`/
   `idorAndInputValidation.test.js`:
   - CSRF: a static scan confirming every one of the (now 309) `$router->post()`
     registrations in `public_html/index.php` (and their `app.post()`
     mirrors in `server.js`) includes `CsrfCheck::verify()`/`verifyCsrf`
     in its middleware list — spot-checking every POST route individually
     would be redundant, since CSRF is enforced by this one shared
     middleware, not per-route logic.
   - Staff-side IDOR: a role/permission matrix cross-checking Section 2's
     documented grants directly against `PermissionService::can()` (the
     same check every route's `PermissionCheck::requires()` middleware
     calls) — confirms a Logistics Executive holding `manage_orders`
     cannot reach any CA/finance permission, an Accounts Executive can, a
     Viewer/Auditor cannot reach any order-mutating permission, and the CA
     role itself (no order-editing access — since 28 Oct it holds
     `view_orders` read-only and `view_cafin`) cannot reach `manage_orders`
     or `inr_actual_edit` despite owning the CA module's view permissions.
   - Public unauthenticated endpoints: token security for both intake
     links (`ClientIntakeRepository`/`clientIntakeRepository.js` and
     `PiIntakeRepository`/`piIntakeRepository.js`). Both already compare a
     SHA-256 hash of a 256-bit random token (never the raw value) and
     additionally gate on submission status — pinned against a guessed
     token, an expired token, and (the one that would be easy to
     regress) the genuine token reused after the submission has moved
     past its editable state (converted/rejected for client intake;
     pending_review/applied for PI intake). Each status-gate assertion
     was checked against a deliberately removed status clause to confirm
     it actually fails before being confirmed correct against the real
     query.
8. ✅ Core CRUD correctness suite (P3) — `CoreCrudTest.php`/
   `coreCrud.test.js`, one test per module named in Section 6's P3 tier
   (Clients, HS Codes, Watermarks, Company Holidays, Email Templates,
   Product Catalog, the Internal Reference Library, Signatory
   Designations), each exercising create → read → update → delete/toggle
   against the real repository. Two upsert-style repositories
   (`WatermarkSettingsRepository::upsertGlobal()` for the real, shared
   'global' draft watermark row, and `InternalReferenceDocRepository::
   upsert()` for the real, shared `WALLREF` reference-doc row) are pinned
   specifically on "a second call updates the existing row rather than
   inserting a duplicate," and both tests restore the row's original
   content afterward since these are genuine shared company-wide
   settings, not disposable per-test fixtures.

This completes every tier QA-4 set out to build (P0 through P3).

## 9. Added since (QA Round 5 and owner decisions, 27–29 Oct 2026)

Each suite exists in both stacks (PHP `tests/Integration/…Test.php`, Node
`tests/integration/….test.js`):

| Suite | What it pins |
|---|---|
| `RouteMatrixTest` / `routeMatrix.test.js` | Every registered route through the real router and middleware: a permission-gated route refuses (403) a user with no permissions; every staff page without a parameter opens for Admin without a server error; every public page opens signed out |
| `RouteBehaviourTest` / `routeBehaviour.test.js` | QA Round 6, R5-14: each route that had no behaviour test (124, plus 5 found since, plus the 21 only Jest covered) driven as a permitted user — the record changed, the audit row written or the page shown — and every route with a record id answers a missing id as "not found" with no audit row. Node's `/sample-data` checks are in `sampleDataLoadClear.test.js` (Jest runs files side by side) |
| `UngatedRoutesTest` / `ungatedRoutes.test.js` | The 88 routes with no permission check: each one's own guard (session, token, CSRF) and behaviour |
| `OrderAccessGuardTest`, `PermittedLinksTest` | One order-access guard on every `/orders/{id}/*` route; links shown only where the viewer can open them |
| `PaymentTermsApprovalTest` | Terms on a "Requires MD approval" preset block the Quotation until approved |
| `ClientEmailUniqueTest`, `Round5ValidationTest` | One email per client; server checks matching the browser ones (no 500 on bad numbers, no empty saves) |
| `CfrBuyerInsuresTest` | CFR documents: insurance NIL, the buyer insures |
| `VendorBillsAndExpensesTest`, `VendorBillProformaTest` | Supplier and logistics bills: proforma / tax invoice, linking, part payments, overpayment refused, 7e invoice carried into 10a, Step 12 / ledger / CAFIN counting |
| `CaFinancialSnapshotTest` | The CAFIN snapshot |
| `PaymentTermsWordingTest`, `PaymentTermsScenariosTest` | PI / CI payment wording in PDF and Word: the advance box (or NO ADVANCE PAYABLE on Letter of Credit), bank charges, Calendar Days — across all 5 payment presets × FOB / CFR / CIF × USD / EUR / GBP |
| `ZohoServerGuardTest` / `zohoServerGuard.test.js` | The `.env` Zoho switches: off = no request made; `test` = only `TEST-` customers; e-invoice / e-way bill / GST filing blocked unless production with `ZOHO_EINVOICE_ENABLED=true`; Zoho Mail never sends |
| `ZohoBooksStage1Test` … `ZohoBooksStage5Test` | Zoho Books: overseas customers and payments at the actual rate, the Test Books connection button and the TEST- clean-up (1); CI and Debit Note as export invoices with payments applied (2); vendors, bills, vendor payments and order expenses (3); cost entries, the LC payment, export-benefit journals and the App vs Zoho check (4); the app restores what it sent, Books owns the expenses the CA enters, a locked FY is left to the CA (5) |
| `CafinLcAndZohoExpensesTest` | CAFIN counts the LC payment as money received and expenses booked in Books for the order; the scheduled restore check runs once a day, Sync Now always |
| `ShippingBillTest` | The shipping bill's number, date and customs exchange rate on the order |

Every Zoho test sends its requests to a fake Zoho, never the real one.

The Playwright suite in `e2e/` adds a browser-level check of the same
wording: the critical path downloads the generated PI and CI (PDF and
Word) and reads them (see `e2e/README.md`).

## 10. Added since (31 Oct – 1 Nov 2026)

| Test | What it checks |
|---|---|
| `paymentDescriptionRule.test.js` | Rule D-212: every money record's description, exact wording, bank reference required |
| `ownerDecisionsNov1.test.js`, `makerCheckerGuard.test.js` | D-216: no self-approval of payment terms (MD/ED excepted), no self-review, no self-approved send, nobody grants what they do not hold or changes their own rights, LC-only zero advance, GST refund periods, masked Zoho secrets, every order keeps an assignee, the daily digest |
| `kanbanParity.test.js`, `kanbanCoverage.test.js` | Owner hard rule: every board offers what its list offers (and back), including Associates; the Orders board pages 25 per column |
| `lineTypes.test.js`, `exportServiceInvoice.test.js`, `paymentMilestones.test.js`, `productCategories.test.js` | D-215 (5a): line types, the Export Service Invoice, payment milestones, product categories |
| `associateSystem.test.js` | D-217: personal codes, rates (personal / standard / none) fixed on the order, earned only when paid in full, hold (pro rata and claw-back removed by D-245 P3: full payment only, take-back only for fraud or breach), bank checks by two people with a wait, the buyer's own registration (hidden field, e-mail code, first completed wins, direct approach caught), MD-only link changes, 2-step login, the portal never showing prices, costs, profit or CAFIN, read-only staff view, Zoho bill wording |
| `gapShipmentGates.test.js`, `gapDueDatesLc.test.js`, `gapPartPayments.test.js` | D-218: before-shipment balance and inspection gates on the BL with manager release; balance due from the scanned BL and its reminder; LC dates, amendments and reminders; several receipts, still owed, short-pay sign-off; the OC "sent" clock |
| `cfrWording.test.js` | C-16: the Shipment Booking clause and the Buyer PO freight sentence by incoterm (PDF and Word) |
| `ungatedRoutes.test.js`, `routeBehaviour.test.js` | Updated for the associate portal (its own sign-in on 18 routes) and the new staff routes |

Browser suite: `e2e/tests/10-departments.spec.js` and `11-associates.spec.js`; the critical path and role workflows now resolve the inspection and clear a before-shipment balance before the BL, and a second person approves each document.

## 11. Added since (1 Nov 2026: placeholders, self-approval rules, QA Round 8)

| Test | What it checks |
|---|---|
| `formPlaceholder.test.js` | D-220: every typed input and text area in every view has a placeholder (not date, file, checkbox, radio, hidden or read-only) |
| `formLabelConventions.test.js` | D-222 R8-N08: every visible field has a label; a label with "*" belongs to a required field; required drop-downs with no sensible default start with "Choose…"; app.js `markRequiredLabels()` |
| `selfApprovalRules.test.js` | D-221: Admin → Self-approval rules (Super Admins only), defaults MD + ED, a person qualifies through their own roles, every change audited with roles before and after. (Personal rates carrying upward, D-221: `associateSystem.test.js`) |
| `selfApprovalActions.test.js` | D-222 R8-N09: all seven self-approvals go through the rules (cancellation approve/reject, purchase award, amendment MD-approval, short-payment clearing, payment terms, document review, e-mail send), exact refusals, audit codes `…_SELF_APPROVED`; no Super Admin / manage_permissions exemption |
| `qaRound8Part1.test.js` | D-222 part 1: GST refund credits and claw-backs saved once under a row lock (concurrent requests); safe commission CSV; one-off invitation links used up at the first registration; `clients.associate_client_number`; suspended associate's read-only portal; bank e-mail wording |
| `qaRound8Part2.test.js` | D-222 part 2: original BL copies required 1 to 10; reviewer list leaves out the generator unless self-review is allowed ("(generated it — self-review)"); daily digest counts only real sends, mail off says "Daily digest: e-mail sending is off — nothing sent.", a failed send is retried |
| `departments.test.js` | Updated for D-222 R8-N10: approval limits and the 'director' level only with `manage_approval_limits`, never on one's own designation; `APPROVAL_LIMIT_CHANGED` audited |
| `makerCheckerGuard.test.js` | Updated: the guard asks Self-approval rules instead of a fixed MD/ED list |
| `associatePortalRoutes.test.js` | D-211 for QA Round 8: the 15 routes without a behaviour test (associate portal bank, clients, code, commission invoice, documents, invitation cancel, messages, set-password; `/invite/{token}`, `/quotation-details/verify`, inspection result, OC sent) through the real router |

Browser suite: `11-associates.spec.js` now expects the personal rate carried upward ("clients 6–10: 1.25% (personal)").

## 12. Added since (2 Nov 2026: documents, money safety, compliance, agreements, remittances)

| Test | What it checks |
|---|---|
| `quotationPiLayout.test.js` | D-223: QT and PI have no untitled CoO / Port table (PDF and Word); Port of Discharge once in Shipping; the CoO line follows the selected type; the PI's Buyer's PO in Buyer Details only when entered |
| `documentWordingD224.test.js` | D-224: OC Buyer's PO row; Debit Note full buyer block; BL Instruction section 8 without payment terms; CI wording without "IGST refund"; total invoice value in words (USD, EUR, GBP, AED, INR …) |
| `freightCostInInr.test.js` | D-225: Step 9a freight and insurance converted at the freight receipt / freight leg / advance-balance rate; missing rate flagged, not counted; forwarder's rupee bills still replace it; CAFIN C1 wording |
| `orderAllDocumentsPanel.test.js` | All Documents lists generated documents and uploaded files, only those the person may open (Sales, Procurement, Logistics, MD); CAFIN never for others |
| `billPrefillAndForwarder.test.js` | Steps 7e / 10a pre-filled from the Supplier PO; Step 9a forwarder pre-selected |
| `helpGuide.test.js` | `/help/supplier-logistics-payments` for any staff member, screenshots present and small, same text on both stacks, linked from 7e, 10a, the logistics card and Getting Started |
| `moneyLeakClosures.test.js` | Drafts, superseded copies and older CAFIN copies carry their document's money class; CAFIN left out of the dossier for others; audit amounts masked per role; money reports refused |
| `documentCopyCleanup.test.js` | Clean-up rules remove exactly the preview; never sent copies, current copies, open disputes or locked years; audit rows; `markSent` flags the file |
| `orderPageOwnerPointsNov2.test.js` | No associate controls without an associate; 7c starts with the Supplier Link; COO Prep Sheet from Stage 8; per-order Zoho sync (fake Zoho) |
| `exportCompliance.test.js` | D-226: the three roles and their permissions; compliance checklist, COO Prep Sheet, benefit claims and uploads allowed; no purchase, logistics, buyer-payment, profit, incentive or CAFIN amounts; compliance tasks in My tasks |
| `remittanceDifference.test.js`, `remittanceDifferenceReasons.test.js` | D-227: "why is it different?" on advance, freight, balance and milestones; bank charges cost with D-212 wording and Zoho `bank_charges`; 3% cap; short / excess; Admin-managed reasons filtered by direction; "Other" needs text and treatment; treatment locked once used |
| `agreementsRegister.test.js` | D-228: add / renew / end early; status; reminders 30 / 7 / 0 days once each; staff agreements only for `manage_agreements`; client and associate agreements listed; Kanban parity |
| `orderLockGuardMiddleware.test.js` | Updated: `/orders/:id/zoho-sync` works on a closed order |
| `logisticsBillsAndOwnBankCharges.test.js` | D-232: Logistics adds a logistics bill but gets 403 on paying it or removing a supplier bill; Accounts still pays; Procurement refused; "our bank's charges" booked as a Bank Charges cost with the exact description |
| `cafinReceiptsBankCharges.test.js` | D-236: CAFIN receipts show received + bank charges and the INR credited; an INR actual is not reduced again; rows without charges unchanged; Excel note |
| `complianceTracking.test.js` | D-234: ECGC limit / exposure warnings, declaration reminder, Missing eBRC / FIRC report, claim sanctioned amount, deadline and reminders once each |
| `associateTds.test.js` | D-235: 194H 2 % after the ₹20,000 threshold with catch-up, 20 % without PAN, abroad papers before payout, exact D-212 wording, Zoho payload with/without tax id, TDS register |
| `zohoClientsProductsSync.test.js` | D-238: switches off send nothing; clients created / linked by name / updated / inactivated; products without cost, inactivated; pending counts; re-push all |
| `zohoApiLimits.test.js` | D-239: every Zoho call metered (token included); daily allowance stops before calling; minute pacing waits in the background and refuses a person quickly; 429 / code 44 / OAuth stop the run; shared token reused and refreshed once after 401 |
| `orderLinePricing.test.js` | D-245 P2: Pick from Products carries each seller's quote and floor (staff, associate level); each line checked against the floor of whoever sells (OK / below / below minimum / no price / unchecked for hand-typed, other currency); save refused below the minimum (no minimum shown to staff); below the floor sent to the MD (notification) and the PI refused; MD approval down to the minimum only, effect on margin and team incentive, commission 0 on an associate's line, refuse; a price change voids the approval; the PI fixes the figures; who sees what on the order page; D-240 removed (columns, table, permission, settings, route; Zoho item rate 0) |
| `pricingEngine.test.js`, `pricingSetup.test.js` | D-245 P1: the price build-up to the paisa (₹80,000 → USD 1,000.00 → 1,250.00 → 1,287.50 → 1,326.13; levels 2/5/10% and fixed USD); who sees which price; settings MD only and audited; product overrides; market prices and stale badges; commission levels and ladder (no gaps, used levels only deactivated); associate level MD only |
| `associateCommission.test.js` | D-245 P3: commission on invoiced goods FOB line by line, below-floor approved line 0; bank charges never lower the basis; ladder by counting orders (≥ ₹5 lakh), a small order earns the current step without counting; agreed claim lowers the basis, unpaid pays nothing; due date, claim hold, payout run; GST on top only with GSTIN and tax invoice; take-back only fraud/breach; forcing yes MD only; registration lapse; portal price list |
| `teamIncentive.test.js` (reworked) | D-245 P4: % of profit after commission or fixed, global / product / per order with reason; ticks and proportional spread; custom shares total 100; one person two roles; above-profit refused, below-margin warning; program off + manual amount; no automatic lock; ready list, MD lock / lock all / unlock with reason; paid never changes; history; roles CRUD |
| `incentiveReports.test.js` | D-245 P5: estimate replaced by the locked figure (never twice), never negative, GST/TDS not costs, CAFIN CSV columns and locked-only switch, profitability switch and totals = sum, register / To pay / Owed / statement totals and CSV columns, visibility gate |
| `d245WorkedExamples.test.js` | D-245 P6: five worked orders end to end with identical figures on both stacks — (1) direct sale 15%: profit before ₹5,61,375.80, incentive ₹84,206.37, after ₹4,77,169.43, locked then paid; (2) associate 5% with a below-floor line: commission USD 1,395.92 = ₹1,15,861.36, GST ₹20,855.04, claim hold, incentive ₹1,03,629.88; (3) ladder to 5% at the 6th counting order, a ₹2.3 lakh order not counted (₹11,585.97); (4) ₹4,00,000 profit, ₹1,00,000 fixed, one person two roles ₹75,000, lock / unlock / lock and history; (5) agreed claim → ₹53,780.68, unpaid → nothing, bank charges ignored; register (and its pages), To pay, Owed and profitability totals equal the orders |
| `zohoBooksStage3.test.js` (extended) | D-233: "Waiting for Zoho" counts, nothing while Zoho is off, one failure e-mail per failing run without amounts, mail-off path |
| `mysqlCompatibleDdl.test.js` (changed) | D-241: also checks `seed.sql`; any future migration file still checked |

Browser suite: `12-money-matrix.spec.js` — every role × about 75 screens, 18 files and the dossier ZIP; fails on any amount a role may not see (0 violations on both stacks, 2 Nov 2026).

Totals at go-live (2 Nov 2026): PHPUnit 2,191 tests; Jest 392 suites / 2,258 tests; browser suite 58 tests per stack (including `12-money-matrix.spec.js`, 0 violations). Two tests that exercised back-fills of existing data were removed with the migration files (D-241): the go-live database has no existing data to back-fill.

## Appendix

Full route-by-route and table-by-table inventory:
[`_inventory_raw.md`](./_inventory_raw.md).
