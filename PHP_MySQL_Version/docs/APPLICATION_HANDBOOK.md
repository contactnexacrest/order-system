# NexaCrest Application Handbook

**Version 1.0 — first go-live edition, November 2026**

The complete description of the NexaCrest export order system: what it is for, how it is built on both stacks (PHP + MySQL and Node + MySQL), every role, rule, screen, money formula, document, setting and test, how to run it, why it is the way it is, and how to change it safely.

| | |
|---|---|
| Application | NexaCrest export order system (`PHP_MySQL_Version/` and `NODE_MySQL_Version/`, in parity) |
| State described | 6 Nov 2026, before go-live |
| Size at this edition | 194 tables · 24 roles · 94 permissions · ~744 routes per stack · PHPUnit 2,433 · Jest ~2,500 · e2e 68 + 68 |
| Copies | `docs/APPLICATION_HANDBOOK.md` (master), `PHP_MySQL_Version/docs/APPLICATION_HANDBOOK.md`, `NODE_MySQL_Version/docs/APPLICATION_HANDBOOK.md` (identical) |
| People | Roles only: Managing Director (md@email.com), Executive Director (ed@email.com), Admin (admin@email.com), company info@email.com, +91-0000000000. Real names are entered only on the live server. |

---

## How to use this handbook

**Who it is for.** A developer, owner or adviser who comes back to this system years from now with no memory of it. It is the input for any future development: read the relevant sections before you change anything.

**How it is organised.**

| If you want to… | Read |
|---|---|
| Understand the business and the words used | §1, §2 |
| Find your way around the code | §3 and Appendix A |
| Know who may see or do what | §4 |
| Follow an order from enquiry to closure | §5 |
| Work on one area of the app | §6 (one subsection per module) |
| Check a money calculation | §7 (worked examples in INR/USD) |
| Change a generated document | §8 first (double confirmation rule) |
| Change a setting or install the app | §9, §12 |
| Know the rules you must never break | §10 |
| Run or add tests | §11 |
| Operate the live system | §12 |
| Know *why* something was decided | §13 and the register `docs/decisions/owner-decisions-register.md` |
| Know what is missing or still open | §14 (open questions in §14.5) |
| Add a division, category, document, role, report, Zoho flow, setting or list page | §15 |
| Make any change | §16 (the change checklist) and Appendix B |

**Conventions.**

- **Both stacks.** A PHP class `App\Services\FooService` (`PHP_MySQL_Version/app/src/Services/FooService.php`) has a Node twin `NODE_MySQL_Version/app/src/services/fooService.js`. Where only the PHP name is given, the Node file is the camelCase twin.
- **Codes.** `D-nnn` = numbered owner decision; `R1…R26` = standing rules; `O-nn` = open points; QA finding IDs (`ZOH-1`, `PAR-7` …) come from the register. Search the code for a D-code to find where it is built.
- **Menu paths** are written *Settings › Company › Zoho Books* (left-menu entry › menu-bar item). Section references are written §7.3. Since D-320 (11 Nov 2026) the left menu has 13 entries and every section has a menu bar (§6.0); every path in this handbook uses those menus (brought up to date on 11 Nov 2026).
- **Money.** Amounts are INR (₹) or USD with two decimals; worked examples were recomputed from the code. Dates and times are IST.
- **Source of truth.** The code. Where this handbook and the code disagree, the code wins and this handbook is wrong: fix it (Appendix B).

---

## Contents

- [How to use this handbook](#how-to-use-this-handbook)
- [1. Purpose and business context](#1-purpose-and-business-context)
  - [1.1 What NexaCrest does](#11-what-nexacrest-does)
  - [1.2 The divisions](#12-the-divisions)
  - [1.3 Who uses the system](#13-who-uses-the-system)
  - [1.4 What the app replaces](#14-what-the-app-replaces)
  - [1.5 How the app relates to Zoho Books](#15-how-the-app-relates-to-zoho-books)
- [2. Glossary](#2-glossary)
  - [2.1 Documents the app generates](#21-documents-the-app-generates)
  - [2.2 Shipping, customs and trade terms](#22-shipping-customs-and-trade-terms)
  - [2.3 Indian regulatory and finance terms](#23-indian-regulatory-and-finance-terms)
  - [2.4 Order process terms](#24-order-process-terms)
  - [2.5 Money, pricing and people terms](#25-money-pricing-and-people-terms)
  - [2.6 System and project terms](#26-system-and-project-terms)
- [3. Architecture](#3-architecture)
  - [3.1 Why two stacks, and the parity rule](#31-why-two-stacks-and-the-parity-rule)
  - [3.2 Tech stack and versions](#32-tech-stack-and-versions)
  - [3.3 Folder layout](#33-folder-layout)
  - [3.4 Request flow](#34-request-flow)
  - [3.5 Templates: Standard and Kanban](#35-templates-standard-and-kanban)
  - [3.6 app.js — sitewide browser behaviour and validation](#36-appjs--sitewide-browser-behaviour-and-validation)
  - [3.7 The database](#37-the-database)
    - [3.7.1 Tables grouped by area](#371-tables-grouped-by-area)
    - [3.7.2 Key relationships](#372-key-relationships)
  - [3.8 Documents: generation, versions and snapshots](#38-documents-generation-versions-and-snapshots)
  - [3.9 Cron jobs and the scheduler](#39-cron-jobs-and-the-scheduler)
  - [3.10 E-mail sending](#310-e-mail-sending)
  - [3.11 File storage](#311-file-storage)
  - [3.12 Zoho integration overview](#312-zoho-integration-overview)
  - [3.13 Security](#313-security)
  - [3.14 Environments and .env](#314-environments-and-env)
  - [3.15 Backups overview](#315-backups-overview)
  - [3.16 Where to look first (quick map)](#316-where-to-look-first-quick-map)
- [4. Roles, permissions and access](#4-roles-permissions-and-access)
  - [4.1 How a permission check works](#41-how-a-permission-check-works)
  - [4.2 The 24 roles](#42-the-24-roles)
  - [4.3 The 94 permissions](#43-the-94-permissions)
  - [4.4 Departments, designations, "Log in as" and "Preview as"](#44-departments-designations-log-in-as-and-preview-as)
  - [4.5 Who may give rights; Super Admin; protected founder accounts](#45-who-may-give-rights-super-admin-protected-founder-accounts)
  - [4.6 Self-approval rules and approval limits](#46-self-approval-rules-and-approval-limits)
  - [4.7 Money visibility (MoneySeparation, D-214 phase 2)](#47-money-visibility-moneyseparation-d-214-phase-2)
  - [4.8 Division visibility (see_all_divisions, D-257)](#48-division-visibility-see_all_divisions-d-257)
  - [4.9 The Investor (view only) role](#49-the-investor-view-only-role)
  - [4.10 The client portal (buyers)](#410-the-client-portal-buyers)
  - [4.11 The associate portal and access levels](#411-the-associate-portal-and-access-levels)
- [5. The order life cycle, step by step](#5-the-order-life-cycle-step-by-step)
  - [5.1 Two trackers on one page: 9 stages and 19 steps](#51-two-trackers-on-one-page-9-stages-and-19-steps)
  - [5.2 Before the order: enquiry and intake](#52-before-the-order-enquiry-and-intake)
  - [5.3 Creating the order](#53-creating-the-order)
  - [5.4 Step-by-step reference](#54-step-by-step-reference)
    - [Step 1–3 · Quotation (Stage 1)](#step-13--quotation-stage-1)
    - [Step 4 · Buyer's PO (Stage 2)](#step-4--buyers-po-stage-2)
    - [Step 5 · Proforma Invoice & Payment (Stage 3)](#step-5--proforma-invoice--payment-stage-3)
    - [Step 6 · Order Confirmation (Stage 4)](#step-6--order-confirmation-stage-4)
    - [Step 7 · Supplier PO (Stage 5) — Procurement](#step-7--supplier-po-stage-5--procurement)
    - [Step 8 · Production & Estimated Shipment](#step-8--production--estimated-shipment)
    - [Step 9 · Freight Payment (Stage 6) — CFR/CIF only](#step-9--freight-payment-stage-6--cfrcif-only)
    - [Step 10 · Packing & BL Instruction (Stage 7)](#step-10--packing--bl-instruction-stage-7)
    - [Step 11 · Commercial Invoice & Balance (Stage 8)](#step-11--commercial-invoice--balance-stage-8)
    - [Step 12 · Closure — Financials, Benefits & Costs](#step-12--closure--financials-benefits--costs)
    - [Step 13 · Closure — Documents & Settlement (Stage 9)](#step-13--closure--documents--settlement-stage-9)
    - [Steps 14–19](#steps-1419)
  - [5.5 What freezes at PI approval (D-262 snapshot)](#55-what-freezes-at-pi-approval-d-262-snapshot)
  - [5.6 Incoterm effects (FOB / CFR / CIF)](#56-incoterm-effects-fob--cfr--cif)
  - [5.7 Payment presets](#57-payment-presets)
  - [5.8 LC orders](#58-lc-orders)
  - [5.9 Amendments, complaints, disputes, cancellation, Mark Lost](#59-amendments-complaints-disputes-cancellation-mark-lost)
  - [5.10 Editing, duplicating, archiving](#510-editing-duplicating-archiving)
- [6. Modules](#6-modules)
  - [6.0 Menus: the left menu and the section menu bars (D-320–D-322, 11 Nov 2026)](#60-menus-the-left-menu-and-the-section-menu-bars-d-320d-322-11-nov-2026)
  - [6.1 Clients and intake](#61-clients-and-intake)
  - [6.1b Buyer details on orders follow the client (D-337)](#61b-buyer-details-on-orders-follow-the-client-d-337-12-nov-2026)
  - [6.2 Products, categories and specifications](#62-products-categories-and-specifications)
  - [6.3 Pricing engine and price requests](#63-pricing-engine-and-price-requests)
  - [6.4 Associates and commission / TDS](#64-associates-and-commission--tds)
  - [6.5 Team incentive (eligibility and caps)](#65-team-incentive-eligibility-and-caps)
  - [6.6 Procurement (RFQ, POs, QC)](#66-procurement-rfq-pos-qc)
  - [6.7 Logistics and freight](#67-logistics-and-freight)
  - [6.8 Documents and review / approval](#68-documents-and-review--approval)
  - [6.9 Payments and remittances](#69-payments-and-remittances)
  - [6.10 CAFIN and the CA module](#610-cafin-and-the-ca-module)
  - [6.11 Reports and dashboard](#611-reports-and-dashboard)
  - [6.11b Approvals page (D-310)](#611b-approvals-page-d-310-11-nov-2026)
  - [6.12 My Tasks and the management cockpit](#612-my-tasks-and-the-management-cockpit)
  - [6.13 E-mail templates](#613-e-mail-templates)
  - [6.14 Zoho Books sync](#614-zoho-books-sync)
  - [6.15 Divisions: Domestic Trade, Import & Distribution, Merchant Trade](#615-divisions-domestic-trade-import--distribution-merchant-trade)
  - [6.16 Backups](#616-backups)
  - [6.17 Admin pages](#617-admin-pages)
  - [6.17b Setup checklist (D-312)](#617b-setup-checklist-d-312-11-nov-2026)
  - [6.18 Sample data playground](#618-sample-data-playground)
- [7. Money rules and formulas](#7-money-rules-and-formulas)
  - [7.1 Pricing (D-245, D-255, D-256)](#71-pricing-d-245-d-255-d-256)
    - [The seven steps](#the-seven-steps)
    - [Global settings and per-product overrides](#global-settings-and-per-product-overrides)
    - [Who sees what](#who-sees-what)
    - [Specifications and options (D-255)](#specifications-and-options-d-255)
    - [One list of surface-finish names (D-357)](#one-list-of-surface-finish-names-d-357)
    - [CBM at 50 mm and above](#cbm-at-50-mm-and-above)
    - [Exact supplier prices (D-256)](#exact-supplier-prices-d-256)
    - ["Included in base price" (D-256)](#included-in-base-price-d-256)
    - [Staleness](#staleness)
    - [Pricing ready? and Try a price (D-256)](#pricing-ready-and-try-a-price-d-256)
  - [7.2 Order-line pricing and MD approval (D-245 P2)](#72-order-line-pricing-and-md-approval-d-245-p2)
  - [7.3 Associate commission (D-245 P3, D-235, D-252)](#73-associate-commission-d-245-p3-d-235-d-252)
  - [7.4 Team incentive (D-245 P4, D-259)](#74-team-incentive-d-245-p4-d-259)
  - [7.5 Profit layers: estimated, locked, paid (D-245 P5)](#75-profit-layers-estimated-locked-paid-d-245-p5)
  - [7.6 Exchange rates](#76-exchange-rates)
  - [7.7 CAFIN logic and milestone money](#77-cafin-logic-and-milestone-money)
  - [7.8 Domestic GST (D-257 Phase 2)](#78-domestic-gst-d-257-phase-2)
  - [7.9 Import: landed cost and duty (D-257 Phase 3)](#79-import-landed-cost-and-duty-d-257-phase-3)
  - [7.10 Merchant trade margin (D-257 Phase 4)](#710-merchant-trade-margin-d-257-phase-4)
  - [7.11 Rounding parity between PHP and Node](#711-rounding-parity-between-php-and-node)
  - [7.12 The D-212 money description rule](#712-the-d-212-money-description-rule)
- [8. Generated documents](#8-generated-documents)
  - [8.1 Document types](#81-document-types)
  - [8.2 Revisions, approval and superseded copies](#82-revisions-approval-and-superseded-copies)
  - [8.3 Signatory and seal](#83-signatory-and-seal)
  - [8.4 Watermarks](#84-watermarks)
  - [8.5 Category-driven columns, quality documents and T&C clause sets (D-257 Phase 0B / 1)](#85-category-driven-columns-quality-documents-and-tc-clause-sets-d-257-phase-0b--1)
  - [8.6 The Natural Stone identity tests](#86-the-natural-stone-identity-tests)
  - [8.7 Double confirmation for any document change](#87-double-confirmation-for-any-document-change)
- [9. Settings and configuration](#9-settings-and-configuration)
  - [9.1 Admin settings areas and keys](#91-admin-settings-areas-and-keys)
    - [Company Settings (company_settings, /settings, manage_company_settings)](#company-settings-company_settings-settings-manage_company_settings)
    - [E-mail (in company_settings)](#e-mail-in-company_settings)
    - [Zoho Books (in company_settings, category zoho_books)](#zoho-books-in-company_settings-category-zoho_books)
    - [Pricing (pricing_settings, Products › Pricing, MD only, audited)](#pricing-pricing_settings-products--pricing-md-only-audited)
    - [Team incentive (incentive_settings, incentive_roles; Finance & accounts › Team incentive settings, MD)](#team-incentive-incentive_settings-incentive_roles-finance--accounts--team-incentive-settings-md)
    - [Associate commission and TDS (incentive_settings)](#associate-commission-and-tds-incentive_settings)
    - [Divisions (divisions, division_modules, division_settings; Settings › Divisions, MD, manage_divisions)](#divisions-divisions-division_modules-division_settings-settings--divisions-md-manage_divisions)
    - [Lists (dropdown_options, Settings › Lists, manage_dropdown_options)](#lists-dropdown_options-settings--lists-manage_dropdown_options)
    - [GST and duty rates](#gst-and-duty-rates)
    - [Document copies clean-up (company_settings, category document_cleanup)](#document-copies-clean-up-company_settings-category-document_cleanup)
    - [Watermarks and DOCX](#watermarks-and-docx)
    - [Remittance difference reasons](#remittance-difference-reasons)
    - [Backups (Settings › Backups, read only, D-260)](#backups-settings--backups-read-only-d-260)
  - [9.2 .env keys (both app/.env.example files)](#92-env-keys-both-appenvexample-files)
  - [9.3 Installation order (D-241)](#93-installation-order-d-241)
  - [9.4 README go-live SQL block (real values)](#94-readme-go-live-sql-block-real-values)
- [10. Hard rules and restrictions](#10-hard-rules-and-restrictions)
  - [10.1 Other standing owner rules](#101-other-standing-owner-rules)
- [11. Testing](#11-testing)
  - [11.1 The suites at a glance](#111-the-suites-at-a-glance)
  - [11.2 Running the suites locally](#112-running-the-suites-locally)
  - [11.3 Test databases and isolation](#113-test-databases-and-isolation)
  - [11.4 The QA matrices and the UI crawl (built 5–6 Nov 2026, D-258)](#114-the-qa-matrices-and-the-ui-crawl-built-56-nov-2026-d-258)
  - [11.5 Conventions tests (the ones that enforce the standing rules)](#115-conventions-tests-the-ones-that-enforce-the-standing-rules)
  - [11.6 Continuous integration](#116-continuous-integration)
  - [11.7 Known slow or fragile tests](#117-known-slow-or-fragile-tests)
  - [11.8 Adding a test for a new feature, on both stacks](#118-adding-a-test-for-a-new-feature-on-both-stacks)
- [12. Operations](#12-operations)
  - [12.1 Install and go-live](#121-install-and-go-live)
  - [12.2 Daily and weekly checks](#122-daily-and-weekly-checks)
  - [12.3 Backup and restore (SOP 32, D-260)](#123-backup-and-restore-sop-32-d-260)
  - [12.4 Zoho](#124-zoho)
  - [12.5 Troubleshooting](#125-troubleshooting)
  - [12.6 Logs](#126-logs)
- [13. Decision history](#13-decision-history)
  - [13.1 The big "whys"](#131-the-big-whys)
  - [13.2 Decisions by theme (condensed)](#132-decisions-by-theme-condensed)
  - [13.3 Standing rules in one list](#133-standing-rules-in-one-list)
  - [13.4 Owner decisions of 6 Nov 2026 recorded here](#134-owner-decisions-of-6-nov-2026-recorded-here)
- [14. Known limits and open items](#14-known-limits-and-open-items)
  - [14.1 Deferred items (decided to wait)](#141-deferred-items-decided-to-wait)
  - [14.2 What is NOT built](#142-what-is-not-built)
  - [14.3 Pending answers from the CA, the bank and the lawyer (docs/external/)](#143-pending-answers-from-the-ca-the-bank-and-the-lawyer-docsexternal)
  - [14.4 Placeholders to replace at go-live](#144-placeholders-to-replace-at-go-live)
  - [14.5 Open questions](#145-open-questions)
- [15. Scope for enhancement](#15-scope-for-enhancement)
  - [15.1 Activate (or add) a division](#151-activate-or-add-a-division)
  - [15.2 Add a product category (e.g. Engineering)](#152-add-a-product-category-eg-engineering)
  - [15.3 Add a document type](#153-add-a-document-type)
  - [15.4 Add a role or permission](#154-add-a-role-or-permission)
  - [15.5 Add a report](#155-add-a-report)
  - [15.6 Add a Zoho flow](#156-add-a-zoho-flow)
  - [15.7 Add a setting](#157-add-a-setting)
  - [15.8 Add a list page (Standard + Kanban)](#158-add-a-list-page-standard--kanban)
  - [15.9 Ideas already discussed with the owner](#159-ideas-already-discussed-with-the-owner)
- [16. How to work on this codebase](#16-how-to-work-on-this-codebase)
- [Appendix A. Index of code locations](#appendix-a-index-of-code-locations)
- [Appendix B. Keeping this handbook current](#appendix-b-keeping-this-handbook-current)

---

## 1. Purpose and business context

### 1.1 What NexaCrest does

NexaCrest International Private Limited is an Indian export trading company. Today it **exports Natural Stone** (granite and similar stone products: slabs, tiles, monuments, cut-to-size pieces) to buyers abroad. The stone is bought from Indian suppliers (quarries, factories, traders), checked, packed in wooden crates, shipped by sea in containers, and invoiced in a foreign currency (normally USD).

The company plans to sell **other products later, for example engineering goods** (machined, forged or cast parts). The system is already prepared for this (owner decision **D-257 Phase 0B / Phase 1**, 5 Nov 2026):

- Each **product category** has its own settings: units, default HS code, packing type, the quality documents its orders need, and the set of Terms & Conditions clauses its documents print.
- **Natural Stone is a system category.** It always prints today's documents, word for word. Tests (`StoneDocumentsUnchangedTest` / `stoneDocumentsUnchanged.test.js`) compare the PDF and Word text against copies captured before the change.
- Engineering clauses (Specification & Drawings, Quality Documents, Traceability & Test Certificates …) exist as a **starting text** that the lawyer must review before the first non-stone sale.
- **Fill-in tokens in clause text** are replaced when a document is built, on both stacks: `{advance_pct}`, `{balance_pct}`, `{tolerance}`, `{quotation_validity_days}`, `{pi_validity_days}` and, since D-331 (11 Nov 2026), `{bank_name}`, which comes from Settings › Company › Bank name (the company details saved with the document). The T/T definition uses it, so a change of bank reaches new documents without editing the clause; issued documents never change.

Document references carry the prefix `SC` (for example `SC/PI/26/0511001`; client and order references start `NC/SC/…`). `SC` is the fixed short form used in every reference (D-178). It comes from the project's early working name "StoneCrest" (Workflow Specification v2; the `composer.json` / `package.json` descriptions still say "NexaCrest / StoneCrest"). The specification and the app were renamed NexaCrest (D-199), but the prefix stays, because numbers already issued must never change.

### 1.2 The divisions

One company, several **divisions** (D-257, 5 Nov 2026). Every order belongs to one division for life.

| Code | Division | Status at go-live | What it is | SOP |
|---|---|---|---|---|
| EXP | Export | **Active** (the only active one) | Export of goods from India: today's stone business. All 9 stages, all documents. | 01–27 |
| DOM | Domestic Trade | Built, **planned** (off) | Sale to Indian resellers in INR with GST tax invoices. | 29 |
| IMP | Import & Distribution | Built, **planned** (off) | Import into India, customs duty and landed cost, stock, local sale. | 30 |
| MTT | Merchant Trade | Built, **planned** (off) | Buy abroad, sell abroad (RBI merchanting trade), both legs in foreign currency. | 31 |

Key points:

- A planned division cannot be used until the MD activates it in **Settings › Divisions** (with a pre-flight checklist).
- **While only one division is active, divisions are invisible** to staff: no switcher, no Division filter, no Division field. Only Settings › Divisions shows them.
- Company-level people (Admin, MD, ED, Finance, Audit, Administration, CA, Investor) hold `see_all_divisions`. Everyone else sees only their own division.
- Each division can switch **modules** on or off (see Glossary: *module switch*).
- Each division can override company, pricing and incentive settings (`division_settings`). Export has no overrides.
- Non-Export divisions number their records separately, prefixed with their code (for example `DOM/NC/SC/ORD/26/0511001`).

### 1.3 Who uses the system

There are **three separate sign-ins** that never open each other's pages, plus several no-login token links.

| Who | Sign-in | What they do | Guard |
|---|---|---|---|
| **Staff** (all departments) | `/login` + a 6-digit code e-mailed at every login (D-265) | Run orders, documents, payments, reports, settings | `SessionAuth` + `PermissionCheck` |
| **Buyers (clients)** | `/client/login` + a 6-digit code e-mailed at every login (D-265) | See their own orders, download released documents, report payments, acknowledge the OC, give feedback, request reorders, raise complaints | `ClientAuth` |
| **Associates** (outside agents who introduce buyers) | `/associate/login` (+ e-mailed code) | Their own clients and orders, quotes (by access level), commission and payouts. Never costs, margins or supplier prices. | `AssociateAuth` |
| Prospects, buyers, suppliers, partners **without login** | Token links: `/quotation-details`, `/pi-details/{token}`, `/buyer-action/{token}`, `/payment-action/{token}`, `/supplier-action/{token}`, `/vendor-details/{token}`, `/associate-register/{token}`, `/invite/{token}` | Fill in their details, upload a signed PO or acknowledgment, send payment proof | Random token, hashed/encrypted at rest; rate limits on public forms |

**Staff departments** (MGMT, SALES, PROC, LOG, EXPORT_COMPLIANCE, FIN, AUDIT, ADMIN, OPS), their designations and which ones see the whole company are listed once, in §4.4. The 24 roles are in §4.2.

Other important user types:

| User type | Role / flag | Notes |
|---|---|---|
| **MD** (Managing Director) | role *Managing Director*, protected founder account | Final authority: approvals, price below floor, locking incentives, activating divisions. Login placeholder `md@email.com`. |
| **ED** (Executive Director) | role *Executive Director*, protected founder account | Same business authority as MD by default. Login placeholder `ed@email.com`. |
| **Admin** | role *Admin* (system role) | Full system access including user/role administration. Placeholder `admin@email.com`. |
| **Super Admin** | flag `users.is_super_admin` | Can never be deleted or deactivated (DB trigger). Manages Self-approval rules, delegations. |
| **CA** (Chartered Accountant) | role *CA / Chartered Accountant* | CA/Accounting module (expenses, bank reconciliation, FY lock, export benefits, GST refunds, Zoho sync). Can open orders read-only, sees all orders and CAFIN (`view_orders`, `view_all_orders`, `view_cafin`). |
| **Investor** | role *Investor (view only)* | Dashboards, management cockpit and reports across every division. Every page that changes anything is refused (D-257 Phase 5). |
| **Associates** | `associates` table, own portal | Access level Starter / Quoting / Full / Custom set by the MD (D-261). Commission on invoiced FOB (D-245 P3), TDS deducted (D-235). |
| **Clients** | `clients` + `client_logins` | One client record can buy from several divisions (`client_divisions`). |

### 1.4 What the app replaces

Before the app, each order was run from a folder of **Word and Excel templates** (42 files in 8 folders: buyer-facing documents, internal checklists, SOP and stage-gate sheets) filled in by hand, with e-mails and spreadsheets to track payments. The source review found real defects, for example Tier-2 (post-BL) payment wording sitting inside the Tier-1 (before-shipment) Order Confirmation and Commercial Invoice. The app replaces this with:

- **One database record per order** and every document generated from it (PDF and Word), so a figure is typed once.
- **Stage gates** that stop a later step until the earlier one is done.
- **Review and approval** of every document before it goes to the buyer, with DRAFT/FINAL watermarks.
- **Payment presets** as the single source of payment wording (fixes the Tier-1/Tier-2 mix-up structurally).
- **Audit log** of every action, **role-based money visibility**, and a **client portal** instead of e-mail attachments.

Lead capture and nurture **stay in Zoho CRM** and are out of scope. A Zoho-qualified buyer is sent the quotation-details form link; from there the app runs the order to closure.

### 1.5 How the app relates to Zoho Books

| | The app | Zoho Books |
|---|---|---|
| Role | **Operational system of record**: orders, documents, payments as they happen, costs, profit, incentives, commissions | **Accounting ledger**: invoices, customer payments, bills, vendor payments, expenses, journals, GST |
| Direction | Pushes to Books (**one way, app → Zoho**) | Receives; the app only reads Books to check ("App vs Zoho check") |
| When | Hourly job `zoho_sync` plus "Sync now" / "Sync this order" | — |
| What goes | Commercial Invoice and Debit Note as invoices (at the customs exchange rate from the shipping bill), customer receipts, vendor bills and payments, expenses, cost entries, LC receipts, export benefits, GST refund journals, associate payout cost journals (D-264), optionally customers and items (D-238); the new divisions' records too (D-257 (6)) | — |
| Description rule | Every money record carries the same description in the app and in Books (D-212) | — |
| Safety | `.env` switches (`ZOHO_INTEGRATION_ENABLED`, `ZOHO_ENVIRONMENT`, `ZOHO_EINVOICE_ENABLED`) + Admin setting "Zoho Books enabled" + go-live date | — |

CAFIN (the internal financial annexure) and the profit figures are built in the app, not in Books. Books is where the CA files GST and closes the year. Details: §3.12 (how the integration is built), §6.14 (the module) and §12.4 (running it).

---

## 2. Glossary

Terms are grouped. "Code" means the `document_types.code` value in the database.

### 2.1 Documents the app generates

Full list with formats, PDF/DOCX and audience: §8.1. All reference formats come from `document_types.ref_format` and use a 2-digit year since 29 Oct (`{YY}`; numbers issued earlier keep `2026`). Example: `SC/CI/26/2910001` = Commercial Invoice, 2026, 29 Oct, first of the day. A GST invoice number may have at most 16 characters, which is why the year is short.

| Code | Name | Audience | Stage / step | Notes |
|---|---|---|---|---|
| **QT** | Quotation | Buyer | Stage 1 | Valid for `quotation_validity_days` (default 30). Needs payment-terms approval first if the preset "Requires MD approval". |
| **ANNEXA** | Annexure A | Buyer | Stage 1 | Optional product annexure with images and terms (`include_annexure_a`). Reference `SC/ANX/…`. Annexure B (as shipped, D-336) is no separate type: it is printed inside the CI / PL — see §8.8. |
| **BUYERPO** | Buyer PO — Order Acceptance | Buyer | Stage 2 | A PO the app prepares for the buyer to sign when the buyer has no PO of their own. |
| **PI** | Proforma Invoice | Buyer | Stage 3 | Asks for the advance. Amber "ADVANCE PAYABLE NOW" box, or "NO ADVANCE PAYABLE — … by Letter of Credit". **The first PI approval freezes the order** (D-262). |
| **OC** | Order Confirmation | Buyer | Stage 4 | Buyer acknowledges in the portal; auto-confirmed after 48 hours. |
| **SUPPO** | Supplier PO — Material Procurement | Supplier (never the buyer) | Stage 5 | Purchase order to the stone supplier; `never_shown_to_buyer = 1`. |
| **FDN** | Freight Debit Note ("DN", "Debit Note") | Buyer | Stage 6 (CFR/CIF only) | Charges freight (and insurance on CIF). Goes to Zoho Books as an invoice. Auto-skipped for FOB. |
| **PL** | Packing List | Buyer | Stage 7 | Crate-by-crate weights and volumes. |
| **BLI** | BL Instruction Sheet | Internal / CHA | Stage 7 | Instructions to the forwarder/CHA for drafting the Bill of Lading. |
| **CI** | Commercial Invoice | Buyer and customs | Stage 8 | Goods only (services go on the ESI). Uses `documents.document_date`. Goes to Zoho at the customs exchange rate. |
| **ESI** | Export Service Invoice | Buyer | Stage 8 | Separate invoice for service lines (D-215). |
| **COOPREP** | Certificate of Origin Preparation sheet | Internal | Stage 8–9 | Data sheet for applying for the COO (through CAPEXIL). |
| **BLE** | Bill of Lading Endorsement | Buyer | Closure | Endorsement of the original BL after the balance is paid. |
| **AMD** | Payment Terms Amendment | Buyer signs it (category *internal*: never in the portal) | Any | Legal agreement changing advance %, balance trigger or days. Request → MD approval → generate → upload signed copy → activate. |
| **CAFIN** | CA Financial Annexure — Government Benefits & Expenses (Internal Only) | **Internal only, never the client** | Any stage; automatic at *Mark Fully Settled* | Snapshot of money received, costs, benefits and profit. Needs `view_cafin`. Template `templates/CAFIN/`. |
| CHECKLIST, CHECKLIST_2_FINANCE, CHECKLIST_3_PACKING, CHECKLIST_4_SHIPPING | Cross-verification checklists 1–4 | Internal | Before release | One per role (Sales, Finance, Packing, Shipping). In-app Reference Library pages (`/reference-docs/{code}`), not per-order documents. |
| DOM / IMP / MTT | Division documents | Varies | Division flows | Domestic tax invoice; import PO; merchant trade deal documents. |
| SOP_A_SALES, SOP_B_SALES, STAGEGATE, WALLREF | Reference documents | Internal | — | Internal reference sheets carried over from the old template set; in-app Reference Library pages. |

### 2.2 Shipping, customs and trade terms

| Term | Meaning | Where in the app |
|---|---|---|
| **BL** | Bill of Lading: the shipping line's receipt and title document for the goods. Draft BL → checked → original BL issued → scanned copy sent → originals couriered after balance. | Stage 7, `order_shipping`, `order_draft_bl_reviews`, `order_bl_endorsements` |
| **COO** | Certificate of Origin. For stone it is applied for through CAPEXIL. | COOPREP document; `coo_type` on client/order (`TBC` blocks Stage 1) |
| **Incoterms** | ICC 2020 delivery terms. Seeded: **FOB** (Free On Board, named port of loading; default), **CFR** (Cost and Freight, named port of discharge), **CIF** (Cost, Insurance and Freight). | `incoterms`; order snapshot `incoterm_code`. On FOB, Stage 6 is skipped. On CFR/CIF the freight sentence follows the incoterm (D-219). |
| **CHA** | Customs House Agent: clears goods through Indian customs; often also the forwarder. | `logistics_partners` |
| **Shipping bill** | Indian customs export declaration. Its number, date and **customs exchange rate** are recorded. | `order_shipping.shipping_bill_*`, `POST /orders/{id}/shipping-bill` |
| **HS code** | Harmonised System tariff code for goods (stone default 680293). | `hs_codes`, order lines |
| **SAC** | Services Accounting Code, the GST code for a service line. | `order_products.sac_code` |
| **LC** | Letter of Credit: a bank's promise to pay on documents. A preset with payment method `LC` has 0% advance. | `order_lc_details`, `order_lc_amendments` |
| **Pre-shipment inspection** | Inspection before shipping; a Passed inspection can be a shipment gate. | `order_inspections`, `ShipmentGateService` |
| **Port routes** | Pairs of ports with suggested sailing lead time. | `ports`, `port_routes` |

### 2.3 Indian regulatory and finance terms

| Term | Meaning | Where in the app |
|---|---|---|
| **IEC** | Importer-Exporter Code issued by DGFT; needed for any export. | Company settings (category `company`); printed on documents |
| **LUT** | Letter of Undertaking: lets the company export without paying IGST (zero-rated supply). Renewed every financial year. | Settings `lut_number`, `lut_valid_fy`, `lut_expiry_date`; alerts 30 days before, escalation to MD 15 days before (`check_alerts`) |
| **RCMC** | Registration-cum-Membership Certificate from the export promotion council (CAPEXIL for stone). Needed for COO and benefits. | Settings `rcmc_number`, `rcmc_valid_until`; same alert pattern |
| **CAPEXIL** | Export promotion council for stone and minerals; issues the RCMC and the COO. | COOPREP |
| **FIRC** | Foreign Inward Remittance Certificate: the bank's proof that foreign currency came in. | Payment legs, LC receipts (`lc_firc_reference`) |
| **eBRC** | Electronic Bank Realisation Certificate on the DGFT system; proves export proceeds were realised. Needed for benefits and GST refunds. | *Missing eBRC/FIRC* report (`/reports/missing-ebrc`), module `firc_fema` |
| **FEMA** | Foreign Exchange Management Act: export proceeds must be realised within the RBI time limit. | Module `firc_fema`; tracking via FIRC/eBRC |
| **ECGC** | Export Credit Guarantee Corporation: insures export receivables. Per-client limit and per-order declaration. | `clients.ecgc_limit_*`, `orders.ecgc_declared_on`, `ComplianceTrackingService` (D-234) |
| **RoDTEP / Duty Drawback** | Export incentive schemes (refund of embedded taxes/duties) credited as scrips or cash. | "Export benefits": `ca_export_benefits`, `order_export_benefit_schemes`, module `export_benefits` |
| **GST refund** | Refund of input GST on exports under LUT. | `gst_refund_claims`, `gst_refund_credits` (D-213), module `lut_igst` |
| **IGST** | Integrated GST (interstate/import). Relevant for IMP (IGST paid on import) and DOM. | `gst_rates`, `GstCalculator` |
| **GSTIN** | GST registration number of an Indian party. | `clients.gstin` (DOM), vendor bill snapshots |
| **Reverse charge** | GST paid by the recipient (for example on some transport bills, 5%). | `order_vendor_bills.gst_reverse_charge` |
| **e-invoice (IRN) / e-way bill** | GST electronic invoice registration / goods movement permit. | Only with `ZOHO_EINVOICE_ENABLED=true` **and** `ZOHO_ENVIRONMENT=production` |
| **TDS** | Tax Deducted at Source. Associates' commission: section 194H, 2% above ₹20,000 a year with catch-up, 20% without PAN. | `AssociateTds`, `/associates/tds` (D-235) |
| **UTR / SWIFT** | Bank transfer reference numbers (Indian NEFT/RTGS UTR; international SWIFT/MT103). | Required "bank reference" on money forms (D-212) |
| **FY / FY lock** | Indian financial year (1 April – 31 March). A locked FY refuses changes to its money records. | `ca_fy_locks`, `CaFyLockGuard` |
| **INR Settlement Register** | Per-order record of each foreign-currency leg (advance, freight, balance, LC) and the rupees actually credited. | Order Step 12 and CA pages |

### 2.4 Order process terms

| Term | Meaning |
|---|---|
| **Stage (1–9)** | The 9 fixed stages in `stages_master`: 1 Enquiry & Quotation, 2 Buyer PO, 3 Proforma Invoice, 4 Order Confirmation, 5 Supplier PO, 6 Freight Payment, 7 Packing & BL Instruction, 8 Commercial Invoice & Balance, 9 Document Despatch & Closure. |
| **Stage gate** | The one action that "passes" a stage and unlocks the next (for example Stage 3 passes when the advance is cleared, or the LC is confirmed usable). It is refused until that stage's document is finished (`stageDocumentGate()`). |
| **Step / step card** | The order page is a list of collapsible step cards, numbered by the real order of work (1 QT … 12 Financials … Complaints, Disputes). Steps are finer than stages; sub-steps are lettered (4d, 7e, 9a, 10f …). |
| **Force-generate** | Override to generate a later-stage document before its stage; needs a typed reason; audit-logged. |
| **Order lock** | `orders.is_locked`: set once an order is complete or lost. `OrderLockGuard` refuses edits. Separately, the "frozen" state after the PI (see *snapshot*). |
| **Payment preset** | A named set of payment terms in `payment_presets`: advance %, method (`CASH_ADVANCE` / `LC`), balance %, balance trigger (`A_BEFORE_SHIPMENT` / `B_AGAINST_BL`), balance days, printed wording, "Requires MD approval", "allow zero advance". Five are seeded; the order copies its preset at creation. Table and worked examples: §5.7. |
| **Tier 1 / Tier 2 (A / B)** | Old names for the two balance triggers: Tier 1 / Option A = balance before shipment; Tier 2 / Option B = balance against the scanned BL. |
| **Payment milestones** | An optional schedule of several payment parts for one order (`order_payment_milestones`, D-215), beyond the simple advance/balance split. |
| **Line type** | Order line is Goods, Service or Charge (`order_products.line_type`). Goods go on the CI; services on the ESI; goods FOB excludes services. |
| **Remittance difference** | When the buyer's payment differs from the amount asked: a reason (bank charges, exchange, short payment …) and optional bank charges booked as a cost (D-227, D-244). |
| **Shortfall / write-off** | A short payment that is approved and cleared (`order_payment_shortfalls`, permission `approve_short_payment`). The balance formula is net of write-offs (ORD-09). |
| **Shipment gate** | Step 10f waits for a before-shipment balance and a Passed inspection unless a manager releases it with a reason (`order_shipment_gate_overrides`). |
| **Mark Fully Settled** | Final money step; generates CAFIN automatically. |
| **Amendment** | Change of payment terms during an order (AMD document). Activation updates the order's own terms. |
| **Dispute / Complaint** | Dispute = commercial disagreement with formal replies; Complaint = quality/service complaint, split out on 23 Oct. Both have their own step card and portal pages. |
| **Reorder request** | A buyer's request (portal) to repeat an earlier order. |
| **Intake** | The forms a buyer fills: *quotation details* (public form, becomes a client after staff review) and *PI details* (per-order link to confirm buyer/consignee/notify party). |
| **Buyer Action Link / Supplier Link / Payment Action Link** | No-login token links: buyer uploads signed PO / acknowledgment; supplier downloads the Supplier PO and uploads acknowledgment; buyer reports payment details (UTR, amount, date). |
| **Cancellation** | Order cancellation workflow with request and decision (`order_cancellations`). |
| **Lost** | Order status `lost`: buyer walked away; reason required. |

### 2.5 Money, pricing and people terms

| Term | Meaning |
|---|---|
| **Money visibility** | Each person sees only the money their permissions allow: `view_selling_prices`, `view_buyer_payments`, `view_purchase_prices`, `view_logistics_costs` … (`MoneyVisibility`, D-214 phase 2). Sales sees the selling side, Procurement the buying side, Logistics only logistics costs; Finance, Management, Audit and CA see all. |
| **Minimum price / floor / quote price** | Pricing from the supplier price (D-245 P1/P2). Below the floor the MD approves; below the minimum nobody can. The QT and PI wait for the approval. |
| **Price snapshot** | `order_products.price_snapshot`: the line's price build-up, fixed at the PI. |
| **Associate commission** | Paid to an associate on the invoiced FOB of goods, only after the buyer has paid in full; level fixed at the PI (D-245 P3). |
| **Team incentive** | Reward to staff per order from profit after associate commission; Open → Ready → Locked by MD → Paid; eligibility and caps (D-245 P4, D-259). |
| **Profit (estimated / locked / paid)** | One profit calculation in `OrderProfitabilityService`: before commission & incentive → commission → incentive → after. |
| **Vendor bill** | Supplier or logistics bill (proforma or tax invoice), with part payments (`order_vendor_bills`, `order_vendor_bill_payments`). Logistics enters, Accounts pays (D-232). |
| **Maker-checker / Self-approval** | A person may not approve their own request unless Users & roles › Self-approval rules allows their role for that rule (seven-plus rules: payment terms, document review, e-mail send, cancellation, purchase award, amendment MD-approval, short-payment, price discount). `MakerCheckerGuard`, `SelfApprovalRules`. |
| **Grant guard** | Nobody can grant a right they do not hold or change their own rights (`GrantGuard`, D-216). |
| **Department / designation** | A person can belong to several departments; they work under one at a time ("Log in as"). Designations (Executive/Manager/Director) map to roles. |
| **Preview as** | Admin views the app as a designation would see it; read-only (nothing can be posted). |
| **Protected founder account** | MD and ED accounts (`users.is_protected_account`). Name, e-mail, phone, role, designation, signatory flag, active flag cannot be changed through the app or SQL while the trigger is on. |
| **Super Admin** | `users.is_super_admin`; cannot be deleted or deactivated (trigger). |
| **Field protection** | Protected rows in `company_settings`, `tc_clauses`, `payment_presets` cannot be deleted or blanked (triggers); lock/unlock needs a second person's approval. |
| **Cockpit / My tasks / Daily digest** | Management overview (`/cockpit`), each person's queue (`/my-tasks`), and the 08:15 IST e-mail of what waits on each person. |

### 2.6 System and project terms

| Term | Meaning |
|---|---|
| **Stack** | One of the two complete implementations: `PHP_MySQL_Version` or `NODE_MySQL_Version`. |
| **Parity** | Both stacks must behave the same; every change goes into both (rule R1). |
| **D-codes (D-001 … D-262)** | Numbered owner decisions in `docs/decisions/owner-decisions-register.md`. Stable numbers, never renumbered. Cite them in code comments and commits. |
| **The register** | That file. It also holds standing rules **R1, R2 …**, a test finding index, and **open points O-nn**. Rule: never re-ask a decided point. |
| **Finding IDs** | QA finding codes cited in comments: `R5-24`, `R6-N04` (QA Round 5/6/8), `QA-5 UP-02`, `AUTH-10`, `L-26`, `M-09` (external QA report), `ACC-2`, `ZOH-1`, `MAIL-2`, `PAR-2` (QA fix batches, D-258). |
| **"Confirmed twice"** | A change to a generated document needs the owner's double confirmation of before/after (rule R6). |
| **Standard / Kanban** | The two UI templates. Standard = list pages; Kanban = boards (columns by stage/status). Each user picks one (`users.ui_template_preference_id`), else the company default `default_ui_template`. **Both must offer the same functions** (hard rule 1 Nov; `KanbanParityTest` / `kanbanParity.test.js`). |
| **Division** | See 1.2. `division_id` on orders, users (NULL = company level), departments, associates, price requests, report definitions, CA expenses. |
| **Module switch** | Per-division on/off for a part of the system (`division_modules`; no row = on). Keys: `sales_documents`, `supplier_purchase`, `export_shipping`, `lut_igst`, `export_benefits`, `firc_fema`, `ecgc`, `associates`, `team_incentive`, `import_stock` (IMP only), `merchant_trade` (MTT only). A module that is off hides its menu and its addresses answer 403 (`DivisionService::MODULES`, checked in `SessionAuth`). |
| **Snapshot** | The word is used for several "copy at a moment" mechanisms: (1) **order snapshot columns** — preset, incoterm, ports, currency, buyer/consignee/notify party copied onto `orders` at creation, so editing a lookup never rewrites an old order; (2) **frozen snapshot** — at the first PI approval, `OrderSnapshotService::freeze` stores company details, T&C clauses, categories, tolerance, MD-approval flag and pricing FX rate (`orders.frozen_snapshot_json`, D-262); (3) **revision data snapshot** — `document_revisions.data_snapshot` and `documents.render_context_json` hold the exact data used to render each revision (DOC-2); (4) **CAFIN** is a pre-formatted financial snapshot; (5) **price snapshot** on order lines; (6) **backup snapshots** of storage. |
| **TEST- records** | (a) Records created while **Test Mode** is on get a `TEST-` prefix and are hidden from real reports. (b) In **Zoho**, test customers and their documents are named `TEST-…`; with `ZOHO_ENVIRONMENT=test` only those may be sent; all must be removed before go-live (`zoho_cleanup_test_records`) and each removal logged. |
| **Test Mode** | Sandbox switch (`test_mode_settings`): client portal and public form closed, business e-mail redirected to one test address, admin settings frozen, records flagged TEST-. |
| **Sample data** | `is_sample_data = 1` rows from the Sample Data Playground; hard-deletable; left out of CAFIN export and Zoho. |
| **Zoho change log** | `docs/zoho/zoho-change-log.csv` (UTF-8 with BOM): one row (Z-001 …) for every change made in Zoho by anyone, plus read-only checks. Different from the app's own **Zoho Sync Log** table (`zoho_sync_log`). |
| **Migration** | After go-live, every schema change is a guarded, re-runnable SQL file in `docs/migrations/` (information_schema + PREPARE; no `IF [NOT] EXISTS` column/index syntax because the host runs MySQL). Before go-live all migrations were folded into `schema.sql` / `seed.sql` (D-241). |
| **Reference library** | Internal documents and guides shown inside the app (`internal_reference_docs`, `reference_library_documents`). |

---

## 3. Architecture

### 3.1 Why two stacks, and the parity rule

| Stack | Folder | Runs on | Why it exists |
|---|---|---|---|
| PHP + MySQL | `PHP_MySQL_Version/` | **Bluehost shared hosting** today (cPanel, cron, no long-running processes, no Chromium) | Must run where the company is hosted now, with nothing but PHP + MySQL. |
| Node + MySQL | `NODE_MySQL_Version/` | A **VPS** (needs Node and a Chromium binary for PDFs) | So the company can move to a VPS at any time without redevelopment. |

Both stacks use **the same database schema** (`docs/schema.sql` is identical in purpose; 194 tables in each), the same seed data, the same URLs, the same screens, the same documents and the same business rules.

**Parity rule (R1, CLAUDE.md):** every change goes into both stacks, and into both UI templates (Standard and Kanban), in the same change, with tests on both. A feature is not done until it works on both. The Playwright suite in `e2e/` runs the same browser tests against both (`npm run test:php` → `http://127.0.0.1:8080`, `npm run test:node` → `http://127.0.0.1:3000`).

Mapping between stacks is mechanical: PHP `App\Services\OrderSnapshotService` ↔ Node `src/services/orderSnapshotService.js`; PHP `Controllers/OrderController.php` ↔ Node `controllers/ordersController.js`; PHP view `views/orders/show.php` ↔ Node `views/orders/show.njk`; Twig document template `templates/PI/*.html.twig` ↔ Nunjucks `templates/PI/*.njk`. Counts match: 89 controllers each; 120 repositories each; ~110 PHP / 106 Node service files (some PHP services sit in subfolders such as `Services/Docx/`).

### 3.2 Tech stack and versions

Verified from `PHP_MySQL_Version/app/composer.json` + `composer.lock`, `NODE_MySQL_Version/app/package.json` + `package-lock.json`, and `e2e/package.json` (6 Nov 2026).

**PHP stack**

| Layer | Library | Constraint | Locked version | Use |
|---|---|---|---|---|
| Runtime | PHP | `>=8.1` | dev: 8.3 | Plain PHP, `declare(strict_types=1)` |
| Routing | own `App\Helpers\Router` | — | — | Hand-rolled front controller; no framework |
| Screen views | **plain PHP files** | — | — | `app/views/**.php` (not Twig) |
| Document templates | `twig/twig` | ^3.8 | 3.30.0 | `app/templates/<CODE>/*.html.twig` for PDFs |
| PDF | `dompdf/dompdf` | ^3.0 | 3.1.6 | HTML/CSS → PDF; pure PHP (chosen over Puppeteer/TCPDF for shared hosting). Note: **DOMPDF, not mPDF.** |
| Word | `phpoffice/phpword` | ^1.2 | 1.4.0 | DOCX builders in `Services/Docx/` (`DocxDocumentBuilder`) |
| E-mail | `phpmailer/phpmailer` | ^6.9 | 6.12.0 | SMTP transport |
| Markdown | `league/commonmark` | ^2.4 | 2.10.3 | SOP / help pages |
| HTML sanitising | `ezyang/htmlpurifier` | ^4.19 | 4.19.1 | Rich text (annexure terms etc.) |
| Tests | `phpunit/phpunit` | ^11.0 | 11.5.56 | `app/tests/Unit`, `app/tests/Integration` |
| `.env` | own `App\Config\Env` | — | — | No phpdotenv dependency |

The bootstrap (`app/bootstrap.php`) has its own PSR-4 autoloader for `App\`, so login/RBAC pages run even before `composer install`; document generation needs `vendor/`.

**Node stack**

| Layer | Library | Constraint | Locked | Use |
|---|---|---|---|---|
| Runtime | Node.js | — | dev: 22.x | `TZ=Asia/Kolkata node src/server.js` |
| Web | `express` | ^4.19.2 | 4.22.3 | Routes in `src/server.js` |
| Views + document templates | `nunjucks` | ^3.2.4 | 3.2.4 | `app/views/**.njk` and `app/templates/<CODE>/*.njk` |
| DB | `knex` + `mysql2` | ^3.1 / ^3.11 | 3.3.0 / 3.24.4 | Query builder and driver |
| Sessions | `express-session` + `connect-session-knex` | ^1.18 / ^5.0 | 1.19.0 | Stored in a `sessions` table that the store creates itself (not among the 194 schema tables) |
| PDF | `puppeteer-core` | ^25.11 | 25.16.0 | Headless Chromium at `PUPPETEER_EXECUTABLE_PATH` (`services/pdfRenderService.js`) |
| Word | `docx` | ^9.0.2 | 9.7.1 | `services/docx/` |
| E-mail | `nodemailer` | ^10.0 | 10.3.1 | SMTP |
| Scheduler | `node-cron` | ^4.6 | 4.6.0 | Optional in-process jobs |
| Uploads | `multer` | ^2.4 | 2.5.0 | Multipart files |
| Passwords | `bcrypt` | ^6.0 | 6.0.0 | Hashes compatible with PHP `password_hash` |
| Others | `qs`, `cookie-parser`, `csv-stringify`, `express-validator`, `jszip`, `marked`, `node-html-parser`, `sanitize-html` (2.17.0), `dotenv` | | | |
| Tests | `jest` + `supertest` | ^30.5 / ^7.3 | 30.5.2 / 7.3.0 | `app/tests/{unit,integration,manual}`; run one file at a time (`--runInBand`, shared test DB) |

**Shared**

| Item | Version / note |
|---|---|
| Database | MySQL 8 on the host; MariaDB in development. Rules: no `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS`, no `CREATE INDEX IF NOT EXISTS`; queries must pass `ONLY_FULL_GROUP_BY`; `utf8mb4` / `utf8mb4_unicode_ci`. |
| E2E | `@playwright/test` ^1.56.1 (locked 1.64.1) in `e2e/` |
| CI | `.github/workflows/tests.yml`: PHPUnit job and Jest job (installs `poppler-utils` to read generated PDFs/Word text) |
| Fonts | `app/assets/fonts` in each stack (embedded in PDFs) |

### 3.3 Folder layout

**Repository root**

```
order-system/
├── CLAUDE.md                     standing rules (parity, ask before push, review pack, PDF double confirmation …)
├── README.md                     overview of both stacks
├── ARCHITECTURE.html             visual architecture page
├── docs/
│   ├── SOP/                      user manual, chapters 00–32 + ca-01…ca-09 (master copy; copied into each stack)
│   ├── decisions/                owner-decisions-register.md (D-codes, rules R1…, open points)
│   ├── QA/                       MASTER_TEST_PLAN.md
│   ├── guides/                   GO_LIVE_CHECKLIST.md and other guides
│   ├── migrations/               README.md only at go-live (D-241); post-go-live migrations go here
│   ├── zoho/                     README.md, zoho-change-log.csv
│   ├── external/                 questions for the AD bank, CA, lawyer
│   ├── legal/                    lawyer notes (D-245)
│   ├── checks/, evidence/, workflow/
├── e2e/                          Playwright suite (tests/00-login … 14-qa-batch2, qa-*), seed-ui-test-users.sql
├── reference_templates/          the original Word/Excel templates the documents replicate
├── PHP_MySQL_Version/
└── NODE_MySQL_Version/
```

**PHP stack**

```
PHP_MySQL_Version/
├── public_html/                  WEB ROOT (the only URL-reachable folder)
│   ├── index.php                 front controller: ~744 routes, middleware per route, then dispatch
│   ├── _dev_router.php           for `php -S` locally
│   └── assets/{css,js,img,fonts} app.css, app.js (sitewide browser validation)
├── app/                          OUTSIDE the web root
│   ├── bootstrap.php             autoloader, .env, error log, timezone Asia/Kolkata, session cookie
│   ├── .env / .env.example
│   ├── src/
│   │   ├── Config/               Database.php (PDO), Env.php
│   │   ├── Controllers/          89 controllers
│   │   ├── Services/             business logic (Docx/ subfolder for Word builders)
│   │   ├── Repositories/         120 repositories (all SQL lives here)
│   │   ├── Middleware/           AssociateAuth, ClientAuth, CsrfCheck, OrderLockGuard, PermissionCheck,
│   │   │                         SessionAuth, SuperAdminOnly, TestModeGate
│   │   └── Helpers/              31 helpers: Router, Csrf, View, FieldValidator, PermittedLinks, OrderStorage,
│   │                             RateLimiter, Csv, AmountInWords, FinancialYear, DbResilience, …
│   ├── views/                    plain-PHP screen views, one folder per area (orders/, ca/, client_portal/, …)
│   ├── templates/                Twig document templates: QT/ PI/ OC/ CI/ PL/ BLI/ BLE/ BUYERPO/ SUPPO/ FDN/
│   │                             COOPREP/ ESI/ AMD/ ANNEXA/ CAFIN/ DOM/ IMP/ MTT/ + shared partials (_layout, _buyer_details …)
│   ├── cron/                     8 job scripts (see 3.9)
│   ├── tests/                    Unit/, Integration/, Support/, fixtures/, bootstrap.php; phpunit.xml
│   ├── assets/fonts/
│   ├── composer.json / composer.lock / vendor/
├── scripts/                      backup_db.sh, backup_storage.sh, backup_test_restore.sh, backup_lib.sh, tests/
├── storage/                      STORAGE_BASE_PATH in dev (assets, clients, associates, internal, logs, temp,
│                                 pdf_image_cache, font_cache, test)
└── docs/                         ARCHITECTURE.md, schema.sql, seed.sql, SOP/ (copy), QA/TEST_PLAN.md, guides/, gallery/
```

**Node stack**

```
NODE_MySQL_Version/
├── app/
│   ├── src/
│   │   ├── server.js             Express app: global middleware, ~744 routes, error handlers, optional scheduler
│   │   ├── config/               db.js (knex), env.js (.env loader)
│   │   ├── controllers/          89 controllers (camelCase file names)
│   │   ├── services/             business logic (docx/ subfolder, pdfRenderService.js)
│   │   ├── repositories/         120 repositories
│   │   ├── middleware/           associateAuth, clientAuth, csrfCheck, orderLockGuard, permissionCheck,
│   │   │                         saveSessionBeforeRedirect, sessionAuth, superAdminOnly, testModeGate
│   │   ├── helpers/
│   │   └── jobs/                 the same jobs as PHP cron + scheduler.js
│   ├── views/                    Nunjucks screen views (same folder names as PHP)
│   ├── templates/                Nunjucks document templates (same codes as PHP)
│   ├── public/{css,js,img,fonts} web assets; public/js/app.js
│   ├── tests/                    unit/, integration/, manual/, fixtures/, support/; jest.config.js
│   ├── assets/fonts/
│   ├── .env / .env.example, package.json, package-lock.json
├── scripts/                      the same backup scripts
├── storage/                      dev storage (also products/)
└── docs/                         same as PHP
```

### 3.4 Request flow

Every request follows the same layers on both stacks.

```
Browser
  │  HTTPS
  ▼
Front controller ── PHP: public_html/index.php → Router::dispatch()
                    Node: src/server.js (Express)
  │  1. Security headers on every response: X-Content-Type-Options nosniff, X-Frame-Options DENY,
  │     Referrer-Policy strict-origin-when-cross-origin, HSTS (not in local)
  │  2. Session start (PHP native session "nexacrest_session"; Node express-session in DB table `sessions`)
  │  3. Test Mode gates (global): blockClientFacingInTestMode, blockAdminWritesInTestMode
  ▼
Route match (method + path, {id} params) and its middleware list, in order:
  │  SessionAuth.required()  / ClientAuth / AssociateAuth   ← who is signed in
  │  PermissionCheck.requires('key') / requiresAny([...])   ← RBAC
  │  CsrfCheck.verify()                                       ← every POST
  │  OrderLockGuard.check()                                   ← on order write routes
  │  SuperAdminOnly                                          ← a few admin routes
  │  beforeHandler: missing order/document → 404 (after permission checks, so no existence leak)
  ▼
Controller  (reads input, calls services, picks a view, sets flash, redirects)
  ▼
Service     (business rules: stage gates, payments, documents, Zoho, mail, money visibility …)
  ▼
Repository  (SQL only; PDO prepared statements / knex)
  ▼
MySQL
  ▲
View        (PHP: View::render('orders/show', data, 'layout/base');
             Node: res.renderView('orders/show', data))  → HTML + app.js
```

Example route (PHP, `public_html/index.php`):

```php
$router->post('/suppliers/{id}/update', [$suppliersDirectory, 'update'],
    [SessionAuth::required(), PermissionCheck::requires('manage_suppliers'), CsrfCheck::verify()]);
```

**What `SessionAuth::required()` checks, in order** (`app/src/Middleware/SessionAuth.php`, Node `middleware/sessionAuth.js`):

| # | Check | Result if it fails |
|---|---|---|
| 1 | Signed in | Redirect `/login` |
| 2 | Idle timeout (`company_settings.session_timeout_staff_minutes`, default 240 = 4 h) and the 12-hour limit since sign-in (`session_absolute_staff_hours`, D-283) | Logout with "You were signed out after … without activity" / "… log in again at least every 12 hours", `/login` |
| 3 | User still active (AUTH-10) | Logout |
| 4 | `users.session_version` equals the value saved at login (L-26) | Logout (a password change ends every other session) |
| 5 | Forced password change / password expiry (`password_expiry_days`) | Redirect `/force-password-change` |
| 6 | Person in several departments has not chosen one (D-214) | Redirect `/choose-department` |
| 7 | **Preview as** active and request is a POST (ACC-2) | Refused, nothing saved |
| 8 | Own division not active and request is a POST (ACC-12) | 403 |
| 9 | **Order access** for any `/orders/{id}/…` path: archived, assignment, division (`OrderAccessService::denialReasonForPath`, R5-24) | 403 |
| 10 | **Module switch** off for this path in the current division(s) (D-257) | 403 "This part of the system is not in use here." |
| 11 | Client / associate outside the user's divisions | 403 |
| 12 | Report Division filter; order pages enter the **order's own division** (its numbering and settings) and re-check the module switch (ACC-11) | 403 if module off |

**Database errors at the chokepoint:** `Router::dispatch` turns MySQL SQLSTATE `22001` (too long), `22007` (bad date/number) and `23000` (duplicate / missing reference) into a friendly flash message instead of a 500. A briefly unreachable database answers **503** (`DbResilience`, R7-N04). Node has matching error handlers at the end of `server.js`.

**Links and buttons:** `Helpers/PermittedLinks` renders a link only where the viewer can open its target (QA UI-006/UI-007, 13 Nov 2026: also the money reports a person may not see, `/products`, a product category and Commission levels). `View::asset()` adds `?v=` to `app.js` / `app.css` so a deploy is picked up at once.

### 3.5 Templates: Standard and Kanban

| Item | Detail |
|---|---|
| Table | `ui_templates` (`standard` "Standard", `kanban` "Kanban Board"), each can be switched off by Admin |
| Per-user choice | `users.ui_template_preference_id`; changed in My Account |
| Default | `company_settings.default_ui_template` for new users and anyone who never chose |
| Resolution | `UiTemplateService::resolve()` (PHP) / Node equivalent: user choice if active → company default → `standard` |
| Views | e.g. `views/orders/index.php` (list) and `views/orders/index_kanban.php` (board); shared card `_card.php`, shared filters `_filters.php` |
| Parity rule (1 Nov, hard) | Every filter, search, count, field, badge, action, link and money rule on a Standard list is on its Kanban board and vice versa. Orders board shows 25 per column, then "Show more". Enforced by `tests/Integration/KanbanParityTest.php` / `kanbanParity.test.js`. Switching view keeps the filters (`initSwitchViewKeepsFilters` in `app.js`). |

### 3.6 `app.js` — sitewide browser behaviour and validation

One file per stack, loaded by every layout: `PHP_MySQL_Version/public_html/assets/js/app.js` and `NODE_MySQL_Version/app/public/js/app.js` (about 1,490 lines each, kept identical in behaviour). CLAUDE.md rule: **every form is checked in the browser before submit, message beside the field; server checks stay too** (`Helpers/FieldValidator`). Extend `fieldError()` for a new rule; do not write per-page scripts. **Lengths (QA UI-010, 13 Nov 2026):** a typed field saved to a VARCHAR column carries `maxlength` no larger than the column (e.g. Company legal name 255, consignee phone 30, BL number 60), so the browser stops a too-long value at the field; `QaUiBatchNov13Test` / `qaUiBatchNov13.test.js` check every view against `docs/schema.sql` for the field names that are one column of one length (name / e-mail / reason / notes fields, used for columns of different lengths, are not covered by that check).

| Function | What it does |
|---|---|
| `initSubmitValidation()` | Capturing `submit` listener on the whole document. Runs `fieldError()` on every enabled, named, non-hidden field; shows each message inline; shows an error pop-up listing the problems; opens a closed step card holding the first invalid field and focuses it. Skipped for `novalidate` forms and `formnovalidate` buttons. |
| `fieldError(el)` | D-348: a `required` drop-down still on its empty "Choose…" option with `data-required-message` shows that message (an order line's Thickness and Size / format). D-270: `data-piece-size="length|width|thickness"` on an order line's size fields — needed when the line's unit must be converted to the product's reference unit (PCS all three; RMT width and thickness; SQM / SQFT thickness unless the Thickness option gives it): "Enter the length — needed to compare a price per piece."; `data-allowed-goods-only` on a category option applies its unit list to goods lines only. The rule engine. HTML attributes (`required`, `type`, `min`/`max`, `minlength`, `pattern`) plus data attributes: `data-match` (and the conventions `<name>_confirm`, `confirm_password` → `new_password`), `data-required-if`, `data-required-when`, `data-required-unless` (file or reason), `data-not-more-than`, `data-allowed-from` (units allowed by a category), `data-refuse-when`, `data-min-exclusive="x"` (the number must be greater than x, with `data-min-exclusive-message`; `data-min-exclusive-unless="<checkbox>"` lifts it while that box is ticked — QA UI-011: New order and order-line Quantity must be more than 0 unless Qty To Be Confirmed), bank-detail rules (IFSC `^[A-Z]{4}0[A-Z0-9]{6}$`), numeric field names. |
| `markRequiredLabels()` / `initRequiredLabels()` | Adds one " *" to the label of each required field; removes it when the field stops being required. |
| `initReasonFieldValidation()` | Reason fields need meaningful text (10 characters, or the field's own `minlength`). By default a grey "Must be at least 10 characters." hint shows under the field from the start; a field with `data-reason-hint="on-error"` (compact inline boxes, e.g. Reject / Cancel Send on Email Send Approvals, D-269) shows no hint and only the red error ("Reason must be at least 10 characters (n so far).") on blur or submit. |
| `initShowWhen()`, `initGstCalc()`, `initAutoReasons()`, `initOptionFills()`, `initPatternFromSelect()` | Show a field only for one choice; GST % → amount and total; "Not Applicable" fills its reason; fills from a picked option. |
| `initRemittanceDifference()`, `initRemittanceInr()`, `initRemittanceStatements()` | Live remittance-difference reasons, bank charges and INR credited. |
| `initFormDraftRestore()` | Keeps typed input after a refused submit. |
| `markSubmitting()` / `keepSubmitterChoice()` | Every POST form: on submit the form's buttons are disabled and the pressed one reads "Working…". D-350 (12 Nov 2026): first the pressed button's `name`/`value` is copied into a hidden input (`data-submitter-choice`, from `event.submitter`, or the last clicked submit button in older browsers), so a form with two choice buttons (Approve / Refuse, Save / Restore) sends the right choice; a button outside its form (`form="…"`) is not disabled and sends its own value. |
| `initConfirmDialogs()` / `submitterOf()` | D-351 (12 Nov 2026): capturing `submit` listener that shows the `data-confirm` question with `window.confirm()` before a form is sent. The question is read from the pressed button first (`event.submitter`, or the last clicked submit button in older browsers) — a plain submit button, a `formaction` button or one outside its form via `form="…"` — and otherwise from the `<form>`; when both carry one, only the button's is asked. Cancel stops the submit before `markSubmitting()`, so nothing is sent and no button stays disabled; OK sends the pressed button's choice and `formaction`. Buttons using it: Associates › Access levels "Restore recommended defaults", order Step 1 "Refresh company details on this order", Incentives › Settings role "Delete". |
| `initConfirmDialogs()`, `initLockedSteps()`, `initDatePickers()`, `initSlowDownloadLinks()`, `initTableScrollShadows()` | UI helpers. |

Tests that enforce the conventions: `FormValidationConventionsTest` / `formValidationConventions.test.js` (confirm fields), `FormPlaceholderTest` / `formPlaceholder.test.js` (a placeholder on every typed input and textarea except date, file, checkbox, radio, hidden), `FormLabelConventionsTest` / `formLabelConventions.test.js` (labels, "*", "Choose…" on required drop-downs; on every drop-down the empty first option reads "Choose…" or "Choose a …" — never "Select…", "— Select —" or "— choose —", 11 Nov 2026).

### 3.7 The database

**194 tables** in each stack's `docs/schema.sql` (175 `CREATE TABLE` + 19 `CREATE TABLE IF NOT EXISTS`), filled with reference data by `docs/seed.sql`. A new install is `schema.sql` then `seed.sql` (D-241). Engine InnoDB, `BIGINT UNSIGNED` ids, foreign keys throughout. 65 foreign keys point at `orders(id)` and 132 at `users(id)`.

#### 3.7.1 Tables grouped by area

| Area | Tables |
|---|---|
| **Configuration** | `company_settings` (key-value; every configurable business value), `email_settings` + `compliance_alert_owners` (Settings › E-mail, D-280 / D-281), `dropdown_options` (many small lists), `ports`, `port_routes`, `incoterms`, `currencies`, `payment_presets`, `document_types`, `document_sections`, `tc_clauses`, `tc_clause_documents`, `email_templates`, `watermark_settings`, `docx_generation_settings`, `file_upload_contexts`, `company_holidays`, `hs_codes`, `hs_code_product_examples`, `reference_sequences`, `ui_templates`, `test_mode_settings`, `remittance_difference_reasons`, `stages_master` |
| **Auth / RBAC / security** | `users`, `roles`, `permissions`, `role_permissions`, `user_permissions`, `password_reset_tokens`, `login_attempts`, `rate_limit_hits`, `two_fa_backup_codes`, `super_admin_delegations`, `field_protection_requests`, `self_approval_rules` |
| **Departments & divisions** | `departments`, `department_designations`, `user_department_memberships`, `designations`, `divisions`, `division_modules`, `division_settings`, `client_divisions` |
| **Signatures & assets** | `assets` (logos, seals, watermarks), `user_signature_assets`, `company_default_signatory`, `document_type_signatories` |
| **Clients & intake** | `clients`, `client_logins`, `client_password_reset_tokens`, `client_intake_submissions`, `pi_intake_submissions`, `client_payment_reports`, `order_reorder_requests`, `order_reorder_request_products` |
| **Products & pricing** | `products` (legacy product master), `product_images`, `catalog_products`, `catalog_product_images`, `catalog_product_suppliers`, `catalog_product_supplier_options`, `catalog_product_misc_charges`, `product_categories`, `product_category_attributes`, `spec_definitions`, `spec_options`, `product_spec_hidden`, `product_option_overrides`, `pricing_settings`, `price_requests` |
| **Suppliers & logistics** | `suppliers`, `supplier_type_links`, `supplier_factory_addresses`, `logistics_partners` |
| **Orders (Export) — core** | `orders`, `order_assignees`, `order_stages`, `order_products`, `order_department_owners`, `order_comments`, `order_comment_attachments`, `order_feedback`, `order_cancellations`, `order_annexure_products`, `order_annexure_images`, `order_annexure_terms`, `order_quality_documents` |
| **Orders — stage data** | `order_production`, `order_supplier_po`, `order_supplier_po_documents`, `order_buyer_po_documents`, `order_oc_acknowledgments`, `order_freight`, `order_packing`, `order_crates`, `order_shipping`, `order_draft_bl_reviews`, `order_bl_endorsements`, `order_inspections`, `order_inspection_fees`, `order_shipment_gate_overrides`, `order_compliance_tasks`, `compliance_task_types` |
| **Orders — money** | `order_payment_status`, `order_payment_receipts`, `order_payment_shortfalls`, `order_payment_milestones`, `order_lc_details`, `order_lc_amendments`, `order_cost_entries`, `order_vendor_bills`, `order_vendor_bill_payments`, `order_expenses`, `order_travel_expenses`, `order_export_benefit_schemes`, `order_zoho_invoices` |
| **Documents & files** | `documents`, `document_revisions`, `document_reviews`, `document_cross_verifications`, `amendments`, `file_store` |
| **Disputes & complaints** | `disputes`, `dispute_documents`, `dispute_replies`, `order_complaints`, `order_complaint_replies` |
| **E-mail & notifications** | `email_log` (send queue + history), `notifications`, `daily_digest_log` |
| **Audit & reports** | `audit_log`, `report_definitions` |
| **CA / accounting** | `ca_expenses`, `ca_bank_statement_lines`, `ca_fy_locks`, `ca_export_benefits`, `gst_refund_claims`, `gst_refund_credits`, `compliance_alerts_sent` |
| **Zoho** | `zoho_sync_log`, `zoho_api_usage`, `zoho_token_cache`, `zoho_pending_inactivations` |
| **Procurement** | `purchase_requirements`, `purchase_quotes` |
| **Coordination** | `department_task_types` |
| **Agreements** | `agreements`, `agreement_alerts_sent` |
| **Associates** | `associates`, `associate_tokens`, `associate_bank_accounts`, `associate_documents`, `associate_invitations`, `associate_events`, `associate_messages`, `associate_codes`, `associate_registration_links`, `associate_registrations`, `associate_price_access`, `associate_access_level_settings`, `associate_access_features`, `associate_access_log`, `commission_levels`, `commission_ladder_steps`, `associate_ladder_steps` (D-291), `vendor_bank_changes` (D-294) |
| **Team incentive** | `incentive_settings`, `incentive_roles`, `user_incentive_roles`, `order_incentives`, `order_incentive_people`, `order_incentive_roles`, `order_incentive_history`, `incentive_payouts`, `incentive_kept` |
| **Reference library** | `internal_reference_docs`, `reference_library_documents`, `reference_library_categories` |
| **Domestic Trade (DOM)** | `indian_states`, `gst_rates`, `domestic_orders`, `domestic_order_lines`, `domestic_payments`, `domestic_documents`, `domestic_counters` |
| **Import & Distribution (IMP)** | `import_duty_rates`, `import_shipments`, `import_shipment_lines`, `import_payments`, `stock_balances`, `stock_movements` |
| **Merchant Trade (MTT)** | `merchant_deals`, `merchant_deal_lines`, `merchant_payments`, `merchant_deal_documents`, `merchant_alerts_sent` |

The schema file itself is organised in lettered sections in the order they were added (A Configuration, B Auth/RBAC, C Assets & files, D Stages, E Orders, F Documents, G Files, H E-mail, I Disputes, J Audit, … BQ UI templates, CE Vendor bills, CN Team incentives and associates, then D-257 divisions). The comment above each table explains *why* it exists — read it before changing a table.

#### 3.7.2 Key relationships

```
divisions ──< orders >── clients ──< client_divisions >── divisions
                │  └── client_logins, client_intake_submissions, associates (clients.associate_id)
                │
                ├──< order_products >── products / catalog_products (catalog_product_id) ── product_categories
                ├──< order_stages >── stages_master          (current_stage_id → stages_master)
                ├──  order_payment_status, order_payment_receipts, order_payment_shortfalls, order_payment_milestones
                ├──  order_production, order_supplier_po >── suppliers
                ├──  order_freight, order_packing ──< order_crates, order_shipping, order_lc_details
                ├──< order_vendor_bills ──< order_vendor_bill_payments   (suppliers / logistics_partners)
                ├──< order_cost_entries, order_expenses, order_travel_expenses
                ├──< documents ──< document_revisions, document_reviews, document_cross_verifications
                │        └── document_types; pdf_file_id / docx_file_id → file_store
                ├──< file_store (also client_id, stage_id, linked_document_id → documents)
                ├──< amendments (orders.active_amendment_id → amendments)
                ├──< disputes, order_complaints, order_comments, email_log, audit_log (by table+id)
                ├──< order_assignees >── users;  order_department_owners >── departments
                ├──  order_incentives ──< order_incentive_people / roles; incentive_payouts
                └──  order_zoho_invoices, order_export_benefit_schemes, order_compliance_tasks

users >── roles ──< role_permissions >── permissions;  users ──< user_permissions (per-user overrides)
users ──< user_department_memberships >── department_designations >── departments (>── divisions)
users.division_id → divisions (NULL = company level)
```

Important design choices:

| Choice | Why |
|---|---|
| **Order snapshot columns** (preset terms, incoterm, currency, ports, buyer/consignee/notify party copied onto `orders` at creation; the `*_id` columns are for traceability only) | Editing a preset, port or client must never rewrite what an old order shows or prints. A PI-intake correction updates that order's own snapshot only. A duplicated order re-reads today's values. |
| **Frozen at the first PI approval** (D-262, §5.5) | After the PI, company details, T&C clauses, categories, tolerance, MD-approval flag and pricing FX rate come from `orders.frozen_snapshot_json`; `OrderSnapshotService::within()` overlays them for every document run. The MD can refresh company details deliberately (`POST /orders/{id}/company-details/refresh`). |
| **Normalised stage tables, not JSON** | Reports need to query overdue payments, orders by stage, etc. |
| **`company_settings` as key-value** | A new setting is a row, not a migration. No business value is hard-coded in templates. |
| **Soft delete** | `file_store.is_active`, `clients.is_active`; contact records never hard-deleted. |
| **Protected rows** | DB triggers (see 3.13). |

### 3.8 Documents: generation, versions and snapshots

| Step | PHP | Node |
|---|---|---|
| Gather data | `Services/DocumentDataAssembler.php` (all values from DB; nothing literal in templates) | `services/documentDataAssembler.js` |
| Category profile | `CategoryDocumentProfile` (packing words, quality documents, clause set) | `categoryDocumentProfile.js` |
| Render HTML | Twig `templates/<CODE>/…html.twig` | Nunjucks `templates/<CODE>/…njk` |
| PDF | DOMPDF | `pdfRenderService.js` (Puppeteer / Chromium) |
| Word | `Services/Docx/DocxDocumentBuilder` (PHPWord) | `services/docx/` (`docx`) |
| Orchestration | `DocumentGenerationService` (stage gates, review, approval, `finalizeApproval`, watermark DRAFT→FINAL) | `documentGenerationService.js` |

Versions: `documents` holds one row per document with `revision_number` and `status` (`draft`, `in_review`, `approved`, `sent`, `superseded`). Each revision is a row in `document_revisions` with `data_snapshot` (the field values used — immutable history), its own PDF/DOCX file ids and `is_current`. Since D-258 (DOC-2) `documents.render_context_json` keeps each revision's full render data so an approval re-render prints exactly what was reviewed. **Rule: no change that alters a generated document without showing the owner before/after and getting confirmation twice (CLAUDE.md, R6). All current PDFs are correct as they are.**

### 3.9 Cron jobs and the scheduler

There is no permanent worker on shared hosting, so background work runs as scheduled scripts. Run them in IST.

| Job | PHP script (`app/cron/`) | Node (`src/jobs/`) | Schedule (IST) | What it does |
|---|---|---|---|---|
| Deferred e-mail dispatch | `dispatch_deferred_emails.php` | `dispatchDeferredEmails.js` | every 10 min | Sends approved / scheduled e-mails from `email_log` |
| OC auto-confirm | `auto_confirm_oc_acknowledgments.php` | `autoConfirmOcAcknowledgments.js` | every 15 min | Confirms an OC the buyer did not acknowledge within 48 h |
| Alerts | `check_alerts.php` | `checkAlerts.js` | daily 06:00 | LUT/RCMC expiry, freight/balance overdue, dispute response overdue, agreements (30/7/0 days), compliance (ECGC, eBRC, claim deadlines), merchant-trade alerts |
| Zoho sync | `zoho_sync.php` | `zohoSync.js` | hourly | Pushes pending records to Zoho Books; one run at a time (`ZohoSyncLock`) |
| Daily digest | `daily_digest.php` | `dailyDigest.js` | daily 08:15 | Per-person e-mail of waiting work, associate reminders, backup warnings |
| Document copy clean-up | `document_copy_cleanup.php` | `documentCopyCleanup.js` | daily 02:15 | Removes old draft/superseded copies per Admin rules; does nothing while every rule is off (default) |
| Exchange rate (D-301) | `fetch_fx_rate.php` | `fetchFxRate.js` | daily 07:05 | Fetches USD→INR (Frankfurter, backup open.er-api.com) into `fx_rates`; failure keeps the last rate; a move over 2 % waits for the MD. Needs HTTPS to api.frankfurter.app and open.er-api.com |
| Zoho TEST clean-up | `zoho_cleanup_test_records.php` | `zohoCleanupTestRecords.js` | **by hand** | Removes `TEST-` records from Books (dry run unless `--apply`; `--log` writes change-log rows) |
| Reorganise order files | `reorganize_order_files.php` | `reorganizeOrderFiles.js` | **by hand** | Moves files into the per-step folders (dry run unless `--apply`) |
| Backups | `scripts/backup_db.sh`, `backup_storage.sh`, `backup_test_restore.sh` | same | 02:00, 02:30, 04:00 on the 1st | See 3.15 |

PHP crontab (README):

```
CRON_TZ=Asia/Kolkata
*/10 * * * *  php …/app/cron/dispatch_deferred_emails.php
*/15 * * * *  php …/app/cron/auto_confirm_oc_acknowledgments.php
0 6 * * *     php …/app/cron/check_alerts.php
0 * * * *     php …/app/cron/zoho_sync.php
15 8 * * *    php …/app/cron/daily_digest.php
15 2 * * *    php …/app/cron/document_copy_cleanup.php
5 7 * * *     php …/app/cron/fetch_fx_rate.php
```

Node offers two options (never both, or jobs double-fire): external cron calling `node src/jobs/<name>.js` (recommended), or `RUN_JOBS_IN_PROCESS=true` in `.env` so `src/jobs/scheduler.js` runs them with `node-cron` inside the web process (Zoho sync with `noOverlap`).

### 3.10 E-mail sending

All business e-mail goes through **one chokepoint**: `MailSenderService::send()` (PHP `app/src/Services/MailSenderService.php`, Node `services/mailSenderService.js`; D-258 MAIL-2 moved every remaining sender onto it). The transport is SMTP (PHPMailer / Nodemailer) using `SMTP_*` from `.env` (Bluehost mail). **Zoho Mail is not used to send** (`ZohoMailService::IN_USE = false`).

Order of checks inside `send()`:

| # | Check | Effect | Applies to security e-mails (2FA codes, password reset)? |
|---|---|---|---|
| 1 | **Settings › E-mail (D-280)** — the e-mail's kind and audience (`EmailPolicy::holdReason`, Node `emailPolicy.js`): a MUST e-mail always goes; a buyer or supplier e-mail is held while **Testing mode** (`company_settings.email_testing_mode = 1`) is on; any other e-mail is held when its switch (`email_settings`) is off | Nothing is sent; the full message is written to the error log so staff can send it by hand; `MailSenderService::$lastOutcome` = `held`. With buyer document e-mails held, approved documents count as sent (`markSentIfMailDisabled`). A send through the e-mail log (document or follow-up, Send now or with the queue off) that is held is recorded as held — status `cancelled` by the system (no `cancelled_by`), reason "Held, not sent: <reason>. Send it outside the app, or submit it again once that changes." — never as `failed`; the screen says "Not e-mailed: <reason> …" and the requester and approver get the same reason in-app. It is never sent later by itself (QA MTX-003). | MUST kinds are never held |
| 2 | **Test Mode** (`TestModeService::resolveEmailRecipient`) | Redirects to the Test Mode address; if none is set, the mail is **not** sent at all | No |
| 3 | **Mail redirect** (`MailRedirectService`: `mail_redirect_enabled`, `mail_redirect_address`) | Rehearse real mail without Test Mode: all business mail goes to one address | No |
| 4 | **CC list** (`mail_cc_emails`, `mail_default_cc_email`) | Added to every business mail | No |
| 5 | Transport | SMTP; with no SMTP configured in dev, logged only | — |

**Deferred send (two levels)** — `EmailDispatchService`:

1. Level 1: a staff member requests a send (`requestSend`) with a template, the document and optionally a scheduled time. A row is written to `email_log` with status `pending_approval`. Only one active send per document (or per generic template per order).
2. Level 2: a holder of `approve_email_send` approves (`approveSend`), rejects or cancels. Maker-checker applies unless a Self-approval rule allows it.
3. The cron job `dispatch_deferred_emails` sends approved rows when due (`dispatch`). "Send now" override exists.
4. If `mail_approval_queue_enabled = 0`, the request is auto-approved and sent at once (audit-logged `EMAIL_SEND_AUTO_APPROVED`).

Payment-request e-mails include the Payment Action Link paragraph. Sending the OC starts the 48-hour acknowledgment clock.

**Settings › E-mail (D-280, 11 Nov 2026; `/admin/email-settings`, permission `manage_company_settings`).** One row per e-mail kind, one switch per audience (Staff, Associate, Buyer, Supplier); stored in `email_settings` (kind, audience, is_enabled). Every save needs a reason and is audit-logged (`EMAIL_SETTING_CHANGED`, `EMAIL_TESTING_MODE_CHANGED`). The kinds are listed once in `EmailPolicy::KINDS` / `emailPolicy.KINDS`:

| Group | Kind (tier per audience) |
|---|---|
| A. Log-in and account access — **MUST** (locked on) | `login_code` (staff, associate, buyer), `set_password_invite` (associate, buyer; access-critical), `forgot_password` (staff, associate, buyer — the associate one is new, D-282), `bank_details_changed` (associate), `bank_confirm_code` (associate: the 6-digit code to confirm new bank details in the portal, D-293), `vendor_bank_confirm` (supplier / logistics partner: the link to confirm new bank details or "This is not us — cancel it", D-294 / D-303), `backup_code_alert` (staff), `quote_verification_code` (buyer) |
| B. Orders and documents — SHOULD | `buyer_documents` (buyer; the **associate copy** is the only OPTIONAL, off), `buyer_links` (correction, PI form, Buyer Action, Payment Action), `buyer_messages` (order messages, complaint replies), `buyer_followups` (payment follow-up, shipment readiness, BL copy, balance received), `supplier_links` (Supplier Action link, Vendor Details link) |
| C. Associate programme — SHOULD | `associate_clients`, `associate_commission`, `associate_kyc_price_message`, `associate_registration_declined`, `associate_code_status` (event → kind in `ASSOCIATE_EVENT_KINDS`) |
| D. Staff — SHOULD | `staff_approvals` (one e-mail per approval, incl. line price approvals and price requests), `staff_counterparty`, `staff_compliance`, `staff_zoho`, `staff_daily_summary`, `staff_assigned`, `staff_other` (notification type → kind in `NOTIFICATION_KINDS`) |

The two bank-details confirmations are listed since D-332 (12 Nov 2026); they were always sent (security path of the mail send, never held), and their senders (`AssociateBankConfirmation::sendCode`, `VendorBankChangeService::sendLink`, Node the same) pass the same kind and audience.

**Testing mode** (`email_testing_mode`, replaced `mail_sending_enabled`; **on** in a fresh install since D-347 — switched off on go-live day) holds only buyer and supplier e-mails that are not MUST. Staff and associate e-mails and every MUST e-mail still go out. It is set on the E-mail settings page only (hidden from Company Settings). Each staff member's own "e-mail me my notifications" switch still applies to non-MUST e-mails.

**Never a false "e-mailed".** A screen or audit line says "e-mailed" only when the send returned true; otherwise it gives the reason ("testing mode holds e-mails to buyers and suppliers", "this e-mail is switched off in Admin › E-mail settings", "no client e-mail on file", "the e-mail could not be sent"). Links (set-password invites always; PI form, Buyer Action, Payment Action, Supplier Action, Vendor Details links) are shown under the message with a **Copy** button (`Flash::set($type, $message, $copyLink)`, partial `views/partials/copy_link.*`, app.js `[data-copy]`). Order Communication posts store the reason (`order_comments.email_status`: sent / testing_mode / switched_off / no_email / failed; `sending_off` on posts from before D-280).

**Who on the staff side is e-mailed (D-281, `StaffRouting` / `staffRouting.js`).** Each order event type has a department (`DEPARTMENT_FOR_TYPE`: buyer → Sales & Export; supplier → Procurement; payments, FDN, balance, LC dates → Accounts & Finance; inspection → Logistics; ECGC declarations, benefit claims → Export Compliance). The e-mail goes to that department's **owner on the order and the order's assignees who are members of it**. With nobody from the department on the order: the department's manager (director if no manager), with "Nobody from <department> is assigned to this order — please assign someone."; with no manager: the Managing Director. The MD, ED and Order Coordinator are not e-mailed for these routine events; the in-app notices go to the same people as before (the order's assignees, or everyone with `manage_orders`). Associate events go to the associate's relationship manager (none: the associate team; bank changes also to the `manage_associate_bank` verifiers). **Company-level compliance alerts** (LUT, RCMC, agreements, TDS, merchant-trade RBI limits, ECGC policy, monthly eBRC) are e-mailed to the **responsible person and backup** set in section E of the E-mail settings page (`compliance_alert_owners`); not set: the department's manager, then the MD.

**Login-code wording per audience (D-280).** Staff get template `two_factor_code`; buyers get `client_login_code` (no "contact your administrator"); associates their own fixed wording.

### 3.11 File storage

| Item | Rule |
|---|---|
| Root | `STORAGE_BASE_PATH` in `.env`, **outside the web root**. Every path is `STORAGE_BASE_PATH/<parts from DB>`. |
| Top folders | `assets/` (logos, signatures, seals, watermarks, e-mail headers), `clients/`, `associates/`, `products/` (Node), `internal/`, `temp/<session>/`, `logs/` (`error.log`), `pdf_image_cache/`, `font_cache/` (PHP), `test/` |
| Order folder | `clients/<client no>/<order ref>/NN-step/` — `01-quotation/` (+ `annexure-images/`), `04-buyer-po/`, `05-proforma-invoice/` (+ `lc/`), `06-order-confirmation/`, `07-supplier-po/`, `09-freight/`, `10-packing-and-bl/`, `11-commercial-invoice/`, `12-financials/` (CAFIN), `13-closure/`, `payments/`, `amendments/<ref>/`, `disputes/<id>/`, `order-updates/`, `additional-documents/`, `checklists/`, `reference/`, `other/`. Received files go in a `received/` subfolder. Defined in `Helpers/OrderStorage::AREAS`. A generated document goes by its **document type**, not the stage the order was at. |
| File names | Random 32-hex "UUID" names (`bin2hex(random_bytes(16))` in `FileUploadService`) — never guessable. The original name is kept in the DB. |
| Index table | `file_store`: `client_id`, `order_id`, `stage_id`, `file_origin` (`GENERATED_AUTO` / `GENERATED_MANUAL` / `RECEIVED`), `received_from`, `document_type_label`, `linked_document_id` (every generated file points to its `documents` row — used to classify money and CAFIN files), `sent_to_client`, `internal_only`, `server_path`, `uuid_filename`, `original_filename`, size, MIME, `is_active` (soft delete), `removed_at` / `removed_reason` (clean-up removed the bytes). |
| Downloads | Through controllers only (`/file-store/{id}/download`, `/documents/{id}/download`, `Helpers/StoredFileDownload`) with permission, money-visibility and CAFIN checks; `X-Content-Type-Options: nosniff` stops an uploaded HTML file running. |
| Upload contexts | `file_upload_contexts` decides allowed types and sizes per context (`vendor_bill`, `expense_receipt`, `associate_agreement`, `agreement_file` …). |
| Versions | Each document revision has its own PDF/DOCX `file_store` rows; superseded copies are kept until the clean-up rules (off by default) remove them; the PDF the buyer received is marked (`markSentToClient`). |

**CAFIN never reaches the client** (CLAUDE.md, hard rule): `document_types.never_shown_to_buyer = 1` and category `internal`; the client portal, Buyer/Payment Action links and e-mail dispatch exclude never-shown-to-buyer types; every CAFIN download needs `view_cafin`; `FileStoreRepository` resolves drafts, superseded and older CAFIN copies to their type so a generic file route cannot leak one; general data exports leave CAFIN out.

### 3.12 Zoho integration overview

| Part | Code (PHP; Node has the camelCase twin) | Notes |
|---|---|---|
| Hard switches | `.env` `ZOHO_INTEGRATION_ENABLED`, `ZOHO_ENVIRONMENT` (`test`/`production`), `ZOHO_EINVOICE_ENABLED`; `ZohoGuard::assertRequestAllowed()` on every request | Missing/unknown value = off / test / off. No screen can change them. In `test`, only customers named `TEST-…` may be sent; in `production`, test and sample orders are never sent. |
| Soft switches | Settings › Zoho: credentials (shown masked, `SecretSetting`), `zoho_books_enabled`, go-live date `zoho_books_sync_start_date`, `zoho_sync_clients`, `zoho_sync_products` (off) | Production sends nothing until the go-live date is set. |
| API client | `ZohoBooksService` (OAuth self-client, `zoho.in` data centre by default); `setTransport()` lets tests use a fake Zoho | Looks a record up by its reference before posting, so a retry never duplicates (ZOH-2). |
| Limits | `ZohoApiBudget`: `zoho_api_daily_limit` 800, `zoho_api_minute_limit` 30; one shared encrypted token for 50 min (`zoho_token_cache`); "too many requests" stops the run (D-239). After a 401 the new token is used for every later request of the run (QA ZOH-003). A refresh token Zoho refuses (`invalid_code`, revoked) stops the run at the first refusal with "Zoho sign-in failed … Reconnect" (`ZohoSignInFailed`) and is not tried again for 10 minutes (QA ZOH-004) | Usage shown on the Zoho Books Sync page. |
| Running | `CaSyncService::fullSync` (hourly cron), *Sync now*, *Sync this order* (`POST /orders/{id}/zoho-sync`); `ZohoSyncLock` one run at a time; `DivisionZohoSync` for DOM/IMP/MTT | |
| What is waiting | `ZohoPendingService` (order page, menu, Sync page, D-233) | |
| Failures | `ZohoSyncFailureNotifier` e-mails holders of `ca_module_manage` once per failing run; every call in `zoho_sync_log` | |
| Checking | `OrderZohoMatchService`, `GET /orders/{id}/zoho-check` — read-only: match / not sent / before go-live / different / missing in Books / only in Books | An associate's commission is its Books bill (AS-12): the check compares the bill's amount before tax, and the daily restore puts back only a deleted bill (QA ZOH-002). |
| Master data | `ZohoMasterDataSync`: app → Zoho customers and items, linked by exact name, made inactive (never deleted) | D-238 |
| Descriptions | `PaymentDescription` builds the same description for app and Books (D-212) | |
| Records in Zoho | Contacts, invoices (CI, Debit Note, ESI), customer payments, bills and vendor payments, expenses, journals (GST refunds, export benefits, IGST credit, associate payout costs) | Ids stored on the app rows (`zoho_*` columns). |
| Human changes | Every change in Zoho by anyone → a row in `docs/zoho/zoho-change-log.csv` | TEST records removed before go-live, removal logged. |

### 3.13 Security

| Control | Where | Detail |
|---|---|---|
| **Passwords** | `AuthService`, `bcrypt` (Node) / `password_hash` (PHP) | Password checked *before* revealing account state (AUTH-04/05); dummy hash for unknown e-mails. Expiry (`password_expiry_days`) and forced change on first login / reset. |
| **Lockout** | `login_attempts`, `failed_login_lockout_count` (5), `lockout_duration_minutes` (15) | Failed attempt recorded before counting (L-03). |
| **Login code (D-265, 7 Nov 2026)** | `TwoFactorService`, `BackupCodeService`, `RememberBrowserService` (Node `twoFactorService.js`, `backupCodeService.js`, `rememberBrowserService.js`); tables `two_fa_backup_codes`, `login_remembered_browsers`; setting `login_remember_browser_days` | **Every staff member (every role) and every buyer** enters a 6-digit code e-mailed after the password, at every login. Mandatory: it cannot be switched off per person (`users.two_fa_enabled` / `two_fa_method` are no longer read; the Users list shows "Required"). Valid 10 minutes, 5 tries (then sign in again), "Send me a new code" at most once a minute; always e-mail (SMS stays off); sent as security mail, so it goes out even when business e-mail is switched off and is never redirected. Each portal has its own pending slot (`_2fa_pending` staff, `_client_2fa_pending` buyer). Staff: `/2fa`, `/2fa/resend`, `/2fa/backup`; buyers: `/client/login/code`, `/client/login/code/resend`. Staff "Log in as this client" is unaffected. **Emergency backup codes:** an effective Super Admin or anyone holding `manage_company_settings` (MD, ED, Admin) can make 10 one-time codes on My Account (current password needed; shown once; stored hashed; a new set replaces the old) and use one on the code screen ("Use a backup code instead", shown only to them; counts against the same 5 tries). Each use is audited (`LOGIN_BACKUP_CODE_USED`) and every `manage_company_settings` holder gets an in-app notice and e-mail. **"This is my own office computer" (D-283, was "Remember this browser"):** Settings › Security › `login_remember_browser_days` (default 30, 0–90; 0 = off). When above 0, staff (never buyers or associates) may tick "This is my own office computer" on the code screen: an HttpOnly, SameSite=Lax, Secure-except-local cookie `nexacrest_trusted_browser` (`selector.validator.HMAC` signed with `APP_ENCRYPTION_KEY`) skips the code on that browser for that person only; the server keeps a SHA-256 of the validator and the person's `session_version`. A password change or reset deletes the rows and bumps `session_version`; setting the days to 0 ends every remembered browser; "Forget all remembered computers" on My Account (own) and in Users › Edit (Admin, `manage_users`) deletes them too (`LOGIN_BROWSERS_FORGOTTEN`). In `APP_ENV=local` the code is shown on screen. Associates keep their own e-mailed code (unchanged). |
| **Sessions (D-283, 11 Nov 2026)** | `SessionTimeouts` (Node `sessionTimeouts.js`), `bootstrap.php` / `server.js`, the three auth middlewares, app.js `initIdleWarning` | Cookie `nexacrest_session`, HttpOnly, Secure (not in local), SameSite=Lax. Idle timeouts per area from Settings › Security: staff `session_timeout_staff_minutes` 240, buyers `session_timeout_client_minutes` 120, associates `session_timeout_associate_minutes` 120 (5–1440); staff also signed out `session_absolute_staff_hours` (12, 1–24) after sign-in however active. PHP: `session.gc_maxlifetime` and the cookie lifetime follow the longest timeout (+5 min), session files live in `storage/sessions` (no host clean-up at ~24 min), the cookie is re-sent on every request. Node: `cookie.maxAge` = longest timeout, `rolling: true`, so the MySQL session store keeps the session as long. Every signed-in page carries `<meta name="session-idle">`; 2 minutes before the end app.js shows "You will be signed out soon" with **Stay logged in** (POST `/keep-alive`, `/client/keep-alive`, `/associate/keep-alive`); the 12-hour limit can only be warned about. `session_regenerate_id` at login; the idle clock starts at login. |
| **Session versioning** | `users.session_version` | Saved at login; compared on every request; a password change bumps it so every other session (a stolen one too) is logged out (L-26). Deactivation also logs out at once (AUTH-10). |
| **CSRF** | `Helpers/Csrf` + `Middleware/CsrfCheck` (Node `helpers/csrf.js`, `middleware/csrfCheck.js`) | One random 64-hex token per session, hidden field `_csrf`, `hash_equals` check on every POST route. A failed check redirects to the right login page; an over-size upload gives a clear 413 instead (UP-03). |
| **RBAC** | `permissions`, `role_permissions`, `user_permissions`; `PermissionCheck` per route; `PermissionService::can()` in views | Plus `OrderAccessService` (one guard on every `/orders/{id}/*` route), `MoneyVisibility`, `GrantGuard`, `MakerCheckerGuard` / `SelfApprovalRules`. Route coverage is tested (`RouteMatrixTest`, `UngatedRoutesTest`). |
| **Rate limits** | `Helpers/RateLimiter` + `rate_limit_hits` (IP + bucket sliding window) | Public quotation-details form (INT-05), quotation verify (10 per 30 min per submission), associate registration (5 per 15 min per IP). Login is protected by lockout instead. |
| **Headers** | `Router::SECURITY_HEADERS`, Node first middleware | nosniff, X-Frame-Options DENY, Referrer-Policy, HSTS outside local. |
| **Protected founder accounts trigger** | `trg_users_protected_bd` / `trg_users_protected_bu` on `users` | For `is_protected_account = 1`: no delete; no change of name, e-mail, phone, role, designation, signatory eligibility, super-admin flag, active flag or the protection flag. The app refuses first with a message; the trigger is the backstop. Password fields are *not* covered (self-service reset works). Real names/e-mails are set once at go-live by SQL that lifts the trigger briefly and puts it back (GO_LIVE_CHECKLIST). |
| **Super Admin trigger** | `trg_users_super_admin_bd` / `_bu` | A Super Admin can never be deleted or deactivated. |
| **Protected settings triggers** | `trg_company_settings_*`, `trg_tc_clauses_*`, `trg_payment_presets_*` | Protected rows cannot be deleted or blanked; lock/unlock needs a second person (`field_protection_requests`). |
| **Tokens at rest** | `APP_ENCRYPTION_KEY` (`EncryptionService`); hashed token columns | Buyer/Payment Action link tokens encrypted or hashed; changing the key invalidates existing links. |
| **Uploads** | `FileUploadService`, upload contexts | Random names, outside web root, nosniff on download. |
| **Audit** | `audit_log` (`AuditLogRepository::log`) | Every significant action, with the active department; amounts masked for people who may not see them (`AuditMoneyMask`). |
| **Test Mode / Preview** | `TestModeGate`, `SessionAuth` | Admin writes frozen in Test Mode; Preview-as is read-only. |
| **Errors** | `bootstrap.php`, Node error handlers | Errors to `storage/logs/error.log`; shown on screen only in `APP_ENV=local`. |

### 3.14 Environments and `.env`

`.env` lives in `app/` (outside the web root) and is never committed. Copy `app/.env.example`. Every key, with its default and meaning, is listed once in §9.2. The ones that change behaviour most: `APP_ENV` (`local` shows errors and 2FA codes on screen and turns off the Secure cookie and HSTS: never on an internet-facing server), `DEV_SERVER` (red "DEV / TEST ENVIRONMENT" strip), `APP_ENCRYPTION_KEY` (encrypts action-link tokens; do not rotate casually) and the three Zoho hard switches (§3.12).

Typical environments:

| Environment | Settings |
|---|---|
| Developer machine | `APP_ENV=local`, MariaDB, `php -S` with `_dev_router.php` on 8080 / `npm start` on 3000, Zoho off |
| Staging / UAT | `APP_ENV=production`, `DEV_SERVER=true`, `ZOHO_ENVIRONMENT=test` (TEST- customers only), often Mail redirect or Test Mode on |
| Live (PHP on Bluehost) | `APP_ENV=production`, `DEV_SERVER=false`, MySQL 8, cron in `CRON_TZ=Asia/Kolkata`, `ZOHO_ENVIRONMENT=production` once go-live is approved, `ZOHO_EINVOICE_ENABLED=false` until e-invoicing is required |

Timezone is fixed to **Asia/Kolkata** on both stacks (`date_default_timezone_set` in PHP; `TZ=Asia/Kolkata` in the Node start script).

### 3.15 Backups overview

Owner decision **D-260** (6 Nov 2026): keep everything **10 years**; a backup is only an extra copy and never touches live data.

| Script (same in both stacks' `scripts/`) | What it does | Cron (IST) |
|---|---|---|
| `backup_db.sh` | `mysqldump --single-transaction` (no locks), gzip, encrypt, `.sha256` beside it → `BACKUP_DIR/db/nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc` | 02:00 daily |
| `backup_storage.sh` | Daily snapshot of every document → `BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/`; unchanged files stored once (hard links, `rsync --link-dest`); first run of each month also writes a full encrypted archive | 02:30 daily |
| `backup_test_restore.sh` | Checks checksum, decrypts, loads the newest copy into a **separate scratch database**, counts tables, drops it; checks the newest archive opens | 04:00 on the 1st |
| `backup_lib.sh` | Shared functions | — |

Retention: database copies older than 120 days thinned to one per month; documents never need thinning. `BACKUP_DIR` must be outside the web root and outside `STORAGE_BASE_PATH` (scripts refuse otherwise). The encryption key file is `chmod 600`, and a copy must be kept away from the server. Off-site copy via `rclone copy --immutable` once `BACKUP_OFFSITE_TARGET` is set (not chosen yet). **Settings › Backups** (`manage_company_settings`: Admin, MD, ED, System Administrator; read-only, `BackupStatusService`) shows the last runs; the daily digest warns if a copy is older than 26 hours, failed, not encrypted, or the test-restore is older than 35 days. Restoring is manual: SOP 32 and §12.3 (set-up and restore steps). Self-test `bash scripts/tests/backup_scripts_test.sh` (also run by `BackupFacilityTest` / Jest).

### 3.16 Where to look first (quick map)

| Question | PHP | Node |
|---|---|---|
| Which URL does what, and who may call it? | `public_html/index.php` | `app/src/server.js` |
| Is this person allowed on this order? | `Services/OrderAccessService.php` | `services/orderAccessService.js` |
| What money may they see? | `Services/MoneyVisibility.php` | `services/moneyVisibility.js` |
| Which division / module? | `Services/DivisionService.php` | `services/divisionService.js` |
| What goes on a document? | `Services/DocumentDataAssembler.php` + `templates/<CODE>/` | `services/documentDataAssembler.js` + `templates/<CODE>/` |
| Profit, CAFIN | `OrderProfitabilityService`, `CaFinancialSnapshotService` | camelCase twins |
| Every e-mail | `MailSenderService`, `EmailDispatchService` | twins |
| Zoho | `ZohoBooksService`, `ZohoGuard`, `CaSyncService` | twins |
| Why is it like this? | `docs/decisions/owner-decisions-register.md` (search the D-code in the code comment) | same |

## 4. Roles, permissions and access

This section explains who may see and do what. Read it before you touch any
permission, route or page. The rule of thumb is simple: **access is role +
permission, never a person's name in code** (D-116). The data is in
`roles`, `permissions`, `role_permissions`, `user_permissions`,
`departments`, `department_designations` and `user_department_memberships`.

### 4.1 How a permission check works

Every protected route names a permission key in its route line:

- PHP: `PHP_MySQL_Version/public_html/index.php`, e.g.
  `PermissionCheck::requires('settle_orders')` (middleware in
  `app/src/Middleware/PermissionCheck.php`).
- Node: `NODE_MySQL_Version/app/src/server.js`, e.g.
  `requirePermission('settle_orders')` (middleware in
  `app/src/middleware/permissionCheck.js`).

The check itself is `PermissionService::can()` (PHP
`app/src/Services/PermissionService.php`, Node
`app/src/services/permissionService.js`). In order:

1. **Preview?** If the person is previewing a designation (4.4), the
   previewed role is used and there is **no** Super Admin bypass and **no**
   personal exception.
2. **Effective role.** `ActiveDepartmentService::effectiveRoleId()` returns
   the role of the department designation the person "logged in as". A
   person with no department membership uses the role on their user record.
3. **Super Admin.** A permanent Super Admin (`users.is_super_admin = 1`) or
   someone with a live row in `super_admin_delegations` passes every check.
4. **Role + personal exceptions.** `PermissionRepository::effectivePermissions()`
   merges `role_permissions` with `user_permissions` (personal exceptions,
   each with a reason and an optional `expires_at` "until" date).

Holding a permission never overrides the state of the record (D-116). A
Supplier PO still needs Stage 5 open; a closed order still refuses changes
(`OrderLockGuard`); an action on a record that does not exist says "not
found" (D-209).

Two more filters sit on top of permissions:

- **Order visibility by assignment (D-118).** Most people see only orders
  in `order_assignees` for them. `view_all_orders` bypasses this.
  `view_archived_orders` is needed for archived ones. Checked by
  `OrderAccessService` on every order page and child route.
- **Division scope (D-257).** `DivisionService` limits every list, report
  and order route to the person's division, unless they hold
  `see_all_divisions`.

### 4.2 The 24 roles

All roles are seeded in `docs/seed.sql` (both stacks). Only **Admin** is a
system role (cannot be deleted). Each department designation is its own
role (D-214).

| # | Role | Department / designation | Seed permissions | In one line |
|---|---|---|---|---|
| 1 | Admin | none (keeps own role, D-214) | 93 | Full system access incl. users, roles and audit log. Lacks only `manage_approval_limits`. |
| 2 | Managing Director | Company Management · director | 94 (all) | Full business authority; the only role with every key. |
| 3 | Executive Director | Company Management · director | 87 | Same as MD except: `manage_pricing`, `manage_incentives`, `manage_divisions`, `manage_product_specifications`, `manage_associate_bank`, `pay_incentives`, `record_market_prices`. |
| 4 | Export Executive | Sales & Export · executive ("Sales/Export Executive") | 22 | Day-to-day order work QT → OC, documents, records (not clears) payments. |
| 5 | Accounts Executive | Accounts & Finance · executive | 29 | Payment tracking, clearing, supplier payments, INR actuals, CA view. |
| 6 | Logistics Executive | Logistics · executive | 14 | Packing, freight, BL, shipping documents, logistics bills. |
| 7 | Viewer / Auditor | Audit & Compliance · "Internal Auditor" | 11 | Read-only: orders, clients, reports, audit log, all money classes. |
| 8 | CA / Chartered Accountant | Audit & Compliance · "External CA" | 10 | Read-only CA module, every order (read-only), CAFIN. No editing (D-114). |
| 9 | Sales/Export Manager | Sales & Export · manager | 29 | Export Executive + approvals, all orders, assign owners. |
| 10 | Sales Director | Sales & Export · director | 33 | Manager + cockpit, staff reports, email-send approval. |
| 11 | Procurement Executive | Procurement · executive (limit ₹0) | 11 | Supplier PO, purchasing, market prices. Sees the order number, not the client. |
| 12 | Procurement Manager | Procurement · manager (limit ₹5,00,000) | 15 | Executive + approve purchases within limit, all orders. |
| 13 | Procurement Director | Procurement · director (no limit) | 17 | Manager + cockpit, agreements view. |
| 14 | Logistics Manager | Logistics · manager | 18 | Executive + logistics partners directory, all orders. |
| 15 | Logistics Director | Logistics · director | 20 | Manager + cockpit, agreements view. |
| 16 | Finance Manager | Accounts & Finance · manager | 36 | Accounts + order financials, CAFIN generate, associate bank checks. |
| 17 | Finance Director | Accounts & Finance · director | 41 | Manager + short-payment approval, payment-terms approval, cockpit. |
| 18 | System Administrator | Administration · other | 17 | Users, settings, templates, lists. **No business money, no data export.** |
| 19 | Order Coordinator | Order Desk · executive | 4 | Status of every order, sets department owners, cockpit. No prices. |
| 20 | Operations Manager | Order Desk · manager | 5 | Coordinator + order assignees. No money (confirmed 1 Nov 2026). |
| 21 | Export Compliance Executive | Export Compliance · executive | 8 | Compliance checklist, COO Prep Sheet, benefit claims, gov't documents (D-226). |
| 22 | Export Compliance Manager | Export Compliance · manager | 13 | Executive on every order + approve documents, compliance task types. |
| 23 | Export Compliance Director | Export Compliance · director | 14 | Manager + cockpit. |
| 24 | Investor (view only) | none | 3 | `see_all_divisions`, `view_cockpit`, `view_reports` only (D-257). |

Abbreviations used in the next table: ExpEx = Export Executive, AccEx =
Accounts Executive, LogEx = Logistics Executive, Viewer = Viewer / Auditor,
CA = CA / Chartered Accountant, SalesMgr/SalesDir, ProcEx/ProcMgr/ProcDir,
LogMgr/LogDir, FinMgr/FinDir, SysAdmin = System Administrator, OrdCoord =
Order Coordinator, OpsMgr = Operations Manager, ECEx/ECMgr/ECDir = Export
Compliance Executive/Manager/Director.

### 4.3 The 94 permissions

Generated from the seed (`permissions` + `role_permissions`), grouped by the
`category` column. "What it allows" is the first sentence of the seeded
description. A Super Admin holds every key regardless of this table. Admin
can change any role's ticks in **Users & roles › Roles and permissions**
(`/admin/permissions`), subject to the "nobody raises their own rights" rule
(4.5).

**Category: admin**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `data_export_run` | Download a full structure-only and data-only dump of the live database, to migrate all historical records into a fresh reinstall. | Admin, MD, ED |
| `delete_assets` | Permanently remove a superseded (inactive) logo/signature/seal/watermark upload. | Admin, MD, ED, SysAdmin |
| `manage_approval_limits` | Change a designation's purchase approval limit or make it a 'director' (no limit). | MD, ED |
| `manage_assets` | Upload/replace logo, signature, seal, watermark. | Admin, MD, ED, SysAdmin |
| `manage_company_settings` | Edit company_settings key-value configuration. | Admin, MD, ED, SysAdmin |
| `manage_divisions` | Admin > Divisions: modules, number formats, division settings and activating a planned division. | Admin, MD |
| `manage_email_templates` | Add or edit email templates used when composing a send (never delete — every past send keeps its own frozen copy in the email log regardless). | Admin, MD, ED, SysAdmin |
| `manage_field_protection` | Request or approve locking/unlocking a protected field (company setting, T&C clause, or payment preset). | Admin, MD, ED, SysAdmin |
| `manage_permissions` | Edit role/user permission matrix. | Admin, MD, ED, SysAdmin |
| `manage_sample_data` | Load/clear the Sample Data Playground (test clients/orders only — never real data). | Admin, MD, ED, SysAdmin |
| `manage_signatories` | Manage the designations list, mark a user as signatory-eligible, upload their signature/designation-seal images, and set the global and per-document-type default signatory. | Admin, MD, ED, SysAdmin |
| `manage_task_types` | Set the standard and escalation days of each kind of department task. | Admin, MD, ED |
| `manage_users` | Create/edit/deactivate user accounts and assign roles. | Admin, MD, ED, SysAdmin |
| `see_all_divisions` | Company level: see every division's orders, clients and reports, and pick one division or "All divisions" in the header (the picker shows only when two or more divisions are active). | Admin, MD, ED, AccEx, Viewer, CA, FinMgr, FinDir, SysAdmin, Investor |
| `view_audit_log` | View the immutable system audit trail. | Admin, MD, ED, Viewer, SysAdmin |

**Category: Agreements**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `manage_agreements` | Add, edit, renew and terminate agreements with clients, suppliers, logistics partners, associates and staff; sees staff NDAs; gets the expiry reminders | Admin, MD, ED |
| `view_agreements` | See the agreements register and download signed copies (not staff agreements) | Admin, MD, ED, SalesDir, ProcDir, LogDir, FinDir |

**Category: Associates**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `manage_associates` | Associate register, agreements, rates, invitations, client links and their changes, portal access | Admin, MD, ED |
| `manage_associate_bank` | See associates' full bank details and verify bank changes (once the associate has confirmed them; one or two people — setting) | Admin, MD, FinMgr, FinDir |
| `view_associate_commission_rules` | See associate commission rules — an associate's rule (standard ladder / own ladder / fixed %), the % and level of their next order, orders counted ("3 of 5") and the steps; without it staff see "Set by the MD" (D-292) | Admin, MD, ED, FinDir, FinMgr |
| `view_associates` | See which client was introduced by which associate (no amounts, bank details or agreements) | Admin, MD, ED, ExpEx, SalesMgr, SalesDir |

**Category: ca**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `ca_fy_lock_override` | Push through a single CA entry even while its financial year is locked — for a genuine backdated correction, never routine use. | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `ca_internal_doc_manage` | Toggle the internal-only CA Financial Annexure (govt export benefits + expenses) for an order and generate it. | Admin, MD, ED, FinMgr, FinDir |
| `ca_module_manage` | Trigger a Zoho Books sync and view its log, and lock/unlock a financial year for CA data entry — kept separate from ca_module_view since these are administrative actions with a wider blast radius than routine data entry, not … | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `ca_module_view` | View the CA/Accounting module (independent of the order-pipeline system) — the INR settlement register and, as later phases land, revenue/expense/reconciliation reports. | Admin, MD, ED, AccEx, CA, FinMgr, FinDir |
| `inr_actual_delete` | Remove a recorded INR actual amount (e.g. | Admin, MD, ED, FinMgr, FinDir |
| `inr_actual_edit` | Record or correct the actual INR amount credited to the bank for a cleared advance/balance/freight payment. | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `inr_actual_view` | See the actual INR amount credited to the bank for a cleared advance/balance/freight payment. | Admin, MD, ED, AccEx, CA, FinMgr, FinDir |
| `manage_order_financials` | View/add/edit an order's export benefit claims, other costs, and its profitability summary. | Admin, MD, ED, FinMgr, FinDir |
| `view_cafin` | See and download the internal CA Financial Annexure (CAFIN): the CAFIN box on the order page and CAFIN in document lists. | Admin, MD, ED, AccEx, CA, FinMgr, FinDir |

**Category: catalog**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `browse_product_catalog` | Browse the full product catalog list, not just search results. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, ProcEx, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir |
| `manage_compliance_task_types` | Add or deactivate compliance task types (ECGC Cover, Pre-Shipment Inspection, etc.). | Admin, MD, ED, SysAdmin, ECMgr, ECDir |
| `manage_dropdown_options` | Add, edit, and deactivate the admin-editable option lists used across the app (Container Type, Certificate of Origin Type, etc. | Admin, MD, ED, SysAdmin |
| `manage_hs_codes` | Add, edit, and deactivate HS codes in the master list order creation picks from — kept separate from ordinary order-entry access so a new code always goes through a privileged person first. | Admin, MD, ED, SysAdmin |
| `manage_logistics_partners` | Add, edit, and deactivate CHA and transportation partners in the directory order staff pick contacts from. | Admin, MD, ED, LogMgr, LogDir |
| `manage_payment_presets` | Create, edit, deactivate payment presets (advance/balance %, balance-trigger wording). | Admin, MD, ED, SysAdmin |
| `manage_ports` | Add, edit, and deactivate the Port of Loading / Port of Discharge options offered on the New Order form. | Admin, MD, ED, SysAdmin |
| `manage_pricing` | Change the pricing settings, a product's pricing overrides, the associate commission levels and ladder, and an associate's commission level. | Admin, MD |
| `manage_product_catalog` | Create, edit, and delete products, suppliers, images, and misc charges. *Note: also Duplicate a product (D-358) and Configure it — base price, product-level values, switching it Active (D-361; Thickness rules also need `manage_product_specifications`, D-363).* | Admin, MD, ED |
| `manage_product_specifications` | Change one product's specifications: hide a specification or option, change an option's value, add options or specifications for that product only, reset to the category. | Admin, MD |
| `manage_suppliers` | Add, edit, deactivate and delete suppliers (and their types and factory addresses) in the Suppliers directory the Supplier PO picks from. | Admin, MD, ED, ProcEx, ProcMgr, ProcDir |
| `record_market_prices` | Record supplier and market prices for products (dated, valid until, history) and mark the one used for pricing. *Note: also Edit price, the Offer active switch and "+ Add product" (with `manage_suppliers`, D-359 / D-360); since D-361 the selling price comes from the configured base price, and "used for pricing" only picks whose CHA, packing and dates go with it.* | Admin, MD, ProcEx, ProcMgr, ProcDir |
| `view_price_buildup` | See a product's cost, margin, minimum price, team-incentive reserve, every floor and quote price, and the pricing settings. | Admin, MD, ED |
| `view_product_catalog` | Search and view individual products in the internal product catalog. | Admin, MD, ED, ExpEx, AccEx, LogEx, Viewer, SalesMgr, SalesDir, ProcEx, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir |
| `view_product_pricing` | See product pricing, supplier list, and misc charges. | Admin, MD, ED, ExpEx, AccEx, SalesMgr, SalesDir, FinMgr, FinDir |

**Category: clients**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `impersonate_client` | Start a staff-initiated client-portal session for a client who can't use the portal themselves. | Admin, MD, ED |
| `view_clients` | See the Clients list and a client's detail page without being able to create, edit, or act on it. | Admin, MD, ED, Viewer |
| `view_client_email_full` | See a client's full email address (vs a masked view). | Admin, MD, ED, ExpEx, AccEx, SalesMgr, SalesDir, FinMgr, FinDir |

**Category: documents**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `approve_documents` | Approve/release a generated document to the buyer. | Admin, MD, ED, SalesMgr, SalesDir, ECMgr, ECDir |
| `approve_email_send` | Level-2 approval for a deferred client email before it actually sends (Section 10 — Email & Deferred Send System). | Admin, MD, ED, SalesDir |
| `cross_verify_documents` | Add an independent pass/fail quality check on any generated document — separate from the formal reviewer sign-off. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir, FinMgr, FinDir |
| `download_pdf` | Download a generated PDF document. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, ProcEx, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir, ECEx, ECMgr, ECDir |
| `edit_locked_data` | Edit a field that is normally locked after stage sign-off. | Admin, MD, ED |
| `generate_compliance_documents` | Generate the COO Prep Sheet (for the Certificate of Origin application) without the general Generate documents permission. | Admin, MD, ED, ECEx, ECMgr, ECDir |
| `generate_documents` | Generate QT/PI/OC/PL/CI/etc. | Admin, MD, ED, ExpEx, LogEx, SalesMgr, SalesDir, ProcEx, ProcMgr, ProcDir, LogMgr, LogDir |

**Category: incentives**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `manage_incentives` | See and change the incentive settings, each order's incentive and profit basis, the associate register and every payout. | Admin, MD |
| `pay_incentives` | See the incentive payouts list (person, order, amount) and record payments. | Admin, MD, AccEx, FinMgr, FinDir |
| `view_own_incentives` | See your own incentive amounts once the buyer has fully paid. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, ProcEx, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir, ECEx, ECMgr, ECDir |

**Category: money**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `view_buyer_payments` | What the buyer has paid and still owes: the payment snapshot, the remittances and their screenshots. | Admin, MD, ED, ExpEx, AccEx, Viewer, CA, SalesMgr, SalesDir, FinMgr, FinDir |
| `view_logistics_costs` | CHA, transport and packing costs in the catalogue, freight rates, and logistics bills and their scans. | Admin, MD, ED, AccEx, LogEx, Viewer, CA, LogMgr, LogDir, FinMgr, FinDir |
| `view_purchase_prices` | Supplier PO values, factory and supplier costs in the catalogue, supplier bills and their scans, and the Supplier PO document. | Admin, MD, ED, AccEx, Viewer, CA, ProcEx, ProcMgr, ProcDir, FinMgr, FinDir |
| `view_selling_prices` | Unit prices, FOB and order values, and the sales documents that carry them (QT, PI, OC, CI, AMD, Debit Note). | Admin, MD, ED, ExpEx, AccEx, Viewer, CA, SalesMgr, SalesDir, FinMgr, FinDir, ECEx, ECMgr, ECDir |

**Category: orders**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `approve_payment_terms` | Approve an order's payment terms when its preset is marked "Requires MD approval" — needed before the Quotation can be generated or sent. | Admin, MD, ED, SalesMgr, SalesDir, FinDir |
| `approve_short_payment` | Approve a short payment — clear a buyer payment that is less than due, with a written reason | Admin, MD, ED, FinDir |
| `approve_buyer_details_change` | Approve or refuse a request to copy the client's current details onto an order that already has an issued document (D-337). | MD, ED |
| `assign_department_owner` | Choose who in a department owns an order for that department (a manager for their own department; MD/ED and Admin for any). | Admin, MD, ED, SalesMgr, SalesDir, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir, OrdCoord, OpsMgr, ECMgr, ECDir |
| `assign_order_owner` | Add or remove which staff are assigned to an order (order_assignees). | Admin, MD, ED, SalesMgr, SalesDir, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir, OpsMgr, ECMgr, ECDir |
| `clear_payments` | Confirm a buyer payment actually landed in the bank, unlocking the next stage gate — split out of manage_payments so the person who records a payment is never also the one who confirms it. | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `close_orders` | Close a fully-despatched order or mark one lost — QA-5 RBAC-04: split out of manage_orders so an Accounts-only user can no longer close an order. | Admin, MD, ED, ExpEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir |
| `edit_order_post_confirmation` | Edit order details or product rows once Order Confirmation has been issued (Stage 4+) — a reason is required and every use is logged. *Note: since D-342 product rows and the currency are fixed from the first approved Quotation for everyone, this permission included.* | Admin, MD, ED |
| `manage_complaints` | View complaints, acknowledge/resolve them, and log one on a client's behalf. | Admin, MD, ED, SalesMgr, SalesDir |
| `manage_compliance_checklist` | Work the order's compliance checklist (ECGC cover, inspection, fumigation, phytosanitary, due diligence …). | Admin, MD, ED, ExpEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir, ECEx, ECMgr, ECDir |
| `manage_disputes` | View/raise/update a dispute's status, attach evidence, and enable/disable the client-facing dispute button. | Admin, MD, ED, SalesMgr, SalesDir |
| `manage_export_benefit_claims` | Answer which export benefit schemes an order is eligible for and file the claims (scheme, reference no., amount claimed, date). | Admin, MD, ED, FinMgr, FinDir, ECEx, ECMgr, ECDir |
| `manage_logistics_bills` | Add a CHA, transport or freight-forwarder bill to an order and remove one that has no payment on it. | Admin, MD, ED, AccEx, LogEx, LogMgr, LogDir, FinMgr, FinDir |
| `manage_orders` | Create/edit orders and advance them through stage gates. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir, FinMgr, FinDir |
| `manage_payments` | Record and clear an order's advance/balance payment (Stages 3 and 8) — QA-5 RBAC-03/04: split out of manage_orders so a Logistics-only user can no longer mark a buyer payment as cleared; kept with Accounts, who already owns … | Admin, MD, ED, ExpEx, AccEx, SalesMgr, SalesDir, FinMgr, FinDir |
| `manage_shipping` | Record freight terms/payment and packing/BL/shipping actions (Stages 6-7 and 9) — QA-5 RBAC-04: split out of manage_orders so an Accounts-only user can no longer record a BL. | Admin, MD, ED, ExpEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir |
| `manage_supplier_payments` | Mark a supplier payment as paid, and see the Outgoing (Supplier) side of Today's Payments — split out of manage_order_financials so Accounts can confirm supplier payments without the broader financials/profitability access. | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `manage_supplier_po` | Create and confirm the Supplier PO and record production status (steps 7a-7d). | Admin, MD, ED, ProcEx, ProcMgr, ProcDir |
| `override_buyer_acknowledgment` | Record a buyer's OC acknowledgment manually (e.g. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir, FinMgr, FinDir |
| `override_shipment_gates` | Override shipment gates — release the BL step with a written reason when the pre-shipment balance or the inspection is not cleared | Admin, MD, ED |
| `respond_to_complaints` | Post a reply in a complaint's conversation thread — kept separate from manage_complaints so sales roles can answer without also managing its status, same split as respond_to_disputes. | Admin, MD, ED, ExpEx, SalesMgr, SalesDir |
| `respond_to_disputes` | Post a reply in a dispute's reply thread — kept separate from manage_disputes so sales roles can answer without also managing dispute status or the button. | Admin, MD, ED, ExpEx, SalesMgr, SalesDir |
| `settle_orders` | Mark an order fully settled once every rupee is in and out. | Admin, MD, ED, AccEx, FinMgr, FinDir |
| `upload_order_documents` | Attach government and agency documents (ECGC policy, Certificate of Origin, phytosanitary / fumigation / inspection certificates, eBRC …) to an order in Step 14, without Manage orders. | Admin, MD, ED, ECEx, ECMgr, ECDir |
| `view_all_orders` | See every order in the Orders list, Kanban board, and order detail page, not just ones assigned to you. | Admin, MD, ED, AccEx, Viewer, CA, SalesMgr, SalesDir, ProcMgr, ProcDir, LogMgr, LogDir, FinMgr, FinDir, OrdCoord, OpsMgr, ECMgr, ECDir |
| `view_archived_orders` | See orders that have been archived out of the default listing. | Admin, MD, ED, ExpEx, AccEx, LogEx, SalesMgr, SalesDir, LogMgr, LogDir, FinMgr, FinDir |
| `view_orders` | See the Orders list and an order's detail page without being able to create, edit, or act on it. | Admin, MD, ED, Viewer, CA, ProcEx, ProcMgr, ProcDir, OrdCoord, OpsMgr, ECEx, ECMgr, ECDir |

**Category: procurement**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `approve_purchases` | Approve an award above the awarding designation's approval limit (up to this designation's own limit; no limit = any amount). | Admin, MD, ED, ProcMgr, ProcDir |
| `manage_purchasing` | Record supplier quotes, compare and award, follow production, record receipt and the quality check. | Admin, MD, ED, ProcEx, ProcMgr, ProcDir |
| `raise_purchase_requirements` | Ask Procurement for what an order needs (product, quantity, needed by) — no prices. | Admin, MD, ED, ExpEx, SalesMgr, SalesDir |

**Category: reports**

| Key | What it allows | Roles that hold it (seed) |
|---|---|---|
| `manage_report_definitions` | Create/edit/delete saved report definitions. | Admin, MD, ED, SysAdmin |
| `view_cockpit` | Open, overdue and escalated work per department, orders by stage, supplier delays and overdue buyer payments — counts and status only. | Admin, MD, ED, SalesDir, ProcDir, LogDir, FinDir, OrdCoord, OpsMgr, ECDir, Investor |
| `view_reports` | Run and view reports and dashboards. | Admin, MD, ED, ExpEx, AccEx, Viewer, SalesMgr, SalesDir, FinMgr, FinDir, Investor |
| `view_staff_reports` | View the Staff Productivity report (documents generated and audit-log activity per user) — kept separate from view_reports since it shows individual staff activity, not just business data. | Admin, MD, ED, SalesDir, FinDir |

### 4.4 Departments, designations, "Log in as" and "Preview as"

**Why (D-214, built 1 Nov 2026).** Day to day nobody edits permissions. To
give someone access, add them to a department. To take it away, end the
membership. The chain is **department → designation → role → permissions**.

**Departments (seed, `departments`).** `sees_whole_company` = 1 means the
department is company-level (not tied to one division).

| Code | Name | Company-wide | Designations (level → role) |
|---|---|---|---|
| MGMT | Company Management | yes | Managing Director (director), Executive Director (director) |
| SALES | Sales & Export | no | Sales/Export Executive → Export Executive; Sales/Export Manager; Sales Director |
| PROC | Procurement | no | Procurement Executive (limit ₹0); Procurement Manager (₹5,00,000); Procurement Director (no limit) |
| LOG | Logistics | no | Logistics Executive; Logistics Manager; Logistics Director |
| EXPORT_COMPLIANCE | Export Compliance (D-226) | no | Export Compliance Executive; Manager; Director |
| FIN | Accounts & Finance | yes | Accounts Executive; Finance Manager; Finance Director |
| AUDIT | Audit & Compliance | yes | Internal Auditor → Viewer / Auditor; External CA › CA / Chartered Accountant |
| ADMIN | Administration | no | System Administrator |
| OPS | Order Desk (optional, switched off) | no | Order Coordinator (executive); Operations Manager (manager) |

Sales, Procurement, Logistics, Export Compliance and Order Desk belong to
division EXP (Export); the others are company-level (seed line
`UPDATE departments SET division_id …`).

**Two different "designation" lists — do not confuse them.**

1. `department_designations` — the ladder above. Each row has a `level`
   (executive / manager / director / other), a `role_id`, an
   `approval_limit_inr` (purchasing) and `incentive_eligible` (D-259).
   Managed in **Users & roles › Departments** (`/admin/departments`,
   `manage_permissions`).
2. `designations` — the **signatory titles** printed under a signature on
   documents (seed: "Director", "Founder & Managing Director", "Founder &
   Executive Director"). Managed in **Settings › Documents › Signatories**
   (`manage_signatories`), with `user_signature_assets` and
   `document_type_signatories` (per document type default; the AMD defaults
   to the MD, D-067).

**Memberships.** `user_department_memberships` (user, designation,
`starts_on`, `ends_on`, `is_default`). Added and ended on **Users & roles › Users →
Edit › Departments**. An ended membership stays on record; from the next
day it cannot be chosen, and if someone is working in it at that moment it
stops at once.

**"Log in as".** After password and the e-mailed code (D-265):

- One membership → straight in, working as that department.
- Several → `/choose-department` asks which; "Start here next time" sets
  `is_default`.
- No membership → the person works with the role on their user record.
- The sidebar shows "Logged in as <designation> · <department>" in the
  department colour, with **Switch department** (`POST /switch-department`).
- Every audit-log row records the department the person was acting as.

Code: `ActiveDepartmentService` (PHP `app/src/Services/ActiveDepartmentService.php`,
Node `app/src/services/activeDepartmentService.js`), controller
`DepartmentController` / `departmentController.js`, admin pages
`DepartmentAdminController` / `departmentAdminController.js`.

**"Preview as" (view-only).** On Users & roles › Departments, a holder of
`manage_permissions` presses **Preview the app as this**
(`POST /admin/preview-as`). The app is shown exactly as that designation
sees it: the admin's own extra rights, Super Admin bypass and personal
exceptions are all off. A yellow banner stays at the top. **A preview is
look-only (D-258, 6 Nov 2026):** `SessionAuth` refuses every POST except
`/preview/exit` and `/logout` with "You are previewing the app: nothing can
be saved during a preview."

**Personal exceptions.** Users & roles › Roles and permissions › *Grant an extra permission*
writes `user_permissions` with a reason and an optional **Until** date
(`expires_at`). After that date it is listed as "ended" and no longer
applies. Use it rarely, e.g. letting one Logistics person download the
Commercial Invoice (it carries selling prices) with a reason and end date.

### 4.5 Who may give rights; Super Admin; protected founder accounts

**Nobody raises their own rights (owner decision A-8, 1 Nov 2026).**
Enforced by `GrantGuard` (PHP `app/src/Services/GrantGuard.php`, Node
`grantGuard.js`):

- You cannot give a permission you do not hold yourself — not on a role,
  not as a personal exception, not by giving someone a role or membership
  that carries it.
- You cannot change your own rights: your role's permissions, your own role,
  or an exception/membership for yourself.
- Removing a permission is always allowed.
- A Super Admin is the exception, so nobody is ever locked out.

So the System Administrator (who holds `manage_permissions` and
`manage_users`) can never tick money permissions onto a role.

**Super Admin.** `users.is_super_admin = 1` (permanent) or a time-limited
row in `super_admin_delegations` (granted with a reason, revocable). Pages:
`/super-admin` and `/admin/self-approval` (both `SuperAdminOnly`
middleware). A Super Admin passes every permission check but **not** the
maker-checker rules — those are governed by the self-approval rules (4.6).

**Protected founder accounts.** The seed creates exactly two users: the
Managing Director (md@email.com) and the Executive Director (ed@email.com),
both `is_super_admin = 1` and `is_protected_account = 1`. The application
refuses — for everyone, including other Super Admins — to:

- edit their details or department memberships (`UserController`,
  `DepartmentAdminController`);
- deactivate, archive or delete them;
- reset their password (only the person, via "Forgot password");
- change their signatory eligibility or designation title
  (`SignatoryController`);
- remove their Super Admin status (`SuperAdminService` throws);
- move them between divisions (D-258).

The users list shows a **"Protected — founder"** tag. Any change must be
made directly in the database by the owner's instruction.

### 4.6 Self-approval rules and approval limits

**Maker-checker.** Normally a second person approves what someone made
(D-117, replaced by D-216/D-221). The single choke point is
`MakerCheckerGuard` (PHP `app/src/Services/MakerCheckerGuard.php`, Node
`makerCheckerGuard.js`) reading `self_approval_rules` via
`SelfApprovalRules`.

**Users & roles › Self-approval rules** (`/admin/self-approval`, Super Admins only)
lists ten rules. For each, tick the roles whose holders may approve their
**own** work. A person qualifies when any of their own roles (main role,
acting department, any membership) is ticked. Seed default for every rule:
**Managing Director and Executive Director** — except `buyer_details_change`
(D-337): **Managing Director only**.

| Rule key | Self-approval of… | Audit action when used |
|---|---|---|
| `payment_terms` | payment terms of an order they created | PAYMENT_TERMS_SELF_APPROVED |
| `document_review` | a document they generated | DOCUMENT_SELF_REVIEWED (badge "self-review") |
| `email_send` | an e-mail send they requested | EMAIL_SEND_SELF_APPROVED |
| `cancellation` | approve/reject a cancellation they requested | ORDER_CANCELLATION_SELF_APPROVED / _REJECTED |
| `purchase_award` | a purchase award they made (above their limit) | PURCHASE_AWARD_SELF_APPROVED |
| `amendment` | MD-approve an amendment they requested | AMENDMENT_SELF_APPROVED |
| `short_payment` | clear short a payment whose receipts they recorded | SHORT_PAYMENT_SELF_APPROVED |
| `price_discount` | approve a line price below the floor that they set (D-240/D-245) | PRICE_DISCOUNT_SELF_APPROVED |
| `bank_details` | verify bank details (associates and suppliers) they entered themselves, with a reason (D-293) | (reason kept on the bank change) |
| `buyer_details_change` | approve or refuse their own request to copy the client's details onto an order with an issued document (D-337; **default Managing Director and Executive Director**) | BUYER_DETAILS_CHANGE_APPROVED / _REFUSED (decided by the requester) |

There is **no other exception**: Admin, System Administrator and Super
Admin self-approve only if one of their roles is ticked. A rule change is
logged as SELF_APPROVAL_RULE_CHANGED. A self-review assigned while allowed is
refused at approval if the rule was withdrawn in between.

**Approval limits (purchasing, D-214 phase 3).** Each designation has
`approval_limit_inr`; level `director` means no limit.

| Designation | Approves an award up to |
|---|---|
| Procurement Executive | ₹0 — every award goes to an approver |
| Procurement Manager | ₹5,00,000 |
| Procurement Director, MD, ED | no limit |
| Any manager-level designation with no limit set | ₹0 (waits — nobody self-approves on an unknown limit) |

Example: a Procurement Manager awards a quote of 2,000 SQM × ₹300 + 18% GST =
₹7,08,000. That is above ₹5,00,000, so it waits for a holder of
`approve_purchases` whose own limit covers it (Director or MD/ED).

Only holders of `manage_approval_limits` (MD, ED, Super Admins) change a
limit or make a designation "director", and never on a designation they hold
themselves (QA R8-N10). Logged as APPROVAL_LIMIT_CHANGED.

### 4.7 Money visibility (MoneySeparation, D-214 phase 2)

One rule decides whether the signed-in person may see a kind of money:
`MoneyVisibility` (PHP `app/src/Services/MoneyVisibility.php`, Node
`moneyVisibility.js`). Every page, list, Kanban card, report, CSV, document
download, file download and dossier ZIP asks it.

| Money class | Permission | Default holders | Documents carrying it |
|---|---|---|---|
| Selling prices (unit prices, FOB, order values) | `view_selling_prices` | Sales, Finance, MD/ED, Admin, auditors, CA, Export Compliance | QT, BUYERPO, PI, OC, FDN (Debit Note), CI, AMD, COOPREP, ESI |
| Buyer payments (paid / owed, remittances, screenshots) | `view_buyer_payments` | Sales, Finance, MD/ED, Admin, auditors, CA | CHECKLIST_2_FINANCE |
| Purchase prices (Supplier PO values, supplier bills, factory cost) | `view_purchase_prices` | Procurement, Finance, MD/ED, Admin, auditors, CA | SUPPO |
| Logistics costs (CHA, transport, packing, freight, logistics bills) | `view_logistics_costs` | Logistics, Finance, MD/ED, Admin, auditors, CA | BLI |
| Order costs, expense receipts, profit | `manage_order_financials` | Admin, MD, ED, Finance Manager/Director | CAFIN also needs `view_cafin` |

Rules worth remembering:

- **Status is shown, amounts are hidden.** Logistics sees "Remittance
  recorded on 3 Jun" without the amount; Sales sees "Supplier advance: paid
  on 12 Jun" without the bill.
- **Procurement sees the order number, not the client** (`seesClient()`):
  the client name is left off the order page, list and board for anyone
  acting as a Procurement designation. Logistics sees the client name.
- **Every copy follows the rule** (D-229): drafts, superseded copies,
  internal Word copies and older CAFINs carry `file_store.linked_document_id`
  so they inherit the document's money class.
- **Audit trails hide amounts** with "— hidden (no permission to see these
  amounts)" (`AuditMoneyMask`).
- Reports with selling prices or buyer payments open only for people who may
  see them.
- Test: `e2e/tests/12-money-matrix.spec.js` signs in as every role, opens
  about 75 screens/CSVs and every file of a sample order, and fails if an
  amount leaks.

### 4.8 Division visibility (`see_all_divisions`, D-257)

One company, several divisions (`divisions`): **EXP Export (active)**, DOM
Domestic Trade, MTT Merchant Trade, IMP Import & Distribution (all
**planned**). Every order has a `division_id` that never changes; users and
departments have a division (empty = company level).

- `see_all_divisions` (Admin, MD, ED, Accounts Executive, Viewer/Auditor, CA,
  Finance Manager/Director, System Administrator, Investor) sees every
  division and gets the header switcher with "All divisions".
- Everyone else sees only their own division's orders, clients, reports,
  counts, tasks, files and CSV exports.
- **While only one division is active nothing shows:** no switcher, no
  Division filter or field. Settings › Divisions (`manage_divisions`: Admin,
  MD) is the only visible place.
- Staff placed in a division that is not active can look but not save.
- Code: `DivisionService` (PHP/Node), `DivisionController` /
  `divisionController.js`; tables `divisions`, `division_modules`,
  `division_settings`, `client_divisions`.

### 4.9 The Investor (view only) role

Role 24, added by D-257. It holds only `see_all_divisions`, `view_cockpit`
and `view_reports`: dashboards, the management cockpit and reports across
every division. Every page that changes anything is refused (tested route
by route). It has no client-portal access and sees no order pages
(it lacks `view_orders`).

**Watch out: the seed Investor sees no money.** The role holds none of the
four money permissions (4.7). `ReportController::refusedForMoney()` refuses
every report that prints selling prices or buyer payments (per-client,
aggregate, payments, today's payments, amendments, trends, ageing, products,
performance) with "You do not have permission to see the amounts in this
report.", and Order Profitability needs `manage_order_financials`. The
Reports page does not offer those reports to someone who would be refused
(QA UI-006, 13 Nov 2026), and a link to one anywhere else on a staff page is
plain text (`PermittedLinks::MONEY_REPORTS`). With the
seed set-up an Investor can open the dashboard, the cockpit, Operations
Queues, Conversion and Disputes — counts and status only. If the owner wants
investors to see revenue or profit, add `view_selling_prices` (and perhaps
`view_buyer_payments`) to the role in Users & roles › Roles and permissions; that is a
business decision, not a bug fix.

### 4.10 The client portal (buyers)

**Who:** buyers only, at `/client/login` (layout `views/layout/client.php` /
`client.njk`, middleware `ClientAuth`). Logins are in `client_logins`; one
e-mail per client is enforced by a unique index (D-129, D-210).

**When the login exists.** It is created automatically when the advance is
cleared or the LC is confirmed usable (Stage 3 → 4). A returning client
keeps their login. Before that the buyer only uses no-login links:
quotation details form (`/quotation-details`), PI details form
(`/pi-details/{token}`), the Buyer Action Link, the Payment Action Link.

**What a buyer sees** (SOP 12):

| Screen | Content |
|---|---|
| My Orders | Every order grouped Active / Complete / Disputed / Did not go ahead (= lost) / Cancelled. "Documents Sent" shows as **Complete**. Columns Order number · Where it is now (the stage in buyer words: Quotation · Order confirmed · In production · Shipping · Shipped — `AssociatePortalRepository::stageLabel`, D-330) · Ordered on · **Open order**. Order history can be limited by `client_portal_show_order_history` (global) and a per-client override. |
| Inside an order | OC acknowledgement (when pending); Documents (final approved/sent customer-facing PDFs only; can be hidden by `client_portal_show_document_downloads` + per-client override); Buyer PO upload (closes once the advance clears, D-127, and never offered or accepted on a cancelled/lost order, client portal and Buyer Action Link alike; the "After you sign your purchase order, upload a scan of it here…" intro shows only while upload is open — not on a cancelled/lost order (D-269) and not once the advance has been received (D-330); Order Updates chat; Report a Payment (only after the PI is issued, never on cancelled/lost, D-206); **Ask to cancel this order** (a dispute; only if staff switched it on for that order); **Tell us what we could do better** (a process issue / complaint; always); Order Feedback (after documents are sent, D-133); Reorder This (each line's unit matched to the unit list ignoring case/spaces; an unknown unit is shown and the buyer must choose, never a silent PCS). The OC acknowledgement table's Port of Discharge falls back to the typed port, as the documents do. |
| My Account | Change password only. Forgot-password link valid 72 h, max 5 per client per hour, generic answer. |

**CAFIN never reaches the client (standing rule, D-068, D-106).** The
portal lists only `document_types.category = 'customer_facing'` documents in
status approved or sent (`DocumentRepository::customerFacingForOrder()`),
and every download re-checks the type, so CAFIN, the Supplier PO, BLI, COO
Prep Sheet, AMD and checklists are refused **even by direct ID**. E-mail
compose refuses internal document types; link pages offer only issued
customer documents. Customer-facing types: QT, ANNEXA, BUYERPO, PI, OC, FDN,
PL, CI, BLE, ESI.

**Staff "Log in as this client".** Needs all three: permission
`impersonate_client` (Admin, MD, ED), the client's own
`allow_staff_impersonation` toggle, and the global
`client_impersonation_enabled` setting (off by default). An amber banner
shows on every portal page; every use is logged. Staff can also reset a
client's portal password (one-time temporary password, D-122).

**Client portal login block (D-282, 11 Nov 2026).** The client page shows
"Client portal login": account status (Active / Switched off), created
date, last login; the permanent login page `/client/login` with a Copy
button; **Send set-password link** (`POST /clients/{id}/portal/send-link`,
`manage_company_settings`; a new 72-hour one-use link e-mailed to the
client, MUST, always also shown with Copy); **Reset with a temporary
password**; **Log in as this client**. Without a portal account yet the
block says it is created when the first advance clears. The portal invite
e-mail (template `client_portal_invite`, token `{login_link}`) gives the
permanent login page, and staff see whether it was really e-mailed, with
the set-password link to copy. Buyers get their own login-code wording
(template `client_login_code`). Buyers are signed out after 2 hours idle
(`session_timeout_client_minutes`, D-283).

**Plain words for buyers, associates and suppliers (D-330, 11 Nov 2026; owner "23 OK", confirmed
twice).** Every screen and e-mail a buyer, associate or supplier sees uses short sentences and
everyday words; staff screens keep their terms. Rules the code follows:

- A buyer never sees an internal step name or the word "lost": stages are Quotation · Order
  confirmed · In production · Shipping · Shipped (Cancelled / Did not go ahead); refusals say "This
  order was cancelled" / "This order did not go ahead, so …".
- Buyer labels: "Bank reference number (transaction ID, UTR or SWIFT reference)" (was "Transaction
  ID / UTR"), "Paid from (bank and account)", "Delivery terms (Incoterm)", "Port where the goods
  arrive", "Who receives the goods (consignee)", "Who to tell when the goods arrive (notify party)",
  "Company tax number (VAT, EORI or tax registration)", "Your purchase order", "Your review";
  buttons "Yes, this is correct — please go ahead", "Ask to cancel this order" (was "Raise a
  Dispute"), "Send my details" (PI details form). The process-issue section is "Tell us what we
  could do better" and the reply e-mail's subject "Our reply about the issue you raised on order
  … — <company>". The Payment Action Link calls the freight leg "Freight and insurance", paid
  against the "freight bill (debit note)".
- Seeded e-mail templates (`email_templates`): the document e-mails say "(your enquiry reference:
  …)" and "based on quotation / proforma invoice …"; `send_fdn` subject "Freight bill (debit note)
  … — please pay before we ship"; `shipment_readiness` subject "Your order … is ready to ship
  (shipment readiness confirmation)" (the documents' payment terms still name the "Shipment
  Readiness Confirmation"); `pi_form_link`, `buyer_action_link` (a numbered 1-2-3 list),
  `client_portal_invite`, `client_portal_password_reset`, `quotation_correction_link`,
  `payment_followup`, `bl_copy_sent`, `balance_receipt_confirmation`, `send_annexa`, `send_esi`
  reworded. The payment-link paragraph added to PI / debit note / CI e-mails asks for "a
  screenshot of the payment and its details (bank reference number, amount and date)". An
  existing database gets the new wording only through the guarded D-330 SQL, which updates a
  template only while its body is still the old seeded text (an admin's edit is never
  overwritten; safe to run twice).
- Associate portal: "This page is not open for your account"; My clients columns "How they came to
  us · Your client until · Last day for orders that earn commission", code field "Your word for
  the code", button "Show another ending"; statement and notices as in SOP 24 (no "basis",
  "ladder", "payout run" or "protection" in what the associate reads; the FOB masking of notices
  also matches "invoice value of the goods").
- Supplier link: "Signed purchase order (or your written acceptance)".
- Not changed: PDF / DOCX documents (8 passages listed for the owner, not confirmed) and staff
  wording.

### 4.11 The associate portal and access levels

Associates are outside agents (India or abroad) who introduce buyers under
an Associate Agreement (D-217, D-245). They are **not staff**: no staff
login, no department. They use `/associate` with password + an e-mailed
code (`/associate/login`, `/associate/login/code`, middleware
`AssociateAuth`). A buyer login never opens it, and an associate never sees
a buyer's portal.

**Getting in (D-282, 11 Nov 2026).** Accepting a registration (or "Send
set-password link" on the associate page) makes a set-password link
(`/associate/set-password/{token}`, valid 72 hours, works once) and e-mails
it (MUST e-mail `set_password_invite`, never held by a switch). The e-mail
also gives the permanent login page `/associate/login`. Staff always see
the link with a **Copy** button, and the message says truthfully whether
the e-mail went out. The login page has **Forgot password?**
(`/associate/forgot-password`): a new 72-hour, one-use link to the
address if it belongs to an associate whose portal is on (same answer
either way; at most 5 an hour; `ASSOCIATE_PASSWORD_RESET_REQUESTED`).
The associate page shows three blocks: **Portal login** (status, password
set or not, last login, the login page with Copy, "Send set-password
link", "Switch portal off"), **Quotation link (with Associate code)** with
Copy, and **Personal code** (current code, new code, "Block the old
code", "Set code" spaced apart). Associates are signed out after 2 hours
idle (`session_timeout_associate_minutes`, D-283).

Never shown at any level: cost, margin, supplier prices, profit, CAFIN,
buyer payment amounts, other associates.

An invitation, order, payout or client that is not the associate's own (or
does not exist) answers 404 with the same short wording ("Order not
found." etc.), shown inside the portal layout with a "Back to your home
page" link (`associate_portal/not_found`) — it never says whether the
record exists for someone else.

**Owner decisions of 6 Nov 2026 (business volume and FOB):**

- An associate **may see their commission** (per order, and the portal's
  "Earned in the last N months / Waiting to be paid / Paid in the last N
  months / On hold" commission figures — N is the setting "Commission period
  shown to associates", 3–24 months, default 12, D-263), but must **never see
  the total business they bring in, nor a count or total of their orders**.
  The portal has no lifetime or period order-value or order-count summary
  (no total FOB, no total sales, no "your business with us" figure, no
  number of orders) on any page, statement, notice or e-mail, and one must
  never be added. The number of clients they brought in is allowed.
- **D-263 (6 Nov 2026):** the associate never sees the running number of
  their counting orders or the ₹ threshold behind the ladder (the statement
  says only "Counts toward the ladder: yes / no"), nor a level name or band —
  only the rate ("5%"), and only with "Show commission %"
  (`AssociateAccess::rateOnly`, `AssociateCommission::rateLabel`). The FOB in
  a "commission earned" notice is removed unless `fob_value` is on
  (`maskNotice`). My clients shows order numbers only with `orders`.
  `AssociateCommissionPeriodTest` / `associateCommissionPeriod.test.js`
  render every portal page and download at Starter, Quoting, Full and
  Custom (everything on) and fail on any aggregate of orders or business.
- Whether an associate sees the **FOB value of an individual order** is
  governed **only by the Access-levels matrix** below (`fob_value`; for a
  Custom associate, their own tick). No other setting or screen adds or
  removes it.

**Access levels (D-261, 6 Nov 2026).** Each associate has
`associates.access_level` = starter / quoting / full / custom. The single
decision point is `AssociateAccess::allows()` (PHP
`app/src/Services/AssociateAccess.php`, Node `associateAccess.js`). Hidden
features are refused on the server (lock page, HTTP 403: "Not available at
your access level — please contact NexaCrest"); the FOB value and commission
% are stripped server-side, and amounts are blanked in notices and e-mails.

Default matrix (`AssociateAccess::DEFAULTS`; MD can change it in
**Associates › Access levels**, stored in `associate_access_level_settings`;
"Restore recommended defaults" puts these back):

| Feature (key) | Starter | Quoting | Full |
|---|---|---|---|
| Profile, bank & agreement (`profile`) — always on | ✔ | ✔ | ✔ |
| My clients (`clients`) | ✔ | ✔ | ✔ |
| Clients' orders and stage (`orders`) | ✔ | ✔ | ✔ |
| Show FOB order value (`fob_value`) | — | ✔ | ✔ |
| Commission per order, amount (`commission`) | ✔ | ✔ | ✔ |
| Commission % on screen (`commission_pct`) | — | ✔ | ✔ |
| Registration links / personal code (`registration_links`) | ✔ | ✔ | ✔ |
| Catalogue, no prices (`catalogue`) | — | ✔ | ✔ |
| Get price / quoting (`get_price`) | — | ✔ | ✔ |
| Price requests "Other" (`price_requests`) | — | ✔ | ✔ |
| Messages (`messages`) | ✔ | ✔ | ✔ |
| Documents of their orders (`order_documents`) | — | — | ✔ (switch only: the portal has no order-documents page yet, O-22) |
| TDS certificates (`tds_certificates`) | ✔ | ✔ | ✔ |
| Commission statement download (`commission_statement`) | — | ✔ | ✔ |
| Amounts in messages and e-mails (`notice_amounts`) | — | — | ✔ |

- **Custom** starts from Starter and uses the associate's own ticks in
  `associate_access_features`.
- New associates start on **Starter**; every associate that existed before
  6 Nov 2026 stays **Full**.
- Only the MD changes a level (associate page → Portal access, reason ≥ 10
  characters, logged in `associate_access_log`).
- After 3 completed, fully paid orders (setting) "Ready for the Quoting
  level?" shows on the associate page and on the dashboard of everyone with
  `manage_associates` (D-263; not tied to the MD role). Nothing upgrades by
  itself.

Staff permissions for associates: `manage_associates` (Admin, MD, ED),
`view_associates` (Sales roles: who introduced which client, no amounts),
`manage_associate_bank` (Admin, MD, Finance Manager/Director). Only the MD
(or a Super Admin) can move or remove a client's associate, with a reason.

### 4.12 SOP access by role (D-266)

Owner decision D-266 (7 Nov 2026). The SOP / Operations Guide (`/sop`) shows each staff member only the chapters for their work. Service `SopAccessService` / `sopAccessService.js`; screen **Users & roles › SOP access** (`/admin/sop-access`, link at the top of Company Settings and on the SOP home page for those who manage it).

| Rule | How |
|---|---|
| **SOP library on/off** | Company setting `sop_library_enabled` (category `sop_access`, kept off the general Settings page). Off hides the SOP menu, every chapter, its screenshots and the **Gallery** from all staff except the people who always see everything. Getting Started stays open. |
| **Per chapter, on for everyone** | `sop_chapters.is_enabled`. Off hides the chapter from everyone except the people below, even where it is ticked. |
| **Role ticks** | `sop_role_chapters (role_id, slug)`. A person sees the chapters of their own role **and** of every role they hold through a current department membership (same model as permissions, §4.4). "Copy ticks from" another role fills the form; nothing is saved until Save. |
| **Extra chapters for one person** | `sop_user_chapters (user_id, slug)`, added on **Users › Edit** ("Extra SOP chapters for this person"); shown as **Extra for you** in their list. |
| **Always everything** | An effective Super Admin and whoever holds `manage_company_settings` (seeded: Admin, MD, ED, System Administrator) see every chapter and manage SOP access. No new permission was added. A "Preview as" another designation shows that designation's chapters only. |
| **Always visible** | `00-getting-started`, to every staff member, even with the library off. |
| **CA chapters** | A `ca-` chapter still also needs `ca_module_view`. |
| **New chapter** | A chapter file with no `sop_chapters` row is **New**: only the people who always see everything see it until it is ticked for a role or a person, or switched on. |
| **Links and screenshots** | A link to a chapter the person cannot open is shown as plain text. A screenshot (`/sop-assets/…`) is served only when a chapter the person may open (or the SOP home page) shows it, else 403 "Not permitted.". The Getting Started tour (`/getting-started`) follows the same rules (QA UI-005, 13 Nov 2026): its quick-reference chapter links are plain text for chapters the person may not open, and a screenshot they may not load is left out with its caption (e.g. the dashboard screenshot, which no chapter shows, appears only for the people who see every chapter). |
| **Refusal** | Opening a hidden chapter shows, in the normal layout with HTTP 403: "This SOP chapter isn't available for your role. Ask your Admin to add it if you need it." with "Back to your SOP chapters". With the library off: "The SOP library is switched off. Ask your Admin if you need it." with "Open Getting Started". |
| **Audit** | `SOP_LIBRARY_SWITCHED`, `SOP_CHAPTER_SWITCHED`, `SOP_ROLE_CHAPTERS_SAVED`, `SOP_USER_CHAPTER_ADDED`, `SOP_USER_CHAPTER_REMOVED`. |

**Seeded defaults (fresh install; generous — Admin changes them).** Everyone: 00 Getting started, 00 Introduction, 12 Client portal, 17 Order edit & duplication, 19 Complaints, 23 Coordination. Plus:

| Roles | Chapters |
|---|---|
| Admin, Managing Director, Executive Director, System Administrator | every chapter |
| Export Executive, Sales/Export Manager, Sales Director | 01–04, 10 Amendments, 11 Disputes, 13 Document review, 24 Associates; 27 Pricing for a sales role holding `view_product_pricing` |
| Procurement Executive / Manager / Director | 05, 22 Procurement |
| Logistics Executive / Manager / Director | 06, 07, 09 |
| Accounts Executive, Finance Manager, Finance Director | 03, 08, 14 Reports, 20 Incentives, every `ca-` chapter |
| Export Compliance Executive / Manager / Director | 07, 25 Export compliance, 26 Agreements |
| Order Coordinator, Operations Manager | 01–09, 13 |
| Viewer / Auditor | 01–09, 14 |
| CA / Chartered Accountant | 08, 14, every `ca-` chapter |
| Investor (view only) | 14, 28 Divisions |

Every chapter shipped at install is configured and on. Tests: `SopAccessD266Test` / `sopAccessD266.test.js`.

## 5. The order life cycle, step by step

This section follows one export order from the first enquiry to archive. It
is built from `StageGateService`, `OrderController` (PHP
`app/src/Controllers/OrderController.php`, Node
`app/src/controllers/ordersController.js`), the order page view (PHP
`app/views/orders/show.php`, Node `app/views/orders/show.njk`) and SOPs
01–19. Domestic, Import and Merchant Trade orders follow their own, shorter
flows (6.15).

### 5.1 Two trackers on one page: 9 stages and 19 steps

**The 9 stages** (`stages_master`, one `order_stages` row per stage per
order) are the internal pipeline. Each stage row has a status: `locked` →
`in_progress` → `gate_passed` (or `skipped`). Only
`StageGateService::passAndUnlockNext()` passes a gate. It:

- refuses unless the order is `active` and the stage is `in_progress`
  (D-002; lost/complete orders refuse every gate);
- is idempotent (passing an already passed gate returns true);
- writes the audit row `ORDER_STAGE_GATE_PASSED`;
- unlocks the next stage and sets `orders.current_stage_id`.

Every gate action calls `StageGateService::isUnlocked()` **before** writing
anything, so an out-of-order request is refused before any data is stored.

| # | Stage (`stage_slug`) | What passes the gate | Gate action (route) |
|---|---|---|---|
| 1 | Enquiry & Quotation (`quotation`) | QT approved (mail off) or sent (mail on) — automatic | document approval / send |
| 2 | Buyer Purchase Order (`buyer_po`) | Buyer's PO ref + received date confirmed | `POST /orders/{id}/buyer-po` (Step 4d) or the buyer via the Buyer Action Link |
| 3 | Proforma Invoice (`pi`) | Advance cleared; on LC: LC confirmed usable | `…/payment/advance/clear` or `…/payment/lc/confirm-usable` (Step 5e) |
| 4 | Order Confirmation (`oc_production`) | Buyer acknowledges the OC (portal, link, staff record, or 48 h auto-confirm) | `…/oc-acknowledgment` (Step 6c) / cron |
| 5 | Supplier PO (`supplier_po`) | Supplier's signed PO confirmed | `…/supplier-po/signed` (Step 7d) |
| 6 | Freight Payment (`freight`) | Freight cleared (CFR/CIF); **auto-skipped on FOB** | `…/payment/freight/clear` (Step 9d) |
| 7 | Packing & BL Instruction (`bl_instruction`) | BL number + date recorded | `…/bl-issued` (Step 10f) |
| 8 | Commercial Invoice & Balance (`commercial_invoice`) | Balance cleared (and CI approved/sent) | `…/payment/balance/clear` (Step 11d) |
| 9 | Document Despatch & Closure (`closure`) | Courier tracking number entered (Close Order) | `…/close` (Step 13f) |

**The 19 steps** are what staff see on the order page, in the real order of
work, grouped in five coloured sections (D-007, D-028):

| Section | Steps |
|---|---|
| A · Order Booking (blue) | 1 Prepare Quotation · 2 Send Quotation · 3 Client Accepts Quotation · 4 Buyer's PO · 5 Proforma Invoice & Payment · 6 Order Confirmation |
| B · Procurement & Production (green) | 7 Supplier PO · 8 Production & Estimated Shipment |
| C · Shipment & Balance (amber) | 9 Freight Payment · 10 Packing & BL Instruction · 11 Commercial Invoice & Balance |
| D · Closure & Settlement (purple) | 12 Closure — Financials, Benefits & Costs · 13 Closure — Documents & Settlement · 19 Order Complete |
| E · After-Sales & Records | Amendments · 14 Additional Documents · 16 Order Feedback · 17 Complaints · 18 Disputes |

Step 15 is **Order Communication**, pinned above the sections with
Products, Payment Overview and Document Review & Approval (D-029).

**Page rules every step follows.**

- **D-001 — a stage passes only once its own document is finished:**
  approved while buyer document e-mails are held (testing mode, or the
  switch off in Settings › E-mail, D-280), sent while they go out. Internal documents (Supplier PO, BLI)
  need approval only. The gate button stays disabled and a note above it
  lists what is missing (D-023). Code:
  `DocumentGenerationService::isDocumentComplete()`.
- **D-003/D-004 — steps and sub-steps run in sequence.** A sub-step is
  locked ("Complete Step 5a first") until the one before is done or
  skipped. Every Skip needs a reason and is audit-logged. Steps 1–3 are
  independent; compliance cards never lock anything.
- **D-005 — a later step done means the earlier ones are done.**
- **D-006 — one door at a time.** After each action the page returns to
  the step being worked on.
- **Force-generate.** Generating a later document before its stage is
  unlocked needs the "Force-Generate a Document" override, with a typed
  reason (SOP 13).
- **Regenerating an earlier document** after later stages passed needs a
  confirmation and marks later stages "DATA MAY BE STALE" (D-027).

**Order statuses** (`orders.status`): `active` → `shipped_docs_sent`
(after Close Order; the buyer sees "Complete") → `complete` (Mark Fully
Settled, locked). Side exits: `lost` (Mark Lost, locked) and `cancelled`
(approved cancellation, locked). `disputed` exists in the enum, but a dispute
never changes the order status. The only way to set it is the Admin
override `POST /orders/{id}/override-status-lock` (`edit_locked_data`,
reason required, audit `FIELD_EDIT`); code that lists open orders treats
`disputed` like `active`. `is_locked = 1` on complete / lost / cancelled; every order-changing
route goes through `OrderLockGuard` (D-019).

### 5.2 Before the order: enquiry and intake

**Path A — the buyer fills the public form.** `/quotation-details` (no
login; rate-limited). It asks for company, billing (structured address),
consignee, notify party, Incoterm, container type, port, CoO type — never
product details. The submission lands in `client_intake_submissions` and in
**Orders › Quotation requests** (`/client-intake`, live count
badge). Staff choose:

- **Accept & Create Client** → creates the `clients` row (with its
  **Buyer Inquiry Ref** `client_unique_number`, e.g. `NC/SC/26/2009001`)
  and opens it. It creates **no** order.
- **Reject** with a reason (≥ 10 characters).
- **Resend Link** — a one-time self-correction link (7 days).

If the buyer arrived through an associate's link or code, the associate is
carried as a hidden field; the buyer verifies the e-mail with a 6-digit code
(valid 30 minutes). Accept is refused until verified. On Accept the client
is linked to the associate for 12 months (D-217). A buyer never sees an
error caused by an associate code (D-246).

**Path B — staff create the client** at **Clients › Add Client**
(`/clients/create`). One e-mail per client (D-129, unique index). A client
can be **duplicated** to a new Buyer Inquiry Ref (save refused if nothing
changed).

**Billing address (D-346).** The Add / Edit / Duplicate Client forms have no
single "Billing Address" box: Billing Address Line 1 \*, Line 2, City /
Town \*, Postcode, Billing Country \* (required in the browser and on the
server: "Company legal name, Billing Address Line 1, City / Town and Billing
Country are required."). `clients.billing_address` — still what documents,
the Zoho payload and domestic documents read — is always composed from the
parts by one shared helper, `ClientAddress::compose()` (Node
`helpers/clientAddress.js`), used by the client forms and both public intake
forms (`ClientIntakeController`, `PiIntakeController`): "Line 1, Line 2,
City Postcode, Country" (city and postcode joined by a space, empty parts
skipped). A legacy client with only the old single box shows "Address on
file (old single box): … — copy it into the boxes above." under the address
fields on Edit; saving needs the parts.

Code: `ClientIntakeController`, `ClientIntakeReviewController`,
`ClientController` (Node `clientsController.js`), `AssociateIntakeService`.

### 5.3 Creating the order

From the client page, **Add Order** (`/orders/create`, `manage_orders`).
On save (`OrderController::store`):

- The order reference is reserved atomically: `NC/SC/ORD/{YY}/{DDMM}{NNN}`,
  a daily counter, e.g. `NC/SC/ORD/26/0411001` = first order of 4 Nov 2026
  (D-248, `ReferenceNumberService`, `reference_sequences`). Another
  division prefixes its code.
- **The order is standalone (D-186):** Incoterm, currency, ports, client
  address/consignee/notify party, and the **payment preset's terms**
  (`preset_name`, `advance_pct`, `payment_method`, `balance_pct`,
  `balance_trigger_option`, `balance_days`, wording, `show_bl_instructions`)
  are copied onto `orders`. Several splits can run at once (D-072). The
  preset cannot be changed later on Edit Order — only by Amendment (D-022).
- The creator becomes the first assignee (`order_assignees`, D-118). An
  order always keeps at least one assignee.
- Nine `order_stages` rows; Stage 1 `in_progress`.
- A row for every active compliance task type and export-benefit scheme
  (`order_compliance_tasks`, `order_export_benefit_schemes`, D-095).
- The division is fixed (`orders.division_id`).
- Insurance is cleared on CFR/FOB orders (D-049).
- A client introduced by an associate links `orders.associate_id`; the
  commission level is fixed at the PI (D-245).

**Order lines** (`order_products`): line type **Goods** (HS code from
`hs_codes`), **Service** (SAC code 99xxxx, invoiced on the separate Export
Service Invoice), or **Charge** (D-215). On a USD order a goods line must be
**picked from Products** while the pricing setting is on; the quote price of
whoever sells (staff or the associate's level) fills in (D-245 P2). Below
the seller's floor the line needs MD approval; below the product's minimum
price it is refused (6.3).

**Any unit, piece size and conversion (D-270, owner point 25, 11 Nov 2026).**
A line may use any unit of its category's list (`product_categories.allowed_units`;
Natural Stone seeded `CBM,SQM,SQFT,PCS,TON,RMT`; the stone list applies to goods
lines only, charges keep any unit; Set / Lot can be added by the MD). A goods line
carries a piece size in cm, `order_products.piece_length_cm / piece_width_cm /
piece_thickness_cm` (DECIMAL(7,2), up to 10000, 2 decimals). For every price check —
saving (`OrderLinePricing::saveRefusal`), the line badge and Price check
(`checkLine`), the MD's approval screen and the QT/PI block reason — the line price is
converted into the product's reference unit (`LineUnits` / `lineUnits.js`):

| Line unit | Needs | Converted to the reference (per CBM) |
|---|---|---|
| CBM | — | same unit |
| SQM / SQFT | thickness | 1 SQM × thickness; 1 SQM = 10.7639 SQFT (no thickness needed between the two) |
| PCS (also NOS) | length, width, thickness | one piece = L × W × T |
| RMT | width, thickness | 1 m × W × T |
| Ton (TON, MT) / kg | — | weight per CBM: `product_categories.weight_t_per_cbm` (Natural Stone 2.7), product override `catalog_products.weight_t_per_cbm` |
| Set, Lot, other | — | cannot be converted → status "no price", the line goes to the MD |

A line in the reference unit itself needs nothing (as before). The thickness may come
from the Thickness option (its mm). The Thickness (cm) box and the Thickness option
follow each other, in the browser and on save (`OrderLinePricing::syncThickness`): 3 cm
chooses "30 mm"; from the product's per-CBM thickness (50 mm) up, the "priced per CBM"
option ("Other, 50 mm or more"), whose thickness is then the line's own — so 13 cm needs
no manual price; a thickness with no option below 50 mm is refused beside the field.

**Thickness and Size / format are chosen, never preselected (D-348, 12 Nov 2026, owner
"go").** When a product is picked on a line (New Order form, order page line panel), the
specification drop-downs named exactly "Thickness" and "Size / format" (`REQUIRED_SPEC_NAMES`
in `OrderLinePricing` / `orderLinePricing.js`; exact name match) start on "Choose…" —
`required`, label "*", `data-required-message` "Choose the Thickness — the price depends on
it." shown beside the field by app.js. Every other specification keeps its base option. A
saved or sent-back selection keeps its options; a selection that lacks one of the two shows
"Choose…"; a line saved with no options at all shows the base options as before. While either
is on "Choose…" no quote price is filled (an automatically filled one is taken back, a typed
one stays — D-339), no floor note shows, and the hint reads "Choose the Thickness and Size /
format to see the price."; the spec-json carries only the chosen options. Typing Thickness
(cm) sets the Thickness option only while it is on "Choose…" or was set that way before —
never over a choice made by hand: exact match (2 → 20 mm, 1.5 → 15 mm), otherwise from the
per-CBM thickness (5 cm) up "Other, 50 mm or more", below that none; a green note "set from
Thickness (cm) 13" shows under it, and the price is fetched as if the option had been picked.
On save (New Order, order page add and edit, both stacks) `requiredSpecsRefusal()` refuses a
goods line picked from Products whose product offers either specification without it chosen:
"Line N: choose the Thickness and the Size / format — the price depends on them." (only the
missing one named, "… depends on it."). Not affected: lines typed by hand, products without
those specifications, a line from an associate's price request, and an edit that leaves the
product and options of a saved line unchanged.

**"Monument (cut to size)" and the installation defaults (D-347, 12 Nov 2026, owner "add this … in
seed").** Natural Stone › Size / format has the option **Monument (cut to size)** at 0 % — thick
pieces cut to an exact size (headstones, bases, kerbs), sold per CBM; "Cutter slab" stays −10 % and
"Gangsaw slab" is for thin slabs. A fresh install (seed.sql, both stacks) also starts with e-mail
Testing mode **on** (`email_testing_mode` 1 — switched off on go-live day), the team incentive
program **on** (`incentive_settings.program_enabled` 1), other costs to FOB **5.5 %** and margin
**15 %** (`pricing_settings`). Migration `2026-11-12i` adds the option and, in Part 2 (overwriting),
sets the same four values on an existing database.
The old "50 mm and above: set the unit to CBM" refusal is gone. A missing size is
refused beside the field on save ("Enter the length (cm) — needed to compare a price
per piece with the product's price per CBM."); a saved line without it gets the status
`needs_size` — badge "Size needed", it holds the QT/PI ("Line N needs its piece size to
check its price — …") and is **not** sent to the MD. The line shows its figures per its
own unit and the product's reference figures ("Product reference per CBM: quote … ·
lowest … · your price = USD 2,644.23 / CBM (1 piece = 0.1872 CBM)"). The MD's approval
also holds only for the size it was given for (the size joins
`price_approved_selection`). The Dimensions text is filled "180 × 80 × 13 cm" from the
sizes (browser; on save when left empty) and stays editable; it prints on the documents
as before — no document change. `/pricing/line-quote` takes `unit` and the three sizes
and returns the quote and lowest per the line's unit plus the reference figures.

**Products section (D-273, owner point 17).** The order page's Products card is one
compact table (`orders/_products_table.php` / `.njk`): # · product and its options as
labels (D-271) · size (Dimensions text) · qty · unit · price · total · price-check badge ·
Edit / Duplicate / Delete. Above it a summary bar
(`OrderLinePricing::summary()`, e.g. "2 lines OK · 1 line needs MD approval (line 3) —
the Quotation waits for it." with a **Show** link to Price check); below the goods a
totals row with the goods value, about … CBM and containers of 8 CBM
(`OrderLinePricing::totals()`, `LineUnits::CONTAINER_CBM`; lines without a size or
quantity are named as not counted); charges and services follow in a second table of
the same style. **+ Add product** is always visible to whoever may edit lines. Edit /
Add open a side panel (`aside.line-panel`) in four steps — 1 Product, 2 Options (grid of
3 columns), 3 Size and quantity (L, W, T, unit, qty), 4 Price with the reference box and
line total — and a closed **More** with description, Dimensions text, finish, line
type, HS / SAC code and category. The order page is one view for both templates; the
Kanban board has no products section. People without edit rights see the same table
without buttons. The New Order form has the same three size fields per row.

**Payment terms needing approval (D-075).** If the preset has "Requires MD
approval" (seed: Established Buyer — Post-BL, Extended Terms — Post-BL, LC),
Step 1 shows a yellow box and the QT cannot be generated or sent until a
holder of `approve_payment_terms` approves (`…/payment-terms/approve`). The
creator cannot approve their own order's terms unless the `payment_terms`
self-approval rule allows it (D-216/D-221). Never on a cancelled or lost
order (D-205). Wording (D-269): the box reads "Payment terms need approval
first"; someone who cannot approve sees "Waiting for approval by someone
who can approve payment terms (Admin, Managing Director, …)", and a refused
Generate/Send says the same. The role names are read live from the roles
that hold `approve_payment_terms` (enabled, in role order;
`PaymentTermsApprovalService::approverRoleNames()` /
`paymentTermsApprovalService.approverRoleNames()`), so a role given or
denied the permission in Roles & Permissions is named, or not, at once.
Seed: Admin, Managing Director, Executive Director, Sales/Export Manager,
Sales Director, Finance Director.

### 5.4 Step-by-step reference

For each step: what the user does, the gate that must pass, what is stored,
and the document produced. Document numbers use a 2-digit year (D-177),
e.g. `SC/PI/26/0511001`.

#### Step 1–3 · Quotation (Stage 1)

| | |
|---|---|
| User does | 1a **Generate Quotation** (PDF; tick DOCX for an internal Word copy). Optional Annexure A (**Edit Annexure A ›** opens `/orders/{id}/annexure`: product specification, additional terms or both, one **Save all changes**; Annexure B as shipped via its button from the Commercial Invoice step on, §8.8). A line can say "As per annexure" (§8.8). Take the QT through review/approval (SOP 13). Step 2 has no button: send happens in Document Review (mail on) or approval counts as sent (mail off). Step 3 "Client accepts" is outside the system. |
| Gate | Price check passes (no line below the seller's floor without MD approval; none below minimum; no product without a price). Payment terms approved if required. Stage 1 passes **automatically** when the QT is approved (mail off) or sent (mail on). |
| Stored | `documents` (status draft → in_review → approved → sent), `document_reviews`, `file_store` (folder `01-quotation/generated/`), `orders.quotation_date`, `quotation_valid_until`. |
| Document | **QT** Quotation `SC/QT/{YY}/{DDMM}{NNN}`; **ANNEXA** Annexure A. |

#### Step 4 · Buyer's PO (Stage 2)

| | |
|---|---|
| User does | 4a Generate Buyer PO (NexaCrest's PO-style acceptance document, with milestones and the order's own payment wording). 4b (optional) Send the **Buyer Action Link** — a no-login page where the buyer downloads the approved Buyer PO and uploads the signed copy with their PO number. 4c Attach the signed copy (staff or buyer). 4d **Confirm Buyer PO Received**: PO reference + date received (not future, not before order creation, D-148). |
| Gate | Buyer PO document approved/sent. With `wet_signature_required_buyer_po` on (default), at least one signed copy attached (D-025). If the buyer completes 4b themselves, 4c/4d complete automatically. |
| Stored | `orders.buyers_po_ref`, `buyer_po_received_on` (Day 1 of the associate's 10-day window); `order_buyer_po_documents` (every upload kept); token hash in `orders.buyer_action_token_hash` (encrypted, D-128). |
| Document | **BUYERPO** `SC/PO/…` |

#### Step 5 · Proforma Invoice & Payment (Stage 3)

| Sub-step | Cash advance order | LC order |
|---|---|---|
| 5a | Generate PI Form Link (`/pi-details/{token}`) | same |
| 5b | Apply the client's PI-stage confirmation (PI Intake Review `/pi-intake-review`), or **Skip PI Form** with a reason (D-009) | same |
| 5c | **Generate Proforma Invoice** (price check again) | same |
| 5d | **Record Advance Remittance** (`manage_payments`) | **Record LC Details**: issuing bank, LC no., amount, charges, copy |
| 5e | **Mark Advance Cleared** (`clear_payments`) — gate | **Confirm LC Usable** (`clear_payments`) — gate |

- **Gate:** PI approved/sent; the remittance recorded before it is cleared
  (D-077); recording and clearing by different permissions (D-076).
- **Recording a remittance (D-078, D-227, D-244).** The form pre-fills the
  advance due and shows "Buyer to pay now". Enter the amount received, the
  **INR credited by the bank** (the rate is worked out from it), and the
  **bank reference (UTR/SWIFT, required, D-212)**. More than the order value
  is refused. If the amount differs from what is due, pick a reason under
  "Why is it different?" (`remittance_difference_reasons`): bank charges
  (counted as received in full; charges become an order cost), buyer paid
  less, or buyer paid more. A short payment is cleared only by a holder of
  `approve_short_payment` with a reason.
  *Example:* order USD 7,500, 40% advance = USD 3,000. The bank credits
  ₹2,52,300 → rate 84.10. Description written by the app: "Advance received
  from <buyer> for order NC/SC/ORD/26/0411001 · USD 3,000.00 at ₹84.10 ·
  against Proforma Invoice SC/PI/26/0511001 · bank ref UTR…" (staff notes are
  appended, never replace it).
- **Clearing** unlocks Stage 4 and **creates the client portal login**.
- **Stored:** `order_payment_status` (amount, received/cleared times, INR
  actual, rate, bank and FIRC refs), `order_payment_receipts`,
  `order_payment_shortfalls`, `order_lc_details`, `pi_intake_submissions`,
  `client_payment_reports` (buyer self-reports — information only).
- **Payment Action Link** (D-080): a no-login page for the buyer to send UTR,
  amount, date and a required screenshot — one payment at a time (advance
  at 5d, freight at 9c, balance at 11c). Information only; staff still
  record from the bank statement.
- **Document:** **PI** `SC/PI/…` with the amber "ADVANCE PAYABLE NOW" box
  (D-050) or "NO ADVANCE PAYABLE" on LC (D-052).
- **D-262 freeze** happens when the first PI is approved (5.5).

#### Step 6 · Order Confirmation (Stage 4)

| | |
|---|---|
| User does | 6a Generate OC → approve/send. 6b **Generate OC Confirmation Link** (a button, never automatic; 6b ticks only after it is pressed, D-242). 6c Record the buyer's acknowledgement: the buyer clicks "I have read order confirmation SC/OC/…. It is correct — please go ahead" on the link (portal: "Yes, this is correct — please go ahead", D-330), or staff record an e-mail/phone acknowledgement with an evidence note. With mail off, staff press **Mark Order Confirmation Sent to Buyer** to start the clock. |
| Gate | OC issued (approved/sent). Then acknowledgement by any path. If none within `oc_ack_auto_confirm_hours` (48) after the OC was sent and the link generated, the cron `auto_confirm_oc_acknowledgments` confirms it. With `oc_ack_override_restricted` on, staff recording needs `override_buyer_acknowledgment`. |
| Stored | `order_oc_acknowledgments`, `orders.oc_link_generated_at`. |
| Document | **OC** `SC/OC/…` — order value, advance due, advance actually received, balance = value − actual advance (D-053). |

From here edits to order details need `edit_order_post_confirmation`
and a reason (logged `ORDER_EDITED_POST_CONFIRMATION`). Product lines (and the
currency) were already fixed when the first Quotation was approved (D-342, §8.10):
they change only through a Products & prices amendment.

#### Step 7 · Supplier PO (Stage 5) — Procurement

| | |
|---|---|
| User does | First card: **Compliance — with the Supplier PO** (Due Diligence, ECGC by default). Optional **Purchase Requirements** card (Sales raises, Procurement quotes/awards — 6.6). 7a **Save Supplier PO Terms** (D-361: choose the supplier from the table of their quoted prices for the line's product — the price fills in; a different agreed price needs a reason, kept with the PO; supplier from the Suppliers directory, material spec, GST %, advance %, delivery location from the supplier's factory addresses) — creates the record and reference. 7b Generate Supplier PO. 7c Send the **Supplier Link** (no login, 60 days) and/or attach the acknowledgement, or **Skip — supplier did not acknowledge** with a reason. 7d **Confirm Supplier Signed & Returned PO** — gate. 7e **Supplier Advance Paid**: add the supplier's bill (Proforma or Tax invoice, D-091), then **₹ Mark Paid** with the UTR (`manage_supplier_payments`). At 0% advance 7e completes by itself (D-012). |
| Gate | Supplier PO approved; with `wet_signature_required_supplier_po` on, an acknowledgement file or the skip. Needs `manage_supplier_po`. After the gate, **FOB orders auto-skip Stage 6** (`maybeAutoSkipFreightStage`, audit `ORDER_STAGE_GATE_SKIPPED`). |
| Stored | `order_supplier_po` (with its own snapshot of the supplier, D-086), `order_supplier_po_documents`, `order_vendor_bills`, `order_vendor_bill_payments`, `order_compliance_tasks`. |
| Document | **SUPPO** `SC/SPO/…` — internal, never to the buyer; the buyer never learns the supplier. |

#### Step 8 · Production & Estimated Shipment

| | |
|---|---|
| User does | 8a Post production status text, estimated shipment text and **Estimated Shipment Date** (`manage_supplier_po`). Changing the date posts a message to the client's Order Updates thread. Record the **Pre-Shipment Inspection result** (Passed/Failed, date, agency, report file; `manage_shipping`). Failed notifies MD, ED, Admin. |
| Gate | Ticked only after a real entry (D-013). No stage gate. |
| Stored | `order_production` (audit `PRODUCTION_STATUS_UPDATED`, `EST_SHIPMENT_DATE_UPDATED`), `order_inspections`, `order_inspection_fees`. A daily reminder fires 7 days before the estimated date when a PSI task is open. |

#### Step 9 · Freight Payment (Stage 6) — CFR/CIF only

| | |
|---|---|
| User does | 9a Save Freight Terms (confirmed rate, insurance on **CIF only**, freight forwarder from Logistics Partners; `manage_shipping`). 9b Generate **Debit Note**. 9c Record Freight Remittance (`manage_payments`). 9d **Mark Freight Cleared** (`clear_payments`) — gate. |
| Gate | Debit Note approved/sent; remittance recorded. On **FOB** the card is greyed "Not applicable — FOB" (D-021). |
| Stored | `order_freight`, freight columns of `order_payment_status`. Due date = Debit Note date + 3 calendar days; overdue alert the day after (D-051). |
| Document | **FDN** "Debit Note" `SC/DN/…` (older ones `SC/FDN/…`); GST line "NIL — pure cost reimbursement" (D-062). |

#### Step 10 · Packing & BL Instruction (Stage 7)

| | |
|---|---|
| User does | First card: **Compliance — before shipment** (Fumigation, Phytosanitary, PSI). 10a **Supplier Balance Paid** (bill + payment; waits for 7e when an advance was planned). 10b Save Packing & Crates (crate HS code comes from the product, D-036). 10c Generate Packing List. 10d Save Shipping Details (line, vessel, voyage, ETD/ETA, containers, seals). 10e Generate BL Instruction Sheet. CHA **Draft BL review** loop (rounds, approve / request revision; never blocks). 10f **Confirm BL Issued** (BL no. + date, optional scan) — gate. Then **Shipping Bill** (number, date, customs exchange rate). |
| Gate | PL approved/sent; BLI approved. **Shortfall tolerance:** if packed quantity is short by more than the tolerance %, the buyer's written approval must be uploaded (hard rule). **Shipment gates** (`ShipmentGateService`): the BL waits while (a) a **before-shipment** balance (Standard — New Buyer, Extended Terms — Before Shipment) is not cleared, or (b) a Pre-Shipment Inspection task (fixed key `pre_shipment_inspection`, D-262 gap 7) is not Passed. A holder of `override_shipment_gates` (MD, ED, Admin) may release with a reason ≥ 10 characters (`order_shipment_gate_overrides`). |
| Stored | `order_packing`, `order_crates`, `order_shipping` (`bl_number`, shipping bill and customs rate), `order_draft_bl_reviews`. |
| Document | **PL** `SC/PL/…`; **BLI** `SC/BLI/…` (internal). |

#### Step 11 · Commercial Invoice & Balance (Stage 8)

| | |
|---|---|
| User does | 11a Generate CI (actual shipped quantities; on CFR/CIF a reminder until the scanned BL is attached, D-037). 11b **Mark Scanned BL Copy Sent to Buyer** — on post-BL and LC orders this **starts the balance due date** = that day + balance days (D-218 G-2). 11c Record Balance Remittance. 11d **Mark Balance Cleared** — gate. |
| Gate | CI approved/sent; every milestone cleared first; remittance recorded. **Before-shipment orders:** 11c/11d open from Step 9 onward; "Mark Balance Cleared (before shipment)" clears without passing Stage 8, and later "Complete Step 11 (unlocks Stage 9)" passes it once the CI is approved. |
| Stored | balance columns of `order_payment_status`, `balance_due_date`. Overdue reminders daily after the due date. |
| Document | **CI** `SC/CI/…` (balance clause follows the order's own terms, D-054; date today or backdated, never future, D-055). Optional **ESI** Export Service Invoice for service lines. The approved CI goes to Zoho Books at the shipping-bill rate (D-165). |

#### Step 12 · Closure — Financials, Benefits & Costs

Guided sub-steps (D-097), `manage_order_financials`:

- 12a **Order Costs** (`order_cost_entries`, `order_expenses`,
  `order_travel_expenses`, inspection fee, LC & bank-loan costs, courier).
  Each cost says what it was paid from (D-103) and carries a D-212
  description.
- 12b **Export Benefit Eligibility** — answer every scheme (Eligible / Not
  eligible) (`order_export_benefit_schemes`).
- 12c **Review Profit & Complete Step 12** (`OrderProfitabilityService`).
- 12d **Team Incentive** (MD; 6.5).

Step 12 can be skipped with a reason; values stay blank, and "Reopen Step 12"
exists (D-098).

#### Step 13 · Closure — Documents & Settlement (Stage 9)

| Sub-step | What | Permission |
|---|---|---|
| 13a | Generate **COO Prep Sheet** (can be done as soon as the BL is issued, before the balance clears) | `generate_documents` or `generate_compliance_documents` |
| 13b | Record **original BLs received** from the CHA (1–10) | `manage_shipping` |
| 13c | Save **BL Endorsement** details | `manage_shipping` |
| 13d | Generate / print **BL Endorsement** (auto-approved, visible to the buyer) | `manage_shipping` |
| 13e | Mark original BLs **endorsed** | `manage_shipping` |
| 13f | **Close Order** — courier tracking no.; optional courier company, charges (₹0–1,00,000) and "paid from" (books a Courier Charges cost, D-243) — **Stage 9 gate** | `close_orders` |
| 13g | **Government benefits — claimed & received** (one row per claim; skip with reason; correct/delete with reason, D-099/D-100) | `manage_order_financials` / `manage_export_benefit_claims` |
| 13h | **Mark Fully Settled** — final lock | `settle_orders` |

**Close Order gates:** BLI and COOPREP approved/sent (D-017); original BLs
received and endorsed recorded (5 Nov 2026 decision, supersedes D-018). A
holder of `edit_locked_data` may override with a reason ≥ 10 characters
(`CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE`). Result: status `shipped_docs_sent`;
the buyer sees "Complete"; the order stays unlocked for benefits.

**Mark Fully Settled is blocked, in this order, while:** (1) a benefit claim
awaits receipt; (2) a compliance task is Not Started or Pending Approval
(D-015); (3) a scheme is still Pending; (4) an Eligible scheme has no claim.
Each can be overridden by `edit_locked_data` with a reason. A payment without
a FIRC/eBRC reference is only a warning (D-234); unpaid vendor bills are only
a warning (D-020). On success: status `complete`, `is_locked = 1`, and the
**CAFIN is generated automatically** (D-108; a failure never blocks
settlement).

**Documents:** **COOPREP** (internal, no reference no., D-069), **BLE**
`SC/BLE/…`, **CAFIN** `SC/CAFIN/…` (internal).

#### Steps 14–19

- **14 Additional Documents** — free-form uploads (`manage_orders` or
  `upload_order_documents`); removing one needs a reason ≥ 10 characters
  (D-152).
- **15 Order Communication** — two-way chat with the client
  (`order_comments`, attachments); with mail off the client is not e-mailed.
  Beside each staff post, staff see what happened to its e-mail (D-268,
  stored in `order_comments.email_status`): "e-mailed", "not e-mailed —
  e-mail sending is switched off in Admin", "not e-mailed — no client
  e-mail on file" or "not e-mailed — the e-mail could not be sent" (posts
  saved before D-268: "not e-mailed"). Client posts carry no note.
- **16 Order Feedback** — 1–5 stars + testimonial, after documents are sent
  (`order_feedback`); staff can record it for the client.
- **17 Complaints**, **18 Disputes** — 5.9.
- **19 Order Complete** — green once Mark Fully Settled is done.

### 5.5 What freezes at PI approval (D-262 snapshot)

When the **first PI of an order is approved** (`finalizeApproval` of a PI),
`OrderSnapshotService::freeze()` (PHP
`app/src/Services/OrderSnapshotService.php`, Node `orderSnapshotService.js`)
writes onto the order: `orders.frozen_at`, `frozen_snapshot_json`,
`quantity_tolerance_pct`, `payment_terms_requires_md_approval`,
`pricing_fx_rate_at_pi`, plus `order_products.zoho_item_id` per line.

| Frozen with the order | Effect of a later Admin change |
|---|---|
| T&C clauses of every document type (title, text, set, show/hide), validity days | Not printed on this order |
| Quantity shortfall tolerance % | Packing gate and printed tolerance stay as agreed |
| Company details: bank, branch, account, SWIFT, IFSC, LUT, RCMC, IEC/PAN, GSTIN, addresses, director, RBI purpose codes, disclaimer | Not printed on this order (incl. AMD, BLE, CAFIN) |
| Product-category rows (packing words, quality documents, clause set) | Not applied |
| Preset "Requires MD approval" flag | Neither blocks nor frees this order |
| Pricing exchange rate (USD-per-unit commission/incentive on a non-USD order) | Commission and incentive unchanged |
| Each line's Zoho item | CI goes to Books with the approved item |

Every document run (`generate`, `finalizeApproval`, `markSuperseded`,
`generateAmendment`, `generateCaInternalAnnexure`, `generateBlEndorsement`)
runs inside `OrderSnapshotService::within()`, which overlays the frozen
values (PHP: a static overlay restored in `finally`; Node:
AsyncLocalStorage). Before the freeze nothing changes.

Other things fixed at the PI: each priced line's figures and the
associate's commission level (D-245). A vendor bill keeps the vendor's name,
GSTIN, PAN; an associate payout keeps its GST % and GSTIN (gaps 8–9).

**Real change, e.g. new bank account:** the MD uses **Refresh company
details on this order** (Step 1; `POST /orders/{id}/company-details/refresh`;
`manage_company_settings` + MD), reason ≥ 10 characters, audit
`ORDER_COMPANY_DETAILS_REFRESHED`. Only company details change; documents
already issued stay as they are. Tests: `OrderSelfContainedTest` /
`orderSelfContained.test.js`.

**MD name or title changed in Settings › Company (D-349, 12 Nov 2026, owner confirm 1 +
confirm 2).** Saving a change to `md_name` or `md_title` (all the usual checks first: reason,
value, protected, Super Admin) does not save straight away: the submission is kept in the
session (30 minutes) and `GET /settings/md-change` shows "The Managing Director's name changed
from "<old>" to "<new>". Apply it to:" with two ticked boxes — **N open orders** (status not
`complete` / `lost` / `cancelled`, frozen, whose `frozen_snapshot_json.company.md_name` /
`md_title` still equals the old value; only the count shows, and "Show the orders" — a `<details>`, closed — opens their references (D-350)) and **N documents still in
Draft / In review** whose kept signatory is the company fallback (`signatory_user_id IS NULL`,
`signatory_name_snapshot` / `signatory_designation_snapshot` equal to the old value). When the
default signatory (`company_default_signatory`) is a protected founder login whose `users.name`
differs from the new name, an info line says: "The name printed under the signature comes from
the founder login, which the app cannot change on purpose. Change it with the founder SQL block
in the README (First-time setup)." (the app never renames a founder; the DB trigger would refuse
it). Then "Not changed: N documents already approved or sent; N closed and cancelled orders."
and two buttons: **Save and apply** and **Save setting only** (`POST /settings/md-change?choice=
apply|setting_only`, `manage_company_settings`; the choice travels in the button's `formaction`,
kept after D-350 made app.js send a pressed button's name/value too). "Show the documents" opens
the document numbers the same way (D-350). Both replay the kept submission through the same
checks and save the setting; "Save and apply" then runs `MdNameSyncService::apply`
(`mdNameSyncService.apply`) in one transaction: the ticked orders' snapshot keys, the ticked
documents' signatory snapshots and, on those documents, `company_snapshot_json` and the stored
`render_context_json` (`company.md_name/md_title`, `signatory.name/designation`, where they still
show the old value — the FINAL copy is re-rendered from it on approval; the DRAFT PDF already
made keeps the old wording until it is regenerated). Audit: `ORDER_MD_DETAILS_SYNCED` (orders,
`frozen_snapshot_json.company.<key>`, old → new) and `DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED`
(documents, column, old → new), reason "D-349 apply from Settings › Company: <reason>".
Approved and sent documents, and closed / lost / cancelled orders, never change. Going back to
Settings saves nothing.

**Signatory renamed in Admin › Users (D-349).** When a non-protected, signatory-eligible user's
name is changed on Users › Edit, `MdNameSyncService::userRenamed` puts the new name on
`signatory_name_snapshot` (and `render_context_json.signatory.name`) of that user's documents
still in Draft / In review; audit `DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED` per document. The success
message says how many. Approved and sent documents never change; founder logins cannot be edited
here at all.

### 5.6 Incoterm effects (FOB / CFR / CIF)

| | FOB | CFR | CIF |
|---|---|---|---|
| Who pays freight | Buyer, own forwarder | NexaCrest, recovered by Debit Note | NexaCrest, recovered by Debit Note |
| Insurance | Buyer | **Buyer** (prints "NIL — buyer arranges insurance (CFR)") (D-049) | NexaCrest; insurance box shown only here |
| Stage 6 / Step 9 | Auto-skipped; greyed "Not applicable — FOB" | Normal | Normal |
| Indicative freight/insurance on the order | Hidden | Freight only | Freight + insurance |
| Documents | Freight and insurance print "NIL" (D-048) | "Freight is paid separately by Debit Note"; Buyer PO row "Freight: invoiced separately … (insurance arranged by the buyer)" | "Freight & Insurance: invoiced separately by Debit Note before shipment" |
| CI | Raised before shipment; no BL-copy reminder | Raised against the BL; reminder until scanned BL attached | same as CFR |
| Profit | — | Freight leg in revenue; insurance excluded | Freight + insurance in revenue (converted to INR, D-225) |
| Logistics bills | Allowed on every incoterm | A **Final** logistics bill waits for 9d freight cleared (D-090) | same |

### 5.7 Payment presets

`payment_presets` (Settings › Lists › Payment presets, `manage_payment_presets`). All
five seeded presets are **protected** — unlock through Field Protection
first (D-073). Preset names are internal and never shown to the client.

| Preset | Advance | Method | Balance | Balance trigger | Days | Needs MD approval | BLI rows |
|---|---|---|---|---|---|---|---|
| Standard — New Buyer (default) | 40% | Cash advance | 60% | Before shipment | 3 | No | Yes |
| Established Buyer — Post-BL | 40% | Cash advance | 60% | Against BL | 7 | Yes | Yes |
| Extended Terms — Before Shipment | 30% | Cash advance | 70% | Before shipment | 3 | No | Yes |
| Extended Terms — Post-BL | 30% | Cash advance | 70% | Against BL | 7 | Yes | Yes |
| Irrevocable Letter of Credit at Sight | 0% | LC | 100% | Against BL | 7 | Yes | Yes |

Zero advance is only for LC (D-074). Buyer deadlines count **calendar
days** (D-051).

*Worked example (Standard — New Buyer, USD 10,000 FOB):* advance USD 4,000
due by the PI's valid-until date; balance USD 6,000 due before shipment —
the BL step stays locked until it is cleared. The PI prints the balance as
payable within 3 calendar days of the Shipment Readiness Confirmation. *Established Buyer — Post-BL:*
the balance USD 6,000 has no date until Step 11b; if the scanned BL is sent
on 10 Dec, the balance is due 17 Dec.

### 5.8 LC orders

- Preset "Irrevocable Letter of Credit at Sight" (`payment_method = LC`).
- The PI, QT and OC never show "0%": "NO ADVANCE PAYABLE — the full FOB
  value … is payable by Letter of Credit" (D-052).
- 5d **Record LC Details**, 5e **Confirm LC Usable** (passes Stage 3 and
  creates the portal login). No Payment Action Link at 5d.
- **LC dates** (issued, last shipment, presentation deadline, expiry) are
  validated (none after expiry, none on/before issue) and each is reminded
  once, 7 days before, by the daily alerts job.
- **LC amendments** are listed (`order_lc_amendments`).
- The bank's payment under the LC is recorded in **Step 12a → LC Payment
  Received**; a bank loan against the LC too (`…/lc-received`,
  `…/lc-details`). CAFIN counts LC money as received (D-112).
- The balance due date starts at Step 11b (scanned BL sent).
- An LC order does not wait for a before-shipment balance at the BL step.

### 5.9 Amendments, complaints, disputes, cancellation, Mark Lost

**Amendments** (SOP 10, `AmendmentController`, `AmendmentService`,
`amendments`). Change **payment terms** after the PI is issued — or, since D-342,
the order's **products and prices** after its first Quotation is approved
(`amendment_type` 'products', `ProductsAmendmentService`; §8.10).

1. Raise a request (`manage_orders`): reason ≥ 10 characters, requested by
   importer or NexaCrest, only the terms that change, optional effective
   date. Reference `SC/AMD/{YY}/…`.
2. **MD-approve** (`approve_documents`) or reject; not your own request
   unless the `amendment` self-approval rule allows.
3. Generate the Payment Terms Amendment Agreement (**AMD**, signatory MD by
   default).
4. **Upload signed copy & activate** → the order's live terms update at once;
   later documents carry an amendment note.

No stage gate is affected. The buyer has no portal screen for it.

**Products & prices amendment (D-342).** Once the order's first Quotation is
approved its lines are locked (section 8.10); the only way to change pieces,
quantity, size or price, or to add or remove a line, is
**Amendments › New amendment — Products & prices**
(`/orders/{id}/amendments/products/new`): a price only goes down, the MD
approves, the AMD document (PDF) prints the amended table, the buyer signs,
the signed copy activates it. The stage does not change.

**Complaints** (SOP 19): a process issue without intent to cancel. Always
available to the buyer ("Tell us what we could do better"; was "Report a Process Issue"). `manage_complaints`
(status) and `respond_to_complaints` (reply). Tables `order_complaints`,
`order_complaint_replies`.

**Disputes** (SOP 11): the buyer wants to cancel / not proceed. Response due
date = 10 **working** days (`company_holidays`, `WorkingDaysCalculator`).
`manage_disputes` (log, status, evidence, client button) and
`respond_to_disputes` (reply thread). The buyer can raise one only if
"Show 'Ask to cancel this order' (dispute) button" is ticked for that order (the buyer's button was "Raise a Dispute" before D-330). A dispute never
changes stage or status and never exempts the order from Close Order gates.
Tables `disputes`, `dispute_documents`, `dispute_replies`.

**Cancellation** (D-082, D-258), `order_cancellations`:

1. **Request Cancellation** with a reason (`close_orders`).
2. A holder of `edit_locked_data` approves (choosing *No refund*, *Refund
   pending* or *Refund already completed*) or rejects. Not the requester,
   unless the `cancellation` self-approval rule allows.
3. Only while the order is `active` (or disputed). Status → `cancelled`,
   locked.
4. A refund is completed only by **Record refund paid** with date and bank
   reference (D-212); amount > 0 and ≤ what the buyer paid.

**Mark Lost** (`close_orders`, `…/mark-lost`, reason required). Refused when:
the order is not `active`; a BL number exists (goods shipped — use Close
Order); a cancellation is pending; any payment was received or the LC was
confirmed (use Request Cancellation, D-258). Result: status `lost`, locked,
reported as lost.

### 5.10 Editing, duplicating, archiving

- **Before Stage 4**: `manage_orders` edits details, and lines until the first
  Quotation is approved.
- **Stage 4+**: `edit_order_post_confirmation` + reason
  (`ORDER_EDITED_POST_CONFIRMATION`) for the details.
- **Lines and currency after the first approved Quotation** (D-342): refused for
  everyone, overrides included — Products & prices amendment only (§8.10).
- **Locked** (complete / lost / cancelled): `edit_locked_data` + reason
  (`ORDER_EDITED_WHILE_LOCKED`), checked first.
- **Duplicate Order**: copies client, terms, ports and active lines into a
  new order at Stage 1; nothing from the source's history. Each line is the
  same full copy as a line's Duplicate (D-272) and is price-checked at once
  (`OrderLinePricing::afterSave`), so a line below its lowest goes to the MD.
- **Duplicate a line (D-272, owner point 24)**: an exact copy placed right
  below the original (later lines move down one `line_no`), marked "copy of
  line N" (`order_products.copied_from_id`): description, every option
  (`spec_selection`), piece size, unit, quantity, price, HS, line type, SAC,
  category and attributes, product, material, `price_request_id`,
  `zoho_item_id`. Not copied: the MD's approval, `price_snapshot`, an open
  approval request. The copy is checked at once; below the lowest it goes to
  the MD. Audit `ORDER_LINE_DUPLICATED`.
- **Options on a line (D-271, owner point 13)**: shown as labels under the
  description (`OrderLinePricing::optionChips()`). An option switched off
  since shows "“X” is no longer offered — choose again" in red and is never
  replaced silently (the edit form keeps it selected with that text; its
  price check is "no price" until a new option is chosen).
- **Client reorder request**: the buyer's "Reorder This" lands in
  **Orders › Reorder requests** (`order_reorder_requests`).
- **Reorder lines "As per annexure" (D-338, 1a)**: the buyer's reorder form
  (client portal) and the staff review form (`/reorder-requests/{id}`) have an
  **As per annexure** tick box next to "Quantity not decided yet" / "Qty To Be
  Confirmed" — never both (browser: `app.js` `initAnnexureLine`, one handler
  per line via `data-annexure-scope`; server: `AnnexureChoice::reorderLineError()`
  / `reorderLineError()`, "Line N: tick either "…" or "As per annexure", not
  both." / "Line N: a line "As per annexure" still needs its quantity — enter
  the Qty."); the quantity stays required and the Dimensions are cleared.
  Stored in `order_reorder_request_products.as_per_annexure` (pre-filled from
  the earlier order's line) and carried onto `order_products.as_per_annexure`
  when the request is approved.
- **A duplicate keeps Annexure A (D-338, 1b)**: Duplicate Order (and approving
  a reorder request, which duplicates) copies Annexure A — its product entries
  with the same image files (as "Copy everything from Annexure A" does), the
  Additional Terms, "include Annexure A" and the content mode
  (`AnnexureChoice::copyAnnexureAToOrder()` / `copyAnnexureAToOrder()`, audit
  `ANNEXURE_A_COPIED`). Annexure B and `ci_annexure` are not copied. The
  shared image files keep their original `file_store` row (and its order).
- **Archive** (`POST /orders/{id}/archive`, `manage_orders`): sets
  `is_archived = 1`, `ORDER_ARCHIVED` audit row. Visibility only — nothing is
  deleted. Archived orders are listed at `/orders/archived`
  (`view_archived_orders`) and can be unarchived.

## 6. Modules

One subsection per module. File paths follow one convention:

| | PHP stack (`PHP_MySQL_Version/`) | Node stack (`NODE_MySQL_Version/`) |
|---|---|---|
| Routes | `public_html/index.php` | `app/src/server.js` |
| Controllers | `app/src/Controllers/XController.php` | `app/src/controllers/xController.js` (Order → `ordersController.js`, Client → `clientsController.js`, Product → `productsController.js`) |
| Services | `app/src/Services/X.php` | `app/src/services/x.js` |
| Repositories | `app/src/Repositories/XRepository.php` | `app/src/repositories/xRepository.js` |
| Views | `app/views/<area>/*.php` | `app/views/<area>/*.njk` |
| Document templates | `app/templates/<TYPE>/` (Twig) | `app/templates/<TYPE>/` |
| Scheduled jobs | `app/cron/*.php` | `app/src/jobs/*.js` (`scheduler.js`) |

Below, "Code" names the PHP class; the Node file is the same name in
camelCase per this table. Every list page that has a Kanban board must keep
the same filters, counts, fields, actions and money rules on both templates
(standing rule; `KanbanParityTest` / `kanbanParity.test.js`).

---

### 6.0 Menus: the left menu and the section menu bars (D-320–D-322, 11 Nov 2026)

- **Purpose.** Everything visible at first glance: a short left menu, one
  menu bar per section, real buttons for main actions (owner point 26).
- **Left menu (13 entries).** Dashboard · Approvals (n) · My tasks (n) ·
  WORK Orders, Clients, Associates, Products, Procurement & suppliers,
  Finance & accounts · OVERVIEW Reports · HELP Help & SOP · ADMIN Users &
  roles, Settings. An entry shows only when the person can open at least one
  page of its section and links to the first such page that opens in the
  same tab (Help & SOP → Reference library). Counts beside entries:
  Approvals (everything waiting for this person), My tasks (the tasks the
  My tasks page opens on), Finance & accounts (records waiting for Zoho),
  Settings (LUT / RCMC near expiry). Approvals shows for everyone: it holds
  Documents (My Reviews); the other tabs show only to approvers.
- **Section menu bar.** On every page of a section, at the top of the page:
  the section's pages, drop-downs (▾) for sub-pages, a count where
  something waits, the page shown underlined. Which section a page belongs
  to is decided by its path (longest prefix wins), so a new page under a
  section's path gets the bar with no change to the page. No bar on the
  Dashboard, Notifications, My account and My tasks. (11 Nov 2026 build
  review) A category's tabs underline **Categories**; an open drop-down's
  page underlines the drop-down. (D-362, 13 Nov 2026) **Category price
  rules** (`/products/price-rules` → `/products/categories/<id>?tab=rules`)
  and **Global pricing settings** (`/pricing/settings`) are not in any menu:
  they are reached only from the Products screen's link bar; on them the
  left menu still marks **Products** (prefixes `/products`, `/pricing`). The Approvals bar's counts are what this person
  may approve — the same numbers as the Approvals page's chips (E-mails =
  sends waiting for this person's Level-2 approval, not the whole send queue).

| Section | Menu bar |
|---|---|
| Approvals | All · Prices · Payment terms · Documents (`/reviews`) · E-mails (`/email-approvals`) · Bank details · Other |
| Orders | Active · Archived · Quotation requests (n) · PI-stage details (n) · Reorder requests · Disputes · Complaints |
| Clients | All clients · + Add client · Agreements; button "Open in Orders ›" |
| Associates | All · + Add · Registrations (n) · Price requests · Commission ▾ (settings and ladder, levels) · Payouts · TDS register · Access levels · Report |
| Products | All · Categories · Pricing ▾ (commission levels and ladder, specifications) · Price requests · HS codes ▾ (HS codes, fix HS codes on products); button "Open Suppliers in Procurement ›" (D-361: "+ Add" and "Link suppliers" removed — products come from Procurement › Suppliers › + Add product; "Link these prices (N)" is a button on the Products page) |
| Procurement & suppliers | Procurement · Suppliers · Logistics partners · Supplier performance · Imports & stock · Merchant trade |
| Finance & accounts | CA / Accounting · Zoho sync (n) · Incentive payouts · My incentives · Team incentive settings; on CA pages "Open in Reports ›" |
| Reports | All reports · Freight cost · Supplier report · Management cockpit · Audit log |
| Help & SOP | Getting started · SOP · Reference library · Gallery |
| Users & roles | Users · + Add user · Roles and permissions · Departments · Task due days · Self-approval rules · SOP access · Field protection |
| Settings | Setup checklist (n) · Company (n) · E-mail · Security · Holidays · Document clean-up · Backups · Zoho · Documents ▾ (email templates, watermarks, signatories, assets, UI templates) · Lists ▾ (ports, port routes, payment presets, dropdown lists, GST rates, import duty, compliance task types, remittance difference reasons, associate TDS rules, reference library categories) · Divisions · Admin overrides · Data export · Sample data · Test mode · Super Admin |

- **Permissions.** Each link needs exactly the permission its page needs
  (tested against the routes); Super Admin pages need the Super Admin; a
  module switched off for the division (D-257) hides its pages.
- **Order page action bar (D-321).** A bar of buttons at the top that stays
  visible while scrolling: Edit order · Documents (jumps to Document Review
  & Approval) · Full report · Duplicate order · More ▾ (Request
  cancellation, Log in as client, Audit trail), each shown only to those
  allowed. The client and associate pages keep their main buttons at the
  top while scrolling.
- **Config and code.** One config, `app/src/Config/navigation.json` (PHP) =
  `app/src/config/navigation.json` (Node), byte for byte; engine
  `App\Services\Navigation` / `services/navigation.js` (pure: permissions,
  facts, counts and path in; menus out); one shared partial
  `views/partials/section_nav.php` / `.njk` drawn by `layout/base`, so the
  Standard and Kanban templates show the same menus. Counts: PHP in
  `layout/base.php`, Node in `middleware/sessionAuth.js`
  (`CoordinationController::myTaskCount`). Drop-downs close on a click
  elsewhere and a menu link to a part of a page opens the closed card that
  holds it (`app.js` `initSectionMenus`).
- **Tests.** `NavigationMenuD320Test` / `navigationMenuD320.test.js` (13
  entries, every old menu page in a bar, link = route permission, first
  page, active item, counts, order action bar, sticky CSS);
  `KanbanParityTest` / `kanbanParity.test.js` (same bar and left menu on the
  list and the board); e2e `01-role-visibility.spec.js`.
- **SOP / decisions.** SOP 00 (Finding your way); D-320, D-321, D-322.

---

### 6.1 Clients and intake

- **Purpose.** Hold one record per buyer; take enquiries from the public
  form; take PI-stage details from the buyer before the PI.
- **Screens.** Clients › All clients (`/clients`), + Add client
  (`/clients/create`), client page (Duplicate, Client Portal Access,
  Agreements card, associate line, GST registration once Domestic is
  active); Orders › Quotation requests (`/client-intake`);
  Orders › PI-stage details (`/pi-intake-review`). Public:
  `/quotation-details`, `/pi-details/{token}`.
- **Key rules.**
  - One e-mail per client, enforced in code and by a unique index (D-129, D-210).
  - Buyer Inquiry Ref (`client_unique_number`) is generated once per client
    and reused on every order.
  - Accept creates the client only, never an order. Reject needs ≥ 10 characters.
  - The PI details form pre-fills everything the client gave at QT stage
    (D-130); the client ticks a consent box; applying it is Step 5b.
  - Fixed lists are drop-downs (country, Incoterm, container type, CoO
    type with "Not sure") (D-060, D-147).
  - Associate-introduced buyers: hidden code, e-mail verification, first
    completed registration wins, 12-month protection (D-217, D-246).
  - Consignee/notify party used on Buyer PO, BLI, COOPREP (D-061).
  - **Buyer details on orders follow the client (D-337, 12 Nov 2026).** Each
    order keeps its own copy of the buyer's details (company name, billing
    address and lines, VAT/EORI, contact, e-mail, phone, country of
    destination, consignee and notify party — the columns
    `OrderRepository::applyClientSnapshot` writes). See 6.1b.
- **Tables.** `clients`, `client_divisions`, `client_intake_submissions`,
  `pi_intake_submissions`, `client_logins`, `client_password_reset_tokens`,
  `associate_registrations`.
- **Code.** `ClientController`, `ClientIntakeController`,
  `ClientIntakeReviewController`, `PiIntakeController`,
  `PiIntakeReviewController`, `ClientRepository`, `ClientIntakeRepository`,
  `PiIntakeRepository`, `AssociateIntakeService`, `AssociateMatchService`.
- **SOP / decisions.** SOP 01, 03 (PI form), 12; D-122, D-129, D-130,
  D-147, D-217, D-246, D-253, D-337.

### 6.1b Buyer details on orders follow the client (D-337, 12 Nov 2026)

- **Why.** Staff corrected a buyer's e-mail on the client page, regenerated
  a draft quotation, and it still printed the old e-mail: the order's own
  copy (R25) never changed and nothing could refresh it.
- **"Issued".** An order has an issued document when any of its documents
  (any type, buyer-facing or internal) is *approved* or *sent*, or
  *superseded* after having been approved or sent. Drafts, documents in
  review, deleted drafts and documents superseded straight from draft /
  review (audit `DOCUMENT_SUPERSEDED` with old value `draft` / `in_review`)
  are not issued; a superseded document without that audit row counts as
  issued (safe side). `BuyerDetailsRefreshService::hasIssuedDocument()`.
- **Case 1 — automatic.** Saving a client (client edit form; accepting a
  PI-stage confirmation) copies the client's details onto every running
  order of that client (not cancelled, lost or complete) with nothing
  issued, only when at least one field differs. The client page says
  "Buyer details also updated on draft order(s): <refs>." Audit
  `ORDER_BUYER_DETAILS_REFRESHED` per order and field (old → new, reason
  "Source: client edit"). FYI notification `buyer_details_refreshed` to the
  holders of `approve_buyer_details_change` (not the person who saved):
  "Buyer details of <client> changed by <user> — also updated on draft order
  <ref>: Email: a@b.com → x@y.com". No approval.
- **Order page card** (`#buyer-details-change`, under the cancellation
  banner, only for people who see the client): shown when the order's copy
  differs from the client or a request waits. Table *Field · On this order ·
  Client now*. With nothing issued: **Update buyer details from the client**
  (Edit orders; applies at once, same audit with source "order page (nothing
  issued yet)") — for orders whose copy went stale before this rule.
- **Case 2 — with approval.** With an issued document the card says "The
  client's details have changed since this order was created" and offers
  **Ask to update buyer details from the client** (Edit orders; Reason *,
  10–500 characters, browser + server). One PENDING request per order
  ("A request to update the buyer details is already waiting for
  approval."). Table `order_buyer_detail_requests` (order, requester,
  reason, `changes_json` old/new per field at request time,
  `client_snapshot_hash` = SHA-256 of the client's details then, status
  PENDING / APPROVED / REFUSED / LAPSED, decided by / at, note). Holders of
  `approve_buyer_details_change` (MD, ED) are notified
  (`buyer_details_change_requested`, e-mail kind Staff approvals) and see it
  on the card and in Approvals › Other ("Buyer details update"). Approve
  (optional note) or Refuse (note 10–500 characters). Nobody decides their
  own request unless Self-approval rules › `buyer_details_change` allows
  it (default MD only): "You asked for this change — someone else must
  approve it (Admin › Self-approval rules)."
- **Lapse.** On approval, if the client's details changed since the request
  (hash differs), the request becomes LAPSED and nothing is applied: "The
  client's details changed again after this request — ask again so the
  approver sees the current details." Saving the client while a request
  waits lapses it too (the client page says so). The requester is told of
  every decision (`buyer_details_change_decided`).
- **Issued documents never change** — they keep their stored render context
  and files; only documents generated after the change show the new
  details. Order history (audit trail) shows BUYER_DETAILS_CHANGE_REQUESTED /
  _APPROVED / _REFUSED / _LAPSED and ORDER_BUYER_DETAILS_REFRESHED.
- **Code.** `BuyerDetailsRefreshService`, `OrderBuyerDetailRequestRepository`,
  `BuyerDetailsController`, `views/orders/_buyer_details_change.php`; Node
  `buyerDetailsRefreshService.js`, `orderBuyerDetailRequestRepository.js`,
  `buyerDetailsController.js`, `_buyer_details_change.njk`. Routes
  `POST /orders/{id}/buyer-details/refresh|request` (manage_orders),
  `POST /orders/{id}/buyer-details/{requestId}/approve|refuse`
  (approve_buyer_details_change). Migration `2026-11-12d`. Tests
  `BuyerDetailsRefreshTest` / `buyerDetailsRefresh.test.js`.

### 6.2 Products, categories and specifications

- **Purpose.** The internal product catalogue that order lines are picked
  from; categories define line attributes, packing words, quality
  documents and clause sets; specifications change the price.
- **Screens.** Products (`/products`), Products › Categories, product page
  (Market prices, Price options (specifications), Pricing ready? checklist),
  Products › Pricing › Specifications (D-306: also on the category page, tab "Options and their price effect").
- **Key rules.**
  - Category **Natural Stone** is fixed; others may have up to 6 attributes
    (e.g. Size, Grade, Colour); empty attributes print "To Be Confirmed" (D-215).
  - An inactive category stays on existing lines.
  - Specifications per category with options worth a %, USD per SQM, USD per
    running metre, USD per container, or a quantity % applied last
    (D-255). Base option changes nothing; a 0 option reads "Included in base
    price" (D-256).
  - Thick stone (≥ 50 mm, setting) is priced per CBM from the per-CBM supplier price.
  - A product's own options can be hidden, overridden or added
    (`manage_product_specifications`: Admin, MD). A used option can only
    be deactivated.
  - Category settings are frozen per order at PI approval (D-262).
  - **Active · Inactive · All (D-334).** The Products list and its search filter by
    status (`?status=active|inactive|all`, default Active) with a count on each chip.
  - **D-361:** also Not configured · No active supplier · No supplier yet; the search matches supplier names; a
    product is offered on orders only when quotable (configured with an active supplier offer, or "No supplier yet");
    Configure / Edit (`/products/{id}/configure`) holds the base price and the three levels — see §7.1 D-361.
  - **D-362:** a tab or chip with 0 products opens Active (All when Active is 0 too); after Reactivate / Deactivate
    from the list an emptied tab opens the tab that now holds the product.
    An inactive product cannot be picked on new order lines and shows **Used on**:
    orders whose lines were picked from it (removed lines included), associate price
    requests, procurement lines (`ProductRepository::usage()`), or "not used anywhere";
    the product page shows the same line. `manage_product_catalog` may **Reactivate**
    it (audit PRODUCT_REACTIVATED) or **Delete…** it only when it is inactive and not
    used anywhere (supplier prices, images and price history go with it). The server
    refuses to delete an active product ("Deactivate it first") or a used one; an
    allowed delete still marks the Zoho item inactive (D-238). An active product's
    page offers **Deactivate** (audit PRODUCT_DEACTIVATED), not Delete. Routes:
    POST `/products/{id}/deactivate`, `/products/{id}/reactivate`, `/products/{id}/delete`.
  - **Duplicate a product (D-358, 13 Nov 2026, owner go; mockup approved).** `manage_product_catalog`
    sees **Duplicate** next to View on each row of Products and next to Edit on the product page.
    GET `/products/{id}/duplicate` is the confirm screen: **Name of the new product \*** (pre-filled
    "<name> (copy)"; "(copy 2)" … when that is taken), **Copied exactly** and **Not copied** with real
    counts, the note "The copy starts as Inactive …", **Create the copy** / **Cancel**. The name must be
    unique among products, case and surrounding spaces ignored — checked in the browser (`data-not-in-list`
    rule in `app.js`, against a datalist of every product name) and on the server (POST refused with 422
    and the message beside the field). POST `/products/{id}/duplicate` (CSRF) copies in ONE transaction,
    by INSERT … SELECT on the live column list (identical values; a column added later is copied too):
    `catalog_products` (category, HS code, origin, unit, specifications text, costs to FOB, price rules,
    pricing overrides …), the product's own `spec_definitions` and `spec_options` (option ↔ specification
    links re-pointed to the copies, D-357 `dropdown_option_id` kept), `product_spec_hidden`,
    `product_option_overrides`, `catalog_product_suppliers` with `catalog_supplier_thickness_prices` and
    `catalog_product_supplier_options`, `catalog_product_misc_charges`, and `catalog_product_images` (the
    image FILE is copied into the copy's own folder, so deleting one product's image never removes the
    other's). **Reset on the copy:** name as typed, `is_active = 0`, `zoho_item_id` / `zoho_synced_hash` /
    `zoho_synced_at` cleared, created/updated by the current user at now. Products have no SKU or unique
    code, so nothing else is changed. **Not copied:** `catalog_supplier_price_history` (the trail of
    changes on the source's supplier rows — the current price itself is on the copied supplier and
    thickness rows), `price_requests`, order lines, quotations and anything on orders; associate price
    access is per category (`associate_price_access`), so the copy is covered by its category as it is.
    Audit `PRODUCT_DUPLICATED` on the new product (`field_name = copied_from_product_id`, `old_value` =
    source id). No new column: the product page reads that audit row and, while the copy is inactive,
    shows "Copied from <source> on <date> by <role>. Check the details, update the supplier price, then
    switch it to Active." Zoho: the copy has no item, so the Zoho sync skips it while inactive and creates
    a new item when it is active (existing `ZohoMasterDataSync::productsToPush()` rule). An inactive copy is
    not offered in Pick from Products. Code: `ProductDuplicateService` (PHP
    `app/src/Services/ProductDuplicateService.php`, Node `src/services/productDuplicateService.js`),
    `ProductController::duplicateForm()/duplicate()`, view `products/duplicate`.
- **Tables.** `catalog_products`, `catalog_product_images`,
  `catalog_product_suppliers`, `catalog_product_supplier_options`,
  `catalog_product_misc_charges`, `product_categories`,
  `product_category_attributes`, `spec_definitions`, `spec_options`,
  `product_option_overrides`, `product_spec_hidden`, `hs_codes`,
  `hs_code_product_examples`.
- **Code.** `ProductController`, `ProductCategoryController`,
  `SpecController`, `ProductService`, `ProductDuplicateService` (D-358), `ProductSpecs`,
  `CategoryDocumentProfile`, `SpecRepository`, `ProductRepository`.
- **Permissions.** `view_product_catalog`, `browse_product_catalog`,
  `manage_product_catalog`, `manage_product_specifications`,
  `view_product_pricing`, `manage_hs_codes`.
- **SOP / decisions.** SOP 01 (order lines), 27; D-215, D-255, D-256, D-257 (Phase 0B/1), D-334, D-358.

### 6.3 Pricing engine and price requests

- **Purpose.** Work out a safe selling price per unit (FOB, USD) from the
  supplier price, and stop anyone selling below it.
- **Screens.** Products › Global pricing settings (`/pricing/settings`; MD edits, MD/ED view; section headings
  and legends in Title Case — Exchange Rate, Margin and Costs, Team Incentive Reserve, Supplier Prices, Order
  Lines, Specifications and Associate Prices, Who Can Do What — D-338),
  product page build-up, Procurement › Market prices
  (`/products/{id}/market-prices`), order Products card **Price check**,
  `/orders/{id}/products/{productId}/price-approval` (MD), Associates →
  Price requests.
- **The formula (D-245):** cost → minimum price (margin on the price) →
  team-incentive reserve → staff floor → associate floor → quote price; every
  value is global with a per-product override. Steps, worked examples and
  who sees what: §7.1. The order-line check and MD approval: §7.2.

- **Key rules.**
  - Rounding half-up to the paisa; one engine on both stacks.
  - Nobody goes below the **minimum price**, not even the MD.
  - Below the seller's floor the line waits for the MD (`manage_pricing`)
    to approve with a reason; that line's associate commission becomes 0
    and the discount comes out of the incentive.
  - The QT and PI are refused while a line fails the check.
  - Prices, level and rate are fixed when the PI is issued.
  - Cost never reaches the browser or an associate.
  - Market prices are dated rows with valid-until; one is "used for
    pricing"; stale/expired badges.
  - **D-359 (13 Nov 2026):** a supplier's offer is used only while it is
    switched on (`offer_active`) and its supplier is active; each value comes
    from product → category → global; the selling price is worked out from
    the product's configured base price (which follows the supplier price
    "used for pricing" until the Configure screen fixes it). Simple **Edit
    price** form per supplier price. Details: §7.1 "Suppliers, offers and the
    configured base price".
  - **Price requests (associates):** Get price returns at once (number
    `PR-YY-DD-NNNN`, quote, lowest price, valid 7 days, max 10 a day). An
    "Other" request goes to staff, priced within 24 h by the MD. Staff can
    type the request number on an order line.
- **Tables.** `pricing_settings`, `commission_levels`,
  `commission_ladder_steps`, `catalog_product_suppliers`, `price_requests`,
  `associate_price_access`, `order_products` (frozen line figures).
- **Code.** `PricingEngine`, `PricingService`, `PricingLevels` (D-359), `SupplierOffers` (D-359),
  `OrderLinePricing`, `PriceRequestService`, `PricingController`, `EditPriceController` (D-359),
  `PriceRequestController`, `PricingRepository`.
- **Permissions.** `record_market_prices` (Procurement, MD),
  `view_price_buildup` (MD, ED), `manage_pricing` (MD).
- **SOP / decisions.** SOP 27, 24 (price requests); D-240 (replaced),
  D-245, D-255, D-256.

### 6.4 Associates and commission / TDS

- **Purpose.** Outside agents who introduce buyers and earn commission.
- **Screens.** Associates › All associates (`/associates`; register, associate
  page with Portal access, Track record, Payouts, Price access), Associates
  → Access levels, → Price requests, → Registrations; Reports › Associates
  (`/reports/associates`); Associate TDS register (`/associates/tds`);
  Settings › Associate TDS. Associate portal `/associate` (4.11).
- **Key rules.**
  - Commission is on the **final invoiced FOB of the goods**, line by line;
    level fixed on the order at the PI; fixed level or ladder by counting
    orders (invoiced FOB ≥ ₹5 lakh) (D-245 P3).
  - **Commission rule per associate (D-291, 11 Nov 2026):** Standard ladder
    (default) / **Own ladder** (a copy of the standard ladder, edited for this
    associate; it overrides the standard one — `associate_ladder_steps`;
    "+ Add own ladder" / Edit / Remove) / **Fixed %** (a commission level).
    Only the MD (`manage_pricing`) on the associate page › Commission
    (`/associates/{id}/commission-rule`), with a reason (Audit Log
    `ASSOCIATE_COMMISSION_RULE_CHANGED`; `associates.commission_rule_*`); new
    orders only (an order keeps the level fixed at its PI). The edit form no
    longer changes an existing associate's rule (a new associate may start on
    Standard ladder or Fixed). `AssociateCommission::ladderFor()`.
  - **Who sees what (D-292):** with `view_associate_commission_rules` (Admin,
    MD, ED, Finance Director, Finance Manager) the associate page shows the
    rule (with who set it, when and why), "Next order earns x% — Level n",
    "Orders counted so far x of y — n more counting orders to reach z%", the
    step boxes (current step marked) and the counting rule; the Level column
    of the orders table and the list's "Commission rule" column (Standard and
    Kanban). Everyone else sees "Set by the MD." The associate sees **only**
    the current %: "x% of the FOB value of each order from the clients you
    introduced" (wording "FOB value", never "goods value"), a 4-step "How it
    works" (fixed at PI; FOB value on the Commercial Invoice; earned when the
    buyer has paid in full; paid N days later in the monthly / weekly
    payment, in the currency they are paid in to the verified account —
    `AssociatePayoutService::payCurrency` / `payCurrency()`: India always
    Indian rupees, whatever an old record says; abroad their payout currency,
    US dollars when none — the same currency as the "How we set your
    commission" box (11 Nov 2026 build review)), the % fixed on each
    order ("Your %") and the statuses "Not earned yet — buyer still paying" /
    "Earned — will be paid on <date>" / "Paid on <date> (ref)" / "On hold — a
    buyer claim on this order is open" (a NexaCrest hold keeps its reason).
    Never a level, the ladder, counts or the next step; the PI notice says
    "Your commission on it is fixed at 5% of its FOB value".
  - **Payout day (D-292):** monthly 1st–28th or **Last day of the month**,
    weekly Monday–Sunday; "If the payment day is a Sunday or holiday: pay on
    the working day before (default) / after" using the Holiday Calendar (a
    working day = not a Sunday, not in `company_holidays`); if "before" lands
    before the due date the commission goes in the next run. Seed: last day
    of the month, working day before. A change of the payment run needs a
    reason (Audit Log). `AssociateCommission::payoutRunOn / scheduledRunOn /
    workingDay / payDayText`.
  - Only orders in the 10 days from the client's first PO received; later
    only the MD can force "associate: yes" with a reason.
  - Earned only when the buyer has **paid in full**; no pro rata; nothing on
    cancelled orders; due N days after full payment; paid next payout run
    against the associate's invoice; held while a buyer claim is open.
  - Take-back after payment only for fraud or breach (negative line on the
    next payout).
  - **TDS (D-235):** section 194H at 2% above ₹20,000 a financial year
    with catch-up; never more than the payout; abroad needs TRC, Form 10F,
    no-PE and Form 15CA/15CB. Rules and worked examples: §7.3.
  - **Never the total business (owner, 6 Nov 2026):** commission yes; a
    lifetime or period order-value total, or a count of orders, no. The FOB
    of one order shows only as the Access-levels matrix says (4.11).
  - **Portal commission period (D-263):** the portal totals and the
    commission list, CSV and printable statement cover the last N months
    (setting, default 12, counting the current month): earned by incentive
    month, paid by payment date; commission still due or on hold always
    shows. Staff screens are unlimited (`AssociatePortalRepository::payouts`
    vs `portalPayouts` / `totals($id, $months)`).
  - **Fixed at full payment (D-264, 7 Nov 2026):** when the buyer has paid in
    full (last receipt cleared with its rupees recorded) `AssociatePayoutService::autoFix`
    / `associatePayoutService.autoFix` fixes the commission in the order currency and in
    rupees at the rate of all the buyer's payments (`order_incentives.associate_fixed_at`,
    `associate_fixed_rate`; `incentive_payouts.fixed_rate`, `fixed_on`), creates the
    payout, tells the associate and puts the fixed amount in the order's profit. Hooks:
    after clearing advance / freight / balance / milestone and recording LC money; the
    daily job (`daily_digest` / `dailyDigest`) catches up, holds / releases for buyer
    claims, works settlements out again at the same fixed rate, and carries abroad
    amounts below the minimum (default USD 100) to the next run. The MD may change the
    amount before payment with a reason (`POST /orders/{id}/incentive/associate-commission`;
    `associate_override_fc`). The MD's lock covers the team incentive only. The portal
    shows the order currency; India adds one fixed-rupee line; abroad never rupees.
    "Paid in": India always INR, abroad USD/EUR/GBP. Paying records `bank_debited_inr`
    (abroad, required), `bank_charges_inr` and `payout_difference_inr` — "Associate
    payout costs" of the month (Incentive Payouts), a company cost, not on the order.
    It goes to Zoho Books (owner, 7 Nov 2026) as one journal per payout
    (`CaSyncService::syncPayoutCosts` / `caSyncService.syncPayoutCosts`, full sync only):
    the account `zoho_payout_costs_account_id` (Associates › Commission settings) debited
    and the deposit (bank) account credited — a negative difference the other way round —
    notes = the D-212 description, reference `<buyer inquiry ref>-APC-<payout id>` (the order goes by its buyer inquiry ref in Books, as on the commission bill and payment); the journal id and
    what was sent are kept (`incentive_payouts.zoho_cost_journal_id`, `zoho_cost_hash`), so
    it is sent once and updated on a change. Without the account nothing is sent: the Sync
    Log and the payouts page say so. Live TEST-org check pending.
  - **Bank details (D-293, 11 Nov 2026):** new details (portal, or staff
    from the signed form) are `awaiting_confirmation`: the associate gets the
    bank-changed e-mail (MUST) and a bar on every portal page; on
    `/associate/bank/confirm` they see current vs new, re-type the last 4
    digits of the NEW account, enter a fresh 6-digit code e-mailed to their
    REGISTERED e-mail (valid 10 minutes, 5 tries) and tick "These are my bank
    details" → Confirm (status `pending`), or "This is not me — cancel it"
    (cancelled, `cancelled_how = not_me`; the MD, Finance Director and
    Finance Manager are alerted in-app and by e-mail). No answer in 7 days →
    cancelled by the daily job (`expired`); the old account stays. Newer
    details replace open ones (`replaced`). Staff see "Waiting for the
    associate — ends <date>" and **Remind associate**; **Verify only after the
    confirmation**. Setting "Bank details check": **One person** (default) —
    one verifier who is not the enterer ticks at least 2 of: matches the bank
    proof / phone call on the registered number / test payment with its bank
    reference (note optional); or **Two people** (first check + verify, as
    before). MD and ED may verify their own entry with a reason
    (Users & roles › Self-approval rules › "Bank details", `bank_details`). Wait
    before use: a **first** account 0 days, a **changed** account 15 days
    (both 0–90; `associate_bank_wait_first_days`,
    `associate_bank_wait_change_days`); until then payments go to the old
    account. Encrypted, masked; payment only into a verified usable account.
    `AssociateBankConfirmation`, `BankDetailsCheck`. The same rules apply to
    suppliers and logistics partners (D-294, §6.4b).
  - **KYC** checklist must be complete before any commission is paid
    (D-252); Aadhaar last 4 digits only (D-251).
  - **KYC (D-290, 11 Nov 2026):** the ID document's type is chosen
    (Passport / National ID card / Residence permit / Driving licence,
    `associate_documents.id_doc_type`) and its number is 5–30 letters,
    digits, dashes, slashes or spaces (PAN and Aadhaar unchanged). **ID proof**
    and **Address proof** are separate required items in India and abroad
    (India: PAN, ID proof = Aadhaar or an ID document, address proof, bank
    proof, agreement, GST certificate with a GSTIN; abroad: ID proof, address
    proof, bank proof, agreement). The same document may be uploaded under
    each — two records whose file names the app sets ("ID proof — <name> —
    <date>", "Address proof — <name> — <date>"); no "use the same file"
    button. Associates accepted before the change have until
    `associates.address_proof_due_on` (60 days, set at go-live of this
    change) before payouts are held for a missing address proof
    (`AssociateKyc::missingForPayout`).
  - Self-registration link (7 days), code change (up to 3, old codes keep
    working) (D-253, D-254).
- **Tables.** `associates`, `associate_codes`, `associate_invitations`,
  `associate_registration_links`, `associate_registrations`,
  `associate_bank_accounts`, `associate_documents`, `associate_events`,
  `associate_messages`, `associate_tokens`, `associate_access_features`,
  `associate_access_level_settings`, `associate_access_log`,
  `associate_price_access`, `incentive_payouts`.
- **Code.** `AssociateAdminController`, `AssociatePortalController`,
  `AssociateBankConfirmation`, `BankDetailsCheck` (D-293),
  `AssociateRegistrationController`, `AssociateAccessController`,
  `AssociateTdsController`, `AssociateService`, `AssociateCommission`,
  `AssociateTds`, `AssociateKyc`, `AssociateCodeService`,
  `AssociateAccess`, `AssociatePortalService`.
- **SOP / decisions.** SOP 24, 20; D-175, D-176, D-217, D-235, D-245,
  D-246, D-250–D-254, D-261.


### 6.4b Supplier and logistics-partner bank details (D-294, 11 Nov 2026)

- **Purpose.** The same protection as for associates: new bank details are
  never paid into before the vendor confirms them and NexaCrest verifies them.
- **Flow.** A change typed by staff (supplier / logistics partner form) or by
  the vendor (Vendor Details Link) does **not** replace the account on the
  supplier: it is a row in `vendor_bank_changes` (`awaiting_confirmation`).
  A confirmation link is e-mailed to the vendor's **registered** e-mail (the
  one on file before the change; for a new record, the one entered with it):
  `/vendor-bank-confirm/{token}` shows current vs new, the last 4 digits are
  re-typed, "These are our bank details" ticked → Confirm (`pending`), or
  "This is not us — cancel it" (cancelled; MD and Finance alerted). No answer
  in 7 days → cancelled by the daily job; the old account stays. On the
  supplier page, **Bank details changes**: "Send the link again" (to the
  e-mail on file), **Verify** (only after the confirmation; one person other
  than the one who typed them, at least 2 checks; two-people setting; MD/ED
  self-verify with a reason), Reject. After verification the change waits
  its days (first account 0 / change 15) and is then copied onto the
  supplier as verified (`applied`; on the page and by the daily job). The old
  "I have verified these bank details" tick on the form is gone.
- **Code.** `VendorBankChangeService`, `VendorBankChangeRepository`,
  `VendorBankChangeController` (Node: `vendorBankChange*`); routes
  `/suppliers/{id}/bank-changes/{changeId}/verify|check|reject|resend`, the
  same under `/logistics-partners/`.

### 6.5 Team incentive (eligibility and caps)

- **Purpose.** Share part of each order's profit with the staff who worked
  it.
- **Screens.** Team Incentives (`/incentives/settings`, MD), order **Step
  12d · Team Incentive** (MD; ED read-only), Team Incentives › Ready to
  lock, Incentive Payouts (`/incentives/payouts`, Accounts), My Incentives
  (`/my-incentives`), Reports › Incentive & Commission Register / To pay /
  Owed / Statement.
- **Key rules (D-245 P4, D-259).**
  - Basis = order profit **after** associate commission, never below ₹0.
  - Global % of profit (15% to start) or fixed per unit; product override;
    the MD may set a % or amount on one order with a reason.
  - Roles and shares (`incentive_roles`, total 100%), the amount formula
    and per-order overrides: §7.4.
  - States: **Open → Ready to lock** (fully paid, no open claim) **→
    Locked** (by the MD only; nothing locks by itself) **→ Paid** (never
    changes). Unlock with a reason until paid.
  - **Eligibility:** `users.incentive_eligible` (empty = follow
    `department_designations.incentive_eligible`); only the MD changes it.
  - **Caps** per role: `cap_per_order_inr`, `cap_per_month_inr` (month the
    order locks into). Anything not eligible or above a cap is **kept by the
    company**, never shared to others.
  - Worked example of eligibility and caps: §7.4.
  - Accounts pays with salary (payroll ref) or bank transfer (UTR); cannot
    change any amount; paid ≤ payout; once all are paid the order's
    incentive goes to Zoho as Commission Expense.
- **Tables.** `incentive_settings`, `incentive_roles`, `user_incentive_roles`,
  `order_incentives`, `order_incentive_roles`, `order_incentive_people`,
  `order_incentive_history`, `incentive_kept`, `incentive_payouts`.
- **Code.** `IncentiveController`, `IncentiveReportController`,
  `IncentiveService`, `IncentiveReportService`, `IncentiveRepository`.
- **Permissions.** `manage_incentives` (Admin, MD), `pay_incentives`
  (Admin, MD, Accounts, Finance), `view_own_incentives`.
- **SOP / decisions.** SOP 20; D-171–D-174 (superseded), D-208, D-245,
  D-259. Tests `IncentiveEligibilityTest` / `incentiveEligibility.test.js`.

### 6.6 Procurement (RFQ, POs, QC)

- **Purpose.** Buy what an order needs, keep buying prices with
  Procurement/Finance/management.
- **Screens.** Order page "Purchase Requirements — for Procurement" card
  (next to Step 7); Procurement & suppliers › Procurement (`/procurement`, queue);
  › Supplier performance; › Suppliers (`/suppliers`; each supplier's product offers, the offer switch and
  "+ Add product" — D-360, see 7.1);
  order Step 7 (Supplier PO); public Supplier Link page.
- **Key rules.**
  - Sales raises a requirement (product, quantity, needed-by) — never a
    price (`raise_purchase_requirements`).
  - Procurement records quotes (unit price, GST %, lead time, validity, ref)
    — compared **cheapest with GST first**.
  - **Award** checks the designation's approval limit (4.6); above it, an
    approver with `approve_purchases` and a covering limit approves.
  - Receipt with **quality check**: Passed / Passed with remarks / Failed.
  - Cancel with a reason. Repeated clicks change nothing (idempotent).
  - Supplier PO (Steps 7a–7d, production status): `manage_supplier_po`.
    Supplier advance/balance: bill first, then pay with UTR (D-089), by
    `manage_supplier_payments` (Accounts). Proforma vs tax invoice (D-091).
  - Suppliers directory: several types per supplier; delete only if never
    used (D-085). Vendor bank account encrypted, masked, typed twice, IFSC
    checked; a no-login Vendor Details Link (D-088).
- **Tables.** `purchase_requirements`, `purchase_quotes`, `suppliers`,
  `supplier_type_links`, `supplier_factory_addresses`, `order_supplier_po`,
  `order_supplier_po_documents`, `order_vendor_bills`,
  `order_vendor_bill_payments`, `order_production`, `order_quality_documents`.
- **Code.** `PurchasingController`, `PurchasingService`,
  `PurchasingRepository`, `SupplierController`, `SupplierActionController`,
  `VendorBillController`, `VendorDetailsController`, `OrderController`
  (Supplier PO actions).
- **SOP / decisions.** SOP 05, 22; D-011, D-012, D-085–D-094, D-214
  (phase 3), D-216.

### 6.7 Logistics and freight

- **Purpose.** Freight terms, packing, shipping, BL, the CHA, and paying
  logistics partners.
- **Screens.** Order Steps 9, 10, 13b–13e; Logistics Partner Bills &
  Payments card (every incoterm); Procurement & suppliers › Logistics partners
  (`/logistics-partners`); Settings › Lists › Ports (`/ports`) and Port Routes
  (`/port-routes`, sailing days used to suggest lead times); Reports › Freight cost (`/reports/freight-cost`, `view_logistics_costs`).
- **Key rules.**
  - Partner service types: CHA, Transportation, CHA + Transportation,
    Freight Forwarder (D-087).
  - **Logistics adds bills, Accounts pays them** (D-232):
    `manage_logistics_bills` (Logistics roles) adds; `manage_supplier_payments` pays.
  - Each logistics bill is Advance or Final; a Final bill on CFR/CIF waits
    for freight cleared (D-090).
  - GST picked from a list (0/5/12/18/28%, 18% preselected), server
    recalculates (D-092); reverse charge only for GTA road transport (D-093).
  - Insurance CIF only; Debit Note 3 calendar days.
  - Shortfall tolerance gate; shipment gates (5.4 Step 10).
  - Original BLs and endorsement before Close Order (5 Nov 2026).
- **Tables.** `logistics_partners`, `ports`, `port_routes`, `order_freight`,
  `order_packing`, `order_crates`, `order_shipping`,
  `order_draft_bl_reviews`, `order_bl_endorsements`, `order_inspections`,
  `order_shipment_gate_overrides`, `order_vendor_bills`.
- **Code.** `LogisticsPartnerController`, `PortController`,
  `PortRouteController`, `OrderController` (freight/packing/shipping/BL
  actions), `ShipmentGateService`, `VendorBillController`,
  `CourierCharges`.
- **Permissions.** `manage_shipping`, `manage_logistics_partners`,
  `manage_logistics_bills`, `manage_ports`, `override_shipment_gates`.
- **SOP / decisions.** SOP 06, 07, 09; D-021, D-036, D-037, D-048, D-049,
  D-087, D-089–D-093, D-218, D-219, D-232.

### 6.8 Documents and review / approval

- **Purpose.** Generate every PDF/DOCX, take it through review, approval
  and sending, keep every revision.
- **Screens.** Order page: Document Review & Approval (pinned), each step's
  Generate button, All Documents quick links; My Reviews (`/reviews`);
  Email Send Approvals (`/email-approvals`); Settings › Documents › Watermarks, Signatories,
  Assets, Field Protection; Settings › Document copies clean-up.
- **Life cycle.** `draft → in_review → approved → sent`, later
  `superseded`. All assigned reviewers approve → the app finalises (swaps
  the DRAFT watermark for the final one). Only the latest revision can be
  approved; approving one supersedes every sibling (re-rendered "INVALID
  DOCUMENT — SUPERSEDED" from its own stored data, D-258). Sent/superseded
  cannot go back to review. From the second send a "REVISION 02" stamp.
- **Document types:** the full list, with category, reference format,
  PDF/DOCX and who may see each, is in §8.1.

- **Key rules.**
  - **No PDF/document change without the owner's double confirmation**
    (standing rule); all current PDFs are correct.
  - The generator may not review their own document unless the
    `document_review` self-approval rule allows (D-070, D-221).
  - Internal documents never reach the buyer (D-068).
  - No checkboxes on documents (D-046); signatures print as images with an
    identifying line (D-058); PHP and Node give the same page breaks (D-057).
  - Sending with mail on is two-level: Level 1 request, Level 2 approval
    (`approve_email_send`), deferred window, cancel until dispatched
    (D-123, D-142).
  - Regenerating needs confirmation; later stages show "DATA MAY BE STALE".
  - Copies clean-up never removes anything sent to the client or any
    current copy (D-230).
  - Every file stored by step folder `01-quotation/ … 13-closure/` with
    random names (D-149).
  - Cross-verification (independent pass/fail) by
    `cross_verify_documents`.
- **Tables.** `documents`, `document_types`, `document_reviews`,
  `document_revisions`, `document_sections`, `document_cross_verifications`,
  `document_type_signatories`, `company_default_signatory`,
  `user_signature_assets`, `tc_clauses`, `tc_clause_documents`,
  `watermark_settings`, `docx_generation_settings`, `file_store`,
  `file_upload_contexts`, `assets`, `field_protection_requests`.
- **Code.** `DocumentGenerationService`, `DocumentDataAssembler`
  (protected by the PDF rule), `Services/Docx/*`, `ReviewWorkflowService`,
  `DocumentController`, `ReviewController`, `AnnexureController`,
  `WatermarkController`, `SignatoryController`, `FileStoreController`,
  `DocumentCopyCleanupService`; Node also `pdfRenderService.js`.
- **SOP / decisions.** SOP 13, 15; D-001, D-027, D-030, D-046–D-071,
  D-138, D-149–D-152, D-229, D-230, D-262.

### 6.9 Payments and remittances

- **Purpose.** Record what the buyer paid, clear it against the bank, and
  record what NexaCrest paid out — each with a correct description.
- **Screens.** Order page: Payment Overview (Payment Snapshot, Payment
  Ledger, Payment schedule), Steps 5d/5e, 9c/9d, 11c/11d, 7e, 10a, Logistics
  Partner Bills; Reports › Payments, Today's Payments, Ageing, Missing eBRC;
  Settings › Lists › Remittance difference reasons; public Payment Action Link.
- **Key rules.**
  - Record and clear are different permissions (`manage_payments` vs
    `clear_payments`, D-076); clear only after recording (D-077).
  - Amount + INR credited by the bank → rate worked out (D-244); one rate
    field (D-079). Money only arrives in INR in the company's current
    account (D-084).
  - Difference > 1% needs a reason (D-078, D-227); short clears need
    `approve_short_payment` and a reason saying what the short is (agreed
    discount / settled claim / not paid).
  - **Every money record has a correct description (D-212, hard rule):**
    the app builds it from the facts (`PaymentDescription`); a bank
    reference (UTR/SWIFT/FIRC) is required where money moved through a
    bank; a reference already used is refused.
  - **Milestones** (D-215): up to 4 between advance and balance, saved
    before the advance clears; balance can't clear until all milestones
    clear; the CI lists them.
  - Amounts are shown only to people with the money rights (4.7).
  - Repeated clicks record a payment once (D-210).
  - The buyer can report a payment only after the PI is issued (D-206).
- **Tables.** `order_payment_status`, `order_payment_receipts`,
  `order_payment_shortfalls`, `order_payment_milestones`,
  `client_payment_reports`, `order_lc_details`, `order_lc_amendments`,
  `remittance_difference_reasons`, `order_vendor_bills`,
  `order_vendor_bill_payments`, `order_cancellations` (refunds).
- **Code.** `OrderController` (payment actions), `PaymentMilestoneController`,
  `PaymentActionController`, `PaymentActionService`, `PartPaymentService`,
  `PaymentDescription`, `RemittanceInr`, `RemittanceDifference`,
  `BalanceDueService`, `OrderLedgerService`, `PaymentPresetController`,
  `PaymentTermsApprovalService`.
- **SOP / decisions.** SOP 03, 06, 08, ca-02; D-072–D-084, D-205, D-206,
  D-212, D-218, D-227, D-243, D-244.

### 6.10 CAFIN and the CA module

- **Purpose.** Accounting work for the Chartered Accountant, kept apart
  from the order pipeline: INR actuals, forex, FIRC/eBRC, Zoho sync,
  expenses, bank reconciliation, FY lock, export benefits, GST refunds, and
  the internal CA Financial Annexure (CAFIN) per order.
- **Screens.** CA / Accounting (`/ca`): INR Settlement Register, revenue
  reports (FY / calendar year), expenses, bank statement import and
  matching, FY lock, export benefits, GST refunds; CA › Zoho Books Sync
  (`/ca/zoho-sync`); order page CAFIN box (top), Order Financials (Step 12
  tabs: Costs, Export Benefits, Zoho Expenses, Profitability, Internal
  Annexure, D-033); CAFIN CSV export.
- **Key rules.**
  - INR actual is recorded the day a leg clears (`inr_actual_edit`);
    deletion is separate (`inr_actual_delete`).
  - **FY lock** stops changes to a closed year; `ca_fy_lock_override`
    pushes one entry through, logged (`CaFyLockGuard`).
  - CAFIN (sections A–F: order, supplier cost, expenses, benefits, profit,
    open items) can be generated any time from the CAFIN box
    (`ca_internal_doc_manage`) and is generated automatically at Mark Fully
    Settled (D-107, D-108, D-110).
  - Seeing/downloading CAFIN needs `view_cafin` **and** access to the order
    (D-109, D-204). CSV files end `_INTERNAL`, every download logged (D-113).
  - **CAFIN never reaches the client** — portal, links, e-mail compose and
    downloads all refuse it, even by ID (D-106).
  - CAFIN receipts show what the bank credited (D-236); LC money counts as
    received (D-112).
  - The CA role is read-only and sees every order (D-114).
  - GST on purchases under LUT is a refund claimed per period (RFD-01), not
    an order cost (D-213).
- **Tables.** `ca_expenses`, `ca_export_benefits`, `ca_fy_locks`,
  `ca_bank_statement_lines`, `gst_refund_claims`, `gst_refund_credits`,
  `order_cost_entries`, `order_expenses`, `order_export_benefit_schemes`,
  `order_travel_expenses`, `order_inspection_fees`, `order_payment_status`
  (INR columns).
- **Code.** `CaController`, `OrderFinancialsController`,
  `OrderExpenseController`, `GstRefundController`,
  `ComplianceTrackingController`, `CaSyncService`,
  `CaFinancialSnapshotService`, `CafinExportService`, `CaFyLockGuard`,
  `OrderProfitabilityService`, `BenefitSettlementService`,
  `BankStatementCsvParser`; templates `app/templates/CAFIN/`.
- **Permissions.** `ca_module_view`, `ca_module_manage`, `inr_actual_*`,
  `ca_internal_doc_manage`, `view_cafin`, `manage_order_financials`,
  `ca_fy_lock_override`, `manage_export_benefit_claims`.
- **SOP / decisions.** SOP ca-01 … ca-09, 25; D-099–D-115, D-213, D-234,
  D-236.

### 6.11 Reports and dashboard

- **Purpose.** Answer business questions and give each person their
  starting view.
- **Screens.** Dashboard (`/`): search, Active Orders by Stage, My
  Approvals, Overdue Payments, Balance/Freight payment overdue, Compliance
  Expiry Warnings (LUT/RCMC), Agreements expiring, Team incentives ready to
  lock, Associates ready for Quoting, Recent Activity. Reports › All reports
  (`/reports`).
- **Recent Activity (D-268).** Shown with `view_reports` or
  `view_audit_log`. With `view_audit_log` (Admin, MD, ED): the latest 15
  audit entries app-wide. Without it: only the person's own actions plus
  actions on the orders they can open (the Orders list's visibility rule —
  division, assignment unless `view_all_orders`, archived only with
  `view_archived_orders`); never another person's login.
- **Reports** (route → permission):

| Report | Route | Permission |
|---|---|---|
| Aggregate (save as Saved Report) | `/reports/aggregate` | `view_reports` (+ selling prices) |
| Operations Queues / Conversion | `/reports/queues`, `/reports/conversion` | `view_reports` |
| Payments / Today's Payments / Ageing | `/reports/payments`, `/todays-payments`, `/ageing` | `view_reports` + buyer payments |
| Disputes / Amendments / Trends | `/reports/disputes`, `/amendments`, `/trends` | `view_reports` |
| Sales Performance / Product Sales | `/reports/performance`, `/products` | `view_reports` |
| Freight Cost | `/reports/freight-cost` | `view_logistics_costs` |
| Supplier Performance | `/reports/suppliers` | `view_purchase_prices` |
| Order Profitability (Profit by division when ≥ 2 active) | `/reports/order-profitability` | `manage_order_financials` |
| Staff Productivity (active users only; the dates apply to documents and audit actions alike, D-269) | `/reports/staff` | `view_staff_reports` |
| Incentive & Commission Register, To pay, Owed, Statement | `/reports/incentives…` | incentive gate |
| Associates | `/reports/associates` | `manage_associates` |
| Missing eBRC / FIRC | `/reports/missing-ebrc` | `ca_module_view`, `manage_export_benefit_claims` or `manage_order_financials` |
| Per-Client / Per-Order (Full Report) | `/reports/client/{id}`, `/reports/order/{id}` | `view_reports` |

- **Key rules.** Reports show only orders the person may see (assignment,
  archive, division — D-118, D-204); money reports need the money class
  (4.7); CSV export only (no .xlsx, D-183); CSV cells neutralised against
  formula injection (D-182); queries pass ONLY_FULL_GROUP_BY (standing
  rule). Test Mode shows only test data.
- **Tables.** `report_definitions`, plus reads from order tables.
- **Code.** `ReportController`, `ReportRepository`, `DashboardController`,
  `DashboardRepository`, `DivisionProfitService`, `IncentiveReportService`.
- **SOP / decisions.** SOP 14; D-104, D-118, D-183, D-204.

### 6.11b Approvals page (D-310, 11 Nov 2026)

- **Purpose.** One place for everything a person may approve, so nothing
  waits unseen (the MD could not find where to approve a line price).
- **Where.** Left menu **Approvals (n)** (since D-322 shown to everyone, as it
  holds Documents = My Reviews; its tabs show to anyone holding an approver
  permission — `manage_pricing`, `approve_payment_terms`, `approve_email_send`,
  `approve_documents`, `edit_locked_data`, `approve_purchases`,
  `approve_short_payment`, `manage_associate_bank`, `manage_associates`,
  `manage_field_protection`, `approve_buyer_details_change` — and to anyone else once something waits for
  them, e.g. an assigned document review). Route `GET /approvals`
  (signed-in staff; every row is checked per person).
- **Tabs and counts.** All · Prices · Payment terms · Documents (= My
  Reviews) · E-mails (= Email Approvals, Level 2 waiting for approval) ·
  Bank details · Other (amendments, cancellation requests, purchase
  approvals, short payments, associate registrations, associate price
  requests, field-protection requests, reorder requests). The D-240
  "discount above warning" approval no longer exists separately — since
  D-245 it is the line-price approval (Prices tab). The Prices tab also
  holds the daily exchange rate when it moved more than 2 % and waits for
  the MD's OK (D-301; holders of `manage_pricing`; the button opens
  Products › Global pricing settings › Exchange rate).
- **Columns.** Waiting since ("Today 10:42", "Yesterday", "9 Nov") · What ·
  Order / who · Details · a button that opens the **exact existing approval
  screen** (line price approval page, order Step 1 for payment terms,
  `/reviews#review-<id>`, `/email-approvals#email-<id>`, the associate /
  supplier / partner bank section, `/orders/<id>/amendments`, the order's
  cancellation banner, `/procurement/<id>`, the order's payment step,
  `/associates/registrations`, `/associates/price-requests?show=pending`,
  `/admin/field-protection`, `/reorder-requests/<id>`). Nothing is approved
  on this page.
- **Who sees a row.** Exactly who could act on it today: the screen's
  permission, the order visibility rule (division, assignment, archived) for
  order items, and Users & roles › Self-approval rules (a price you set yourself, terms
  of an order you created, a send you requested, an amendment or cancellation
  you asked for, an award you made, a payment whose receipts you recorded,
  bank details you entered — only where your role is ticked). Two-people bank
  checks: the first checker does not see the second check. Purchase awards
  above the person's approval limit are not shown. Protection requests: not
  your own (Super Admin excepted).
- **Kanban template.** The same rows as a board (one column per tab), the same
  tab chips and buttons, "Switch to list view" / "Switch to board view".
- **Short payments (new request record).** Clearing a payment that came in
  short used to be refused with "ask a manager"; the refusal now also files a
  request in `short_payment_requests` (one per payment leg; owed amount,
  who asked, when) and tells every holder of `approve_short_payment` once
  (notification `short_payment_approval`, e-mail kind Staff approvals). The
  request stays open until the leg is cleared short or the rest is
  received; the refusal text ends "It now waits in Approvals for them."
- **Dashboard › My Approvals.** New tiles *Prices waiting for my approval*,
  *Payment terms*, *Bank details to verify*, *Other approvals* (beside the
  existing ones) and a button **Open my approvals (n)**.
- **Order page.** A yellow bar at the very top for anyone who may approve
  something on that order: "Waiting for your approval: line 2 — USD 320.00 /
  PCS is below the lowest price (USD 325.41 / PCS). The Quotation is held until
  it is approved." with a button "Review and approve line 2" (also payment
  terms, document reviews, e-mails, amendments, cancellation, purchase, short
  payment). The person who asked sees "Line 2 — sent to the MD for approval on
  11 Nov, 10:42 — waiting." (and the same for their cancellation, amendment,
  e-mail and short-payment requests).
- **E-mails.** Every approval e-mail (kind `staff_approvals`, D-280) ends with
  "Open your approvals: <APP_URL>/approvals".
- **Old pages kept.** My Reviews and Email Approvals still work (rows gained
  anchors `review-<id>` / `email-<id>`); the menu will later point to
  Approvals only (point 26).
- **Code.** `ApprovalsInbox` / `approvalsInbox.js`, `ApprovalsController` /
  `approvalsController.js`, views `approvals/index`, `approvals/index_kanban`;
  `OrderAccessService::visibilitySqlFor`. Tests `ApprovalsAndSetupD310Test` /
  `approvalsAndSetupD310.test.js`, `GapPartPaymentsTest` /
  `gapPartPayments.test.js`. SOP 13, 00.

### 6.12 My Tasks and the management cockpit

- **Purpose.** Hand work from one department to the next without anyone
  remembering to do it.
- **Screens.** My Tasks (`/my-tasks`), Reports › Management cockpit
  (`/cockpit`, `view_cockpit`), order side panel **Owners**, Users & roles › Task
  Due Days (`/admin/task-types`, `manage_task_types`).
- **Key rules.**
  - Tasks are computed from order state (nothing stored per task): when a
    step is done, the next department's task appears.
  - Seeded task kinds (`department_task_types`, standard / escalate days):
    Sales — raise purchase requirement (1/2); Procurement — quote & award
    (3/3), approve award (1/2), chase supplier (0/3); Logistics — book
    freight & CHA (2/3), send documents (2/3); Finance — clear buyer payment
    (1/2), pay supplier/logistics bill (3/4); Export Compliance — checklist
    item (5/3), apply COO (2/2), benefit eligibility (3/3), file claim
    (7/7), eBRC follow-up (7/7).
  - Overdue tasks are red; escalated ones show in the cockpit. Tasks never
    show amounts.
  - Whose tasks (D-268): a member works their department's queue
    (Management, the Order Desk and Audit see every department's). With no
    current department membership, a person sees only the tasks on orders
    they own (the department owner), with a note saying so — except
    holders of `view_cockpit` (Admin, MD, ED, directors, Order Coordinator,
    Operations Manager) and an Admin in "Preview as", who keep every
    department's list. Same on the list and the Kanban board.
  - One owner per department per order (`assign_department_owner`); MD/ED,
    Admin and the Order Coordinator choose for any department.
  - Cockpit: open/overdue/escalated per department, orders without an
    owner, orders by status, escalations, late suppliers — counts only.
    Every active department has a row, in Users & roles › Departments order, with
    0s when it has no open work (D-269); work of an inactive department
    still shows, after the active ones.
  - **Daily digest** 08:15 IST, one e-mail per person; counts as sent only
    if mail really left.
- **Tables.** `department_task_types`, `order_department_owners`,
  `order_assignees`, `daily_digest_log`, `notifications`.
- **Code.** `CoordinationController`, `DepartmentTaskRepository`,
  `OrderAssignmentService`, `DailyDigestService`; cron
  `daily_digest.php` / `dailyDigest.js`.
- **SOP / decisions.** SOP 23, 25; D-214 (phase 4).

### 6.13 E-mail templates

- **Purpose.** Editable wording for every system e-mail.
- **Screens.** Settings › Documents › Email templates (`/email-templates`,
  `manage_email_templates`); compose/preview from Document Review; Email
  Send Approvals.
- **Seeded templates (21):** `send_qt`, `send_pi`, `send_oc`, `send_ci`,
  `send_pl`, `send_fdn`, `send_annexa`, `send_ble`, `send_buyerpo`,
  `send_esi`, `balance_receipt_confirmation`, `bl_copy_sent`,
  `payment_followup`, `shipment_readiness`, `buyer_action_link`,
  `pi_form_link`, `quotation_correction_link`, `client_portal_invite`,
  `client_portal_password_reset`, `staff_password_reset`,
  `two_factor_code`.
- **Key rules.**
  - Templates are never deleted; each send keeps a frozen copy
    (`email_log.body_snapshot`).
  - A document is sent with its own template (D-142).
  - Security templates fall back to built-in wording if missing or off.
  - Settings › E-mail (D-280) switches each e-mail per audience; MUST
    e-mails (login and account access) always go; testing mode
    (`email_testing_mode`, on in seed since D-347; replaced `mail_sending_enabled`)
    holds only buyer and supplier e-mails. `mail_approval_queue_enabled`
    (on) gives the two-level send.
  - E-mail only when someone must act or a client acts (D-135); recipients
    are assignees + oversight (D-136); no hidden copies (D-137); Reply-To,
    UTF-8, sender's own signature (D-141).
  - Mail Redirect settings and Test Mode redirect are separate.
  - Mail goes through Bluehost SMTP; Zoho Mail code is switched off (D-139).
- **Tables.** `email_templates`, `email_log`, `notifications`.
- **Code.** `EmailTemplateController`, `EmailDispatchController`,
  `EmailDispatchService`, `EmailService`, `MailSenderService`,
  `MailRedirectService`; cron `dispatch_deferred_emails` (every 10 min).
- **SOP / decisions.** SOP 13, 15; D-123, D-135–D-142, D-155.

### 6.14 Zoho Books sync

- **Purpose.** Keep Zoho Books (the CA's ledger and GST filing tool) equal
  to the app, order by order, without re-typing.
- **Screens.** CA › Zoho Books Sync (`/ca/zoho-sync`: Sync now, Sync Log,
  "Waiting for Zoho: N"), order "App vs Zoho" check
  (`/orders/{id}/zoho-check`), Settings › Zoho (test
  connection, `zoho_sync_clients`, `zoho_sync_products`).
- **What goes.** CI and Debit Note as export invoices (same number and
  date, at the shipping-bill customs rate, D-165/D-166); buyer payments as
  customer payments (foreign amount at the actual rate); supplier and
  logistics bills and vendor payments (with UTR); paid order expenses;
  export benefits as journals; associate commission and team incentive as
  expenses; Domestic/Import/Merchant records (6.15).
- **Key rules.**
  - Off unless switched on: `.env` `ZOHO_INTEGRATION_ENABLED`,
    `ZOHO_ENVIRONMENT` (test/production), `ZOHO_EINVOICE_ENABLED` (D-161).
  - Nothing dated before `zoho_books_sync_start_date` is sent (D-162).
  - "Either sync completely or don't" (D-163); the app is the source for
    what it sends and puts back changes made in Books; Books is the source
    for expenses the CA books there (D-164).
  - A saved record never goes to Books at once; it waits for the sync
    (D-233); failure e-mails notify.
  - API budget per organisation (`ZohoApiBudget`, D-239); one sync at a
    time (`ZohoSyncLock`), no double posting.
  - Test and sample orders never reach production Books.
  - **Zoho change log (standing rule):** every change in Zoho by anyone is
    a row in `docs/zoho/zoho-change-log.csv` (UTF-8 BOM; IDs Z-001…).
    Test records start `TEST-` and are removed before go-live
    (`zoho_cleanup_test_records`).
- **Tables.** `zoho_sync_log`, `zoho_api_usage`, `zoho_token_cache`,
  `zoho_pending_inactivations`, `order_zoho_invoices`; Zoho IDs on
  clients, lines, bills, payments.
- **Code.** `ZohoBooksService`, `CaSyncService`, `ZohoInvoiceData`,
  `ZohoMasterDataSync`, `ZohoPendingService`, `ZohoRestoreService`,
  `ZohoGuard`, `ZohoSyncLock`, `ZohoApiBudget`, `OrderZohoMatchService`,
  `DivisionZohoSync`; cron `zoho_sync.php` / `zohoSync.js` (hourly).
- **SOP / decisions.** SOP ca-03; docs/zoho/README.md; D-160–D-170,
  D-212, D-233, D-238, D-239.

### 6.15 Divisions: Domestic Trade, Import & Distribution, Merchant Trade

All three are **built but planned** (D-257): nobody sees them until the MD
activates the division in Settings › Divisions after the pre-flight checklist.
Export is unchanged. Each keeps its company details when its first
document is fixed (D-262). Each money entry has a D-212 description and a
required bank reference. Each goes to Zoho Books with its own mapping.

| | Domestic Trade (DOM) | Import & Distribution (IMP) | Merchant Trade (MTT) |
|---|---|---|---|
| What | Sell to Indian resellers in ₹ with GST | Import into India, hold stock, sell locally with GST | Buy abroad, sell abroad; goods never enter India (RBI merchanting) |
| Menu | Orders › New order (domestic form) `/domestic/orders/create` | Procurement & suppliers › Imports & stock `/import` + local-sale orders | Procurement & suppliers › Merchant trade `/merchant` |
| Stages | Enquiry → Quotation → OC → Purchase → Dispatch (tax invoice number fixed, e-way bill) → Tax invoice → Payment → Closed | Ordered (LC, import PO) → Shipped (bill of entry, customs rate, charges) → Cleared (duty + landed cost frozen) → In stock → Closed | Contracted (buy/sell lines, PO, BL / switch BL) → Shipped (sale invoice) → Settled (AD-bank checklist) → Closed |
| Money rules | CGST+SGST same state, IGST otherwise; invoice `DOM/26-27/0001` (≤ 16 chars, per FY) | Landed cost = assessable value + BCD + SWS + charges; IGST is input credit, not cost; stock at weighted average, never below zero | Margin = ₹ received − ₹ paid at bank rates; RBI checks: 9 months total, 4-month outlay, USD 5,00,000 advance limit without LC/BG, documents with AD bank |
| Settings (to confirm with CA/bank) | `dom_default_gst_rate` 18, `dom_eway_threshold_inr` 50000, `dom_e_invoice_applicable` 0, `dom_payment_terms_days` 30 | `imp_default_bcd_pct` 10, `imp_default_sws_pct` 10, `imp_default_igst_pct` 18, `imp_margin_pct` 20 | `mtt_max_months_total` 9, `mtt_max_outlay_months` 4, `mtt_advance_limit_usd` 500000, `mtt_usd_inr_rate` 84 |
| Tables | `domestic_orders`, `domestic_order_lines`, `domestic_payments`, `domestic_documents`, `domestic_counters`, `gst_rates`, `indian_states` | `import_shipments`, `import_shipment_lines`, `import_payments`, `import_duty_rates`, `stock_balances`, `stock_movements` | `merchant_deals`, `merchant_deal_lines`, `merchant_payments`, `merchant_deal_documents`, `merchant_alerts_sent` |
| Code | `DomesticOrderController`, `DomesticOrderService`, `DomesticDocumentService`, `GstCalculator`, `GstRateController` | `ImportController`, `ImportService`, `ImportDocumentService` | `MerchantTradeController`, `MerchantTradeService`, `MerchantDocumentService` |
| SOP | 29 | 30 | 31 |

Shared: `DivisionService`, `DivisionController`, `DivisionZohoSync`,
`DivisionProfitService`; tables `divisions`, `division_modules`,
`division_settings`. Module switches per division hide menus and refuse
routes. Numbering: another division counts separately and prefixes its
code unless its own `order_ref_format` places `{DIV}`. SOP 28; D-257,
D-262.

### 6.16 Backups

- **Purpose.** Keep an extra, encrypted copy of the database and every
  order document for 10 years, without touching live data (D-260).
- **Screens.** Settings › Backups (`/admin/backups`,
  `manage_company_settings`) — read-only status; BACKUP WARNING in the
  daily digest.
- **Key rules.** Scripts, schedule, encryption, retention, warnings and
  restore are described once: overview §3.15, procedure §12.3. The page
  only reports what the jobs wrote; it never runs or restores a backup.
- **Code.** `BackupController`, `BackupStatusService`; tests
  `scripts/tests/backup_scripts_test.sh`, `BackupFacilityTest` /
  `backupFacility.test.js`.
- **Related.** Data Export / Migration (`/admin/data-export`,
  `data_export_run`: structure-only + data-only dump for a fresh
  reinstall; SOP 18; `MysqlDumpService`).
- **SOP / decisions.** SOP 32, 18; D-260.

### 6.17 Admin pages

Left menu **Users & roles** and **Settings** (D-320; each page appears in
their menu bars only with its permission; HS codes, Suppliers, Logistics
partners, Team incentives and Pricing now sit in the Products, Procurement &
suppliers and Finance & accounts bars — see 6.0):

| Page | Route | Permission | Notes |
|---|---|---|---|
| Company Settings | `/settings` | `manage_company_settings` | Company, bank, LUT/RCMC (expiry alerts), mail, Zoho, portal defaults, Associate TDS, remittance reasons, document clean-up. Secrets shown as "•••• ends in 1a2b". |
| Setup Checklist | `/admin/setup-checklist` | `manage_company_settings` | D-312: Done / Needed per installation item, ticks, Apply recommended (6.17b) |
| Holiday Calendar | `/holidays` | `manage_company_settings` | Working-day maths (disputes); Indian national holidays (26 Jan, 15 Aug, 2 Oct) seeded for the install year and the next (D-313) |
| HS Codes | `/hs-codes` | `manage_hs_codes` | |
| Logistics Partners / Suppliers | `/logistics-partners`, `/suppliers` | `manage_logistics_partners` / `manage_suppliers` | |
| Ports (+ Port Routes) | `/ports`, `/port-routes` | `manage_ports` | |
| Payment Presets | `/payment-presets` | `manage_payment_presets` | Protected presets |
| Compliance Task Types | `/compliance-task-types` | `manage_compliance_task_types` | Phase: with Supplier PO / before shipment |
| Dropdown Option Lists | `/admin/dropdown-options` | `manage_dropdown_options` | Container type, CoO type, GST rates … |
| Email Templates | `/email-templates` | `manage_email_templates` | 6.13 |
| UI Templates | `/ui-templates` | `manage_company_settings` | Standard / Kanban; Standard can't be deactivated |
| Watermarks, Assets, Signatories | `/watermarks`, `/company-assets`, `/signatories` | `manage_assets`, `manage_signatories` | |
| Divisions, GST Rates, Import Duty Rates | `/admin/divisions`, `/admin/gst-rates`, `/admin/import-duty-rates` | `manage_divisions` | |
| Departments, Task Due Days | `/admin/departments`, `/admin/task-types` | `manage_permissions`, `manage_task_types` | Preview as |
| Permissions | `/admin/permissions` | `manage_permissions` | Role matrix, personal exceptions |
| Users | `/users` | `manage_users` | Active / Inactive / Archived; delete only with no history |
| Field Protection | `/admin/field-protection` | `manage_field_protection` | Lock/unlock needs a second person |
| Team Incentives, Pricing | `/incentives/settings`, `/pricing/settings` | `manage_incentives`, `manage_pricing` | MD |
| Admin Overrides | `/admin/overrides` | `edit_locked_data` | Log of reasoned overrides |
| Sample Data | `/sample-data` | `manage_sample_data` | 6.18 |
| Data Export / Migration | `/admin/data-export` | `data_export_run` | |
| Backups | `/admin/backups` | `manage_company_settings` | |
| Super Admin, Self-approval rules, Test Mode | `/super-admin`, `/admin/self-approval`, `/test-mode` | Super Admin only | |

- **Test Mode** (SOP 16): portal and public form unavailable, business
  mail redirected to one test address, new records prefixed `TEST-`,
  reports show only test data, no audit rows for test records, admin
  settings frozen (D-179), nothing reaches production Zoho. Disable is
  blocked until test data is deleted. Code `TestModeService`,
  `TestModeGate` middleware, table `test_mode_settings`.
- **Code.** `SettingsController`, `UserController`,
  `PermissionAdminController`, `DepartmentAdminController`,
  `SuperAdminController`, `SelfApprovalController`,
  `FieldProtectionController`, `AdminOverrideController`, and one
  controller per list page.
- **SOP / decisions.** SOP 15, 16, 21, 28; D-124, D-157, D-179–D-182,
  D-214, D-221.

### 6.17b Setup checklist (D-312, 11 Nov 2026)

Settings › **Setup checklist** (`/admin/setup-checklist`, `manage_company_settings`;
the menu entry shows how many items are Needed). Each item shows **Done /
Needed** (or Optional) with a button to the page where it is set:

| Item | Done when | Button |
|---|---|---|
| Company identity | name, offices, MD and director names, phone, e-mail are not the seed placeholders | Company Settings |
| Bank details, GSTIN, IEC/PAN | bank keys, GSTIN and IEC/PAN are not the seed placeholders | Company Settings |
| LUT and RCMC | numbers entered, expiry dates not passed | Company Settings |
| ECGC (optional) | no policy = Optional; with a policy, a valid-until date not passed | Company Settings |
| E-mail server | SMTP_HOST, PORT, USERNAME, PASSWORD, FROM_ADDRESS set in `.env` | Send a test e-mail (`/settings#smtp-test`) |
| Zoho (optional) | Zoho Books on with an organisation id | Company Settings |
| Backups | BACKUP_DIR, BACKUP_ENCRYPTION_KEY_FILE, BACKUP_OFFSITE_TARGET set | Backups |
| Pricing confirmed by the MD | ticked (only `manage_pricing` may tick) | Global pricing settings |
| Commission levels | ticked by the MD and at least one active level | Commission levels |
| TDS and benefit deadlines confirmed with the CA | ticked | TDS settings |
| This year's holidays | ticked for the year ("add state and festival holidays for <year>" — a new tick every year); button to add any missing national holidays for this year and next | Holiday Calendar |
| The MD's real e-mail | no active user still has md@email.com, ed@email.com or admin@email.com | Users |
| storage/sessions writable | PHP: the folder exists and is writable; Node: always Done (sessions in the database) | Backups |
| Settings at the recommended values | none differs | the list below |

**Recommended values** (shown with "Differs" and the current value, ticked
rows applied together with **Apply recommended**, reason of 10+ characters,
each change audit-logged as `FIELD_EDIT` with the reason plus
`SETUP_RECOMMENDED_APPLIED`): document clean-up 90 / 365 / latest_only,
`password_expiry_days` 0, `login_remember_browser_days` 30, session timeouts
staff 240 / client 120 / associate 120 minutes, absolute 12 hours, Test Mode
e-mail = the company e-mail.

Ticks are kept in `setup_checklist_ticks` (item key, who, when, note; audit
`SETUP_CHECKLIST_TICKED` / `_UNTICKED`). Code `SetupChecklist` /
`setupChecklist.js`, `SetupChecklistController` / `setupChecklistController.js`,
view `settings/setup_checklist`. SOP 15.

### 6.18 Sample data playground

- **Purpose.** Load realistic sample clients and orders to practise on, and
  clear them any number of times, without touching real data.
- **Screens.** Settings › Sample data (`/sample-data`, `manage_sample_data`):
  Load / Clear.
- **What it loads** (fictional companies): Client A with two orders (A1 at
  Stage 1; A2 at Stage 2 with a pending PI intake in PI Intake Review,
  D-159); Client B — heavily instrumented FOB order left at Stage 8 (buyer
  PO upload, PI intake applied, portal auto-provisioning, OC reject /
  regenerate / approve, full amendment, Supplier PO acknowledgement, FOB
  freight skip, shortfall with buyer approval); Client C — full 9-stage CIF
  order with freight stage, followed by a dispute raised and resolved;
  Client D — quoted then marked lost; Client E — four orders showing
  Annexure A variety; Client F — a raw-block line (HS 25161100).
- **Key rules.**
  - It uses the **same services** as real users (OrderRepository,
    StageGateService, DocumentGenerationService, AmendmentService …), so it
    behaves exactly like the real system.
  - Every record is flagged `is_sample_data = 1` and removed cleanly by
    Clear. Loading twice is refused — clear first.
  - Sample orders never reach production Zoho.
  - Seed also includes sample vendors (Sample Stone Supplier, Sample CHA &
    Transport, Sample Freight Forwarder) — deactivate after testing (D-158).
  - Test Mode (6.17) is the safer, sandboxed alternative for rehearsals.
- **Code.** `SampleDataController`, `SampleDataService`,
  `SampleDataRepository`.
- **SOP / decisions.** SOP 15, 16; D-158, D-159.

## 7. Money rules and formulas

This section explains every money calculation the app makes. Each rule is in **one service per stack**, and the PHP and Node versions use the same steps and the same rounding. When you change a formula, change both files and run the worked-example tests on both stacks (rule R1, parity).

| Area | PHP (`PHP_MySQL_Version/app/src/Services/`) | Node (`NODE_MySQL_Version/app/src/services/`) |
|---|---|---|
| Price build-up (pure maths) | `PricingEngine.php` | `pricingEngine.js` |
| Settings, overrides, supplier choice | `PricingService.php` | `pricingService.js` |
| Specifications and options | `ProductSpecs.php` | `productSpecs.js` |
| Order-line check, MD approval | `OrderLinePricing.php` | `orderLinePricing.js` |
| Associate commission | `AssociateCommission.php` | `associateCommission.js` |
| TDS on commission | `AssociateTds.php` | `associateTds.js` |
| Team incentive | `IncentiveService.php` | `incentiveService.js` |
| Profit (one calculation everywhere) | `OrderProfitabilityService.php` | `orderProfitabilityService.js` |
| Buyer remittance INR and rate gaps | `RemittanceInr.php`, `RemittanceDifference.php` | `remittanceInr.js`, `remittanceDifference.js` |
| CAFIN content | `CaFinancialSnapshotService.php` | `caFinancialSnapshotService.js` |
| Domestic GST | `GstCalculator.php` | `gstCalculator.js` |
| Import landed cost and duty | `ImportService.php` | `importService.js` |
| Merchant trade margin | `MerchantTradeService.php` | `merchantTradeService.js` |
| D-212 money descriptions | `PaymentDescription.php` (+ builders in the services above) | `paymentDescription.js` |
| Rounding helper | `PricingEngine::round2`, PHP `round()` | `src/helpers/money.js` (`round2`, `format2`) |

### 7.1 Pricing (D-245, D-255, D-256)

**Why it exists.** Before D-245 (4 Nov 2026) staff typed selling prices with only a "reference price" to guide them (D-240). The owner wanted one rule that protects the margin, pays the team and the associate out of the price, and still leaves room to negotiate. Orders are always priced **FOB, per unit, in USD**.

#### The seven steps

`PricingEngine::build()` works on unrounded numbers. Only the results are rounded, to 2 decimals, half up, at the end.

| Step | Formula | Notes |
|---|---|---|
| 1. Supplier price in USD | supplier price (₹) ÷ pricing exchange rate | Since D-361 the supplier price is the product's **configured base price** (Products › Configure; `SupplierOffers::pricingRow()`), with the CHA, packing and dates of the usable offer marked "used for pricing". Before D-361 it was that row itself. |
| 2. Cost | supplier USD + other costs to FOB | Other costs = % of the supplier USD (default 5.5 % since D-347), or a fixed USD amount per unit; product → category → global (D-359). |
| 3. Minimum price | cost ÷ (1 − margin %) | **Margin on the price, not markup.** 20 % margin on cost 1,000 gives 1,250, not 1,200. Nobody may sell below it, not even the MD. |
| 4. Team-incentive reserve | % of (minimum − cost), or a fixed USD amount per unit | **0 when the incentive program is off** (`incentive_settings.program_enabled`, seeded `1` since D-347). |
| 5. Staff floor | minimum + reserve | The lowest price any staff member may quote without MD approval. |
| 6. Associate floor | staff floor ÷ (1 − commission %), or staff floor + fixed USD commission | One floor per commission level. |
| 7. Quote price | floor × (1 + buffer %) | Default buffer 3 %. This is what fills in when a product is picked. |

Validation in the engine: the exchange rate must be above 0; the margin must be 0 or more and below 100 (the settings form allows 0–90); a commission % must be below 100.

**Worked example 1 (no options).** Supplier price ₹80,000, pricing rate ₹82.00, other costs 2.5 %, margin 20 %, incentive 15 % (program on), buffer 3 %, associate Level 2 = 5 %.

| Step | Working | Result (USD) |
|---|---|---|
| Supplier USD | 80,000 ÷ 82 | 975.61 |
| Cost | 975.6098 + 2.5 % | **1,000.00** |
| Minimum | 1,000 ÷ 0.80 | **1,250.00** |
| Reserve | 15 % × (1,250 − 1,000) | 37.50 |
| Staff floor | 1,250 + 37.50 | **1,287.50** |
| Staff quote | 1,287.50 × 1.03 = 1,326.125 | **1,326.13** (half up) |
| Associate floor (5 %) | 1,287.50 ÷ 0.95 | 1,355.26 |
| Associate quote | 1,355.263 × 1.03 | 1,395.92 |

With the incentive program **off**, the reserve is 0: staff floor = 1,250.00, staff quote = 1,287.50.

#### Global settings and per-product overrides

Every %, amount and the buffer has a global value (Products › Pricing, `pricing_settings`) and an optional override on the product (`catalog_products.pricing_margin_pct`, `pricing_other_cost_mode/value`, `pricing_incentive_mode/value`, `pricing_buffer_pct`). An empty override means "use the global value". The build-up lists the overrides in use ("margin 25%, buffer 5%").

#### Who sees what

| Permission | Default holders | Sees |
|---|---|---|
| `view_price_buildup` | MD, ED | Cost, margin, minimum, reserve, every floor and quote, Try a price, Pricing ready? |
| `manage_pricing` | MD only | Changes pricing settings, product overrides, commission levels and ladder, an associate's level, approves below-floor lines |
| Selling-price staff (Sales) | — | Only **Quote price** and **Your lowest price** (their floor) |
| `record_market_prices` | Procurement Executive/Manager/Director, MD | Supplier prices only, never selling prices |
| Associate | — | Their own quote and lowest price (through Get price) |
| Client | — | Nothing of the build-up |

Cost never reaches the browser of anyone without `view_price_buildup`: the order form fetches the quote and floor from the server (`OrderLinePricing::lineQuote`).

#### Specifications and options (D-255)

A product category has specifications (Grade, Thickness, Finish, Size, Edge, Quantity, Packing, Treatment, Calibration for Natural Stone) and options. The base option changes nothing. Every other option changes the **cost**, so margin, reserve, commission and buffer are always added on top and every option keeps the margin. `ProductSpecs::adjustments()` applies them in a fixed order:

1. **% options** multiply the cost, one after another (e.g. Standard −8 %, 30 mm +35 %).
2. **Fixed USD amounts** are added:
   - USD per SQM: added. On a CBM price it is converted: USD per SQM × 1000 ÷ thickness (mm).
   - USD per running metre: added only if the product is sold per running metre (unit RM, RMT, LM …); otherwise listed as "charged separately".
   - USD per piece / per kg / per set (D-257 Phase 0B): added only if the product's selling unit matches (PCS/NOS, KG, SET); otherwise "charged separately".
   - USD per container: never in the unit price; always "charged separately".
3. **Quantity %** (kind `quantity`) comes last.

If the options bring the cost to 0 or below, the engine refuses.

A product follows its category. It can hide a specification or option, type its own value (empty = follow the category, so later category changes flow through), or add options or whole specifications for itself. That needs the permission "Manage a product's specifications" (Admin and MD at seed). Used options can only be deactivated.

#### One list of surface-finish names (D-357)

Owner decision D-357 (12 Nov 2026, owner confirm 1 + confirm 2; mockup approved). **Settings › Lists › surface_finish** (`dropdown_options`, `list_key = 'surface_finish'`) is the one place to **name** a finish: name, sort order, default and on/off. **Pricing › Specifications › Surface finish** keeps the **price** only (base, change type, value); there each name is read-only with a "Rename in Settings › Lists" link, and Deactivate / Remove / "+ Add option" are replaced by notes pointing to Settings. Code: `SurfaceFinishList` (PHP `app/src/Services/SurfaceFinishList.php`, Node `src/services/surfaceFinishList.js`).

| Data | Meaning |
|---|---|
| `spec_definitions.list_key` | `'surface_finish'` on the Surface finish specification of a category (the only linked specification; NULL on every other). A category specification added later with the name "Surface finish" is linked at once and gets every finish at no price change. |
| `spec_options.dropdown_option_id` | The Settings row the option is. Name and sort order always follow that row; on/off follows it when it is switched in Settings. |

- **Added in Settings** → an option in every linked specification with no price change (change type none); the Settings page shows "no price change yet — set it in Pricing › Specifications".
- **Switched off in Settings** → the linked options are switched off, so the finish leaves Pick from Products (saved lines keep it, shown as "no longer offered").
- **Renamed in Settings** (on save) → the linked options are renamed, and `order_products.finish` changes on lines of orders whose first Quotation is not approved yet (`orders.prices_fixed_at IS NULL`, the D-342 rule) whose finish is exactly the old name. Orders with an approved Quotation or later, and every `documents` row and issued file, keep the old name. Audit: `DROPDOWN_OPTION_UPDATED`, `SURFACE_FINISH_RENAMED` (with the counts) and one `ORDER_LINE_FINISH_RENAMED` per line. The page shows: "Saved. "Polished" is now "Natural Polish" everywhere: Pick from Products, Pricing › Specifications, and N lines on M draft orders (quotation not yet approved)." and "Not changed: K orders with an approved quotation or later, and every document already issued — they keep "Polished"."
- A finish name is at most 80 characters and appears once in the list (any letter case); otherwise nothing is saved.
- The Settings list shows "Used in: Pick from Products …, order lines, the Supplier PO, the buyer's quotation form and every document" and a read-only column "Price change (from Pricing › Specifications)" (base price / +1.00 USD / SQM / no price change yet), one entry per category when more than one category has the specification.
- **Order lines.** A line picked from Products takes its finish from its Surface finish option every time it is saved (`OrderLinePricing::saveSelection()` and the line update call `SurfaceFinishList::applyToLine()`); the "More › finish" box is hidden for such a line (New Order form and order page line panel; the picker data carries `finish: true` on the linked specification). A line typed by hand keeps the box, fed from the same Settings list.
- **A product's own option** on the Surface finish specification must be a Settings name (it is linked to that row); the product page points to Settings instead of offering a free-text option there.
- **Merge on a live site:** `docs/migrations/2026-11-12j-surface-finish-one-list.sql` (see `docs/migrations/README.md`; it also handles a base finish renamed "Mirror Finish"). Seed: Natural Polish (base, default) 1, Honed +1.00 2, Flamed +2.00 3, Sandblasted +3.00 4, Leather +4.00 5, Brushed +4.50 6, Rock-Pitched 7 and Bush-Hammered 8 (no price change), all USD per SQM.

#### CBM at 50 mm and above

From **50 mm** (setting `cbm_from_mm`, default 50; a product may set its own `cbm_from_mm`) the price is **per CBM** and starts from the product's separate **per-CBM supplier price** (`price_basis = 'cbm'`, `is_cbm_primary = 1`). On a CBM price:

- thickness % options no longer apply;
- per-SQM amounts are converted ×1000 ÷ thickness;
- no per-CBM supplier price, or an unlisted thickness of 50 mm or more → **manual price** (staff price it by hand);
- an order line of 50 mm or more must have unit **CBM**, or the save is refused.

**Worked example 2 (CBM).** ₹53,300 per CBM, 80 mm, Standard −8 %, Leather +USD 4.00 per SQM, 2–4 containers −2 %, other settings as in example 1.

| Step | Working | USD |
|---|---|---|
| Supplier USD + 2.5 % | 53,300 ÷ 82 = 650.00 × 1.025 | 666.25 |
| Standard −8 % | × 0.92 | 612.95 |
| Leather, converted | 4.00 × 1000 ÷ 80 = 50.00 added | 662.95 |
| 2–4 containers −2 % | × 0.98 | **cost 649.69** |
| Minimum | ÷ 0.80 | 812.11 |
| Staff floor | + 15 % × (812.11 − 649.69) | 836.48 |
| Staff quote | × 1.03 | **861.57** per CBM |
| Associate Level 2 | floor ÷ 0.95; quote × 1.03 | 880.50 / 906.92 |

**SOP 27's per-CBM figures (quote USD 904.56, lowest USD 878.21) are also correct**, for different inputs that SOP 27 does not spell out. They are the **associate's** quote and lowest price at Level 2 (5 %), for a product with **other costs 0 %**, options Standard · 80 mm · Leather and **no quantity option** (test `D255PriceRequestsTest` / `d255PriceRequests.test.js`, mockup mock9/t1). Recomputed with `PricingEngine::build()`: 53,300 ÷ 82 = 650.00 → × 0.92 = 598.00 → + 50.00 = cost **648.00** → minimum 810.00 → reserve 24.30 → staff floor 834.30 (staff quote 859.33) → associate floor 834.30 ÷ 0.95 = **878.21** → associate quote × 1.03 = **904.56** per CBM (≈ USD 72.36 per SQM at 80 mm). The USD 861.57 above is the **staff** quote with other costs 2.5 % and the 2–4 containers option. SOP 27 should name its inputs (doc fix, §14.5).

#### Exact supplier prices (D-256)

A supplier may quote one figure for a combination ("30 mm leather, ₹4,900 per SQM"). Procurement records it as `price_basis = 'combo'` and names an option for some specifications ("any" for the rest). `PricingService::exactPrice()`:

- uses it **as it is** for the options it names; the other options still apply;
- the row naming **the most options** wins; on a tie, the **most recently quoted**;
- **stale or expired** exact prices are ignored (the ordinary price is used);
- a per-unit exact price is never used for a per-CBM request.

SOP 27 example: exact ₹4,900 for "30 mm · Leather"; Premium · 30 mm · Leather · 2–4 containers → cost ₹4,900 ÷ 82 + 2.5 % − 2 % = USD 60.03; staff floor USD 77.28, staff quote USD 79.60.

#### "Included in base price" (D-256)

An option worth 0 is shown as **"Included in base price"** everywhere (option lists, Try a price, the associate's breakdown), never "+0%" or "+USD 0.00" (`ProductSpecs::changeLabel()`). In the build-up it is a line of kind `included` with no effect on cost.

#### Staleness

A supplier price is **expired** after its `valid_until` date, and **stale** when its quoted date (else last change) is more than `stale_days` (default 60) old. Stale prices still price, with a badge; stale exact prices are ignored.

#### Pricing ready? and Try a price (D-256)

`PricingService::readiness()` lists what a product still needs. Since D-365 (below): configured base price set ('base'); at least one active supplier ('suppliers'); prices current; specifications; per-CBM price if it is sold at the CBM threshold or above ('cbm'); exact prices current; open to associates. Each item has a `state` ✔ ok / ✘ fix / ℹ info; only `fix` counts (`countToFix`). The product list shows "Pricing: N to fix". **Try a price** runs the same engine on any option combination and saves nothing (build-up viewers only). Its Thickness select starts on **"Supplier's base price (<base, e.g. 50 mm and above>)"** (empty value): the thickness is left out of the selection (`ProductSpecs::SUPPLIER_BASE`), so the supplier row's own BASE is priced (D-335) and the figure equals the product list and build-up; choosing a thickness prices that thickness. The selected option is always the one priced.

#### Products and pricing, 11 Nov 2026 (D-300 to D-306)

**Thickness prices (D-300).** A supplier-price row with `price_basis = 'grid'` holds every thickness: the
category's Thickness options below `cbm_from_mm` (20 mm base, 15/18/30/40 mm with their %) per SQM, and
"50 mm and above" per CBM. Before D-335 (below) only TYPED prices were stored, in `catalog_supplier_thickness_prices`
(`thickness_mm` 0 = 50 mm+ per CBM); CALCULATED prices are worked out each time by `ThicknessPrices` /
`thicknessPrices.js`: other thickness = base × (1 + % / 100); 50 mm+ per CBM = base per SQM × K
(`product_categories.thick_k_factor`, product override `catalog_products.thick_k_factor`). The base is the
typed 20 mm price, else worked back from the first typed thickness, else from the per-CBM price ÷ K.
Saving a box at 0 / empty calculates it again; nothing typed → "Enter at least one price". A box posted as
"calculated" whose value differs from the calculation counts as typed (works without the browser script).
Every change of a price, CHA or the packing tick is a row in `catalog_supplier_price_history` (who, when,
old → new), shown on Products › a product › Market prices › Edit (`/products/suppliers/{id}/price`).

**FOB cost.** `PricingService::fobParts()` = supplier price for the chosen thickness, converted to the
product's unit (per CBM = per SQM ÷ thickness; then `LineUnits::cbmPerUnit`), + CHA + transport per unit,
+ the product's packing + loading costs when "Price includes packing and loading" is unticked. CHA +
transport per container (`catalog_product_suppliers.cha_transport_per_container`, default
`catalog_products.cha_transport_per_container`) ÷ what one container carries: `container_cbm` (8) unless
`container_cbm × weight_t_per_cbm > container_max_t` (27 t), then `container_max_t ÷ weight_t_per_cbm`.
Category values, product overrides. A grid price used for pricing is also the per-CBM price for 50 mm+;
the Thickness option's % is not applied again on top of a grid price. Old one-price rows ('unit', 'cbm',
'combo') work as before, plus CHA from the product default when set.

**Category price rules (D-300).** Products › Categories › <category> › Category price rules (`/products/categories/{id}?tab=rules`,
menu link `/products/price-rules`): thickness %s (the category's Thickness options), × K, + Add a thickness
(below 50 mm), container CBM / maximum t, stone t per CBM, live example column (app.js `initRulesExample`).
Saving needs `manage_pricing` (MD) and a reason; every change is `PRICE_RULE_CHANGED` / `PRICE_RULE_ADDED`
in the Audit Log with that reason (`PriceRules` / `priceRules.js`). A product's own K or container shows
an "own rule" badge (set on Edit product, MD only).

**Typed wins, a base, the rules only a calculator (D-335, 12 Nov 2026).** This replaces the "CALCULATED
prices are worked out each time" part of D-300 for every price added from 12 Nov 2026:
1. A price the user TYPED always wins — no rule ever changes it.
2. Each grid row has a BASE the user chose: `catalog_product_suppliers.base_thickness_mm` (0 = 50 mm and
   above per CBM; NULL = the category's base thickness, as before). The product-level price (Market prices
   summary, price build-up, catalogue / lowest price, associate catalogue, price requests) and order lines
   with no thickness use the base (`ThicknessPrices::cost()` / `thicknessPrices.cost()`); lines of 50 mm
   and above use the stored "50 mm and above" price; a line with a thickness below that uses that
   thickness's stored price.
3. The rules (thickness %, × K, container) are only a CALCULATOR. Market prices shows one **price card** per
   grid row (Thickness · Price (₹) · Unit · How it was set — TYPED / CALCULATED from … / no price · Since ·
   FOB cost (₹) incl. CHA in the thickness's own unit · Base for orders ◉ BASE, "○ make base"), then
   "Product price (from the base): ₹… per <unit> · <base> → USD … at ₹… · minimum (… % margin) USD … · quote
   (+… %) USD …" (the USD part only for `view_price_buildup`), the CHA per CBM line, **+ Add / update a
   price** and History. The form: Thickness *, Price * (₹ per SQM, per CBM for 50 mm+), Quoted on *, "Also work
   out from this <X> price" — one tick box per other thickness with the result and how ("= 4,600 − 20 %",
   "= 20 mm price ₹4,600 per SQM × 40", from a non-base price the base is worked back first: CBM ÷ K, or ÷ (1 +
   %)) and a "change …" link to the product's price rules (`/products/{id}/edit#price-rules`; since D-363 one row
   per thickness with a category / this product badge and one link "Change the % rules for this product" → Configure ›
   Thickness rules); ticked by
   default only where no TYPED price exists ("you typed ₹… on … — kept unless you tick this"); Base for
   orders: keep / make this price the base. app.js `initThicknessCalc` previews; `ThicknessPrices::calcFrom`
   / `thicknessPrices.calcFrom` works it out again on save (`POST /products/suppliers/{id}/thickness-price`;
   `POST /products/suppliers/{id}/base` for "make base"; both `record_market_prices`). Save: upsert the typed
   thickness (`how` 'typed', `quoted_on`), upsert the ticked ones (`how` 'calculated', `calc_from_mm`), leave
   the unticked ones untouched, set the base if chosen, `auto_fill` = 0, and write history lines ("20 mm per
   SQM: — → 4,600.00 typed", "15 mm per SQM: … → 3,680.00 calculated from 20 mm", "Base: … → 50 mm and above").
   A new supplier price (Add a price from another supplier) uses the same fields; its typed thickness is the base.
- **Older rows** (`auto_fill` 1, saved before 12 Nov 2026) keep the old on-the-fly completion, so no price
  changed. The first "Add / update a price" stores what each untouched thickness showed as CALCULATED, then
  switches the row to `auto_fill` 0. With `auto_fill` 0 a thickness without a stored price has **no price**
  (the order line goes to the MD, as today). The older grid boxes (`price[mm]`) are still accepted.
- **Owner example** (product sold per CBM, base 50 mm+ typed ₹1,80,000 / CBM, CHA ₹80,000 per container of
  8.2 CBM, rate 95, margin 8 %, other 0 %, buffer 3 %): FOB ₹1,89,756.10 / CBM, supplier USD 1,894.74, cost
  USD 1,997.43, minimum USD 2,171.12, quote USD 2,236.26 (the engine rounds at the end; ≈ 2,236.25). A 20 mm
  price of ₹4,600 typed later: 4,600 + CHA 195.12 = ₹4,795.12 per SQM; the base stays ₹1,89,756.10 / CBM.
- **Product page (Edit product):** "Costs to FOB" — CHA + transport per container (₹, with "÷ X CBM per
  container = ₹Y per CBM") and Packing + loading per unit (₹; stored in `default_packing_cost`,
  `default_loading_cost` set empty; both still added up where read; added only when a supplier price does
  NOT include packing and loading) — and "Price rules in effect for this product" (every rule with a
  "category" / "own rule" tag; MD switch "Use own rules for this product" opens × K, container CBM and
  maximum t; off clears them). Default factory / transportation / CHA cost are no longer on the form (the
  columns stay, kept as they are). Migration 2026-11-12b turned every "add up the cost parts" row
  (`fob_source` 'computed') into a typed price (`direct`) with the same total.
- **Product page › Suppliers (D-338, 2a):** a new supplier price is always one typed price (FOB Value (₹),
  `fob_source` 'direct'); "add up the cost parts" (FOB Source "Computed") is no longer offered and the
  server refuses it for a new price (`ProductController::COST_PARTS_REFUSAL` / `productsController.js`,
  same wording). A row still saved as Computed stays readable ("Computed (adds up the cost parts)") and
  keeps working; its Edit form keeps the cost parts and offers "Direct — change to one typed price". A
  typed row's Edit form has no cost-part fields and keeps whatever is stored (nothing is cleared).

**Exchange rate (D-301).** `FxRateService` / `fxRateService.js`; settings `fx_mode` ('auto' | 'manual'),
`fx_cushion_pct` (0–5, one decimal), `pricing_fx_rate` (manual, and the fallback until a rate is fetched).
`PricingService::settings()['pricing_fx_rate']` is the rate in use: latest `fx_rates` row with status
`in_use` × (1 − cushion %). Daily job `fetch_fx_rate.php` / `fetchFxRate.js` (07:05 IST): Frankfurter, then
open.er-api.com; failure → a `failed` row, the last rate stays (warning); a move > 2 % against the rate in use
→ `waiting_md`, in-app notice to `manage_pricing` holders, MD decides on Global pricing settings with a reason
(`/pricing/fx-rates/{id}/decide`). The MD can also "Fetch today's rate now". Issued QT / PI keep their prices
(price snapshot on the line). The rate line with [Why?] (`pricing/_fx_rate_line`) appears only on the
catalogue header and Global pricing settings, for build-up viewers.

**Rate used for prices right now (D-340).** On Global pricing settings the separate "Exchange Rate" block above the
form is gone; the **Exchange Rate (USD → INR)** fieldset starts with a box titled "RATE USED FOR PRICES RIGHT
NOW" (`FxRateService::status()` / `fxRateService.status()`, which adds to `current()`): the rate in big type
with a badge — **Automatic · fetched today**, **Automatic · rate of 11 Nov 2026**, **Manual fall-back**
(Automatic, nothing fetched yet) or **Manual**; for Automatic with a fetched rate the facts Market rate ·
Fetched (date and time from `fx_rates.fetched_at`) · Source · Cushion ("2.0% → ₹88.40 × 0.98") and "Manual rate
₹82.00 is not used — it is only the fall-back if no rate has ever been fetched."; the warnings (orange); for
Manual "For comparison, last market rate fetched: ₹88.40 (12 Nov 2026) — your rate is 5.2% above the market."
and, when above, "Above the market means prices in USD come out lower than intended."; the D-304 rate line with
[Why?]; the MD's approve / keep form for a move over 2 %; "Fetch today's rate now" (MD) and "History (last 30
fetches)" (link to the history, `#fx-history`, now at the end of the fieldset). Colour: green = Automatic with
a fetched rate and no warning; orange = fall-back or any warning; blue = Manual. The fetch and decide forms are
empty forms before the settings form; their buttons and the reason field sit in the box with `form="…"`
(`#fx-fetch-form`, `#fx-decide-form`). The box keeps `id="exchange-rate"` (menu link, approvals, redirects).
Field badges: the cushion "not used in Manual" (and the field muted, `fx-unused`) in Manual; the manual rate
"not used now — fall-back only" (muted, still editable) in Automatic with a fetched rate, "in use now" in
Automatic without one and in Manual. They are right on load and follow the mode drop-down live
(`select[data-fx-mode]`, delegated handler `applyFxModeBadges` in the sitewide app.js; `data-fx-when`,
`data-fx-muted-when`, `data-fx-has-market` on the fieldset).

**Order page: the rate the prices were worked out at (D-340).** In the order's Products card, above Price
check, price build-up viewers (`view_price_buildup`: MD, ED) see one line (`FxRateService::orderLine()` /
`fxRateService.orderLine()`; `#price-rate`), only when a line is price-checked: before the PI "Prices worked
out at ₹86.63 per USD (Automatic, 12 Nov 2026) — live: follows today's rate." ("(Manual)", "(Manual
fall-back)"); after the PI the rate fixed with the line figures (`order_products.price_snapshot` → `fx_rate`,
else `orders.pricing_fx_rate_at_pi`) and the date of the first PI: "Prices worked out at ₹86.63 per USD — fixed
on the Proforma Invoice of 12 Nov 2026 at ₹86.63 per USD.", adding "; today's rate ₹86.40 does not change
them." when today's rate differs. Before the PI no per-line rate is stored, so there is nothing to compare
with. Never shown to other roles, associates, buyers or on documents (D-304). No database change. **Since D-342** an
order whose first Quotation is approved shows "Prices worked out at ₹86.63 per USD — fixed by the approved Quotation of
12 Nov 2026." (`PriceLock::fixedRate()` / `fixedDate()`), with the same "; today's rate … does not change them." when it
differs; the PI wording above remains only for an order fixed at its PI and not by a Quotation.

**One master for HS code and supplier (D-302, D-303).** Product HS code must be an active `hs_codes` code
(form datalist + `data-in-list`, server check); "+ Add a new HS code" posts to `/hs-codes` with `return_to`.
Order-line HS fields carry `data-in-list` too. `/products/fix-hs-codes` lists products whose code is not on the
list. A product price's supplier is `catalog_product_suppliers.supplier_id` → `suppliers`
(`supplier_name` keeps the directory name); search-pick with the location list from
`supplier_factory_addresses` (else city/state); `/suppliers/quick-add` creates a supplier with
`details_pending = 1`; `/products/link-suppliers` links typed names ("Match by name" or one by one). The
supplier form shows "Prices given for products" (purchase-price viewers) and the "Details pending" tick.

**app.js (`fieldError`).** `data-in-list="<datalist id>"`: the value must be one of the list's options.
`data-at-least-one="<name prefix>"`: at least one enabled field with that name prefix holds a number above 0
(message on the first). `initThicknessGrid`, `initSupplierPick`, `initRulesExample`.

**Labels and visibility (D-304, D-305).** Catalogue headers carry currency and unit; "Supplier price ₹ / unit"
with "≈ USD" (product editors), "Staff lowest", "Associate lowest", staff "Quote price" / "Your lowest". On an
order from an associate's client, staff see "for this order" figures and the note; "sold by <associate>
(level)" only for `view_price_buildup`.

**Category page (D-306).** `/products/categories/{id}` with tabs General | Options and their price effect |
Category price rules | Products in this category (`ProductCategoryController::show`). `/pricing/specifications` (GET)
redirects to the Options tab; the specification actions redirect there too. A product's options page links
"edit for all products in <category>".

**Suppliers, offers and the configured base price (D-359, 13 Nov 2026, owner "go with recommendation"; Push 1 of 3).**
- **Offers.** Each `catalog_product_suppliers` row is one supplier's OFFER of a product (a product has many suppliers,
  a supplier many products — never a product per supplier). An offer is **usable** only when `offer_active` = 1 and its
  directory supplier is active (`suppliers.is_active`; a price typed before the directory link counts as active). An
  unusable offer is never used: not as the price "used for pricing", not as the per-CBM price, not as an exact price for a
  combination, not in lowest / highest. When the price marked "used for pricing" is switched off the product shows "The
  supplier price behind this product is switched off (inactive supplier or offer) — choose another supplier price in
  Configure." (wording of the D-365 follow-up; `SupplierOffers::usable()` /
  `supplierOffers.usable()`; the repository's `forProduct()` / `find()` carry `supplier_is_active`.)
- **Configured base price.** `catalog_products.configured_base_price_inr` with `configured_base_unit` ('m2' at a
  thickness, 'CBM' for 50 mm and above, or the product's unit), `configured_base_thickness_mm` (0 = 50 mm and above,
  NULL = a plain price per unit), `configured_at` / `configured_by`. While `configured_base_follows_supplier` = 1 (every
  product priced before D-359 — the migration filled the price it is priced from today — and every new product) the
  base price is the usable supplier price "used for pricing", live, exactly as before; its stored copy is refreshed when
  that price is saved through Edit price. Once fixed (D-361, follows = 0) it never changes by itself and the engine prices
  from it: `SupplierOffers::pricingRow()` turns it into a price row — the offer used for pricing unchanged when it is the
  same price at the same thickness, otherwise a price for every thickness typed at the base thickness (other thicknesses
  worked out by the price rules, as an older `auto_fill` row) or a price per unit, with that offer's CHA, packing and dates.
- **Three levels.** Margin %, other costs, buffer %: the product's own value, else its category's
  (`product_categories.margin_pct`, `other_cost_pct`, `buffer_pct`; empty = follow global), else Global pricing settings. Weight
  t/CBM, container CBM, container max t, thickness factor K: product → category → global (`pricing_settings`
  `weight_t_per_cbm` 2.70, `container_max_t` 27.00, `container_cbm` 8.00, `thick_k_factor` 40 — the last fallback). The
  team incentive is product → global. `catalog_products.pricing_follow_global` = 1 ("Reset all to global values") skips
  the category. The product's own other-costs value is the existing `pricing_other_cost_mode` / `_value` (% or USD).
  `PricingLevels::resolve()` returns each value with where it comes from (product / category / global);
  `resetPreview()` lists what a reset would change; `resetToCategory()` clears the product's own values;
  `resetToGlobal()` clears them and sets the flag (audit `PRODUCT_PRICING_RESET`). Used by `PricingService::effective()`
  and `ThicknessPrices::rules()`.
- **Edit price** (`/products/suppliers/{id}/edit-price`, `record_market_prices`; mockup `edit_price_new.png`). Reached from
  an **Edit price** button on each supplier row of Products › a product › Market prices and an **Edit price** link on each
  row of the supplier page's "Prices given for products" (Cancel / close go back there, `?from=supplier`). Left: New price
  (₹) * (big), per SQM / CBM / the unit, Thickness * (a price for every thickness; default the base thickness, marked
  "(base)"), "Was ₹… → now ₹… (−₹…, −… %)", Quoted on * (not in the future), Valid until (not before Quoted on), "More
  (optional)": CHA + transport per container (₹; placeholder shows the product's default), Price includes packing and
  loading, Notes; Save / Cancel. Right: "Other thicknesses — worked out for you" (thickness · price · was), **Type it
  yourself** per thickness (that row is then saved as typed), and for `view_price_buildup` "Selling price after saving:
  minimum USD … · quote USD … / unit (was … · …)" — only when this offer is the one the selling price comes from, else
  why not. The figures come from `GET …/edit-price/preview` (JSON; `SupplierOffers::preview()` →
  `ThicknessPrices::calcFrom` / `values`, `PricingService::fobParts` / `buildFromFob`); app.js `initEditPrice` only shows
  them. Save (`POST …/edit-price`): a price for every thickness → `ThicknessPrices::addPrice()` with every other thickness
  ticked (worked out from the entered one), then one `addPrice()` per typed thickness; the base thickness is not changed;
  a plain price → `fob_value`; then dates, CHA, packing, notes (price history rows for each change, audit
  `MARKET_PRICE_CHANGED` "… (Edit price)"). The page stays with a green "Saved. <supplier> · <product>: ₹old → ₹new per …,
  quoted …. Other thicknesses updated." and **Undo** · **See price history**. Undo (`POST …/edit-price/undo`, one-time
  token kept in the session) puts back every stored thickness price, the row's price, base, auto_fill, dates, CHA,
  packing, notes and the product's configured-price copy, with a history row "Undo (Edit price)". Issued quotations and
  orders never change (D-342).
- **For D-360 / D-361.** `SupplierOffers::offers()`, `markedForPricing()`, `setOfferActive()` (history "Offer active",
  audit `SUPPLIER_OFFER_ON/OFF`), `offerBase()`, `lowestHighest()` (usable offers quoted like the product's base),
  `configuredBase()`, `setConfiguredBase()` (follows = 0, audit `PRODUCT_BASE_PRICE_SET`), `followSupplier()`,
  `refreshFollowedBase()`, `pricingRow()`; Node twins in `supplierOffers.js` and `pricingLevels.js`.

**Suppliers pages — products, offers and "+ Add product" (D-360, 13 Nov 2026, owner "go with recommendation"; Push 2 of 3;
mockups p1, p2, decision_d1 part B).** No database change (uses D-359's columns).
- **Procurement & suppliers › Suppliers (`/suppliers`, `manage_suppliers`).** Every existing column and filter stays (Type,
  Search, Status, Actions; Edit / Deactivate / Delete). New: **Show:** All · Active · Inactive · **No product prices yet (N)**
  (`?show=all|active|inactive|no-prices`, kept with Type and Search; N counts the suppliers in the current Type / Search with
  no offer linked to them); a **No product prices yet** badge next to such a supplier; three columns **Base price quoted (₹)**,
  **Quoted on · valid until**, **Offer active**; under each supplier one row per offer linked to it (`catalog_product_suppliers`
  with that `supplier_id`): product name (link to the product; "Product inactive" badge), the base price as quoted
  (`SupplierOffers::offerBase()`: "₹190,000 / CBM (50 mm and above)", "₹1,650 / SQM (20 mm)", "₹80,000 / unit"; "Exact price for
  a combination" for a combo row), "12 Nov 2026 · 12 Jan 2027" (or "no end date"), the **Offer active** switch and **Edit price**
  (`/products/suppliers/{id}/edit-price?from=suppliers`; Back / Cancel return to `/suppliers#supplier-{id}`). **+ Add product**
  per active supplier. An inactive supplier's offers are greyed with "Inactive: none of their prices are used anywhere."
  Offers, prices and the switch are shown only to purchase-price viewers (`view_purchase_prices`, as on the supplier page);
  the switch, + Add product and Edit price need `record_market_prices` as well (without it the switch shows On / Off).
  Prices typed against a supplier name not linked to the directory (before D-303) are not listed under a supplier until
  linked (D-361: Products › Link these prices). The Suppliers list has no board view, so there is no Kanban twin to keep parallel.
- **Edit supplier › Prices given for products (mockup p2).** Columns Product · Base thickness · Price · Quoted on · Valid until ·
  **Offer active** · **Actions** (**Edit price** `?from=supplier`, **Open product** `/products/{id}`). The "Used for pricing"
  column is removed from this table only — the product keeps its own pricing supplier (Market prices) until D-361. A
  **+ Add product** button beside the heading opens the **+ Add product** panel below the table (a `<details id="add-product">`,
  open after `?add=1` or a refused save).
- **"+ Add product"** (the same form, `suppliers/_add_product.php` / `_add_product.njk`, on the supplier page's panel and on
  `GET /suppliers/{id}/add-product` from the list): **Product *** — every product, those with no offer linked to any
  directory supplier first ("— no supplier yet", red badge "no supplier yet" when picked), "(Inactive)", "(already offered:
  use Edit price)" — or **create a new product**: **Name *** (unique, as Duplicate checks it), **Category *** (active
  categories), **HS code** (optional; must be on the HS Code Master List; empty = the category's default HS code). Then
  **Base thickness *** (the product's / category's thicknesses with the base marked "(base)", or "No thickness: one price" for
  a product without thickness rules), **Price ₹ *** (above 0, at most 2 decimals), **per *** (SQM / SQFT / CBM following the
  thickness; for one price the product's unit or CBM — a new product's unit list for a new product), **Quoted on *** (not in
  the future), **Valid until** (not before Quoted on). app.js `initAddOffer` fills the thickness and unit lists from
  `data-choices` (`SupplierOffers::addProductChoices()` / `priceShape()`); the fields of the hidden half are disabled so the
  sitewide check skips them. `POST /suppliers/{id}/add-product` (`manage_suppliers` + `record_market_prices`, CSRF):
  refuses an inactive supplier; refuses a second offer of the same product from the same supplier and sends the user to that
  offer's **Edit price** with "… already has a price for … — a supplier offers a product once. Change that price here with
  Edit price."; a new product is created by `SupplierOffers::createProduct()` — **Inactive**, not configured
  (`configured_at` empty), category set, specifications empty, audit `PRODUCT_CREATED` "(from a supplier, Inactive, not
  configured)". The offer (`SupplierOffers::addOffer()`): a `catalog_product_suppliers` row with the supplier's directory
  name and city / state, `supplier_id`, `offer_active` = 1, packing and loading included, CHA = the product's default, the
  dates; a price for every thickness → `ThicknessPrices::addPrice()` at the typed thickness with every other thickness
  ticked and the typed one made the base (others worked out by the category's price rules, exactly as Market prices); one
  price → `fob_value` (`price_basis` 'cbm' when per CBM for a product whose unit is not CBM). It is marked "used for pricing"
  only when the product has none yet (then an existing product's followed base price is refreshed); otherwise every
  existing price is untouched. History "Offer added (+ Add product)", audit `MARKET_PRICE_ADDED` "… (+ Add product)". Back to
  where it was opened (`/suppliers#supplier-{id}` or the supplier page `#product-prices`) with a green "<product> added to
  <supplier>: ₹… / … (…), quoted …. The offer is active." (for a new product: "New product "…" created (Inactive, not
  configured yet) and added …").
- **Offer active switch** (`POST /suppliers/offers/{offerId}/active`, `manage_suppliers` + `record_market_prices`, CSRF;
  `active` = 1 / 0, `from` = suppliers / supplier): `SupplierOffers::setOfferActive()` (history "Offer active", audit
  `SUPPLIER_OFFER_ON/OFF`). The engine reads it live: switched off, the offer is not used for pricing, matching or lowest /
  highest (a product priced from it shows "The supplier price behind this product is switched off (inactive supplier or
  offer) — choose another supplier price in Configure." — D-365 follow-up wording); switched on, the same figures come back.
- **Code.** `SupplierController::index / editForm / addProductForm / addProduct / offerActive` (Node
  `supplierController.js`), `SupplierOffers::forSuppliers / listRow / countsBySupplier / existingOffer / priceShape /
  addProductChoices / createProduct / addOffer` (Node `supplierOffers.js`), views `suppliers/index`, `form`, `add_product`,
  `_add_product`, `_offer_switch`; `EditPriceController` accepts `from=suppliers`. CSS `.filter-pill`, `.offer-switch`,
  `.add-offer-*`.
- **Done in D-361** (below): Products list, Configure / Edit, Link a supplier, Link these prices, Global pricing settings physical
  block, Category price rules (margin / other costs / buffer), the quotable rule in Pick from Products, the Supplier PO supplier-choice table.

**Products, Configure and the Supplier PO (D-361, 13 Nov 2026, owner "go with recommendation"; Push 3 of 3; mockups
products_now_vs_after, p3–p8, decision_d1 part A, decision_d2, configure_three_levels_mockup).** Owner decisions of 13 Nov:
(1) the base price never changes by itself; (2) only `manage_product_catalog` configures, edits pricing values or activates a
product (creating one from a supplier stays `manage_suppliers` + `record_market_prices`, Inactive); (3) legacy supplier prices
with no supplier record are linked by name by the migration, the rest on "Link these prices".
- **Migration `2026-11-13b-products-fixed-base-and-link-legacy.sql`.** Every product with a base price: the stored copy is
  refreshed from the usable price "used for pricing" (as 13a), then `configured_base_follows_supplier` = 0 — fixed at exactly
  the price it uses today (no selling price changes; checked on both dev databases with the D-359 snapshot). Legacy supplier
  prices (`supplier_id` NULL) are linked where the typed name, ends trimmed and capitals ignored, equals exactly one ACTIVE
  supplier's legal name (history "Linked to supplier (migration 13b)"). New columns `catalog_products.legacy_no_supplier`
  (set on the first run for products with no linked supplier), `order_supplier_po.supplier_offer_id`, `quoted_unit_price_inr`,
  `price_override_reason`. Marker: Audit Log `MIGRATION_2026_11_13B` with the counts.
- **Where a product stands** (`SupplierOffers::status()`; SQL `USABLE_SQL`, `CONFIGURED_SQL`, `NO_SUPPLIER_YET_SQL`,
  `quotableSql()`). **Configured** = follows 0 with a base price. **No supplier yet** = `legacy_no_supplier` and no offer
  linked to a directory supplier (ends as soon as one is linked: `supplierLinked()`, audit `PRODUCT_SUPPLIER_LINKED`).
  **Quotable** (may be Active and picked) = No supplier yet, or Configured with ≥ 1 usable offer (plan rule 4). Statuses shown:
  Configured / Not configured / No active supplier / No supplier yet.
- **Products list (`/products`).** Columns Name · HS Code · Origin · **Suppliers** ("N active", "N paused") · **Lowest – highest
  supplier price ₹** (usable offers quoted like the base; each a link to that offer's Edit price `?from=products`, lowest green,
  highest red) · **Base price ₹ (configured)** ("not set" until configured; "≈ USD" for product editors) · the USD columns as
  before (worked out from the configured base; "Not configured yet." for a product that is not) · **Latest supplier quote** ·
  **Status** (Active / Inactive + the standing badge) · **Configure** (not configured) or **Edit**, **Link a supplier** (No
  supplier yet; `manage_suppliers` + `record_market_prices`), View · Duplicate, Reactivate only when quotable (else greyed with
  the reason; the reactivate route refuses it too). Filters: Active · Inactive · All · Not configured · No active supplier · No
  supplier yet (the last three across active and inactive, shown when not 0). Search also matches a supplier's name (typed or
  directory). Buttons: Categories · Category price rules · Global pricing settings · Fix HS codes · **Link these prices (N)** (only while N > 0) ·
  **Go to Suppliers**; "+ Add Product" and "Link suppliers" are gone from the page and the Products menu (their routes still
  work). Empty state "No products yet — 1. Add a supplier · 2. Add the products they offer · 3. Configure" with Go to Suppliers.
- **Configure / Edit (`/products/{id}/configure`, `manage_product_catalog`).** Left: **Base price (₹ / CBM, 50 mm and above |
  ₹ / SQM, 20 mm | ₹ / unit) *** — default the highest usable supplier price (its unit / thickness; configured products keep
  theirs), "Default = highest active supplier price (…). Lowest: …", a warning below the lowest ("Below the lowest supplier
  price — no supplier sells at this price."), **See all supplier prices (N)** (supplier, base price as quoted, dates, active /
  paused, Edit price `?from=configure`), and — when the highest usable price differs from the base and a supplier price changed
  after `configured_at` (`SupplierOffers::priceChange()`) — "Supplier prices changed: highest is now ₹X … **Use ₹X**"
  (`POST …/configure/use-price` → `setConfiguredBase()`). Right: the three-level table Setting | Global | Category: <name> |
  This product | Comes from (badge this product / category / global) with **reset** per own row (app.js puts back the inherited
  value; saved as empty), rows Margin %, Other costs (% or USD per unit), Buffer %, Weight t / CBM, Container CBM, Container max
  tonnes, Thickness factor; a value equal to the inherited one is not stored. **Reset all to category values** / **Reset all to
  global values** each open a confirm box (`<dialog>`) listing the figure changes ("Margin % 20% → 15%") and the values that only
  change level, Cancel / Reset (`POST …/configure/reset`, `PricingLevels::resetToCategory / resetToGlobal`). **Active** checkbox:
  switched on only when quotable ("It stays Inactive: Not configured yet — ask the product manager." otherwise). Save (`POST
  …/configure`): `setConfiguredBase()` when the price / unit / thickness changed (audit `PRODUCT_BASE_PRICE_SET`), each changed
  value (audit `PRODUCT_PRICING_CHANGED`), the Active switch (audit `PRODUCT_REACTIVATED / DEACTIVATED`). The product page gets
  **Configure / Edit pricing**. Details, specifications and images stay on Edit details.
- **Edit price** accepts `from=products` (back to the list) and `from=configure`. `refreshFollowedBase()` no longer fills a base
  price never configured, and "+ Add product" no longer refreshes a base price (decision 1).
- **Link a supplier (`/products/{id}/link-supplier`, decision 1 part A).** Supplier * (active directory suppliers; "+ new
  supplier" quick-add returns here with it picked), Their price (₹) *, For * (thickness) or Per * (unit), Quoted on *, Valid
  until; saved with `SupplierOffers::addOffer()` (as "+ Add product"); green "Linked. … The red "No supplier yet" badge is gone
  …". The configured base price stays.
- **Link these prices (`/products/link-prices`, `record_market_prices` or `manage_suppliers`, decision 3).** Every legacy price
  (`supplier_id` NULL): product, supplier as typed, price, dates, and a Supplier * drop-down (exact name pre-selected) with
  **Link** (`SupplierOffers::linkPrice()`: `supplier_id` and the legal name, history "Linked to supplier", audit
  `SUPPLIER_PRICE_LINKED`); "+ Add a new supplier (quick)" returns here.
- **Global pricing settings** — block **Default physical values for every category and product**: weight t / CBM (0.1–20), container
  max t (1–100), container CBM (0.5–100), thickness factor (1–1000), stored as 2.70 / 27.00 / 8.00 / 40.
- **Category price rules** (per category, MD, with a reason) — new **Margin, other costs and buffer** fields (empty = global; the
  placeholder shows the global value) and a **reset to global** button beside every value (empties it; then Save).
- **Pick from Products** offers only quotable active products (`ProductRepository::forPicker()`); products not configured or
  with no active supplier are listed greyed under "Not offered yet" with "— not configured yet — ask the product manager" /
  "— no active supplier — ask Procurement" (`notQuotable()`); a posted id must be quotable (`isQuotable()`).
- **Supplier PO terms form (decision 2).** Above the fields, per order line picked from Products: a table of every offer from
  a directory supplier (`SupplierOffers::forOrderPo()`): supplier, quoted ₹ per unit (at the line's thickness for a price for
  every thickness, else the base), valid until ("expired — confirm price" when past), PO value for the line quantity (when the
  units match), **Choose**; lowest first and marked; switched-off offers and inactive suppliers "not offered". Choosing fills
  Supplier, Unit Price (and the hidden quoted price and offer id), Quantity / Material when empty, Unit. A unit price different
  from the quote needs **Reason the price differs from the quote** (app.js `data-required-if-differs`; server: at least 10
  characters) — kept in `order_supplier_po.price_override_reason` with `quoted_unit_price_inr` / `supplier_offer_id`, audit
  `SUPPLIER_PO_PRICE_OVERRIDE`. The SUPPO document's layout and wording do not change; issued POs never change.
- **Code.** `ProductConfigureController` (Node `productConfigureController.js`), `SupplierOffers` D-361 section,
  `ProductRepository` statuses / `forPicker` / `notQuotable` / `isQuotable`, `PriceRules`, `PricingService::readSettingsForm`,
  `OrderSupplierPoRepository::setOfferChoice`; views `products/index`, `configure`, `link_supplier`, `link_prices`,
  `_price_rules`, `pricing/settings`, `orders/_product_picker`, `orders/show`; app.js `initConfigure` and the
  `data-required-if-differs` rule; CSS D-361 block. The Products list has no board view (no Kanban twin).

**Three small fixes (D-362, 13 Nov 2026, owner request).**
- **Row-action buttons.** "Edit price" / "Open product" (supplier page › Prices given for products, Suppliers list offer
  rows, Configure › See all supplier prices) sit in a `.row-actions` cell as small buttons (`btn-sm`, `btn-sm
  btn-secondary`): side by side, each on one line, wrapping as whole buttons below 760 px. Cause: an `<a class="btn-sm">`
  had only padding (no `display`, `white-space`, background), so the border of `btn-secondary` drew on an inline,
  wrapping link. CSS D-362 block (both stacks).
- **Products tabs.** `ProductRepository::openTab()` (Node `productRepository.openTab`): a status tab or filter chip with 0
  products redirects to Active (All when Active is 0 too), keeping the search and the flash message. Reactivate /
  Deactivate posted from the list (`return_to=list`, with the tab `status` and `q`) returns to that tab; when it is now
  empty it opens the tab that now holds the product — Active after Reactivate, Inactive after Deactivate. Deactivate on a
  product's page still returns to that page.
- **Names and one place.** `/pricing/settings` is **Global pricing settings** (note "Applies to every product unless a
  category or product changes it"); the category rules tab is **Category price rules** (note "Applies to every product in
  the category unless the product changes it"). Both are reached only from the Products screen's link bar (Categories ·
  Category price rules · Global pricing settings · Fix HS codes · Go to Suppliers); the menu items "Price rules",
  "Pricing settings (margin, buffer, exchange rate)" and "Exchange-rate history" are removed (Pricing ▾ keeps Commission
  levels and ladder, Specifications). Admin › Settings › Setup checklist's pricing row now opens Products ("Open Products ›
  Global pricing settings"). Other in-app links stay, with the new names. Routes and permissions unchanged; the left menu
  still marks Products on both pages.

**Products list buttons, category form, "Also work out" rows and Thickness rules (D-363, 13 Nov 2026, owner "go with
recommendation"; mockups ui3_u1–u4).**
- **Products list row actions.** The last column is a `.row-actions` cell of small buttons on one line (`.product-list-actions`):
  the main action filled — **Edit**, or **Configure** when the product is not configured — then **View** and **Duplicate**
  outlined (`btn-sm btn-secondary`); **Link a supplier** (filled), **Reactivate** and **Delete…** sit in the same row. No
  wrapping on desktop; whole buttons wrap below 760 px. A "cannot delete" reason shows under the buttons.
- **Add a category / category Name and attributes.** `.cat-attr-grid`: **Name \*** alone on the first row (half width), then
  the attributes two per row (1 | 2, 3 | 4, 5 | 6); one column below 760 px. Still exactly `MAX_ATTRIBUTES` = 6 boxes, no
  "+ add attribute". Placeholders: e.g. Size, Grade, Colour, Finish, Thickness, Packing (the category edit form uses the
  same layout and placeholders).
- **"Also work out from this … price"** (`products/_price_add`, inside "+ Add / update a price"; Edit price does not use
  it): one row per thickness — tick box · thickness · the worked-out price (live while the price is typed; until then
  "type the price to see it") · its % rule ("−20 % of 20 mm", "base", "× 40 of 20 mm per SQM") with a badge **category** or
  **this product** (the product's own value: its option override, or its own thickness factor). A thickness with a typed
  price is highlighted: "you typed ₹X on <date> — kept unless you tick". The per-row "change −20 %" links are replaced by
  one link under the list, **Change the % rules for this product** → `/products/{id}/configure#thickness-rules`. Field
  names (`thickness_mm`, `thickness_price`, `price_quoted_on`, `also[]`, `base`), the ticking rule (ticked = no typed
  price) and the maths are unchanged — a save posts and stores exactly the same rows as before (checked against HEAD).
  app.js `initThicknessCalc` hides `[data-also-amount]` until the amount can be worked out.
- **Configure › Thickness rules** (`#thickness-rules`, below the Configure form): table Thickness | Category | This product
  (input) | Comes from (badge) | reset, the base row as "base", and "50 mm and above × K" read-only (that factor is the
  "Thickness factor" row of the levels table). Save posts to the existing product Price options save
  (`POST /products/{id}/specifications/{specId}`, `manage_product_specifications`) with `from=configure`: a value equal to
  the category's follows the category (no own value), only this product's `product_option_overrides` rows are written —
  never `spec_options` (the category) or another product — and the page returns to Configure with "Saved for <product>
  only — other products keep the category's values." Options not shown (not offered, 50 mm and above) are posted back
  unchanged. **Reset all to category** opens a confirm box listing the changed rows and posts the same save with every
  shown value empty. "reset" on one row puts back the category value (then Save). Users without
  `manage_product_specifications` see the table read-only. The product form's "Price rules in effect for this product"
  links to it. Category price rules already carry "Applies to every product in the category unless the product changes it"
  (D-362). Code: `ProductConfigureController::thicknessRules` (Node `thicknessRules`), `SpecController::saveProductSpec`
  (`from=configure`), `pricingController.addView` (Node: `rule`, `isBase`, `own`), views `products/index`, `categories`,
  `category`, `_price_add`, `configure`, `_form`; CSS D-363 block. No migration. The Products list has no board view (no
  Kanban twin).

**Supplier prices typed per SQFT, one set of unit names, and the "Supplier performance" button (D-364, 13 Nov 2026, owner
"go with recommendation"; mockups units_a, units_b, sqm_sqft_mockup).** No database change, no migration.
- **Supplier price unit list: SQM · SQFT · CBM.** "+ Add product" (suppliers `_add_product`), Edit price (D-359) and Link a
  supplier (D-361) have a **per \*** drop-down: **SQM** and **SQFT** for a thickness below "50 mm and above" (or a product
  without thicknesses sold per SQM), **CBM** for "50 mm and above" (or a per-CBM price). The unit follows the thickness and
  back (app.js `initSqftNote`, `select[data-sqft-thickness]` options carry `data-unit` "SQM" / "CBM"). PCS, Ton and RMT are
  not offered here (they need the piece size or the weight; they stay on the order line). "+ Add / update a price"
  (`_price_add`) has no unit choice and is unchanged apart from its labels. A product whose own unit is SQFT (no
  thicknesses) keeps its price per SQFT.
- **SQFT is an input unit only.** On save the price per SQFT becomes the SQM price, ₹ per SQFT × 10.7639
  (`LineUnits::SQFT_PER_M2` / `lineUnits.SQFT_PER_M2`), 2 decimals, and is stored exactly as an SQM price is stored
  (`unit` "m2", the thickness price rows, `fob_value`) — nothing downstream changes. `SupplierOffers::fromSqft()` (Node
  `supplierOffers.fromSqft`) does it once for `addOffer()` (+ Add product, Link a supplier) and `save()` (Edit price);
  `sqmPriced()` says when SQFT is allowed; `sqftToSqm()`, `typedText()`, `notesWithTyped()`. The Edit price preview
  (`/products/suppliers/{id}/edit-price/preview?unit=SQFT`) converts the same way.
- **Live conversion under the field** (`p[data-sqft-note]`, `.sqft-note`): "₹185 / SQFT = **₹1,991.32 / SQM** (× 10.7639)
  — saved as the SQM price", shown while SQFT is chosen and a price is typed.
- **What was typed is kept:** the offer's notes get the line "typed ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)" (staff
  notes are kept; a later save typed per SQM drops the old line, a new SQFT save replaces it), the price history gets a row
  "Price typed per SQFT" with the same text, and the green message and the audit log say it too.
- **The same unit names on every screen: SQM, SQFT, CBM.** `LineUnits` LABELS (and app.js `UNIT_LABELS`) now read 'M2' =>
  'SQM', 'SQFT' => 'SQFT' (was "m²" / "sq ft"); every screen label "m²" / "per m²" / "sq ft" reads SQM / SQFT: supplier
  prices, the thickness rows ("Also work out"), the Products list, Configure, Market prices, the product page, Edit price,
  the suppliers pages, CHA + transport, price rules, Global pricing settings, specification price changes ("USD per
  SQM"), the order line's price check messages and the price history texts written from now on ("20 mm per SQM").
  **Documents, e-mails and Zoho are unchanged**: no document builder, template, `DocumentDataAssembler`, e-mail or Zoho
  payload reads these labels (documents print their own unit codes, `LineTable`); a QT, PI, OC, CI, PL and Supplier PO
  generated before and after (rolled back) have identical text on both stacks.
- **Order-line conversion verified** (product priced per SQM at 20 mm, quote 55.51 · lowest 53.89 · minimum 52.71): SQFT
  ÷ 10.7639 → 5.16 · 5.01 · 4.90; CBM × 50 (÷ 0.02 m) → 2,775.50 · 2,694.50 · 2,635.50; PCS 60 × 40 cm × 0.24 → 13.32 ·
  12.93 · 12.65; Ton × 18.5185 (CBM ÷ 2.70) → 1,027.96 · 997.96 · 976.11. The order form's live quote now uses only the
  newest answer (`refreshPrice` keeps a per-line sequence), so a late "Enter the width" no longer overwrites the hint.
- **"Supplier performance" button** (Procurement, next to the Status filter) is a normal small button aligned with the
  drop-down. Fixed at the root in the CSS D-364 block: `a.btn-secondary:not(.btn)` is an inline-block button (no
  underline, rounded); `form.btn-row` aligns its items to the bottom so links and small buttons line up with the
  drop-downs (also Associates › TDS and Edit price's Cancel).
- **Code.** `SupplierOffers` D-364 section, `SupplierController::readOffer` / `addProduct`, `EditPriceController`
  (`unit`, `sqmUnit`, preview), `ProductConfigureController::linkSupplier`, views `suppliers/_add_product`,
  `products/edit_price`, `products/link_supplier`, `orders/_product_picker_js`; app.js `initSqftNote`, `initAddOffer`,
  `initEditPrice`; tests `SqftSupplierPriceTest` / `sqftSupplierPrice.test.js`. Kanban: none of these screens has a board
  view (no Kanban twin).

**Products list short view and "Pricing ready?" rework (D-365, 13 Nov 2026, owner go; mockups plist2_t1, plist2_t2,
plist2_t3, plist_r).** No database change, no migration, no price changes (snapshots identical on both stacks).
- **Products list (default columns):** Name (link; grey line under it "HS code · origin · per <unit>"; the "Pricing: N
  to fix" and override badges kept) · Suppliers ("N active", "N paused") · Supplier prices (₹) — lowest – highest, each a
  link to the offer's Edit price, then "/ <unit> (<thickness>)" (`SupplierOffers::shortUnit`, e.g. "/ CBM (50 mm+)",
  "/ SQM (20 mm)") · Base price "₹180,000 / CBM" (`productRow()['base']['amount' / 'short']`; "≈ USD" kept for product
  editors) · Cost (USD) "our real cost" and Min price (USD) "never below" (highlighted, `col-key`) · Staff lowest (USD) ·
  Staff quote (USD) "/ unit" · Status · Actions (D-363). Sales staff keep Quote price / Your lowest. The HS Code and
  Origin columns moved under the name.
- **"Show all columns"** adds Associate lowest (USD) and Latest supplier quote (`col-extra`, shaded; hidden by CSS unless
  the table has `.show-all`). Remembered per user in the browser (localStorage key `products.showAllColumns.<user id>`,
  wrapped in try/catch; there is no per-user preference table, `users.ui_template_preference_id` is the template only).
  Without JavaScript the box submits `?cols=all` (server default = short view). app.js `initShowAllColumns`.
- **Layout:** the table sits in `.table-scroll.product-list-scroll`; every cell `white-space: nowrap`; the Name column is
  `position: sticky; left: 0` with a subtle shadow; narrow screens keep the page itself from scrolling sideways. CSS
  D-365 block.
- **Pricing ready? (`PricingService::readiness` / `pricingService.readiness`):** removed 'unit' ("Supplier price per
  <unit> entered and used for pricing"). Added 'base' "Configured base price set (₹X / unit, thickness)" (fails when not
  configured; button Configure, `SupplierOffers::configuredShort`) and 'suppliers' "At least one active supplier
  (names)" (fails with no usable offer — button "Add a supplier price" → Market prices #add-price; ℹ for a "No supplier
  yet" product — Link a supplier). Kept 'cbm', 'specs', 'current', 'exact'; 'cbm' when failing reads "No price for 50 mm
  and above (per CBM)" with "Add a 50 mm+ price" (a grid offer → `/products/suppliers/{id}/edit-price?thickness_mm=0`,
  which opens at 50 mm and above, unit CBM, price empty; otherwise `/products/{id}/market-prices?basis=cbm[&supplier_id=]
  #add-price`, which picks "per CBM" or, in a grid category, the 50 mm and above thickness) and "Not sold at 50 mm+" →
  the product's Thickness specification edit (`/products/{id}/specifications?edit={spec}`), the existing per-product
  "offered" switch (`product_option_overrides.is_hidden`). 'current' when failing → Edit price of the offer used for
  pricing. 'associates' is ℹ (not counted) while no associate is active — "Not open to associates yet — no associate in
  the system", link Associates › Access levels; with one or more active associates it is ✘ as before ("Give price
  access": that associate's Price access when there is one, else Associates). Items carry `state` ('ok' | 'fix' |
  'info'), `info`, and for 'cbm' `link2` / `link2_text`; `to_fix` = `countToFix()` (Procurement's filtered list too).
  Order: base, suppliers, current, specs, cbm, exact, associates. View `_pricing_ready` (✔ / ✘ / ℹ list,
  `ul.ready-list`).
- **Dev data, "to fix" before → after:** Absolute Black Granite Slab 0 → 0; MM Sentinel Product 3 (unit, cbm, current)
  → 3 (base, current, cbm; suppliers ℹ "No supplier yet"); Black Galaxy Granite Block (P1) 0 → 0; E2E Priced Granite
  Block 1 (cbm) → 1 (cbm); Black Galaxy Granite D-255 / D-256 demos 0 → 0; Tan Brown Granite 1 (current, stale) → 1.
- **Code.** `PricingService::readiness` / `countToFix`, `SupplierOffers::shortUnit` / `configuredShort` / `productRow`,
  `ProductController::index` / `show`, `EditPriceController::form` (`thickness_mm`), `PricingController::marketPrices`
  (`prefillBasis`), views `products/index`, `_pricing_ready`, `edit_price`, `market_prices`, `_price_add` (`pickMm`),
  Node twins; app.js `initShowAllColumns`; CSS D-365 block. The Products list has no board view (no Kanban twin).
- **Follow-up the same day (owner): the list fits the page.** Compact type, the unit on its own small line, tighter
  padding and stacked row buttons make the default view exactly the page width at 1440 px, so Staff lowest, Staff
  quote, Status and Actions are visible without scrolling (CSS only, both stacks). The "not priced" reasons from
  `PricingService::figures()` / `pricingService.figures()` no longer mention the removed "used for pricing" step:
  "Not configured yet — set the base price in Configure."; "The supplier price behind this product is switched off
  (inactive supplier or offer) — choose another supplier price in Configure."; "No price for 50 mm and above (per CBM)
  yet — add a 50 mm+ supplier price, or set a manual price." No migration.

### 7.2 Order-line pricing and MD approval (D-245 P2)

`OrderLinePricing` checks each line against the floor of **whoever sells the order**:

- **staff**, or
- the **client's associate** (if the order is one the associate earns on), at their commission level. The level is the one fixed on the order at PI, else their fixed level, else the ladder step for their next counting order.

**Which lines are checked.** Goods lines picked from Products on a **USD** order. Not checked: hand-typed lines, service and charge lines, TBC-price lines, non-USD orders. With "Goods lines must be picked from Products" on (`require_product_pick = 1`, seeded on), a typed goods line on a USD order is refused in the browser and on the server.

**Line statuses.**

| Status | Rule | What happens |
|---|---|---|
| OK | price ≥ floor | Nothing. |
| Below floor | minimum ≤ price < floor | The line can be saved. It is sent to the MD (in-app notification, "Ask MD to approve line N"). QT and PI are blocked until approved. |
| Below minimum | price < minimum | **Save refused** (`saveRefusal`). Nobody may approve it. To sell lower, the MD changes the product's margin (logged). |
| No price | product has no build-up | Needs MD approval before QT/PI. |
| CBM price worked out (`cbm_calculated`, D-338) | price ≥ floor, but the line is priced per CBM (50 mm and above) from a supplier row whose 50 mm+ price is only CALCULATED from a thinner base (no typed per-CBM price) | Sent to the MD exactly like a line below the floor ("Ask MD to approve line N", notification, Approvals › Prices "Price — CBM worked out", approval page "Reason: CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted" — the base and 50 mm figures follow the row and the rules). QT and PI wait for it. A typed CBM price needs no approval. Worked out from the build-up (`ThicknessPrices::cost()` returns `how`, `calc_from_mm`, `cbm_from`; `liveFigures()` adds `cbm_calculated`); no new column. |
| Approved | MD approved this exact price and options | Passes. |

**A line "As per annexure" that needs its size (D-338, 1c).** When a line ticked "As per annexure" is priced in a unit that needs the piece size to be compared (e.g. a per-SQM product on a CBM line), the refusal is exactly *Line N says "As per annexure", so its size is not known — type the price per CBM for this line.* (the unit word follows the line's unit: "per SQM", "per piece" …; `OrderLinePricing::annexureSizeMessage()` / `annexureSizeMessage()`). The same text when the line is saved (`saveRefusal(…, $annexureLineNo)`; shown without the "Product line:" prefix), on the Quotation / PI gate (`documentBlockReason()`), in the price check and on the MD's approval page.

Comparisons allow 0.0001 for floating noise.

**MD approval** (`approve()`): reason 10–500 characters; refused if below the minimum; refused if the MD set the price personally and Users & roles › Self-approval rules (`price_discount`) do not allow it (seeded: MD and ED may self-approve). On approval the line stores who, when, the reason, the approved price and options, `price_approval_commission_zero` (an associate's line earns **0 commission**), and `price_approval_incentive_cut` (shown as an explanation only — the incentive is a % of real profit, which the lower price already reduced, so it is never subtracted twice). **Any later price or option change voids the approval.**

**Fixed at PI.** When the PI is issued, `snapshotForPi()` stores each checked line's figures in `order_products.price_snapshot`, and `AssociateCommission::fixLevelAtPi()` fixes the associate's level on the order. Later changes to settings or supplier prices do not move that order. A line filled from a valid associate price request keeps the request's figures.

**Documents gate.** `documentBlockReason()` refuses the QT and the PI while any checked line is below its floor, below the minimum, or has no price, unless MD-approved.

### 7.3 Associate commission (D-245 P3, D-235, D-252)

Associates are the only partner type and the only people outside the company who earn on orders.

**Levels and ladder (seed).**

| Level | Rate | Ladder step (counting orders) |
|---|---|---|
| Level 1 | 2 % | 1–5 |
| Level 2 | 5 % | 6–10 |
| Level 3 | 7 % | 11–20 |
| Level 4 | 10 % | 21 onward |

A level may also be a fixed USD amount per unit. Per associate the MD chooses **Standard ladder** (default), **Own ladder** (a copy of the standard ladder, changed for that associate; it overrides the standard one) or **Fixed %** — with a reason, new orders only (D-291). Ladder steps start at 1, have no gaps or overlaps, and only the last is open-ended (`PricingService::readLadderForm`). A used level can only be deactivated.

**Counting order.** A fully paid order whose in-range invoiced FOB in INR is at least `associate_counting_threshold_inr` (₹5,00,000). A smaller order earns the current step and does not count.

**Formula** (`AssociateCommission::forOrder()`):

1. Basis = the **final invoiced FOB of the goods lines**, line by line. No freight, insurance, services or charges. A line the MD approved below the floor earns 0 and is out of range.
2. Commission per line = FOB × level % (or quantity × USD amount).
3. Only an **agreed discount** or a **settled buyer claim** lowers the basis (when a short payment is cleared, staff must say which). A shortfall marked **not paid by the buyer** means the order is not fully paid: **commission 0** (no pro rata).
4. Convert to INR at the **realised rate**: (INR credited + rupees the bank kept as charges) ÷ foreign amount received, over advance, milestones, balance and LC money. Bank charges never lower the commission. Until every receipt has its INR, the rate is an estimate (recorded rate; nothing received → expected rate, else the pricing rate).
5. A USD-per-unit level on a non-USD order converts at the USD rate: the PI-frozen pricing rate `orders.pricing_fx_rate_at_pi` (D-262), else the current pricing rate.

**Payment timing.**

| Setting | Default | Rule |
|---|---|---|
| `associate_commission_due_days` | 30 | Due date = date of full payment + 30 days. |
| `associate_claim_hold` | 1 (on) | Held while a buyer claim opened on or before the due date is open. |
| `associate_payout_run`, `associate_payout_day`, `associate_payout_holiday_rule` | monthly, last, before | Paid in the first payout run on or after the due date (monthly day 1–28 or `last` = the last day of the month, or weekly day 1–7); a run day on a Sunday or a Holiday Calendar day moves to the working day before (or after) (D-292). |
| KYC (D-252, D-290) | — | **Mark paid** is refused until the associate's KYC is complete (address proof: not before `address_proof_due_on` for associates accepted before 11 Nov 2026). |

Take-back after payment only for fraud or breach, with a reason (audited).

**GST on top.** An associate with a GSTIN, whose tax invoice for the payout is on file, gets GST at `associate_gst_pct` (18 %) on top. Once paid, it is worked on the gross actually billed (paid + TDS), so the GST paid equals the GST on the Zoho bill. GSTIN and GST % are frozen on the payout at lock (D-262). In Zoho the bill carries the tax `zoho_gst_commission_tax_id`; without it the sync refuses that bill (D-256).

**TDS (D-235, `AssociateTds::compute`).**

| Case | Rule |
|---|---|
| Associate in India, FY total ≤ ₹20,000 | No TDS. |
| The payout that takes the FY (April–March) past ₹20,000 | TDS on itself **plus a catch-up** on that year's earlier commission paid without TDS. |
| Later payouts that FY | TDS on the full amount. |
| Rate | 2 % under section 194H (setting `associate_tds_section`); **20 % under 206AA** if no PAN or the PAN card is not accepted. |
| Rounding | TDS rounded to the **nearest rupee** (section 288B). |
| Cap and carry (QA batch 4, MON-1) | TDS is never more than the payout. What the cap cuts off is stored as `tds_shortfall_inr` and **carried into the associate's next payout in the same FY**. |
| Associate abroad | No TDS by default (`associate_tds_foreign = 0`), but Mark paid is refused until the TRC, Form 10F and no-PE declaration are accepted and the payout's Form 15CA (and 15CB, `associate_15cb_required = 1`) is attached. |

About **section 195** (payments to non-residents): the code has **no section-195 rule**. With `associate_tds_foreign = 0` (seed) an abroad payout has no TDS once the TRC, Form 10F and no-PE declaration are accepted (Mark paid still needs Form 15CA/15CB). If `associate_tds_foreign` is switched on, an abroad payout runs through exactly the India logic above (same threshold, catch-up, `associate_tds_section` and rate), not a separate 195 / DTAA rate (`AssociateTds::compute`). Whether that is right is a CA question (`docs/external/CA_questions.md` Q4, §14.5); until it is answered, do not pay any associate abroad.

**Worked example 3 (commission).** USD order, goods FOB USD 20,000, all in range, associate at Level 2 (5 %).

| Item | Working | Result |
|---|---|---|
| Commission (USD) | 20,000 × 5 % | USD 1,000.00 |
| Receipts | advance USD 6,000 credited ₹5,04,000; balance USD 14,000 credited ₹11,75,300 after ₹700 bank charges | — |
| Realised rate | (5,04,000 + 11,75,300 + 700) ÷ 20,000 | ₹84.00 |
| Commission (INR) | 1,000 × 84.00 | **₹84,000.00** |
| Fully paid 10 Jan 2027 | due 9 Feb 2027; monthly run on the last day of the month (D-292; 28 Feb 2027 is a Sunday → the working day before) | paid in the run of **27 Feb 2027** |
| TDS (PAN verified, first payout of the FY) | round(84,000 × 2 %) | ₹1,680 |
| Net | 84,000 − 1,680 | ₹82,320.00 |
| GST (GSTIN + tax invoice) | 18 % × (82,320 + 1,680) | ₹15,120.00 |
| Bank payment | net + GST | **₹97,440.00** |

**Catch-up example.** Earlier this FY: ₹15,000 paid, no TDS. New payout ₹10,000 → FY total ₹25,000 > ₹20,000 → TDS = round((10,000 + 15,000) × 2 %) = ₹500; net ₹9,500.

**Shortfall carry example.** Earlier this FY: ₹19,900 paid, no TDS. New payout ₹400 → TDS due = round((400 + 19,900) × 2 %) = ₹406 → TDS = ₹400 (capped), net ₹0, ₹6 carried to the next payout.

**Other rules.** Forcing "associate: yes" on an order is MD only with a 10–300 character reason. A buyer registration with no order within `associate_protection_months` (6) lapses in the daily job. The order's cost is always the **gross** commission; GST on commission is input credit (not a cost) and TDS is a liability.

### 7.4 Team incentive (D-245 P4, D-259)

**Amount** (`IncentiveService::calculate()`):

```
profit before = order profit before commission and incentive
basis         = max(0, profit before − associate commission)
amount        = MD's fixed ₹ for this order, or
                MD's % of basis for this order, or
                (program off) 0, or
                global rule: % of basis (default 15 %) or USD per unit × quantity × USD rate
```

A product with its own incentive rule uses it for its lines, weighted by the line's share of the goods FOB. When the program is off, the MD may still set an amount by hand on one order (reason required).

**Checks.** Warning if the margin after commission and incentive falls below the minimum margin (20 %). Refusal if the amount is more than the basis (the whole profit).

**Role split (seed, `incentive_roles`).**

| Role | Usual share |
|---|---|
| Sales | 30 % |
| Sales Manager | 10 % |
| Export Documentation | 15 % |
| Export Compliance | 5 % |
| Marketing | 15 % |
| Logistics | 15 % |
| Accounts | 10 % |

Active shares must total 100 %. A used role can only be deactivated.

**Per-order overrides** (`split()`): the MD ticks which roles get it. Unticked roles, and roles with nobody, spread their share **proportionally** over the ticked roles. Custom shares (totalling 100 %) win over usual shares. People per role: those set on the order, else the order's assignees with that role, else everyone with that role. Inside a role the amount is split equally. The last role and the last person take the rounding, so the parts add up to the amount exactly. One person may hold several roles.

**Eligibility and caps (D-259, 6 Nov 2026).**

- `users.incentive_eligible` (empty = follow the designation) overrides `department_designations.incentive_eligible` (default eligible). Only the MD changes either.
- Caps per role: `incentive_roles.cap_per_order_inr` and `cap_per_month_inr` (empty = no cap). The monthly cap counts what the person already has locked in that role for the month the order would lock into.
- A person not eligible gets nothing. Anything above a cap is **kept by the company**, never shared out to others. Kept amounts are stored per person (`incentive_kept`) and on the order (`team_by_share_inr`, `kept_not_eligible_inr`, `kept_capped_inr`).
- Only orders not yet locked are affected. With no caps and everyone eligible, every figure is unchanged.

**Worked example 4 (incentive).** Profit before commission & incentive ₹3,00,000; associate commission ₹84,000 (example 3).

| Item | Working | ₹ |
|---|---|---|
| Basis | 3,00,000 − 84,000 | 2,16,000.00 |
| Amount (global 15 %) | 2,16,000 × 15 % | **32,400.00** |
| Sales 30 % | | 9,720.00 |
| Sales Manager 10 % | | 3,240.00 |
| Export Documentation 15 % | | 4,860.00 |
| Export Compliance 5 % | | 1,620.00 |
| Marketing 15 % | | 4,860.00 |
| Logistics 15 % | | 4,860.00 |
| Accounts 10 % (takes rounding) | | 3,240.00 |
| If Marketing is unticked | Sales gets 30 ÷ 85 × 32,400 | 11,435.29 |
| If Sales has a ₹8,000 per-order cap | Sales gets 8,000; company keeps | 1,720.00 |

**Worked example 4b (eligibility and caps; the D-259 test case `IncentiveEligibilityTest` / `incentiveEligibility.test.js`).** Basis ₹4,00,000 × 15 % = ₹60,000 by share. Sales share ₹18,000, capped at ₹15,000 → ₹3,000 kept. Sales Manager (₹6,000) not eligible → ₹6,000 kept. Logistics ₹9,000, but only ₹6,000 left under its monthly cap → ₹3,000 kept. Paid ₹48,000; kept by the company ₹12,000.

**States: open → ready → locked → paid.**

| State | Rule |
|---|---|
| Open | Estimated; MD can change it any time. |
| Ready to lock | Buyer fully paid every leg (advance or LC, milestones, balance, freight on CFR/CIF), no buyer claim open. |
| Locked | Only the MD locks (one order, or "Lock all ready"). Nothing locks by itself. Associate commission locks with it. Lock writes the payouts and the Commission cost entries. The order row is claimed `SELECT … FOR UPDATE`, so two lock clicks write once. |
| Paid | Accounts pays (Paid via + reference required, D-212). Never changes again. |

Unlock needs a reason, and only until anything is paid. Staff are paid in the payout run after the lock date. The MD is reminded `lock_reminder_days` (3) before each run. Every change goes to `order_incentive_history`.

### 7.5 Profit layers: estimated, locked, paid (D-245 P5)

`OrderProfitabilityService::computeForOrder()` is the **one profit calculation** used by the order page (Step 12), the Order Profitability report, the CAFIN CSV and the incentive.

**Base profit.**

```
revenue (INR) = goods FOB at the real settled rate per leg (else that leg's own rate)
              + bank charges added back where INR credited is known
              + freight leg on CFR/CIF (the Debit Note reimburses freight)
              + payment milestones (QA batch 4, MON-2)
              + LC extra
cost (INR)    = supplier PO total payable
              + freight and CIF insurance (order currency × rate, D-225; ocean freight bills replace the rate)
              + logistics partner bills (tax invoices; proformas only for what was paid, never both)
              + order cost entries (CHA, bank charges, commission, …)
              + order expenses (real travel replaces the provisional travel %)
              + Zoho Books expenses linked to the order (duplicate guard)
profit        = revenue + export benefits − cost
margin %      = profit ÷ revenue × 100
```

Insurance is a seller cost on **CIF only** (on FOB/CFR the buyer insures, D-049). A freight amount with no rate is left out and flagged "not counted yet", never added as rupees (D-225).

**Incentive layer** (`incentiveLayer()`):

```
profit before commission & incentive
 − associate commission     (Estimated / Locked / Paid / Held)
 = profit for the incentive
 − team incentive           (Estimated / Locked / Paid / Held)
 = profit after
```

- **Open orders carry the estimate**, so profit is never overstated.
- A **locked** figure replaces the estimate; it is never added on top. (Locked amounts are already inside the cost entries and are taken out before the layer is applied.)
- Cancelled or lost orders carry 0.
- Only holders of `manage_incentives`, `pay_incentives` or `view_price_buildup` see commission, incentive and profit after. Others see profit before (it would reveal their sum).
- Reports offer **Include open (estimated)** or **Locked only**.
- The **CAFIN PDF is deliberately unchanged**: it prints locked figures only. Changing it needs the owner's double confirmation.

### 7.6 Exchange rates

There are four different rates. Do not mix them up.

| Rate | Where it comes from | Used for |
|---|---|---|
| **Pricing FX** (`pricing_settings.pricing_fx_rate`, seed 82.0000; since D-301 the daily `fx_rates` rate with `fx_mode` auto) | MD, Products › Global pricing settings. Automatic (default): the market rate fetched daily at 07:05 IST minus the cushion `fx_cushion_pct` (2 %); a daily change over 2 % waits for the MD. `pricing_fx_rate` is used in Manual mode and until the first fetch. | Turning supplier ₹ into USD prices. Converting USD-per-unit commission/incentive on non-USD orders. Frozen at PI approval as `orders.pricing_fx_rate_at_pi` (D-262). |
| **Recorded remittance rate** | Worked out: INR credited ÷ foreign amount (D-244). Typed only when "INR not known yet" is ticked. | Revenue, realised rate, D-212 descriptions. |
| **Bank's rate on the advice** (optional) | Typed from the bank advice. | Checking the INR credited. |
| **MTT USD rate** (`mtt_usd_inr_rate`, 84.0000) | Merchant Trade division setting. | Merchant margin in USD when neither leg is USD. |

**Rate gap (D-244, `RemittanceInr`).** gap = amount × bank's rate − INR credited.

- |gap| ≤ ₹1 → saved silently (rounding).
- |gap| > ₹1 → the user must choose:
  - **bank_charges** — the gap is booked at once as a **Bank Charges** order cost with a D-212 description; the receipt is valued at the bank's rate, so the charges count once (as cost) and profit adds them back to revenue. Only when less arrived.
  - **typo** — refused, nothing saved.
  - **other** — a 10–255 character reason kept on the receipt (audit `REMITTANCE_INR_GAP_EXPLAINED`).

Example: USD 10,000 received, bank's rate ₹84.10 → expected ₹8,41,000; credited ₹8,40,250 → gap ₹750 → "bank charges" → ₹750 cost; revenue counts ₹8,41,000.

**Amount differs from what is due (`RemittanceDifference`).** A difference of more than 1 % (at least 1 unit) on the first receipt of a leg, or anything above what is still due, needs a reason from an Admin-managed list: bank charges (the leg counts as paid in full, charges become an order cost and go to Zoho with the customer payment), short payment (cleared later with a manager's reason and a kind — agreed discount / settled claim / not paid), excess. `max_bank_charges_pct` (3) caps how much may be treated as bank charges.

### 7.7 CAFIN logic and milestone money

**CAFIN** (CA Financial Annexure, internal only) is the complete money snapshot of one order, built by `CaFinancialSnapshotService::build()`. Every value is formatted there, so PHP and Node print identical text.

| Section | Content |
|---|---|
| A | Order details, products, money received from the buyer |
| B | Supplier cost, bills and payments (Proforma vs Tax invoice, "asks ₹X now", "replaced by tax invoice, not a cost", D-111) |
| C | Every expense: freight and insurance (C1), logistics bills (C2, tax invoices only), other order costs incl. Commission lines for incentive and associate commission (C3), order expenses, Zoho Books expenses (C5) |
| D | Government benefits (RoDTEP, RoSCTL, drawback …) |
| E | Profit, with the cash position. **CAFIN profit equals the order-page profit** (D-110). |
| F | Open items for the CA |

Rules: generated automatically at **13h Mark Fully Settled** (D-108; a failure never blocks settlement); can be generated any time from the CAFIN box (D-107, `ca_internal_doc_manage`: Admin, MD, ED, Finance Manager/Director); seeing and downloading needs `view_cafin` (D-109; seed: Admin, MD, ED, Accounts Executive, CA, Finance Manager, Finance Director); LC money counts as money received (D-112); CSV export with `_INTERNAL` file names (D-113). Prints locked incentive figures only (D-245 P5). Never to the client, never to Zoho (rule R10, §10).

**Payment milestones (D-215).** Up to 4 milestones between advance and balance; advance + milestones + balance = 100 %; the balance is what is left. Each milestone is its own payment step (remittance with bank reference, clear, Zoho). The balance cannot be cleared until every milestone is cleared.

Milestone INR in profit (`PaymentMilestoneRepository::inInr`):

| Case | INR used | Estimated? |
|---|---|---|
| INR credited known | credited + bank charges | No |
| Received, rate known | received × rate | Yes |
| Not received | planned (FOB × %) × fallback rate (balance rate, else advance rate) | Yes |

Example: FOB USD 20,000, milestone 2 = 30 % "on loading" → planned USD 6,000; received USD 6,000, credited ₹5,03,400 → ₹5,03,400 in revenue.

### 7.8 Domestic GST (D-257 Phase 2)

`GstCalculator::compute()` works in **whole paise (integers)**, so there is no floating-point error.

- **Same state** (seller's GST state code = place of supply): **CGST + SGST**, each half the rate.
- **Other state**: **IGST** at the full rate.
- Per line: taxable = quantity (3 decimals) × rate (2 decimals), rounded to the paisa; each tax rounded to the paisa, half away from zero. Totals are sums of lines. An HSN summary groups by HSN + rate.
- **Round-off**: the invoice total is rounded to the rupee; the difference is the "round off" line.
- GSTIN check: format plus the base-36 check character (`isValidGstin`).
- Rates: Settings › Lists › GST rates (`gst_rates`, seed HSN 6802 = 18 %); DOM default `dom_default_gst_rate` 18. To be confirmed by the CA.

**Worked example 5 (GST).** Line 1: 10.000 × ₹1,234.50; line 2: 3 × ₹999.99; 18 %.

| | Same state | Other state |
|---|---|---|
| Taxable | 12,345.00 + 2,999.97 = 15,344.97 | 15,344.97 |
| Tax line 1 | CGST 1,111.05 + SGST 1,111.05 | IGST 2,222.10 |
| Tax line 2 | 269.9973 → CGST 270.00 + SGST 270.00 | 539.9946 → IGST 539.99 |
| Gross | 18,107.07 | 18,107.06 |
| Round off | −0.07 | −0.06 |
| Invoice total | **₹18,107.00** | **₹18,107.00** |

Per-line rounding of each half means CGST + SGST can differ from IGST by a paisa. This is expected.

### 7.9 Import: landed cost and duty (D-257 Phase 3)

`ImportService::compute()` (integer paise; rates to 4 decimals):

```
value (INR)      = quantity × unit price (FC) × customs exchange rate
freight, insurance, CHA + port + other are spread over the lines by value
assessable value = value + freight share + insurance share
BCD              = assessable × BCD %
SWS              = BCD × SWS %            (social welfare surcharge, on the duty)
IGST             = (assessable + BCD + SWS) × IGST %   — input credit, NOT a cost
landed cost      = assessable + BCD + SWS + charges share
landed per unit  = landed ÷ quantity (4 decimals)
suggested price  = landed per unit ÷ (1 − imp_margin_pct)   (before GST)
```

Duty rates: the longest matching HSN prefix in Settings › Lists › Import duty (seed: 6802 → BCD 20 %, SWS 10 %, IGST 18 %), else IMP defaults (BCD 10, SWS 10, IGST 18). All to be confirmed by the CA and the customs agent. Duty and landed cost are **frozen** when the import moves to "cleared"; goods enter stock at landed cost (weighted average, never below zero).

**Worked example 6 (import).** 100 units × USD 50 at ₹84.0000; freight ₹20,000; insurance ₹2,000; CHA/port/other ₹15,000; HSN 6802.

| Item | ₹ |
|---|---|
| Value | 4,20,000.00 |
| Assessable | 4,42,000.00 |
| BCD 20 % | 88,400.00 |
| SWS 10 % of BCD | 8,840.00 |
| IGST 18 % of 5,39,240 (credit) | 97,063.20 |
| Landed | 4,42,000 + 88,400 + 8,840 + 15,000 = **5,54,240.00** |
| Landed per unit | 5,542.4000 |
| Suggested price at 20 % | 5,542.40 ÷ 0.80 = **6,928.00** |

### 7.10 Merchant trade margin (D-257 Phase 4)

`MerchantTradeService::money()`: two legs, each in its own currency (buy = paid to the supplier, sell = received from the buyer).

```
margin (INR)   = INR received on the sell leg − INR paid on the buy leg
leg rate       = leg INR ÷ leg FC
USD rate       = sell leg rate if the sale is in USD, else buy leg rate if the purchase is in USD,
                 else mtt_usd_inr_rate (84.0000)
margin (USD)   = margin INR ÷ USD rate
margin %       = margin INR ÷ sell INR × 100
contract margin = sale − purchase (only when both legs share a currency)
```

**Worked example 7.** Buy USD 40,000 paid at ₹83.50 = ₹33,40,000; sell USD 46,000 received at ₹84.00 = ₹38,64,000. Margin ₹5,24,000 → USD 6,238.10 (at 84.00) → 13.56 %. Contract margin USD 6,000.

RBI checks (to be confirmed with the AD bank): whole deal within 9 months, outlay within 4 months, no unbacked supplier advance above USD 5,00,000 (refused).

### 7.11 Rounding parity between PHP and Node

Floating point differs slightly between languages, so the code avoids relying on it.

| Method | Where | Rule |
|---|---|---|
| Decimal-string half-up | `PricingEngine::round2`, `pricingEngine.round2`, `money.js round2` | Format to 9 decimals, then round on the string: 1,326.125 → 1,326.13; 75.225 → 75.23; −12.345 → −12.35. Matches PHP `round($x, 2)` (half away from zero). |
| Integer paise | `GstCalculator`, `ImportService`, `MerchantTradeService` | Amounts parsed from strings into integers (`scaled()`), divided with `roundDiv()` (half away from zero), printed with `money()`. |
| Rupee rounding | TDS | `round(x, 0)` (section 288B). |
| Display | `format2()` (Node) = PHP `number_format(round($x,2),2)`; `GstCalculator::inr()` for Indian grouping (12,34,567.00); generated documents: `MoneyFormat::amount()` / `moneyFormat.amount()` (Indian grouping for an INR order, D-343) | |

Why: the fresh-eyes review (M3, 5 Nov 2026) found Node's older `Math.round(x + EPSILON)` rounded some halves down and negative halves towards zero, so commission, incentive, TDS and CAFIN could differ by a paisa. Five worked example orders are checked to the paisa on both stacks: `PHP_MySQL_Version/app/tests/*/D245WorkedExamplesTest.php` and `NODE_MySQL_Version/app/tests/*/d245WorkedExamples.test.js`.

### 7.12 The D-212 money description rule

**Rule.** Every money record — payment received or made, bill, expense, cost, payout, refund, export benefit, journal — carries a description, **in the app and in Zoho**, that says:

1. **what it is** (advance / balance / freight / LC / milestone / supplier advance / CHA final / incentive / benefit …);
2. **for which order and party**;
3. **what it settles** (invoice / bill / claim / payout no.);
4. **the bank reference** (UTR / SWIFT / FIRC / scrip no.) whenever money moved through a bank.

The app **builds** it from the facts, so it is never blank or wrong. Staff notes are **appended** ("· note: …"), never replace it. A money form refuses to save without the bank reference where one exists. Each new money flow needs tests that check the exact wording on both stacks.

**Why.** The 31 Oct 2026 audit found buyer remittances and LC money with no bank reference, payouts with no payment reference, optional cost descriptions, and generic texts. In Zoho, the CA could not tell what a payment was for.

**Sample texts** (placeholders in angle brackets). Amounts inside descriptions use `number_format` grouping on both stacks (₹185,400.00, not ₹1,85,400.00), so the app and Zoho texts match character for character:

| Record | Text |
|---|---|
| Buyer advance (`PaymentDescription::buyerPayment`) | `Advance received from <Buyer> for order <ref> · USD 1,600.00 at ₹84.10 · against Proforma Invoice <PI no.> · bank ref <UTR>` |
| Buyer balance with charges | `Balance received from <Buyer> for order <ref> · USD 14,000.00 at ₹83.95 · ₹700.00 bank charges deducted by the banks · against Commercial Invoice <CI no.> · bank ref <UTR>` |
| Buyer freight | `Freight received from <Buyer> for order <ref> · USD 1,070.00 at ₹90.00 · against Debit Note <DN no.> · bank ref <UTR>` |
| What a receipt settles (QA MTX-004) | The Zoho invoice it is applied to; before that, the app's own issued (approved / sent) document: freight the Debit Note, the balance and LC money the Commercial Invoice, an advance — or a balance before the CI — the Proforma Invoice. |
| LC money | `LC money received from <Buyer> for order <ref> · LC <no.> (<bank>) · USD 20,000.00, ₹1,679,300.00 credited after ₹700.00 bank charges · against Commercial Invoice <CI no.> · bank ref <ref>` |
| Milestone | `Milestone 2 (30% on loading) received from <Buyer> for order <ref> · USD 6,000.00 at ₹83.90 · against Commercial Invoice <CI no.> · bank ref <UTR>` |
| Bank charges cost (D-244) | `Bank charges deducted in rupees from <Buyer>'s <leg> remittance for order <ref>: USD x at the bank's rate ₹r = ₹y, credited ₹z, charges ₹g · bank ref <UTR>` |
| Associate payment (`AssociateTds::paymentDescription`) | `Associate commission for <Associate> — order <ref> — gross ₹84,000.00, TDS u/s 194H ₹1,680.00 (2%), net paid ₹82,320.00 + GST ₹15,120.00 on the associate's tax invoice (input credit), total paid ₹97,440.00 · UTR <ref>` (an associate abroad: `· SWIFT ref <ref>`) |
| Associate payout cost | `Associate commission to <Associate> on order <ref> (payout no. <n>, settles their commission invoice for this order): ₹84,000.00 + GST ₹15,120.00, TDS ₹1,680.00 · bank ref <ref>` |
| Supplier advance on a proforma | `Supplier advance payment for order <ref> · Supplier PO <no.> · against Proforma <PI no.> · bank ref <UTR>`; once applied in Books to the tax invoice that replaced it (QA ZOH-006): `… · against Tax invoice <no.> (advance paid on Proforma <PI no.>) · bank ref <UTR>` |
| Inspection fee | `Pre-shipment inspection fee — <agency>, invoice <no.>` |
| LC loan interest | `Bank loan interest (LC order) · LC <no.> · loan ₹1,000,000.00 at 9.5%` |
| Travel % | `Travel expenses (factory visits) — 350 km to the factory, 1.5% of the order value USD 20,000.00 at ₹84.00` |
| GST refund | `GST refund under LUT, Oct–Dec 2026, claim <ARN> · provisional · ₹185,400.00 credited 12 Feb 2027 · bank ref <ref>` (the Zoho journal line itself reads `GST refund under LUT, claim <ARN>`) |
| Import supplier | `Import supplier advance of USD 5,000.00 (₹420,000.00 at 84.0000) to <Supplier> (<Country>) against import PO <ref>, SWIFT/UTR ref <ref>` |
| Merchant trade | `Merchant trade supplier advance of USD … (₹… at …) to <Supplier> (<Country>) against merchant trade deal <ref>, PO <no.>, backed by letter of credit <ref>, SWIFT/UTR ref <ref>` |

Incentive payouts need **Paid via** + **reference** (added to the Commission cost line and so to the Zoho expense). A staff-added cost needs a description of 10+ characters. An export benefit received needs its UTR / e-scrip no. A refund needs its paid date and bank reference. An expense typed in Zoho without a description is flagged red in the app.

---

## 8. Generated documents

Documents are rendered from templates in `app/templates/<CODE>/` on both stacks (Twig `*.html.twig` in PHP, Nunjucks `*.njk` in Node, same structure; pipeline in §3.8). PDF: DOMPDF (PHP) and Puppeteer/Chromium (Node, `pdfRenderService.js`, `PUPPETEER_EXECUTABLE_PATH`). DOCX: `Services/Docx/DocxDocumentBuilder.php` (PHPWord) and `services/docx/` (Node). The data comes from `DocumentDataAssembler` (both stacks). PHP and Node print the same page breaks and page counts (D-057).

### 8.1 Document types

Seeded in `document_types` (`docs/seed.sql`). "Category" decides who may see it: only `customer_facing` types can reach the buyer (`DocumentRepository::customerFacingForOrder`). DOCX is an **internal-only** Word copy next to the PDF; the buyer never gets the DOCX (`docx_generation_settings`).

| Code | Name | Reference | When generated | Data source | PDF | DOCX | Category · who may see it |
|---|---|---|---|---|---|---|---|
| QT | Quotation | `SC/QT/…` | Stage 1 | Order, lines, client, T&C | Yes | Yes | customer_facing · staff and buyer. Blocked while a checked line is below floor (D-245). |
| ANNEXA | Annexure A | `SC/ANX/…` | Stage 1, with the QT | Lines, images, `annexure_product_note` | Yes | Yes | customer_facing · staff and buyer |
| BUYERPO | Buyer PO — Order Acceptance | `SC/PO/…` | Stage 2 | Buyer PO, consignee | Yes | Yes | customer_facing · staff and buyer |
| PI | Proforma Invoice | `SC/PI/…` | Stage 3 | Lines, payment terms, bank, milestones | Yes | Yes | customer_facing · staff and buyer. Blocked while a line is below floor. Its first approval freezes the order (§5.5). |
| OC | Order Confirmation | `SC/OC/…` | Stage 4 | Order, advance actually received | Yes | Yes | customer_facing · staff and buyer |
| SUPPO | Supplier PO — Material Procurement | `SC/SPO/…` | Stage 5 | `order_supplier_po`, lines | Yes | Yes | procurement · never to the buyer |
| FDN | Debit Note (freight) | `SC/DN/…` (older `SC/FDN/…`) | Stage 6 (CFR/CIF) | `order_freight` | Yes | Yes | customer_facing · staff and buyer |
| PL | Packing List | `SC/PL/…` | Stage 7 | Packing, packages | Yes | Yes | customer_facing · staff and buyer |
| BLI | BL Instruction Sheet | `SC/BLI/…` | Stage 7 | BL preset, consignee | Yes | Yes | internal · never to the buyer |
| CI | Commercial Invoice | `SC/CI/…` | Stage 8 | Invoiced lines, balance terms | Yes | Yes | customer_facing · staff and buyer |
| ESI | Export Service Invoice | `SC/ESI/…` | When the order has service lines (D-215) | Service lines | Yes | Yes | customer_facing · staff and buyer |
| COOPREP | Certificate of Origin Preparation | none (D-069) | Stage 8–9 (also `generate_compliance_documents`) | Consignee, lines | Yes | Yes | procurement · never to the buyer |
| AMD | Payment Terms Amendment | `SC/AMD/…` | When payment terms are amended (`AmendmentService`) | Amendment | Yes | No | internal · the buyer signs a copy outside the portal; the signed upload activates it |
| BLE | Bill of Lading Endorsement | `SC/BLE/…` | After the BL endorsement form is saved; approved at once (no review) | `OrderBlEndorsement` | Yes | **No** (PDF only: `generateBlEndorsement` stores no Word copy) | customer_facing · staff and buyer |
| CAFIN | CA Financial Annexure (Internal Only) | `SC/CAFIN/…` | Any time (CAFIN box) and automatically at Mark Fully Settled | `CaFinancialSnapshotService` | Yes | No | internal · only `view_cafin` holders who can open the order. Never to the client. |
| CHECKLIST, CHECKLIST_2_FINANCE, CHECKLIST_3_PACKING, CHECKLIST_4_SHIPPING | Cross-Verification Checklists 1–4 | none | Not generated: fixed in-app Reference Library pages | `internal_reference_docs` (Markdown) | No | No | internal · any signed-in staff at `/reference-docs/{code}`; editing needs `manage_company_settings` |
| SOP_A_SALES, SOP_B_SALES, STAGEGATE, WALLREF | SOP Tier A/B, Stage Gate Reference, Wall Reference | none | Not generated: fixed in-app Reference Library pages | `internal_reference_docs`, placeholders from `company_settings` | No | No | internal · as the checklists |
| DQT, DOC, GTI, DCH | Domestic quotation, order confirmation, GST tax invoice, delivery challan | Division numbering | Domestic Trade order stages (D-257 Phase 2) | `DomesticDocumentService` + `GstCalculator` | Yes | No | staff of the DOM division; the buyer receives the PDF |
| IPO | Import purchase order | Division numbering | Import "ordered" stage (D-257 Phase 3) | `ImportDocumentService` | Yes | No | staff of IMP; downloaded from the import page |
| MTT invoice / PO | Merchant trade sale invoice and purchase order | Division numbering | Merchant deal (D-257 Phase 4) | `MerchantDocumentService` | Yes | No | staff of MTT; downloaded from the deal page |

Every Export format is `SC/<X>/{YY}/{DDMM}{NNN}` (2-digit year since 29 Oct 2026, D-177; daily counter). The Reference Library also holds custom entries (uploaded PDF/Word/Excel/text files, optionally filed under a category that requires a permission) managed by `ReferenceDocController`.

Generating needs `generate_documents`; approving needs `approve_documents`; downloading needs `download_pdf` (CAFIN: `view_cafin`, D-109). The server checks the stage (`stageSequenceFor` / `STAGE_DOCUMENTS`), not only the UI.

The client portal lists only customer-facing documents that are approved or sent (`client_portal_show_document_downloads`, default on). CAFIN, Supplier PO, BLI, COO Prep and the inspection report are blocked **even by ID** (D-068, D-106). E-mail compose refuses internal documents.

### 8.2 Revisions, approval and superseded copies

**Lifecycle:** draft → in review → approved → sent → (superseded).

- **Generate** creates a draft with the DRAFT watermark. The revision number is reserved atomically (`ReferenceNumberService::nextDocumentRevisionNumber`), so two people regenerating at once never share one.
- **Two revision numbers.** `revision_number` counts every generation (Rev.00, Rev.01 …, internal). `client_revision_number` counts how many of this type were actually **sent** to the buyer, so internal re-generation never shows the buyer a jump from Rev.00 to Rev.09.
- **Approval.** When every assigned reviewer approves (none rejects), the document is finalized automatically: the same revision is re-rendered with the final watermark. Only the latest revision can be approved. An inactive user cannot review; the same reviewer cannot be added twice (D-070).
- **Superseded.** Approving a revision supersedes every other revision of that type on that order. An approved or sent one is re-rendered with the red **"INVALID DOCUMENT — SUPERSEDED"** watermark and saved as `… (SUPERSEDED).pdf`; a draft or in-review one only changes status. Superseded copies are never deleted.
- **Sending** (e-mail on): two levels — Level 1 submit, Level 2 approval by someone with `approve_email_send` (never the requester), then the deferred dispatch job. With buyer document e-mails held (testing mode or the switch off in Settings › E-mail, D-280), approval **counts as sent** and staff send the PDF themselves. `mail_approval_queue_enabled = 0` removes Level 2.
- **Drift.** Regenerating an earlier-stage document flags later documents "may be out of step".

**`render_context_json` (QA batch 3, DOC-2; D-258).** `documents.render_context_json` stores the exact context a revision was rendered from. Approval re-renders and superseded copies are drawn from this stored context, with only the watermark changed — never from today's order data. So the superseded and final copies show what was really issued. Revisions made before 6 Nov 2026 have no stored context and use the older live-data path.

**Images in the stored context (D-267, owner double-confirmed 7 Nov 2026).** The stored context no longer carries its images (company seal, signatory seal, signature, logo, watermark) inline. Each image is kept once, exactly as it was embedded (the same data URI, byte for byte), in the insert-only table `document_image_blobs`, keyed by its SHA-256; the context holds only `{"__image_ref":"sha256:…", "store":"document_image_blobs", "mime":…, "length":…}` (`RenderContextImages::pack` / `renderContextImages.pack` when storing, `unpack` when loading). On a re-render each image is put back after its SHA-256 is checked; a missing or altered image stops the re-render with an error naming the document and the image ("Document #N cannot be re-rendered: its stored image \"assets.seal_data_uri\" …") — never a silent substitution. Database triggers refuse any UPDATE or DELETE on `document_image_blobs`, so stored images last as long as the documents (10-year record keeping); replacing a company asset or deleting an old asset file cannot change an old document. Contexts stored before this change still hold their images inline and load exactly as before. Effect: a stored context drops from about 3.8 MB to about 15–22 KB; a seal used on 1,000 documents is stored once. The generated PDFs are unchanged (0 differing pixels on every page in the before/after check).

**Order freeze (D-262).** When the first PI is approved, the order's clauses, tolerance, company details, MD-approval flag, pricing rate, category rows and Zoho items are frozen, and every later document run uses them. Details: §5.5.

### 8.3 Signatory and seal

`DocumentDataAssembler::signatoryBlock()` picks the signatory in this order:

1. a per-document override chosen at generation;
2. `document_type_signatories` for that type (seed: AMD → the MD with the designation seal; D-067);
3. `company_default_signatory`;
4. fallback: `company_settings.md_name` / `md_title` and the global signature asset.

A change to `md_name` / `md_title`, or to a signatory user's name in Admin › Users, can be
applied to open orders and Draft / In-review documents (D-349, §5.5).

The user must be `is_signatory_eligible`. The block prints the user's signature image, their designation seal or the company seal (`assets` type `seal`), as images, not black boxes (D-058). Re-renders reuse the signatory stored with the revision (`signatoryFromSnapshot`). The name printed is `users.name`, which is why real names are entered only at go-live (§9.4). An optional disclaimer under the signature block (`show_generated_document_disclaimer`, off by default, D-066).

### 8.4 Watermarks

| Watermark | Source | Default |
|---|---|---|
| Draft | `watermark_settings` (global, draft mode) | "DRAFT — NOT FOR RELEASE", mode `both` (text + image), #a8701f, opacity 0.15, 45° |
| Final | `watermark_settings` (global, final mode) | "NEXACREST INTERNATIONAL — ORIGINAL", mode `both`, grey, opacity 0.15, 45° |
| Revision stamp | Added to the text from the buyer's 2nd send onward | "— REVISION 02" (customer-facing only) |
| Invalid | Hard-coded in `invalidWatermark()` so it can never be weakened | "INVALID DOCUMENT — SUPERSEDED", red #C0152F, 54 pt, 35° |
| CAFIN | Own template, not `_layout.html.twig` | Red "INTERNAL ONLY" banner top and bottom |

Every page carries the reference and the watermark (D-065). A blank watermark setting means the default.

### 8.5 Category-driven columns, quality documents and T&C clause sets (D-257 Phase 0B / 1)

`CategoryDocumentProfile` reads the product categories of the order's goods lines.

| On the document | Natural Stone order | Other category |
|---|---|---|
| Product columns | Dimensions, Finish, HS Code | The category's own attributes and the line's HS code |
| Packages | "Wooden Crates", "Crate No.", "Net Weight (stone only)" | Category packing ("Wooden Boxes"), "Package No.", "Net Weight (goods only)" |
| Documents to be provided | The standard 5 | The 5, then each quality document (e.g. "6. Mill Test Certificate 3.1") |
| Supplier PO section 3 | "Material / Stone Type", ±2 mm / ±0.5 mm | "Material / Product Type", "As per the approved drawing / specification", Quality Documents Required |
| T&C | Natural stone clause set | The category's clause set |

**Certificate of Origin line (D-349, 12 Nov 2026, owner confirm 1 + confirm 2).** Item 3 of
"Documents to be provided" (QT), "Export documentation" (PI) and "Documents provided with this
shipment" (CI), PDF and Word, prints `order.coo_line` from `DocumentDataAssembler::cooLine()` /
`documentDataAssembler.cooLine()`: when `coo_type` is empty, NULL, "TBC" or "To Be Confirmed"
(trimmed, any case) it reads "3. Certificate of Origin Issued by CAPEXIL"; otherwise "3.
Certificate of Origin — <type> — Issued by CAPEXIL" as before. The Word builder reads the same
value (falling back to the same helper), so the PDF and Word wording never differ. Other
documents that show the COO type (Buyer PO, OC, COOPREP) are unchanged.

**No mandatory-fields note, no red stars (D-352, 12 Nov 2026, owner confirm 1 + confirm 2).**
Generated documents, PDF and Word, both stacks, no longer print the line "Fields marked * are
mandatory and must be completed before this document is issued." (it was in the shared PDF
layout `_layout.html.twig` / `_layout.njk` and in the Word builders) nor the red "*" that
followed the payment-terms lines (QT, PI), the production / estimated-shipment line (PI, OC), the
money rows and the amount in words (CI) and the payment-due line (FDN). Amounts, wording and
layout are otherwise unchanged. The `.mandatory-note` style stays (the CI "Total Invoice Value in
Words" line uses it); `.mand-star`, `addMandatoryNote()` / `mandatoryNote()` and the Word
`starRun()` helper are gone. The plain "*" that used to follow field labels was removed
afterwards by D-353 (below).

**No stars after field labels (D-353, 12 Nov 2026, owner confirm 1 + confirm 2).** Generated
documents, PDF and Word, both stacks, no longer print the plain "*" after a field label or a
column heading (e.g. "QUOTATION NO.", "DATE", "VALID UNTIL", "Company Legal Name", "Port of
Discharge", "Product Description", "Qty"). This covers every document template (QT, ANNEXA, PI,
OC, BUYERPO, SUPPO, FDN, PL, BLI, CI, ESI, COOPREP, AMD payment terms and products & prices),
the shared partials (buyer, consignee, line table) and the Word builders
(`DocxDocumentBuilder.php` / `docxDocumentBuilder.js`). The star was plain text, so no style
was removed. No generated document now prints a "*" as a label marker; wording, amounts and
layout are otherwise unchanged, and page counts stay the same (a row or a section heading can
move to the previous page because a label no longer wraps). The input forms in the app keep
their "*" for required fields.

**"Documents to be provided" without "(digitally authorised)" (D-344, 12 Nov 2026, owner confirm 1 +
confirm 2).** On the QT, PI and CI, PDF and Word, both stacks, the list reads "Commercial Invoice" and
"Packing List" (CI: "Commercial Invoice (this document)"). Before, the PDF said "(digitally authorised)"
and the Word copy "(signed and stamped)" — they disagreed, and "digitally authorised" could raise a
discrepancy under an LC that asks for a signed invoice. The printed signature and seal, the other
listed documents and the COO preparation checklist ("Commercial Invoice (signed copy)") are unchanged;
stone fixtures updated for exactly these words.

**Every Supplier PO in Indian grouping (D-345, 12 Nov 2026, owner).** The supplier is paid in rupees,
so every rupee amount on the SUPPO (rate per unit, basic value, GST, total payable, advance, balance;
PDF and Word, both stacks) uses `MoneyFormat::amount()` / `moneyFormat.amount()` with Indian grouping
whatever the order's currency (D-343 had grouped it by the order currency). Amounts in words,
quantities and every other document unchanged; issued Supplier POs keep what they printed.

**Headings keep with what follows (D-354, 12 Nov 2026, owner confirm 1 + confirm 2).** In every
generated document, PDF and Word, both stacks, a section heading or a table's column-header row
is never left alone at the foot of a page: it moves to the next page together with its first
rows. PDF (Dompdf on PHP, Chromium on Node, the same CSS in `_layout.html.twig` / `_layout.njk`
and in the standalone MTT, IMP, DOM, CAFIN and BLE templates): headings (`.section-title`,
`.section-title-highlight`, `.weight-caption`, `.sub-title`, `.annexure-product-title`, the
Legal Terms / Definitions box header and intro line, Additional Terms h3/h4) carry
`page-break-after: avoid; break-after: avoid`; every column-header row is marked
`tr.col-head` (or sits in a `thead`) and carries the same rule, so it stays with the first body
row; `thead` repeats; table rows are not split. Both engines honour these. Word
(`DocxComponents.php` / `docxComponents.js` and the builders): section headings, the
signature reference line, the Weight & Volume caption, the Legal Terms / Definitions box header
and intro, and every column-header row carry "keep with next" (`keepNext`); header rows are also
"repeat as header row" and "do not split" (`tblHeader`, `cantSplit`). Wording, amounts, fonts
and sizes are unchanged; only where a page breaks can change (for example the Freight Debit
Note now starts page 2 with "4. FREIGHT & CHARGES"; one Supplier PO grew from 2 to 3 pages).
Headings inside the WYSIWYG Additional Terms text are not marked keep-with-next in Word
(PhpWord's HTML import offers no hook; Node matches).

**Payment Terms highlight (D-355, 12 Nov 2026, owner confirm 1 + confirm 2).** On the Quotation
(QT) and the Proforma Invoice (PI), PDF and Word, both stacks, the lines under the dark
"PAYMENT TERMS" heading (advance, any milestones, balance, their amount sub-lines, the LC /
no-advance wording, the CFR/CIF freight line, "Currency:" and, on the PI, the cleared-funds
line) sit in one box with a light grey-blue background (#EEF2F8) and a 4px (3pt) navy left edge
(#1F3A68), padded about 6px x 12px. Only the money figure is bold (the currency code and the
amount, e.g. "30% Advance Amount:  **USD 7,450.17**"); the labels, wording, amounts and the order
of the lines are unchanged. The heading itself is unchanged. PDF: `.payment-terms-box` and
`.pt-amount` in `_layout.html.twig` / `_layout.njk` (`page-break-inside: avoid`, so the short box
is never split; the heading keeps with it through D-354). Word: a one-cell table with that fill
and left border and no other border (`DocxComponents::addPaymentTermsBox()` /
`docxComponents.paymentTermsBox()`), its row "do not split" and every line but the last "keep
with next"; the builders' `paymentTermsLines()` return the lines, with the amount as a separate
bold run. The Order Confirmation has no Payment Terms section (its "PAYMENT STATUS" table is
unchanged). Only where a page breaks can change (the PI's Payment Terms now start on the next
page when the box would not fit below the heading); page counts are unchanged. Documents already
issued are not touched.

**Word files: clean spacer under section headings (D-356, 12 Nov 2026, owner confirm 1 + confirm 2).** The small spacer under each section heading in the PHP Word files is written as an empty, keep-with-next paragraph (`DocxComponents::addSectionTitle()`), not as a text break with a paragraph style. PHPWord wrote that text break with a second, empty paragraph-property block (about 9-11 per document since D-354), which Microsoft Word can report as a damaged file and offer to repair. Wording, layout, page counts and keep-with-next are unchanged; PDFs and the Node Word files were never affected.

**Clause sets.** Most clauses print on every order. Some belong to a set (natural stone, general goods, engineering goods) and print only when an order category uses it; an order with two categories prints both sets. The general and engineering wording is a starting text for the lawyer to review before the first non-stone sale.

**Quality documents card.** Per category, up to 10 documents. On the order page: Pending → Received → Approved, file required for Received/Approved. Internal; never shown to the client.

### 8.6 The Natural Stone identity tests

The owner rule is that every current PDF is correct. So D-257 Phase 1 had to prove that a stone order prints **exactly** what it printed before.

- `PHP_MySQL_Version/app/tests/Integration/StoneDocumentsUnchangedTest.php` and `NODE_MySQL_Version/app/tests/integration/stoneDocumentsUnchanged.test.js` render QT, PI, OC, CI, PL, SUPPO, BLI and COOPREP (PDF and Word text, FOB and CIF) and compare them with fixtures in `tests/fixtures/stone_documents`, captured **before** Phase 1 on a fresh schema + seed database.
- Per-run parts are masked (references, dates, e-mails, Rev.N, Page N).
- `CAPTURE_STONE_DOCS=1` rewrites the fixtures — **only ever from pre-change code**.
- `CategoryDrivenDocumentsTest` / `categoryDrivenDocuments.test.js` test the non-stone output; `NaturalStoneClauseWordingTest` guards the stone clause wording.

If one of these tests fails after your change, you changed a document. Stop and follow §8.7.

### 8.7 Double confirmation for any document change

Rule R6 (CLAUDE.md): before changing anything that ends up in a generated document — PDF or DOCX templates, `DocumentDataAssembler`, document builders, CAFIN content — show the owner **what the document shows now and what it will show after**, and get confirmation **twice**. Data may change; the format of client-facing documents must stay intact.

Why: on 27 Oct 2026 a Supplier PO change was built and had to be reverted the same day ("revert immediately … all PDFs are correct", D-063). That mistake is also why pushes became ask-first (R3). Many decisions in register §3.3 record "confirmed twice" with before/after samples.

### 8.8 Annexure A, Annexure B and lines "As per annexure" (D-336)

Owner decision D-336 (12 Nov 2026, owner confirmed twice). Code: `AnnexureChoice` (PHP `app/src/Services/AnnexureChoice.php`, Node `src/services/annexureChoice.js`), `AnnexureController` / `annexureController.js`, `DocumentDataAssembler` / `documentDataAssembler.js`.

**Two annexures, one screen.** `/orders/{id}/annexure` is **Annexure A — as quoted** (unchanged). One button next to the title, **Annexure B (as shipped) ›**, opens `/orders/{id}/annexure?which=B`: the same screen and features (include tick box "Include Annexure B on this order's documents", content Product Specification / Additional Terms / Both, product entries with name, dimensions, finish, images, description, components, technical notes, Additional Terms rich text), with **‹ Annexure A (as quoted)** and **Copy everything from Annexure A** (products, the same image files, terms and content mode; asks "Replace Annexure B with a copy of Annexure A?" when B already has content). Audit entries are the same as Annexure A's, with reason "Annexure B"; the copy logs `ANNEXURE_COPIED_FROM_A`.

| Data | Meaning |
|---|---|
| `order_annexure_products.which`, `order_annexure_terms.which` | `A` (every row before D-336) or `B`. One Additional Terms block per annexure: `UNIQUE(order_id, which)`. |
| `orders.include_annexure_b`, `orders.annexure_b_mode` | Annexure B's include tick box and content mode (like `include_annexure_a`, `annexure_mode`). |
| `orders.ci_annexure` | `A` / `B` / `NONE`, chosen in Step 11a; NULL = the default. |
| `order_products.as_per_annexure` | 1 = the line's size is given in the annexure. |

**Which document prints which annexure.** Quotation (and the standalone ANNEXA), Buyer PO, PI and OC: Annexure A, exactly as before. Commercial Invoice and Packing List: the annexure chosen in **Step 11a · Generate Commercial Invoice** — radio "Annexure printed with the Commercial Invoice and Packing List *": Annexure B — as shipped (n products · saved date · open) / Annexure A — as quoted / No annexure. Default (nothing saved): Annexure B when it is included and has content, else Annexure A when it is included and has content, else none. An option without content is disabled; "No annexure" is disabled while a line says "As per annexure". The choice is saved with the order on Generate (audit `CI_ANNEXURE_CHOSEN`); the Packing List uses the same one. The appendix heading and the red notice read "ANNEXURE B" / "Annexure B" when B is printed; Annexure B's closing note names the CI / PL it is attached to. Annexure B is not a separate document type.

**Order lines "As per annexure".** Order page › Products › Edit / + Add product › step 3 Size and quantity: tick box **As per annexure** next to **Qty To Be Confirmed** — only one of the two (browser `app.js` `initAnnexureLine`, and the server refuses both). While ticked, Length / Width / Thickness show "As per annexure" and accept nothing (the server clears the piece size and the Dimensions text); the Qty stays required. The products table shows "As per annexure" in the Size column. Duplicating a line keeps the tick. On the documents a ticked line prints the quantity as today followed by "(as per Annexure X)" — e.g. "8.801 (as per Annexure A)" — and "Dimensions: As per Annexure X" in its detail row (PDF and Word); X = A on QT, Buyer PO, PI, OC and the chosen annexure on CI / PL (the Packing List has no line quantities, so only its appendix changes). Lines without the tick print exactly as before (StoneDocumentsUnchanged fixtures unchanged).

**Safety check (generation refused with a message).** QT / Buyer PO / PI / OC while a line is ticked and Annexure A is not included or has no product entry: *Line N says "As per annexure" but Annexure A has no product yet — add it in Annexure A (order page › Annexure A), then generate again.* CI / PL with the chosen annexure empty: *Line N says "As per annexure" but Annexure B (as shipped) has no product yet — add it, or choose Annexure A, then generate again.* (A: "… Annexure A (as quoted) … or choose Annexure B …"); with "No annexure": *… "No annexure" is chosen for the Commercial Invoice and Packing List — choose Annexure B (as shipped) or Annexure A (as quoted), then generate again.*

Migration `2026-11-12c-annexure-b-as-per-annexure.sql`. Tests: `AnnexureBAndAsPerAnnexureTest` / `annexureBAsPerAnnexure.test.js`.

**Follow-ups (D-338, 12 Nov 2026).** Reorder request lines can say "As per annexure" too, and a duplicated (or reordered) order keeps a copy of Annexure A (not B) — see §5 (Order edit and duplication). A line "As per annexure" whose price needs the piece size gets one refusal wording everywhere — see §7 (Line statuses). Migration `2026-11-12e-follow-ups-d338.sql` (`order_reorder_request_products.as_per_annexure`). Tests: `FollowUpsD338Test` / `followUpsD338.test.js`.

**One save for the page, Annexure B from the Commercial Invoice step (D-341, 12 Nov 2026; owner "will go with recommendation" and "one save for the whole page").** No migration.

- **Order page, Step 1.** The small grey link became a button **Edit Annexure A ›** (`manage_orders`). The Annexure A generate button reads **Regenerate Annexure A (new revision)** once an Annexure A document exists, else **Generate Annexure A** (like the Quotation's button).
- **One "Save all changes".** The annexure page (A and B) is one form (`id="annexure-save-all"`, multipart, `data-unsaved-guard`) with **Save all changes** at the top and bottom. It saves the content mode, every product entry's fields (`entries[<id>][name|dimensions|finish|description|components|technical_notes]`; name required), the Additional Terms (the rich-text surface is copied into the hidden `content_html` on submit) and a newly chosen image per entry (`images[<id>]`; jpg / jpeg / png / webp, the `product_image` upload limit, 5 MB). Route `POST /orders/{id}/annexure/save-all` (`AnnexureController::saveAll` / `annexureController.saveAll`; `manage_orders`, order lock, CSRF). Everything is checked first; a missing name or a wrong / too large image re-shows the page (HTTP 422) with the message beside the field, the typed values kept and nothing saved ("Nothing was saved — correct the fields marked below, then press "Save all changes" again."). Then all changes are saved together (PHP: one transaction including the image files; Node: the image files are stored first, then one transaction). Audit as before, one row per changed entry (`ANNEXURE_PRODUCT_UPDATED`), new image (`ANNEXURE_IMAGE_UPLOADED`), the terms (`ANNEXURE_TERMS_UPDATED`) and the mode (`ANNEXURE_MODE_UPDATED`), reason "Annexure B" for B. Success: "All changes to Annexure A saved." (or B); nothing changed: "Nothing had changed — nothing to save.". The per-entry Save, Upload Image and Save Additional Terms buttons are gone (their routes still work). **Remove Entry** and an image's **×** still act at once with their confirm: they are separate small forms outside the big form, reached with `form="…"` (no nested forms). **Add Product Entry** stays its own form at the bottom. The include tick box still saves at once. The approved / sent warning confirm stays on Save all changes.
- **Unsaved changes.** `app.js` (both stacks, sitewide): in a form marked `data-unsaved-guard`, once a field was changed, leaving the page (a link, another form on the page, closing the tab) asks *"You have unsaved changes on this page — leave without saving?"*. Saving the form itself never asks. `app.js` `fieldError()` also checks a chosen file against `accept=".jpg,…"` (extension lists only) and `data-max-bytes`.
- **"Generate the new revision" banner (Annexure A).** When the latest ANNEXA document is approved or sent and Annexure A was changed after it was generated (`AnnexureChoice::staleApprovedAnnexA()` / `staleApprovedAnnexA()`: the latest audit row of an Annexure A entry, image, its terms or mode — `lastChangeA()` — is later than `documents.generated_at`; a removed entry / image is found through its audit row, which now records field `order_id` = the order), the page shows *"You changed Annexure A after Rev.N was approved — generate the new revision, then approve and send it."* with **Generate the new revision ›** (posts the usual `/orders/{id}/documents/generate` with ANNEXA, only with `generate_documents`; the same regeneration confirm as the order page when later documents are at risk; returns to the order page). Otherwise the old warning shows.
- **Annexure B only from the Commercial Invoice step.** The **Annexure B (as shipped) ›** button shows once Stage 8 (Step 11, Commercial Invoice & Balance) is open or passed — the same condition that opens Step 11a on the order page (`AnnexureChoice::ciStageReached()`) — or whenever B already has content (`bMade()`). Before that the page shows the muted note *"Annexure B can be made at the Commercial Invoice step."*; `?which=B` shows the same note and **‹ Back to Annexure A**, and every Annexure B change (copy, save, add, mode, terms, include) is refused with that note.
- **Starting Annexure B.** While B is empty, `?which=B` shows one primary button **Start Annexure B from a copy of Annexure A** (the existing copy, no confirm) and a small link **Start empty instead** (`?which=B&start=empty`, the normal empty page). Once B has content the page is as before (Copy everything from Annexure A asks first).
- **Step 11a.** While Annexure B was never made, under the annexure radios: *"Annexure B not made — Annexure A will be printed."* and **Make Annexure B (copy of A) ›** (shown only when the order has an Annexure A from the Quotation; an order that never had an annexure shows no annexure note — owner, 12 Nov 2026) (`manage_orders`; posts the copy, then opens the Annexure B page; its form sits outside the CI form).

Tests: `AnnexureOneSaveD341Test` / `annexureOneSaveD341.test.js`.

### 8.9 The detailed product table, No. of pieces and prices in other units (D-339)

Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a"; mockups approved). Code: `LineTable` (PHP `app/src/Services/LineTable.php`, Node `src/services/lineTable.js`) builds the table from the order lines (pure functions); `DocumentDataAssembler::lineTableBlock()` / `documentDataAssembler.lineTableBlock()` adds it to the document context as `line_table`; templates `_line_table.html.twig` / `_line_table.njk`; Word: `DocxComponents::addLineTable()` / `docxComponents.lineTable()`; `UnitPrices` / `unitPrices.js` for the product card.

| Data | Meaning |
|---|---|
| `orders.line_table_layout` | `classic` = the six-column product table as before; `detailed` = the table below. Default `detailed` (new orders, duplicates and reorders). Migration `2026-11-12g` set every order that existed then to `classic`, so their documents never change. No screen switches it. |
| `order_products.pieces_count` | No. of pieces (whole number). With the size in CBM / SQM / SQFT it works out the line quantity; PCS: quantity = pieces. |
| `order_products.size_note` | Up to 200 characters, e.g. "Random sizes, as available" — printed as "Sizes: …" in the line's grey row. |

**Which documents.** Quotation, PI, Order Confirmation, Commercial Invoice, Buyer PO (with prices) and Packing List (no prices) of a `detailed` order, PDF and Word. Only the product section changes: on QT / PI / CI it replaces the six-column table; on the OC and Buyer PO it stands above the order summary and replaces its Product(s) / Quantity / Unit / Unit Price rows; on the PL it stands in the Product Summary and replaces the Product Description row. Everything else (header, buyer / consignee, totals box, terms, legal terms, signatures, annexure, CAFIN) is unchanged. Issued documents keep their stored context.

**Columns.** # · Material / Colour (the line's description; when the line's product has a colour / variety option it is added) · Surface / Finish (the line's finish, else its surface-finish option) · Dimensions (cm) L W H · <Unit> per piece · Qty No. of pcs · <Unit> all pcs · Price (<CCY>) per <Unit> · Price (<CCY>) per piece · Total Price (<CCY>), then TOTAL (pieces, measure, amount). PL: the last column is "Crates / Packages" = the crates whose product description is the line's description, else "—". Grey row under each line (with prices only): "Qty as per Annexure X" · "Sizes:" size note · "Dimensions:" text of a line without a piece size · another category's attributes · HS Code · Country of Origin. A4 portrait, 8 pt, fixed layout, both header rows repeated on every page (PDF thead, Word `tblHeader`); a table of up to 12 lines is kept on one page.

**Variants (chosen automatically).**

| Situation | Table |
|---|---|
| Every goods line in one unit | Headings name the unit (CBM, SQM, SQFT, PCS); cells show numbers only. |
| Mixed units | "Measure per piece", "Measure all pcs", "Price (CCY) per unit"; each cell names its unit ("0.1872 CBM", "1,950.00 /CBM"); TOTAL shows pieces and amount only; extra row "Totals by unit: 4.680 CBM · 180.00 SQM · 20 PCS" (never added across units). |
| No goods line has Length × Width | Short table: # · Material / Colour · Surface / Finish · Thickness (cm) · Qty <Unit> · Price per <Unit> · Total (PL: … · Qty · Crates / Packages). |
| Line "As per annexure" (D-336) | "As per Annexure X" across L W H (X = A; on CI / PL the chosen annexure), "—" for per piece, pieces and price per piece; all pcs = the line quantity; grey row starts "Qty as per Annexure X". |
| Line without a size in a full table | "—" for L W H, per piece, pieces, price per piece; all pcs = the quantity. |
| PCS line | No measure ("—"); price per unit = price per piece. |
| Service, charge, goods in Ton / RMT / Set / Lot … | Today's rows in the simple table below (on the PL without prices). |

**Measures and rounding** (the same in `LineTable`, `lineTable.js` and `app.js`): CBM per piece = L × W × H / 1,000,000 (4 decimals); SQM = L × W / 10,000 (4 decimals kept, 2 shown); SQFT = SQM × 10.7639 (2 decimals); all pieces = per piece × pieces (CBM 3 decimals, SQM / SQFT 2); price per piece = unit price × measure per piece (2 decimals), half away from zero. **The line total is the line's own amount** (`fob_value` = quantity × unit price, as before) — never worked out again from the rounded figures shown. Amounts as the documents format them (order currency, 1,234,567.00; an INR order in Indian grouping, 12,34,567.00 — D-343, §8.11).

**Order line form (detailed orders only).** Order page › Products › Edit / + Add product › step 3, and the New Order form rows: **No. of pieces** and **Size note** ("e.g. Random sizes, as available"). With Length and Width (and Thickness for CBM) and a unit of CBM / SQM / SQFT the pieces are required (`app.js` `data-pieces-count` in `fieldError()`) and the Qty becomes read-only, worked out (`initLinePieces`; hint "worked out: 25 × 0.1872 CBM"); PCS: Qty = pieces; without a size the Qty is typed. The server works the quantity out again from the pieces and the size and never uses the browser's figure (`piecesInput()`; refusal "Product line: enter the No. of pieces — with the size given, the quantity is worked out from the pieces."). "As per annexure" lines take no pieces. Summary under the fields: "This line: 0.1872 CBM per piece · 25 pcs = 4.680 CBM · USD 365.04 per piece · total USD 9,126.00". The products table shows "25 pcs" and the size note under the Size. Duplicating a line copies pieces and size note. On a `classic` order the form is as before.

**Price filled in, converted, never over a typed price.** Pick from Products, or a change of unit, size, thickness or option, fills the quote converted into the line's unit (`/pricing/line-quote`, D-270) only while the price was filled automatically (`data-auto="1"`) or is empty; once the staff member types a price (`data-auto="0"`) it is never replaced. On a detailed order the floor shows next to the price in the line's unit: "Your floor: USD 37.20 per SQM — this price is 4.8% above" (`data-floor-show`; below the floor the D-245 P2 message as before). Approval rules unchanged (D-245 P2, D-270, D-338).

**Product page › "Prices in other units"** (read-only, nothing stored; `UnitPrices::card()`): one row per thickness option (or "Base price"), per CBM / per SQM / per SQFT, worked out with `LineUnits::factor()`. Sets shown by permission: supplier price (INR) — may see purchase prices; staff quote and staff floor — sees selling prices; every associate level (quote and lowest) — `view_price_buildup` (MD / ED). A thickness whose supplier price is TYPED (D-335) shows "typed price wins (D-335)" and no per-CBM figure. **Associates:** their price request page (the result of Get price) shows their own quote and lowest per CBM / SQM / SQFT at the requested thickness — their level only, nothing newly priced (`UnitPrices::convert()`).

Migration `2026-11-12g-detailed-line-table.sql`. Tests: `LineTableTest` / `lineTable.test.js`, `D339DetailedLineTableTest` / `d339DetailedLineTable.test.js`. Test orders made by the test helpers are `classic`, so the Natural Stone fixtures (§8.6) are unchanged.

### 8.10 Prices and products fixed by the approved Quotation; the Products & prices amendment (D-342)

Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved). Owner rules: "once the QT is approved, the same exchange rate and the same prices for all other documents — not a single change, not even because of the exchange rate"; "no change until we go by amendments"; a price increase after the QT is never accepted by the client; the order does not go back to QT. Code: `PriceLock` (PHP `app/src/Services/PriceLock.php`, Node `src/services/priceLock.js`), `ProductsAmendmentService` / `productsAmendmentService.js`, template `AMD/products_amendment.html.twig` / `.njk`, views `amendments/products_new.php` / `.njk`.

| Data | Meaning |
|---|---|
| `orders.prices_fixed_at`, `prices_fixed_by_document_id` | When the first Quotation was approved, and which one. Set once (`DocumentGenerationService::finalizeApproval()` for a QT → `PriceLock::fixAtQtApproval()`). |
| `orders.pricing_fx_rate_at_qt` | The pricing rate in use at that moment (`FxRateService::rate()`; a PI approved earlier keeps its own rate). The PI's freeze (`OrderSnapshotService::freeze`) stores the same rate in `pricing_fx_rate_at_pi`. |
| `order_products.qt_fixed_unit_price` | The price the buyer accepted — the approved Quotation's, or the activated amendment's. Each line's `price_snapshot` (D-245) is fixed at the same moment, worked out at the fixed rate; a line's live figures on a fixed order also use the fixed rate (`OrderLinePricing::context()['fixed_fx']`). |
| `amendments.amendment_type` | `payment_terms` (Section 8, every amendment before D-342) or `products`. With `products_totals_json` (old / new totals, advance, balance, excess), `activated_at`, `supplier_po_revision_needed`. |
| `amendment_product_lines` | One row per line of a products amendment: `change_kind` (`none` / `change` / `add` / `remove`), old and new size, pieces, quantity, unit price, line total, `price_lowered`. |
| `orders.buyer_po_amended_by_amendment_id`, `_at`, `_by` | The checklist tick "The signed amendment amends the Buyer PO" (logged `BUYER_PO_AMENDED_BY_AMENDMENT`). |

**Lines locked.** After the approval the order page's line routes (add, edit, duplicate, delete) and a currency change on Edit order details are refused for everyone — no "Override locked data", no "Edit an order after confirmation", not the Super Admin — with "Prices and products are fixed by the approved Quotation of 12 Nov 2026 — change them through an Amendment (Order page › Amendments › Products & prices)." The products table shows that note with a link and no Edit / Duplicate / Delete / + Add product. Packing-list actuals, crates and shipped figures are recorded as before. MD price approvals of a line (D-245) still work (they do not change the price).

**The amendment screen** (mockup screen 1). One row per order line: L × W × H, No. of pieces, Qty (worked out from the pieces when the size gives the unit, D-339; else typed), unit price, Remove; up to three new lines (same product as a chosen order line, own size, pieces, qty and price). "Check the changes" shows old → new (struck through / green), the change per line ("Quantity changed", "Size changed", "Price lowered (MD)", "Line added", "Line removed", "no change"), the order total old → new with the difference and the advance ("advance already received USD 6,864.78; the extra USD 1,725.36 is added to the balance." / "… stays credited; the USD X received above the new advance is taken off the balance." / "… is USD X more than the new total — it shows as excess." / "no advance received yet — the advance due becomes USD X."). Refused: a price above the fixed price ("Line 1: USD 1,950.00 is the price the buyer accepted in the Quotation — a price can only go down, with the MD's approval." — browser via `data-max-price` in app.js and server), a price below the product's minimum, nothing changed, every line removed, a reason under 10 or over 500 characters, a second open products amendment. "Send to MD for approval" raises it (status pending, MD notified). The rate is shown only to price build-up viewers.

**Then the existing amendment flow**: MD approval (`approve_documents`, maker-checker rule `amendment`), the AMD document, the buyer's signed copy upload. **Activation** (`ProductsAmendmentService::activate`): refused if a line changed since the draft; changed lines updated (quantity, pieces, size, Dimensions text from the new size, price), added lines copied from their source line at the amendment's line number, removed lines soft-deleted; each keeps its new `qt_fixed_unit_price`; an added line gets its price check figures at the fixed rate; a lowered or new price below the floor is recorded as the MD's line approval ("Agreed with the buyer in Amendment … approved by the MD"); audit `ORDER_LINE_AMENDED` per line. Money: the advance received stays; the balance is the new total less the advance received (`OrderPaymentStatusRepository::balanceDue`); a balance already set and not cleared is set again (`BALANCE_AMOUNT_CHANGED`); commission, incentive and profit are worked out from the lines as always. The stage and the fixed rate do not change.

**Re-issue checklist** (mockup screen 2, order page, `manage_orders`, until nothing is left to do): Quotation — "nothing — the signed amendment and the Quotation together are the agreement"; Proforma Invoice / Order Confirmation issued before the activation — "Outdated by AMD-…" with "Generate revised … (Rev.NN)"; Buyer PO (when one is recorded) — ask the buyer for a revised PO or tick "The signed amendment amends the Buyer PO"; Supplier PO issued and quantities changed — Procurement task `revise_supplier_po` in My tasks (until a Supplier PO is generated after the activation); CI / PL issued — revised CI / PL, "the difference needs a credit / debit note in Zoho Books" (no new Zoho logic); documents not made yet — "will use the amended figures". A row disappears once its document is generated after the activation (or the tick is set). Under the table: "Money worked out again: total … · advance received … · balance now … (was …)." and, for price build-up viewers, "Exchange rate unchanged: ₹… (fixed at QT approval)."

**Document guard** (`PriceLock::documentGuard`, in `DocumentGenerationService::generate()`, no override): the PI, OC, Buyer PO and CI are refused while a goods line is above its fixed price ("Line 2: USD 2,000.00 is higher than USD 1,950.00, the price the buyer accepted in the approved Quotation — the Proforma Invoice cannot be generated. Prices change only through an Amendment (…).") or has no fixed price ("Line N is not in the approved Quotation — …").

**AMD document (PDF only — the AMD has no Word version).** A products amendment prints: meta (Amendment No., date, buyer inquiry ref), "To Order … · Quotation … dated … · Proforma Invoice … dated …", **1. PRODUCTS & PRICES — AS AMENDED** (# · Product · Size (cm) · Pcs · <unit> · Price (CCY) per <unit> · Total (CCY) · Change; old values struck through; a removed line struck through; "Order total (was CCY …)"), **2. EFFECT ON PAYMENT** ("Advance received USD 6,864.78 stays credited. Balance payable as per the agreed payment terms: USD 14,610.57. All other terms of the Quotation / Proforma Invoice remain unchanged."; before an advance: the advance and balance payable; above the new total: the excess held to the Importer's credit), **3. ACCEPTANCE** (Importer company, signatory name, date, signature, stamp) and our signature and seal block as on the other documents. Mixed units: "Qty" and "Price (CCY)" with the unit in each cell. The payment-terms AMD (`payment_terms_amendment`) prints exactly as before.

**Order page rate line (D-340)** on a fixed order: "Prices worked out at ₹X per USD — fixed by the approved Quotation of <date>; today's rate ₹Y does not change them." The price check shows "(fixed)" for figures fixed at the Quotation or the PI.

**Existing orders** (migration `2026-11-12h-price-lock-and-products-amendment.sql`): an order with an approved Quotation is fixed when the migration runs — time = the first approved Quotation's last reviewer approval (else its generation time), rate = `pricing_fx_rate_at_pi` when past the PI, else the pricing rate in use on the day the Quotation was approved (the newest daily rate on or before that day, minus the cushion — owner, 12 Nov 2026), and only when no such rate exists (or the mode is Manual) the pricing rate in use when the migration runs; a price lowered through a Products & prices amendment counts as MD-approved only when it ends below the floor (then the usual commission / incentive effect applies); each active line's fixed price = its price then. Tests: `PriceLockProductsAmendmentD342Test` / `priceLockProductsAmendmentD342.test.js`.

### 8.11 Indian grouping on INR documents, Total CBM from the lines, Word section numbers (D-343)

Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"). Three changes to the generated documents, PDF and Word, both stacks.

**1. Indian digit grouping for an INR order's money.** Every money amount on the documents of an order whose currency is **INR** prints with Indian grouping (lakh / crore: the last three digits, then groups of two): 1,75,000.00 · 6,16,070.00 · 15,40,175.00 · 12,34,56,789.50. Two decimals and the minus sign as before. Every other currency keeps the international grouping (1,540,175.00). One helper: `MoneyFormat::amount($value, $currency)` (PHP `app/src/Helpers/MoneyFormat.php`) / `moneyFormat.amount(value, currency)` (Node `src/helpers/moneyFormat.js`). `DocumentDataAssembler::formatMoney($value, $currency)` / `documentDataAssembler.formatMoney(value, currency)` take the order's currency inside `assemble()` (product lines, totals, advance / balance / milestones, payment status, freight block, Supplier PO block) and `LineTable::build()` / `lineTable.build()` use it for price per unit, price per piece and the TOTAL amount. The indicative freight / insurance figures on the QT / PI print in Indian grouping on an INR order; other currencies print them exactly as stored (as before). Not changed: amounts in words (international words, D-224), quantities, measures (CBM, SQM, pcs), weights, rates and percentages; the CAFIN (`CaFinancialSnapshotService`, its own formatter) and the payment-terms amendment (AMD); screens and e-mails (a call without a currency formats as before). The Supplier PO's rupee amounts follow the order's currency (Indian grouping on an INR order only).

**2. "Total CBM (m³)" from the detailed table.** On the Quotation and PI of an order with the detailed product table (`orders.line_table_layout = 'detailed'`, §8.9) the Weight & Volume box prints the total CBM worked out from the goods lines, 3 decimals (the owner's example: 180 × 80 × 13 × 25 + 140 × 70 × 13 × 25 + 120 × 60 × 13 × 10 = **8.801**). `LineTable::totalCbm()` / `lineTable.totalCbm()`: a line with a piece size (L, W, H) and pieces = pieces × CBM per piece (4 decimals, as the table); a CBM line without a size = its quantity; a SQM / SQFT line with a thickness = area × thickness; services and charges are not counted. When any goods line cannot be turned into CBM (SQM / SQFT without a thickness, PCS without a size, another unit, a quantity to be confirmed) the box prints "To Be Confirmed" — never an estimate typed on the order, which may disagree with the lines (owner, 12 Nov 2026: an old 8.2 was printed next to a table totalling 8.801). Classic orders print as before (the typed estimate, else "To Be Confirmed"); the figures are passed to the templates as `weight_total_cbm`. Context key `order.line_table_total_cbm` (QT / PI / OC of a detailed order; the OC has no Weight & Volume box). Classic orders are unchanged. Other rows of the box are unchanged.

**3. The Word copy numbers its sections as the PDF.** The Word builders (`DocxDocumentBuilder` / `docxDocumentBuilder.js`) take every section number from the PDF's `section_numbers` (`DocumentGenerationService::sectionNumbers()`), print the Buyer Details, Consignee Details and Notify Party sections exactly as the PDF partials (`_buyer_details`, `_consignee_details`, `_notify_party`; shown when the PDF shows them), print the PI's production / estimated shipment box without a numbered heading (as the PDF), and add the **LEGAL TERMS & DEFINITIONS** section (`DocxComponents::addLegalTermsSection()` / `C.legalTermsSection()`) where the PDF has it. Before, the Word copy merged buyer and consignee into one "2. BUYER / CONSIGNEE DETAILS" section, so every later number was one or two lower than the PDF (QT "4. SHIPPING / COMMERCIAL TERMS" against the PDF's "5."). Checked for QT, ANNEXA, PI, OC, BUYERPO, SUPPO, FDN, PL, BLI, CI, ESI and COOPREP, classic and detailed, INR and USD: the heading lists are identical on both stacks.

Tests: `MoneyFormatTest` / `moneyFormat.test.js`, `D343DocumentNumbersTest` / `d343DocumentNumbers.test.js`; `LineTableTest`, `D339DetailedLineTableTest` (INR amounts now in Indian grouping). The Natural Stone Word fixtures of the QT, PI, OC, CI and PL (§8.6) were captured again once, deliberately; the PDF fixtures did not change.

---

## 9. Settings and configuration

Settings live in database tables (seeded by `docs/seed.sql`, identical keys on both stacks) and in `app/.env`. A division setting is read from the division first, then the company (D-257). Changing a setting never changes an order whose PI is approved (D-262).

### 9.1 Admin settings areas and keys

#### Company Settings (`company_settings`, `/settings`, `manage_company_settings`)

Sensitive keys (bank, GSTIN, IEC/PAN, LUT, RCMC) and the reference formats are **protected**: editing needs reason + unlock + confirm, and unprotecting needs a peer-approved request (`field_protection_requests`). Since D-313 (11 Nov 2026) the seed carries **placeholders**, not the company's identifiers: corporate office "Corporate office address, City – 000000, India", GSTIN `29AAAAA0000A1ZY` (passes the GSTIN check; state code 29 kept for the domestic GST split), IEC/PAN `AAAAA0000A`, bank "Bank name (to be entered)" / "Branch (to be entered)" / account `000000000000` / IFSC `XXXX0000000` / SWIFT `XXXXINXXXXX` / "Bank branch address, City – 000000, India" / PIN `000000`, LUT `AD000000000000X`, RCMC `RCMC/CAPEXIL/00000/2026-2027`. Settings › Setup checklist (6.17b) flags each as Needed until the real value is entered at installation (Company Settings, Unlock on the protected rows). The Test Mode e-mail is seeded as the company e-mail (`test_mode_settings.test_email`).

**Plain names on screen (D-332, 12 Nov 2026).** The page never shows a key as the main label. Each setting shows a plain-English name (e.g. `session_timeout_staff_minutes` → "Staff: log out after … minutes idle") and one plain help sentence; the key itself is small grey text under it (and a tooltip on the name) for support. Each section has a plain title (`security` → "Security and logging in", `rbi` → "RBI purpose codes", `ui` → "Screen layout", `zoho_books` → "Zoho Books") and the sections are sorted by that title. The names, help sentences and titles are one map per stack, `App\Helpers\SettingLabels` / `src/helpers/settingLabels.js` (same wording, compared by `settingsPlainLabels.test.js`); a key not in the map shows its name in words ("Something new"), and a key with no help sentence in the map shows its stored description. The seeded descriptions were rewritten in the same words (developer notes such as "SessionAuth" removed); a live database gets them from `docs/migrations/2026-11-12a-settings-plain-labels.sql` (only rows still at the old text). Keys and values did not change. Tests: `SettingsPlainLabelsTest` / `settingsPlainLabels.test.js`.

**Placeholders (QA UI-012, 13 Nov 2026).** Every typed setting field and text box shows a placeholder with an example or the expected format (e.g. `legal_name` "e.g. Company Name Private Limited", `phone` "e.g. +91-0000000000", `mail_cc_emails` "e.g. md@email.com, ed@email.com"), from `SettingLabels::PLACEHOLDERS` / `settingLabels.PLACEHOLDERS` (same list, role addresses only); a key not listed gets one from its value type (number "e.g., 30", e-mail, e-mail list, JSON) or "Type the <name>". `FormPlaceholderTest` / `formPlaceholder.test.js` no longer accept a placeholder (or readonly) that the template adds only under a condition.

| Group | Keys (default) |
|---|---|
| Company | `legal_name`, `registered_office` (placeholder), `corporate_office`, `gstin`, `iec_pan`, `md_name` ("Managing Director"), `md_title`, `director_name`, `director_title`, `phone` (+91-0000000000), `email` (info@email.com) |
| Bank | `bank_name`, `bank_branch`, `bank_account_no`, `swift_bic`, `ifsc`, `bank_address`, `bank_pincode` |
| LUT / RCMC | `lut_number`, `lut_valid_fy`, `lut_expiry_date` (2027-03-31), `rcmc_number`, `rcmc_valid_until` (2027-03-31) |
| RBI | `rbi_purpose_code_advance` P0103, `_balance` P0102, `_freight` P0602 |
| Defaults | `default_currency` USD, `default_estimated_total_cbm` 8.2, `default_estimated_gross_weight_kg` 27000, `default_estimated_net_weight_kg` 26000, `default_ui_template` standard |
| Tolerances | `quantity_shortfall_tolerance_pct` 5.00, `max_bank_charges_pct` 3, `non_usd_price_buffer_pct` 1.75 (advisory) |
| Alerts | `lut_alert_days_x` 30, `lut_escalation_days_y` 15, `rcmc_alert_days_a` 30, `rcmc_escalation_days_b` 15, `fdn_overdue_days_c` 3 (calendar days since D-051, counted by `check_alerts`; the description says so since D-332), `fema_realization_alert_days` 270 |
| Security | `session_timeout_staff_minutes` 240, `session_timeout_client_minutes` 120, `session_timeout_associate_minutes` 120, `session_absolute_staff_hours` 12, `login_remember_browser_days` 30 (D-283), `failed_login_lockout_count` 5, `lockout_duration_minutes` 15, `password_min_length` 10, `password_complexity_json` (upper + number), `password_expiry_days` 0 (off, D-313) |
| Documents | `quotation_validity_days` 30, `pi_validity_days` 15, `revision_start_number` 1, `annexure_product_note`, `show_generated_document_disclaimer` 0, `generated_document_disclaimer_text`, `always_show_consignee_section` 1, `always_show_notify_party_section` 1 |
| Shipping | `bl_type_instruction`, `bl_consignee_instruction` ("TO ORDER OF {company}") — protected |
| Formats (protected) | `master_tracking_ref_format` NC/SC/{YY}/{DDMM}{NNN}, `order_ref_format` NC/SC/ORD/{YY}/{DDMM}{NNN} |
| Disputes | `dispute_response_days_n` 10 (working days), `weekly_off_days` sunday |
| Workflow / orders | `wet_signature_required_buyer_po` 1, `wet_signature_required_supplier_po` 1, `oc_ack_override_restricted` 0, `oc_ack_auto_confirm_hours` 48, `lead_time_display_unit` days |
| Clients | `client_impersonation_enabled` 0, `client_portal_show_order_history` 1, `client_portal_show_document_downloads` 1 |
| Compliance | `ecgc_policy_no` (empty), `ecgc_policy_cover_pct` 90, `ecgc_policy_valid_to` (empty), `ecgc_declaration_day` 10, `benefit_deadline_days_rodtep` 365, `_rosctl` 365, `_duty_drawback` 0, `_other` 0 |
| System | `storage_base_path` — documentation only; the app reads `.env` |

#### E-mail (in `company_settings`)

| Key | Default | Meaning |
|---|---|---|
| `email_testing_mode` | 0 | D-280: Testing mode — hold e-mails to buyers and suppliers (not MUST ones). Set on Settings › E-mail with the per-e-mail switches (`email_settings`). With buyer document e-mails held, approval counts as sent. Replaced `mail_sending_enabled`. |
| `mail_approval_queue_enabled` | 1 | Level-2 approval for buyer e-mails. |
| `reply_to_email` | empty | Monitored reply address (falls back to `email`). |
| `mail_redirect_enabled`, `mail_redirect_address` | 0, empty | Redirect all non-security mail to one address (Super Admin). |
| `mail_cc_emails`, `mail_default_cc_email` | empty | CC on every non-security e-mail (Super Admin only). |
| `zoho_mail_enabled` and `zoho_*` mail keys | 0, empty | Kept, but **not used**: all mail goes through SMTP (owner, 29 Oct 2026). |

SMTP itself is in `.env` (§9.2). "Send Test Email" on the settings page tests it.

#### Zoho Books (in `company_settings`, category `zoho_books`)

`zoho_books_enabled` 0; `zoho_books_client_id`, `_client_secret`, `_refresh_token`, `_organization_id`, `_deposit_account_id` (empty, secrets masked "ends in 1a2b"); `zoho_books_sync_start_date` (go-live date; nothing earlier is sent); `zoho_books_restore_checked_at` (set by the app); `zoho_books_accounts_domain` accounts.zoho.in; `zoho_books_api_domain` www.zohoapis.in; `zoho_sync_clients` 0; `zoho_sync_products` 0; `zoho_api_daily_limit` 800; `zoho_api_minute_limit` 30. Books syncs only when `.env` `ZOHO_INTEGRATION_ENABLED=true` **and** `zoho_books_enabled = 1`. "Test Books Connection" only reads.

#### Pricing (`pricing_settings`, Products › Global pricing settings, MD only, audited)

| Key | Default | Meaning |
|---|---|---|
| `margin_pct` | 15.00 (D-347; was 20.00) | Minimum margin, % of price; a category (Category price rules) or product (Configure) may set its own |
| `other_cost_pct` | 5.50 (D-347; was 2.50) | Other costs to FOB, % of supplier price; category / product as for margin |
| `pricing_fx_rate` | 82.0000 | ₹ per USD — **set on go-live day** |
| `buffer_pct` | 3.00 | Negotiating buffer |
| `incentive_mode` / `incentive_value` | pct / 15.0000 | Team-incentive reserve and global incentive rule |
| `stale_days` | 60 | Supplier price stale after |
| `require_product_pick` | 1 | Goods lines on USD orders must be picked from Products |
| `cbm_from_mm` | 50 | Per-CBM pricing from this thickness |
| `price_request_daily_limit` | 10 | Associate automatic price requests per day |
| `price_request_valid_days` | 7 | Associate price validity |
| `weight_t_per_cbm` / `container_max_t` / `container_cbm` / `thick_k_factor` | 2.70 / 27.00 / 8.00 / 40 | D-359 / D-361: default physical values, used when neither the category (Category price rules) nor the product (Configure) has one |

Also in Pricing: commission levels (`commission_levels`) and ladder (`commission_ladder_steps`) — §7.3; specifications per category; self-approval rule `price_discount` (MD, ED). Self-approval rule `bank_details` (MD, ED): verify bank details one entered oneself, with a reason (D-293).

#### Team incentive (`incentive_settings`, `incentive_roles`; Finance & accounts › Team incentive settings, MD)

`program_enabled` **1** in a fresh install since D-347 (migration 2026-11-12i Part 2 sets it on an existing database too; the owner may still switch it off), `lock_reminder_days` 3. Roles and shares §7.4; caps per role (`cap_per_order_inr`, `cap_per_month_inr`, empty); eligibility per designation and per person (D-259).

#### Associate commission and TDS (`incentive_settings`)

| Key | Default | Where |
|---|---|---|
| `associate_commission_due_days` | 30 | Associates › Commission settings (MD) |
| `associate_claim_hold` | 1 | 〃 |
| `associate_payout_run` / `associate_payout_day` | monthly / last | 〃 — day 1–28 or `last` (D-292); a change of the run needs a reason |
| `associate_payout_holiday_rule` | before | 〃 — Sunday / holiday: the working day before or after (D-292) |
| `associate_counting_threshold_inr` | 500000 | 〃 |
| `associate_gst_pct` | 18.00 | 〃 |
| `associate_portal_commission_months` | 12 | 〃 — commission period shown to associates: 3, 6, 9, 12, 15, 18 or 24 months (D-263) |
| `associate_abroad_charges` | OUR | 〃 — bank charges of a transfer abroad: OUR (NexaCrest pays its own and the intermediary bank's) or SHA (D-264) |
| `associate_abroad_min_payout_usd` | 100 | 〃 — smallest payout abroad; less carries to the next run (D-264) |
| `associate_protection_months` | 6 | 〃 (buyer registration) |
| `associate_registration_link_days` | 7 | 〃 (D-253) |
| `associate_code_changes_per_year` | 3 | 〃 (D-254) — editable on the page since 11 Nov 2026 (0–12) |
| `associate_bank_wait_first_days` | 0 | 〃 — wait before a FIRST bank account is used, 0–90 (D-293; associates and suppliers) |
| `associate_bank_wait_change_days` | 15 | 〃 — wait before a CHANGED bank account is used, 0–90 (D-293) — replaces `associate_bank_wait_days` |
| `bank_details_check_people` | one | 〃 — "Bank details check": one person (not the enterer) or two people (D-293) |
| `associate_upgrade_after_orders` | 3 | 〃 — access level upgrade (D-261), editable on the page since 11 Nov 2026 (1–50) |
| `zoho_gst_commission_tax_id` | empty | Zoho GST tax for commission bills (D-256) |
| `zoho_payout_costs_account_id` | empty | Zoho account for associate payout costs (D-264); empty = not sent |
| `associate_tds_section` | 194H | Settings › Associate TDS (`manage_company_settings`, reason required) |
| `associate_tds_pct` | 2.00 | 〃 |
| `associate_tds_threshold_inr` | 20000 | 〃 |
| `associate_tds_no_pan_pct` | 20.00 | 〃 (206AA) |
| `associate_tds_foreign` | 0 | 〃 |
| `associate_15cb_required` | 1 | 〃 |
| `zoho_tds_tax_id` | empty | 〃 (blank = CA books the TDS) |

Associate access levels (`associate_access_level_settings`, D-261): Starter, Quoting, Full — which portal features each sees (FOB value, commission %, catalogue, Get price, documents …).

#### Divisions (`divisions`, `division_modules`, `division_settings`; Settings › Divisions, MD, `manage_divisions`)

EXP Export is active; DOM, MTT, IMP are planned. The switcher appears only when two or more divisions are active.

| Division | Keys (default) |
|---|---|
| DOM | `dom_default_gst_rate` 18, `dom_eway_threshold_inr` 50000, `dom_e_invoice_applicable` 0, `dom_payment_terms_days` 30, pricing `pricing_fx_rate` 1.0000, incentive `program_enabled` 0 |
| IMP | `imp_default_bcd_pct` 10, `imp_default_sws_pct` 10, `imp_default_igst_pct` 18, `imp_margin_pct` 20, `imp_lc_bank`, `imp_default_cha` (empty), pricing rate 1.0000, incentive off; module `import_stock` |
| MTT | `mtt_max_months_total` 9, `mtt_max_outlay_months` 4, `mtt_advance_limit_usd` 500000, `mtt_usd_inr_rate` 84.0000, `mtt_bank_documents` (7-item list), `mtt_ad_bank_approval` (empty), incentive off; module `merchant_trade` |

#### Lists (`dropdown_options`, Settings › Lists, `manage_dropdown_options`)

Small option lists (container type, CoO type, unit, GST rate, surface finish …), one bulk-save form per list; options are deactivated, never deleted. **surface_finish** is the one list of finish names (D-357, §7.1 "One list of surface-finish names"): a rename there reaches Pick from Products, Pricing › Specifications and the lines of orders whose Quotation is not approved yet; it shows "Used in: …" and the read-only price column.

#### GST and duty rates

Settings › Lists › GST rates (`gst_rates`; seed HSN 6802 = 18 %) and Settings › Lists › Import duty (`import_duty_rates`; seed 6802 = BCD 20, SWS 10, IGST 18). Shown once DOM or IMP is active. All starting values to be confirmed by the CA.

#### Document copies clean-up (`company_settings`, category `document_cleanup`)

| Key | Default | Options |
|---|---|---|
| `doc_cleanup_draft_days` | 90 (D-313) | 0, 30, 90, 180 |
| `doc_cleanup_superseded_days` | 365 (D-313) | 0, 90, 180, 365 |
| `doc_cleanup_cafin_mode` | latest_only (D-313) | keep_all, latest_only (older copies removed 30 days after a newer one) |

Never removed: anything sent to the client, the current copy of every revision, the current internal Word copy, uploaded files, files of an order with an open dispute, documents in a CA-locked financial year. Daily job 02:15.

#### Watermarks and DOCX

`watermark_settings` (draft and final, §8.4); `docx_generation_settings` (DOCX on for QT, ANNEXA, PI, OC, BUYERPO, SUPPO, FDN, PL, BLI, CI, COOPREP, ESI).

#### Remittance difference reasons

`remittance_difference_reasons` — Admin-managed list for "Why is it different?" with treatment bank_charges / short / excess.

#### Backups (Settings › Backups, read only, D-260)

The page (`manage_company_settings`) only shows what the jobs report; the rules are the `BACKUP_*` keys in `.env` (§9.2). Schedule, retention and warnings: §3.15. Set-up and restore: §12.3.

### 9.2 `.env` keys (both `app/.env.example` files)

| Key | PHP | Node | Default / note |
|---|---|---|---|
| `APP_ENV` | ✓ | ✓ | `production`. `local` only on a dev machine (prints 2FA codes on screen). |
| `DEV_SERVER` | ✓ | ✓ | `false`. `true` on any test server shows a red "DEV / TEST ENVIRONMENT" strip. |
| `APP_URL` | ✓ | ✓ | Site URL. |
| `PORT` | — | ✓ | 3000. |
| `SESSION_SECRET_KEY` | — | ✓ | Random 32-byte value. |
| `APP_ENCRYPTION_KEY` | ✓ | ✓ | Required; encrypts Buyer Action Link tokens. Changing it breaks existing links. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET` | ✓ | ✓ | 127.0.0.1, 3306, nexacrest, nexacrest_app, —, utf8mb4 |
| `DB_POOL_SIZE`, `SESSION_DB_POOL_SIZE` | — | ✓ (optional) | 10, 5 |
| `STORAGE_BASE_PATH` | ✓ | ✓ | Absolute path outside the web root. |
| `PUPPETEER_EXECUTABLE_PATH` | — | ✓ | `/usr/bin/chromium` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `SMTP_FROM_ADDRESS`, `SMTP_FROM_NAME` | ✓ | ✓ | Port 587, `tls` (STARTTLS required) or `ssl` (465). |
| `ZOHO_INTEGRATION_ENABLED` | ✓ | ✓ | `false` = no Zoho call ever. Always written out. |
| `ZOHO_ENVIRONMENT` | ✓ | ✓ | `test` = only `TEST-` customers; `production` = only real orders. |
| `ZOHO_EINVOICE_ENABLED` | ✓ | ✓ | `false`. E-invoice / e-way bill / GST filing only with production. Never true while testing. |
| `SMS_GATEWAY_PROVIDER`, `SMS_GATEWAY_API_KEY`, `SMS_GATEWAY_SENDER_ID` | ✓ | ✓ | Optional; blank = e-mail 2FA only. |
| `RUN_JOBS_IN_PROCESS` | — | ✓ | `false` = OS cron runs the jobs. Never true and cron together (double-fires). |
| `BACKUP_DIR` | ✓ | ✓ | Outside web root and storage (scripts refuse otherwise). |
| `BACKUP_KEEP_YEARS` | ✓ | ✓ | 10 |
| `BACKUP_THIN_AFTER_DAYS` | ✓ | ✓ | 120 (0 = keep every nightly copy) |
| `BACKUP_ENCRYPTION_KEY_FILE` | ✓ | ✓ | chmod 600; keep a copy off the server. |
| `BACKUP_OFFSITE_TARGET` | ✓ | ✓ | Empty until chosen; an rclone remote. |
| `BACKUP_RESTORE_TEST_DB` | ✓ | ✓ | Default `<DB_DATABASE>_restore_test`; never the live DB. |

Secrets (Zoho keys, passwords, tokens) go only in the server's environment or the masked Admin fields, never in chat or git (R17).

### 9.3 Installation order (D-241)

```bash
mysql -u <user> -p -e "CREATE DATABASE nexacrest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u <user> -p nexacrest < <stack>/docs/schema.sql
mysql -u <user> -p nexacrest < <stack>/docs/seed.sql
```

Both files start with `SET NAMES utf8mb4;`. Do not remove it, or every em-dash and ₹ is stored garbled. The 120 development migrations were folded into these two files on 2 Nov 2026 and deleted (git history keeps them); `docs/migrations/` holds only its README until after go-live.

A fresh install never needs a migration — the tests FreshInstallCompleteTest / freshInstallComplete.test.js check that every file in docs/migrations is already in schema.sql/seed.sql.

The seed holds the literal `__STORAGE_BASE_PATH__`. Replace it in three tables with the same absolute path you put in `.env`:

```sql
UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/abs/path/to/storage');
UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/abs/path/to/storage');
UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '/abs/path/to/storage');
```

If you skip the second line nothing errors, but every signature and seal on every PDF prints blank. Then fill `.env` (§9.2), set up the scheduled jobs (§3.9) and run the real-values block below.

### 9.4 README go-live SQL block (real values)

The seed holds role placeholders only. The two founder logins are **protected accounts**: trigger `trg_users_protected_bu` refuses any change to their name, e-mail, phone, role, designation, signatory flag, Super Admin or active status, from the app or SQL. Once, right after import, as the database admin user:

```sql
DROP TRIGGER IF EXISTS trg_users_protected_bu;
UPDATE users SET email = 'real-md@yourcompany.com', name = 'Real MD Name' WHERE email = 'md@email.com';
UPDATE users SET email = 'real-ed@yourcompany.com', name = 'Real ED Name' WHERE email = 'ed@email.com';
UPDATE company_settings SET setting_value = 'Real MD Name'       WHERE setting_key = 'md_name';
UPDATE company_settings SET setting_value = 'Real Director Name' WHERE setting_key = 'director_name';
UPDATE company_settings SET setting_value = 'info@yourcompany.com' WHERE setting_key = 'email';
UPDATE company_settings SET setting_value = '+91-XXXXXXXXXX'     WHERE setting_key = 'phone';
UPDATE company_settings SET setting_value = 'Real registered office address, City – PIN, India' WHERE setting_key = 'registered_office';
UPDATE assets SET name = REPLACE(name, 'Managing Director', 'Real MD Name') WHERE name LIKE 'Managing Director%';
-- then re-create trg_users_protected_bu exactly as printed in the stack README
```

Check with `SHOW TRIGGERS LIKE 'users';`. The block is safe to run again to fix a typo. Signature blocks print `users.name`, so this is what puts real names on PDFs. The full trigger text is in `PHP_MySQL_Version/README.md` ("First-time setup") and the Node README.

Other go-live items (README "Placeholder values", `docs/guides/GO_LIVE_CHECKLIST.md`): each seeded Super Admin must set a new password at first login (forced); confirm `lut_expiry_date` and `rcmc_valid_until`; fill ECGC only if there is a policy; set `pricing_fx_rate`; upload the logo, MD signature, company seal and watermark on Company Assets; decide `program_enabled`; set `zoho_books_sync_start_date` before switching Zoho on.

---

## 10. Hard rules and restrictions

These rules come from `CLAUDE.md` (project instructions) and register §2 (R1–R26). They override default habits. Read the register before asking the owner anything already decided (R18).

| Rule | What it means | Why it exists |
|---|---|---|
| **Parity** (R1) | PHP and Node always behave the same; every change goes into both, and every UI change into all counterparts, without being asked. | The owner has not finally chosen a hosting stack; either must be a drop-in. Differences found in QA (e.g. a paisa in rounding) were real bugs. |
| **Verify before pushing** (R2) | PHPUnit, Jest and the Playwright e2e suite (`e2e/`) against both stacks. Never push unverified code, even "to keep it safe". No skipped tests (R22). | Untested pushes broke working behaviour during development. |
| **Ask before pushing** (R3) + **review pack** (R4) | Never push without asking. First a short summary (what is pushed, which files change, which functionality is affected), then the nine-part review pack listed in §16 step 13. | Changed from "push always" to ask-first on 27 Oct 2026 after the Supplier PO PDF mistake. The owner must be able to check a change without reading code. |
| **One branch** (R5) | Work only on the agreed branch; never create a new one. | The owner will rename it to `main`. |
| **No document change without double confirmation** (R6) | Show what the document shows now and after; get confirmation twice. All current PDFs are correct. | Documents go to buyers, banks and customs; a wrong word has legal and money effects (§8.7). |
| **Standard and Kanban stay parallel** (1 Nov 2026) | Any user may choose either template, so every filter, search, count, field, badge, action, link and money rule exists on both, in the same change, on both stacks. `KanbanParityTest` / `kanbanParity.test.js` enforce it. | A user on one template must never miss something the other shows. |
| **Mockups first** (R7) | For a larger UI redesign, show mockups and get confirmation (usually twice) before building. | Cheaper to change a picture than code on two stacks. |
| **CAFIN never reaches the client** (R10) | Not by download, link, email or portal; not to Zoho. Enforced structurally: `internal` category, own template, `view_cafin`, portal blocks by ID, e-mail refuses it, `_INTERNAL` file names. | CAFIN shows cost, margin, benefits and commissions. |
| **Supplier and buyer never cross** (R11) | Vendors and CHA never see the client's details, terms or amounts. Confirmed "already taken care" (D-063). | Commercial confidentiality. |
| **Browser-side validation on every form** (R12) | One sitewide `fieldError()` in `public_html/assets/js/app.js` (PHP) and `public/js/app.js` (Node); HTML attributes for simple rules; message beside the field; server checks stay. "Re-enter" fields named `<name>_confirm` / `confirm_password` or `data-match`. `FormValidationConventionsTest` / `formValidationConventions.test.js`. | Users lost typed data on server-side refusals; one shared script keeps behaviour identical everywhere. |
| **Placeholders on every input** (1 Nov 2026) | Every typed input and textarea has a placeholder with an example or format (not date, file, checkbox, radio, hidden). Labels, required "*", and "Choose…" on required drop-downs. `FormPlaceholderTest`, `FormLabelConventionsTest` (+ Node twins). | Staff should know the expected format without asking. |
| **MySQL compatibility** (R13) | Host = MySQL 8, dev = MariaDB. No `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS`, no `CREATE INDEX IF NOT EXISTS`; guard with information_schema + PREPARE. ONLY_FULL_GROUP_BY. Migrations run-twice-safe, `SET NAMES utf8mb4` first, filename order. | MariaDB-only syntax fails on the live host. |
| **SOP** (R14) | Edit `docs/SOP`, then copy to both stacks' `docs/SOP`. | Three copies must match. |
| **Money descriptions** (D-212) | §7.12. | Zoho and the CA must know what every rupee is. |
| **Zoho change log** (R15) | Every change in any Zoho app, by anyone, test or real, gets a row in `docs/zoho/zoho-change-log.csv` (UTF-8 with BOM) at the time, with the full column set in CLAUDE.md. Read-only checks too. Test records start `TEST-` and are removed before go-live (logged). | Zoho is the books of account; every change must be traceable and test data must not stay. |
| **Zoho never affects core functionality** (R16) | With Zoho off, the app works exactly the same. Nothing from development or testing may reach the GST portal. | The business must run if Zoho is down or unconfigured. |
| **Secrets never in chat or git** (R17) | Keys only in the server's environment / masked Admin fields. | Security. |
| **Everyone sees only what they can use** (R23) | Links, menus and buttons only for permitted users; the server refuses regardless. | Least privilege, and clean screens. |
| **IST everywhere** (R24) | Server, DB session, timestamps, documents. | Indian business and legal dates. |
| **Order is self-contained** (R25, D-262) | Every amount and snapshot is stored on the order; a later master change never alters it — except the buyer details, which follow the client automatically while nothing is issued and by approved request after (D-337, 6.1b). | Agreed orders must not move. |
| **Reasons** (R26) | Every typed reason ≥ 10 characters; skips need a reason and are audited; nothing silently set to 0. | Audit trail. |

### 10.1 Other standing owner rules

**Internal Margin Impact Reference is MD-only.** This is an owner reference about margins, held **outside the app**. It is not in the app at all: no route serves it, which `DivisionOverheadAndInvestorTest` checks (no route pattern contains `margin-impact`). D-245 also says it stays MD-only. Do not add it to the app, reports, the Reference Library or any document. Why: it reveals the company's margin structure; even the ED and the Investor role must not see it.

**No PII (6 Nov 2026, strict hard rule).** No real person's name, e-mail, phone, personal address, personal ID (PAN/Aadhaar/passport) or signature image named after a person in any file: code, comments, SOPs, READMEs, registers, mockups, seed data, tests, fixtures. Use role placeholders: "Managing Director" / md@email.com, "Executive Director" / ed@email.com, "Admin" / admin@email.com, info@email.com, +91-0000000000. Real values are entered only on the live server (§9.4, or Admin). Any occurrence found is fixed at once. Why: the repository and its documents are shared with developers, QA and external advisers; personal data must not travel with them.

**Fresh install, no migrations (D-241, 2 Nov 2026).** Go-live = `schema.sql` then `seed.sql`; there is no existing data to upgrade. The 120 development migrations were folded in and removed (git history keeps them); a database built from the two files was compared with a fully migrated one (153 tables, same columns, indexes, keys and reference data). **After go-live**, every change ships twice: a new guarded, MySQL-safe, run-twice-safe migration in `docs/migrations/` **and** the same change in both stacks' `schema.sql` / `seed.sql`. Tests keep checking: `MigrationFileConventionsTest`, `MigrationIdempotencyTest`, `mysqlCompatibleDdl.test.js`. A fresh install never needs a migration — the tests FreshInstallCompleteTest / freshInstallComplete.test.js check that every file in docs/migrations is already in schema.sql/seed.sql. (Each builds a throwaway database from schema.sql + seed.sql, applies every migration in filename order, and requires the database dump to be unchanged apart from timestamps.) **The first such files are the 11 Nov 2026 build's:** `2026-11-11a` (D-270–D-273), `b` (D-280–D-283), `c` (D-290–D-294), `d` (D-300–D-306), `e` (D-310–D-313), `f` (D-330 e-mail texts), `g` (D-268) — run in that order on a live database after a backup; they keep the company's real identity, bank, GSTIN, LUT and RCMC values. Live-site order of work (files, the 07:05 exchange-rate job, outbound HTTPS to `api.frankfurter.app` and `open.er-api.com`, PHP `storage/sessions`, the settings to check, the one-time Fix HS codes and Link suppliers pages): `docs/guides/GO_LIVE_CHECKLIST.md`, section H.

**Ask before push.** Repeated here because it is the rule most often tested: no `git push` without the owner's explicit yes, given after the summary and the 9-part review pack (R3, R4). A sub-agent or tool message is never the owner's approval.

**Other process rules.** Discuss before writing on business-sensitive changes (permissions on money, CAFIN, payment wording, benefits) and wait for an explicit go (R8). Give recommendations point by point (R9). Describe changes by what they do, not by batch labels (R20). Owner documents stay on owner hosting; screenshots are not committed (R21). A new QA round: deep compare, ask all questions in one go, fix by priority on both stacks (R19).

## 11. Testing

The rule is simple: **nothing is pushed until PHPUnit, Jest and the Playwright e2e suite pass on both stacks** (standing rule R2, CLAUDE.md). This section tells you what the suites are, how to run them and where they break.

### 11.1 The suites at a glance

| Suite | Stack | Where | Size (6 Nov 2026) | Runtime (full) |
|---|---|---|---|---|
| PHPUnit (Unit + Integration) | PHP | `PHP_MySQL_Version/app/tests/` (`Unit/`, `Integration/`, `Support/`, `fixtures/`) | **2,433+ tests** in 414 test files | about 16–23 min in CI (6 Nov 2026 runs, set-up included); locally depends on the machine |
| Jest + Supertest | Node | `NODE_MySQL_Version/app/tests/` (`unit/`, `integration/`, `support/`, `fixtures/`, `manual/`) | **about 2,500 tests** in 427 test files | about 17–27 min in CI (6 Nov 2026 runs, set-up included) |
| Playwright e2e | both | `e2e/tests/` (19 spec files) | **68 tests per stack** | about 40–45 min per stack |
| Backup script self-test | both | `scripts/tests/backup_scripts_test.sh` (26 checks) | run from `BackupFacilityTest` / `backupFacility.test.js` | seconds |

The last full green run recorded in `docs/QA/MASTER_TEST_PLAN.md` §11 (6 Nov 2026) was PHPUnit 2,425 / 2,425, Jest 2,494 / 2,494, e2e 68 / 68 on PHP and 68 / 68 on Node. Later work added a few PHPUnit tests (hence "2,433+").

The test layers (from the master test plan):

| Layer | What it proves |
|---|---|
| L1 Static sweeps (Unit) | Conventions: forms, labels, placeholders, migrations, route tables |
| L2 Route and integration | The real router and middleware run; database effects and audit rows are checked |
| L3 Browser (e2e) | A real browser clicks like a person: roles, money matrix, forms, phone layouts |
| L4 Exploratory crawl | A systematic walk of every page per role, template and screen size |

Two hard rules about tests:

- **No skipped tests** (R22, D-193). Fix a test or delete it. Tests that need a PDF tool fail loudly if the tool is missing; they never skip.
- **Tests never send real mail and never depend on the developer's machine** (D-194). The PHPUnit bootstrap forces `SMTP_HOST` blank; Zoho calls go to a fake transport (`ZohoBooksService::setTransport()` / its Node twin).

### 11.2 Running the suites locally

**Prerequisites (both unit suites):**

- MySQL 8 or MariaDB running, and the `mysql` command-line client on the PATH (the schema has triggers with `DELIMITER $$`, which only the CLI understands, so both suites shell out to it).
- `app/.env` in each stack with a DB user that may **create and drop databases**. Only the database name is overridden by the tests.
- `poppler-utils` (`pdftotext`, `pdfinfo`) and `unzip` — the document tests read the generated PDFs and the `.docx` XML.
- Node stack: a Chromium binary for Puppeteer (`PUPPETEER_EXECUTABLE_PATH` in `app/.env`), or the PDF tests fail with "Document generation failed".

**PHP (PHPUnit):**

```bash
cd PHP_MySQL_Version/app
vendor/bin/phpunit                                        # everything (same as: composer test)
vendor/bin/phpunit --testsuite Unit                       # only the static sweeps
vendor/bin/phpunit tests/Integration/KanbanParityTest.php # one file
vendor/bin/phpunit --filter testEveryBoardOffersWhatItsListOffers
```

**Node (Jest):**

```bash
cd NODE_MySQL_Version/app
npm test                                   # = jest --runInBand (one file at a time — required)
npx jest --runInBand kanbanParity          # one file, by name fragment
NODE_OPTIONS=--max-old-space-size=6144 npx jest --runInBand   # what full runs used in development
```

Jest **refuses** to start with more than one worker (`tests/support/globalSetup.js`, QA Round 7 R7-N05): the files share one database, so parallel files give false failures.

**e2e (Playwright)** — needs both dev servers running against real dev databases (not the test databases):

```bash
# PHP dev server (always through the router script)
cd PHP_MySQL_Version/public_html && php -S 127.0.0.1:8080 _dev_router.php &

# Node dev server — output MUST go to a file, 08-email-functionality reads it
cd NODE_MySQL_Version/app && npm start > /path/to/node-server.log 2>&1 &

cd e2e
npm install                     # once
npx playwright install chromium # once, or set PW_CHROMIUM_PATH
npm run test:php                # BASE_URL=http://127.0.0.1:8080
npm run test:node               # BASE_URL=http://127.0.0.1:3000 (set NODE_SERVER_LOG to the log path)
npx playwright test tests/12-money-matrix.spec.js   # one spec
```

e2e settings come from the environment: `BASE_URL`, `PW_CHROMIUM_PATH`, `E2E_DB_USER`, `E2E_DB_PASSWORD`, `E2E_DB_NAME` (defaults `nexacrest_php_dev` / `nexacrest_node_dev`), `NODE_SERVER_LOG`, `PHP_ERROR_LOG`. One-time setup per dev database (all in `e2e/README.md`):

1. Load `docs/schema.sql` then `docs/seed.sql`.
2. Replace `__STORAGE_BASE_PATH__` in `assets`, `user_signature_assets` and `reference_library_documents`.
3. Give the seeded MD login (md@email.com) a known password and clear `force_password_change`; set the same in `e2e/tests/support/auth.js`.
4. Load `e2e/seed-ui-test-users.sql` (one user per role). Its password hash is PHP's `$2y$` form; for Node swap in the `$2b$` hash named in the file's header.

### 11.3 Test databases and isolation

| | PHP | Node |
|---|---|---|
| Database | `nexacrest_phpunit_test` | `nexacrest_node_jest` |
| Built | once per PHPUnit process, in `tests/bootstrap.php` → `DbTestCase::rebuildDatabase()` (drop, create, `schema.sql`, `seed.sql`) | once per Jest run, in `tests/support/globalSetup.js` |
| Dropped | at process shutdown | in `globalTeardown.js` |
| Never touches | the dev/demo databases | the dev/demo databases |
| Time zone | `Asia/Kolkata` (matches the app, R24) | `TZ=Asia/Kolkata` set in `jest.config.js` before workers start |

**`DbTestCase`** (`PHP_MySQL_Version/app/tests/Support/DbTestCase.php`) is the base class for any PHP test that needs the database. Its Node twin is `tests/support/fixtures.js`. They provide the same helpers:

- `createTestClient()`, `createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', …)` — the order is built exactly like a real one: the client, incoterm, currency, port and preset are *snapshotted* onto the order (the standalone-order rule, R25), and the 9 stage rows, compliance tasks and benefit-scheme rows are created by the real repositories.
- `createTestUser($roleName)`, `createTestDocument()`, `createTestFile()`, `stageStatuses()`.
- `completeAssociateKyc()` (D-252: no commission until KYC is complete).
- `allowHandTypedGoodsLines()` — switches off "goods lines must be picked from Products" (D-245 P2) for one test; `setUp()` switches it back on.
- `setUp()` also resets the per-request caches (permissions, active department, incentive roles) and frees the eight fixed test client e-mails (clients.email is unique, R6-N16).

**`clearDivisionTradeData()`** — the Domestic Trade, Import & Distribution and Merchant Trade tests leave rows in the shared test database. That made list/board tests that expect "nothing" depend on run order. Every such test class calls it in `tearDownAfterClass()` (Node: `afterAll`). It deletes all rows from the 14 division-trade tables, turns any order in another division back into a cancelled Export test order, and sets every division except Export back to `planned`. **Rule:** a new test that creates division-trade data, or activates a division, must call it.

**The stone fixtures and `CAPTURE_STONE_DOCS`.** `StoneDocumentsUnchangedTest` / `stoneDocumentsUnchanged.test.js` compare every stone document with captured text in `tests/fixtures/stone_documents/` (§8.6). `CAPTURE_STONE_DOCS=1` rewrites the fixtures. **Only ever capture from code that is known to be correct and only after the owner has double-confirmed the document change (R6).** If this test fails, a stone document changed: treat it as a document change, not as a test to update.

**The suite lock.** Each suite uses one fixed database name, so two runs of the same suite at the same time drop each other's database mid-run. In development every full run was wrapped in `flock` on a lock file, for example:

```bash
flock /path/to/test.lock vendor/bin/phpunit
flock /path/to/test.lock npx jest --runInBand
flock /path/to/test.lock npx playwright test
```

Use one lock file for everything that touches the same databases (unit suites and e2e). The master test plan's note "the shared test database is locked by another process" refers to this.

### 11.4 The QA matrices and the UI crawl (built 5–6 Nov 2026, D-258)

| Matrix | Files (PHP / Node / e2e) | What it does | Last result |
|---|---|---|---|
| **Roles and single permissions** | `e2e/tests/qa-roles-permissions-matrix.spec.js` + `e2e/support/qa-matrix.js` | Creates one user per staff role and one user per permission (on a role holding only that permission) — 118 users. Each signs in and opens every staff GET route with real IDs. Fails on any 5xx, error text in a page, or a page its gate should refuse that answered 200. Also checks an order of a planned division never shows to Export staff. Cleans up after itself. | 23,010 requests per stack, 0 wrong answers. About 6 min PHP, 4 min Node. |
| **Incoterm × payment preset** | `QaIncotermPresetMatrixTest.php` / `qaIncotermPresetMatrix.test.js` | FOB, CFR, CIF × every seeded preset; generates every order document (QT, ANNEXA, BUYERPO, PI, OC, SUPPO, FDN, PL, BLI, CI, COOPREP) in PDF and Word. A business refusal ("record the freight first") is allowed; a crash is not. QT/PI/OC/CI must show the order total and the preset's split. `QA_REPORT=/path.json` writes the table. | 165 combinations per stack, 0 crashes, 0 wrong totals or wordings |
| **Mail** | `QaMailMatrixTest.php` / `qaMailMatrix.test.js` | Every `send_<code>` template renders with a subject, the buyer as recipient and no unreplaced `{token}`; PI / Debit Note / CI mails carry the payment link; generic templates render or refuse with a business message; with the approval queue on and sending off, each send queues, is approved and dispatches; Supplier PO and CAFIN can never be mailed. | 0 problems on either stack |
| **Payment wording** (older) | `PaymentTermsScenariosTest.php` / Node twin | PI and CI wording for all 15 incoterm × preset cells | green |
| **Money matrix** | `e2e/tests/12-money-matrix.spec.js` + `support/moneyMatrixSeed.js` | One user per role; a sentinel order where every money class carries a distinctive amount; ~75 Standard + 12 Kanban screens, 18 files and the dossier ZIP per role. Fails on any amount a role may not see (D-229). | 0 violations |
| **UI crawl** | `e2e/tests/qa-ui-crawl.spec.js` | Human-style walk: MD and main roles, Standard and Kanban, desktop and phone. Follows every same-site link (one page per route shape, up to `QA_CRAWL_MAX`, default 120), opens every step card and tab, records 4xx/5xx, console errors and horizontal overflow, and submits each form empty and with bad values (the browser must stop it). Every POST is aborted, so nothing is written. Options: `QA_CRAWL_ROLES`, `QA_CRAWL_MAX`, `QA_CRAWL_OUT`. | ~420 pages per run, 8 runs clean |

The full specification of the matrices (role × route, single permission × route, mail switch combinations, template × list page, divisions) is in `docs/QA/MASTER_TEST_PLAN.md` §3 and §5. Its coverage-gap list (§8, G1–G18) shows what is still thin.

### 11.5 Conventions tests (the ones that enforce the standing rules)

These fail the build when a rule in CLAUDE.md is broken. If one fails after your change, **fix the change, not the test**.

| Rule | PHP | Node |
|---|---|---|
| Standard and Kanban stay parallel (2026-11-01 hard rule) | `KanbanParityTest`, `KanbanCoverageTest`, and one `*KanbanBoardTest` per board | `kanbanParity.test.js`, `kanbanCoverage.test.js`, `*KanbanBoard.test.js` |
| Browser-side validation, `*_confirm` / `data-match` fields | `FormValidationConventionsTest` (Unit) | `formValidationConventions.test.js` |
| Placeholders on every typed input | `FormPlaceholderTest` (Unit), `FieldPlaceholdersTest` | `formPlaceholder.test.js` |
| Labels, required `*`, "Choose…" on required drop-downs | `FormLabelConventionsTest` (Unit), `FieldLabelsTest` | `formLabelConventions.test.js` |
| Every permission-gated route refuses the wrong user | `RouteMatrixTest` | `routeMatrix.test.js` |
| Every route without a gate is deliberate | `UngatedRoutesTest` | `ungatedRoutes.test.js` |
| Every route has a behaviour test (D-211) | `RouteBehaviourTest` | `routeBehaviour.test.js` |
| Menu links match route permissions (R23) | `NavLinksMatchRoutePermissionsTest` | `navLinksMatchRoutePermissions.test.js` |
| MySQL-safe migrations (R13) | `MigrationFileConventionsTest` (Unit), `MigrationIdempotencyTest` (runs each file twice) | `mysqlCompatibleDdl.test.js` |
| Fresh install already holds every migration (schema.sql + seed.sql) | `FreshInstallCompleteTest` | `freshInstallComplete.test.js` |
| No money leak (D-229) | `MoneyLeakClosuresTest`, `MoneySeparationTest` | `moneyLeakClosures.test.js` |
| PHP and Node documents have the same page breaks (D-057) | `DocumentPageCountParityTest` | `tests/manual/documentPageCountParity.js` (run by hand) |
| Stone documents unchanged (D-257 Phase 1) | `StoneDocumentsUnchangedTest` | `stoneDocumentsUnchanged.test.js` |

**Stack parity itself** (same routes, methods, permission gates and guards on both stacks) has **no standing unit test**. It was proven once by the QA inventory (735 / 735 routes on 5 Nov 2026, kept as `e2e/support/qa-inventory.json`); the only standing parity tests are `KanbanParityTest` and `DocumentPageCountParityTest`. The route matrix tests on each side cover the gates; the twin test files are named alike (`FooBarTest.php` ↔ `fooBar.test.js`). Gap G14 in the master plan lists the few test files that still exist on one side only.

### 11.6 Continuous integration

`.github/workflows/tests.yml` (owner decision O-5 / D-216 C-5, 1 Nov 2026) runs on **every push and pull request**:

- **PHP — PHPUnit:** Ubuntu, PHP 8.3 (`pdo_mysql, mbstring, gd, zip, intl, bcmath`), a MySQL 8.0 service, `composer install`, `.env` copied from `.env.example` with test values (APP_ENV=local, SMTP blank, Zoho off, a CI-only encryption key), `poppler-utils` + `unzip`, then `vendor/bin/phpunit`.
- **Node — Jest:** Ubuntu, Node 22, the same MySQL 8.0 service, `npm ci` with `PUPPETEER_SKIP_DOWNLOAD`, the same `.env` approach plus `SESSION_SECRET_KEY`, then `npm test`.

Why MySQL 8 in CI: the live host runs MySQL while development runs MariaDB, so CI catches MySQL-only differences and strict mode (R13). **CI does not run the e2e suite** — that needs running servers and seeded dev databases, so it stays a manual step before every push. No secrets are needed in CI.

### 11.7 Known slow or fragile tests

| Test | Problem | Cause / what to do |
|---|---|---|
| `12-money-matrix.spec.js` on **Node** | About 24 minutes (PHP: about 2; the test allows up to 90) | The order-profitability report is slow on a large dev database. It is the longest single test. It also leaves `money-matrix-role-N@nexacrest.test` users and `MM-SENTINEL-*` orders in the dev database. |
| `qa-roles-permissions-matrix.spec.js` | Uses a snapshot route list | `qa-matrix.js` reads `e2e/support/qa-inventory.json` (or the file in `QA_INVENTORY`). It is the 5 Nov 2026 inventory (735 routes); routes added since are not driven until it is regenerated, and the script that generated it is not in the repository (§14.5). |
| `qa-ui-crawl.spec.js` | ~6 min per template × size | One finding (travel-expense form "not focusable" on PHP) did not reproduce; `qa-order-cost-forms.spec.js` now covers it. A one-off focus failure here is usually timing. |
| `08-email-functionality.spec.js` on Node | Fails at once | The Node server's output was not redirected to a file, or `NODE_SERVER_LOG` points elsewhere. |
| Any document test on Node | "Document generation failed" | `PUPPETEER_EXECUTABLE_PATH` does not point at a real Chromium. The real reason is only in the server log. |
| PDF / Word reading tests | Fail loudly | `pdftotext` / `unzip` missing (they never skip, D-193). |
| List and board tests | Pass alone, fail in the full run | Another test left records behind. Add `clearDivisionTradeData()` (division data) or clean your own rows; never rely on run order. |
| Jest full run | Out of memory on big runs | Use `NODE_OPTIONS=--max-old-space-size=6144`. |
| Upload routes in PHPUnit | Cannot really upload | PHP cannot upload inside PHPUnit, so upload routes are tested by their refusals; Jest/Supertest tests the real upload (D-211). |
| `stoneDocumentsUnchanged` | Slow (180 s timeout) | Renders 16+ documents. Do not raise the timeout to hide a real hang. |
| Cross-browser | Only Chromium is automated | Safari / Firefox are a manual 5-step check (open point O-17). |
| e2e login for 6 of 8 roles | Fails on a fresh clone | `e2e/seed-ui-test-users.sql` not loaded, or the PHP hash used on Node. |

### 11.8 Adding a test for a new feature, on both stacks

1. **Write the test first** (CLAUDE.md, "tests first"). Name the twins alike: `tests/Integration/WidgetApprovalTest.php` and `tests/integration/widgetApproval.test.js`. Put a one-line comment at the top of each naming the owner decision (D-xxx) and the twin file.
2. **PHP:** extend `App\Tests\Support\DbTestCase`. Build data with `createTestClient()` / `createTestOrder()` / `createTestUser('Export Executive')`. Call the controller or service directly; check the database row, the audit row and the page text.
3. **Node:** use `supertest` against `require('../../src/server')`, the helpers in `tests/support/fixtures.js`, and `db.queryOne()` for checks. Log in as a real user (see `kanbanParity.test.js` for the pattern), keep the CSRF token, and end with `afterAll(() => db.pool.end())`.
4. **Check the refusals too:** a user without the permission gets 403 and nothing is written; a missing ID says "not found" and writes nothing (D-209); a disallowed state is refused (D-116).
5. **Money:** assert the exact description wording (D-212) and that the save is refused without the bank reference.
6. **Document output:** do not add or change a golden file without the owner's double confirmation (R6).
7. **Clean up:** call `clearDivisionTradeData()` if you touched a non-Export division; use unique e-mails (`…-<random>@nexacrest.test`).
8. **UI:** if the feature adds a page or form, the conventions tests will check labels, placeholders, validation and the Kanban twin. Add the page to `KanbanParityTest::pages()` and its Node twin if it is a list page. Add an e2e step if it is a critical path.
9. **Run** the new test alone on both stacks, then the full suites and e2e under the lock (§16).

---

## 12. Operations

### 12.1 Install and go-live

The business view is `docs/guides/GO_LIVE_CHECKLIST.md` (tick it top to bottom on the day). The technical steps are in each stack's README, "First-time setup". In short:

**1–3. Database, storage paths and real values.** Follow §9.3 (create the database, load `schema.sql` then `seed.sql`, replace `__STORAGE_BASE_PATH__`) and §9.4 (the real-values SQL that puts real names on the founder logins and on PDFs, then re-creates the protection trigger). No real name or e-mail is ever written into the repository.

**4. `app/.env`** (copy from `app/.env.example`):

| Key | Value on live |
|---|---|
| `APP_ENV` | `production` (`DEV_SERVER=false`, real `APP_URL`) |
| `APP_ENCRYPTION_KEY` | a fresh random key; Node also needs `SESSION_SECRET_KEY` |
| `DB_*` | the live database |
| `STORAGE_BASE_PATH` | absolute path to `storage/`, outside the web root |
| `SMTP_*` | Bluehost SMTP (D-139). Blank encryption means STARTTLS/TLS (D-140) |
| `ZOHO_INTEGRATION_ENABLED` / `ZOHO_ENVIRONMENT` / `ZOHO_EINVOICE_ENABLED` | always written out. `false` / `test` / `false` until the live Books organisation is ready; then `true` / `production`; e-invoice stays `false` (D-161) |
| `SMS_GATEWAY_*` | may stay empty (email 2FA only) |
| `PUPPETEER_EXECUTABLE_PATH` (Node) | a real Chromium |
| `RUN_JOBS_IN_PROCESS` (Node) | `false` if you use cron (never both) |
| `BACKUP_*` | see §12.3 |

**5. Web server.** PHP: point the document root at `public_html/` (`.htaccess` routes everything to `index.php`). Node: run `src/server.js` under PM2 (`pm2 start src/server.js --name nexacrest`, `pm2 save`, `pm2 startup`) behind Nginx.

**6. Scheduled jobs**, in Indian time (`CRON_TZ=Asia/Kolkata`, R24): install every job in the table in §3.9, including the hourly Zoho sync (**must be installed**, D-233: records reach Books only on a sync) and the three backup scripts (§12.3).

Example (PHP on Bluehost): `0 * * * * php /home/<cpaneluser>/nexacrest_webapp/app/cron/zoho_sync.php >> /home/<cpaneluser>/logs/zoho_sync.log 2>&1`. Node: `cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/zohoSync.js >> /var/log/nexacrest/zoho_sync.log 2>&1`. On Node, use **either** cron **or** `RUN_JOBS_IN_PROCESS=true` (node-cron inside the web process), never both, or every job fires twice.

**7. Backups set-up** (§12.3), then **first login** (md@email.com / the seeded password `ChangeMe#2026`, forced change, then the e-mailed login code — on a first install with e-mail not yet set up, read it from the server log or set up mail first; then make the MD and Admin backup codes on My Account), company settings, company assets, pricing, commission and incentive settings, people, products, and a **dry run in Test Mode** (references get a `TEST-` prefix) with one order taken to closure. All in the go-live checklist, sections C–F. On go-live day switch e-mail **Testing mode** off (Settings › E-mail; it is on in a fresh install since D-347), once a test e-mail from Settings › Company arrives.

### 12.2 Daily and weekly checks

**Daily** (MD / Admin / Accounts):

- The daily digest arrived. A **BACKUP WARNING** line means act today.
- **Zoho Sync Log** (`/ca/zoho-sync`): no failures. A failed run also e-mails every "Manage CA module" holder once.
- "**Waiting for Zoho: N**" beside Zoho Sync in the menu is not growing (it should drop to 0 after each hourly sync).
- The e-mail approval queue has no stuck items; no `failed` rows in the e-mail log.
- Notifications: overdue balances, freight, LC dates, disputes.

**Weekly:**

- **Settings › Backups**: every job OK (database, documents, off-site when set, test-restore within 35 days).
- Prices are not stale (default 60 days); "Pricing ready?" on products.
- The incentive / commission "Ready to lock" list.
- `storage/logs/error.log` on each server: anything new?
- Free disk space for `storage/` and `BACKUP_DIR`.

**Monthly:** the test-restore result on the 1st; the payout run (commission paid on the last day of the month by default, D-292).

### 12.3 Backup and restore (SOP 32, D-260)

**What and how long:**

| What | When (IST) | Kept |
|---|---|---|
| Whole database (`mysqldump --single-transaction`, gzip, AES-256, `.sha256`) | 02:00 nightly | 10 years; copies older than 120 days thinned to the first of each month |
| Every document (`rsync --link-dest` snapshot: an unchanged file is stored once) | 02:30 nightly | 10 years |
| Full encrypted archive of documents | first night of each month | 10 years |
| Test-restore into a scratch database, then dropped | 04:00 on the 1st | result in Settings › Backups |

Why this design: the owner must keep records 9–10 years, the backup must never touch live data, and must not duplicate files. So the scripts only **read** the live system, refuse a `BACKUP_DIR` inside the storage folder, web root or app, never overwrite a backup, and only ever delete files named like a backup inside `BACKUP_DIR`.

**Set-up (once):**

1. `mkdir -p <BACKUP_DIR> && chmod 700 <BACKUP_DIR>`; `openssl rand -base64 48 > <keyfile> && chmod 600 <keyfile>`. **Keep a copy of the key away from the server** — without it no copy can be opened.
2. In `app/.env`: `BACKUP_DIR`, `BACKUP_KEEP_YEARS=10`, `BACKUP_THIN_AFTER_DAYS=120`, `BACKUP_ENCRYPTION_KEY_FILE`, `BACKUP_OFFSITE_TARGET=` (empty until the owner picks a provider; then an rclone remote, copied with `--immutable`), `BACKUP_RESTORE_TEST_DB` (default `<DB_DATABASE>_restore_test`; on Bluehost create it in cPanel and give the app user rights).
3. `chmod 700 scripts/*.sh`; add the three cron lines.
4. Run `backup_db.sh` and `backup_storage.sh` by hand, then `backup_test_restore.sh`; Settings › Backups must show OK for each.

**Restore** is never automatic and is done by the server administrator on the MD's instruction:

- *One document:* copy the file from `BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/` back to the same path under `STORAGE_BASE_PATH`. Usually unnecessary: every document revision is kept (SUPERSEDED copies open from the order's history).
- *Whole database:* stop the app → pick `BACKUP_DIR/db/nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc` → `sha256sum -c` its `.sha256` → take a fresh backup of the current database first → `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in <file> -pass file:<key> | gunzip | mysql -u <user> -p <db>` → restore documents from the **same night** → start the app and spot-check orders.
- *All documents:* stop the app → `rsync -a <snapshot>/ STORAGE_BASE_PATH/` (or decrypt a monthly archive and `tar -xzf`) → start and check.

### 12.4 Zoho

**How the sync works.** Zoho Books is the CA's official ledger (D-160). The app never sends a record at the moment it is saved; the **hourly sync** (or *Sync now* on `/ca/zoho-sync`, *Sync this order*, *Send to Zoho Books* on a record) sends it (D-233). `CaSyncService::runFullSync()` / `caSyncService.runFullSync()` sends CIs and Debit Notes (export invoices), buyer payments and LC money (applied to the invoice), vendors, bills and vendor payments, expenses and paid costs, and received export benefits (journals); then it imports the expenses the CA books in Zoho. Since D-257 (6) the other divisions sync in the same job (`DivisionZohoSync` / `divisionZohoSync.js`).

Safety rails:

- `ZohoGuard` checks every request. Off → no call at all. `test` → only `TEST-` customers. Production → test and sample orders are skipped; nothing is sent before the go-live date (`zoho_books_sync_start_date`). Government features need production **and** e-invoice both true (D-161, R16).
- One sync at a time (`ZohoSyncLock`, a database lock). Before posting a payment, expense, cost or journal the app looks it up in Books by reference, so a retry never duplicates (D-258 QA4).
- API budget (D-239): `zoho_api_daily_limit` 800, `zoho_api_minute_limit` 30 (Settings › Zoho); the sync stops at once on "too many requests". Usage shows on the sync page.
- Restore check (D-164): at most once every 23 h a scheduled run puts back records deleted or changed in Books; *Sync now* always checks.
- *Check against Zoho Books* (`/orders/{id}/zoho-check` and the division equivalents) reads only and shows match / not sent / different / missing.
- **CAFIN never goes to Zoho** (R10).

**The Zoho change log** (CLAUDE.md, R15). Every change made in Zoho — by anyone, by hand or by script, test or real — gets a row in `docs/zoho/zoho-change-log.csv` (UTF-8 **with BOM**, so Excel opens it cleanly) at the time it is made. Read-only checks get a row too ("Read only - no change"). The columns are listed in CLAUDE.md (Log ID Z-001…, IST date/time, done by, on whose instruction, how, app, organisation, where, action, what exactly, before, after, why, test or real, must be removed before go-live, removed on/by, related app change, owner approval, notes). On the live site, the records the app creates itself are logged in the app's own Zoho Sync Log instead. Changing the app's own Zoho settings is an app change, not a Zoho change, and needs no row.

**Removing the TEST- records before go-live.** On 6 Nov 2026, 48 rows were marked "must be removed before go-live" with no removal date (Z-629–Z-641, Z-648–Z-654, Z-656–Z-657, Z-659–Z-661, Z-663, Z-674–Z-688, Z-691–Z-693, Z-695–Z-698), all in the **test** organisation. After the owner has looked at them:

```bash
php app/cron/zoho_cleanup_test_records.php                     # dry run: lists only
php app/cron/zoho_cleanup_test_records.php --apply --by "Admin" --log ../docs/zoho/zoho-change-log.csv
node src/jobs/zohoCleanupTestRecords.js --apply --by "Admin" --log ../docs/zoho/zoho-change-log.csv
```

`--from-sync-log` also removes everything this database sent (refused when `ZOHO_ENVIRONMENT=production`). The script does not yet know the new-division Zoho IDs (open item ZOH-17), so check those records by hand. Fill "removed on / by" for every row. The live organisation starts clean.

### 12.5 Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Signatures / seals blank on every PDF | `__STORAGE_BASE_PATH__` not replaced in `user_signature_assets` | Run the three `UPDATE`s (§12.1) |
| Em-dashes and ₹ show as garbage | `seed.sql` / a migration loaded without `SET NAMES utf8mb4` | Reload; never remove that line |
| PHP local: a URL gives 404 | `php -S` started without `_dev_router.php` | `php -S 127.0.0.1:8000 _dev_router.php` |
| Node: "Document generation failed" | `PUPPETEER_EXECUTABLE_PATH` wrong | Point it at Chromium (`which chromium`); details in `storage/logs/error.log` |
| No e-mails go out | Testing mode on or the e-mail's switch off (Settings › E-mail, D-280 — both off / on in a fresh install), SMTP blank, or the send waits in the approval queue (D-123) | Settings › E-mail: Testing mode and the switches; check `SMTP_*`; approve the queue; check the dispatch cron |
| E-mails go to the wrong address | Mail redirect or Test Mode is on | Switch off redirect / Test Mode. Security mail (2FA, resets, associate and buyer codes) is never redirected (MAIL-13) |
| Records not in Zoho | Hourly sync not installed; before the go-live date; Zoho off; a TEST/sample order in production | Install the cron; set `zoho_books_sync_start_date`; read the Sync Log row |
| "Another sync is running" | `ZohoSyncLock` | Wait for the running sync; one Sync Log row records the stop |
| Sync stops with "too many requests" | Zoho API limit or the app's own budget | Wait; check usage on the sync page; review D-239 limits |
| A buyer in two currencies is stopped by the sync | A Books customer has one currency | Ask the CA (open point O-15) |
| Founder account cannot be edited | `trg_users_protected_bu` (by design) | Use the real-values SQL block |
| A staff member can look but not save | Their division is not active, or Test Mode freezes that screen (D-179) | Move the user to an active division; leave Test Mode |
| Node jobs run twice | Both cron and `RUN_JOBS_IN_PROCESS=true` | Pick one |
| Alert / digest times are wrong | Cron runs in UTC | `CRON_TZ=Asia/Kolkata` or convert the times |
| Users see old JS after a deploy | — | Should not happen: `app.js`/`app.css` carry a version stamp (D-189); hard-refresh if a proxy cached it |
| Settings › Backups says "Not set up" | `BACKUP_DIR` empty, or the folder is inside storage / web root | Fix `app/.env`; the script refuses unsafe folders |
| Locked out after failed logins | Account lockout | An admin resets; check `login_attempts` |

### 12.6 Logs

| Log | Where | What is in it |
|---|---|---|
| Application error log | `storage/logs/error.log` on **both** stacks (PHP `app/bootstrap.php`; Node `src/helpers/logger.js`) | Uncaught exceptions, fatal errors, DB connection failures, e-mail attempts on PHP. Not shown on screen in production. |
| Node process output | `pm2 logs nexacrest` (or the file you redirect to) | Startup lines, `console.error` from e-mail on Node |
| Cron / job logs | wherever the cron line redirects: e.g. `~/logs/zoho_sync.log` (Bluehost) or `/var/log/nexacrest/*.log` (VPS) | One summary line per run |
| Backup logs | `backup_db.log`, `backup_storage.log`, `backup_test_restore.log` | Each run; Settings › Backups shows the last result |
| Audit log | in the app: `/audit-log` and each order's audit log (`audit_log` table) | Every core action, denial (`PERMISSION_DENIED`), override, unlock, download of CAFIN. Amounts masked for people without the money permission (D-229) |
| Zoho Sync Log | `/ca/zoho-sync` (`zoho_sync_log`) | Every sync part, success or failure, skipped parts |
| E-mail log | the e-mail approval pages (`email_log`) | Queued, approved, sent, failed |
| Zoho change log | `docs/zoho/zoho-change-log.csv` (repository) | Changes made in Zoho by people or scripts (§12.4) |

---

## 13. Decision history

The full register is `docs/decisions/owner-decisions-register.md` (about 260 decisions, last updated 6 Nov 2026). It has: §2 standing rules R1–R26; §3 decisions by area (D-001…D-263); §4 an index of every test finding (QA rounds 1–6 and the 5–6 Nov programme); §5 open points (O-1…O-23); §6 sources and caveats. **How to use it:** check every new finding there first; if a decision covers it, apply it and cite the row ("per D-041"); never re-ask a decided point (R18 — the owner's words: "STICK TO THE DECISIONS WHICH WE HAD ALREADY TAKEN"); record each new decision the same day as a new D-row (numbers are never reused).

### 13.1 The big "whys"

| Design choice | Why | Codes |
|---|---|---|
| **The order is standalone.** Client, supplier, incoterm, currency, ports, preset and every amount are copied onto the order when used. Once the first PI is approved, T&C clauses, tolerances, company details, the MD-approval flag and the pricing rate are frozen onto it too (`orders.frozen_snapshot_json`). | A shipped order and its documents must never change because a master record was edited later. Documents re-rendered years later must print what was agreed. | R25, D-186, D-072, D-105, D-262 |
| **Two stacks (PHP and Node) in strict parity.** Same schema and seed, same routes (~744), same behaviour, mirrored tests. | PHP runs on today's Bluehost shared hosting with no build step; Node is VPS-ready, so the company can move host at any time without redevelopment. Both can even share one database. | R1, D-057 |
| **CAFIN never reaches the client.** CAFIN (the internal financial annexure for the CA) is blocked from the portal even by ID, refused by e-mail compose, checked on every download, never sent to Zoho, and needs `view_cafin`. | It holds cost, margin and profit. A single leak would damage supplier and buyer relationships. | R10, D-106–D-115, D-229 |
| **No money leak anywhere.** Every amount belongs to a money class (selling prices, buyer payments, purchase prices, logistics costs…); a person sees only the classes they hold — on pages, files, ZIPs, audit logs, CSVs, reports. | Sales staff must not see costs; logistics must not see selling prices; associates see no prices by default. | D-214 phase 2, D-229, D-261 |
| **Every money record has a correct description.** Built from the facts (what, which order and party, what it settles, bank reference); staff notes only add; money forms refuse to save without the bank reference. Same text in Zoho. | The CA and auditors must understand every entry years later without asking. | D-212 (hard rule) |
| **Divisions hidden until two are active.** Export is the only active division; DOM, IMP and MTT are built but `planned`. The switcher, report Division filter and user Division field appear only once two divisions are active. | Prepare for new businesses without changing anything for today's Export users. | D-257 (0A), D-257 (5) |
| **Stages pass only on approved documents, in order.** "One door at a time." | Export compliance and payment safety: no shipment before money and paperwork are right. | D-001–D-027 |
| **Maker-checker.** A different person approves what someone made; recording and clearing a payment are different people. Self-approval only where Users & roles › Self-approval rules allow. | Fraud and error control. | D-117, D-076, D-221 |
| **Zoho is optional and fenced off.** With Zoho off the app works exactly the same; nothing from testing reaches GST/government portals. | Books is the CA's ledger, but the business must never stop because of an integration. | R16, D-161, D-163 |
| **Mail only when someone must act.** No audit-log e-mails; mail sending off by default; two-level send approval on by default. | One order once produced about 90 e-mails. | D-135, D-155, D-123 |
| **Mockups first, documents double-confirmed.** | The owner wants to see before building; client-facing documents are legal papers and are correct as they are. | R6, R7, R8 |
| **First install = `schema.sql` + `seed.sql`; after go-live every change is a migration.** | A clean start with no history to replay; a safe path for the live database later. | D-185, D-241 |
| **Backups for 10 years, read-only on live.** | Statutory record keeping (9–10 years). | D-260 |

### 13.2 Decisions by theme (condensed)

**Process and stage gates (D-001–D-027).** A stage passes only when its document is approved (mail off) or sent (mail on) (D-001), only on an active order at a reached stage (D-002). Steps and sub-steps unlock strictly in order (D-003, D-004); a skip always needs a reason (R26). Step 4 Buyer PO: generate → link → signed copy required (D-008). Compliance runs in two phases (D-014). Close Order needs BLI and COOPREP approved or sent (D-017). FOB skips the freight stage (D-021, D-048). The payment preset cannot be changed on Edit Order (D-022). Regenerating an earlier document flags later ones "DATA MAY BE STALE" but cancels nothing (D-027, D-216 C-1).

**Order page UI (D-028–D-045).** 19 numbered steps in five colour-coded sections; At a Glance strip; one save button per section; Order Financials as one card with five tabs (D-033); the Kanban/order-page redesign "Concept C" kept (D-045). Standard and Kanban stay parallel (hard rule, 1 Nov).

**Documents (D-046–D-071, D-223, D-224, D-230, D-257 (1)).** No checkboxes on documents; fixed wording and layout; reference and watermark on every page; PI "ADVANCE PAYABLE NOW" box; LC orders never show "0%"; CI balance clause follows the order's own terms (D-054); PHP and Node documents have the same page counts (D-057); internal documents never go to the buyer (D-068). Documents follow the product category, but Natural Stone prints exactly as before (D-257 (1)).

**Payments and terms (D-072–D-084, D-205, D-227, D-243, D-244).** Five protected presets (D-073); risky terms need approval by a holder of `approve_payment_terms` before the Quotation (D-075; wording names the holding roles, D-269); recording and clearing by different people (D-076); amount and rate recorded together; buyer money arrives in INR in one current account (D-084); remittances record the INR the bank credited and the rate is worked out (D-244).

**Suppliers, logistics, bills (D-085–D-094, D-232).** Bill first, then pay (D-089); proforma vs tax invoice "Option B" (D-091); GST always a % from a drop-down (D-092); Logistics enters logistics bills, Accounts pays (D-232).

**Financials and benefits (D-095–D-105, D-213, D-225, D-234).** All finances order by order (D-101); benefits claimed and received at Step 13g (D-099); profit includes benefits and the CFR/CIF freight leg (D-104); GST refunds under LUT (D-213).

**CA module and CAFIN (D-106–D-115, D-236).** See 13.1. The CA role is read-only and sees every order (D-114).

**Roles, access, organisation (D-116–D-124, D-214–D-216, D-221, D-226, D-261, D-263).** Role + permission (Owner Decision #3); order visibility by assignment, `view_all_orders` for management (D-118); departments → designations → roles → permissions with Log in as, Preview as, My tasks and the cockpit (D-214); nobody grants what they do not hold (D-216); self-approval rules in Admin (D-221); associate portal access levels Starter / Quoting / Full / Custom (D-261); an associate never sees a count or total of orders or business, a level name or the ladder threshold, and sees commission for a set period (D-263).

**Client portal and links (D-125–D-134, D-206, D-242, D-246, D-250).** One step at a time on the Buyer Action Link; one e-mail per client (D-129); feedback only after shipping documents; a buyer never sees an error caused by an associate code (D-246).

**E-mail (D-135–D-142).** See 13.1. Bluehost SMTP; Zoho Mail is not used to send (D-139).

**Forms (D-143–D-148, D-207, D-220, D-247).** Browser validation on every form (R12); typed values kept after an error (D-144); server enforces the same rules (D-145); fixed lists are drop-downs (D-147); placeholders everywhere (D-220); "Not saved" pop-up (D-247).

**Files (D-149–D-154).** Order files stored by step folder (`01-quotation` … `13-closure`), `generated/` and `received/`, random names on disk; the server checks the real file type (D-151).

**Zoho Books (D-160–D-170, D-233, D-238, D-239, D-257 (6)).** See §12.4.

**Incentives and associates (D-171–D-176, D-208, D-217, D-235, D-245, D-251–D-256, D-259).** Team incentive program with on/off and minimum profit (D-171); associate system with its own portal and KYC (D-217, D-252); TDS worked out by the app (D-235); pricing build-up with margin, staff floor, associate floor and buffer — nobody sells below the minimum price (D-245); eligibility and caps (D-259).

**References (D-177, D-178, D-248).** Two-digit year; atomic sequences; order reference `NC/SC/ORD/{YY}/{DDMM}{NNN}` (D-248).

**Test Mode and security (D-179–D-183, D-209).** Test Mode freezes decision screens and never mails a real address (Owner Decision #4); login and session security (D-180); admin-set passwords shown once (D-181); "not found" changes nothing (D-209); deliberately not built (D-183).

**Data and deployment (D-184–D-191, D-210, D-241, D-249, D-260).** MySQL-safe, run-twice-safe migrations (D-184); IST everywhere (D-188, R24); simultaneous saves handled (D-210); daily alert job reads once (D-249).

**Testing and process (D-192–D-204, D-211, D-258).** Disposable test databases (D-192); no skips (D-193); every route has a behaviour test (D-211); the full QA programme found 107 untested bugs, fixed in batches QA1–QA4 (D-258); docs kept current with every change (D-198); work order on a QA round: highest priority first, batches pushed only after "push" (D-201).

**Orders, documents and annexures, 12 Nov 2026 (D-336–D-346, D-349, D-352–D-356).** "As per annexure" order lines, Annexure B (as shipped) and the annexure chosen for the CI / PL (D-336, §8.8); reorder lines "As per annexure", a duplicate keeps Annexure A, a worked-out CBM price goes to the MD (D-338); one "Save all changes" on the annexure page, Edit / Regenerate Annexure A, Annexure B from the CI step (D-341); buyer details on orders follow the client — automatic while nothing is issued, by approved request after (D-337); the detailed product table, No. of pieces and prices in other units (D-339, §8.9); prices and products fixed by the first approved Quotation, changed only by a Products & prices amendment (D-342, §8.10); Indian grouping on INR documents, Total CBM from the lines, Word section numbers as the PDF (D-343, §8.11); "Documents to be provided" without "(digitally authorised)" (D-344); every Supplier PO in Indian grouping (D-345); the client billing address in parts only (D-346); the COO line without "TBC" and the MD name / title kept in sync (D-349); no mandatory note or stars, headings kept with their content, the Payment Terms box, a clean Word spacer (D-352–D-356, §8.5).

**Products, suppliers and pricing, 12–13 Nov 2026 (D-340, D-347, D-348, D-357–D-365).** "Rate used for prices right now" box and the order-page rate line (D-340); "Monument (cut to size)" and the installation defaults — Testing mode on, incentive on, other costs 5.5 %, margin 15 % (D-347); Thickness and Size / format always chosen on an order line (D-348); one list of surface-finish names in Settings › Lists, base "Natural Polish" (D-357); Duplicate a product (D-358); supplier offers with an Offer active switch, Edit price and "+ Add product" (D-359, D-360); the configured base price that never changes by itself, Configure with Global / Category / Product values, Link a supplier, Link these prices, quotable-only Pick from Products and the Supplier PO supplier choice (D-361); Global pricing settings and Category price rules reached from the Products link bar (D-362); Products list buttons, the category form, "Also work out" rows and Configure › Thickness rules (D-363); supplier prices per SQFT and the unit names SQM · SQFT · CBM on every screen (D-364); the Products list short view and the "Pricing ready?" rework (D-365). Sitewide buttons: a pressed button's choice always reaches the server, and a question written on a button is asked (D-350, D-351).

### 13.3 Standing rules in one list

R1 parity · R2 verify before push · R3 ask before push · R4 nine-part review pack · R5 one branch (`claude/app-creation-review-lxxexc`, to be renamed `main`) · R6 documents need double confirmation · R7 mockups first · R8 discuss before writing business-sensitive changes · R9 recommendations point by point · R10 CAFIN never to the client · R11 supplier and buyer never cross · R12 browser validation · R13 MySQL-safe SQL · R14 SOP copied to both stacks · R15 Zoho change log · R16 Zoho never affects core · R17 secrets never in chat or git · R18 never re-ask · R19 QA round method · R20 describe changes by what they do · R21 owner material stays on owner hosting · R22 no skipped tests · R23 everyone sees only what they can use · R24 IST everywhere · R25 the order is self-contained · R26 reasons ≥ 10 characters, skips need a reason.

### 13.4 Owner decisions of 6 Nov 2026 recorded here

These two decisions were given while this handbook was written. They are not yet a numbered row in the register (§14.5).

| Decision | What it means in the app | Where |
|---|---|---|
| **Associates may see their commission, never the total business they bring in.** | The associate portal shows commission per order and the commission totals (earned, waiting, paid this year). It never shows a lifetime or period order-value summary (total FOB, total sales, "your business with us") on any page, statement, notice or e-mail. Checked 6 Nov 2026: `views/associate_portal/home.php` / `commission.php` show commission figures only; keep it that way. | §4.11, §6.4 |
| **Per-order FOB visibility is governed by the Access-levels matrix only.** | The FOB value of one order shows to an associate only when their level (or, for Custom, their own tick) has `fob_value` on (`AssociateAccess::allows()`, stripped server-side otherwise). No other setting or screen may add or remove it. | §4.11 |

---

## 14. Known limits and open items

### 14.1 Deferred items (decided to wait)

| Item | What is missing | Why it waits | Source |
|---|---|---|---|
| **O-22** | The QA programme items below | Priority call by the owner; ZOH-23 needs the CA | Register §5.1 |
| ZOH-4 | A Books payment edited after it was applied | Rare; needs a design for re-applying | D-258 |
| ZOH-10 | Re-send when a new-division record changes after sync | Divisions not active yet | D-258 |
| ZOH-13 | Testing against a Zoho organisation without GST registration | Needs such an organisation | D-258 |
| ZOH-17 | Clean-up script does not know the new-division Zoho IDs | Check those TEST- records by hand | §12.4 |
| ZOH-21 | API call counter fully safe under parallel syncs | Mitigated by the one-sync lock | D-258 |
| ZOH-23 | Stock-issue COGS journal for Import & Distribution | Needs the CA's account names | D-258 |
| PAR-4 | Password-hash format differs across stacks (`$2y$` vs `$2b$`) | Each stack verifies its own; matters only if one database is shared | D-258 |
| PAR-7 | Test coverage differs between stacks: a few test files exist on one side only (Node `buyerPoActionLink`, `govtExportBenefitsGate`, `smtpEncryptionSetting`; PHP `ReasonValidator`, `DeadlockRetryTest`, the migration-convention tests), and the division Zoho pushes, the restore check with bank charges, milestone rows in the match check and retry double-post protection have no fake-transport test on either stack | Partly covered by the QA batch 4 tests; the rest waits for a priority call | D-258, master plan G14 |
| MAIL-10c | SMTP login difference between stacks | — | D-258 |
| Associate order-documents page | The "Full" access level includes order documents, but there is no associate-portal page for them yet | Deferred with D-261 | O-22 |
| A7 generated agreements | Agreements generated by the app | Waits for the lawyer's wording | D-257 (5)(d) |
| **O-23** | Activating Domestic Trade, Import & Distribution or Merchant Trade | CA, AD bank and lawyer must confirm the starting values | §14.3 |
| D-237 | Workflow templates (samples / air shipment), domestic GST sales engine beyond DOM, service-only orders | Owner: "do not ask for a couple of months" (not before Jan 2027) | D-237 |

### 14.2 What is NOT built

- **SMS 2FA** — `SmsService` exists but no provider is wired in; email 2FA only (D-183).
- **Excel (.xlsx) reports** — CSV only (D-183).
- **Scheduled e-mailing of saved reports** — saved reports run on demand only (D-183).
- **Generic amendments** — only the payment-term fields of the spec's worked example can be amended (advance %, balance trigger and days, balance amount); not incoterm or currency (D-183).
- **E-way bill** — disabled; **GST e-invoice** — blocked unless production and `ZOHO_EINVOICE_ENABLED` are both true (D-161).
- **Zoho CRM** (O-21, not started) and **Zoho Sign** (O-3, paused by the owner). Zoho Mail code is kept but switched off in code (D-139).
- **Off-site backup** — the provider is not chosen; copies stay on the server until `BACKUP_OFFSITE_TARGET` is set (D-260).
- **Kanban boards** exist on 12 list pages; `/import` and `/merchant` are not yet in the parity test, and other lists (clients, products, suppliers, users, CA lists, incentives, domestic) have no board — the owner has not yet said whether they must (master plan G12).
- **CFR golden documents** — only FOB and CIF stone documents are captured; adding CFR fixtures needs owner double confirmation (G8).
- **Responsive leftovers** — scroll hints on tables, tap targets on text links (O-6).

### 14.3 Pending answers from the CA, the bank and the lawyer (`docs/external/`)

Prepared 6 Nov 2026. Each answer becomes a settings / SOP / checklist update in one small change.

| Pack | Needed before | Questions |
|---|---|---|
| `CA_questions.md` | **Export go-live:** questions 1–6 | GST on commission to an associate abroad (reverse charge?) and in India (18%?); TDS 194H 2% with ₹20,000 threshold (20% without PAN); section 195 / DTAA for associates abroad; Form 15CA/15CB; Zoho tax codes for commission bills. **Until answered, do not pay any associate abroad.** |
| | Domestic Trade | 18% GST for HSN 6802 and other HSNs; e-way bill limit ₹50,000; e-invoicing applicability; invoice format; 30-day terms |
| | Import & Distribution | Basic duty 20%, SWS 10%, IGST 18%; assessable value; IGST credit timing; landed cost basis |
| | divisions | Zoho account names (Imported Goods Stock, IGST Paid on Import, Merchanting Trade Sales / Purchases) |
| | Merchant Trade | GST on merchanting |
| `AD_bank_questions.md` | Merchant Trade / Import | 9-month whole-deal limit; 4-month outlay limit; advance above USD 500,000 needs LC/BG?; documents per deal; bank approval; switch BL; reporting; import LCs; documents for payments abroad |
| | current Export business | FIRC per remittance vs eBRC on the DGFT portal |
| `Lawyer_questions.md` | **Export go-live:** questions 1–3 | Associate agreement redraft; liquidated damages abroad; Sales Manager Schedule A (see `docs/legal/D-245-lawyer-notes.md`); associate access levels |
| | first non-stone sale | Review the general / engineering T&C clauses (Product Characteristics, Specification & Drawings, Quantity Tolerance (Goods), Quality Documents, Conformity to Specification, Traceability & Test Certificates); missing clauses for engineering goods |
| | new divisions | Domestic and Merchant Trade terms |

Other open points needing outside answers: O-11 (workflow specification §13 questions: travel rule, Leg-2 Debit Note timing, "Client Closed", ECGC/LC/eBRC checks), O-15 (a buyer in two currencies in Books), O-19 (associate agreement and internal margin reference — lawyer).

### 14.4 Placeholders to replace at go-live

| Placeholder | Where | Replace with |
|---|---|---|
| md@email.com, ed@email.com, "Managing Director" | `users` (protected founder accounts), `company_settings.md_name`, `director_name`, asset names | Real names / e-mails via the real-values SQL (§12.1) |
| info@email.com, +91-0000000000, "Registered office address, City – 000000, India" | `company_settings` `email`, `phone`, `registered_office` | Real company values |
| Corporate office, GSTIN, IEC/PAN, bank, LUT and RCMC placeholders (D-313) | `company_settings` | The company's real values — Settings › Setup checklist lists every one still Needed |
| Password `ChangeMe#2026` (both seeded Super Admins) | seed | Forced change at first login; deactivate the second if not needed |
| `__STORAGE_BASE_PATH__` | `assets`, `user_signature_assets`, `reference_library_documents` | The absolute storage path |
| LUT expiry and RCMC valid-until 31 Mar 2027 | `company_settings` | Confirm |
| ECGC policy number / valid-to | empty | Fill only if there is a policy |
| `pricing_fx_rate` 82.00 | Products › Global pricing settings | Used only in Manual mode and until the first daily fetch (D-301) — press **Fetch now** on go-live day |
| Other costs to FOB 2.5% | Products › Pricing | Real figure from the first 3–5 shipments |
| Logo, MD signature, seal, watermark | `/company-assets` | Real images (placeholders are labelled graphics) |
| `APP_ENCRYPTION_KEY`, `SESSION_SECRET_KEY` | `.env` | Fresh random values |
| `zoho_books_sync_start_date` | Settings | The go-live date (nothing is sent in production until set) |
| `BACKUP_OFFSITE_TARGET` | `.env` | When the owner picks a provider |
| Division settings "to be confirmed" | Settings › Divisions | CA / bank answers before activating a division |
| General / engineering T&C wording | Settings › Admin overrides | Lawyer's wording before the first non-stone sale |
| Dev-only credentials (e.g. the e2e default DB password) | `e2e/`, dev `.env` | Never used on live |

### 14.5 Open questions

Every "(verify)" item from the writing of this handbook was checked against the code on 6 Nov 2026. What the code does is stated in the relevant section. These points remain open: they need an outside answer or a fix in another document.

| # | Question or doc fix | Owner of the answer | Where in this handbook |
|---|---|---|---|
| 1 | **Section 195 / DTAA for associates abroad.** The code has no section-195 rule; with `associate_tds_foreign` on it applies the 194H logic. Is that right, and at what rate? Until answered, do not pay any associate abroad. | CA (`docs/external/CA_questions.md` Q4) | §7.3 |
| 2 | **SOP 27 per-CBM example** (USD 904.56 / 878.21) is arithmetically correct but does not name its inputs (associate Level 2, other costs 0 %, Standard · 80 mm · Leather, no quantity option). Add them to `docs/SOP/27-pricing.md` (all 3 copies). | Doc fix | §7.1 |
| 3 | **D-183 is out of date on user editing.** `/users/{id}/edit` and `/users/{id}/update` exist on both stacks (name, e-mail, role; protected founder accounts refused). Update the register row. | Register fix | §6.17 |
| 4 | ~~**Seed description of `fdn_overdue_days_c`** still says "working days"~~ — done 12 Nov 2026 (D-332): the description says calendar days, in both `seed.sql` files and in `docs/migrations/2026-11-12a-settings-plain-labels.sql`. | Doc / seed fix | §9.1 |
| 5 | **QA route inventory.** `e2e/support/qa-inventory.json` is the 5 Nov 2026 snapshot (735 routes); the stacks now have ~744. The generator script is not in the repository. Commit a generator or regenerate the file by hand when routes change. | Developer | §11.7 |
| 6 | **The two owner decisions of 6 Nov 2026** (§13.4) need a numbered D-row in `docs/decisions/owner-decisions-register.md`. | Register fix | §13.4 |

---

## 15. Scope for enhancement

Every recipe below follows the change checklist in §16: both stacks, both templates, tests first, docs, review pack, ask before push. After go-live, every schema or seed change ships as **a new migration file plus the same change in both stacks' `schema.sql` / `seed.sql`** (`docs/migrations/README.md`).

### 15.1 Activate (or add) a division

**Activate a planned one (DOM, IMP or MTT)** — mostly configuration (SOP 28):

1. Get the CA / bank / lawyer answers for that division (§14.3) and put them into the division's settings.
2. Zoho: create and confirm the needed accounts in the live Books organisation.
3. Users & roles › Departments: set the Division of the departments that will work in it. Users & roles › Users: set the Division of its staff (company-level people stay "Company level").
4. Settings › Divisions › (division): tick its modules; add its own settings — at least `order_ref_format` and every value that differs from Export.
5. Work through **Before activating** (departments, staff, number format, modules, settings), tick the confirmation and press **Activate** (MD, `manage_divisions`; audit-logged).
6. From now on the division switcher, "All divisions", the report Division filter, the user/department Division field, the Zoho division tag and the Profit-by-division section appear. Test a full record in that division in Test Mode first.
7. A division with no orders can be set back to planned. Export always stays active.

**Add a brand-new division** (code work, both stacks):

1. Migration + seed: a `divisions` row (code, name, `planned`, sort order); `division_modules` rows for modules that must be off (no row = on); any `division_settings`.
2. If it needs its own flow (like `/domestic`, `/import`, `/merchant`): tables, repositories, service, controller, views (Standard **and** Kanban), routes in `public_html/index.php` and `src/server.js` with permission gates; add the URL patterns to a module in `DivisionService::MODULES` / `divisionService.js`.
3. Scope every query with `DivisionService::sql()` / `condition()` (and the Node equivalents) — lists, reports, dashboard, counts, files, CSVs.
4. Numbering: its own counter; references start with its code unless the format has `{DIV}`.
5. Documents: its own templates folder (as `DOM/`, `IMP/`, `MTT/`) — owner double confirmation.
6. Zoho: extend `DivisionZohoSync` / `divisionZohoSync.js` with D-212 descriptions, duplicate guard, go-live date and a *Check against Zoho Books* page; log every TEST call in the change log.
7. Tests: isolation (404 for other divisions), module switches, the full flow, Zoho payloads (mocked); call `clearDivisionTradeData()` (and add the new tables to it on both stacks).
8. SOP chapter (like 29–31), README, register row.

### 15.2 Add a product category (e.g. Engineering)

1. **Products › Categories** → add the category (needs *Manage product catalogue*). Add its attributes (e.g. Material, Size) — these print as the document columns.
2. **Settings: <category>**: default unit, allowed units, default HS code (from the HS master list), packing type, quality documents (max 10), T&C clause set.
3. Check list values: units, supplier types, package types (Settings › Lists › Dropdown lists).
4. Clause set: if none fits, add clauses in Settings › Admin overrides with the set. **The general and engineering wording is a starting text — the lawyer reviews it before the first sale** (Lawyer_questions §B).
5. Specifications and pricing (SOP 27): options that change the price (per piece / kg / set), CBM rule, supplier prices, "Pricing ready?" all ✓, associate access to the category.
6. Run `StoneDocumentsUnchangedTest` / `stoneDocumentsUnchanged` — Natural Stone must not change.
7. Generate every document for a sample order of the new category in Test Mode and show the owner; any change to how a category prints is a document change (R6).

No code is needed for a category that fits the existing profile (`CategoryDocumentProfile` / `categoryDocumentProfile.js`). If the documents need a new column or section, that is a document-builder change: before/after and double confirmation.

### 15.3 Add a document type

1. **Owner first:** a mockup and before/after; double confirmation (R6). Decide: customer-facing, internal or procurement; reference format; whether it gates a stage; who reviews it; whether a Word copy is needed; may it ever reach the buyer?
2. Migration + seed: a `document_types` row (`code`, `name`, `category`, `ref_format` with `{YY}`, `never_shown_to_buyer`, `revision_enabled`, `min_reviewers_default`), `document_sections`, `tc_clause_documents` links.
3. Templates: PHP `app/templates/<CODE>/<name>.html.twig`; Node `app/templates/<CODE>/<name>.njk`. Only variables, no business literals (ARCHITECTURE §4).
4. Generation: add the code to the template map, title and party labels in `DocumentGenerationService` (both stacks); feed data from `DocumentDataAssembler`; add a Word builder (`Services/Docx/DocxDocumentBuilder.php` / Node `services/docx`) if needed.
5. Storage folder for its step; stage gate in `stageDocumentGate()` if it gates a stage; review / approval rules.
6. E-mail: a `send_<code>` template in the seed if the buyer receives it; if internal, `never_shown_to_buyer = 1` so the portal, compose and payment links refuse it (as CAFIN, SUPPO).
7. Money visibility: `MoneyVisibility::canSeeDocument()` must know which money class it carries.
8. Tests: generation on both stacks, page-count parity (D-057), the mail matrix, the incoterm × preset matrix list of codes, a refusal test if internal, a golden file only after double confirmation.

### 15.4 Add a role or permission

**Permission:**

1. Migration + seed: `permissions` row (`permission_key`, name, description, lowercase category) and `role_permissions` grants for the default roles. Data the app needs goes in the migration **and** `seed.sql`.
2. Gate the routes on both stacks: `PermissionCheck::requires('key')` / `requiresAny([...])` in `public_html/index.php`; the same gate in `src/server.js`.
3. Hide links, buttons and forms from non-holders (R23) via the permitted-links helpers; check object-level rules separately (holding a permission never bypasses the record's state, D-116).
4. If it is a money permission, wire it into `MoneyVisibility` (pages, files, CSVs, audit masking).
5. Tests: `RouteMatrixTest` / `routeMatrix` pick the gate up; add behaviour tests for "with" and "without"; run the roles/permissions matrix e2e. `GrantGuard` already stops people granting what they do not hold.

**Role:** a `roles` row and its grants (migration + seed); place it under a department and designation (Users & roles › Departments) (D-214); add it to `e2e/seed-ui-test-users.sql` and `e2e/tests/support/auth.js` if e2e should cover it; the money matrix picks every seeded role up. Decide money classes and `see_all_divisions` deliberately.

### 15.5 Add a report

1. Query in `ReportRepository` (or a dedicated service) on both stacks. Must pass `ONLY_FULL_GROUP_BY`; scope by division (`DivisionService::sql()`); leave out test and sample orders where totals matter.
2. Controller action in `ReportController` with a route gated by `view_reports` (or a narrower permission); refuse people who may not see the money in it ("You do not have permission to see the amounts in this report.", D-229).
3. View with filters (labels, placeholders, validation); a CSV export via `?format=csv` using `Helpers/Csv` (formula-injection safe).
4. Link it from the Reports page; the shared Division filter appears automatically when two divisions are active.
5. Tests on both stacks: numbers by hand calculation, money refusal, CSV, division scope; add it to the money-matrix screens if it shows amounts.
6. SOP 14 (Reports) and, for the CA, the ca-02 chapter.

### 15.6 Add a Zoho flow

1. Agree the accounting with the CA first (which Books object, which accounts and taxes).
2. Build the payload in a service (e.g. `ZohoInvoiceData`, `CaSyncService`, `DivisionZohoSync`), calling `ZohoBooksService` — every request passes `ZohoGuard::assertRequestAllowed()` and counts against `ZohoApiBudget`.
3. Description from `PaymentDescription` (D-212): what, order, party, what it settles, bank reference. Store the Books ID on the app record.
4. Duplicate guard: look up by reference (or bank reference + amount) before posting; respect the go-live date; skip TEST/sample orders in production.
5. Run inside the hourly sync and *Sync now*, under `ZohoSyncLock`; add a Sync Log row per outcome; include it in the "Waiting for Zoho" count (`ZohoPendingService`) and in *Check against Zoho Books*; consider the restore check (`ZohoRestoreService`).
6. Tests with the fake transport (`setTransport()`), on both stacks: payload, description wording, duplicate guard, off/test/production behaviour.
7. A live test only in the **test** organisation, with `TEST-` names, each call logged in `docs/zoho/zoho-change-log.csv`, and removed before go-live. Teach `zoho_cleanup_test_records` about the new object.
8. SOP ca-03 Zoho sync.

### 15.7 Add a setting

1. Migration + seed: a `company_settings` row (`setting_key`, `setting_value`, `value_type` string/number/boolean/date/json, `category`, `description`, `is_sensitive`, `is_protected`). Pricing values go in `pricing_settings`; incentive values in the incentive settings. The Settings page groups rows by `category` automatically.
2. Validation: `SettingValueValidator` (both stacks) by `value_type`; add a rule there for anything special (never a 500, D-207).
3. Give the field a short worked example beneath it (as SOP 27 does) and a placeholder.
4. Secrets: mask with `SecretSetting`; never in git or chat (R17).
5. If divisions may override it, read it through the division overlay (`DivisionService::overrideFor()`), so a division's own value wins.
6. If it affects a document, it falls under R6; if it must not change after the PI, add it to the order freeze (D-262).
7. Tests: `SettingsValueValidationTest` pattern; behaviour with the value on and off; SOP 15 (Admin settings).

### 15.8 Add a list page (Standard + Kanban)

1. **Mockup first** if it is a new screen (R7).
2. Controller action with filters, search and counts; scope by access and division; money per `MoneyVisibility`.
3. Two views per stack: PHP `app/views/<area>/index.php` **and** `index_kanban.php` (with `_card.php`, `_filters.php`); Node `views/<area>/index.njk` **and** `index_kanban.njk` (`_card.njk`, `_filters.njk`). Choose with `UiTemplateService::showsBoard()` (Kanban user and no `?view=list`).
4. The board must offer exactly what the list offers: every record link, action, filter, count, field, badge and money rule; "Switch to list view" / "Switch to board view"; paging ("Show more").
5. Add the page to `pages()` in `KanbanParityTest.php` and `kanbanParity.test.js`, plus a `<Name>KanbanBoardTest` / `<name>KanbanBoard.test.js`.
6. Menu link only for permitted users (`NavLinksMatchRoutePermissionsTest`); route gated on both stacks.
7. Run the UI crawl on both templates and both screen sizes.

### 15.9 Ideas already discussed with the owner

| Idea | Status | Source |
|---|---|---|
| Workflow templates (sample orders, air-shipment orders) | Deferred to at least Jan 2027 | D-237 |
| Domestic GST sales with e-invoice IRN and e-way bill | Deferred; DOM division built but planned | D-237, D-257 (2) |
| Service-only orders | Deferred | D-237 |
| Zoho CRM integration | Planned after Books, not started | O-21 |
| Zoho Sign | Paused | O-3 |
| Off-site backups | Provider to be chosen; rclone ready | D-260 |
| Generated associate agreements (A7) | Waits for the lawyer | D-257 (5) |
| Associate portal page for order documents (Full level) | Deferred | D-261, O-22 |
| 2FA backup codes | Built (D-265, 7 Nov 2026) for Super Admin and company-settings holders | D-265 |
| Kanban boards on more list pages | Owner to decide which | Master plan G12 |
| Responsive polish (table scroll hints, tap targets) | Priority call pending | O-6 |
| CFR golden documents | Needs double confirmation | Master plan G8 |
| Gap list items G-7 (later), G-8 (leave) | Owner chose later / leave | D-218 |
| Spec-versus-app gap list for the Workflow Specification | Offered, not yet answered | O-11 |
| SMS 2FA, .xlsx export, scheduled report e-mails, generic amendments | Builder scope, not requested | D-183 |
| Rename the branch to `main`; retry the fresh install on MySQL 8; Zoho token without Mail scopes | Owner housekeeping | O-20 |

---

## 16. How to work on this codebase

This is the change checklist. It comes from CLAUDE.md and the standing rules; follow it for every change, however small.

1. **Read the register first** (`docs/decisions/owner-decisions-register.md`). If a decision covers the point, apply it and cite it; never re-ask (R18). Note any open point (§5) you touch.
2. **Mockups first** for a larger UI change (R7). Show images, get confirmation (usually twice) before building.
3. **Double confirmation** before any change that ends up in a generated PDF or Word document — templates, the data assembler, document builders (R6). Show the owner what the document shows now and what it will show after; get "yes" twice. Same care for anything touching CAFIN, money permissions or payment wording (R8, R10). **CAFIN must never reach the client** — check the portal, e-mail, links, token pages, associate portal and Zoho for any new path.
4. **Both stacks** (R1): PHP and Node, same routes, gates, wording and behaviour, in the same change.
5. **Both templates**: every list-page change goes to Standard and Kanban (hard rule, 1 Nov).
6. **Forms**: browser validation through `app.js` `fieldError()` (both stacks), placeholders on every typed input, labels, `*` and "Choose…" on required drop-downs, `*_confirm` / `data-match` on re-enter fields; server checks too (R12, D-220).
7. **Money**: every new money record gets an app-built description and a required bank reference where money moved through a bank, in the app and in Zoho, with exact-wording tests on both stacks (D-212).
8. **Database**: MySQL-safe (no `IF [NOT] EXISTS` on columns or indexes; information_schema + PREPARE), safe to run twice, `SET NAMES utf8mb4;`, passes `ONLY_FULL_GROUP_BY` (R13). Before go-live: edit `schema.sql` / `seed.sql` on both stacks. After go-live: a migration **and** the same change in both schema/seed files.
9. **Tests first**, on both stacks (§11.8). No skips (R22).
10. **The full run under the lock**: PHPUnit, Jest, and e2e against both stacks (R2), each wrapped in `flock` on one lock file so nothing else uses the test or dev databases at the same time. Explain any failure; never push red.
11. **Zoho**: any change made in Zoho during the work gets a change-log row at the time (R15); test records start with `TEST-`.
12. **Docs**: update `docs/SOP` and copy it to `PHP_MySQL_Version/docs/SOP` and `NODE_MySQL_Version/docs/SOP` (R14); both READMEs; ARCHITECTURE; the master test plan if coverage changed; the in-app help if a screen changed; a new D-row in the register the same day for any owner decision (D-198).
13. **The review pack** (R4) — nine parts: (1) what changed, one plain line per fix; (2) before/after screenshots on both stacks for everything visible; (3) "try it yourself" steps: which user/role, which order, what to click, what should happen and what should now be refused; (4) roles affected; (5) test results on both stacks, failures explained; (6) risks and what was deliberately not changed; (7) live-site steps (migrations, in order); (8) files changed, grouped; (9) the decision asked: push / don't push / change first. Describe changes by what they do, not by internal labels (R20). Screenshots are not committed to git, and owner material is not published outside the owner's hosting (R21).
14. **Ask before push** (R3): a short summary — what is pushed, which files change, which functionality is affected — plus the review pack. Push only after the owner says "push", on the one working branch (R5).
15. **CI check**: after the push, confirm both jobs in `.github/workflows/tests.yml` (PHPUnit and Jest on MySQL 8) are green. A red CI after a green local run usually means a MySQL-only difference or strict mode — fix it before anything else.

---

## Appendix A. Index of code locations

PHP paths are under `PHP_MySQL_Version/app/src/` (routes: `PHP_MySQL_Version/public_html/index.php`); Node paths are under `NODE_MySQL_Version/app/src/` (routes: `src/server.js`). Views are `app/views/<area>/` and document templates `app/templates/<CODE>/` on both stacks.

| Module | PHP (main files) | Node (main files) | Handbook |
|---|---|---|---|
| Routing, middleware | `public_html/index.php`, `Helpers/Router.php`, `Middleware/SessionAuth.php`, `PermissionCheck.php`, `CsrfCheck.php`, `OrderLockGuard.php` | `server.js`, `middleware/sessionAuth.js`, `permissionCheck.js`, `csrfCheck.js`, `orderLockGuard.js` | §3.4 |
| Auth, 2FA, passwords | `Controllers/AuthController.php`, `Services/AuthService.php`, `TwoFactorService.php`, `PasswordPolicyService.php` | `controllers/authController.js`, `services/authService.js`, `twoFactorService.js`, `passwordPolicyService.js` | §3.13 |
| Roles, permissions, departments | `PermissionService.php`, `ActiveDepartmentService.php`, `GrantGuard.php`, `MakerCheckerGuard.php`, `SelfApprovalRules.php`, `SuperAdminService.php`; `PermissionAdminController`, `DepartmentAdminController`, `SelfApprovalController`, `SuperAdminController` | `permissionService.js`, `activeDepartmentService.js`, `grantGuard.js`, `makerCheckerGuard.js`, `selfApprovalRules.js`, `superAdminService.js`; matching `*Controller.js` | §4.1–4.6 |
| Order access, money visibility | `OrderAccessService.php`, `MoneyVisibility.php`, `AuditMoneyMask.php` | `orderAccessService.js`, `moneyVisibility.js`, `auditMoneyMask.js` | §4.7 |
| Divisions | `DivisionService.php`, `DivisionController.php`, `DivisionProfitService.php`, `DivisionZohoSync.php` | `divisionService.js`, `divisionController.js`, `divisionProfitService.js`, `divisionZohoSync.js` | §4.8, §6.15 |
| Client portal | `ClientPortalController.php`, `ClientPortalService.php`, `Middleware/ClientAuth.php` | `clientPortalController.js`, `clientPortalService.js`, `middleware/clientAuth.js` | §4.10 |
| Associates, commission, TDS | `AssociateAdminController.php`, `AssociatePortalController.php`, `AssociateRegistrationController.php`, `AssociateAccessController.php`, `AssociateTdsController.php`; `AssociateService.php`, `AssociateAccess.php`, `AssociateCommission.php`, `AssociateTds.php`, `AssociateKyc.php` | `associateAdminController.js`, `associatePortalController.js`, `associateRegistrationController.js`, `associateAccessController.js`, `associateTdsController.js`; `associateService.js`, `associateAccess.js`, `associateCommission.js`, `associateTds.js`, `associateKyc.js` | §4.11, §6.4, §7.3 |
| Clients and intake | `ClientController.php`, `ClientIntakeController.php`, `ClientIntakeReviewController.php`, `PiIntakeController.php`, `PiIntakeReviewController.php`, `AssociateIntakeService.php` | `clientsController.js`, `clientIntakeController.js`, `clientIntakeReviewController.js`, `piIntakeController.js`, `piIntakeReviewController.js`, `associateIntakeService.js` | §5.2, §6.1 |
| Orders, stages, gates | `OrderController.php`, `StageGateService.php`, `ShipmentGateService.php`, `OrderSnapshotService.php`, `OrderEditGuard.php`, `OrderDuplicationService.php`, `ReferenceNumberService.php` | `ordersController.js`, `stageGateService.js`, `shipmentGateService.js`, `orderSnapshotService.js`, `orderEditGuard.js`, `orderDuplicationService.js`, `referenceNumberService.js` | §5 |
| Amendments, disputes, complaints | `AmendmentController.php`, `AmendmentService.php`, `DisputeController.php`, `ComplaintController.php`, `OrderComplaintService.php` | `amendmentController.js`, `amendmentService.js`, `disputeController.js`, `complaintController.js`, `orderComplaintService.js` | §5.9 |
| Products, categories, specifications | `ProductController.php`, `ProductCategoryController.php`, `SpecController.php`, `ProductService.php`, `ProductSpecs.php`, `CategoryDocumentProfile.php` | `productsController.js`, `productCategoryController.js`, `specController.js`, `productService.js`, `productSpecs.js`, `categoryDocumentProfile.js` | §6.2 |
| Pricing, price requests | `PricingController.php`, `PriceRequestController.php`, `PricingEngine.php`, `PricingService.php`, `OrderLinePricing.php`, `PriceRequestService.php` | `pricingController.js`, `priceRequestController.js`, `pricingEngine.js`, `pricingService.js`, `orderLinePricing.js`, `priceRequestService.js` | §6.3, §7.1–7.2 |
| Team incentive | `IncentiveController.php`, `IncentiveReportController.php`, `IncentiveService.php`, `IncentiveReportService.php` | `incentiveController.js`, `incentiveReportController.js`, `incentiveService.js`, `incentiveReportService.js` | §6.5, §7.4 |
| Procurement, suppliers | `PurchasingController.php`, `PurchasingService.php`, `SupplierController.php`, `SupplierActionController.php`, `VendorBillController.php`, `VendorDetailsController.php` | `purchasingController.js`, `purchasingService.js`, `supplierController.js`, `supplierActionController.js`, `vendorBillController.js`, `vendorDetailsController.js` | §6.6 |
| Logistics | `LogisticsPartnerController.php`, `PortController.php`, `PortRouteController.php`, `ShipmentGateService.php`, `CourierCharges.php` | `logisticsPartnerController.js`, `portController.js`, `portRouteController.js`, `shipmentGateService.js`, `courierCharges.js` | §6.7 |
| Documents, review, files | `DocumentGenerationService.php`, `DocumentDataAssembler.php`, `Services/Docx/`, `ReviewWorkflowService.php`, `DocumentController.php`, `ReviewController.php`, `FileStoreController.php`, `FileUploadService.php`, `Helpers/OrderStorage.php` | `documentGenerationService.js`, `documentDataAssembler.js`, `services/docx/`, `pdfRenderService.js`, `reviewWorkflowService.js`, `documentController.js`, `reviewController.js`, `fileStoreController.js`, `fileUploadService.js` | §3.8, §6.8, §8 |
| Payments, remittances | `OrderController.php` (payment actions), `PaymentMilestoneController.php`, `PaymentActionController.php`, `PaymentDescription.php`, `RemittanceInr.php`, `RemittanceDifference.php`, `BalanceDueService.php`, `OrderLedgerService.php` | `ordersController.js`, `paymentMilestoneController.js`, `paymentActionController.js`, `paymentDescription.js`, `remittanceInr.js`, `remittanceDifference.js`, `balanceDueService.js`, `orderLedgerService.js` | §6.9, §7.6, §7.12 |
| CA module, CAFIN, profit | `CaController.php`, `OrderFinancialsController.php`, `GstRefundController.php`, `CaFinancialSnapshotService.php`, `CafinExportService.php`, `CaFyLockGuard.php`, `OrderProfitabilityService.php`; templates `templates/CAFIN/` | `caController.js`, `orderFinancialsController.js`, `gstRefundController.js`, `caFinancialSnapshotService.js`, `cafinExportService.js`, `caFyLockGuard.js`, `orderProfitabilityService.js` | §6.10, §7.5, §7.7 |
| Reports, dashboard | `ReportController.php`, `Repositories/ReportRepository.php`, `DashboardController.php` | `reportController.js`, `repositories/reportRepository.js`, `dashboardController.js` | §6.11 |
| My Tasks, cockpit, digest | `CoordinationController.php`, `OrderAssignmentService.php`, `DailyDigestService.php`, `cron/daily_digest.php` | `coordinationController.js`, `orderAssignmentService.js`, `dailyDigestService.js`, `jobs/dailyDigest.js` | §6.12 |
| E-mail | `MailSenderService.php`, `EmailDispatchService.php`, `MailRedirectService.php`, `EmailTemplateController.php`, `EmailDispatchController.php` | `mailSenderService.js`, `emailDispatchService.js`, `mailRedirectService.js`, `emailTemplateController.js`, `emailDispatchController.js` | §3.10, §6.13 |
| Zoho Books | `ZohoBooksService.php`, `ZohoGuard.php`, `CaSyncService.php`, `ZohoInvoiceData.php`, `ZohoMasterDataSync.php`, `ZohoPendingService.php`, `ZohoRestoreService.php`, `ZohoSyncLock.php`, `ZohoApiBudget.php`, `OrderZohoMatchService.php` | camelCase twins (`zohoBooksService.js` …) | §3.12, §6.14, §12.4 |
| Domestic Trade | `DomesticOrderController.php`, `DomesticOrderService.php`, `DomesticDocumentService.php`, `GstCalculator.php`, `GstRateController.php` | `domesticOrderController.js`, `domesticOrderService.js`, `domesticDocumentService.js`, `gstCalculator.js`, `gstRateController.js` | §6.15, §7.8 |
| Import & Distribution | `ImportController.php`, `ImportService.php`, `ImportDocumentService.php` | `importController.js`, `importService.js`, `importDocumentService.js` | §6.15, §7.9 |
| Merchant Trade | `MerchantTradeController.php`, `MerchantTradeService.php`, `MerchantDocumentService.php` | `merchantTradeController.js`, `merchantTradeService.js`, `merchantDocumentService.js` | §6.15, §7.10 |
| Settings and admin pages | `SettingsController.php`, `UserController.php`, `FieldProtectionController.php`, `AdminOverrideController.php`, `TestModeController.php`, `TestModeService.php`, `UiTemplateService.php` | `settingsController.js`, `userController.js`, `fieldProtectionController.js`, `adminOverrideController.js`, `testModeController.js`, `testModeService.js`, `uiTemplateService.js` | §6.17, §9 |
| Reference Library | `ReferenceDocController.php`, `Repositories/InternalReferenceDocRepository.php`, `ReferenceLibraryRepository.php` | `referenceDocController.js` and twin repositories | §8.1 |
| Sample data, data export | `SampleDataController.php`, `SampleDataService.php`, `DataExportController.php`, `MysqlDumpService.php` | `sampleDataController.js`, `sampleDataService.js`, `dataExportController.js`, `mysqlDumpService.js` | §6.16, §6.18 |
| Backups | `BackupController.php`, `BackupStatusService.php`; `PHP_MySQL_Version/scripts/backup_*.sh` | `backupController.js`, `backupStatusService.js`; `NODE_MySQL_Version/scripts/backup_*.sh` | §3.15, §12.3 |
| Scheduled jobs | `app/cron/*.php` | `src/jobs/*.js`, `jobs/scheduler.js` | §3.9 |
| Browser validation | `public_html/assets/js/app.js` | `app/public/js/app.js` | §3.6 |
| Schema and seed | `PHP_MySQL_Version/docs/schema.sql`, `seed.sql` | `NODE_MySQL_Version/docs/schema.sql`, `seed.sql` | §3.7 |
| Tests | `app/tests/Unit`, `app/tests/Integration`, `Support/DbTestCase.php` | `app/tests/unit`, `app/tests/integration`, `support/fixtures.js` | §11 |
| Browser tests | `e2e/tests/*.spec.js`, `e2e/support/` (shared by both stacks) | same | §11 |

---

## Appendix B. Keeping this handbook current

This handbook is the single, self-contained description of the application and the input for future development. It is only useful while it is true.

**The rule (CLAUDE.md, 6 Nov 2026).** Every change that adds or changes a **feature, rule, setting, table or screen** also updates this handbook **in the same commit**, in all three copies:

- `docs/APPLICATION_HANDBOOK.md` (the master),
- `PHP_MySQL_Version/docs/APPLICATION_HANDBOOK.md`,
- `NODE_MySQL_Version/docs/APPLICATION_HANDBOOK.md`.

Edit the master, then copy it over both stack copies so the three files are identical (`cmp` must report nothing).

**What to update, by kind of change:**

| Change | Update |
|---|---|
| New or changed feature or screen | The module in §6, the order step in §5 if it is part of an order, the template note (Standard **and** Kanban), Appendix A if new files appear |
| New or changed rule (business, money, access) | §4 (access), §5 (order flow), §7 (money), §10 (hard rules) as relevant, and the decision in §13 with its D-code |
| New or changed setting | §9.1 (key, default, where) or §9.2 (`.env`) |
| New or changed table | §3.7.1 (area table) and the module's "Tables" line; keep the table count (194 on 6 Nov 2026) right |
| New role or permission | §4.2 / §4.3 and the counts (24 roles, 94 permissions on 6 Nov 2026) |
| New route | The module's screens line; the route count (~744 on 6 Nov 2026) |
| New document or document change | §8 (after the owner's double confirmation, R6) |
| New Zoho flow | §3.12, §6.14, §12.4 |
| New test suite, matrix or a change in test counts | §11 (PHPUnit 2,433, Jest ~2,500, e2e 68 + 68 on 6 Nov 2026) |
| Something deferred, decided or answered | §13, §14 (move an answered open question out of §14.5) |

**Style.** Plain, short English; tables over prose; file paths for both stacks; say *why* with the D-code. **No PII:** role names and role e-mails only (md@email.com, ed@email.com, admin@email.com, info@email.com, +91-0000000000). Before committing, run the PII check:

```bash
grep -inE "<the owner's list of real names, e-mails, phone and address fragments>" docs/APPLICATION_HANDBOOK.md
```

It must return nothing. Bump the version line on the title page for a larger revision (1.1, 1.2 …) and note the month.
