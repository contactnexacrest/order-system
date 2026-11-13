# NexaCrest Order System — Master Test Plan

One master copy for both stacks (`PHP_MySQL_Version`, `NODE_MySQL_Version`)
and the Playwright suite (`e2e/`). It replaces the route, role and
permission counts in `PHP_MySQL_Version/docs/QA/TEST_PLAN.md` (dated 29 Oct
2026: 489 routes, 8 roles, 63 permissions), which are out of date. That
file's conventions are kept here: every fact comes from reading the source,
not from estimates; risks are ranked P0 to P3; and a test asks whether an
action can be taken when it should not be, by calling the endpoint
directly, whatever the UI would normally prevent.

| | |
|---|---|
| Snapshot | commit `9b4ad1cb` (HEAD, 5 Nov 2026). Uncommitted work in the tree is left out (see §10.3). |
| Reference stack | PHP (`public_html/index.php`, `app/src`, `app/views`, Twig `app/templates`) |
| Machine-readable inventory | `/tmp/claude-0/-home-user-order-system/449be023-f066-5c75-974a-90ffd9a587a6/scratchpad/qa/inventory.json`, used by the test generator |
| How the counts were made | Routes and forms were parsed from both stacks. `schema.sql` and `seed.sql` were loaded into a private, socket-only MariaDB to read the *final* role grants. Tests were indexed by route path and by controller action. |
| Not done | No test suite was run (the shared test database is locked by another process). |

---

## 1. Scope

### 1.1 In scope

| Area | Size (PHP = Node unless noted) |
|---|---|
| Routes | **735** (242 GET, 493 POST). Every route has the same method, path, permission gate and guards on both stacks (735/735). |
| Actors | **24 staff roles**; Super Admin flag; client portal (17 routes); associate portal (24 routes); public / token links (51 routes) |
| Permissions | **94**. `seed.sql` inserts 91 and `schema.sql` inserts 3. The brief's figure of 154 does not match the source. |
| Order pipelines | Export: 9 stages (`stages_master`). Domestic (DOM): 8. Import & Distribution (IMP): 5. Merchant Trade (MTT): 4. |
| Incoterms | FOB, CFR, CIF |
| Payment presets | 5 (all protected) |
| Outgoing e-mail | 20 seeded templates, plus 4 template keys that fall back to built-in text, plus 12 hard-coded e-mails = **36 kinds** |
| UI templates | Standard and Kanban. **12 list pages have a board.** |
| Forms | **547** `<form>` elements per stack, of which **379** take typed input |
| Database | **190 tables** |
| Divisions | EXP (active); DOM, MTT and IMP (planned); 11 module switches per division |
| Money | **206 routes** touch money. CAFIN appears in 17+ code units and 2 routes. |

### 1.2 Out of scope

- Content of generated PDF and DOCX documents. These are covered by the
  existing golden files. CLAUDE.md says any change needs double owner
  confirmation, so this plan only *reads* documents to check them; it
  never changes templates.
- Real Zoho (Books and Mail) writes. Use mocked payloads or the TEST org
  only, and log every Zoho touch in `docs/zoho/zoho-change-log.csv`.
- Load and performance testing, apart from the existing concurrency
  workers.

### 1.3 Test layers

| Layer | Where | Purpose |
|---|---|---|
| L1 Static sweeps | PHPUnit Unit, Jest unit | Conventions for forms, labels, placeholders, migrations and route tables |
| L2 Route and integration | `PHP_MySQL_Version/app/tests/Integration` (362 files), `NODE_MySQL_Version/app/tests/integration` | Run the real router and middleware, then check database effects and the audit log |
| L3 Browser (e2e) | `e2e/tests` (14 specs, 63 tests), run against both stacks through `BASE_URL` | Click like a person: roles, money matrix, forms, mobile layouts |
| L4 Exploratory crawl | §5 | Systematic human-style walk per role and per template |

---

## 2. Inventory

All detail is in `inventory.json`. The tables below summarise it.

### 2.1 Routes by controller, with existing coverage

Column "Specific tests" counts routes whose path, or controller action, is
named by at least one test. Sweep tests are not counted here. The sweeps
cover every route as follows:

- `RouteMatrixTest` / `routeMatrix.test.js`: a user with zero permissions
  gets 403, and Admin can open every page without parameters.
- `UngatedRoutesTest` / `ungatedRoutes.test.js`: sign-in redirect, CSRF on
  every POST, unknown id answers 404 rather than 500, portal separation,
  Super Admin routes refuse Admin.
- `NavLinksMatchRoutePermissions`: every navigation link matches its
  route's permission.

| Controller | Routes | PHP | Node | e2e | **No specific test** | Gating permissions |
|---|---|---|---|---|---|---|
| Order | 90 | 89 | 88 | 36 | 0 | manage_orders, manage_payments, clear_payments, manage_shipping, close_orders, inr_actual_*, approve_payment_terms, assign_order_owner, edit_locked_data … |
| AssociatePortal | 32 | 21 | 23 | 2 | **5** | (associate sign-in) |
| AssociateAdmin | 29 | 29 | 28 | 5 | 0 | manage_associates, view_associates, manage_associate_bank |
| Ca | 26 | 26 | 26 | 15 | 0 | ca_module_view/manage, inr_actual_edit, view_cafin, ca_internal_doc_manage |
| ClientPortal | 25 | 19 | 21 | 5 | **4** | (client sign-in) |
| Client | 24 | 23 | 23 | 4 | 1 | view_clients, manage_orders, impersonate_client, edit_locked_data |
| Report | 22 | 22 | 22 | 18 | 0 | view_reports, view_staff_reports, view_purchase_prices, view_logistics_costs, manage_order_financials |
| **Spec** (D-255) | 21 | 0 | 0 | 0 | **21** | manage_pricing, manage_product_specifications, view_price_buildup |
| **Import** (IMP) | 19 | 5 | 7 | 0 | **12** | manage_purchasing, manage_payments, view_purchase_prices, ca_module_manage, manage_divisions |
| OrderFinancials | 18 | 18 | 16 | 1 | 0 | manage_order_financials, manage_export_benefit_claims, inr_actual_edit |
| Product | 16 | 16 | 16 | 3 | 0 | view/manage_product_catalog |
| ReferenceDoc | 15 | 14 | 14 | 3 | 0 | manage_company_settings |
| **MerchantTrade** (MTT) | 15 | 5 | 5 | 0 | **10** | manage_purchasing, manage_payments, view_purchase_prices, ca_module_manage |
| **DomesticOrder** (DOM) | 13 | 3 | 2 | 0 | **10** | manage_orders, manage_payments, generate_documents, ca_module_manage |
| PermissionAdmin | 13 | 13 | 13 | 1 | 0 | manage_permissions |
| Incentive | 13 | 12 | 12 | 2 | 1 | manage_incentives, pay_incentives, view_own_incentives |
| Pricing | 13 | 11 | 11 | 0 | 2 | manage_pricing, record_market_prices, view_price_buildup |
| **Division** | 12 | 6 | 6 | 0 | **6** | manage_divisions, see_all_divisions, manage_users, manage_permissions |
| **Auth** | 11 | 2 | 4 | 2 | **7** | (public / session) |
| Signatory, Supplier, Purchasing, Annexure, EmailDispatch, User, HsCode, LogisticsPartner, Port, PortRoute, ComplianceTaskType, DepartmentAdmin, Agreement, Amendment, PaymentPreset, EmailTemplate, Dispute, … (52 smaller controllers) | 229 | — | — | — | 0–2 each | see inventory |
| **PriceRequest** | 5 | 0 | 0 | 0 | **5** | manage_pricing, view_price_buildup |
| **SuperAdmin** | 4 | 1 | 1 | 0 | **3** | Super Admin only |

