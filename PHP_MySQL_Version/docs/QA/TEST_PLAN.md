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

- **2 stacks**, PHP/MySQL and Node/MySQL, route-for-route identical: **282
  routes each** (`public_html/index.php` / `src/server.js`).
- **84 database tables** (`docs/schema.sql`), 24 of which hang directly off
  `orders.id` (the order-lifecycle-dependent tables — see
  `_inventory_raw.md` §3 for the full list).
- **8 roles, 34 permissions** (`docs/seed.sql`), plus two mechanisms that
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
- **A CA/Accounts module** (15 routes, 4 tables) with a financial-year lock
  mechanism that must reject writes once a period is locked, except through
  the explicit override permission.
- **A Test Mode** that globally blocks client-facing routes and
  admin-settings writes while enabled — its own gate needs testing
  independent of anything else.

## 2. Roles and permissions

| Role | Permission scope |
|---|---|
| Admin, Managing Director, Executive Director | All 34 permissions (wildcard grant in seed.sql) |
| Export Executive | manage_orders, generate_documents, download_pdf, view_reports, view_client_email_full, cross_verify_documents, view/browse product catalog, view_product_pricing, view_archived_orders |
| Accounts Executive | manage_orders, download_pdf, view_reports, view_client_email_full, cross_verify_documents, view/browse product catalog, view_product_pricing, view_archived_orders, ca_module_view, inr_actual_view, inr_actual_edit, ca_module_manage, ca_fy_lock_override |
| Logistics Executive | manage_orders, generate_documents, download_pdf, cross_verify_documents, view/browse product catalog, view_archived_orders |
| Viewer / Auditor | view_reports, view_audit_log, view_product_catalog |
| CA / Chartered Accountant | ca_module_view, inr_actual_view (view-only, no order-management access at all) |

34 permissions span 7 categories: admin (10), reports (3), orders (3),
documents (6), clients (1), catalog (5), ca (6). Full grant list in
`_inventory_raw.md` §1.

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
| CA / Accounts | 15 | ca_expenses, ca_bank_statement_lines, ca_fy_locks, zoho_sync_log | **FY-lock enforcement** on every write path |
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
- **CA module**: Zoho Books revenue sync → expenses (read-only mirror) →
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
  schema are the primary tool here**, not mocking.
- **Node**: Jest 30 + Supertest, `npm test`. `tests/unit/` mocks
  repositories for pure service logic; `tests/integration/` uses
  Supertest against the real Express app plus a disposable database
  (`nexacrest_node_jest`), rebuilt the same way via `globalSetup.js`.
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
4. Amendment/document-review/email-approval gate suite (P0.4).
5. A systematic pass over every `manage_*`-gated write route asking
   Section 6's P0.5 question, fixing what QA-1-style bugs turn up.
6. P1 financial-integrity suites.
7. P2/P3 suites as capacity allows, prioritized by which modules see the
   most real usage.

## Appendix

Full route-by-route and table-by-table inventory:
[`_inventory_raw.md`](./_inventory_raw.md).
