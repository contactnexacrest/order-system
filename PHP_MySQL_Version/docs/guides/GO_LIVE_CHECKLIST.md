# Go-live checklist (Export division)

Prepared 5 Nov 2026 (Phase 0C.1), updated 6 Nov 2026. Work through it top to bottom on go-live day, and tick each line. The technical install steps are in each stack's `README.md` under "First-time setup". This list is the business view of the same process.

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
- [ ] **Data ready:**
  - [ ] suppliers;
  - [ ] logistics partners;
  - [ ] products with **supplier prices** (per m², plus **per CBM** for anything sold at 50 mm or more);
  - [ ] any exact supplier prices;
  - [ ] clients to load;
  - [ ] staff list with department and designation;
  - [ ] associates;
  - [ ] commission levels.
- [ ] **Rehearsal:** do the whole list below once on a test copy of the server, with a sample order taken all the way to closure.

## B. Install (go-live day)

- [ ] Create the database (utf8mb4). Import `docs/schema.sql`, then `docs/seed.sql`. **No migrations.** The database user needs the **TRIGGER** privilege ("All Privileges" includes it): the schema creates triggers, including the permanent document image store `document_image_blobs` (D-267).
- [ ] Run the three storage-path `UPDATE`s (README, "First-time setup").
- [ ] Fill in `app/.env`:
  - [ ] database;
  - [ ] `STORAGE_BASE_PATH`;
  - [ ] SMTP (Bluehost) — **must work before the first login**: every login asks for a 6-digit code sent by e-mail (D-265). Until mail works the code is only in the server error log;
  - [ ] `APP_ENV=production`;
  - [ ] a fresh `APP_ENCRYPTION_KEY` (Node also needs `SESSION_SECRET_KEY`);
  - [ ] Zoho: `ZOHO_INTEGRATION_ENABLED=true` and `ZOHO_ENVIRONMENT=production` only when the live Zoho organisation is ready;
  - [ ] `ZOHO_EINVOICE_ENABLED=false`.
- [ ] Point the web root at `public_html/` (PHP), or start the Node service.
- [ ] Set up the scheduled jobs (README: "Bluehost cron setup" or "Background jobs"): daily digest and alerts, Zoho sync, backups. Run them in Indian time (`CRON_TZ=Asia/Kolkata`): alerts at 06:00, digest at 08:15.
- [ ] Set up the **backups** (README, "Backups"; SOP 32; owner decisions D-260):
  - [ ] create `BACKUP_DIR` outside the web root and outside the storage folder, and the encryption key file;
  - [ ] keep a copy of the key **away from the server** (without it the copies cannot be opened);
  - [ ] add the `BACKUP_…` lines to `app/.env` (keep 10 years; thin after 120 days);
  - [ ] add the three cron lines (database 02:00, documents 02:30, test-restore 04:00 on the 1st);
  - [ ] run the database and documents backups once by hand; **Admin › Backups** shows both as OK;
  - [ ] run `scripts/backup_test_restore.sh` once; Admin › Backups shows the test-restore as OK (the database copy includes `document_image_blobs`, the permanent document images — always restore the whole database);
  - [ ] off-site copy: when the provider is chosen, set `BACKUP_OFFSITE_TARGET`.

## C. First login and company setup

- [ ] Put in the real logins and company details: the seed has role placeholders only (md@email.com, ed@email.com, "Managing Director", info@email.com, phone +91-0000000000, registered office "Registered office address, City – 000000, India"). Run the "Set the real logins and company details" SQL in the README (First-time setup): it lifts the founder-account protection trigger for a moment, sets the real names and e-mails, and puts the trigger back (check with `SHOW TRIGGERS LIKE 'users';`). The company settings can also be changed in Admin › Company settings; the founder logins only through this SQL. No real name or e-mail is ever written into the repository.
- [ ] Log in with the real MD e-mail and the seeded password, set a new password, and enter the 6-digit login code from the e-mail (D-265 — required for every staff member and every buyer; nothing to switch on).
- [ ] **Backup codes:** the MD and the Admin each open My Account › Backup codes, make the 10 one-time codes and keep them **offline** (printed or in a safe). They are the way in if e-mail stops working.
- [ ] **Admin › Settings › Security › Remember this browser:** leave it at **0 (off)** — the code is asked at every login.
- [ ] **Company settings** (`/settings`): the real company details are already seeded — check each once (README, "Placeholder values you must replace before going live"):
  - [ ] director name, bank details and pincode;
  - [ ] **LUT number, financial year and expiry** (expiry seeded as 31 Mar 2027 — confirm);
  - [ ] **RCMC number and valid-until date** (seeded as 31 Mar 2027 — confirm);
  - [ ] IEC/PAN, SWIFT;
  - [ ] ECGC policy, if any (empty until filled).