Totals: 581 routes have both a PHP and a Node specific test; 30 Node only;
19 PHP only; 14 e2e only or e2e with one stack; **91 have none**. For
Spec, Import, Domestic, Merchant, Division and PriceRequest, service-level
tests exist; the routes themselves are not driven.

Guards: 640 routes need staff sign-in (`SessionAuth`), 109 have
`OrderLockGuard`, 11 are Super Admin only, 41 use `requiresAny`, and 493
POST routes check CSRF. No route requires two permissions at once.

**Staff routes with sign-in only (30).** The check happens inside the
controller, so each of these needs its own test (§3.2):

- `/`
- `/account*`
- `/reviews*`: assigned reviewer, plus the self-approval rule `document_review`
- `/email-log/{id}/cancel`: requester, or a holder of `approve_email_send`
- `/pricing/line-quote`: shows selling price only with `view_selling_prices`, never cost
- `/notifications`
- `/choose-department`, `/switch-department`, `/preview/exit`
- `/sop*`, `/reference-docs*`, `/gallery*`, `/company-assets/preview`
- `/force-password-change`, `/getting-started`, `/help/*`

### 2.2 Roles

Grants are the final state after every grant and revoke in `seed.sql`.
"Staff routes open" means routes the role passes at the permission layer,
out of the 643 staff routes and excluding Super Admin only routes.

| # | Role | Dept / level | Perms | Staff routes open (GET/POST) | e2e role coverage |
|---|---|---|---|---|---|
| 1 | Admin (system) | — | 93 (no manage_approval_limits) | 632 (192/440) | 01-role-visibility, money matrix |
| 2 | Managing Director | MGMT director | 94 | 632 | yes (seeded super admin) |
| 3 | Executive Director | MGMT director | 87 (no manage_incentives, pay_incentives, manage_pricing, manage_product_specifications, record_market_prices, manage_divisions, manage_associate_bank) | 565 | yes |
| 4 | Export Executive (= Sales/Export Executive) | SALES exec | 22 | 186 | yes |
| 5 | Accounts Executive | FIN exec | 29 | 225 | yes |
| 6 | Logistics Executive | LOG exec | 14 | 154 | yes |
| 7 | Viewer / Auditor (= Internal Auditor) | AUDIT | 11 | 68 | yes |
| 8 | CA / Chartered Accountant (= External CA) | AUDIT | 10 | 57 | yes |
| 9 | Sales/Export Manager | SALES mgr | 29 | 199 | money matrix only |
| 10 | Sales Director | SALES dir | 33 | 207 | money matrix only |
| 11 | Procurement Executive | PROC exec (limit ₹0) | 11 | 104 | money matrix only |
| 12 | Procurement Manager | PROC mgr (limit ₹5,00,000) | 15 | 108 | money matrix only |
| 13 | Procurement Director | PROC dir | 17 | 111 | money matrix only |
| 14 | Logistics Manager | LOG mgr | 18 | 165 | money matrix only |
| 15 | Logistics Director | LOG dir | 20 | 168 | money matrix only |
| 16 | Finance Manager | FIN mgr | 36 | 263 | money matrix only |
| 17 | Finance Director | FIN dir | 41 | 268 | money matrix only |
| 18 | System Administrator | ADMIN | 17 | 169 | money matrix only |
| 19 | Order Coordinator | OPS (inactive dept) | 4 | 36 | money matrix only |
| 20 | Operations Manager | OPS | 5 | 38 | money matrix only |
| 21 | Export Compliance Executive | EXPORT_COMPLIANCE | 8 | 47 | money matrix only |
| 22 | Export Compliance Manager | EXPORT_COMPLIANCE | 13 | 60 | money matrix only |
| 23 | Export Compliance Director | EXPORT_COMPLIANCE | 14 | 61 | money matrix only |
| 24 | Investor (view only) | — | 3 (view_reports, view_cockpit, see_all_divisions) | 47 (35/12, mostly sign-in-only pages) | money matrix; `DivisionOverheadAndInvestorTest` route walk |

**Access tiers that sit outside the role table:**

- **Super Admin** (`users.is_super_admin`, delegations in
  `super_admin_delegations`). Bypasses every permission check. Seeded on
  MD and ED. 11 routes are Super Admin only.
- **Per-user overrides** (`user_permissions`, with an expiry). Grant or
  deny on top of the role.
- **Department membership and "Preview as"**
  (`user_department_memberships`, `ActiveDepartmentService::effectiveRoleId`).
  Being signed in "as" a designation changes the effective role.
- **Client portal** (`ClientAuth`). Provisioned when the Stage 3 advance
  clears. Staff can impersonate a client only when all three hold:
  `impersonate_client`, `client_impersonation_enabled`, and the client's
  own opt-in.
- **Associate portal** (`AssociateAuth`, login code by e-mail).
- **Incentive roles** (`incentive_roles`: Sales 30%, *Sales Manager 10%*,
  Export Documentation 15%, Export Compliance 5%, Marketing 15%,
  Logistics 15%, Accounts 10%). These are payout shares, **not** login
  roles. The brief's "Sales Manager" is this, not an access role.
- **Self-approval rules** (`self_approval_rules`): amendment, cancellation,
  document_review, email_send, payment_terms, price_discount,
  purchase_award, short_payment. Each rule ticks the roles allowed to
  approve their own request.

### 2.3 Permissions (94)

| Category | n | Keys |
|---|---|---|
| orders | 26 | manage_orders, view_orders, view_all_orders, assign_order_owner, approve_payment_terms, manage_payments, clear_payments, manage_shipping, close_orders, edit_order_post_confirmation, view_archived_orders, manage_disputes, respond_to_disputes, manage_complaints, respond_to_complaints, manage_order_financials, manage_supplier_payments, override_buyer_acknowledgment, approve_short_payment, override_shipment_gates, manage_logistics_bills, manage_export_benefit_claims, upload_order_documents, … |
| catalog | 15 | view/browse/manage_product_catalog, view_product_pricing, manage_hs_codes, manage_logistics_partners, manage_suppliers, manage_ports, manage_payment_presets, manage_compliance_task_types, manage_pricing, view_price_buildup, record_market_prices, manage_product_specifications, … |
| admin | 14 (+1 written "Admin") | manage_users, manage_permissions, manage_company_settings, manage_assets, delete_assets, view_audit_log, manage_sample_data, manage_field_protection, manage_signatories, manage_email_templates, data_export_run, manage_divisions, manage_dropdown_options, …; **`manage_approval_limits` has category `Admin` (capital A) — an inconsistency to fix** |
| ca | 9 | ca_module_view, ca_module_manage, ca_fy_lock_override, ca_internal_doc_manage, view_cafin, inr_actual_view/edit/delete, … |
| documents | 7 | generate_documents, approve_documents, edit_locked_data, download_pdf, cross_verify_documents, approve_email_send, generate_compliance_documents |
| reports | 4 | view_reports, manage_report_definitions, view_staff_reports, view_cockpit |
| money | 4 | view_selling_prices, view_buyer_payments, view_purchase_prices, view_logistics_costs |
| incentives | 3 | manage_incentives, pay_incentives, view_own_incentives |
| clients | 3 | view_clients, view_client_email_full, impersonate_client |
| Associates / Agreements / procurement | 3 / 2 / 3 | manage/view_associates, manage_associate_bank; manage/view_agreements; manage_purchasing, approve_purchases, raise_purchase_requirements |

**13 permissions gate no route.** Each is checked only inside code, so it
needs a behavioural test rather than a route test:

| Permission | Checked in |
|---|---|
| view_all_orders | OrderAccessService, OrderController |
| edit_order_post_confirmation | OrderEditGuard |
| override_shipment_gates | ShipmentGateService, OrderController |
| approve_short_payment | PartPaymentService, views/orders/show |
| override_buyer_acknowledgment | OrderController (when `oc_ack_override_restricted`=1) |
| ca_fy_lock_override | CaFyLockGuard |
| inr_actual_view | OrderController |
| view_selling_prices, view_buyer_payments | MoneyVisibility, AuditMoneyMask |
| view_client_email_full | ReportController, views |
| browse_product_catalog, view_product_pricing | ProductController |
| manage_approval_limits | DepartmentAdminController |

