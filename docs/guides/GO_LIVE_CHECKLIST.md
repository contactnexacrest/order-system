# Go-live checklist (Export division)

Prepared 5 Nov 2026 (Phase 0C.1), updated 13 Nov 2026. Work through it top to bottom on go-live day, and tick each line. The technical install steps are in each stack's `README.md` under "First-time setup". This list is the business view of the same process.

**A site that is already live** (its database already holds data) does not reinstall: it follows section **H. Upgrade of 11 Nov 2026** at the end (it covers the builds of 11, 12 and 13 Nov 2026, migrations `2026-11-11a` … `2026-11-13c`), then checks the settings named there.

Menu paths follow the menus of 11 Nov 2026 (D-320): the left menu has 13 entries and each section has its own menu bar at the top of the page — written *Left-menu entry › menu-bar item*. Since 13 Nov 2026 (D-362) **Global pricing settings** and **Category price rules** are not in any menu: they are buttons in the link bar at the top of the Products page (Categories · Category price rules · Global pricing settings · Fix HS codes · Link these prices (only while some are left) · Go to Suppliers). Unit names on every screen are **SQM**, **SQFT** and **CBM** (D-364).

## A. Before the day (one to two weeks ahead)

- [ ] **CA:** confirm GST on commission for associates **abroad** (reverse charge, 15CA/15CB, TDS section 195). Until confirmed, do not pay any associate abroad. Send `docs/external/CA_questions.md` (questions 1–6).
- [ ] **Lawyer:** the Associate agreement and the manager's Schedule A are final (see `docs/legal/D-245-lawyer-notes.md`). Signed copies are ready for upload. Send `docs/external/Lawyer_questions.md`.
- [ ] **Lawyer, before the first non-stone sale:** review the new general / engineering T&C clauses (Product Characteristics, Specification & Drawings, Quantity Tolerance (Goods), Quality Documents, and the two supplier PO clauses — SOP 27, "Documents follow the category").
- [ ] **Only before activating Domestic Trade, Import & Distribution or Merchant Trade** (not needed for Export go-live): the CA confirms GST rates by HSN, the e-way bill limit, e-invoicing, import duty rates and the Zoho account names; the AD bank confirms the merchanting rules and gives its merchanting approval (SOPs 29, 30, 31; register O-23). Questions: `docs/external/CA_questions.md` (B–E) and `docs/external/AD_bank_questions.md`.
- [ ] **Zoho:**
  - [ ] the live organisation is set up: bank (deposit) account, GST, TDS taxes, chart of accounts;
  - [x] the GST commission-bill test has passed in the **test** organisation, logged in `docs/zoho/zoho-change-log.csv` (done 5 Nov 2026, Z-690 to Z-693);
  - [ ] before activating Import & Distribution or Merchant Trade: the accounts *Imported Goods Stock*, *IGST Paid on Import*, *Merchanting Trade Sales* and *Merchanting Trade Purchases* exist in the live organisation (names confirmed by the CA);
  - [ ] every `TEST-` record has been removed, with each removal logged.
- [ ] **Hosting:** the server can make outbound HTTPS calls to `api.frankfurter.app` and `open.er-api.com` (the daily exchange rate, D-301). Ask the host if outbound traffic is limited.
- [ ] **Data ready:**
  - [ ] the company's real details: corporate office, GSTIN, IEC/PAN, bank name, branch, account number, SWIFT, IFSC, bank address and pincode, LUT number and RCMC number (the seed has placeholders only since 11 Nov 2026, D-313);
  - [ ] suppliers (every supplier that gives product prices must be in the Suppliers directory — D-303). Their products are added from the supplier: **Procurement & suppliers › Suppliers › + Add product** (an existing product, or a new one that starts Inactive — D-360);
  - [ ] logistics partners;
  - [ ] products with **supplier prices**: one typed thickness price is enough (20 mm per SQM, or 50 mm+ per CBM; a price quoted per SQFT is typed as SQFT and saved as the SQM price × 10.7639 — D-364) — the others are worked out by the Category price rules (D-300); CHA + transport per container where known;
  - [ ] each product **configured** (Products › **Configure**: the base price — by default the highest active supplier price — and the three pricing levels Global | Category | This product), then made Active (D-361). Only people with Manage product catalogue configure or activate;
  - [ ] any exact supplier prices (special combinations);
  - [ ] each product's HS code is on the HS Code Master List (D-302);
  - [ ] clients to load;
  - [ ] staff list with department and designation;
  - [ ] associates, with ID proof **and** address proof (D-290);
  - [ ] commission levels;
  - [ ] the state and festival holidays of this year (the national holidays are seeded).