- [ ] **Company assets** (`/company-assets`): real logo, MD signature, company seal, watermark.
- [ ] **Admin › Pricing:**
  - [ ] **pricing exchange rate** = today's market rate minus about 1.5%;
  - [ ] margin 20%, buffer 3%, other costs to FOB (2.5% until worked out from real shipments);
  - [ ] team incentive 15% of profit;
  - [ ] stale after 60 days;
  - [ ] "goods lines picked from Products" left ON.
- [ ] **Associates › Commission settings:**
  - [ ] paid 30 days after full payment, with claim hold on;
  - [ ] payout run monthly on the 5th;
  - [ ] counting order ₹5 lakh, registration 6 months, GST 18%;
  - [ ] Zoho GST tax for commission;
  - [ ] TDS settings;
  - [ ] **transfer charges abroad: OUR** (NexaCrest pays all bank charges; the associate receives the full amount) and **minimum payout abroad USD 100** (D-264);
  - [ ] **Zoho account for "Associate payout costs":** first create an expense account with that name in Zoho Books, then choose it here. Until it is chosen, the exchange difference and charges of payments abroad are not sent to Zoho (the Sync Log says so).
- [ ] **Commission levels and ladder:** check 2 / 5 / 7 / 10% and the ladder steps.
- [ ] **Team incentive roles:** check the split (Sales 30, Sales Manager 10, Export Documentation 15, Export Compliance 5, Marketing 15, Logistics 15, Accounts 10). The incentive program is on or off, as you decide.
- [ ] **Team incentive eligibility and caps** (SOP 20, D-259): mark any designation that is not eligible (Admin › Departments), switch off single people if needed (Edit user, MD only), and set the per-role caps per order / per month (Admin › Team Incentives). Empty means no cap.
- [ ] **SOP access** (Admin › SOP access, SOP 15, D-266): check the chapters each role sees (generous defaults are seeded). MD, ED and Admin always see everything.
- [ ] **Associate access levels** (SOP 24, D-261): check the level matrix (Associates › Access levels). New associates start on Starter: no prices, no FOB value, no commission %.
- [ ] **Specifications** (Natural Stone): check the starting values. Products' own changes come where needed.
- [ ] **Admin › Divisions:** only Export is active. Leave the others "planned".

## D. People

- [ ] Create staff with department and designation. **One email = one person.**
- [ ] Give **"See all divisions"** only to company-level people: Owner/Admin, MD, ED, CA, company accounts.
- [ ] Create the **Investor (view only)** login, if needed.
- [ ] Check self-approval rules and approval limits.
- [ ] Remove or deactivate the second seeded Super Admin if it isn't needed.

## E. Products and prices

- [ ] For every product: **"Pricing ready?"** shows all ✓ (supplier price, CBM price if sold at 50 mm or more, specifications, prices current, open to associates).
- [ ] **"Try a price"** on 2–3 products gives the figures you expect.
- [ ] Give associates access only to the categories and forms they should price. Check the daily limit (10) and validity (7 days). An associate also needs the Quoting (or Full) level to see Get price.

## F. Dry run (still on the live server, using test mode)

- [ ] Switch on **test mode** (references get a `TEST-` prefix).
- [ ] Create one order end to end: quotation → PI → advance (enter the **INR credited by the bank**) → supplier PO → freight → BL → commercial invoice → balance → original BLs received and endorsed → closure (Close Order needs both). Generate every document and check the PDFs.
- [ ] Check CAFIN, the profit report, and the incentive and commission register: the associate's commission is **fixed automatically** when the balance is cleared (D-264); the team incentive is estimated until the MD locks it.
- [ ] Check a sales staff login: no cost, margin or commission visible. Check the client portal: no CAFIN, no internal prices.
- [ ] Check that Zoho received exactly what was expected (only if Zoho is switched on). Log it in the Zoho change log.
- [ ] **Switch off test mode.** Delete or archive the test order.

## G. After go-live (first week)

- [ ] Daily: Zoho sync log free of errors; the alert digest arrives.
- [ ] Weekly: Admin › Backups shows all jobs OK (the daily digest also warns); prices are not stale; the "Ready to lock" list is reviewed.
- [ ] First payout run: check the "To pay" report; lock the team incentive (associate commissions are already fixed and become due by themselves); pay. For an associate abroad, enter the rupees the bank debited and the charges.
- [ ] Gather the real "other costs to FOB" from the first 3–5 shipments and update the %.