Check sites for every permission on both stacks are listed in
`inventory.json` → `permissions[].php_check_sites` / `node_check_sites`.

### 2.4 Workflow stages and gates

**Export (`stages_master`).** Every advance goes through
`StageGateService::passAndUnlockNext`, which refuses unless the stage is
`in_progress`.

| # | Slug | Gate action (route) | Notes |
|---|---|---|---|
| 1 | quotation | QT sent (mail on) or approved (mail off) | blocked while payment terms await MD approval |
| 2 | buyer_po | `POST /orders/{id}/buyer-po`, `/buyer-action/{token}/buyer-po` | wet signature flag `wet_signature_required_buyer_po` |
| 3 | pi | `POST /orders/{id}/payment/advance/clear` (cash) **or** `/payment/lc/confirm-usable` (LC) | provisions the client portal and sends the invite |
| 4 | oc_production | `/orders/{id}/oc-acknowledgment`, `/client/orders/{id}/acknowledge-oc`, `/buyer-action/{token}/acknowledge-oc`, cron `auto_confirm_oc_acknowledgments` after `oc_ack_auto_confirm_hours` | clock starts when the OC is sent, or marked sent when mail is off |
| 5 | supplier_po | `/orders/{id}/supplier-po/signed` (also via `/supplier-action/{token}/acknowledgment`) | then `maybeAutoSkipFreightStage` |
| 6 | freight | `/orders/{id}/payment/freight/clear` | **CFR/CIF only; FOB auto-skips** |
| 7 | bl_instruction | `/orders/{id}/bl-issued` | shipment gates: pre-shipment balance, inspection; `override_shipment_gates` |
| 8 | commercial_invoice | `/orders/{id}/payment/balance/clear` (2 code paths, including the short-payment path) | `approve_short_payment`, tolerance settings |
| 9 | closure | `/orders/{id}/close` (`close_orders`) | |

**Domestic (DOM):** enquiry → quotation → order_confirmation → purchase →
dispatch → tax_invoice → payment → closed.
`DomesticOrderService::blockers` requires, in order:

1. lines;
2. DQT;
3. buyer GSTIN and DOC;
4. supplier PO reference, or IMP stock;
5. dispatch date and vehicle, plus an e-way bill when the consignment is
   above `dom_eway_threshold_inr`;
6. GTI and DCH.

**Import (IMP):** ordered → shipped → cleared → received ("In stock") →
closed. Blockers, in order:

1. lines and import PO;
2. BL;
3. clearance (BoE, rate, CHA, date);
4. supplier fully paid;
5. IGST credit date.

**Merchant (MTT):** contracted → shipped → settled → closed. Blockers: lines;
BL; both legs settled; RBI limits (`mtt_max_months_total` 9,
`mtt_max_outlay_months` 4, `mtt_advance_limit_usd` 500000).

All three divisions advance with `POST …/advance` and an `expectedStage`
(a stale-page guard). **None of these advance routes has a route-level
test.**

### 2.5 Incoterms

| Code | Behaviour and document effects | Existing tests |
|---|---|---|
| FOB (default) | Stage 6 auto-skipped. No FDN. No freight leg on the payment link. Port of loading is shown. Insurance is never shown. Final logistics bills can be paid at once. CI total = FOB value. | Golden FOB_{QT,PI,OC,PL,CI,BLI,SUPPO,COOPREP} pdf+docx (`StoneDocumentsUnchangedTest`); `StageGateServiceTest`; `FobFreightNilTextTest` |
| CFR | Stage 6 FDN and freight clearance. Port of discharge. Buyer insures, so stored insurance is never shown or charged. "Freight is paid separately". CI total includes freight. Final logistics bills blocked until the FDN payment clears. | `CfrBuyerInsuresTest`, `CfrWordingTest`, `CommercialInvoiceNonFobTotalTest`. **No golden documents.** |
| CIF | As CFR, plus insurance on the FDN, CI and Zoho invoice, and in profitability. | Golden CIF_* docs. e2e `01-critical-path` and `02-role-workflows` walk a CIF order. |

`MerchantTradeService` and `ImportService` accept all 11 Incoterms 2020
codes (EXW…DDP) as free text, with no workflow effect.

### 2.6 Payment presets (`payment_presets`, all `is_protected=1`, USD)

| Preset | Adv % | Method | Balance | Days | MD approval | Zero adv | Effects |
|---|---|---|---|---|---|---|---|
| Standard — New Buyer (default) | 40 | CASH_ADVANCE | 60 A_BEFORE_SHIPMENT | 3 | no | no | Balance due before BL: shipment gate, Shipment Readiness e-mail |
| Established Buyer — Post-BL | 40 | CASH_ADVANCE | 60 B_AGAINST_BL | 7 | **yes** | no | QT blocked until `approve_payment_terms`; balance after BL copy |
| Extended Terms — Before Shipment | 30 | CASH_ADVANCE | 70 A_BEFORE_SHIPMENT | 3 | no | no | as Standard at 30/70 |
| Extended Terms — Post-BL | 30 | CASH_ADVANCE | 70 B_AGAINST_BL | 7 | **yes** | no | as Post-BL at 30/70 |
| Irrevocable LC at Sight | 0 | **LC** | 100 B_AGAINST_BL | 7 | **yes** | **yes** | Step 5 shows Record LC / Confirm LC usable; no advance leg; documents say "NO ADVANCE PAYABLE … Letter of Credit"; LC FIRC route |

Preset changes are protected by `field_protection_requests` (maker-checker).
Amendments can override an order's terms (`AmendmentService`).

### 2.7 E-mail (36 kinds)

> **Since 11 Nov 2026 (D-280):** the master switch `mail_sending_enabled` named in this section no longer exists. It was replaced by `email_testing_mode` ("Testing mode — hold e-mails to buyers and suppliers", opposite meaning, off in a fresh install) and the per-kind, per-audience switches in the table `email_settings` (Settings › E-mail); MUST e-mails are never held. `EmailPolicy` / `emailPolicy.js` decides before every send. Read `mail_sending_enabled=0` below as "Testing mode on" for buyer and supplier e-mails; the current matrix is tested by `EmailSettingsD280Test` / `emailSettingsD280.test.js`.

**Transports:**

- **Queue** (`EmailDispatchService`). Level-2 approval (`approve_email_send`)
  unless `mail_approval_queue_enabled=0`. Optional `scheduled_at`, sent by
  cron `dispatch_deferred_emails`. Send-now applies only to rows already
  approved. Cancel works while `pending_approval` or `approved`, with a
  reason. Self-approval is governed by the `email_send` rule. Delivery is
  through `MailSenderService`: `mail_sending_enabled=0` blocks it; then
  Test Mode recipient → `mail_redirect` → CC list → Zoho Mail first, SMTP
  as fallback.
- **Direct** (`EmailService::sendPlainText`). Sent immediately over SMTP
  only. Test Mode, redirect and CC apply. **It does not check
  `mail_sending_enabled`.**
- **Security** (`isSecurityEmail=true`). Never redirected or CC'd, and
  Test Mode does not change the recipient.

