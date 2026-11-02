# Database migrations — upgrading an existing installation

A **new** installation needs none of these files: load the stack's
`docs/schema.sql` and then `docs/seed.sql` (see the stack README). Both
already contain every change listed here.

An **existing** database (the live site, or a dev copy made before a
release) is brought up to date by running the files in this folder that it
has not had yet, **in filename order**. The two stacks share one database
design, so the same files serve PHP and Node.

```bash
mysql -u <user> -p <database> < docs/migrations/2026-10-31a-client-email-unique.sql
mysql -u <user> -p <database> < docs/migrations/2026-10-31b-payment-references.sql
mysql -u <user> -p <database> < docs/migrations/2026-10-31c-inspection-agency.sql
mysql -u <user> -p <database> < docs/migrations/2026-10-31d-gst-refunds.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01a-departments.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01b-money-separation.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01c-procurement.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01d-coordination.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01e-line-types.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01f-export-service-invoice.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01g-payment-milestones.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01h-product-categories.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-01i-owner-decisions.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02a-associate-system.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02b-gap-shipment-gates.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02c-gap-due-dates-lc.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02d-gap-part-payments.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02e-cfr-wording.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02f-self-approval-rules.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02g-qa-r8-associates.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02h-qa-r8-approvals.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02m-generated-file-links.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02n-document-copy-cleanup.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02r-export-compliance.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02q-remittance-difference.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02s-agreements-register.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02t-remittance-difference-reasons.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02u-reference-library-payment-guide.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02v-logistics-bills-and-own-bank-charges.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02x-associate-tds.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-02y-ecgc-ebrc-incentive-tracking.sql
```

## Rules every file follows

- **MySQL-safe.** The live host runs MySQL 8, dev runs MariaDB. No
  `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS` and no `CREATE INDEX IF NOT
  EXISTS`. Column, index and foreign-key changes are guarded with an
  `information_schema` lookup and a `PREPARE`d statement instead.
- **Safe to run twice.** Every file from 2026-10-25 onward checks before it
  changes anything, so running one again does nothing. Most files say this
  in their first lines.
- **Back up first.** Take a database backup before a release's migrations.

## Latest releases — run in this order

Run each file below that the database has not had yet, in this order, then
deploy the code for both stacks. As of 30 Oct 2026 the live site still
needs **27f, 27g, 28a, 28c, 28d, 28e, 29a, 29b, 29c, 29d, 29e, 29f, 30a, 30b, 30c, 30d, 31a, 31b, 31c, 31d, 11-01a, 11-01b, 11-01c, 11-01d and 11-01e**; every file added since then (**11-01f to 11-01i, 11-02a to 11-02h, 11-02m, 11-02n and 11-02q to 11-02y**, rows 30 to 52 below) is also still to run.

