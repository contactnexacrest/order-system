# Go-live checklist (Export division)

Prepared 5 Nov 2026 (Phase 0C.1), updated 13 Nov 2026. Work through it top to bottom on go-live day, and tick each line. The technical install steps are in each stack's `README.md` under "First-time setup"; on Bluehost (cPanel) follow **`docs/guides/DEPLOYMENT_GUIDE_BLUEHOST.md`**, the click-by-click server guide (sections 0–12 for a new install, 13 for routine checks, 14 for updates). This list is the business view of the same process.

**A site that is already live** (its database already holds data) does not reinstall: it follows section **H. Updating a live site** at the end.

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
- [ ] Set up the scheduled jobs (README: "Bluehost cron setup" or "Background jobs"), in Indian time: deferred e-mails, OC auto-confirm, alerts at 06:00, Zoho sync, digest at 08:15, document clean-up at 02:15, **exchange rate at 07:05** (`app/cron/fetch_fx_rate.php` / `src/jobs/fetchFxRate.js`, D-301), backups.
  - [ ] **Bluehost:** cPanel has no `CRON_TZ`: first find the server's clock with a one-time `date > <home>/servertime.txt` job (usually **MST**, India = MST + 12 h 30 min), then type the server hours from the deployment guide, section 10 (ten jobs; the test-restore row has **Day = 1**). Delete the one-time job;
  - [ ] create `<home>/logs` before adding the jobs; set the **Cron Email** to an admin address;
  - [ ] if a log says `php: command not found`, use `/usr/local/bin/php`.
- [ ] Set up the **backups** (README, "Backups"; SOP 32; owner decisions D-260):
  - [ ] create `BACKUP_DIR` outside the web root and outside the storage folder, and the encryption key file in **its own folder next to it** (never inside). Both folders **0700**; if they sit inside the hosting account's `public_html`, each also gets an `.htaccess` with `Require all denied`. Key file **0600**, about 65 bytes (Terminal, or a one-time cron job — deployment guide 11.2);
  - [ ] create the **restore-test database** in cPanel › MySQL Databases and give the app's database user **ALL PRIVILEGES** on it; put its full name in `BACKUP_RESTORE_TEST_DB`;
  - [ ] set the four `scripts/*.sh` to **0700**;
  - [ ] keep a copy of the key **away from the server** (without it the copies cannot be opened);
  - [ ] add the `BACKUP_…` lines to `app/.env` (keep 10 years; thin after 120 days);
  - [ ] add the three cron lines (database 02:00, documents 02:30, test-restore 04:00 on the 1st);
  - [ ] run the database and documents backups once by hand; **Settings › Backups** shows both as OK;
  - [ ] run `scripts/backup_test_restore.sh` once; Settings › Backups shows the test-restore as OK (the database copy includes `document_image_blobs`, the permanent document images — always restore the whole database);
  - [ ] off-site copy: when the provider is chosen, set `BACKUP_OFFSITE_TARGET`.
- [ ] **Migration check:** run `docs/checks/check_all_migrations.sql` in phpMyAdmin — on a fresh install every row says **already done**.
- [ ] **Backup folders locked:** if the backup and key folders are inside `public_html`, opening them through any domain gives **403 Forbidden** (or Not Acceptable / a page that never loads for the key file) — never a download.
- [ ] **Web lock (13 Nov 2026):** the project's top-level `.htaccess` (`Require all denied`) and the first line `Require all granted` in `PHP_MySQL_Version/public_html/.htaccess` are both on the server; the login page opens, and `PHP_MySQL_Version/README.md` opened through any other domain of the hosting account answers **403 Forbidden** (see the PHP README, "Web lock").

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
- [ ] **Settings › Security** (D-283): staff signed out after 4 hours idle (240 minutes), buyers and associates after 2 hours (120), staff log in again at least every 12 hours; **"This is my own office computer"** skips the login code for **7 days** on that computer (D-369, 13 Nov 2026; 0 = code at every login).
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

## H. Updating a live site (every new release)

The live site was installed fresh from `schema.sql` + `seed.sql` on 14 Nov 2026 (owner decision D-372), so the 11–13 Nov 2026 upgrade files are gone; git history keeps them. For every later release, outside working hours (deployment guide, section 14):

1. [ ] **Back up:** Settings › Backups shows last night's database and documents backups OK (or run `scripts/backup_db.sh` once with a one-time cron job).
2. [ ] **Code:** cPanel › Git Version Control › Manage › Pull or Deploy › **Update from Remote**. Never edit Git files by hand on the server. Node: replace the app folder and `pm2 restart nexacrest` after step 3.
3. [ ] **Database:** run `docs/checks/check_all_migrations.sql` in phpMyAdmin (read-only). Run every file in `docs/migrations/` whose row says **NEEDED** or **PARTLY done**, in date order; "#1060 Duplicate column name" on a `PLAIN ALTER (owner D-371)` file is harmless. Run the check again until every row says **already done** (or "nothing to run").
4. [ ] **After the update:** `scripts/*.sh` still 0700, `app/.env` still 0600, the web-lock tests still answer 403 / Not Acceptable, the cron jobs match the README; log in and open an order.
5. [ ] **Release notes:** read the README "Follow-up" section of that date for settings to check, and tell staff what changed.