| Kind | Keys | Trigger | Attachment / special condition |
|---|---|---|---|
| Queue + document | send_qt, send_annexa, send_buyerpo, send_pi, send_oc, send_fdn, send_pl, send_ci, send_ble | "Send to Buyer" on an **approved** document of the matching type (`send_<type>` enforced) | Document PDF. PI, FDN and CI add the Payment Action Link. QT is refused while payment-terms approval is pending, and passes Stage 1 on send. OC starts the 48 h acknowledgment clock. Internal types (`never_shown_to_buyer`) are refused. If the document is no longer `approved` at dispatch, the send fails. |
| Queue + document (**missing**) | send_esi | ESI is buyer-sendable, but **no `send_esi` template is seeded**, so `buildPreview` throws "Unknown or inactive email template" | verify (§10) |
| Queue, generic | payment_followup, shipment_readiness, balance_receipt_confirmation, bl_copy_sent | Order page, no document; at most one active send per order and template | bl_copy_sent attaches the scanned BL and is refused until it is uploaded |
| Direct, security | two_factor_code, staff_password_reset, client_portal_password_reset | 2FA sign-in; `/forgot-password`; `/client/forgot-password` | must not reveal whether an account exists |
| Direct | client_portal_invite | Advance cleared (Stage 3), first time only | set-password link |
| Direct | quotation_correction_link, pi_form_link, buyer_action_link | staff action | token links |
| Direct, **template not seeded** (built-in fallback text) | payment_action_link, supplier_action_link, vendor_details_link | `/orders/{id}/payment-action-link`, `/orders/{id}/supplier-po/supplier-link`, `/suppliers|/logistics-partners/{id}/details-link` | sent only if an e-mail is on file; supplier link valid 60 days |
| Hard-coded | associate login code, buyer verification code, associate event, associate portal opened, associate registration declined | associate flows | **login and verification codes are not flagged as security e-mails**, so they are redirected and CC'd when redirect is on |
| Hard-coded | order chat to client; complaint reply | staff reply | `MailSenderService`. Chat attachments above the cap become a portal link. |
| Hard-coded | action notification; daily digest; Zoho sync failure; compliance alerts (cron `check_alerts`); settings test e-mails | various | notifications only when `users.email_action_notifications_enabled=1` |

### 2.8 Divisions, modules, settings

- **Divisions:** EXP Export (active), DOM Domestic Trade (planned), MTT
  Merchant Trade (planned), IMP Import & Distribution (planned).
  Activation is `manage_divisions`. User and department division routes
  are `/users/{id}/division` and `/admin/departments/{id}/division`.
  `see_all_divisions` lifts division isolation.
- **Module switches** (`division_modules`, 11 per division): associates,
  ecgc, export_benefits, export_shipping, firc_fema, import_stock,
  lut_igst, merchant_trade, sales_documents, supplier_purchase,
  team_incentive.

  | Division | Modules on |
  |---|---|
  | EXP | all except import_stock and merchant_trade |
  | DOM | associates, sales_documents, supplier_purchase, team_incentive |
  | MTT | associates, ecgc, firc_fema, merchant_trade, sales_documents, supplier_purchase, team_incentive |
  | IMP | associates, import_stock, sales_documents, supplier_purchase, team_incentive |

- **Division settings:**
  - DOM: `dom_default_gst_rate` 18, `dom_eway_threshold_inr` 50000,
    `dom_e_invoice_applicable` 0, `dom_payment_terms_days` 30,
    `pricing_fx_rate`, `program_enabled`.
  - IMP: bcd 10, sws 10, igst 18, margin 20, `pricing_fx_rate`,
    `program_enabled`.
  - MTT: the RBI limits in §2.4, `mtt_usd_inr_rate` 84,
    `mtt_bank_documents`, `program_enabled`.
- **Company settings** (109 keys in 25 categories). Behaviour switches to
  test in combination:

  | Group | Keys |
  |---|---|
  | mail | `mail_sending_enabled`, `mail_approval_queue_enabled` |
  | mail_redirect | `mail_redirect_enabled/address`, `mail_cc_emails`, `mail_default_cc_email` |
  | zoho | `zoho_mail_enabled`, `zoho_books_enabled`, `zoho_sync_*`, API limits |
  | orders | `oc_ack_auto_confirm_hours`, `oc_ack_override_restricted` |
  | workflow | `wet_signature_required_*` |
  | clients | `client_impersonation_enabled`, `client_portal_show_*` |
  | security | lockout, password policy, session timeout |
  | tolerances | `max_bank_charges_pct`, `quantity_shortfall_tolerance_pct`, `non_usd_price_buffer_pct` |
  | document_cleanup | `doc_cleanup_cafin_mode`, … |
  | ui | `default_ui_template` |
  | alerts / compliance | LUT, RCMC, FEMA, ECGC, benefit deadlines |

  Also: Test Mode (`test_mode_settings`, Super Admin) and the Sample Data
  Playground.
- **Departments:** MGMT, SALES, PROC, LOG, FIN, AUDIT, ADMIN, OPS
  (inactive), EXPORT_COMPLIANCE, with 22 designations mapped to roles.
  Procurement approval limits are on the designations.

### 2.9 UI templates (`UiTemplateService`)

Which template a person sees is resolved in this order:

1. the user's own preference, if that template is active;
2. otherwise the company `default_ui_template`, if it is active;
3. otherwise `standard`, which cannot be deactivated.

`showsBoard` means the template is `kanban` and the URL does not have
`?view=list`.

Pages with a board:

| Page | Path | In `KanbanParityTest` / `kanbanParity.test.js` |
|---|---|---|
| Orders | /orders | yes (plus `OrderKanbanBoardTest`) |
| Disputes | /disputes | yes (+ board test) |
| Complaints | /complaints | yes (+ board test) |
| Reorder Requests | /reorder-requests | yes (+ board test) |
| Quotation Intake | /client-intake | yes (+ board test) |
| PI Intake | /pi-intake-review | yes (+ board test) |
| Procurement | /procurement | yes |
| My tasks | /my-tasks | yes |
| Associates | /associates | yes |
| Agreements | /agreements | yes |
| **Imports (IMP)** | /import | **no**. The board is inline in `import/index.php`; only `ImportDistributionTest` touches it. |
| **Merchant deals (MTT)** | /merchant | **no**. Same as Imports, in `MerchantTradeTest`. |

Other list pages with **no board** (Standard only): clients, products,
suppliers, logistics partners, users, reports, CA lists, incentives,
domestic orders (shown inside /orders). The CLAUDE.md hard rule requires
every list page to have a parallel board. Confirm with the owner whether
these are exempt.

### 2.10 Forms

There are 547 forms on each stack; 379 take typed input (the rest are
button-only POSTs). Every PHP form has a Node counterpart in the same view
with the same action. Each form is listed in `inventory.json` → `forms`
with its view, line, method, action, matched route, permission, fields and
attributes (required, placeholder, pattern, min, max, data-match).

Ten forms have a dynamic action (shared create/edit forms): agreements,
associates, clients, products, suppliers, email templates, vendor-details
partial, compose, layout search, dashboard filter.

Existing coverage is static sweeps:

- `FormValidationConventionsTest`, `FormPlaceholderTest`,
  `FormLabelConventionsTest` and their Jest twins.
- e2e `09-form-input-kept` covers 9 behaviours.

### 2.11 Tables (190)

`inventory.json` → `tables` gives, for each table, which classes write it
(INSERT/UPDATE/DELETE) and read it, and how many test files name it.

- **Never written by app code** (seed or reference only): currencies,
  incoterms, stages_master, file_upload_contexts, indian_states,
  document_sections, docx_generation_settings, products, product_images,
  two_fa_backup_codes. **`two_fa_backup_codes` is not used by any app code
  on either stack, so there is no 2FA backup-code feature even though the
  schema has the table. Confirm this is intended.**
- **51 tables are not named in any test.** Among them: all domestic_*,
  import_*, merchant_* tables; order_incentive_*; order_lc_amendments;
  order_zoho_invoices; dispute_documents; document_revisions;
  login_attempts; report_definitions. Tests may reach these through
  repositories, but no assertion reads the rows.

### 2.12 Money and CAFIN