| # | File | What it does |
|---|---|---|
| 1 | `2026-10-27a-balance-due-after-actual-advance.sql` | One-off fix: balance due = FOB − the advance actually received, for orders whose balance is not in yet |
| 2 | `2026-10-27b-payment-action-link.sql` | No-login Payment Action Link for the client (PI advance, Debit Note, CI balance) |
| 3 | `2026-10-27c-logistics-partner.sql` | Logistics Partners directory; Step 9a picks the freight forwarder from it |
| 4 | `2026-10-27d-benefits-at-settlement-and-vendor-bank.sql` | Government benefits recorded at settlement (Step 13g); supplier / logistics partner bank details |
| 5 | `2026-10-27e-vendor-bills-and-order-expenses.sql` | Supplier and logistics partner bills with payments; order expenses with receipts |
| 6 | `2026-10-27f-document-date.sql` | The Commercial Invoice can carry its own date |
| 7 | `2026-10-27g-logistics-bill-payment-kind.sql` | A logistics bill is an Advance or the Final bill |
| 8 | `2026-10-28a-view-cafin-permission.sql` | Permission "View & download CAFIN" (Admin, MD, ED, Accounts Executive, CA) |
| 9 | `2026-10-28c-payment-terms-approval.sql` | Payment-terms approval before the Quotation (permission "Approve payment terms": Admin, MD, ED) |
| 10 | `2026-10-28d-ca-view-orders.sql` | The CA role can open orders read-only |
| 11 | `2026-10-28e-vendor-bill-proforma-and-payments.sql` | Bills are a Proforma or a Tax invoice; a bill can take several payments. Existing paid bills are copied into the new payments table |
| 12 | `2026-10-29a-zoho-stage2-invoices.sql` | Zoho Books Stage 2: remembers which Books invoice each order's Commercial Invoice and Debit Note became, and when each payment was applied to it |
| 13 | `2026-10-29b-zoho-stage3-purchases.sql` | Zoho Books Stage 3: remembers each supplier's and logistics partner's Books vendor, and which bills, vendor payments and order expenses are in Books |
| 14 | `2026-10-29c-two-digit-year-references.sql` | Every new reference number uses the 2-digit year (`SC/CI/26/2910001`) — a GST invoice number may have at most 16 characters. Numbers already issued keep `2026` |
| 15 | `2026-10-29d-zoho-sync-start-date.sql` | Zoho Books go-live date setting (`zoho_books_sync_start_date`): only records dated on or after it go to Books; on the live server nothing is sent until it is set |
| 16 | `2026-10-29e-shipping-bill-and-reverse-charge.sql` | The shipping bill's number, date and customs exchange rate on the order (the CI and Debit Note go to Zoho Books at that rate), and the reverse-charge mark on transport bills |
| 17 | `2026-10-29f-zoho-costs-lc-benefits.sql` | Order costs say what they were paid from and remember their Zoho Books expense; LC orders record the money the bank paid under the LC; export benefits remember their Zoho Books journal |
| 18 | `2026-10-30a-lc-payment-firc.sql` | The LC payment's FIRC / eBRC reference and date, so it joins the INR Settlement Register like the other legs; the setting `zoho_books_restore_checked_at` (when the scheduled Zoho sync last checked the records already in Books — once a day) |
| 19 | `2026-10-30b-team-incentives.sql` | Team incentives and associates: the settings (switched **off** until management turns them on), each person's incentive role, each order's incentive and people, the payouts by incentive month, the associate register and the client's associate, the marker on the order's commission cost line, and the permissions Manage team incentives (Admin, MD), Pay incentives (Admin, MD, Accounts Executive) and View own incentives |
| 20 | `2026-10-30c-ca-view-all-orders.sql` | The CA / Chartered Accountant role sees every order, read-only (`view_all_orders`), not only the orders assigned to it |
| 21 | `2026-10-30d-associate-agreement-po-date.sql` | The associate's signed agreement kept as a PDF (upload context `associate_agreement`), and the date the buyer's PO was received (Step 4d) — Day 1 of the associate's 10 days |
| 22 | `2026-10-31a-client-email-unique.sql` | One email per client, enforced by the database (unique index `uq_clients_email`). **First run `docs/checks/2026-10-28-duplicate-client-emails.sql`** and give any client sharing an email its own; until then this file adds nothing and prints which emails are shared. A blank email becomes "no email" |
| 23 | `2026-10-31b-payment-references.sql` | Rule D-212: the bank / payment reference on buyer remittances (advance, balance, freight), the money paid under an LC, incentive and associate payouts (how paid + reference), export benefits received and cancellation refunds (date + bank ref). Columns only; records already saved keep their data |
| 24 | `2026-10-31c-inspection-agency.sql` | Rule D-212, Batch 2: the inspection agency and its invoice no. on a pre-shipment inspection fee NexaCrest pays (the cost line is described from them). Columns only |
| 25 | `2026-10-31d-gst-refunds.sql` | GST refunds under LUT (owner decision 2026-10-31, option A): the claims (RFD-01, per period) and the money credited to the bank (with its RFD-05 / UTR reference); each credit goes to Zoho Books as a journal. New tables only |

| 26 | `2026-11-01a-departments.sql` | Organisation design D-214, phase 1: departments, their designations (Executive / Manager / Director, each its own role) and people's memberships; the new roles (Sales/Export Manager, Sales Director, Procurement Executive / Manager / Director, Logistics Manager / Director, Finance Manager / Director, System Administrator, Order Coordinator, Operations Manager) with their starting rights; existing staff become members of the department matching their role (Admin accounts are left as they are); the audit log records the department a change was made in; personal exceptions get an end date. New tables and two new columns |