- [ ] **Rehearsal:** do the whole list below once on a test copy of the server, with a sample order taken all the way to closure.

## B. Install (go-live day)

- [ ] Create the database (utf8mb4). Import `docs/schema.sql`, then `docs/seed.sql`. **No migrations** on a new install. The database user needs the **TRIGGER** privilege ("All Privileges" includes it): the schema creates triggers, including the permanent document image store `document_image_blobs` (D-267).
- [ ] Run the three storage-path `UPDATE`s (README, "First-time setup").
- [ ] Fill in `app/.env`:
  - [ ] database;
  - [ ] `STORAGE_BASE_PATH`;
  - [ ] SMTP (Bluehost) — **must work before the first login**: every login asks for a 6-digit code sent by e-mail (D-265). Until mail works the code is only in the server error log;
  - [ ] `APP_ENV=production`;
  - [ ] a fresh `APP_ENCRYPTION_KEY` (Node also needs `SESSION_SECRET_KEY`);
  - [ ] Zoho: `ZOHO_INTEGRATION_ENABLED=true` and `ZOHO_ENVIRONMENT=production` only when the live Zoho organisation is ready;
  - [ ] `ZOHO_EINVOICE_ENABLED=false`.
- [ ] **PHP only:** the folder `storage/sessions/` exists and the web server can write to it (D-283). Without it staff are signed out after about 24 minutes.
- [ ] Point the web root at `public_html/` (PHP), or start the Node service.
- [ ] Set up the scheduled jobs (README: "Bluehost cron setup" or "Background jobs"), in Indian time (`CRON_TZ=Asia/Kolkata`): deferred e-mails, OC auto-confirm, alerts at 06:00, Zoho sync hourly, digest at 08:15, document clean-up at 02:15, **exchange rate at 07:05** (`app/cron/fetch_fx_rate.php` / `src/jobs/fetchFxRate.js`, D-301), backups.
- [ ] Set up the **backups** (README, "Backups"; SOP 32; owner decisions D-260):
  - [ ] create `BACKUP_DIR` outside the web root and outside the storage folder, and the encryption key file;
  - [ ] keep a copy of the key **away from the server** (without it the copies cannot be opened);
  - [ ] add the `BACKUP_…` lines to `app/.env` (keep 10 years; thin after 120 days);
  - [ ] add the three cron lines (database 02:00, documents 02:30, test-restore 04:00 on the 1st);
  - [ ] run the database and documents backups once by hand; **Settings › Backups** shows both as OK;
  - [ ] run `scripts/backup_test_restore.sh` once; Settings › Backups shows the test-restore as OK (the database copy includes `document_image_blobs`, the permanent document images — always restore the whole database);
  - [ ] off-site copy: when the provider is chosen, set `BACKUP_OFFSITE_TARGET`.

## C. First login and company setup