- **Money visibility** (`MoneyVisibility`):

  | Class | Permission | Documents / files |
  |---|---|---|
  | selling | view_selling_prices | QT, BUYERPO, PI, OC, FDN, CI, AMD, COOPREP, ESI |
  | buyer_payments | view_buyer_payments | CHECKLIST_2_FINANCE |
  | purchase | view_purchase_prices | SUPPO |
  | logistics | view_logistics_costs | BLI |
  | financials | manage_order_financials | files tagged with this money class |

  The audit log is masked by `AuditMoneyMask`. 206 routes touch money
  (flag `money_route`).
- **Money flows that need a description (D-212):**

  | Flow | Covers |
  |---|---|
  | Buyer side | advance, balance, freight, LC, milestones, part-payments and shortfalls, receipts, remittance differences |
  | Supplier and vendor side | supplier advance, vendor bills and payments, logistics bills, CHA |
  | Costs | order expenses, cost entries, travel, inspection fees, CA expenses |
  | Benefits | export benefits, GST refund claims and credits |
  | People | incentive payouts, associate commission and TDS |
  | Divisions | DOM payments, IMP supplier payments and duty, MTT buy/sell legs |
  | Zoho | invoices, payments, bills, journals |

  `PaymentDescriptionRuleTest` and the division tests check wording.
- **CAFIN** (document type `CAFIN`, never shown to the buyer):

  | Role | Where |
  |---|---|
  | Who may see it | `view_cafin` (see and download; includes the CA role); `ca_internal_doc_manage` (generate) |
  | Routes | `/ca/cafin-export`; `/documents/{id}/download` (any of download_pdf / view_cafin) |
  | Code | CaController, DocumentController, DocumentGenerationService, CafinExportService, CaFinancialSnapshotService, FileStoreController, FileStoreRepository::moneyClassFor, EmailDispatchController (refuses internal documents), ClientPortalController (excludes never-shown types), PermittedLinks, DocumentCleanup (`doc_cleanup_cafin_mode`), OrderFinancials, ReportController, CAFIN box in `orders/show`, `ca/cafin_export` |

  **Places a client can reach, to check for CAFIN:** `/client/*`;
  `/buyer-action/*`; `/payment-action/*`; `/supplier-action/*`;
  `/vendor-details/*`; `/associate/*`; the intake forms; every outgoing
  e-mail, including chat attachments; Zoho invoice attachments.

---

## 3. Test matrices

### 3.1 Role × route

**Generation rule** (for the test generator, using `inventory.json`):

```
for role in roles[kind=staff_role] + {Super Admin, no-permission user}:
  for route in routes[actor=staff]:
    expected = 'super_admin' in guards ? (role is Super Admin)
             : all(requires_all ⊆ role.permissions) and all(any-set ∩ role.permissions ≠ ∅)
    GET  -> expected ? (status < 500, not 403) : 403 (and audit PERMISSION_DENIED)
    POST -> expected ? (no 403; state-dependent result) : 403 with zero DB mutation
```

Portal routes:

- `actor=client_portal`: signed-in client gets 200 for their own order and
  404 for another client's. Staff sign-in is not accepted.
- `actor=associate_portal`: the associate sees only their own clients;
  amounts follow the price-access rules.

Size: 26 actors × 643 staff routes = 16,718 cells per stack. Run GET cells
for all roles. Run POST cells as "refused" checks for every role that
lacks the permission, and as one positive test per (route, minimal role).

**Priority roles:** the 16 department roles and Investor (rows 9–24 in
§2.2). Today they are tested only by the money matrix, which checks
visibility, not actions.

### 3.2 Single permission × route

There are 81 permissions that gate routes. For each one, build a
**synthetic role holding only that permission**, and a second
synthetic role holding **every permission except that one**.

| Check | Expectation |
|---|---|
| Only-permission role on each route in `route_gates` | not 403 (GET status < 500; POST reaches handler validation) |
| Only-permission role on each other gated route | 403 |
| All-but-one role on each route gated **only** by that permission | 403, `PERMISSION_DENIED` audit row, no data change |
| `requiresAny` routes (41) | each listed permission alone opens the route; none of the others does |

The 13 permissions checked only in code (§2.3) are tested at behaviour
level instead:

| Permission | With it | Without it |
|---|---|---|
| view_all_orders | sees unassigned orders on the list, the board, the detail page and the file store | 404 on an unassigned order |
| edit_order_post_confirmation | can edit at Stage ≥ 4 with a reason | edit refused |
| override_shipment_gates | can record BL with open blockers, with a reason that is logged | refused |
| approve_short_payment | can clear a balance below tolerance | refused, and the self-approval rule applies |
| override_buyer_acknowledgment | (with `oc_ack_override_restricted`=1) can record OC acknowledgment | refused; with the setting at 0, `manage_orders` is enough |
| ca_fy_lock_override | can write into a locked financial year, and the write is logged | refused |
| inr_actual_view | sees INR actual amounts | hidden |
| view_selling_prices, view_buyer_payments | the money class shows on every page, export and the audit log | masked |
| view_client_email_full | full e-mail address | masked e-mail |
| browse_product_catalog, view_product_pricing | catalogue list; pricing | search only; no pricing |
| manage_approval_limits | can edit designation limits | refused |

Per-user override (`user_permissions`): grant and revoke each of the
P0 permissions to one user, check that the effect is immediate, and check
that expiry is honoured.

### 3.3 Incoterm × preset (15 cells × 2 stacks)

Today only the PI and CI *wording* is covered for all 15 cells
(`PaymentTermsScenariosTest`). The behaviour columns below are new.

| Preset ↓ / check → | Stage 6 | Payment-action legs | MD approval before QT | Step 5 UI | Balance window | Logistics bill gate | Documents |
|---|---|---|---|---|---|---|---|
| Standard (40/60 before shipment) | FOB: skipped; CFR/CIF: FDN required | adv(+freight if CFR/CIF)+bal | no | Record advance / clear | before BL (shipment gate) | FOB free; CFR/CIF after FDN clear | QT, PI, OC, CI; FDN for CFR/CIF |
| Established Post-BL (40/60) | same | same | **yes** | same | after BL copy, 7 d | same | same |
| Extended Before Shipment (30/70) | same | same | no | same | before BL | same | same |
| Extended Post-BL (30/70) | same | same | **yes** | same | after BL | same | same |
| LC at Sight (0/100) | same | **no advance leg** | **yes** | Record LC / Confirm usable | after BL | same | "NO ADVANCE PAYABLE … LC" |

For every cell: create the order → generate and approve QT (and test the
MD-approval refusal) → walk to closure → check the stage history, the
payment-action page legs, the vendor-bill gate, the Zoho payload (mocked)
and the descriptions (D-212). Add **CFR golden documents** first. Get
owner double confirmation before adding any new golden file, because it
fixes document content.

### 3.4 Mail conditions

> **Since 11 Nov 2026 (D-280):** the master switch `mail_sending_enabled` named in this section no longer exists. It was replaced by `email_testing_mode` ("Testing mode — hold e-mails to buyers and suppliers", opposite meaning, off in a fresh install) and the per-kind, per-audience switches in the table `email_settings` (Settings › E-mail); MUST e-mails are never held. `EmailPolicy` / `emailPolicy.js` decides before every send. Read `mail_sending_enabled=0` below as "Testing mode on" for buyer and supplier e-mails; the current matrix is tested by `EmailSettingsD280Test` / `emailSettingsD280.test.js`.

Columns are switch settings; rows are e-mail kinds. Expected = delivered
to whom (R = real recipient, X = redirect address, T = Test Mode address,
∅ = not sent).