| 27 | `2026-11-01b-money-separation.sql` | D-214, phase 2: who sees which money — the permissions See selling prices, See buyer payments, See purchase prices, See logistics costs, Supplier PO and production, and Mark an order fully settled, granted by department (Sales: selling + buyer payments; Procurement: purchase + Supplier PO; Logistics: logistics costs; Accounts & Finance, MD/ED, auditors and CA: all money; settling: Accounts & Finance and management). New rows only |
| 28 | `2026-11-01c-procurement.sql` | D-214, phase 3: Procurement — purchase requirements (raised by Sales, no prices) and supplier quotes; the permissions Raise purchase requirements (Sales, management), Work purchase requirements (Procurement, management) and Approve purchases (Procurement Manager / Director, management); starting approval limits Procurement Executive ₹0 and Procurement Manager ₹5,00,000 (only where none is set). New tables and rows only |
| 29 | `2026-11-01d-coordination.sql` | D-214, phase 4: one owner per department per order; the kinds of department task with their due and escalation days (editable in Admin → Task Due Days); the permissions Assign department owners (managers, directors, MD/ED, Admin, Order Coordinator), Management cockpit (directors, MD/ED, Admin, Order Coordinator) and Task due days (Admin, MD/ED); the Order Coordinator sees every order (no money). Also brings the tables added on 1 Nov to the database's own collation where they differ. New tables and rows only |
| 30 | `2026-11-01e-line-types.sql` | D-215 (5a): each order line is Goods, Service or Charge (`order_products.line_type`, every existing line goods) and a service's SAC code (`sac_code`). Columns only |
| 31 | `2026-11-01f-export-service-invoice.sql` | D-215 (5a): the Export Service Invoice document type (`ESI`, customer-facing, reference `SC/ESI/{YY}/{DDMM}{NNN}`) with DOCX turned on. New rows only |
| 32 | `2026-11-01g-payment-milestones.sql` | D-215 (5a): an order's own payment schedule — the milestones between the advance and the balance (`order_payment_milestones`), each with its remittance, bank reference, clearing and Zoho Books payment. New table only |
| 33 | `2026-11-01h-product-categories.sql` | D-215 (5a): product categories and their attributes (`product_categories`, `product_category_attributes`; Natural Stone = today's products, Dimensions and Finish) and the order line's category and attribute values (`order_products.category_id`, `attribute_values`, every existing line Natural Stone). New tables and columns only |
| 34 | `2026-11-01i-owner-decisions.sql` | Owner decisions of 1 Nov 2026: System Administrator loses `data_export_run` (A-8); Procurement Executive gets `manage_suppliers` (C-8); `payment_presets.allow_zero_advance`, on for the Letter of Credit presets (C-4); `daily_digest_log` for the once-a-day digest (C-7); an existing order with no assignee gets its creator (C-7). Safe to run twice |
| 35 | `2026-11-02a-associate-system.sql` | Associate system (owner decision 1 Nov 2026, AS-1 … AS-17): associates get status Invited/Active/Suspended/Ended, personal rates that may be empty (empty = the standard ladder), a no-commission switch, portal login fields, a personal code; new tables `associate_tokens`, `associate_bank_accounts`, `associate_documents`, `associate_invitations`, `associate_events`, `associate_messages`, `associate_codes`; intake submissions carry the associate, the buyer's e-mail verification and confirmation; clients carry the 12-month lock; orders carry the rate fixed at creation; payouts can be held or clawed back; the standard ladder, TDS %, protection months and bank waiting days in `incentive_settings`; permissions `manage_associates`, `view_associates`, `manage_associate_bank`; upload context `associate_document`. Safe to run twice |
| 36 | `2026-11-02b-gap-shipment-gates.sql` | Gap list G-1 / G-3 (owner, 1 Nov 2026): `order_inspections` (Passed / Failed, date, agency, report) and `order_shipment_gate_overrides` (a manager's written release of the Bill of Lading step); upload context `inspection_report`; permission `override_shipment_gates` for MD, ED and Admin. Safe to run twice |
| 37 | `2026-11-02c-gap-due-dates-lc.sql` | Gap list answers 1 Nov 2026 (D-218), part B. G-2: a balance due against the BL, and every LC order's balance, has no due date until the scanned BL is sent; existing open balances are corrected (no date while the scanned BL is not sent, else that day + the order's balance days). G-4: `order_lc_details` gets `lc_issue_date`, `lc_expiry_date`, `lc_last_shipment_date`, `lc_presentation_deadline`; new table `order_lc_amendments`; upload context `order_lc_amendment`. Safe to run twice |
| 38 | `2026-11-02d-gap-part-payments.sql` | Gap list answers 1 Nov 2026 (D-218), part C (G-5): new table `order_payment_receipts` (every remittance of the advance, freight, balance and each milestone kept separately; the leg's existing columns keep the total) and `order_payment_shortfalls` (a leg cleared while money was still owed, with the manager's reason); permission `approve_short_payment` (MD, ED, Admin, Finance Director); existing remittances back-filled as one receipt each. Safe to run twice |
| 39 | `2026-11-02e-cfr-wording.sql` | C-16 (owner confirmed twice, 1 Nov 2026): the "Shipment Booking (CFR/CIF)" clause asks for freight only on CFR orders, freight and the insurance premium on CIF. Only a clause still carrying the old seeded text is changed. Documents already generated keep their text. Safe to run twice |
| 40 | `2026-11-02f-self-approval-rules.sql` | Owner decision D-221: table `self_approval_rules` — per rule (payment terms of an order you created, review of a document you generated, approval of an e-mail send you requested) the roles that may self-approve, set in Admin → Self-approval rules by Super Admins. Defaults: Managing Director and Executive Director on all three. Safe to run twice |
| 41 | `2026-11-02g-qa-r8-associates.sql` | QA Round 8 (R8-N05), owner decision AS-17: new column `clients.associate_client_number` — the client's number for its associate (1 for the first client they brought, 2 for the second …), given when the client is linked and never changed (an MD move to another associate gives that associate's next number). Existing linked clients numbered per associate by registration date, then client id; open orders (not cancelled / lost, commission not locked) get their client's number and the rate for it (personal ladder with carry-forward, else the standard ladder; "no commission" 0). Safe to run twice |
| 42 | `2026-11-02h-qa-r8-approvals.sql` | QA Round 8 (R8-N09, R8-N10): four more Self-approval rules — order cancellation, purchase award, amendment MD-approval, short-payment clearing — default Managing Director and Executive Director (seeded only while a rule has no roles and was never changed on the Admin page); new permission `manage_approval_limits` (Managing Director, Executive Director) — only its holders change a designation's approval limit or make it 'director', never on their own designation. Safe to run twice |
| 43 | `2026-11-02m-generated-file-links.sql` | Owner points 6/15 (no money leak): every generated file (draft, final, SUPERSEDED copy, internal DOCX, older CAFIN) gets `file_store.linked_document_id`, so a copy that is no longer the document's current PDF keeps its document type and money class — the file-store download and the dossier ZIP refuse it to people without that money permission, and older CAFIN copies stay CAFIN-only. Back-fills by current pdf/docx, then by order + file name. Safe to run twice |
| 44 | `2026-11-02n-document-copy-cleanup.sql` | Owner points 5/8/9 (document copies): `file_store.removed_at` / `removed_reason`; three settings for Admin → Settings → Document copies clean-up (drafts of approved documents, never-sent superseded revisions, old CAFIN copies — all off); back-fills `file_store.sent_to_client` on the current PDF of every document already sent. Run after 2026-11-02m. Safe to run twice |
| 45 | `2026-11-02r-export-compliance.sql` | D-226 (owner "3 yes"): the Export Compliance department — Executive / Manager / Director roles and designations, four narrow permissions (`manage_compliance_checklist` given to every role that had `close_orders`, `manage_export_benefit_claims`, `generate_compliance_documents`, `upload_order_documents`), and five task types for its My tasks queue. New rows only (INSERT IGNORE). Safe to run twice |
| 46 | `2026-11-02q-remittance-difference.sql` | Owner 2 Nov 2026 ("18 yes", D-227): `order_payment_receipts.difference_reason`, `bank_charges`, `bank_charges_inr`, `bank_charges_cost_id` — why a buyer remittance differs from what is due (bank charges deducted / buyer paid less / buyer paid more); setting `max_bank_charges_pct` (3). Safe to run twice |
| 47 | `2026-11-02s-agreements-register.sql` | D-228 (owner "4 yes"): the Agreements register — tables `agreements` and `agreement_alerts_sent`, permissions `manage_agreements` (Admin, MD, ED) and `view_agreements` (also Sales / Procurement / Logistics / Finance Directors), upload context `agreement_file`, and the admin-managed list `agreement_type`. The client and associate agreements stay where they are (listed read-only). Safe to run twice |
| 48 | `2026-11-02t-remittance-difference-reasons.sql` | Owner follow-up on point 18 (2 Nov 2026, D-227): table `remittance_difference_reasons` (Admin → Remittance difference reasons: label, treatment bank charges / short / excess, order, active) with 11 usual reasons seeded once; `order_payment_receipts.difference_reason_id`, `difference_reason_text`, `difference_reason_custom`. Run after 02q. Safe to run twice |
| 49 | `2026-11-02u-reference-library-payment-guide.sql` | Owner points 11/12 (2 Nov 2026): the Supplier & Logistics Payments Guide (PDF and Word) is listed in the Reference Library. The files ship in git under `storage/assets/reference_library/`; the storage path is copied from the existing Quarry SOP row. Safe to run twice. |
| 50 | `2026-11-02v-logistics-bills-and-own-bank-charges.sql` | Owner decisions (2 Nov 2026), D-232 and D-227 follow-up: new permission `manage_logistics_bills` (Logistics Executive / Manager / Director plus every holder of `manage_supplier_payments`) — Logistics adds logistics partner bills, Accounts pays them; columns `order_payment_receipts.own_bank_charges_inr` and `own_bank_charges_cost_id` for our own bank's fee recorded with a buyer remittance. MySQL-safe (information_schema + PREPARE); safe to run twice. Run after 02u. |
| 51 | `2026-11-02x-associate-tds.sql` | Owner decision (2 Nov 2026, D-235): TDS on associate commission worked out by the app. Adds `incentive_payouts.tds_rate`, `tds_section`, `tds_reason`, `tds_override_reason`, `tds_challan_no`, `tds_challan_date`, and the incentive settings `associate_tds_section` (194H), `associate_tds_threshold_inr` (20000), `associate_tds_no_pan_pct` (20), `associate_tds_foreign` (0), `associate_15cb_required` (1), `zoho_tds_tax_id` (blank). MySQL-safe (information_schema + PREPARE); safe to run twice. Run after 02v. After running: if the CA turns on TDS in Zoho Books, log it in `docs/zoho/zoho-change-log.csv` and put its TDS tax id in Settings → Associate TDS. |
| 52 | `2026-11-02y-ecgc-ebrc-incentive-tracking.sql` | Owner decision (2 Nov 2026, gap-list Q-6, D-234): ECGC buyer credit limit on `clients` (`ecgc_limit_amount`, `_currency`, `_approved_on`, `_valid_to`, `_ref`, `_file_id`) and the monthly shipment declaration on `orders` (`ecgc_declared_on`, `ecgc_declaration_ref`); claim tracking on `ca_export_benefits` (`sanctioned_amount`, `sanctioned_at`, `claim_deadline`, `claim_status`, `rejected_reason`; claims already received set to `received`); table `compliance_alerts_sent` (one reminder per thing and threshold); company settings in category `compliance` (ECGC policy no., cover %, valid to, declaration day, claim-deadline days per scheme — to be confirmed with the CA); upload context `ecgc_limit_letter`. MySQL-safe (information_schema + PREPARE); safe to run twice. Run after 02x. |