- [ ] Put in the real logins and company details: the seed has role placeholders only (md@email.com, ed@email.com, "Managing Director", info@email.com, phone +91-0000000000, registered office "Registered office address, City – 000000, India"). Run the "Set the real logins and company details" SQL in the README (First-time setup): it lifts the founder-account protection trigger for a moment, sets the real names and e-mails, and puts the trigger back (check with `SHOW TRIGGERS LIKE 'users';`). The company settings can also be changed in Settings › Company; the founder logins only through this SQL. No real name or e-mail is ever written into the repository.
- [ ] Put in the company's real identity, bank, GSTIN, IEC/PAN, LUT, RCMC (and ECGC if any): either on **Settings › Company** (Unlock on the protected rows), or all at once with the "Set the company's real details by SQL" block in the README (First-time setup, right after the founder block). That block also puts the real LUT and bank numbers into the staff checklists in the Reference library, and ends with a check query that must return 0. Install order: schema.sql → seed.sql → storage-path UPDATEs → founder block → company-details block. Not for the 11 Nov upgrade of the live database: it already has its real values. Then generate one draft PI and check the header, the bank section and the LUT line.
- [ ] Log in with the real MD e-mail and the seeded password, set a new password, and enter the 6-digit login code from the e-mail (D-265 — required for every staff member and every buyer; nothing to switch on).
- [ ] **Backup codes:** the MD and the Admin each open My Account › Backup codes, make the 10 one-time codes and keep them **offline** (printed or in a safe). They are the way in if e-mail stops working.
- [ ] **Settings › Setup checklist** (D-312): work down the list until every item is **Done** — it links to each page below:
  - [ ] *Company identity* — name, offices, MD and director names, phone, e-mail;
  - [ ] *Bank details, GSTIN and IEC / PAN* — the seed has placeholders (D-313); enter the real values on **Settings › Company** (Unlock on the protected rows). They print on the Proforma Invoice and other documents;
  - [ ] *LUT and RCMC — numbers and dates* (expiry dates seeded as 31 Mar 2027 — confirm);
  - [ ] *ECGC policy* (optional, empty until filled);
  - [ ] *E-mail server* — then send a test e-mail;
  - [ ] *Zoho Books and Zoho Mail* (optional);
  - [ ] *Backups*;
  - [ ] *This year's holidays* — add the state and festival holidays on Settings › Holidays, then tick;
  - [ ] *The MD's real e-mail* (and the other starting users');
  - [ ] *Session folder storage/sessions is writable* (PHP);
  - [ ] *Settings at the recommended values* — **Apply recommended** sets the ones below in one go;
  - [ ] the three ticks: the MD has checked the global pricing settings (exchange rate and margins) and the commission levels; TDS and export-benefit deadlines confirmed with the CA.
- [ ] **Settings › Security** (D-283): staff signed out after 4 hours idle (240 minutes), buyers and associates after 2 hours (120), staff log in again at least every 12 hours; **"This is my own office computer"** skips the login code for 30 days on that computer (0 = code at every login).
- [ ] **Settings › Company › MD name and title:** if they change later, the app asks whether to apply the new name to the open orders and the Draft / In-review documents (D-349); approved and sent documents never change. The founder logins themselves change only through the README's founder SQL block.
- [ ] **Settings › E-mail** (D-280, D-281):
  - [ ] every MUST e-mail is locked on; check the SHOULD switches are on and the OPTIONAL "copy of buyer documents to the associate" is as you want it (off);
  - [ ] **Testing mode — hold e-mails to buyers and suppliers: OFF** on go-live. A fresh install starts with it **ON** since 12 Nov 2026 (D-347), so buyers and suppliers get no e-mail until it is switched off here;
  - [ ] name the **responsible person and a backup** for each company-level alert (LUT, RCMC, agreements, TDS, merchant-trade RBI limits, ECGC policy, monthly eBRC). Empty = the department's manager, then the MD.