| Kind ↓ \ switches → | default | `mail_sending_enabled=0` | `mail_redirect_enabled=1` | Test Mode on | `mail_approval_queue_enabled=0` | Zoho Mail on |
|---|---|---|---|---|---|---|
| Queue document (send_*) | after approval → R + CC | ∅. Document marked sent on approval; no Send button. | X + CC | T (or ∅ if no test address) | sent immediately, auto-approved and logged | Zoho, then SMTP fallback |
| Queue generic | after approval → R | ∅ | X | T | immediate | Zoho |
| Security (2FA, resets) | R, no CC | R | **R** | **R** | n/a | SMTP only |
| Direct (invite, links) | R + CC | **R (not blocked) — confirm intended** | X | T | n/a | SMTP only |
| Associate login code, buyer verification code | R + CC | R | **X — code lost; confirm** | T | n/a | SMTP |
| Notifications | R if the user opted in | R | X | T | n/a | SMTP |

Also test:

- Approval: maker-checker refusal; the allowed self-approval path, which
  must leave an `EMAIL_SEND_SELF_APPROVED` audit row; reject with a reason
  (the reason validator applies).
- Deferred: `scheduled_at` in the future is not sent by cron early;
  send-now works only on approved rows; cancel only by the requester or an
  approver.
- Dispatch: the document must still be `approved` at dispatch; the PDF
  must exist on disk; a double dispatch is refused (`claimForSend` race).
- Attachments: the PDF actually sent is the one shown before approval
  (e2e 08); bl_copy_sent with no BL fails; chat attachments above the cap
  go as a link.
- `send_esi`: missing-template behaviour.

### 3.5 Template × list page

For each of the 12 board pages × {Standard, Kanban, Kanban + `?view=list`}
× {roles with access}:

1. The same filters, search box, counts, fields, badges, actions, links and
   money rules. Diff the record set and the controls.
2. Paging ("Show more") matches the list.
3. The switch link round-trips.
4. A Standard user sees no board switch.
5. Resolution order: preference → company default → standard; a
   deactivated template falls back.
6. Board columns follow the stage sets (export 9, DOM 8, IMP 5, MTT 4).

Add `/import` and `/merchant` to `KanbanParityTest` and
`kanbanParity.test.js`. Ask the owner which other list pages must gain
boards (§2.9).

### 3.6 Divisions

| Check | Steps |
|---|---|
| Activation lifecycle | planned → active → deactivated; `manage_divisions` only; audit logged |
| Module switches | each switch hides or shows its menu, routes and documents for that division. Example: `export_shipping` off for DOM means no BL steps. |
| Isolation | a user in division A cannot list or open B's orders, deals, imports, reports or files (404, not 403, so existence does not leak). `see_all_divisions` and Investor see all. Overhead sharing appears only when 2+ divisions are active. |
| Division settings | GST, e-way threshold, duty rates, RBI limits drive the blockers and amounts; invalid values are refused (`SettingValueValidator`) |
| Per-division flows | DOM, IMP and MTT end to end, with payments, documents (DQT/DOC/GTI/DCH; import PO; MTT PO and sale invoice), Zoho push (mocked), reports, money descriptions |
| Cross-division | IMP stock consumed by a DOM sale (stock shortfall blocker) |

---

## 4. Risk tiers (carried over from TEST_PLAN.md)

- **P0 — authorization and state-machine integrity:** stage gates;
  cross-tenant isolation (client, associate, division); single-permission
  matrix; Super Admin and delegations; CAFIN never reaching the client;
  money visibility.
- **P1 — financial integrity:** preset × incoterm amounts; part and short
  payments; INR actuals, FIRC and exchange rates; benefits; incentives
  and TDS; D-212 descriptions; Zoho payloads; division money.
- **P2 — communication and workflow:** mail conditions, approval and
  deferral; token links (expiry, single use); OC auto-confirm cron;
  amendments, disputes, complaints.
- **P3 — UX parity:** Standard and Kanban; forms (validation, placeholders,
  labels, kept input); mobile layouts.

---

## 5. Human-style UI crawl specification

**Goal.** Walk the app the way a person would, per role, per template, per
viewport. Every visible element must be either usable or deliberately
absent.

**Actors.** Each of the 24 seeded roles, using `e2e/seed-ui-test-users.sql`
extended to all 24 roles. Also Super Admin, an associate, a client, and a
token holder (buyer, payment, supplier, vendor links).

**Matrix.** Actor × {Standard, Kanban} × {desktop 1280×800, mobile
390×844}.

**For each actor:**

1. Sign in. 2FA where it is on. Force a password change on first sign-in.
2. Record the navigation menu. Compare it with the permission-derived
   expectation (`NavLinksMatchRoutePermissions`).
3. Breadth-first crawl from `/`. Follow every `<a href>` inside the app
   and every GET form (filters, search). Visit each distinct
   route-pattern up to 3 concrete ids: the newest record, an archived one,
   and one in another division or assigned to someone else.
4. On each page:
   - no 5xx, no PHP warning or Nunjucks error text, no `undefined`/`NaN`;
   - no horizontal overflow on mobile;
   - every link resolves to a permitted route, with no 403 behind a
     visible link;
   - the page title and breadcrumb are correct;
   - money values appear only if the actor holds that money class (reuse
     the sentinel amounts in `e2e/tests/support/moneyMatrixSeed.js`);
   - CAFIN appears only with `view_cafin`.
5. For each data-entry form on the page, read-only by default:
   - Submit it empty. The browser must stop it with an inline message
     beside each required field, and open a closed step card if needed.
   - Fill invalid values: comma in a number, spaces only, out of
     min/max, mismatched `*_confirm`. It must be stopped in the browser.
   - Check that every typed input and textarea has a placeholder and a
     label, that required fields show `*`, and that required drop-downs
     show "Choose…".
6. In the **write pass**, on a disposable database only, submit one valid
   example per form, using its placeholder as the value. Then check:
   - a success message;
   - the record appears on its list, both Standard and Kanban;
   - an audit row;
   - for money forms, the description text (D-212), and that the save is
     refused when the bank reference is missing.
7. On the board pages, compare cards with list rows (§3.5).
8. Sign out: POST `/logout` with CSRF. Pressing Back shows no data.

**Token and portal pass.**

- For each token link: open it, use it, reuse it (expect refusal or
  idempotence), let it expire, and tamper with the token.
- For each document a client can reach: the type must not be in the
  never-shown list. CAFIN, SUPPO, BLI, COOPREP and the checklists must
  never appear.

**Output.** One record per (actor, template, viewport, page, element),
covering status, links, forms, money sentinels seen, CAFIN seen and a
screenshot. Two files: a crawl report (JSON) and a diff against the
expected permission matrix. Run against both stacks and diff PHP with
Node page by page (link sets, form field sets, record counts).

---

## 6. Data set-up

1. **Schema and seed:** `docs/schema.sql` + `docs/seed.sql` (identical in
   substance on both stacks; only comments differ). This is the whole
   database for the first live install: no migration (D-241). The migrations
   folder holds only its README until after go-live; `MigrationIdempotencyTest`
   checks any future file. Use MySQL 8 strict mode with ONLY_FULL_GROUP_BY as
   well as MariaDB.
2. **Users:** one per role (24), plus a role with no permissions, plus
   synthetic single-permission and all-but-one roles (generated, 81 × 2).
   Also a Super Admin and a delegated Super Admin, users in two divisions,
   and users with department memberships (for Preview as).
3. **Parties:** 2 clients (A and B, for isolation), each with portal login
   and impersonation opt-in on and off; 2 associates (each with one
   client); 2 suppliers; 1 logistics partner (CHA). Every name starts with
   `TEST-`.
4. **Orders:**
   - 15 export orders, one per preset × incoterm cell, in USD, EUR and GBP
     as in `PaymentTermsScenariosTest`.
   - One order parked at each of stages 1–9.
   - One archived order, one lost order, one amended order.
   - One order with a dispute, a complaint, a reorder request, an
     inspection blocker and a short payment.
   - One order with the CAFIN generated.
   - The money-matrix sentinel order (`moneyMatrixSeed.js`).
5. **Divisions:** activate DOM, IMP and MTT in the test database only. Add
   one record per stage for each, and an IMP stock lot feeding a DOM sale.