There is no `2026-10-28b` file; that letter was not used.

Switching Zoho Books on after these migrations follows the **Go-live
checklist** in `docs/SOP/ca-03-zoho-sync.md` (`.env` switches, then the
go-live date and login in Admin → Company Settings, Zoho Books section). Without the
go-live date, a production server sends nothing to Zoho Books.

## Checks after 2026-10-28

Two read-only queries in `docs/checks/` change nothing. Run them once on
the live database after the 28 Oct release:

- `2026-10-28-duplicate-client-emails.sql` lists every email shared by more
  than one client. The portal now refuses a shared email, so give each
  client its own (Clients → Edit).
- `2026-10-28-blank-dropdowns-and-reference-docs.sql` lists dropdown options
  and reference documents saved empty by the earlier bug. Re-enter the text
  (Reference Library → the document → Edit) or the option name (Admin →
  Dropdown options).

After `2026-10-28e`, this should show the same count and total as the bills
already marked paid:

```sql
SELECT COUNT(*), SUM(amount_inr) FROM order_vendor_bill_payments;
SELECT COUNT(*), SUM(paid_amount_inr) FROM order_vendor_bills WHERE paid_at IS NOT NULL;
```

## Older files

Everything before 2026-10-27a (including `2026-10-25` and the
`2026-10-26` files) was applied to the live site in earlier releases.
Read a file's first lines before running it on an older copy; not every
early file is safe to run twice.