- [ ] **Settings › Document clean-up** (D-313): drafts removed 90 days after approval, never-sent superseded revisions after 365 days, CAFIN latest only — or change them.
- [ ] **Company assets** (Settings › Documents › Assets, `/company-assets`): real logo, MD signature, company seal, watermark.
- [ ] **Products › Global pricing settings** (button at the top of the Products page — D-362; applies to every product unless a category or product changes it):
  - [ ] **exchange rate: Automatic**, cushion **2 %** (pricing rate = today's market rate − 2 %, D-301). Press **Fetch today's rate now** and check the box "Rate used for prices right now" (badge *Automatic · fetched today*) and its source (Frankfurter / European Central Bank, or open.er-api.com) — D-340;
  - [ ] margin 15 %, buffer 3 %, other costs to FOB 5.5 % (the owner's starting values of 12 Nov 2026, D-347 — change them once real shipments are known);
  - [ ] default physical values for every category and product: stone weight 2.70 t per CBM, a container holds 8 CBM / at most 27 t, 50 mm+ per CBM = base price × 40 (D-359);
  - [ ] team incentive 15% of profit;
  - [ ] stale after 60 days;
  - [ ] "goods lines picked from Products" left ON.
- [ ] **Products › Category price rules** (Natural Stone, D-300, D-362; applies to every product in the category unless the product changes it): 30 mm +35 %, 40 mm +70 %, 50 mm+ per CBM = 20 mm price per SQM × 40, a container holds 8 CBM / at most 27 t, stone weight 2.7 t per CBM; margin, other costs and buffer empty = follow Global pricing settings (D-359). Only the MD changes them, with a reason. A product's own thickness % is set on its **Configure › Thickness rules** (D-363).
- [ ] **Products › Categories › Natural Stone:** the line units CBM, SQM, SQFT, PCS, Ton and RMT (D-270, D-364); add Set or Lot only if needed (those lines go to the MD).
- [ ] **Settings › Lists › surface_finish** (D-357): the one list of surface-finish names (Natural Polish, Honed, Flamed, Sandblasted, Leather, Brushed, Rock-Pitched, Bush-Hammered). Rename or switch off a finish here; its price change stays on Products › Pricing › Specifications › Surface finish.
- [ ] **Associates › Commission › Commission settings and ladder:**
  - [ ] paid 30 days after full payment, with claim hold on;
  - [ ] **payout day: last day of the month**; a Sunday or holiday moves to the **working day before** (D-292);
  - [ ] counting order ₹5 lakh, registration 6 months, GST 18%; personal code changes a year; upgrade after N orders;
  - [ ] Zoho GST tax for commission;
  - [ ] TDS settings;
  - [ ] **transfer charges abroad: OUR** (NexaCrest pays all bank charges; the associate receives the full amount) and **minimum payout abroad USD 100** (D-264);
  - [ ] **Zoho account for "Associate payout costs":** first create an expense account with that name in Zoho Books, then choose it here. Until it is chosen, the exchange difference and charges of payments abroad are not sent to Zoho (the Sync Log says so);
  - [ ] **bank details** (D-293): checked by **one person** (who did not enter them; or two, if you prefer); wait before use: first account **0 days**, a change **15 days**.
- [ ] **Commission levels and ladder** (Associates › Commission › Commission levels): check 2 / 5 / 7 / 10% and the ladder steps.
- [ ] **Team incentive roles:** check the split (Sales 30, Sales Manager 10, Export Documentation 15, Export Compliance 5, Marketing 15, Logistics 15, Accounts 10). The incentive program is on or off, as you decide.
- [ ] **Team incentive eligibility and caps** (SOP 20, D-259): mark any designation that is not eligible (Users & roles › Departments), switch off single people if needed (Edit user, MD only), and set the per-role caps per order / per month (Finance & accounts › Team incentive settings). Empty means no cap.
- [ ] **SOP access** (Users & roles › SOP access, SOP 15, D-266): check the chapters each role sees (generous defaults are seeded). MD, ED and Admin always see everything.
- [ ] **Self-approval rules** (Users & roles › Self-approval rules): check them, including the new **Bank details** rule (MD and ED may verify bank details they entered themselves, with a reason — D-293).
- [ ] **Associate access levels** (SOP 24, D-261): check the level matrix (Associates › Access levels). New associates start on Starter: no prices, no FOB value, no commission %.
- [ ] **Options and their price effect** (Natural Stone; Products › Categories › Natural Stone): check the starting values, including Size / format "Monument (cut to size)" at 0 % (D-347). On an order line Thickness and Size / format always start on "Choose…" (D-348). Products' own changes come where needed.
- [ ] **Settings › Divisions:** only Export is active. Leave the others "planned".

## D. People

- [ ] Create staff with department and designation. **One email = one person.** Each department needs a **manager** (designation): staff e-mails about an order go to the people of the relevant department assigned to it, and to the manager when nobody is assigned (D-281).
- [ ] Give **"See all divisions"** only to company-level people: Owner/Admin, MD, ED, CA, company accounts.
- [ ] Check who holds **"See associate commission rules"** (seeded: Admin, MD, ED, Finance Director, Finance Manager — D-292).
- [ ] Create the **Investor (view only)** login, if needed.
- [ ] Check self-approval rules and approval limits.
- [ ] Remove or deactivate the second seeded Super Admin if it isn't needed.

## E. Products and prices

- [ ] For every product: **"Pricing ready?"** shows all ✔ (D-365): configured base price set; at least one active supplier; a 50 mm+ CBM price, or "Not sold at 50 mm+"; specifications; prices current; exact prices. "Open to associates" is only ℹ information while no associate is active. Each ✘ line has a button that opens the form to fix it.
- [ ] **Products** list: every product to be quoted shows **Configured**; the filters *Not configured*, *No active supplier* and *No supplier yet* are empty (D-361). Only quotable products can be picked on an order line.
- [ ] **Products › HS codes › Fix HS codes on products** shows nothing left: every product's HS code is on the master list (D-302).
- [ ] **Products › Link these prices** is gone from the Products page (nothing left to link): every product price is linked to a supplier in the directory (D-303 / D-361). Complete the details of any supplier marked *Details pending*.
- [ ] **"Try a price"** on 2–3 products gives the figures you expect, including a line in PCS with its piece size (Length × Width × Thickness in cm) and a 30 mm line (D-270, D-300).
- [ ] Give associates access only to the categories and forms they should price. Check the daily limit (10) and validity (7 days). An associate also needs the Quoting (or Full) level to see Get price.

## F. Dry run (still on the live server, using test mode)

- [ ] Switch on **test mode** (references get a `TEST-` prefix).
- [ ] Create one order end to end: quotation → PI → advance (enter the **INR credited by the bank**) → supplier PO → freight → BL → commercial invoice → balance → original BLs received and endorsed → closure (Close Order needs both). Generate every document and check the PDFs.
- [ ] Ask for one line price below the staff lowest price: it appears on **Approvals (n)** › Prices for the MD; approve it from there (D-310).
- [ ] After the Quotation is approved, try to change a line: it is refused (prices and products are fixed by the approved Quotation); make the change through **Amendments › Products & prices** instead (D-342).
- [ ] On the Supplier PO terms (Step 7a), pick the supplier from the table of the product's supplier offers; type a different price once to see that it asks for a reason (D-361).
- [ ] At the Commercial Invoice step (11a), choose which annexure prints with the CI and Packing List (Annexure B as shipped / Annexure A as quoted / none — D-336).
- [ ] Check CAFIN, the profit report, and the incentive and commission register: the associate's commission is **fixed automatically** when the balance is cleared (D-264); the team incentive is estimated until the MD locks it.
- [ ] Check a sales staff login: no cost, margin or commission visible; the catalogue shows only Quote and Your lowest (D-305). Check the client portal: no CAFIN, no internal prices.
- [ ] Check that Zoho received exactly what was expected (only if Zoho is switched on). Log it in the Zoho change log.
- [ ] **Switch off test mode.** Delete or archive the test order.

## G. After go-live (first week)

- [ ] Daily: Zoho sync log free of errors; the alert digest arrives; the exchange rate was fetched (Products › Global pricing settings › Exchange Rate (USD → INR) — a change over 2 % waits on Approvals for the MD).
- [ ] Weekly: Products — any product showing "Supplier prices changed: highest is now ₹X" is reviewed by a product manager (**Use ₹X**, or keep the configured base price; it never changes by itself — D-361).
- [ ] Weekly: Settings › Backups shows all jobs OK (the daily digest also warns); prices are not stale; the "Ready to lock" list is reviewed.
- [ ] First payout run (last day of the month): check the "To pay" report; lock the team incentive (associate commissions are already fixed and become due by themselves); pay. For an associate abroad, enter the rupees the bank debited and the charges.
- [ ] Gather the real "other costs to FOB" from the first 3–5 shipments and update the %.

## H. Upgrade of 11 Nov 2026 (a site that is already live)

For a database that already holds data. Owner decisions D-268, D-269, D-270–D-330 (11 Nov 2026) and D-331–D-365 (12–13 Nov 2026). Do it outside working hours, in this order:

1. [ ] **Back up** the database and the `storage/` folder (Settings › Backups, or run the two backup scripts by hand) and keep the copy until the upgrade is checked.
2. [ ] Put the new code in place (both stacks are built the same; PHP: upload the files; Node: replace the app folder and `pm2 restart nexacrest` after step 3).
3. [ ] Run the database files below, **in this order** (each is safe to run twice; MySQL-compatible):
   Before file 19 (`2026-11-13a`), run these two **read-only** checks and keep the output (they change nothing):

   ```sql
   -- 1. Products priced from an INACTIVE supplier. From 13a on such a price is no longer used:
   --    the product shows "The supplier price used for pricing is switched off".
   --    Reactivate the supplier (Procurement & suppliers › Suppliers) or, after the upgrade,
   --    configure the product from an active supplier's price (Products › Configure).
   SELECT p.id, p.name AS product, s.supplier_legal_name AS supplier
     FROM catalog_product_suppliers cps
     JOIN catalog_products p ON p.id = cps.product_id
     JOIN suppliers s        ON s.id = cps.supplier_id
    WHERE cps.is_primary = 1 AND s.is_active = 0
    ORDER BY p.name;

   -- 2. Active categories without their own stone weight or 50 mm+ factor. From 13a on they use
   --    the global defaults (2.70 t per CBM, 50 mm+ per CBM = base price × 40) from
   --    Global pricing settings; type the category's own value on Category price rules if it differs.
   SELECT c.id, c.name, c.weight_t_per_cbm, c.thick_k_factor,
          (SELECT COUNT(*) FROM catalog_products p WHERE p.category_id = c.id) AS products
     FROM product_categories c
    WHERE c.is_active = 1 AND (c.weight_t_per_cbm IS NULL OR c.thick_k_factor IS NULL)
    ORDER BY c.name;
   ```

   1. [ ] `docs/migrations/2026-11-11a-quote-any-unit-piece-size.sql` (D-270–D-273)
   2. [ ] `docs/migrations/2026-11-11b-email-settings-sessions.sql` (D-280–D-283)
   3. [ ] `docs/migrations/2026-11-11c-associates-kyc-commission-bank.sql` (D-290–D-294)
   4. [ ] `docs/migrations/2026-11-11d-thickness-prices-fx-hs-suppliers.sql` (D-300–D-306)
   5. [ ] `docs/migrations/2026-11-11e-approvals-setup-defaults.sql` (D-310–D-313)
   6. [ ] `docs/migrations/2026-11-11f-plain-language-email-templates.sql` (D-330)
   7. [ ] `docs/migrations/2026-11-11g-order-comment-email-status.sql` (D-268)
   8. [ ] `docs/migrations/2026-11-11h-tt-clause-bank-name.sql` (D-331: the T/T definition names the bank from Settings › Company › Bank name; documents print as before)
   9. [ ] `docs/migrations/2026-11-12a-settings-plain-labels.sql` (D-332: plain help sentences under the settings on Settings › Company; keys and values unchanged)
   10. [ ] `docs/migrations/2026-11-12b-supplier-prices-base-and-calculated.sql` (D-335: supplier prices get a BASE for orders and TYPED / CALCULATED marks; "add up the cost parts" prices become typed prices with the same total — no price changes)
   11. [ ] `docs/migrations/2026-11-12c-annexure-b-as-per-annexure.sql` (D-336: order lines "As per annexure", Annexure B (as shipped), the annexure printed with the CI / PL — new columns only, existing orders print as before)
   12. [ ] `docs/migrations/2026-11-12d-buyer-details-refresh-approval.sql` (D-337: requests to update the buyer details of an order with an issued document, permission "Approve buyer details change" for MD and ED, self-approval rule MD and ED — new table and rows only, no order changes)
   13. [ ] `docs/migrations/2026-11-12e-follow-ups-d338.sql` (D-338: reorder request lines can say "As per annexure" — one new column, no data change)
   14. [ ] `docs/migrations/2026-11-12f-buyer-details-self-approval-md-ed.sql` (D-337: the self-approval rule for buyer details changes ticks the Executive Director too — one row, no order data change)
   15. [ ] `docs/migrations/2026-11-12g-detailed-line-table.sql` (D-339: the detailed product table on the QT / PI / OC / CI / Buyer PO / Packing List, No. of pieces and Size note on an order line — every order that already exists is set to the classic table, so its documents print exactly as before; orders created after the upgrade get the detailed table)
   16. [ ] `docs/migrations/2026-11-12h-price-lock-and-products-amendment.sql` (D-342: prices and products fixed by the approved Quotation, the "Products & prices" amendment — every order that already has an approved Quotation is fixed now (its rate: the PI's rate when past the PI, else today's pricing rate) and its lines change only through an amendment from then on)
   17. [ ] `docs/migrations/2026-11-12i-monument-option-and-defaults.sql` (D-347: Size / format option "Monument (cut to size)" at 0 %; and the owner's defaults — e-mail Testing mode ON, team incentive ON, other costs 5.5 %, margin 15 %. Part 2 overwrites current values: skip it if the live values are already right. Switch Testing mode OFF on go-live day.)
   18. [ ] `docs/migrations/2026-11-12j-surface-finish-one-list.sql` (D-357: one list of surface-finish names — Settings › Lists › surface_finish names every finish, Pricing › Specifications keeps the price only; the base finish ("Polished", or "Mirror Finish" as renamed on the live site) becomes "Natural Polish", Natural/Sandblasted → Sandblasted, Leather/Satin → Leather, Brushed added to Settings, Rock-Pitched and Bush-Hammered get options with no price change; prices unchanged; lines of orders whose Quotation is not approved yet are renamed, other orders and issued documents keep the old names. Afterwards check Settings › Lists › surface_finish: any value it did not recognise is kept as it is, active, with "no price change yet".)
   19. [ ] `docs/migrations/2026-11-13a-suppliers-products-configure.sql` (D-359: suppliers, products and the configured base price — a per-offer Active switch on every supplier price (all ON), each product's configured base price filled with the price it is priced from today (it keeps following the supplier price "used for pricing"), the category's own margin / other costs / buffer % (empty = follow global), a "follow global" flag per product, and the global physical defaults 2.70 t/CBM, 27 t, 8 CBM, × 40. No selling price changes. An offer of an inactive supplier is no longer used for pricing: check Suppliers for inactive suppliers whose price is "used for pricing".)
   20. [ ] `docs/migrations/2026-11-13b-products-fixed-base-and-link-legacy.sql` (D-361: every product's base price becomes FIXED at exactly the price it uses today — no selling price changes; when a supplier's price changes later the product shows "Supplier prices changed: highest is now ₹X — Use ₹X" and nothing changes until a manager clicks it. Supplier prices typed before the Suppliers directory are linked automatically where the typed name equals exactly one active supplier's legal name; the rest are listed under Products › "Link these prices (N)". Products that exist now with no linked supplier stay active and quotable with a red "No supplier yet" badge until a supplier is linked. The Supplier PO keeps the supplier offer chosen, its quoted price and a reason when the agreed price differs. The last line of output shows the counts; afterwards open Products › "Link these prices" and link the remaining rows.)
   21. [ ] `docs/migrations/2026-11-13c-permission-descriptions.sql` (text only: the descriptions of `record_market_prices`, `edit_order_post_confirmation` and `manage_compliance_task_types` on Users & roles › Roles and permissions say what they really unlock today; no permission, grant or role changes. The last line of output says "3 of 3".)

   `mysql -u <user> -p <database> < docs/migrations/<file>.sql` for each. The files keep the company's real details, bank, GSTIN, LUT and RCMC as they are.
4. [ ] **PHP:** create `storage/sessions/` and make it writable by the web server.
5. [ ] Add the **exchange-rate job** at 07:05 IST: PHP `5 7 * * * php …/app/cron/fetch_fx_rate.php`; Node `5 7 * * * … node src/jobs/fetchFxRate.js` (or restart the app when `RUN_JOBS_IN_PROCESS=true` — `scheduler.js` runs it).
6. [ ] Check outbound HTTPS to `api.frankfurter.app` and `open.er-api.com`: **Products › Global pricing settings › Exchange Rate (USD → INR) › Fetch today's rate now** shows today's rate and its source in the box "Rate used for prices right now".
7. [ ] Log in (PHP: everyone is signed out once, because sessions now live in `storage/sessions/`) and open **Settings › Setup checklist**; deal with every **Needed** item.
8. [ ] **Settings › E-mail:** check **Testing mode**. It replaced "Mail sending enabled" with the opposite meaning: if mail sending was off, Testing mode is now **on** and buyers and suppliers still get no e-mail. Switch it off when the site should e-mail buyers and suppliers. Name the responsible person and backup for the company-level alerts.
9. [ ] **Settings › Security:** the new idle timeouts (4 h staff, 2 h buyers and associates, 12 h staff limit) replaced the single 30-minute setting; "This is my own office computer" is 30 days unless you had chosen another number.
10. [ ] **Settings › Document clean-up:** the upgrade switched the clean-up on where it was off (drafts 90 days, superseded never-sent 365 days, CAFIN latest only) and password expiry off where it was 90 days. Change them back here if the company wants. The nightly clean-up job starts removing copies under these rules.
11. [ ] **Products › Category price rules** and two or three products: existing supplier prices were turned into thickness rows (the old base price became the 20 mm price per SQM; a per-CBM price became the 50 mm+ price). Check that the 30 mm and 50 mm+ figures are right — a 30 mm price on a product priced per CBM is now worked out from the 20 mm price (D-300).
    - [ ] **Products › Market prices** of two or three Natural Stone products (D-335): each supplier price shows a price card with its **BASE** (◉). A product sold per CBM whose per-CBM price was typed has "50 mm and above" as the base, others 20 mm; change it with "make base" if needed. Older prices still work out missing thicknesses each time until someone adds a price with "+ Add / update a price".
12. [ ] **Products › HS codes › Fix HS codes on products** (once): choose a master-list code for every product listed.
13. [ ] **Products › Link these prices (N)** (once, after migration 13b): pick or quick-add the supplier for each remaining row; complete the details of each supplier marked *Details pending*.
    - [ ] **Products** list (D-361, D-365): every product priced today is **Configured** at exactly the price it used before (no selling price changed). Check the filters *No active supplier* and *No supplier yet*: link a supplier (**Link a supplier**) or switch on an offer (Procurement & suppliers › Suppliers › Offer active) for each product listed. Compare with the output of the two checks run before 13a.
    - [ ] **Settings › E-mail:** migration 12i Part 2 switched **Testing mode ON** (and set team incentive on, other costs 5.5 %, margin 15 %). If the live site already e-mails buyers and suppliers, switch Testing mode **OFF** again; correct the margin and other costs on Global pricing settings if the company had other values.
    - [ ] **Settings › Lists › surface_finish** (D-357): any finish name the merge did not recognise is kept as it is, active, with no price change yet; rename or switch it off here.
14. [ ] **Open orders with Natural Stone lines in BOX, NOS, SET, KG or MT:** such a line is refused on its next edit until the MD adds the unit (Products › Categories › Natural Stone) or the line is changed to CBM, SQM, SQFT, PCS, Ton or RMT. A line in another unit without its piece size shows "Size needed" and holds the QT / PI until the size is given (D-270).
15. [ ] **Associates:** associates already accepted have **60 days** from the upgrade day to add an address proof before their payouts are held (D-290). The payout day moved from the 5th to the **last day of the month** (only where it was still 5). Bank changes that were already waiting for staff count as confirmed by the associate.
16. [ ] **Users & roles:** check who holds "See associate commission rules" and the new "Bank details" self-approval rule.
17. [ ] Tell staff about the new menus (SOP 00, "Finding your way around"), the **Approvals** page and the order action bar; and, from the 12–13 Nov builds: orders with an approved Quotation are fixed (changes only through Amendments › Products & prices, D-342 — migration 12h fixed existing orders); orders that existed before the upgrade keep the classic product table on their documents, new orders get the detailed table (D-339); products are added from Procurement & suppliers › Suppliers › + Add product and configured on Products › Configure (D-360, D-361); the Supplier PO starts from a supplier offer (D-361).
18. [ ] **Tutorial videos:** do not send the recorded videos to new staff before the owner has decided on `tutorials/VIDEO_PROGRESS.md`, "Out of date after the 11 Nov 2026 build" and "Also out of date after the 12 Nov 2026 build".