6. **Switches:** a fixture can set mail, redirect, queue, Test Mode, Zoho
   and `oc_ack_override_restricted` for each test, and restores them after.
7. **External:**
   - SMTP: a capture sink (`e2e/tests/support/mail.js`).
   - Zoho: mocked by default; the TEST org only with `ZohoGuard`
     permitting it, and a row in `docs/zoho/zoho-change-log.csv` for each
     touch.
   - Cron scripts are invoked directly with a frozen clock.
8. **Isolation:** the shared test database is locked by another process.
   Run under the suite lock, or on a private schema. The PHPUnit bootstrap
   pins `DB_DATABASE=nexacrest_phpunit_test`; the generator should accept
   its own schema name. Never
   run against live data. The Sample Data Playground must refuse a
   database that holds real data.

---

## 7. Pass / fail criteria

A build **passes** only if all of the following hold on **both stacks**:

1. PHPUnit, Jest and the Playwright e2e suite are green against both
   stacks. Each skip states its reason and links to a gap ID.
2. Route parity: 735/735 routes have the same method, path, permissions
   and guards. A route on one stack only is a failure.
3. Role × route and single-permission × route: 0 unexpected allows, and 0
   unexpected 403s for the minimal role. Every denial writes
   `PERMISSION_DENIED`.
4. 0 money sentinels seen by an actor without that money class. 0 CAFIN
   references (link, file, e-mail attachment, portal entry, Zoho
   attachment) reachable by a client, associate or token holder.
5. Stage gates: every out-of-order action is refused with no database
   change. FOB skips Stage 6 exactly once. Auto-confirm fires only on
   Stage 4 orders past the deadline.
6. Money: every amount in the incoterm × preset matrix matches hand
   calculations to the paisa or cent. Every money record has the exact
   D-212 description wording, and the save is refused without a bank
   reference.
7. Mail: every cell in §3.4 behaves as expected. Security mail is never
   redirected. No e-mail is sent twice.
8. Templates: every board offers exactly what its list offers (§3.5).
9. Forms: 0 forms submittable with an invalid field in the browser; 0
   missing placeholders, labels or required markers; typed input is kept
   after a server-side rejection.
10. Crawl: 0 errors at 5xx or above, 0 broken in-app links, 0 permitted
    links answering 403, 0 horizontal overflow at 390 px.
11. Documents: golden files unchanged unless the owner double-confirmed
    the change.

A **blocker** is any failure of item 3, 4, 5 or 6.

---

## 8. Coverage gap list (ranked by risk)

| # | Tier | Gap | Evidence | Action |
|---|---|---|---|---|
| G1 | P0 | **No single-permission × route test.** The route matrix tests only a user with zero permissions (403) and Admin (opens). Nothing proves that a role holding *other* permissions is refused, or that the minimal permission is enough. | RouteMatrixTest / routeMatrix.test.js | Generate §3.2 from `inventory.json` |
| G2 | P0 | **The 16 department roles and Investor have no action-level tests.** e2e role tests cover only the original 8 roles; the money matrix checks only visibility. | 01-role-visibility, 12-money-matrix | §3.1 for roles 9–24 |
| G3 | P0 | **Super Admin delegation routes (`/super-admin`, grant and revoke) have no behavioural test**, only "Admin is refused". This is a path to privilege escalation. | UngatedRoutesTest only | Grant, use, expire, revoke, with audit |
| G4 | P0 | **The 13 permissions checked only in code** (view_all_orders, override_shipment_gates, approve_short_payment, edit_order_post_confirmation, ca_fy_lock_override, view_selling_prices, …) have uneven tests, and none goes through a single-permission role. | §2.3 | §3.2 behaviour table |
| G5 | P0 | **No single sweep proves CAFIN never reaches a client.** Existing tests cover the portal download and the compose refusal. Nothing covers the buyer, payment, supplier or vendor token pages, the associate portal, chat attachments or Zoho attachments in one assertion. | CafinExportTest, ClientDocumentDownloadsVisibilityTest | CAFIN reach sweep (§5 token pass) |
| G6 | P0 | **Division routes are untested at route level**: DOM 10/13, IMP 12/19, MTT 10/15, Division admin 6/12. That includes all three `advance` stage-gate routes and every division payment route. There is no e2e. Work is still in progress on these files (§10.3). | coverage table §2.1 | Route tests plus §3.6 isolation |
| G7 | P0 | **Auth flows untested at route level:** `/2fa` (show and verify), `/reset-password/{token}`, `/force-password-change`, `/client/set-password`, `/client/account/password`, `/client/logout`. `two_fa_backup_codes` is unused. | 7/11 Auth routes untested | 2FA, reset and token-expiry tests |
| G8 | P1 | **Incoterm × preset is tested for wording only.** No behavioural matrix (stage skip, payment-action legs, vendor-bill gate, MD approval, LC step). The only e2e walk is CIF with one preset. **CFR has no golden documents.** | PaymentTermsScenariosTest | §3.3 |
| G9 | P1 | **Pricing specifications and price requests** (SpecController 21, PriceRequest 5, associate `/associate/prices`, `/associate/catalogue/{id}/price`): 0 route-level tests. Only service tests exist, and cost and selling exposure to associates is at stake. | D255/D256/D257 service tests | Route tests with money sentinels |
| G10 | P1 | **E-mail templates with no test naming them:** send_oc, send_fdn, send_buyerpo, send_annexa, send_pl, send_ble, payment_action_link, supplier_action_link, vendor_details_link. **`send_esi` is missing.** | grep counts | One send test per template, plus the ESI decision |
| G11 | P1 | **Mail switch combinations are untested.** Direct mail ignores `mail_sending_enabled`; associate and buyer codes are not security e-mail, so a redirect swallows them. Each switch has only one test. | §3.4 | Matrix §3.4 |
| G12 | P2 | **Kanban parity misses `/import` and `/merchant`.** Other list pages (clients, products, suppliers, users, CA lists, incentives, domestic) have no board; this conflicts with the CLAUDE.md hard rule unless they are exempt. **e2e has no Kanban test at all.** | KanbanParityTest pages() | Extend the parity test; ask the owner; add a Kanban crawl |
| G13 | P2 | **51 tables are never named in any test.** No assertion reads incentive history and people, LC amendments, `order_zoho_invoices`, dispute documents or document revisions. | tables.json | Add DB assertions to the flows |
| G14 | P2 | **PHP and Node test files are not paired one to one** (402 vs 414 names). PHP only: OrderStep6bStatus, PiIntakeReviewNullFields, DocumentPageCountParity, MigrationIdempotency, ReasonValidator and others. Node only: lcPaymentLeg, govtExportBenefitsGate, buyerPoActionLink and others. | name diff | Check each pair; port the missing ones |
| G15 | P3 | **Forms are covered only by static sweeps**, plus 9 e2e cases. No browser walk of all 379 data-entry forms (inline errors, step-card opening, kept input). | FormValidation sweeps, e2e 09 | §5 form pass |
| G16 | P2 | 30 routes have Node-only specific tests and 19 have PHP-only, so parity of behaviour is unproven for them. | routes[].specific_test_files | Port tests |
| G17 | P2 | Division settings validation, `client_impersonation` (three-flag gate) and Test Mode × each client-facing route: covered by single-case tests only. | — | Combination tests |
| G18 | P3 | The OPS department is inactive but its roles (Order Coordinator, Operations Manager) are seeded with grants. Behaviour when a department is inactive is untested. | department seed | Test |

---

## 9. Execution order

1. Generators from `inventory.json`: §3.1 GET cells, §3.2 matrices,
   CAFIN and money sentinel sweep. These close G1, G2, G4 and G5 at the
   lowest cost.
2. Super Admin, auth and division route tests (G3, G6, G7).
3. Incoterm × preset behaviour matrix, plus CFR golden files after owner
   double confirmation (G8).
4. Mail matrix and template tests (G10, G11).
5. Kanban parity extension and the crawl (G12, G15).
6. Parity clean-up (G14, G16) and DB assertions (G13).

Every new test is written for both stacks in the same change (CLAUDE.md
parity rule).

---

## 10. Findings to confirm (from inventory, not yet tests)

1. **`send_esi` template missing.** ESI is buyer-sendable, but sending it
   through the queue would fail (`EmailDispatchService::buildPreview`).
   **Done 6 Nov:** `send_esi` added (MAIL-7, D-258 QA3).
2. **`EmailService::sendPlainText` does not check `mail_sending_enabled`.**
   With mail "off", invites, link e-mails and notifications still go out
   over SMTP. This is the same on both stacks. Confirm whether intended.
   **Done 6 Nov:** every business e-mail now goes through the one sender that
   obeys the switch (MAIL-2, D-258 QA3).
3. **Uncommitted work in the tree (excluded from counts):** four new PHP
   routes (`GET /domestic/orders/{id}/zoho-check`,
   `POST /import/{id}/duty-payment`, `GET /import/{id}/zoho-check`,
   `GET /merchant/{id}/zoho-check`) with no Node counterpart yet. The view
   `import/show.php` already posts to `/import/{id}/duty-payment`. Re-run
   the inventory once this lands, and fail the build if the two stacks
   differ. **Done 6 Nov:** landed on both stacks with the Zoho round
   (D-257 (6)); the route tables match (735 routes).
4. **Associate login code and buyer verification code are sent as
   ordinary e-mail**, so they are redirected and CC'd when mail redirect
   is on. **Done 6 Nov:** they now count as security mail (MAIL-13).
5. **`two_fa_backup_codes` is unused** by app code on both stacks, so
   there is no 2FA backup-code path. **Still open** (no backup codes are
   offered; nothing is broken).
6. **The permission category `Admin` (capital A)** on
   `manage_approval_limits` splits the admin group in the permission
   editor. **Still open** (cosmetic; a seed change).
7. **The permission count is 94, not 154.** Update any document that
   says 154. **Done 6 Nov:** READMEs, TEST_PLAN and the register now say 94;
   no document said 154 (older sections quoting 60 or 63 are dated history).

## 11. Results of this round (6 Nov 2026)

- **Code review (Q2):** 107 bugs no test covered — ORD 15, MON 8, DOC 7,
  MAIL 13, ACC 18, UI 15, ZOH 24, PAR 7. Fixed in batches QA1–QA4 (register
  D-258); the deferred items are register open point O-22.
- **Roles and single permissions:** 118 test users × every staff GET route,
  23,010 requests per stack, 0 wrong answers, 0 errors.
- **Incoterm × preset:** 165 combinations per stack, 0 crashes, 0 wrong
  totals or wordings.
- **Mail matrix:** 0 problems on either stack.
- **UI crawl:** about 420 pages per run, Standard and Kanban, desktop and
  phone, both stacks. One finding (order page travel-expense form "not
  focusable" on PHP) did not reproduce in two re-runs; a browser test
  (`qa-order-cost-forms.spec.js`) now covers those forms. All 8 runs clean.
- **Last full run (6 Nov 2026, after incentive caps, backups and associate
  access levels):** PHPUnit 2,425 / 2,425, Jest 2,494 / 2,494, browser suite
  68 / 68 on PHP and 68 / 68 on Node.

## 12. Changes since this round (7–13 Nov 2026) — what the next round must cover

The inventory above is the 5–6 Nov snapshot. Since then (owner decisions D-259 to D-365) the
schema has **210 tables** and the seed **96 permissions** (24 roles; new: `approve_buyer_details_change`,
MD and ED, D-337). Per-stack test files added for 11–13 Nov are listed in each stack's
`docs/QA/TEST_PLAN.md` (sections 15 and 16). D-348 to D-365 were shipped **without running the
suites** (owner's instruction; syntax checks, document renders and browser checks only), so the next
full run starts with them.

Behaviour that changed and the matrices that must follow it:

| Area (decisions) | What changed | Where it lands in this plan |
|---|---|---|
| Price lock (D-342) | From the first approved Quotation every order-line and currency change is refused on the server for everyone, Super Admin and "Edit an order after confirmation" included; changes go through Amendments › Products & prices (price only down, MD approval, AMD document, re-issue checklist). | §2.4 gates; §3.2 every order-line POST route after an approved QT must refuse; the money matrix for the amendment's balance effect |
| Buyer details (D-337) | Client edit updates orders with nothing issued; after that a request approved by `approve_buyer_details_change` (self-approval rule `buyer_details_change`, MD and ED). | §2.3 / §3.2 the new permission and its four routes `/orders/{id}/buyer-details/…`; Approvals › Other |
| Annexures (D-336, D-338, D-341) | "As per annexure" lines (order and reorder), Annexure B made from the CI step, Step 11a annexure choice (A / B / none), one "Save all changes" (`POST /orders/{id}/annexure/save-all`). | §2.4 Stage 8 gate; §2.10 forms; document matrix for CI / PL |
| Suppliers → products → configure (D-358–D-361) | New routes: `/products/{id}/configure` (+ `/reset`, `/use-price`), `/products/{id}/link-supplier`, `/products/link-prices`, `/products/{id}/duplicate`, `/products/suppliers/{supplierId}/edit-price` (+ `/preview`, `/undo`), `/suppliers/{id}/add-product`, `POST /suppliers/offers/{offerId}/active`. Configure and activation need `manage_product_catalog`; + Add product and the offer switch need `manage_suppliers` + `record_market_prices`; Edit price needs `record_market_prices`. The Products page has no "+ Add Product" any more. Supplier PO terms take a supplier offer; a changed price needs a reason. | §2.1 route table, §3.1 / §3.2 role and single-permission matrices; money matrix: base price, Edit price, supplier prices only for those who may see purchase prices |
| Pricing levels and names (D-359, D-362, D-363) | Values from product → category → global; `/pricing/settings` = Global pricing settings and `/products/price-rules` = Category price rules, reached only from the Products link bar (left-menu items removed); Configure › Thickness rules per product. | §5 UI crawl (menu paths); §3.2 `manage_pricing` / `view_price_buildup` |
| Units and lists (D-357, D-364, D-365) | One surface-finish list (Settings › Lists › surface_finish); SQM · SQFT · CBM on every screen, a supplier price per SQFT saved as SQM × 10.7639; the Products list short view with "Show all columns" and the reworked "Pricing ready?". | §2.10 forms; §5 crawl at phone width (the Products table scrolls with the Name column fixed) |
| Order lines (D-348) | Thickness and Size / format start on "Choose…"; a goods line picked from Products without them is refused on the server. | §2.10 forms; order-line route tests |
| Documents (D-339, D-343–D-345, D-349, D-352–D-356) | Detailed product table, Indian grouping (INR orders and every Supplier PO), Word section numbers = PDF, COO line, no stars or mandatory note, keep-with-next, Payment Terms box. | Document fixtures (stone fixtures were captured again where the text changed); the incoterm × preset matrix §3.3 |
| Browser behaviour (D-350, D-351) | `app.js` keeps the pressed button's choice (`keepSubmitterChoice()`) and asks a `data-confirm` written on a button. | §5 crawl: every two-button form (line price approve / refuse, access-level restore defaults, MD-change apply / setting only) |
| Settings (D-340, D-347, D-349) | "Rate used for prices right now" box; a fresh install starts with e-mail Testing mode ON, team incentive on, other costs 5.5 %, margin 15 %; the MD-change step (`/settings/md-change`). | §3.4 mail matrix (Testing mode on by default); §2.8 settings |

Products still have no Kanban board (D-358 note), so §3.5 is unchanged; the Suppliers list's new
"No product prices yet" filter and offer rows must appear on both templates if a Suppliers board
is ever added.
