# NexaCrest Export Operations Webapp — Node/MySQL port (Phases A–E)

**Start here for the full picture: [docs/APPLICATION_HANDBOOK.md](docs/APPLICATION_HANDBOOK.md)**

This is a full ground-up rebuild of the NexaCrest export-operations webapp
on Node.js/Express/MySQL, replacing the earlier PHP/MySQL delivery. It was
built by re-reading the same three source documents (the spec, the schema,
the architecture doc) and re-running the same phase-by-phase methodology
(A → E) used for the PHP build — not a mechanical line-by-line transpile.
The **database schema and seed data are the same** (`docs/schema.sql` /
`docs/seed.sql` create the same tables and rows as the PHP delivery's;
only comments, and one setting's description, name each stack's own
files), so the two builds can even share one live database if you ever wanted to run both
side by side during a migration window.

Functionally this delivers the same application the PHP build did — same
roles/permissions, same 9-stage order pipeline, same 11 generated document
types, same review/approval/amendment/dispute/audit-log/dashboard/reports
feature set — on a different, and for most VPS hosting, simpler stack:
one Node process behind Nginx instead of PHP-FPM, a templating engine
(Nunjucks) that plays the same role Twig did, Puppeteer-over-Chromium for
PDF instead of Dompdf, and the `docx` npm package instead of PHPWord.

## What's in this folder

```
app/                    the application itself — this is what you deploy
  src/
    server.js           entry point: Express app, session/CSRF/auth
                         middleware, every route registration
    config/              env.js (.env loader), db.js (MySQL pool + tx helper)
    controllers/          one per resource, mirrors the PHP Controllers/ tree
    services/             business logic (document generation, review
                           workflow, amendments, email dispatch, sample data, …)
    repositories/          all SQL lives here — one file per table/aggregate
    middleware/            session auth, permission gate, CSRF check
    helpers/               csrf, password hashing, CSV export, masking, …
    jobs/                  background jobs — see "Background jobs" below
  views/                  Nunjucks templates for the app's own UI, one
                          folder per resource, mirrors PHP's views/ tree
  templates/              the business-document Nunjucks templates, one
                          folder per type (QT, PI, OC, BUYERPO, SUPPO,
                          FDN, PL, BLI, CI, COOPREP, AMD, ANNEXA, BLE,
                          CAFIN) rendered to PDF/DOCX
  public/                 static CSS/JS/images served directly by Express
  node_modules/           every npm dependency, already installed — see
                          "About the node_modules folder" below
  package.json / package-lock.json
  .env.example            template — copy to .env and fill in real values
docs/
  schema.sql              the MySQL schema — 210 tables, 403 foreign keys
                          as of 2026-11-13 (plus this stack's own
                          `sessions` table = 211; see the file's own
                          trailing changelog comments)
  seed.sql                seed data: roles/permissions, company settings,
                          lookup tables, one Admin user
  ARCHITECTURE.md         the original architecture document this build
                          (and the PHP build before it) was implemented from
scripts/
  backup_lib.sh           shared helpers for the backup scripts (D-260) — see "Backups"
  backup_db.sh            nightly database copy (encrypted, 10-year retention) — see "Backups"
  backup_storage.sh       nightly documents copy (each file stored once) — see "Backups"
  backup_test_restore.sh  monthly test-restore into a scratch database — see "Backups"
  tests/                  backup_scripts_test.sh, the scripts' self-test
storage/
  assets/                 4 placeholder brand images (logo, signature,
                          seal, watermark) — see step 3 of
                          "First-time setup" for the one-time path fix
                          these need. Replace them for real from
                          `/company-assets` once you're logged in.
                          Everything else here (generated PDFs/DOCX,
                          uploaded files, per-client folders) is created
                          on demand as the app runs — nothing else needs
                          to be pre-created by hand. Keep this whole
                          directory OUTSIDE your web server's document
                          root; nothing in here should ever be served
                          directly by Nginx/Apache.
```

## Requirements

- **Node.js 22.12 or newer** (22 LTS) — check with `node --version`. The
  bundled `puppeteer-core` needs 22.12+, and `nodemailer` / `node-cron`
  need 20+, so the app does not run on Node 20 or 18.
- **MySQL 8.0+** on the live server (MariaDB 10.6+ is fine for
  development only) — same requirement as the PHP build. `docs/schema.sql` and any
  later change are written for MySQL (see CLAUDE.md's MySQL rule).
- **Chromium or Google Chrome** installed on the server (for PDF
  generation via Puppeteer) — see "Install Chromium" below. You do **not**
  need Puppeteer's own bundled Chromium download; this app uses
  `puppeteer-core`, which drives an existing browser install, specifically
  so the multi-hundred-MB Chromium download isn't part of every deploy.
- A process manager — **PM2** is what this guide uses (simplest, most
  common choice for a single Node app on a VPS); a systemd unit works
  identically if you prefer it, see the note at the end of that section.
- **Nginx** (or any reverse proxy) in front of the Node process — Node
  should never be exposed directly to the internet on port 80/443.

## First-time setup (a fresh Linux VPS)

> **Go-live day:** work through `docs/guides/GO_LIVE_CHECKLIST.md`. It is the business view of these steps, with the settings, people, prices and dry run, in order.

1. **Install Node.js 22 LTS.** On Ubuntu/Debian:
   ```bash
   curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
   sudo apt-get install -y nodejs
   ```
   On AlmaLinux/RHEL: `sudo dnf module install nodejs:22`.

2. **Install MySQL 8** if not already present (`sudo apt install
   mysql-server` / `sudo dnf install mysql-server`), then create the
   database and app user:
   ```sql
   CREATE DATABASE nexacrest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'nexacrest_app'@'localhost' IDENTIFIED BY 'a-real-random-password';
   GRANT ALL PRIVILEGES ON nexacrest.* TO 'nexacrest_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
   ALL PRIVILEGES includes **TRIGGER**, which the schema needs (founder-login
   protection, and the permanent document image store `document_image_blobs`, D-267).

3. **Apply the schema and seed data:**
   ```bash
   mysql -u nexacrest_app -p nexacrest < docs/schema.sql
   mysql -u nexacrest_app -p nexacrest < docs/seed.sql
   ```
   This creates all 210 tables (211 once this stack's own `sessions` store
   is added at first run) — the whole database: a new installation runs
   **no** migration (D-241) and seeds: the roles/permissions matrix (24 roles,
   96 permissions), two Super Admin logins, company settings (a few still to
   confirm — see "Placeholder values" below), lookup tables (incoterms,
   currencies, ports, T&C clauses, payment presets), and 4 placeholder brand
   assets (logo, signature, seal, watermark).
   A fresh install never needs a migration — the tests FreshInstallCompleteTest / freshInstallComplete.test.js check that every file in docs/migrations is already in schema.sql/seed.sql.

   **Fix the storage path in the seeded asset rows.** `seed.sql` inserts
   asset rows with a literal placeholder path token — in BOTH the `assets`
   table (company logo/seal/watermark) and the `user_signature_assets`
   table (per-user signatures and designation seals). Run both, right
   after importing `seed.sql`, using the exact same absolute path
   you'll put in `STORAGE_BASE_PATH` below:
   ```sql
   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/opt/nexacrest_node/storage');
   UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/opt/nexacrest_node/storage');
   UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '/opt/nexacrest_node/storage');
   ```
   Skipping this step doesn't break anything — every generated document
   just silently shows no logo/signature/seal/watermark image (the code
   checks the file exists before embedding it, so it degrades gracefully
   rather than erroring) — but you do want your real logo on real
   documents, so don't skip it. The 5 actual placeholder PNGs already ship
   in `storage/assets/` in this package, at the exact relative paths this
   UPDATE points at.

   **Set the real logins and company details (go-live).** The seed holds role
   placeholders only — no real names or e-mails are kept in the repository.
   The two founder logins are *protected accounts*: a database trigger
   refuses any change to their name or e-mail, from the app or from SQL. So,
   once, right after importing (as the database admin user), lift the
   trigger, put in the real values, and put the trigger back exactly as it
   was:
   ```sql
   DROP TRIGGER IF EXISTS trg_users_protected_bu;
   UPDATE users SET email = 'real-md@yourcompany.com', name = 'Real MD Name' WHERE email = 'md@email.com';
   UPDATE users SET email = 'real-ed@yourcompany.com', name = 'Real ED Name' WHERE email = 'ed@email.com';
   UPDATE company_settings SET setting_value = 'Real MD Name'           WHERE setting_key = 'md_name';
   UPDATE company_settings SET setting_value = 'Real Director Name'     WHERE setting_key = 'director_name';
   UPDATE company_settings SET setting_value = 'info@yourcompany.com'   WHERE setting_key = 'email';
   UPDATE company_settings SET setting_value = '+91-XXXXXXXXXX'         WHERE setting_key = 'phone';   -- the real company phone (seeded as +91-0000000000)
   UPDATE company_settings SET setting_value = 'Real registered office address, City – PIN, India' WHERE setting_key = 'registered_office';   -- seeded as a placeholder
   UPDATE assets SET name = REPLACE(name, 'Managing Director', 'Real MD Name') WHERE name LIKE 'Managing Director%';
   DELIMITER $$
   CREATE TRIGGER trg_users_protected_bu BEFORE UPDATE ON users
   FOR EACH ROW
   BEGIN
     IF OLD.is_protected_account = 1 AND (
          NOT (OLD.name <=> NEW.name) OR
          NOT (OLD.email <=> NEW.email) OR
          NOT (OLD.phone <=> NEW.phone) OR
          NOT (OLD.role_id <=> NEW.role_id) OR
          NOT (OLD.designation_id <=> NEW.designation_id) OR
          NOT (OLD.is_signatory_eligible <=> NEW.is_signatory_eligible) OR
          NOT (OLD.is_super_admin <=> NEW.is_super_admin) OR
          NOT (OLD.is_active <=> NEW.is_active) OR
          NOT (OLD.is_protected_account <=> NEW.is_protected_account)
        ) THEN
       SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot change a protected founder account — its identity, role, designation, eligibility and Super Admin/active status can never be edited through the application.';
     END IF;
   END$$
   DELIMITER ;
   ```
   Check it is back with `SHOW TRIGGERS LIKE 'users';` (you should see
   `trg_users_protected_bu`). The three company settings can also be changed
   later in Settings › Company; the two founder logins can't — the app
   refuses on purpose — so use this block for them (it is safe to run again
   later to correct a typo). Signature blocks on documents print the
   signatory's `users.name` (and `md_name` where no signatory is chosen), so
   these lines are what make the real names appear on PDFs. (A later change of
   the MD's name or title on Settings › Company offers to apply it to open orders and
   draft documents — D-349; it never renames a founder login, so use this block for that.) You then log in
   with the real e-mail and the seeded password below. This step is also on
   the go-live checklist.

   **Company identity, bank, GSTIN, IEC/PAN, LUT and RCMC are entered at
   installation (D-313, 11 Nov 2026).** `seed.sql` no longer holds the
   company's real values: the corporate office, GSTIN, IEC/PAN, bank name,
   branch, account number, SWIFT, IFSC, bank address and pincode, LUT number
   and RCMC number are placeholders (the GSTIN keeps state code 29). After the
   first login open **Settings › Setup checklist** (`/admin/setup-checklist`,
   Manage company settings): every item shows **Done** or **Needed** with a
   link — *Company identity*, *Bank details, GSTIN and IEC / PAN*, *LUT and
   RCMC — numbers and dates*, *ECGC policy (optional)*, *E-mail server (SMTP)
   — then send a test e-mail*, *Zoho Books and Zoho Mail (optional)*,
   *Backups*, *This year's holidays*, *The MD's real e-mail* and *Settings at
   the recommended values* (with **Apply recommended**). Enter the real values
   on **Settings › Company** (Unlock on the protected rows) until every item
   is Done; the placeholders print on the documents until then. (The PHP
   stack's extra item, the writable `storage/sessions` folder, does not apply
   here: this stack keeps sessions in the database table `sessions`.)

   **Set the company's real details by SQL (alternative to Settings ›
   Company, 11 Nov 2026).** If you prefer to enter everything at once, run
   this block once, right after the founder block above, as the database
   admin user. In parts A to D replace **every value between quotes**; keep
   each `setting_key` exactly as it is. In part E replace only the
   **second** value of each `REPLACE(...)` (the first one is the placeholder
   being looked for), using the same numbers as in B and C. Lines for things
   you don't have (ECGC) can stay commented out. Parts A to D are safe to run
   again to correct a typo; part E finds nothing the second time, so correct
   a checklist typo on the Reference library page. Part E
   puts the same numbers into the staff checklists (Help & SOP › Reference
   library: the PI, CI and stage-gate checklists), which quote the LUT and
   bank details.
   ```sql
   -- A. Company identity (where it prints: the header of every document)
   UPDATE company_settings SET setting_value = 'Real corporate office address, City – PIN, India' WHERE setting_key = 'corporate_office';   -- same as registered office if there is only one
   UPDATE company_settings SET setting_value = 'REAL-GSTIN-15-CHARS'      WHERE setting_key = 'gstin';      -- 15 characters, starts with the state code (29 = Karnataka)
   UPDATE company_settings SET setting_value = 'REAL-IEC-OR-PAN'          WHERE setting_key = 'iec_pan';    -- 10 characters
   UPDATE company_settings SET setting_value = 'Real Director Name'       WHERE setting_key = 'director_name';  -- only if a director signs any document

   -- B. Bank details (where they print: PI, OC and CI bank section; buyers wire money to these)
   UPDATE company_settings SET setting_value = 'Real bank name'           WHERE setting_key = 'bank_name';
   UPDATE company_settings SET setting_value = 'Real branch name'         WHERE setting_key = 'bank_branch';
   UPDATE company_settings SET setting_value = 'REAL-ACCOUNT-NUMBER'      WHERE setting_key = 'bank_account_no';
   UPDATE company_settings SET setting_value = 'REALSWIFTXXX'             WHERE setting_key = 'swift_bic';  -- 8 or 11 characters
   UPDATE company_settings SET setting_value = 'REALIFSC000'              WHERE setting_key = 'ifsc';       -- 11 characters
   UPDATE company_settings SET setting_value = 'Real bank branch address, City – PIN, India' WHERE setting_key = 'bank_address';
   UPDATE company_settings SET setting_value = 'REAL-PIN'                 WHERE setting_key = 'bank_pincode';  -- 6 digits

   -- C. LUT and RCMC (where they print: PI and CI GST declaration, COO prep sheet; alerts before expiry)
   UPDATE company_settings SET setting_value = 'REAL-LUT-ARN'             WHERE setting_key = 'lut_number';
   UPDATE company_settings SET setting_value = 'FY 2026-27'               WHERE setting_key = 'lut_valid_fy';
   UPDATE company_settings SET setting_value = '2027-03-31'               WHERE setting_key = 'lut_expiry_date';   -- YYYY-MM-DD
   UPDATE company_settings SET setting_value = 'REAL-RCMC-NUMBER'         WHERE setting_key = 'rcmc_number';
   UPDATE company_settings SET setting_value = '2027-03-31'               WHERE setting_key = 'rcmc_valid_until';  -- YYYY-MM-DD

   -- D. ECGC policy (optional; leave commented out if there is no policy)
   -- UPDATE company_settings SET setting_value = 'REAL-ECGC-POLICY-NO' WHERE setting_key = 'ecgc_policy_no';
   -- UPDATE company_settings SET setting_value = '2027-03-31'          WHERE setting_key = 'ecgc_policy_valid_to';   -- YYYY-MM-DD

   -- E. The same LUT and bank numbers inside the staff checklists (Reference library)
   UPDATE internal_reference_docs SET content = REPLACE(content, 'AD000000000000X', 'REAL-LUT-ARN')              WHERE content LIKE '%AD000000000000X%';
   UPDATE internal_reference_docs SET content = REPLACE(content, 'Account No. 000000000000', 'Account No. REAL-ACCOUNT-NUMBER') WHERE content LIKE '%Account No. 000000000000%';
   UPDATE internal_reference_docs SET content = REPLACE(content, 'SWIFT XXXXINXXXXX', 'SWIFT REALSWIFTXXX')       WHERE content LIKE '%SWIFT XXXXINXXXXX%';
   UPDATE internal_reference_docs SET content = REPLACE(content, 'IFSC XXXX0000000', 'IFSC REALIFSC000')          WHERE content LIKE '%IFSC XXXX0000000%';
   UPDATE internal_reference_docs SET content = REPLACE(content, 'Pincode 000000', 'Pincode REAL-PIN')            WHERE content LIKE '%Pincode 000000%';

   -- Check: every line below must show your real value, and the last query must return 0.
   SELECT setting_key, setting_value FROM company_settings
    WHERE setting_key IN ('legal_name','registered_office','corporate_office','gstin','iec_pan','md_name','director_name','phone','email',
                          'bank_name','bank_branch','bank_account_no','swift_bic','ifsc','bank_address','bank_pincode',
                          'lut_number','lut_valid_fy','lut_expiry_date','rcmc_number','rcmc_valid_until','ecgc_policy_no','ecgc_policy_valid_to');
   SELECT COUNT(*) AS placeholders_left FROM internal_reference_docs
    WHERE content LIKE '%AD000000000000X%' OR content LIKE '%Account No. 000000000000%' OR content LIKE '%XXXXINXXXXX%'
       OR content LIKE '%XXXX0000000%' OR content LIKE '%Pincode 000000%';
   ```
   Then open **Settings › Setup checklist**: *Company identity*, *Bank
   details, GSTIN and IEC / PAN* and *LUT and RCMC* should now show
   **Done**. Generate one draft PI and check the header, the bank section
   and the LUT line before any document goes to a buyer. The real values are
   typed only on the live server, never into a file in the repository
   (no-PII rule). **Order of the install SQL:** (1) `schema.sql`, (2)
   `seed.sql`, (3) the three storage-path `UPDATE`s, (4) the founder block
   (real logins, names, phone, e-mail, registered office), (5) this block.
   An existing live database already has its real values: the 11 Nov
   upgrade files keep them, so do **not** run this block there.
4. **Install Chromium** (for PDF generation):
   ```bash
   sudo apt install -y chromium              # Ubuntu — binary is /usr/bin/chromium
   # or: sudo apt install -y chromium-browser  # older Ubuntu/Debian releases
   # or: sudo dnf install -y chromium          # AlmaLinux/RHEL/Fedora
   ```
   Find the actual path with `which chromium` (or `which chromium-browser`,
   or `which google-chrome` if you installed Chrome instead) — you'll need
   it in the next step.

5. **Extract the app and configure `.env`:**
   ```bash
   cd /opt   # or wherever you keep deployed apps
   unzip NexaCrest_Node_Deployment_Package.zip
   cd nexacrest_node/app
   cp .env.example .env
   nano .env   # fill in DB_*, SESSION_SECRET_KEY, STORAGE_BASE_PATH,
               # PUPPETEER_EXECUTABLE_PATH (from step 4), SMTP_* when ready
   ```
   Generate a real session secret with `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`.
   Set `STORAGE_BASE_PATH` to an absolute path **outside** any directory
   Nginx serves — e.g. `/opt/nexacrest_node/storage` (that folder is
   created automatically on first run if it doesn't exist).
   Keep the three Zoho lines written out (never commented out):
   `ZOHO_INTEGRATION_ENABLED=false`, `ZOHO_ENVIRONMENT=test`,
   `ZOHO_EINVOICE_ENABLED=false`. The app works fully without Zoho. On the
   live server, once Zoho Books is set up, set `ZOHO_INTEGRATION_ENABLED=true`
   and `ZOHO_ENVIRONMENT=production`; leave `ZOHO_EINVOICE_ENABLED=false`
   until NexaCrest must e-invoice. Mail always goes through the SMTP
   server (Bluehost) — Zoho Mail is not used to send. See SOP
   [ca-03 Zoho Books Sync](docs/SOP/ca-03-zoho-sync.md).
   **Settings in `app/.env` to check before go-live** (the template `app/.env.example` explains each):
   `APP_ENV=production`, `DEV_SERVER=false`, `APP_URL`, a fresh `APP_ENCRYPTION_KEY`, `DB_*`,
   `STORAGE_BASE_PATH`, `SMTP_*`, the three `ZOHO_*` switches, `SMS_GATEWAY_*` (may stay empty)
   and the `BACKUP_*` lines (see "Backups"). Node also needs `SESSION_SECRET_KEY`, `PORT`, `PUPPETEER_EXECUTABLE_PATH`,
   `RUN_JOBS_IN_PROCESS` and the two `*_POOL_SIZE` values.
   **Outbound HTTPS (D-301, 11 Nov 2026).** The daily exchange-rate job calls
   `https://api.frankfurter.app` (European Central Bank rates) and, if that
   fails, `https://open.er-api.com`. Both are free and need no key. If the VPS
   firewall limits outbound traffic, allow HTTPS (443) to both; check it once
   with **Fetch today's rate now** on Products › Global pricing settings
   (button at the top of the Products page) › Exchange Rate (USD → INR). Without it the rate in use stays as it is and the page and the Setup
   checklist warn.
   **Sessions (D-283, 11 Nov 2026).** The session store (table `sessions`) and
   the cookie follow the longest idle timeout set in Settings › Security
   (staff 4 h by default), so the old one-day store life no longer differs
   from the app's own timeouts. Nothing to configure.

6. **No `npm install` needed** — `node_modules/` ships already built (see
   "About the node_modules folder" below). Just start the app:
   ```bash
   cd /opt/nexacrest_node/app
   node src/server.js
   ```
   You should see `NexaCrest (Node/MySQL) listening on port 3000
   [APP_ENV=production]`. Ctrl-C to stop — for real deployment, use PM2
   instead (next step) so it survives reboots and crashes.

7. **Run it under PM2:**
   ```bash
   sudo npm install -g pm2
   cd /opt/nexacrest_node/app
   pm2 start src/server.js --name nexacrest
   pm2 save
   pm2 startup   # follow the one printed command to enable on-boot start
   ```
   `pm2 logs nexacrest`, `pm2 restart nexacrest`, `pm2 stop nexacrest` are
   your day-to-day commands. **If you'd rather use systemd instead of
   PM2**, a plain unit file works identically — `ExecStart=/usr/bin/node
   /opt/nexacrest_node/app/src/server.js`, `Restart=always`,
   `WorkingDirectory=/opt/nexacrest_node/app`, `EnvironmentFile` isn't
   needed since the app reads `.env` itself via `dotenv`. Either supervisor
   is fine; don't run both.

8. **Put Nginx in front of it** — a minimal reverse-proxy server block:
   ```nginx
   server {
       listen 80;
       server_name www.example.com;
       location / {
           proxy_pass http://127.0.0.1:3000;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
       client_max_body_size 20M;   # file uploads go up to 15MB — leave headroom
   }
   ```
   Then get a real TLS certificate (`sudo certbot --nginx -d www.example.com`)
   and set `APP_URL=https://www.example.com` in `.env`. The app already
   sets `trust proxy` and marks its session cookie `secure` whenever
   `APP_ENV` isn't `local`, so HTTPS-only cookies work correctly the moment
   Nginx is terminating TLS in front of it — no further code change needed.

9. **Log in** at `https://www.example.com/login` with a seeded account
   (both are Super Admin, both the same password):
   - Email: `md@email.com`
   - Email: `ed@email.com`
   - Password: `ChangeMe#2026`

   You'll be forced onto `/force-password-change` immediately (by design —
   `force_password_change` is set on both seeded rows).

   **Every login also asks for a 6-digit code sent by e-mail** (owner decision
   D-265, 7 Nov 2026 — every staff member and every buyer, at every login). So
   **set up e-mail (SMTP in `app/.env`) before the first login**; until mail works
   the code only appears in the server error log. On a local machine
   (`APP_ENV=local`) the code is also shown on the code page. Right after the
   first login, the MD and the Admin each make their **emergency backup codes**
   (My Account › Backup codes: 10 one-time codes, shown once) and keep them
   offline — they are the way in if e-mail ever stops working.

## Using the app

The UI, workflow, and permission model are functionally identical to the
PHP build — every screen, route, and business rule described in
`docs/ARCHITECTURE.md` behaves the same way regardless of which backend
renders it. In short: clients and orders move through a 9-stage gated
pipeline (Quotation → Buyer PO → Proforma Invoice → Order Confirmation →
Supplier PO → Freight Payment [CIF/CFR only, auto-skipped for FOB] →
Packing & BL Instruction → Commercial Invoice & balance → Despatch &
Closure), generating one of 11 document types at the appropriate stage,
each as a watermarked DRAFT PDF that becomes a FINAL PDF once its assigned
reviewer(s) approve it. On top of that pipeline: a two-level deferred
email-send workflow for getting a generated document to the buyer, a
Payment Terms Amendment workflow for changing advance/balance terms
mid-order (request → MD approval → generate the legal agreement → upload
the signed copy → activate, after which future documents for that order
reflect the new terms automatically), dispute tracking, a full audit log
viewer, a permission-gated dashboard and reports module (with CSV export
and savable/shareable aggregate report definitions), user
management (create/deactivate/reactivate/force-reset-password), and a
Sample Data Playground (`/sample-data`) that loads three realistic
practice clients/orders — a fresh Stage 1 order, an FOB order carried to
Stage 5, and a CIF order carried all the way to a closed Stage 9 with
every one of the nine document types generated — and clears them again on
demand, without ever touching real data — useful for training a new user
or demoing the app.

## Background jobs

Seven jobs need to run outside the request/response cycle (plus the backup scripts — see "Backups"):

- **`src/jobs/checkAlerts.js`** — run once daily. Checks LUT/RCMC
  certificate expiry against their configured alert/escalation thresholds,
  freight payments overdue (FDN issued, not yet cleared, past
  `company_settings.fdn_overdue_days_c`), balance payments overdue (the
  day after the balance due date — for post-BL and LC orders that date is
  set when the scanned BL is sent, gap list G-2; also to the order's
  assignees), the Letter of Credit's last shipment date, deadline to
  present documents and expiry (once each, 7 days before, gap list G-4;
  also to the assignees), quotation/PI validity expired, and dispute
  responses past their due date — creates in-app notifications for Admin/MD for each, with a
  same-day dedup guard so a still-unresolved condition doesn't re-notify
  on every run.
  Since 2 Nov 2026 it also sends the Agreements register reminders (D-228):
  30 days, 7 days and on the expiry day, once each, to holders of
  `manage_agreements` (client agreements included).
- **`src/jobs/dispatchDeferredEmails.js`** — run every 5–15 minutes. Sends
  every `email_log` row that's `approved` (Level-2 sign-off already given)
  and due (`scheduled_at <= NOW()`, or `NULL` meaning "immediate"). Safe to
  run as often as you like — each row is only ever picked up once, marked
  `sent`/`failed` immediately.
- **`src/jobs/autoConfirmOcAcknowledgments.js`** — run every 15 minutes.
  Auto-confirms any Order Confirmation the buyer hasn't acknowledged
  (client portal button, or a staff-recorded email reply) within 48 hours
  of it being emailed, unlocking Stage 5 (docs/schema.sql Section AE).
  Gap list G-6 (1 Nov 2026): while buyer e-mails are not sent (Testing
  mode on, or the buyer documents e-mail switched off in Settings › E-mail),
  the 48 hours start only when staff press "Mark Order Confirmation Sent to Buyer"
  (Step 6c), not when the OC is approved.
  Idempotent — a row is only ever picked up once it has
  `acknowledged_at IS NULL AND due_at <= NOW()`, and acknowledging it (by
  any means) before this runs removes it from that set.
- **`src/jobs/dailyDigest.js`** — once every morning at 08:15 IST (owner
  decision 1 Nov 2026): one e-mail per person listing their open tasks and
  documents to review, at most one a day. The same run also sends the
  associates' reminders (D-217): 30 and 7 days before a client's 12-month
  protection ends (if the client has not ordered) and before an
  associate's agreement ends, each once, and marks one-off invitation
  links past their 30 days as expired.
- **`src/jobs/documentCopyCleanup.js`** — once a day at 02:15 IST (owner
  points 5/8/9, 2 Nov 2026; in-process in `scheduler.js`, or
  `node src/jobs/documentCopyCleanup.js` from cron): removes the document
  copies Settings › Document clean-up lists in its preview (drafts of
  approved documents, never-sent superseded revisions, old CAFIN copies).
  Since D-313 (11 Nov 2026) the rules are **on** by default: drafts 90 days
  after approval, never-sent superseded revisions after 365 days (the
  SUPERSEDED copy is kept), CAFIN latest only; set a rule to 0 / "keep all"
  to switch it off. Never removes anything sent to the client, the current
  copy of a document, uploaded files, files of an order with an open
  dispute, or documents in a CA-locked financial year.
- **`src/jobs/fetchFxRate.js`** — once a day at 07:05 IST (owner decision
  D-301, 11 Nov 2026; in-process in `scheduler.js`, or `node
  src/jobs/fetchFxRate.js` from cron): fetches the USD → INR market rate
  from Frankfurter (European Central Bank reference rate of the previous
  working day) or, if that fails, from open.er-api.com, and keeps it in the
  history (`fx_rates`). The pricing rate is the market rate minus the
  cushion (Products › Global pricing settings: 2 % by default, 0–5 %). A
  failed fetch keeps the rate in use and shows a warning (exit code 1); a
  change of more than 2 % against the rate in use waits for the MD on the
  Approvals page. Prices on Quotations and Proforma Invoices already issued
  never change. In **Manual** mode the rate is still fetched for the
  history, but pricing uses the rate the MD typed (`pricing_fx_rate`).
  Needs outbound HTTPS to `api.frankfurter.app` and `open.er-api.com`. Safe
  to run twice a day (one row per date).
- **`src/jobs/zohoSync.js`** — CA / Accounting module. **Must run hourly**
  (owner decision 2 Nov 2026, D-233): saving a receipt, bill, payment,
  expense or cost in the app never sends it to Zoho Books at that moment —
  it waits for the next sync ("Waiting for Zoho: N" on the order page and
  beside Zoho Sync in the menu). Without the hourly run records reach Books
  only when someone presses Sync Now. A run with failures e-mails every
  "Manage CA module" holder once (and notifies them in the app). Runs
  the same `caSyncService.runFullSync()` as the "Sync Now" button on
  `/ca/zoho-sync`: sends Zoho Books the Commercial Invoices and Debit
  Notes (as export invoices), the buyer's payments and the LC payment
  (applied to their invoice; an advance is held until the CI is in
  Books), the suppliers and logistics partners (as vendors) with their
  tax-invoice bills and payments, order expenses and paid cost entries
  (as expenses) and received export benefits (as journals); then imports
  the expenses the CA enters in Books. At most once every 23 hours a
  scheduled run also checks every record already sent and re-creates or
  restores any that was deleted or changed in Books (Sync Now always
  checks). Only records dated on or after the go-live date
  (`zoho_books_sync_start_date`) are sent; with
  `ZOHO_ENVIRONMENT=production` nothing is sent until it is set. While
  Zoho is off (`ZOHO_INTEGRATION_ENABLED` not `true` in `.env`, or the
  Zoho Books switch in Settings › Zoho off) it logs one "skipped" row per
  part and exits 0. The `/ca/zoho-sync` page shows this job's log
  alongside Sync Now's.

All seven are plain Node scripts, runnable directly and printing a
one-line summary to stdout, exit code 0 on success:

```bash
node src/jobs/checkAlerts.js
node src/jobs/dispatchDeferredEmails.js
node src/jobs/autoConfirmOcAcknowledgments.js
node src/jobs/zohoSync.js
node src/jobs/dailyDigest.js
node src/jobs/documentCopyCleanup.js
node src/jobs/fetchFxRate.js
```

**Pick exactly one of these two ways to run them — never both, or the
same job double-fires:**

**Option 1 — external cron (recommended: simplest, and independent of the
web process's own uptime).** Add seven crontab entries, in IST:
```
CRON_TZ=Asia/Kolkata
*/10 * * * *  cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/dispatchDeferredEmails.js >> /var/log/nexacrest/dispatch_deferred_emails.log 2>&1
*/15 * * * *  cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/autoConfirmOcAcknowledgments.js >> /var/log/nexacrest/auto_confirm_oc_acknowledgments.log 2>&1
0 6 * * *     cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/checkAlerts.js >> /var/log/nexacrest/check_alerts.log 2>&1
0 * * * *     cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/zohoSync.js >> /var/log/nexacrest/zoho_sync.log 2>&1
15 8 * * *    cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/dailyDigest.js >> /var/log/nexacrest/daily_digest.log 2>&1
15 2 * * *    cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/documentCopyCleanup.js >> /var/log/nexacrest/document_copy_cleanup.log 2>&1
5 7 * * *     cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/fetchFxRate.js >> /var/log/nexacrest/fetch_fx_rate.log 2>&1
```

Plus the three backup lines (database 02:00, documents 02:30, monthly test-restore) — see "Backups" below.
(`CRON_TZ=Asia/Kolkata` makes 06:00, 08:15, 02:15 and 07:05 IST, the same as the PHP stack. If your cron does not support `CRON_TZ`, set the server time zone to IST or convert the times.)
(`sudo mkdir -p /var/log/nexacrest` first.) Use the full path to `node`
(`which node`) — cron's `$PATH` is much smaller than an interactive
shell's.

**Option 2 — in-process, via node-cron.** Set `RUN_JOBS_IN_PROCESS=true`
in `.env` and restart the app (`pm2 restart nexacrest`). `src/server.js`
then starts `src/jobs/scheduler.js`, which runs `checkAlerts` daily at
06:00 IST (the same time as the PHP stack — QA batch 4, PAR-2), `dispatchDeferredEmails` every 10 minutes,
`autoConfirmOcAcknowledgments` every 15 minutes, `zohoSync` hourly,
`dailyDigest` at 08:15, `documentCopyCleanup` at 02:15 and `fetchFxRate` at 07:05 (all IST), all inside the same process — nothing else to configure, but job
execution is then tied to the web process's uptime (a restart briefly
pauses all seven jobs, and if you run multiple app instances behind a load
balancer for scaling, each instance would independently fire the jobs —
stick to a single instance, or use Option 1, if you ever scale out).

Two more scripts in `src/jobs/` are run by hand, never scheduled:

- **`src/jobs/zohoCleanupTestRecords.js`** — removes the records made in
  Zoho Books while testing: customers and vendors named `TEST-…` with
  their invoices, bills and payments, and expenses and journals marked
  `TEST-`. A dry run by default (lists, changes nothing); `--apply`
  deletes; `--from-sync-log` also removes everything this database sent
  (refused when `ZOHO_ENVIRONMENT=production` — test database only);
  `--by "Name"` and `--log ../docs/zoho/zoho-change-log.csv` add one row
  per deletion to the Zoho change log (without `--log` the rows are
  printed for pasting in). Run it before go-live.
- **`src/jobs/reorganizeOrderFiles.js`** — one-off tidy-up that moves
  older order files into the per-step folders (`01-quotation/`, …). A dry
  run by default; `--apply` moves them. Back up `storage/` and the
  database first.

## Backups — database and every order document (owner decisions D-260, 6 Nov 2026)

The backup is **only an extra copy**. The scripts only *read* the live database and the live
documents (`STORAGE_BASE_PATH`); they never change, move or delete anything live, and they never
overwrite an older backup. Records must be kept for **10 years**.

The document images of every stored snapshot live in the database table `document_image_blobs`
(D-267, insert-only, protected by triggers), so the nightly database copy already contains them;
a restore must load the whole database, never skip that table.

The scripts ship in the repository (`scripts/`, the same files on both stacks):

| Script | What it does | Cron (IST) |
|---|---|---|
| `scripts/backup_db.sh` | `mysqldump --single-transaction` (read-only, no locks), gzip, encrypt, `.sha256` beside it → `BACKUP_DIR/db/nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc` | `0 2 * * *` |
| `scripts/backup_storage.sh` | Daily snapshot of every document → `BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/`. Unchanged files are **stored once** (hard links via `rsync --link-dest`). On the first run of each month also a full encrypted archive → `BACKUP_DIR/storage/archives/` | `30 2 * * *` |
| `scripts/backup_test_restore.sh` | Monthly proof that the newest copy restores: checksum, decrypt, load into a **separate scratch database** (`BACKUP_RESTORE_TEST_DB`, default `<DB_DATABASE>_restore_test`, never the live one), count tables, drop it again; checks the newest documents archive opens | `0 4 1 * *` |

**Where the copies go is set in `app/.env`, not in the cron line:**

```
BACKUP_DIR=/var/backups/nexacrest            # outside the web root and outside STORAGE_BASE_PATH (the scripts refuse otherwise)
BACKUP_KEEP_YEARS=10                     # everything kept 10 years, then removed
BACKUP_THIN_AFTER_DAYS=120               # older database copies thinned to one per month; 0 = keep every nightly copy
BACKUP_ENCRYPTION_KEY_FILE=/etc/nexacrest/backup.key
BACKUP_OFFSITE_TARGET=                   # empty until the owner chooses; then an rclone remote, e.g. b2crypt:nexacrest
```

**Retention in one rule:** everything is kept `BACKUP_KEEP_YEARS` (10) years. To save space, database copies
older than `BACKUP_THIN_AFTER_DAYS` (120) days are thinned to the first copy of each month; documents never need
thinning because each file is stored once. Only files named like a backup, inside `BACKUP_DIR`, are ever removed.

**One-time set-up on the server:**

1. Create the folder and the key (keep a copy of the key somewhere safe **away from the server** — without it the
   encrypted copies cannot be opened):
   ```
   mkdir -p /var/backups/nexacrest && chmod 700 /var/backups/nexacrest
   openssl rand -base64 48 > /etc/nexacrest/backup.key && chmod 600 /etc/nexacrest/backup.key
   ```
2. Add the keys above to `app/.env`.
3. Make the scripts executable: `chmod 700 /opt/nexacrest_node/scripts/*.sh`
4. Add the cron lines (the scripts read everything else from `app/.env`):
   ```
   CRON_TZ=Asia/Kolkata
   0 2 * * *   /opt/nexacrest_node/scripts/backup_db.sh >> /var/log/nexacrest/backup_db.log 2>&1
   30 2 * * *  /opt/nexacrest_node/scripts/backup_storage.sh >> /var/log/nexacrest/backup_storage.log 2>&1
   0 4 1 * *   /opt/nexacrest_node/scripts/backup_test_restore.sh >> /var/log/nexacrest/backup_test_restore.log 2>&1
   ```
5. Run `scripts/backup_db.sh` and `scripts/backup_storage.sh` once by hand, then open **Settings › Backups** — both
   rows should say OK.
6. The test-restore needs a MySQL user allowed to create and drop the scratch database
   (`BACKUP_RESTORE_TEST_DB`). On Bluehost create that database once in cPanel → MySQL Databases and give the app
   user all rights on it.

**Watching it:** Settings › Backups (MD / Admin, read-only) shows the last run of each job, sizes, encryption,
off-site status and the settings. The daily digest warns whoever manages company settings when a nightly copy is
older than 26 hours, has failed, is not encrypted, the test-restore is older than 35 days or failed, or (on a
production server) `BACKUP_DIR` is not set.

**Off-site (later):** when the owner chooses a provider, install `rclone`, configure an encrypted remote
(`rclone config`), and set `BACKUP_OFFSITE_TARGET`. The scripts then `rclone copy --immutable` the database copies
and the monthly archives — copy only, never delete at the target.

**Restoring** is never automatic: follow SOP 32 "Backups and restore" step by step.

**Self-test:** `bash scripts/tests/backup_scripts_test.sh` (also run by PHPUnit `BackupFacilityTest` / Jest
`backupFacility.test.js`) proves retention, thinning, one-copy documents, the guard, untouched live files,
encryption and checksums in a temporary folder.

## Security hardening already built in

These aren't a separate bolt-on pass — they're the same behavior the PHP
build shipped, carried over faithfully since they're implemented in the
services/repositories this port reuses:

- **Non-guessable document filenames** — every generated PDF/DOCX's
  on-disk filename is a random 32-character hex string, at all four
  file-write call sites (`documentGenerationService.js`'s initial
  generation, the approved/FINAL swap, and amendment agreements). The
  human-readable name still appears in the download's
  `Content-Disposition` header and every on-screen list — only the
  physical file in `storage/` is unguessable, which matters because
  `storage/` sits outside the web root anyway (defense in depth, not the
  only thing preventing direct access).
- **Enforced password expiry** — `company_settings.password_expiry_days`
  (seeded at 90) is checked on every request in `sessionAuth.js`; exceeding
  it flips the same `force_password_change` flag a brand-new account uses
  and logs `PASSWORD_EXPIRED_FORCED_CHANGE` to the audit log with the
  exact day count. Verified live this delivery by backdating a real
  account's `password_changed_at` to 100 days ago against a 90-day policy
  and confirming the very next request redirected to
  `/force-password-change`.
- **Mandatory-reason, fully audit-logged admin overrides** — Company
  Settings changes, a client's unique-number override, an order's
  status/lock override, and an amendment's reference override all require
  a non-empty reason (rejected server-side, not just a client-side check)
  and log the old value/new value/reason to `audit_log`.
- **Data masking** — a client's email and phone are shown masked
  (`b***@example.com` style) to anyone without `view_client_email_full`;
  Admin/MD see the real value by default per the seeded RBAC matrix.
- **CSRF** — every state-changing form carries a session-bound token,
  compared with a timing-safe equality check (`crypto.timingSafeEqual`),
  on every POST.
- **Parameterized SQL everywhere** — every repository uses mysql2's named
  placeholders; no repository builds a query by string-concatenating user
  input. The one place raw table/column names are interpolated
  (`sampleDataRepository.js`'s bulk-delete routine) only ever interpolates
  numeric ids it just queried for itself, never request input.
- **Autoescaping templates** — Nunjucks runs with `autoescape: true`
  globally; every one of the ~120 ported views was converted using the
  same explicit escaping rules the PHP→Node conversion followed (see
  inline comments in `server.js` near the Nunjucks setup for the exact
  filter/global list).
- **Session cookies** — `httpOnly`, `sameSite: lax`, and `secure`
  whenever `APP_ENV` isn't `local`; sessions are stored in a real MySQL
  table (`connect-session-knex`), not in-memory, so they survive an app
  restart and work correctly if you ever run more than one instance behind
  a shared load balancer.

## Protected fields (added 2026-09-19)

Certain data is too important to risk a silent, accidental edit or delete —
all Terms & Conditions clauses (27/27, no exceptions), both Payment Presets,
and 17 Company Settings (the 12 banking/GST/LUT/RCMC fields plus the 3
document reference-format strings) ship **protected** by default. This is
one shared mechanism (`is_protected`), reused across all three tables:

- A protected field renders read-only with a 🔒 badge on `/settings` and
  `/admin/overrides`. Editing it requires clicking **Unlock**, which prompts
  for a reason before the field becomes editable — enforced server-side too
  (a bare POST missing the unlock flag is rejected, nothing saved), not just
  in the browser.
- Every unlock and every edit is logged to `audit_log`
  (`PROTECTED_FIELD_UNLOCKED` + `FIELD_EDIT`).
- The `is_protected` flag itself is never one person's decision. Visit
  `/admin/field-protection` (permission `manage_field_protection`, granted
  to Admin/Managing Director by default) to request locking or unlocking a
  field, with a mandatory reason. A **different** privileged user must then
  approve the request before the flag actually flips — the system blocks
  approving your own request, both in the UI and server-side. Rejecting a
  request leaves the flag untouched; either way the decision is logged
  (`PROTECTION_FLAG_APPROVED` / `PROTECTION_FLAG_REJECTED`).
- A database-level backstop (`schema.sql` Section L) blocks hard-DELETE and
  blanking/deactivating a protected row even via direct SQL, independent of
  the application — six triggers, one delete-guard and one blank/deactivate
  guard per table.

To protect or unprotect a field that isn't in the default set (or to
unprotect one that is), you'll need at least two people with the
`manage_field_protection` permission — by design.

## Lost orders & Operations Queues report (added 2026-09-19)

Reporting had no way to answer "how many quotations/PIs are we still
chasing, and how many did we actually lose" — `orders.status` only had
`active` / `complete` / `disputed`. Two additions:

- **`orders.status` now also accepts `lost`**, with `lost_reason`,
  `lost_at`, `lost_by` columns alongside it. Any active order's page has a
  **Mark as Lost** button (permission `manage_orders`) that requires a
  reason, logs `ORDER_MARKED_LOST` to `audit_log`, and locks the order the
  same way closing one does. There's no separate "reopen" button — a wrong
  call is corrected through the existing Admin Override (`edit_locked_data`)
  escape hatch, which now accepts `lost` as a valid override target too.
- **`/reports/queues`** ("Operations Queues", permission `view_reports`,
  linked from `/reports`) is a live snapshot of every stage-transition
  queue — quotations awaiting dispatch, orders awaiting the buyer's PO, PI
  stage, OC awaiting dispatch/acknowledgement, Supplier PO not yet drafted,
  and the CI → scanned BL → balance payment → hard-copy-despatch chain —
  plus a date-ranged funnel section (quotations/PI sent, lost, won,
  amendment count). Every bucket reads columns the app was already
  recording (`documents.status`, `order_payment_status`, `order_shipping`,
  `order_supplier_po`, `email_log.sent_at`) — nothing here is a new source
  of truth, just new queries against existing data.

## Document template fidelity verification pass (added 2026-09-21)

Used the extended Sample Data Playground (a full CIF order through every
document type — see above) to visually inspect every rendered PDF page by
page on both stacks. Found and fixed four real, previously-undiscovered
bugs — all four surfaced only because this was the first time a CFR/CIF
order with real crate/shipping data had ever been carried through every
document type in one run:

1. `orderRepository.setPiDates()` existed but was never called — every
   Proforma Invoice ever generated showed "VALID UNTIL * TBC", and the
   PI-send email's `{pi_valid_until}` placeholder would render blank.
2. The Packing List's crate breakdown skipped the trailing-zero trim every
   other number on the page gets ("10.00" pcs instead of "10").
3. The BL Instruction Sheet's "Container Type / Size" read the rough
   order-creation estimate instead of the confirmed value from the same
   Packing/BL form the document itself is generated from.
4. The Buyer PO Acceptance letter's payment-terms sentence was a static
   string describing only the "before shipment" balance trigger, wrong for
   every order on the "Established Buyer — Post-BL" preset — a real,
   legally-significant defect since this document exists for the buyer to
   countersign. Consolidated onto the same `balanceTriggerSentence()`
   helper the Order Confirmation template and `amendmentService.js` already
   used, instead of a second, static copy.

See the PHP/MySQL README's matching section for the full detail and how
each fix was verified live (regenerate → inspect the actual rendered PDF →
confirm the fix and confirm the other branch didn't regress).

## Top-tier UI/UX pass (added 2026-09-21)

The shared layout (`views/layout/base.njk`) and stylesheet (`public/css/app.css`)
got a pass, which reaches every one of the ~60 views for free: the ~20-link
flat nav bar (unreadable on anything but a wide desktop) is now three
permission-gated dropdowns (`Operations`/`Insights`/`Admin`, built from
plain `<details>/<summary>` — no JS) plus a checkbox-driven hamburger for
screens under 900px; `.card` scrolls horizontally on its own instead of the
whole page gaining a scrollbar when a table is wider than the screen; the
order form's 8-column product-line grid collapses to one column on mobile;
every interactive element gets a visible `:focus-visible` ring; and every
`type="date"`/`type="number"`/`type="datetime-local"` input is now styled
the same width as every other field (they were previously stuck at the
browser's tiny native default, most visible on the Audit Log filter form).
Also fixed a real, live low-contrast bug: the "Notifications" link in the
topbar had no explicit color and inherited a dark navy against the dark
navy header, nearly unreadable on every page. See the PHP/MySQL README's
matching section for the full list and how it was verified live (Playwright
against the running app, both stacks, desktop and a 390px/320px phone
viewport).

## Placeholder values you must replace before going live

Checked against `docs/seed.sql` on 11 Nov 2026. Since D-313 the seed holds **no real company values**: the
corporate office, GSTIN (`29AAAAA0000A1ZY`, state code 29 kept), IEC/PAN, bank name, branch, account number,
SWIFT, IFSC, bank address and pincode, LUT number and RCMC number are placeholders, like the MD and director
names, phone, e-mail and registered office. Enter the real values at installation: **Settings › Setup
checklist** (`/admin/setup-checklist`) shows each group as **Needed** with a link to **Settings › Company**
(Unlock on the protected rows) until it is done. Still to do on the live install (also in
`docs/guides/GO_LIVE_CHECKLIST.md`):

**Where to enter them:** on **Settings › Company**, or all at once with the "Set the company's real details by
SQL" block in "First-time setup" above (right after the founder block). It also puts the real LUT and bank numbers
into the staff checklists and ends with a check query. Not for the existing live database.

- **Logins:** the two seeded Super Admin accounts share the password `ChangeMe#2026`; each must set a new one
  at first login (forced). Deactivate the second one if it is not needed.
- **Company identity, bank, GSTIN, IEC/PAN, LUT and RCMC:** placeholders until entered (see above); they print
  on the documents until then.
- **Dates to confirm:** `lut_expiry_date` and `rcmc_valid_until` are seeded as 31 Mar 2027 — confirm them.
- **ECGC:** `ecgc_policy_no` and `ecgc_policy_valid_to` are empty; fill them only if there is a policy.
- **Pricing exchange rate (D-301):** fetched automatically every day (Products › Global pricing settings ›
  Exchange Rate (USD → INR); pricing rate = market rate − 2 % cushion). `pricing_fx_rate` (seeded 82.00) is used only in
  Manual mode and until the first rate is fetched — press **Fetch today's rate now** on go-live day and check the
  box "Rate used for prices right now" (D-340).
- **E-mail (D-280, D-347):** Settings › E-mail — every SHOULD e-mail is on and, since 12 Nov 2026, **Testing mode
  is ON** in the seed, so buyers and suppliers get no e-mail until it is switched **off** on go-live day. Keep it on
  while you test with real addresses; name the responsible person and backup for the company-level compliance alerts on the same page.
- **Holidays (D-313):** the Indian national holidays of this year and next are seeded; add the state and festival
  holidays (Settings › Holidays) and tick the item on the Setup checklist.
- **Images:** the four company assets (logo, MD signature, company seal, watermark) show a labelled
  placeholder graphic until you upload the real files on **Company Assets** (`/company-assets`).
- **Storage path:** the `__STORAGE_BASE_PATH__` rows are fixed by the three `UPDATE`s in "First-time setup";
  the `storage_base_path` setting is documentation only.

Reference formats in `company_settings` (protected): `master_tracking_ref_format` (the client number,
NC/SC/{YY}/{DDMM}{NNN}) and `order_ref_format` (D-248: NC/SC/ORD/{YY}/{DDMM}{NNN}, a daily counter).

## What's deliberately not built yet (by design, not by mistake)

> **Update 6 Nov 2026:** this list was written phase by phase. Since then several items were built:
> payment presets have their own Admin page (`/payment-presets`) and T&C clause text is edited under
> Settings › Admin overrides; the alert job e-mails as well as notifying in the app; the Buyer PO and supplier
> PO have upload flows; most document types now also have a Word copy; users can be edited from
> `/users`. Items below that are not covered by this note still stand.

- **Actually sending deferred emails needs real SMTP credentials.** With
  none configured, `emailService.sendWithAttachment()` logs to the console
  and returns `false`; the dispatch job correctly marks the row `failed`
  rather than silently pretending it worked. Point `SMTP_HOST`/
  `SMTP_USERNAME`/`SMTP_PASSWORD` at a real account in `.env` and it sends
  for real with zero code changes — confirmed this delivery by running the
  actual dispatch job against a real `approved` row with no SMTP
  configured and observing the expected graceful failure.
- **A management UI for T&C clauses and payment presets themselves** is
  out of scope — both are edited via direct SQL, matching the PHP build.
- **Amendment fields are bounded** to what the spec's worked example
  needs (advance %, balance trigger option + days, balance amount) —
  amending a field outside that set (Incoterm, currency, …) isn't wired up.
- **Automatic email-based escalation for overdue alerts** — `checkAlerts.js`
  creates in-app notifications only, matching the PHP `check_alerts.php`
  it replaces; wiring the same conditions to outbound email is a small,
  mechanical follow-up against `emailService`, not built here to keep this
  phase bounded.
- **Login codes are e-mail only** (owner decision D-265, 7 Nov 2026): every
  staff member and every buyer gets a 6-digit code by e-mail at each login;
  MD / ED / Admin can make backup codes on My Account. `smsService` is kept
  but not used for login codes.
- **DOCX generation stays QT/PI/OC-only** — the other 8 document types are
  PDF-only, matching the PHP build (none of their templates carry the
  "content-parity internal Word copy" requirement QT/PI/OC had).
- **Excel report export** — CSV only, same reasoning as the PHP build
  (native `.xlsx` with formulas/formatting would be a real addition, not a
  drop-in swap, and every report query already returns plain rows
  independent of the export format).
- **Scheduled/emailed delivery of a saved report** — saved report
  definitions re-run on demand from `/reports`; nothing sends one out on
  its own schedule yet.
- **Editing an existing user's name/email/role** from `/users` — only
  create/list/deactivate/reactivate/force-reset are wired up.

## Verifying this delivery yourself

Every claim above was checked by actually running the thing, not by
reading the code and assuming it works — the same standard the PHP
delivery was held to:

- Every new/changed file passed `node -c` (syntax check) before being
  considered done; there is no file in this delivery that hasn't been
  parsed successfully by Node itself.
- **Full Stage 1→9 order lifecycles, live over real HTTP** (cookies +
  scraped CSRF tokens, against the running Express server and a real
  MySQL database, exactly as a browser would) — one CIF order walked from
  client creation through all 9 stages and all 10 document types (11
  `documents` rows, since the QT was regenerated once), with every
  generated PDF rendered to an image (`pdftoppm`) and visually inspected,
  including the DRAFT→FINAL watermark swap on review approval.
- **Phase D workflows, end-to-end:** assigned a reviewer, approved as that
  reviewer, confirmed the FINAL watermark replaced DRAFT on a freshly
  rendered file with the original draft left untouched on disk; composed
  and requested an email send, approved it as Level 2, ran the actual
  dispatch job and confirmed it attempted the send and marked the row
  correctly (failing gracefully with no SMTP configured, exactly as
  designed); ran the amendment lifecycle (request → MD approval → generate
  the Amendment Agreement PDF → upload a signed copy → activate) and
  confirmed the new advance/balance terms actually appear in a
  freshly-generated Proforma Invoice afterward; raised a dispute and
  attached a document to it.
- **Background jobs, exercised on all four alert branches, not just the
  no-op path:** ran `checkAlerts.js` against the real database (LUT/RCMC
  dates far in the future → correctly no-op), then deliberately inserted a
  test overdue dispute and confirmed a `dispute_overdue` notification was
  created for the right recipient and correctly *not* duplicated on an
  immediate re-run; deliberately backdated a freight document and cleared
  a payment-status flag to confirm the `fdn_overdue` branch also fires and
  dedups correctly. All test rows were removed and every touched column
  restored to its exact prior value afterward. Ran `dispatchDeferredEmails.js`
  against a real `approved` email_log row pointing at a real generated PDF
  and confirmed it correctly attempted the send and marked the row
  `failed` (no SMTP configured in this build environment).
- **`src/jobs/scheduler.js`** — confirmed `node-cron` 4.6.0's actual
  installed API (`schedule()`/`.stop()`/`.getStatus()`) before writing
  against it, then started and stopped it directly and confirmed both
  tasks reported status `idle` (running/waiting, not `stopped`) while
  active. Confirmed `RUN_JOBS_IN_PROCESS=true` correctly starts the
  scheduler alongside the web server (checked its startup log line) and
  that the server still serves requests normally either way, before
  restoring the server to its default (jobs-off) mode.
- **Password expiry**, live: backdated a real account's
  `password_changed_at` to 100 days ago against the seeded 90-day policy,
  confirmed the very next request 302-redirected to
  `/force-password-change`, confirmed `force_password_change` flipped to
  `1` and a `PASSWORD_EXPIRED_FORCED_CHANGE` audit-log row was written
  with the exact day count and no redirect loop on the force-change page
  itself — then restored the account to its original state.
- **Sample Data Playground:** loaded 2 sample clients/orders (one pushed
  through to Stage 5 with 3 real documents generated), confirmed via
  direct SQL count that clearing it removed every sample-flagged row
  across every affected table while the one pre-existing real client/order
  (from this delivery's own Stage 1→9 lifecycle test) was completely
  untouched, confirmed by id and row count before and after.
- **Database backup/restore**, live: ran `backup_db.sh` against the real
  dev database, confirmed the resulting `.sql.gz` contained all 53 tables
  (52 app tables + the sessions table), then actually restored it into a
  scratch database and confirmed both the table count and a real row count
  matched — not just that the script exits 0.
- `docs/schema.sql` + `docs/seed.sql` are byte-identical copies of the
  PHP delivery's own already-verified files (52 tables, 76 foreign keys,
  applied cleanly to a fresh database as part of this delivery's own setup).

Before packaging, all test rows created purely for this verification
process (a test dispute, a test document/freight row, backdated timestamps,
sample client/order data) were removed or restored to their original
values — the one real client/order this delivery's own lifecycle test
created remains, exactly as the PHP delivery's own dev database also
retained its one real test client/order.

## About the node_modules folder in this delivery

This package ships with `app/node_modules/` **already built and
included** — the same "no command-line step required" goal the PHP
delivery's bundled `vendor/` folder served, adapted for npm. It contains
real, complete copies of every dependency in `package.json` (Express,
mysql2, bcrypt, Nunjucks, puppeteer-core, docx, nodemailer, node-cron,
multer, knex, and their own sub-dependencies) — about 68MB uncompressed.
You do **not** need to run `npm install` to deploy this.

**One thing to check:** `bcrypt` (used for password hashing) ships
prebuilt native binaries rather than one pure-JS file, selected for your
exact OS/CPU architecture. This package was built and tested on
**Linux x86_64 (glibc)** — the overwhelming majority of VPS providers
(DigitalOcean, Linode, Hetzner, AWS EC2 x86_64, most others, running
Ubuntu/Debian/AlmaLinux) match this exactly, and need nothing extra. If
your target server is **ARM64** (AWS Graviton, Oracle Ampere, a Raspberry
Pi) or **musl-based** (Alpine Linux), the bundled binary won't load; fix
it with:
```bash
cd app && npm rebuild bcrypt
```
which fetches the correct prebuilt binary for your actual platform (no C
compiler needed — `bcrypt` ships prebuilds for linux-arm64, linux-arm, and
musl variants of both, in addition to the glibc x64 build included here).
This needs outbound internet access on the server for that one command;
everything else about the deployment works fully offline once extracted.

You would only need Composer's Node equivalent — running `npm install`
for real — if you want to upgrade a dependency to a newer version or add
a new one of your own. `package-lock.json` is included so that install
would be fully reproducible.

## RBAC matrix note

The roles/permissions seeded in `docs/seed.sql` (Admin, Managing Director,
Export Executive, Accounts Executive, Logistics Executive, Viewer/Auditor)
are a first-cut working default, not a verbatim transcription of a
role-by-role table from the spec — same note the PHP delivery made, since
it's the same seed data. Review the matrix against your real org chart
before going live; it's all DB rows, no code changes needed either way.

**Current matrix (6 Nov 2026):** 24 roles and **94 permissions** in `docs/seed.sql` (the counts below from 30 Oct are kept as history). The up-to-date role list, permission list and which role holds what are in `docs/QA/MASTER_TEST_PLAN.md` (root `docs/QA/`), and every role and every single permission is checked against every staff page by the Q3 matrix (`e2e/tests/qa-roles-permissions-matrix.spec.js`). New since 30 Oct among others: `see_all_divisions`, `manage_divisions` and the **Investor (view only)** role (reports and cockpit only, every edit refused).

**Matrix of 30 Oct 2026 (history):** 8 roles, 63 permissions. Admin and
Managing Director hold all 63; Executive Director holds 61 (every one except
`manage_incentives` and `pay_incentives`). Export Executive, Accounts
Executive, Logistics Executive, Viewer / Auditor and CA / Chartered
Accountant hold the lists in `docs/QA/TEST_PLAN.md` §2. Among the newer
ones: `view_cafin` (Admin, MD, ED, Accounts Executive, CA),
`approve_payment_terms` (Admin, MD, ED), `view_orders` and `view_all_orders`
for the CA (read-only), `manage_incentives` (Admin, MD), `pay_incentives`
(Admin, MD, Accounts Executive) and `view_own_incentives`.

## Final end-to-end verification pass (added 2026-09-21)

Restarted both stacks fresh, confirmed this database's table count (66 —
the extra one versus the PHP stack's 65 is `sessions`, this stack's own
MySQL-backed session store, not schema drift), logged in as the seeded
Admin and hit ten representative authenticated pages — all HTTP 200, no
error output in the server log. Confirmed zero sample-data rows left
loaded and the seeded Admin's password hash restored after this session's
temporary swap for headless-browser login testing.

Also regenerated `NexaCrest_Node_Deployment_Guide.docx` from this
README — see the PHP/MySQL README's matching section for how (a small
PHPWord-based Markdown → OOXML converter, plus a real bug caught and
fixed in that converter itself: PHPWord doesn't escape XML by default).

> **Go-live note (2 Nov 2026, D-241):** the migration files named in the follow-up sections below were folded into
> `docs/schema.sql` / `docs/seed.sql` before the first live installation and removed from `docs/migrations/`
> (git history keeps them). A new installation runs **no** migration — `schema.sql` then `seed.sql` is the whole
> database. Those names stay below only as history of what changed and why. From go-live on, see
> `docs/migrations/README.md` for how a change ships.

## Follow-up (added 2026-10-23): UI Templates/Kanban, Complaints split, two-phase closure, LC & travel-expense tracking

Same delivery as the PHP/MySQL README's matching section — see there for
the full write-up; this stack mirrors every item exactly (same eight new
tables, same 106-table total, same Jest-side coverage at 1407/1407). Worth
calling out here specifically: the Kanban board views use Nunjucks macros
rather than PHP's closures to avoid the same "can't redeclare a view
helper" problem PHPUnit's shared process raises for the PHP stack, and the
UI Template resolution chain is `uiTemplateService.resolve()` here against
the identical `users.ui_template_preference_id` → `company_settings.default_ui_template`
→ `standard` order.

## Follow-up (added 2026-10-24): order assignee visibility, order-page step renumbering, always-materialized Compliance Checklist & Export Benefit Scheme Eligibility, LC preset rename

Same delivery as the PHP/MySQL README's matching section — see there for
the full write-up; this stack mirrors every item exactly (same two new
tables, same 108-table total). Full suite: 1533/1533 Jest, plus a full
Playwright e2e run green on both `BASE_URL`s (40/40 each).

Worth calling out here specifically: fixing the hardcoded-`open` bug on
the "Document Review & Approval" reference card surfaced a real,
previously-latent bug in the order page's own `openAndScrollTo()` JS — a
card nested inside a `.step-group` (Step 8's 8a/8b split, in particular
`#compliance-checklist`) stayed invisible when a direct in-page link (the
"At a Glance" sidebar's "see checklist", the ECGC cross-reference) opened
only the matched card itself and not its closed ancestor group. Fixed by
climbing every ancestor `<details>`, not just the nearest one, in both
stacks' identical inline script.

## Follow-up (added 2026-10-28): 25–28 Oct — order page flow, vendor bills, CAFIN, QA Round 5, PI/CI payment wording

Same delivery as the PHP/MySQL README's matching section — see there for
the full write-up; this stack mirrors every item exactly (same four new
tables, same 112-table total plus this stack's own `sessions` table, 60
permissions, 466 routes). Full suite: 1795 Jest tests, plus the Playwright
e2e suite green against `BASE_URL=http://127.0.0.1:3000`.

**Database changes:** before go-live every migration was folded into `docs/schema.sql` / `docs/seed.sql`
(D-241). After go-live a change ships as a new file in the repository's `docs/migrations/` — see its
`README.md`.

## Follow-up (added 2026-10-29): Zoho Books integration, shipping bill, LC payment received, 2-digit-year references

Same delivery as the PHP/MySQL README's matching section — see there for
the full write-up; this stack mirrors every item exactly (one new table,
`order_zoho_invoices`: 113 tables plus this stack's own `sessions` table,
60 permissions, 471 routes — 158 GET, 313 POST). Full suite: 1,868 Jest
tests in 336 suites, plus the 48-test Playwright e2e suite green against
`BASE_URL=http://127.0.0.1:3000`.

In this stack the pieces are `services/zohoGuard.js` (the `.env` safety
switches), `zohoBooksService.js`, `zohoInvoiceData.js`,
`caSyncService.js` (`runFullSync()`), `zohoRestoreService.js` (the app is
the source of truth for what it sends) and `orderZohoMatchService.js`
(the read-only "App vs Zoho" check at `/orders/:id/zoho-check`); the jobs
are `src/jobs/zohoSync.js` (hourly) and `src/jobs/zohoCleanupTestRecords.js`
(by hand). Tests: `zohoServerGuard.test.js`, `zohoBooksStage1.test.js` …
`zohoBooksStage5.test.js`, `cafinLcAndZohoExpenses.test.js`,
`shippingBill.test.js` — every Zoho request in them goes to a fake Zoho.

**Database:** these changes are part of `docs/schema.sql` / `docs/seed.sql` — the first live install runs
no migration (D-241; the file names above are history). Before switching Zoho on, set the go-live
date in Settings › Company (Zoho Books).

## Follow-up (added 2026-10-30): team incentives and associates

Owner decision 2026-10-30 (mockups approved). `incentiveService` /
`incentiveRepository` / `associateRepository` / `incentiveController`, the
views in `views/incentives/` and Step 12d (`views/orders/_incentive.njk`),
migration `docs/migrations/2026-10-30b-team-incentives.sql` (schema section
CN). An order's team reward (the amount set on the order, else the reward
table for its profit after any associate commission, if it reaches the
minimum and the program is on, plus the new client bonus on a client's
first order) is split by role and fixed when the buyer has fully paid; the
associate's agreement rate applies on the client's first 10 days. Both
become commission cost entries (`order_cost_entries.incentive_kind`), paid
in the incentive month (incentive cut-off day, default the 20th) and sent
to Zoho Books as Commission Expense once paid. Permissions:
`manage_incentives` (Admin, MD — not ED), `pay_incentives` (Admin, MD,
Accounts Executive), `view_own_incentives`. Both stacks now stand at
**119 tables** (plus `sessions`), **231 foreign keys**, **63 permissions** and **487 routes**
(164 GET, 323 POST). Tests: `TeamIncentiveTest` / `teamIncentive.test.js`.
SOP: chapter 20 (Incentives).

> **Replaced by D-245 (4 Nov 2026):** the reward table, minimum profit, new-client bonus, cut-off day and automatic lock are gone — the team incentive is a % of profit after the associate's commission or a fixed amount (global, product or per order), shared over editable roles and locked by the MD (P4). Associate commission is on the invoiced FOB of the goods, full payment only (no pro rata), at the level fixed at the PI, due N days after full payment and paid in the payout run (P3). See SOP 20 and 24.

## Follow-up (added 2026-10-30): CAFIN export (CSV) for all orders

`CafinExportService` (Node: `cafinExportService`) and `GET /ca/cafin-export`
(`view_cafin`): the CAFIN of every order of a financial year (by order date,
filtered by status; test and sample orders left out) as a **summary** CSV
(one row per order) or a **detail** CSV (every CAFIN line, sections A–F),
from the same calculation as the CAFIN PDF. Linked from the CA hub and the
Reports page; each download is audit-logged (`CAFIN_CSV_EXPORTED`); the file
name ends in `_INTERNAL`. The CSV helper now leaves a plain negative number
(e.g. `-1250.50`) as a number instead of quoting it as text. Routes: 487
(164 GET, 323 POST). Tests: `CafinExportTest` / `cafinExport.test.js`.

## Follow-up (added 2026-10-30): stage document notice, Step 8a hint, CA sees all orders

- Each stage's gating button (Steps 4d, 5e, 6c, 7d, 9d, 10f, 11d) now says
  before the click which of the stage's documents still need generating,
  approving or sending, and stays disabled until then
  (`DocumentGenerationService::stageDocumentGate()` / Node
  `stageDocumentGate`, the same rule `stageDocumentBlockedBy()` enforces).
  Tests: `StageDocumentGateNoticeTest` / `stageDocumentGateNotice.test.js`.
- Step 8a's hint now says only the estimated shipment date notifies the
  client.
- The CA / Chartered Accountant role holds `view_all_orders` (read-only):
  migration `docs/migrations/2026-10-30c-ca-view-all-orders.sql`.

## Follow-up (added 2026-10-30): associate agreement on file, buyer PO received date

- **Signed associate agreement.** Associates → Add / Edit takes the signed
  agreement as a PDF with its signing date; one is refused without the
  other (browser: `data-required-if`, extended in the sitewide `app.js` to
  mean "required once that field is filled" when no `=value` is given).
  The list shows a "Signed copy" link. Download `GET /associates/{id}/agreement`
  (`manage_incentives`), every upload and download audit-logged
  (`ASSOCIATE_AGREEMENT_UPLOADED` / `ASSOCIATE_AGREEMENT_DOWNLOADED`); the
  general `/file-store/{id}/download` refuses an agreement file to anyone
  without `manage_incentives`.
- **Buyer PO received date.** Step 4d asks for the date the buyer's PO was
  received (required, not in the future, not before the order date); a
  buyer completing Step 4 through the Buyer Action Link or the portal
  records the day of upload. The associate's 10 days now count from the
  client's first PO received (agreement clause 4.1), falling back to the
  order date for orders confirmed before this change. The date is not
  shown on any generated document.
- Migration `docs/migrations/2026-10-30d-associate-agreement-po-date.sql`
  (`associates.agreement_file_id`, `orders.buyer_po_received_on`, upload
  context `associate_agreement`). **119 tables**, **232 foreign keys**,
  **63 permissions**, **488 routes** (165 GET, 323 POST).
  Tests: `AssociateAgreementAndPoDateTest` / `associateAgreementAndPoDate.test.js`.

## Follow-up (added 2026-10-30): correcting the buyer PO received date

- Under Step 4d's "Buyer PO recorded", **Correct the date received** (or
  **Record the date the PO was received** on an order confirmed before the
  date was asked) takes the date (not in the future, not before the order)
  and a reason of at least 10 characters; audit-logged as
  `BUYER_PO_RECEIVED_DATE_CORRECTED`. Route
  `POST /orders/{id}/buyer-po/received-date` (`manage_orders`, order lock
  guard). An incentive already fixed keeps its figures until it is worked
  out again on the order's incentive card.
- Test fix: `UngatedRoutesTest` builds its router before preparing a
  request, so each test in it passes when run on its own.
- **489 routes** (165 GET, 324 POST). Tests:
  `AssociateAgreementAndPoDateTest` / `associateAgreementAndPoDate.test.js`.

## Follow-up (added 2026-10-31): QA Round 6, batches 1–5

- **Order access (Batch 1).** Disputes, complaints, amendments and the other
  records that belong to an order follow the order's assignment and archived
  rules; reports and a client's page list only orders the user may see; the
  Export Executive's respond permissions open the Disputes / Complaints
  lists; CAFIN download also needs the order; a link that would answer 403
  is shown as plain text.
- **Bad input (Batch 2).** An id in a URL must be a positive whole number
  (else 404); an action on an order or record that does not exist answers
  "not found" after the route's own checks; money, quantity, rate, date and
  range limits; incentive payout and buyer payment-report checks.
- **Simultaneous and repeated saves (Batch 3).**
  - `clients.email` is unique (index `uq_clients_email`, in `docs/schema.sql`;
    history: it came from migration `2026-10-31a-client-email-unique`, D-241). Two saves of one email at once: the second
    gets "Another client already uses this email".
  - A repeated click records and logs once: a payment leg with the same
    amount, day and rate; a payout already paid; payment terms already
    approved.
  - One generation per order and document type at a time (a MySQL named
    lock `nx_docgen_<order>_<type>`); a second one meanwhile is asked to wait.
  - The order details, client and supplier edit forms carry an
    `edit_fingerprint` of the values they were opened with; a save on a form
    opened before someone else's save is refused ("changed by … — reload").
    Saving order details is now audit-logged (`ORDER_DETAILS_UPDATED`).
  Tests: `QaRound6AccessTest`, `QaRound6InputTest`, `QaRound6ConcurrencyTest`
  (Node: `qaRound6Access`, `qaRound6Input`, `qaRound6Concurrency`).
- **Smaller fixes (Batch 4).**
  - Payment terms of a cancelled or lost order cannot be approved; only
    Admin, MD and ED may approve the terms of an order they created (D-117).
  - "Mark all paid" with nothing selected is a warning; two reward-table
    rows with the same profit are refused (`data-unique-among` in app.js).
  - The buyer's payment report needs an issued Proforma Invoice and an
    order that is not cancelled or lost (portal and e-mailed link).
  - A file sent under an unexpected field name is refused with a message
    and the typed text kept (both stacks).
  - Insurance belongs to CIF only (D-049): a CFR/FOB order stores none.
  - Node: urlencoded forms are parsed with qs `allowSparse` (dependency
    `qs` added), so `field[<id>]` keeps its id — dropdown-option edits
    were silently not saved before.
  Tests: `QaRound6SmallFixesTest` / `qaRound6SmallFixes.test.js`.
- **Route coverage (Batch 5, R5-14).** `routeBehaviour.test.js` drives
  each of the 124 routes QA listed as untested (plus 5 found since) through
  the real app as a permitted user and checks what it does — the record
  changed, the audit row, the page — and that a missing id is "not found"
  with nothing written. Its `/sample-data` checks live in
  `sampleDataLoadClear.test.js`, because Jest runs test files side by side
  and a second load/clear would race that file's own checks. Found and
  fixed while writing it: unmatching a bank line, setting TDS on an expense
  and saving an associate reported success for a record that does not
  exist. PHP: `RouteBehaviourTest`.

## Follow-up (added 2026-10-31): rule D-212, Batch 1 — every money record says what it is

Owner hard rule (CLAUDE.md, register D-212): every payment, bill, cost,
payout, refund and benefit carries a correct description, in the app and in
Zoho Books, built from the facts and including the bank reference.

- **Migration** `docs/migrations/2026-10-31b-payment-references.sql`
  (columns only): `order_payment_status.{advance,balance,freight}_bank_reference`,
  `order_lc_details.lc_bank_reference`, `incentive_payouts.paid_via` /
  `paid_reference`, `ca_export_benefits.received_reference`,
  `order_cancellations.refund_paid_at` / `refund_reference`.
- **Forms that now refuse to save without the reference:** the advance,
  freight and balance remittances; LC money received; incentive payouts
  (Paid via + reference, also on "Mark all paid"); export benefit received
  (order page 13g, its correction, CA → Export Benefits); a cancellation
  refund (date paid + bank reference). A staff-added cost needs a
  description of 10 to 255 characters. One check, `bankReference()` in the
  field validator (4–100 characters: letters, digits, spaces, `. / - _ # : ( )`).
- **The description builder** (`PaymentDescription` / `paymentDescription.js`)
  writes the Zoho customer payment's description: "Advance received from
  <buyer> for order <ref> · USD 1,600.00 at ₹84.10 · against Commercial
  Invoice <no.> · bank ref <ref>" (LC: the LC number, the amount, the rupees
  credited after bank charges). The benefit journal's notes add the amount
  and the UTR / e-scrip no.; a paid incentive's Commission cost line (and so
  its Zoho expense) ends "· paid with the salary (payroll), ref …".
- Records saved before keep their data; the new fields apply from the next
  record. Documents (PDF / DOCX) and CAFIN are unchanged.
- Tests: `PaymentDescriptionRuleTest` / `paymentDescriptionRule.test.js`,
  and the Zoho Books stage 2 and 4 tests check the exact wording.

## Follow-up (added 2026-10-31): rule D-212, Batch 2 — the remaining descriptions

- **Migration** `docs/migrations/2026-10-31c-inspection-agency.sql`:
  `order_inspection_fees.agency_name` / `agency_invoice_no`. A fee NexaCrest
  pays needs both; its cost line reads "Pre-shipment inspection fee — <agency>,
  invoice <no.>".
- The automatic cost lines are described from their facts: "Bank loan interest
  (LC order) · LC <no.> · loan ₹<amount> at <rate>%" and "Travel expenses
  (factory visits) — <km> km to the factory, <pct>% of the order value <CUR>
  <FOB> at ₹<rate>" (`PaymentDescription` / `paymentDescription.js`).
- Zoho Books: a supplier bill's line names its Supplier PO; a CI / Debit Note
  gets one internal comment when Books first gets it ("Commercial Invoice for
  order <ref> · <Incoterm> · buyer PO <no.> · <buyer>", from
  `ZohoInvoiceData::summary()` / `zohoInvoiceData.summary()`; not part of the
  invoice fingerprint, so invoices already in Books are not re-sent).
- An expense imported from Zoho without a description shows a red "No
  description in Zoho" badge (CA → Expenses, and the order's linked Zoho
  expenses).

## Follow-up (added 2026-10-31): GST refunds under LUT (owner decision D-213, option A)

- **Migration** `docs/migrations/2026-10-31d-gst-refunds.sql`: tables
  `gst_refund_claims` (period, RFD-01 claim no., filed on, claimed, refused)
  and `gst_refund_credits` (amount, credited on, RFD-05 / UTR reference,
  provisional / final, Zoho journal).
- **CA → GST refunds (LUT)** (`/ca/gst-refunds`; view `ca_module_view`,
  record `inr_actual_edit`): record a claim, the money the bank credited,
  any amount refused; remove a credit only while it is not in Zoho Books.
- **Zoho Books:** `CaSyncService::syncGstRefunds()` / `caSyncService.syncGstRefunds()`
  (part of every full sync) sends each credit once as a journal — the
  deposit account debited, **GST Refund Receivable** credited (the CA adds
  that account) — described by `PaymentDescription::gstRefund()`. Test
  clean-up removes those journals too.
- **The order's cost keeps its GST** (profit and incentive unchanged); Step
  12c shows the GST on the order's tax invoices for information. The Revenue
  Report shows the refunds credited in the period.
- CAFIN section E gains one information line under MARGIN with the same
  GST figure (`CaFinancialSnapshotService` / `caFinancialSnapshotService.js`;
  owner confirmed it twice). The CAFIN CSV export carries it with no amount.
- `app.js` gains `data-not-before` (a date not before another date field).
- SOP chapter `ca-09-gst-refunds.md`.

## Follow-up (added 2026-11-01): Departments, "Log in as" and Preview as (owner decision D-214, phase 1)

- **Migration:** `docs/migrations/2026-11-01a-departments.sql` (new tables
  `departments`, `department_designations`, `user_department_memberships`;
  columns `audit_log.acting_as`, `user_permissions.expires_at`; the new roles
  and their starting rights; existing staff become members of the department
  matching their role, Admin accounts excepted).
- **How a person's role is chosen:** the previewed designation's role, else
  the role of the department membership they logged in as, else their own
  `users.role_id`. Every permission check goes through this, so no call site
  changed. A preview turns off the Super Admin bypass and personal exceptions.
- **Screens:** `/choose-department` (Log in as; until chosen, every other
  staff page redirects there), the "Logged in as" badge with Switch
  department in the menu (`POST /switch-department`), `/admin/departments`
  (`manage_permissions`: departments, designations, Preview the app as —
  `POST /admin/preview-as`, `POST /preview/exit`), and the Departments
  section on the user page (`POST /users/{id}/memberships`,
  `.../memberships/{membershipId}/end`, `manage_users`; founder accounts
  refused). Users & roles › Roles and permissions: an optional **Until** date on an extra
  permission.
- **Audit:** every row written while signed in carries the department the
  person acted as (`acting_as`), e.g. "Procurement Manager · Procurement", or
  "Preview: …".
- **Tests:** `tests/integration/departments.test.js`; e2e `e2e/tests/10-departments.spec.js`. Node keeps the acting department per request in `src/helpers/requestContext.js` (AsyncLocalStorage), opened by `sessionAuth.required()`, so the audit log and permission loads see it without `req`.

## Follow-up (added 2026-11-01): Money separation (owner decision D-214, phase 2)

- **Migration:** `docs/migrations/2026-11-01b-money-separation.sql` —
  `view_selling_prices`, `view_buyer_payments`, `view_purchase_prices`,
  `view_logistics_costs`, `manage_supplier_po`, `settle_orders`, granted by
  department.
- **One rule:** `src/services/moneyVisibility.js` — document type → money class (QT, BUYERPO, PI, OC,
  FDN, CI, AMD, COOPREP selling; CHECKLIST_2_FINANCE buyer payments; SUPPO
  purchase; BLI logistics), stored file → money class (bill scans, signed
  Supplier PO, payment screenshots, LC copy, buyer PO, signed amendment,
  expense receipts = financials), and the flags every view gets as `sees`.
- **Applied to:** document download (also checks the order, every type),
  file-store download, the dossier ZIP, the order page, orders list and
  kanban, catalogue costs, dashboard overdue amounts, the freight-cost report.
- **Re-gated routes:** Supplier PO save / signed / documents / supplier link /
  ack-skip and production status → `manage_supplier_po`; freight remittance →
  `manage_payments`; freight cleared → `clear_payments`; mark fully settled →
  `settle_orders`.
- **Tests:** `tests/integration/moneySeparation.test.js` (role matrix, document and file downloads, order page).

## Follow-up (added 2026-11-01): Procurement (owner decision D-214, phase 3)

- **Migration:** `docs/migrations/2026-11-01c-procurement.sql` — tables
  `purchase_requirements`, `purchase_quotes`; permissions
  `raise_purchase_requirements`, `manage_purchasing`, `approve_purchases`;
  starting approval limits on the Procurement designations.
- **Flow:** Sales raises a requirement on the order (`POST
  /orders/{id}/purchase-requirements`, no prices); Procurement works it at
  `/procurement` and `/procurement/{id}` (quotes, award, approve, receive
  with quality check, cancel); `/procurement/suppliers` is the supplier
  performance view. The award's value is checked against the approval limit
  of the designation the person is working as (`src/services/purchasingService.js`); above it, an
  approver with "Approve purchases" whose own limit covers it approves it
  (never the awarder, unless the maker-checker rule allows).
- **Tests:** `tests/integration/purchasing.test.js`.

## Follow-up (added 2026-11-01): My tasks, owners and cockpit (owner decision D-214, phase 4)

- **Migration:** `docs/migrations/2026-11-01d-coordination.sql` — tables
  `order_department_owners`, `department_task_types`; permissions
  `assign_department_owner`, `view_cockpit`, `manage_task_types`; aligns the
  1 Nov tables to the database collation.
- **Tasks:** `src/repositories/departmentTaskRepository.js` derives each department's open work from order state
  in one query (advance cleared without a requirement, requirements to quote
  / approve / chase, received goods without freight, remittances to clear,
  unpaid bills, documents to send), with due and escalation dates from
  `department_task_types`. A department with task kinds of its own sees its
  own; management, the Order Desk and Audit see all.
- **Screens:** `/my-tasks`, `/cockpit`, `/admin/task-types`, and the Owners
  panel on the order page (`POST /orders/{id}/department-owners`).
- **Tests:** `tests/integration/coordination.test.js`.

## Follow-up (added 2026-11-01): order line types, Export Service Invoice, payment milestones, product categories (owner decision D-215, 5a — confirmed twice)

- **Migrations:** `2026-11-01e-line-types.sql`, `2026-11-01f-export-service-invoice.sql`,
  `2026-11-01g-payment-milestones.sql`, `2026-11-01h-product-categories.sql`.
- **Line types:** each order line is Goods, Service or Charge (services carry a SAC
  code). Goods FOB excludes services; the CI, Packing List, COO and Supplier PO show
  goods only; the Quotation/PI/OC show the service lines.
- **Export Service Invoice (ESI):** a separate document type, PDF and Word,
  generated from the order page when the order has service lines.
- **Payment milestones:** an order may have its own schedule between the advance and
  the balance; each middle milestone is recorded (remittance, bank reference) and
  cleared; the PI/OC list them in section 7; the balance waits for them.
- **Product categories:** Products → Categories (`/products/categories`), each with its own attributes
  shown on the product line (no document change).
- **Tests:** `tests/integration/lineTypes.test.js`,
  `exportServiceInvoice.test.js`,
  `paymentMilestones.test.js`,
  `productCategories.test.js`.

## Follow-up (added 2026-11-01): Standard and Kanban templates kept parallel (owner hard rule)

- Every list page (Orders, Disputes, Complaints, Reorder Requests, Quotation Intake,
  PI Intake, Procurement, My tasks, Associates) offers on its Kanban board every
  record link, action, filter and count of its Standard list, and the other way round;
  each switches to the other ("Switch to list view" / "Switch to board view").
- The Orders board shows 25 cards per column, then "Show more (N more)" (A-5).
- **Tests:** `tests/integration/kanbanParity.test.js` (enforces the rule),
  `kanbanCoverage.test.js`.

## Follow-up (added 2026-11-01): QA Round 7 and the owner decisions of 1 Nov (A, B, C)

- **QA Round 7:** fixes R7-N01 … N16 (milestone bound, client name on lists,
  file links, Node resilience to a lost database connection, amendment date check,
  procurement saved atomically …) with tests first.
- **Owner decisions (D-216):** nobody approves their own payment terms (MD/ED
  excepted, audit-logged), reviews their own document or approves their own e-mail
  send; nobody grants a permission they do not hold or changes their own rights
  (`GrantGuard`); System Administrator has no data export; LC-only zero advance;
  GST refund periods within a financial year; Zoho secrets masked; every order keeps
  an assignee; the daily digest (`src/jobs/dailyDigest.js`, 08:15 IST).
- **Migration:** `2026-11-01i-owner-decisions.sql`.
- **CI:** `.github/workflows/tests.yml` runs PHPUnit and Jest on every push against
  MySQL 8.
- **Tests:** `tests/integration/ownerDecisionsNov1.test.js`.

## Follow-up (added 2026-11-01): the associate system (owner decision D-217)

- **Migration:** `2026-11-02a-associate-system.sql` (tables `associate_tokens`,
  `associate_bank_accounts`, `associate_documents`, `associate_invitations`,
  `associate_events`, `associate_messages`, `associate_codes`; new columns on
  associates, client intake submissions, clients, orders, incentive payouts; the
  standard ladder, TDS %, protection months and bank waiting days in
  `incentive_settings`; upload context `associate_document`).
- **Permissions:** `manage_associates` (MD, ED, Admin), `view_associates` (Sales —
  no amounts), `manage_associate_bank` (Finance Manager/Director, MD, Admin).
- **Associate portal** `/associate` — its own login (password + e-mailed code),
  middleware `associateAuth`: home, my clients (one-off links, personal code,
  registration certificate), orders, commission (statement CSV / print, invoice
  upload), profile (bank change request, documents, agreement), messages. Never
  prices, costs, profit, suppliers, CAFIN or documents.
- **Staff:** `/associates` (Standard list and Kanban board), `/associates/new`,
  `/associates/{id}`, `/associates/ladder`, `/reports/associates`, payouts hold /
  release / claw-back, bank verify (one person by default since D-293, after the associate
  confirms; two people if set) / reject, read-only
  "View their portal".
- **Buyer:** the associate's link `/invite/{token}` or `?code=` is carried on the
  quotation form as a hidden field (no checkbox, no code box); the buyer confirms the
  request with an e-mailed code (`/quotation-details/verify`); staff accept on
  Quotation Intake.
- **Commission:** the rate is fixed on the order at creation; earned when the buyer
  has paid in full; pro rata on short pay; held while suspended; paid only into a
  verified account; sent to Zoho as the associate's bill + vendor payment.
- **Reminders:** in the daily digest job.
- **Tests:** `tests/integration/associateSystem.test.js`, the route, ungated-route and
  Kanban parity tests, `e2e/tests/11-associates.spec.js`. SOP chapter 24.

> **Replaced by D-245 (4 Nov 2026):** the reward table, minimum profit, new-client bonus, cut-off day and automatic lock are gone — the team incentive is a % of profit after the associate's commission or a fixed amount (global, product or per order), shared over editable roles and locked by the MD (P4). Associate commission is on the invoiced FOB of the goods, full payment only (no pro rata), at the level fixed at the PI, due N days after full payment and paid in the payout run (P3). See SOP 20 and 24.

## Follow-up (added 2026-11-01): gap list G-1 … G-6 (owner decision D-218)

- **Migrations:** `2026-11-02b-gap-shipment-gates.sql`, `2026-11-02c-gap-due-dates-lc.sql`,
  `2026-11-02d-gap-part-payments.sql`.
- **G-1:** a "before shipment" balance is recorded and cleared (Steps 11c/11d) before
  the Bill of Lading; Step 10f waits for it.
- **G-2:** a post-BL or LC balance is due from the day the scanned BL is sent (Step
  11b) plus the balance days; `checkAlerts.js` sends a daily overdue reminder.
- **G-3:** Step 8a records the pre-shipment inspection Passed/Failed with its report
  (`order_inspections`); Step 10f waits for a Passed inspection unless it is marked not
  applicable.
- A manager with `override_shipment_gates` (MD, ED, Admin) can release Step 10f with a
  written reason (`order_shipment_gate_overrides`).
- **G-4:** LC issue, last shipment, presentation and expiry dates with reminders
  7 days before each; LC amendments (`order_lc_amendments`, upload context
  `order_lc_amendment`).
- **G-5:** several receipts per buyer payment (`order_payment_receipts`), "still owed";
  clearing short needs `approve_short_payment` (MD, ED, Admin, Finance Director) and a
  reason (`order_payment_shortfalls`). A milestone keeps one receipt.
- **G-6:** with e-mail off, the OC's 48 hours start at "Mark Order Confirmation Sent to
  Buyer".
- **Tests:** `tests/integration/gapShipmentGates.test.js`, `gapDueDatesLc.test.js`,
  `gapPartPayments.test.js`.

## Follow-up (added 2026-11-01): CFR wording (C-16, confirmed twice) and Q-1 … Q-5 (D-219)

- The "Shipment Booking (CFR/CIF)" clause asks for freight only on CFR orders, freight
  and the insurance premium on CIF (`2026-11-02e-cfr-wording.sql`, changes only an
  unedited clause). The Buyer PO's freight sentence follows the incoterm (CIF
  freight & insurance, CFR freight with "insurance arranged by the buyer", FOB none),
  PDF and Word.
- **Tests:** `tests/integration/cfrWording.test.js`.

## Follow-up (added 2026-11-01): placeholders, self-approval rules, rate carry-forward (D-220, D-221) and QA Round 8 (D-222)

- **Migrations (run in this order):** `2026-11-02f-self-approval-rules.sql`,
  `2026-11-02g-qa-r8-associates.sql`, `2026-11-02h-qa-r8-approvals.sql`. All are safe to
  run more than once. (History — now part of schema.sql / seed.sql, D-241.)
- **Placeholders and labels (D-220, R8-N08):** every typed input and text area shows a
  placeholder with an example or the expected format; every visible field has a label.
  The sitewide ``public/js/app.js`` (`markRequiredLabels()`) puts " *" on the label of every required
  field (never a second one) and removes it when the field stops being required.
  Required drop-downs with no sensible default start with "Choose…".
- **Self-approval rules (D-221, R8-N09):** Admin → **Self-approval rules**
  (`/admin/self-approval`, Super Admins only). It covers seven rules:
  - approve the payment terms of an order they created;
  - review a document they generated;
  - approve an e-mail send they requested;
  - approve or reject the cancellation of an order they requested;
  - approve a purchase award they made;
  - MD-approve an amendment they requested;
  - clear short a buyer payment whose receipts they recorded.

  Each rule defaults to Managing Director and Executive Director. A person qualifies only
  through one of their own roles; Super Admin and `manage_permissions` are no longer an
  exemption. ``makerCheckerGuard`` → ``selfApprovalRules`` is the one check for every self-approval. Each
  self-approval is audited (`PAYMENT_TERMS_SELF_APPROVED`, `DOCUMENT_SELF_REVIEWED`,
  `EMAIL_SEND_SELF_APPROVED`, `ORDER_CANCELLATION_SELF_APPROVED`,
  `PURCHASE_AWARD_SELF_APPROVED`, `AMENDMENT_SELF_APPROVED`,
  `SHORT_PAYMENT_SELF_APPROVED`), and so is every rule change
  (`SELF_APPROVAL_RULE_CHANGED`). The Assign reviewers list leaves out the document's
  generator unless their role may self-review; then the option reads
  "(generated it — self-review)".
- **Associate rates (D-221, R8-N05):**
  - An empty (or 0) personal rung carries on the last personal rate set below it.
  - "Client no. N" is now fixed on the client (`clients.associate_client_number`), in
    the order the clients were linked.
- **Approval limits (R8-N10):** the new permission `manage_approval_limits` (Managing
  Director, Executive Director; Super Admins as usual) is needed to change a designation's approval limit or
  make it 'director'. Nobody can do this on a designation they hold themselves
  ("You cannot change the approval limit of a designation you hold yourself."). Each
  change is audited (`APPROVAL_LIMIT_CHANGED`).
- **Money saved once (R8-N11, R8-N12):** GST refund credits and associate claw-backs are
  saved with the claim or payout row locked, and the open amount is re-checked inside
  the lock.
- **Safe CSV (R8-N03):** every CSV, including the associate portal's commission CSV, goes
  through `helpers/csv.js` (`stream`). A cell starting =, +, - or @ is written as text.
- **Invitation links (R8-N04):** a one-off invitation link is used up by the first
  registration sent through it.
- **Suspended associates (Q-R8-2):** a suspended associate gets a read-only portal.
- **Bank e-mails (R8-N01):** bank e-mails say "No payment can be made until new bank
  details are verified." when no verified account is in use.
- **Bill of Lading (R8-N13):** the number of original BL copies is required, 1 to 10.
- **Daily digest (R8-N02):** the digest counts only e-mails that actually left. With
  e-mail sending off it reports "Daily digest: e-mail sending is off — nothing sent." and
  the day is not used up.
- **Tests:** `tests/unit/formPlaceholder.test.js`, `tests/unit/formLabelConventions.test.js`, `tests/integration/selfApprovalRules.test.js`, `selfApprovalActions.test.js`, `qaRound8Part1.test.js`, `qaRound8Part2.test.js`, `associatePortalRoutes.test.js`, plus updates to `makerCheckerGuard.test.js`, `departments.test.js` and `associateSystem.test.js`. The 15 routes that had no behaviour test (D-211) now have one.

## Follow-up (added 2026-11-02): owner's 2 Nov list — documents, money safety, compliance, agreements, remittances

Migrations, in this order after `2026-11-02h`: `2026-11-02m-generated-file-links.sql`, `2026-11-02n-document-copy-cleanup.sql`, `2026-11-02r-export-compliance.sql`, `2026-11-02q-remittance-difference.sql`, `2026-11-02s-agreements-register.sql`, `2026-11-02t-remittance-difference-reasons.sql`, `2026-11-02u-reference-library-payment-guide.sql` (history — now part of schema.sql / seed.sql, D-241). Schema: **148 tables** at that point (**153** at go-live, after D-238 – D-240).

- **Documents (D-223, D-224 — owner confirmed twice):** Quotation and Proforma Invoice have no untitled CoO / Port of Discharge table; the PI prints Buyer's PO / Ref No. in Buyer Details only when entered; the Order Confirmation shows the Buyer's PO; the Debit Note's "To (Buyer)" is the full buyer block; BL Instruction section 8 no longer gives payment terms ("Original BL release: CHA to hand all 3 originals to NexaCrest only"); the Commercial Invoice says "the value declared on the Indian Shipping Bill" (no "IGST refund") and prints the total in words (`src/helpers/amountInWords.js`). PDF and Word alike; the reference Word files (`reference_templates/`) match. Tests: `quotationPiLayout.test.js`, `documentWordingD224.test.js`.
- **Freight in rupees (D-225):** Step 9a freight and insurance are order currency; `orderProfitabilityService.js` converts them (freight receipt rate → freight leg → advance/balance rate), flags a missing rate instead of adding foreign units as rupees, and the working line ("EUR 950.00 × 90.50 = ₹85,975.00") shows in Step 12c and CAFIN C1. Fixed incentives are not recalculated. Test: `freightCostInInr.test.js`.
- **Money never leaks (owner points 6/15):** every generated file carries `file_store.linked_document_id`, so drafts, superseded copies and older CAFIN copies follow the same money rule as the current copy (download, dossier ZIP, All Documents); `src/services/auditMoneyMask.js` hides amounts in the Audit Log, an order's audit log, the order report's Full Audit Trail (and their CSVs) and the dashboard's Recent Activity; reports with selling prices or buyer payments refuse people who may not see that money; product list prices need selling rights; document counts count only what the person may see. Proof: `e2e/tests/12-money-matrix.spec.js` (every role × ~75 screens, files, ZIP; 0 violations). Test: `moneyLeakClosures.test.js`.
- **All Documents panel:** generated documents and every uploaded file of the order, only those the person may open (Sales sees no supplier or logistics papers). Tests: `orderAllDocumentsPanel.test.js`.
- **Bill forms and guide:** Steps 7e/10a start from the Supplier PO (taxable value, GST rate, amount asked now); Step 9a pre-selects the forwarder already on the order; `/help/supplier-logistics-payments` explains supplier and logistics payments with screenshots; the 21-page *Supplier & Logistics Payments Guide* (PDF + Word) is in `docs/guides/`, `docs/SOP/guides/` and the Reference Library (`storage/assets/reference_library/supplier_and_logistics_payments_guide.*`). Tests: `billPrefillAndForwarder.test.js`, `helpGuide.test.js`.
- **Order page (points 10/13/14/16):** no associate controls when the client has no associate; Step 7c starts with the Supplier Link; the COO Prep Sheet can be generated once Stage 8 is open (before the balance clears); "Zoho Sync" in the menu and "Sync this order to Zoho now" (`POST /orders/:id/zoho-sync`, `ca_module_manage`, also on closed orders) next to the Zoho check. Test: `orderPageOwnerPointsNov2.test.js`.
- **Document copies clean-up (points 5/8/9):** Settings › Document clean-up (`/settings/document-cleanup`), off by default: drafts of approved documents, never-sent superseded revisions, older CAFIN copies, after a chosen number of days, with preview and audit; never anything sent to the client, current copies, uploads, open disputes or CA-locked years. The exact PDF the buyer received is now marked `sent_to_client`. Job `src/jobs/documentCopyCleanup.js` (daily 02:15 IST). Test: `documentCopyCleanup.test.js`.
- **Export Compliance department (D-226):** Executive / Manager / Director roles for ECGC, COO, phytosanitary/fumigation/inspection, benefit claims and eBRC follow-up; permissions `manage_compliance_checklist`, `manage_export_benefit_claims`, `generate_compliance_documents`, `upload_order_documents`; its own My tasks queue; no purchase, logistics, buyer-payment, profit, incentive or CAFIN figures. SOP 25. Test: `exportCompliance.test.js`.
- **Remittance differences (D-227):** the advance, freight, balance and milestone remittance forms ask "Why is it different?" from Admin-managed reasons (Settings › Lists › Remittance difference reasons) or "Other — type the reason"; bank charges become an order cost "Bank Charges" (D-212 description, Zoho `bank_charges`) and the leg counts as fully received (cap `max_bank_charges_pct`, 3%); short and excess are recorded and explained. Tests: `remittanceDifference.test.js`, `remittanceDifferenceReasons.test.js`.
- **Agreements register (D-228):** `/agreements` (Standard + Kanban) for client, supplier, logistics partner, associate and staff agreements — signed copy, dates, renew, end early; reminders 30 / 7 / 0 days before expiry from `src/jobs/checkAlerts.js`; a card on each party's page; client and associate agreements listed automatically. Permissions `manage_agreements`, `view_agreements`. SOP 26. Test: `agreementsRegister.test.js`.

### TDS on associate commission (D-235, added 2026-11-02)

- Migration `docs/migrations/2026-11-02x-associate-tds.sql` (after 02v).
- The app works out the TDS on each associate payout: section 194H at 2 % once the associate's commission in the financial year passes ₹20,000 (catch-up on the crossing payment), 20 % (206AA) without an accepted PAN, none for associates abroad once TRC / Form 10F / no-PE are accepted (each payment abroad needs Form 15CA, and 15CB when required). Values: Settings › Lists › Associate TDS rules (`/settings/associate-tds`). Register by quarter with challans: `/associates/tds`.
- Reminders to *Pay incentives* holders come from the existing daily alert job (`check_alerts`): deposit by the 7th, Form 26Q after each quarter.
- **Deployment note for the CA:** to have Zoho Books book the TDS on the associate's bill, the CA turns on TDS in Zoho Books (Settings → Taxes → TDS in Zoho, a manual Zoho change — log it in `docs/zoho/zoho-change-log.csv` when done) and puts the 194H tax's id into Settings › Lists › Associate TDS rules → Zoho Books TDS tax id. Until then the bill is the gross, the payment the net, and the Sync Log says the TDS is left on the bill for the CA.

### Follow-up (added 2026-11-02): clients and products in Zoho Books (D-238)

Migration `docs/migrations/2026-11-02za-zoho-clients-products.sql` (history — now part of schema.sql / seed.sql, D-241). It adds two switches under **Settings › Zoho**, both **off**: `zoho_sync_clients` (clients as Zoho Books customers) and `zoho_sync_products` (products of the Products menu as Zoho Books items, rate 0 — no cost is ever sent). One way, app → Zoho, done by `app/src/services/zohoMasterDataSync.js` inside the existing hourly Zoho sync (`app/src/jobs/zohoSync.js`) and Sync now — **no new scheduled job**. "Re-push all clients and products" on the Zoho Books Sync page (`POST /ca/zoho-sync/repush-master-data`, `ca_module_manage`) sends everything again. Nothing is deleted in Zoho: inactive or deleted records are marked inactive there. A client goes once its first order has set its currency. See SOP `ca-03-zoho-sync.md` → "Clients and products in Zoho Books". Tests: `ZohoClientsProductsSyncTest` / `zohoClientsProductsSync.test.js`.

### Follow-up (added 2026-11-02): Zoho API call limits for this app (D-239)

Migration `docs/migrations/2026-11-02zb-zoho-api-limits.sql` (history — now part of schema.sql / seed.sql, D-241). Zoho limits API calls **per organisation** (about 100 a minute and a daily number by plan) and every Zoho app of the company shares that limit, so this app keeps to its own share: **Settings › Zoho** → `zoho_api_daily_limit` (**800**) and `zoho_api_minute_limit` (**30**). Every Zoho Books call — reads, the OAuth token and sends — is counted in `zoho_api_usage` by `app/src/services/zohoApiBudget.js` (meter) and `zohoBooksService.request()` / `getAccessToken()`. When the day's allowance is used, syncing pauses until midnight (India time); the scheduled sync (`src/jobs/zohoSync.js` (in-process scheduler or external cron)) waits out the per-minute limit, while Sync now / Sync this order / the Zoho check stop and ask to try again in a minute. Zoho's own "too many requests" (HTTP 429, codes 44/45, the OAuth "too many requests continuously") **stops the run at once** with one Sync Log line and one failure e-mail (D-233); nothing is lost. The access token is shared (encrypted, `zoho_token_cache`) by every process for 50 minutes and refreshed under a database lock. Usage today and the last 7 days are on the Zoho Books Sync page. See SOP `ca-03-zoho-sync.md` → "Zoho's call limits". Tests: `zohoApiLimits.test.js`.

### Follow-up (added 2026-11-02): Logistics enters logistics bills, Accounts pays (D-232)

Part of schema.sql / seed.sql (D-241). Permission `manage_logistics_bills`: add a CHA / transport / freight-forwarder bill (number, date, taxable value, GST, scan, proforma or tax invoice, the proforma it replaces) and remove one that has no payment. Held by Logistics Executive, Manager and Director and by every role that holds `manage_supplier_payments`. **Recording a payment (amount, UTR) stays with `manage_supplier_payments`** (Finance / Accounts / management) — a Logistics login sees "Accounts records the payment." and the pay route answers 403. The Logistics Partner Bills card shows to anyone who may see logistics costs. Buyer remittance forms (advance, milestone, freight, balance) also have an optional **"Our bank's charges on this credit (₹, incl. GST)"**, booked at once as an order cost "Bank Charges" with a D-212 description; it is not sent to Zoho (the CA books bank charges from the bank statement). Tests: LogisticsBillsAndOwnBankChargesTest / logisticsBillsAndOwnBankCharges.test.js.

### Follow-up (added 2026-11-02): reference selling price with discount approval (D-240)

> **Replaced by D-245 P2 (4 Nov 2026):** the reference selling price, its note settings, the order-level discount approval (`order_price_approvals`, permission `approve_price_discount`) and the Zoho item rate from it are gone. "Pick from Products" now fills the quote price of whoever sells the order (staff, or the client's associate at their commission level) from the product's price build-up; a line below the seller's floor goes to the MD (permission `manage_pricing`), who may approve it down to the minimum price and never below; the PI waits for it and fixes the figures on each line. See docs/SOP/27-pricing.md and register D-245.

Part of schema.sql / seed.sql (D-241). **Products menu:** a product may carry a reference selling price with currency and unit (e.g. "USD 45.00 per SQFT"), seen and changed only with View selling prices (`view_selling_prices`). **Order lines:** "Pick from Products" fills description, HS code, unit and — when the currencies match — the unit price; the line's own price stays editable and **every document prints the line's own price**. **Note beside the price** (selling-visible staff only; never on documents or the client portal), set under **Products › Pricing › Pricing settings**: on/off (`ref_price_note_enabled`), note level (`ref_price_note_pct`, default 5 %), warning level (`ref_price_warning_pct`, default 10 %). **At or beyond the warning level the Proforma Invoice cannot be generated** until someone with `approve_price_discount` (MD, ED) approves the discount with a reason on the order page's Price check (inside the Products card); a further drop or a new line below the warning level cancels the approval; the self-approval rule `price_discount` decides whether the person who set the price may approve it. **Zoho (D-238):** an INR reference price becomes the Zoho item rate; another currency leaves the rate at 0 and puts "Reference price: …" in the item description; invoice lines carry the Zoho item when the line's product is already in Zoho. Tests: ReferencePriceTest / referencePrice.test.js.

## Follow-up (added 2026-11-06): divisions, category documents, Zoho for the new divisions, and the full QA programme (D-257, D-258)

Everything below is in `docs/schema.sql` / `docs/seed.sql` (no migrations, D-241). **Nothing changes for today's users:** only Export is active, and no division label, switcher or filter shows until a second division is activated.

**Size now (both stacks, rechecked 6 Nov 2026 after access levels, incentive caps and backups):** 194 tables, 24 roles, **94 permissions** (older sections above quote 60 or 63 — those were the counts at the time), 744 routes (247 GET, 497 POST).

- **Divisions (D-257, SOP 28):** every order, user, department, client, associate and price request belongs to a division. Export (EXP) is active; Domestic Trade (DOM), Import & Distribution (IMP) and Merchant Trade (MTT) are built but **planned**. Settings › Divisions (MD only, `manage_divisions`) shows each division's modules, own settings and number formats, a set-up section (create its departments, move staff) and an **Activate** button with a pre-flight checklist. Company-level users get `see_all_divisions`. New role **Investor (view only)**. Settings read the division's own value first, then the company value. Staff of a division that is not active cannot save anything.
- **Product categories (D-257 0B, SOP 27):** each category has units, HS code, packing type, quality documents and a T&C clause set; per-piece / per-kg / per-set prices; a quality-document checklist on the order. Documents take their packing words, quality documents and T&C clauses from the category (Phase 1). **Natural Stone documents are pinned** and proven text-identical by `StoneDocumentsUnchangedTest` / `stoneDocumentsUnchanged.test.js`.
- **Domestic Trade (SOP 29):** `/domestic/orders/…`, GST rates (Settings › Lists › GST rates), GST tax invoice / challan / quotation / OC PDFs, e-way bill fields.
- **Import & Distribution (SOP 30):** `/import…`, Settings › Lists › Import duty, landed cost, stock with movement log, local sale from stock.
- **Merchant Trade (SOP 31):** `/merchant…`, two-leg deals, RBI checks with daily reminders (in `check_alerts`), PO and sale invoice (`MTT/I/26-27/0001`, 16 characters). The selling side is hidden from Procurement.
- **Overhead sharing:** the Order Profitability report shows *Profit by division* only when two or more divisions are active.
- **Zoho Books for the new divisions (D-257 (6), SOP ca-03):** invoices, bills, receipts, payments, customs duty, clearance charges and the IGST credit journal sync automatically in the hourly Zoho job; *Check against Zoho Books* on each domestic order, import and deal. Live-tested in the TEST organisation (change log Z-643 to Z-698), including go-live item 0C.2 (the GST commission bill). **Only one sync runs at a time** (database lock), and payments, expenses and journals are looked up in Books before posting, so a retry never duplicates (D-258).
- **QA programme (D-258, `docs/QA/MASTER_TEST_PLAN.md`):** 107 bugs that no test covered were found and fixed in four batches (access, UI, orders, documents, e-mail, money, Zoho, stack parity); the deferred few are listed in the register (O-22). New tests: `QaBatch1AccessUi`, `QaBatch2`, `QaBatch3DocsMail`, `QaBatch4`, `DivisionIsolation`, `DomesticTrade`, `ImportDistribution`, `MerchantTrade`, `DivisionZohoSync`, `DivisionOverheadAndInvestor`, `CategoryDrivenDocuments`, `StoneDocumentsUnchanged`, `D257ProductNeutral`, `FreshReviewFixes`, the incoterm × preset and mail matrices (`QaIncotermPresetMatrix`, `QaMailMatrix`), and e2e `13-qa-batch1`, `14-qa-batch2`, `qa-roles-permissions-matrix`, `qa-ui-crawl`.
- **Behaviour changes staff will notice** (all in the SOPs): Preview as is look-only; a payment with a different amount can be saved again; Mark Lost is refused once money has come in; a cancellation refund needs *Record refund paid* with the bank reference; Close Order needs the original BLs received and endorsed (ORD-14); the CI balance is net of amounts written off (ORD-09); export benefits are always in INR; the *sending off* switch now covers every business e-mail; old document revisions show what was really issued; the Word copy matches the PDF; Node prints dates as "05 November 2026" like PHP.
- **Team incentive eligibility and caps (D-259, SOP 20):** an MD-only "Eligible for team incentive" switch per person (Add / Edit user) and per designation (Users & roles › Departments), and caps per role per order and per month (Finance & accounts › Team incentive settings). What a person does not get stays with the company; only orders not yet locked change.
- **Associate access levels (D-261, SOP 24):** Starter / Quoting / Full / Custom per associate (MD only, with a reason), the level matrix under Associates › Access levels (FOB value and commission % off for Starter by default), a track record panel and an upgrade suggestion after 3 completed, fully paid orders. New associates start on Starter.
- **Backups (D-260, SOP 32):** see "Backups" above — encrypted database and document copies kept 10 years, Settings › Backups, a digest warning when a copy is late.
- **Questions for the CA, AD bank and lawyer:** `docs/external/`.
- **Cron:** the same app jobs plus the three backup lines (database, documents, monthly test-restore); run them in IST (`CRON_TZ=Asia/Kolkata`). The daily alert job runs at 06:00 IST on both stacks.
- **Go-live:** `docs/guides/GO_LIVE_CHECKLIST.md`. Still pending from others: the CA (GST for associates abroad; DOM/IMP GST, e-way bill, e-invoice and duty rates; Zoho account names), the AD bank (merchanting rules and approval) and the lawyer (Associate agreement, Schedule A, the new non-stone T&C clauses).

## Follow-up (added 2026-11-07): associate payments, login codes, SOP access, document images (D-264 … D-267)

Everything below is in `docs/schema.sql` / `docs/seed.sql` (no migrations, D-241) and in the
Application Handbook. Go-live steps are in `docs/guides/GO_LIVE_CHECKLIST.md`.

- **Login code for everyone (D-265, SOPs 00, 12, 15):** every staff member and every buyer enters
  a 6-digit code e-mailed after the password, at every login (10 minutes, 5 tries, a new code at
  most once a minute). It cannot be switched off per person. **E-mail must work before the first
  login.** MD, ED and Admin make 10 emergency **backup codes** on My Account right after their
  first login and keep them offline; each use is audited and the MD is told. *Settings ›
  Security* (`login_remember_browser_days`) was **0 = off** by default (code every time);
  staff only, never buyers or associates. Since 11 Nov 2026 (D-283) the tick reads "This is my own
  office computer" and the default is 30 days — see the 11 Nov section. Associates keep their own code.
- **SOP access by role (D-266, SOP 15):** *Users & roles › SOP access* — each role sees its own chapters
  (generous defaults are seeded), extra chapters per person on *Users › Edit*, an on/off switch
  for the whole library and for each chapter. MD, ED, Admin and System Administrator always see
  everything; *Getting started* is always visible; a new chapter file is seen only by them until
  it is ticked for roles.
- **Associate payments (D-264, SOP 24):** commission is shown to associates in the order currency
  (USD); associates based in India also see one fixed rupee line once it is fixed. The commission
  is **fixed automatically** when the buyer has paid in full (rupees at the rate of all buyer
  payments; it never changes afterwards), becomes due after the set days, and is held while a
  buyer claim is open; the MD's lock now applies to the team incentive only. India: paid in INR.
  Abroad: USD / EUR / GBP. Bank charges are never deducted. Set in *Associates › Commission
  settings*: **transfer charges abroad** (OUR = NexaCrest pays all charges, default; or SHA),
  **minimum payout abroad** (USD 100; smaller amounts carry to the next run), and the **Zoho
  account for "Associate payout costs"**.
- **Associate payout costs in Zoho (D-264, SOP ca-03):** for an associate abroad, staff record the
  rupees the bank debited and the charges. The difference against the fixed commission (exchange
  movement + charges) is a company cost, never an order cost, and each paid payout with a
  difference is posted to Zoho Books as one journal (*Associate payout costs* debited, the paying
  bank credited; a gain the other way round), reference `<inquiry ref>-APC-<payout id>`, with the
  D-212 description. **Before go-live: create an expense account "Associate payout costs" in Zoho
  Books and choose it in *Associates › Commission › Commission settings and ladder*;** until then the sync skips these
  journals and says so in the Sync Log. Checked live in the Zoho test organisation on 7 Nov 2026
  (change log Z-699 … Z-711).
- **Document images stored once (D-267, owner double-confirmed; SOP 13):** a document's stored
  snapshot keeps each image (seals, signature, logo, watermark) once, by SHA-256, in the table
  **`document_image_blobs`**. Database triggers refuse any UPDATE or DELETE on it, so it is
  **permanent** — it is part of the normal database backup and must be restored with it. Rebuilding
  a final or superseded copy checks every image and stops with a clear error if one is missing or
  changed; it never uses a different image. The generated PDFs are unchanged. The schema creates
  **database triggers** (also the founder-login protection), so the database user that imports
  `schema.sql` needs the **TRIGGER** privilege — "ALL PRIVILEGES" on the database includes it. On
  MySQL with binary logging switched on, creating a trigger may also need SUPER or
  `log_bin_trust_function_creators = 1` (ask the host if the import stops at a `CREATE TRIGGER`).
- **Data export (Settings › Data export):** the temporary dump file is now always deleted, also when a
  download is cancelled or the connection drops.
- **Tutorial videos** (`tutorials/`, catalogue in `tutorials/VIDEO_PROGRESS.md`): videos are never
  committed; a change that alters a recorded video is listed for the owner before any re-recording
  (CLAUDE.md).

## Follow-up (added 2026-11-11): the 27-point build — menus, approvals, e-mail settings, sessions, pricing, associates, setup checklist (D-268 … D-330)

Everything below is in `docs/schema.sql` / `docs/seed.sql` (a new install needs nothing else) and in
the Application Handbook; the SOP chapters say how staff use it. Go-live and upgrade steps are in
`docs/guides/GO_LIVE_CHECKLIST.md`, section "Upgrade of 11 Nov 2026".

**Upgrading an existing (live) database.** Unlike the first install (D-241), a database that already
holds data is brought up to date with the dated files in `docs/migrations/` (rules in
`docs/migrations/README.md`). Back up the database and `storage/` first, put the new code in place,
then run, in this order:

```bash
mysql -u <user> -p <database> < docs/migrations/2026-11-11a-quote-any-unit-piece-size.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11b-email-settings-sessions.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11c-associates-kyc-commission-bank.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11d-thickness-prices-fx-hs-suppliers.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11e-approvals-setup-defaults.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11f-plain-language-email-templates.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11g-order-comment-email-status.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-11h-tt-clause-bank-name.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12a-settings-plain-labels.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12b-supplier-prices-base-and-calculated.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12c-annexure-b-as-per-annexure.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12d-buyer-details-refresh-approval.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12e-follow-ups-d338.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12f-buyer-details-self-approval-md-ed.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12g-detailed-line-table.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12h-price-lock-and-products-amendment.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12i-monument-option-and-defaults.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-12j-surface-finish-one-list.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13a-suppliers-products-configure.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13b-products-fixed-base-and-link-legacy.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13c-permission-descriptions.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13d-expense-cost-bank-reference.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13e-quantity-warning-levels.sql
```

Before `2026-11-13a`, run the two read-only checks in `docs/migrations/README.md` ("Before
`2026-11-13a`": products priced from an inactive supplier, categories without their own weight or
50 mm+ factor) and keep the output.

Each file is safe to run twice and MySQL-compatible. They keep the company's real identity, bank,
GSTIN, LUT and RCMC values (only a fresh install starts with placeholders). Then add the `fetchFxRate.js` cron line (07:05 IST) — or, with `RUN_JOBS_IN_PROCESS=true`, restart the app so `scheduler.js` runs it — and check outbound HTTPS to `api.frankfurter.app` and `open.er-api.com` (Products › Global pricing settings › Fetch today's rate now), and
once, as the MD / a product editor: **Products › HS codes › Fix HS codes on products** and
**Products › Link these prices** (D-361: shown only while supplier prices typed before the Suppliers directory still need a supplier record; migration 13b links exact names itself).

**Menus (D-320–D-322, SOP 00).** The left menu has 13 entries — Dashboard, Approvals (n), My tasks
(n) · Orders, Clients, Associates, Products, Procurement & suppliers, Finance & accounts · Reports ·
Help & SOP · Users & roles, Settings — and every section has a menu bar at the top of its pages
(`app/src/config/navigation.json`, the same file on both stacks). The menu paths in the older sections of this README were brought up to date on 11 Nov 2026: what was under Admin is now under *Users & roles* or *Settings* (HS codes and Pricing under Products, Suppliers and Logistics partners under Procurement & suppliers, Team incentives under Finance & accounts); the old Operations pages are under Orders, Clients or Procurement & suppliers, and the old Insights pages under Reports. The order page has a sticky action bar: Edit order ·
Documents · Full report · Duplicate order · More ▾.

**Approvals (D-310–D-311).** One page, left menu **Approvals (n)** (`/approvals`), with tabs Prices ·
Payment terms · Documents · E-mails · Bank details · Other; each row opens the exact approval.
Dashboard tiles per kind; on the order page approvers see "Waiting for your approval …", the person
who asked sees "Sent to … waiting". A payment that came in short now files a request
(`short_payment_requests`) that waits there.

**E-mail settings and Testing mode (D-280–D-281, SOP 15).** *Settings › E-mail*
(`/admin/email-settings`, Manage company settings) lists every e-mail with one switch per audience
(Staff, Associate, Buyer, Supplier; table `email_settings`). MUST e-mails are always sent and cannot
be switched off: login codes, set-password invites, forgot-password links (buyers and, new,
associates), the bank-details-changed alert, the backup-code alert and the quotation verification
code. SHOULD e-mails are on; the only OPTIONAL one, the copy of buyer documents to the associate, is
off. The old master switch `mail_sending_enabled` is **gone**: **Testing mode — hold e-mails to
buyers and suppliers** (`email_testing_mode`; on in the seed since 12 Nov 2026, D-347 — switch it off on go-live day) holds only buyer and supplier e-mails;
the screen then says so and shows the link with a Copy button, and never says "e-mailed" when
nothing was sent. Staff e-mails about a buyer, supplier or associate go only to the people of the
relevant department assigned to the order, else that department's manager (with an "assign
someone" note), else the MD; MD, ED and Coordinator are not e-mailed for routine events (in-app
notices are unchanged). Company-level compliance alerts (LUT, RCMC, agreements, TDS, merchant-trade
RBI limits, ECGC policy, monthly eBRC) go to a responsible person and a backup named on the same page
(`compliance_alert_owners`; empty = the department's manager, then the MD).

**Portal access (D-282).** Accepting an associate registration sends the set-password e-mail (72 h,
works once) and shows the link with Copy; the e-mail gives the permanent login page
`/associate/login`. New **Forgot password?** page for associates (`/associate/forgot-password`).
The associate page has three blocks (Portal login · Quotation link with the Associate code ·
Personal code); the client page has a "Client portal login" block (`/client/login` with Copy,
status and last login, Send set-password link, temporary password, Log in as).

**Staying logged in (D-283, SOP 15).** `session_timeout_minutes` is **replaced** by
`session_timeout_staff_minutes` (240), `session_timeout_client_minutes` (120),
`session_timeout_associate_minutes` (120) and `session_absolute_staff_hours` (12: staff log in again
at least every 12 hours), all on *Settings › Security*. A popup 2 minutes before the end offers
"Stay logged in" (keep-alive addresses `/keep-alive`, `/client/keep-alive`, `/associate/keep-alive`).
The login-code screen's tick is now **"This is my own office computer"**
(`login_remember_browser_days`, default **30**, 0–90); "Forget all remembered computers" on My
Account, and for Admin on Users › Edit. The session store and cookie now follow the longest idle timeout (no longer a fixed one day).

**Quote in any unit (D-270–D-273, SOPs 01, 17, 27).** A line may be priced in any unit of its
category (Natural Stone: CBM, SQM, SQFT, PCS, Ton, RMT; Set or Lot go to the MD). The price check
converts it to the product's reference unit with the piece size Length × Width × Thickness in cm
(`order_products.piece_length_cm` / `_width_cm` / `_thickness_cm`) and the weight per CBM
(`weight_t_per_cbm`, category 2.7 t, product override). Options show as labels on every line;
Duplicate makes an exact copy right below ("copy of line N", `copied_from_id`); a compact products
table with a side panel. Natural Stone lines in another unit (BOX, NOS, SET, KG, MT) are refused on
their next edit until the MD adds the unit in Products › Categories.

**Products and pricing (D-300–D-306, SOP 27).** One supplier price holds every thickness — 20, 30
and 40 mm per SQM and 50 mm+ per CBM — typed or calculated by the category's **Category price rules**
(Products › Category price rules, a button at the top of the Products page since D-362: 30 mm +35 %,
40 mm +70 %, 50 mm+ per CBM = 20 mm × 40, container 8 CBM / 27 t, stone weight; MD only, with a reason). "Price includes packing and loading" (ticked by
default) and CHA + transport per container (per supplier price; the product has a default).
**Daily exchange rate** (`fx_rates`; `fx_mode` auto, `fx_cushion_pct` 2.0): pricing rate = market
rate − 2 %; see the job above. **One master for HS code and supplier:** a product's HS code is
picked from the HS Code Master List; a product price's supplier is picked from the Suppliers
directory (quick-add makes a directory record marked *Details pending*). Two **one-time pages** for
existing data: **Products › HS codes › Fix HS codes on products** (`/products/fix-hs-codes`, Manage
product catalogue — choose a master code for each product listed) and **Products › Link these prices**
(`/products/link-prices`, Record market prices or Manage suppliers — D-361: migration 13b links exact
names itself; pick or quick-add the supplier for each remaining row; the older `/products/link-suppliers` still works, unlinked). Catalogue columns show currency and unit; "Supplier
price ₹ / unit" with ≈ USD for product editors; plain names **Staff lowest** and **Associate
lowest**. Staff see only Quote and Your lowest; associates see no prices in the catalogue.
Products › Categories › *category* has tabs General · Options and their price effect · Category price
rules · Products. (12–13 Nov 2026 changes to products, suppliers and pricing: see the next section.)

**Associates (D-290–D-294, SOP 24).** KYC: document type first, number 5–30 characters (PAN and
Aadhaar keep their formats); ID proof and Address proof are separate required items (associates
already accepted have 60 days, `address_proof_due_on`). Commission: standard ladder by default,
"+ Add own ladder" (`associate_ladder_steps`) or a fixed level, changed by the MD only with a reason,
for new orders only; associates see only their current % "of the FOB value"; new permission
**See associate commission rules** (`view_associate_commission_rules`: Admin, MD, ED, Finance
Director, Finance Manager) — other staff see "Set by the MD". Payout day: 1–28 or **last day of the
month** (the new default), or weekly; a Sunday or holiday moves to the working day before
(`associate_payout_holiday_rule`). Bank details: the associate confirms new details in the portal
(last 4 digits + a fresh code; "This is not me" cancels and alerts MD and Finance; 7 days without an
answer cancels); suppliers and logistics partners confirm through a link to their registered e-mail
(`vendor_bank_changes`). Then **one** staff member who did not enter them verifies, ticking at
least 2 of bank proof / phone call / test payment (`bank_details_check_people` = one, or two); MD
and ED may verify their own entry with a reason (self-approval rule *Bank details*). Wait before
use: first account 0 days, a change 15 days (`associate_bank_wait_first_days` /
`associate_bank_wait_change_days`, 0–90; they replace `associate_bank_wait_days`). Settings on
Associates › Commission › Commission settings and ladder.

**Setup checklist and defaults (D-312–D-313, SOP 15).** *Settings › Setup checklist*
(`/admin/setup-checklist`, table `setup_checklist_ticks`) — see "First-time setup". New defaults:
document clean-up on (drafts 90 days, superseded never-sent 365 days, CAFIN latest only), password
expiry off, Test Mode e-mail = the company e-mail, Indian national holidays seeded, the two associate
settings (personal code changes a year, upgrade after N orders) editable on the commission settings
page.

**Plain language (D-330).** 190 of the 195 approved rewrites of what buyers, associates and
suppliers read (buyer stage names, "Ask to cancel this order" in the buyer portal, "Bank reference
number (transaction ID, UTR or SWIFT reference)"); the e-mail texts stored in the database are
updated on a live site by `2026-11-11f`. No PDF or document text changed — 8 document passages wait
for the owner's double confirmation.

**Earlier fixes shipped with it.** D-268: Recent Activity scope, the true reason a staff chat post
was not e-mailed (`order_comments.email_status`, `2026-11-11g`), "Pick from Products" button, My
tasks for staff without a department. D-269: payment-terms approver wording, Buyer PO note on
cancelled or lost orders, Staff Productivity, the cockpit shows every department, reason hint shown
only on error.

**Tutorial videos.** Many recorded screens changed; the videos are **not** re-recorded. The list of
what no longer matches is in `tutorials/VIDEO_PROGRESS.md` ("Out of date after the 11 Nov 2026
build"), waiting for the owner's decision (CLAUDE.md video rule).

## Follow-up (added 2026-11-13): documents, annexures, the price lock, and suppliers → products → configure (D-331 … D-365)

Everything below is in `docs/schema.sql` / `docs/seed.sql` (a new install needs nothing else) and in the
Application Handbook; the SOP chapters say how staff use it. A **live** database is brought up to date
with the files of the previous section, `2026-11-11a` … `2026-11-13e`, in that order — before
`2026-11-13a` run the two read-only checks in `docs/migrations/README.md` ("Before 2026-11-13a") and
keep their output. Then the `fetchFxRate.js` cron line at 07:05 IST (or `RUN_JOBS_IN_PROCESS=true`, so `scheduler.js` runs it; see "Background jobs") must be in place, and `docs/guides/GO_LIVE_CHECKLIST.md`
section H lists what to check afterwards (Link these prices, the Products status filters, Testing mode
after `2026-11-12i`, Settings › Lists › surface_finish). The test suites were not run for D-348 … D-365
on the owner's instruction (syntax checks and browser checks on both stacks).

**Documents** (every change confirmed twice by the owner; documents already issued are never touched):

- **Annexure B and "As per annexure" (D-336, D-341; `2026-11-12c`).** An order line can say "As per
  annexure" (no size, quantity still required); next to Annexure A (as quoted) there is **Annexure B (as
  shipped)**, made from the Commercial Invoice step; Step 11a chooses the annexure printed with the CI and
  Packing List (`orders.ci_annexure`, `annexureChoice.js`). The annexure page has one **Save all
  changes** and the buttons Edit / Regenerate Annexure A.
- **Detailed product table (D-339; `2026-11-12g`).** QT, PI, OC, CI, Buyer PO and Packing List print
  # · Material / Colour · Surface / Finish · L W H · measure per piece · No. of pcs · measure of all pieces
  · price per unit · price per piece · total (`templates/_line_table.njk`, `lineTable.js`, `unitPrices.js`). Orders that
  existed before `12g` keep the classic table (`orders.line_table_layout`).
- **Money and numbers (D-343, D-345).** Indian digit grouping (1,75,000.00) for every amount of an INR
  order and for every Supplier PO (`moneyFormat.amount()`); Total CBM worked out from the lines; the Word copy has
  the same section numbers as the PDF.
- **Wording (D-344, D-349, D-352, D-353).** "Commercial Invoice" and "Packing List" without "(digitally
  authorised)"; the Certificate of Origin line reads "3. Certificate of Origin Issued by CAPEXIL" while the
  COO type is empty or "To Be Confirmed" (`documentDataAssembler.cooLine()`); no "Fields marked * are mandatory …" note and no
  "*" after labels on any generated document (the app's input forms keep their "*").
- **Layout (D-354, D-355, D-356).** Headings and column headers stay with their first rows (PDF and Word);
  the Payment Terms on the QT and PI sit in a light grey-blue box with the amounts in bold; a clean
  spacer under Word headings (PHP).

**Orders and clients:**

- **Buyer details follow the client (D-337; `2026-11-12d`, `2026-11-12f`).** While nothing of the order
  is issued, a client edit updates the order at once; after that, by a request approved by a holder of
  **Approve buyer details change** (`approve_buyer_details_change`: MD, ED) — `buyerDetailsRefreshService.js`,
  `buyerDetailsController.js`, table `order_buyer_detail_requests`.
- **Reorders (D-338; `2026-11-12e`).** Reorder lines can say "As per annexure"; duplicating an order copies
  Annexure A; a worked-out per-CBM price goes to the MD like a price below the floor.
- **Price lock and the "Products & prices" amendment (D-342; `2026-11-12h`).** From the first approved
  Quotation the order keeps its exchange rate and every line price (`priceLock.js`); lines change only
  through **Amendments › Products & prices** (prices only go down, MD approval, AMD document
  `templates/AMD/products_amendment.njk`; `productsAmendmentService.js`, table `amendment_product_lines`). `12h` fixes existing orders that
  already have an approved Quotation.
- **Exchange rate shown (D-340).** Global pricing settings has the box "Rate used for prices right now";
  the order page shows MD and ED the rate the prices were worked out at.
- **Client billing address in parts (D-346).** Line 1, City / Town and Country required; composed by
  `clientAddress.compose()`.
- **Order lines (D-348).** Thickness and Size / format start on "Choose…" — the price fills only once both
  are chosen; typing Thickness (cm) sets the Thickness option.
- **MD name (D-349, D-350).** Changing the MD's name or title on Settings › Company offers to apply it to
  the open orders and Draft / In-review documents (`mdNameSyncService.js`); approved and sent documents
  never change; the founder logins still change only by the founder SQL block (First-time setup).
- **Buttons (D-350, D-351).** On a form with two choice buttons the pressed button's choice always reaches
  the server, and a question written on a button is asked (`public/js/app.js`: `keepSubmitterChoice()`,
  `initConfirmDialogs()`).

**Products, suppliers and pricing:**

- **Defaults and options (D-347; `2026-11-12i`).** Size / format "Monument (cut to size)" at 0 %; a fresh
  install starts with e-mail **Testing mode ON** (switch it off on go-live day), team incentive on, other
  costs 5.5 %, margin 15 %. Part 2 of `12i` sets the same four values on a live database.
- **One list of surface finishes (D-357; `2026-11-12j`).** Settings › Lists › surface_finish names every
  finish; Products › Pricing › Specifications › Surface finish keeps only its price
  (`surfaceFinishList.js`). The base finish is "Natural Polish" (was "Polished" / "Mirror Finish").
- **Duplicate a product (D-358).** **Duplicate** on the Products list and the product page
  (`/products/{id}/duplicate`, Manage product catalogue, `productDuplicateService.js`); the copy starts
  Inactive.
- **Supplier offers and the configured base price (D-359; `2026-11-13a`).** Each supplier price of a
  product is an *offer* with an **Offer active** switch (`catalog_product_suppliers.offer_active`); an offer
  is used only when it is on and its supplier is active (`supplierOffers.js`). Each product has a configured
  base price; margin, other costs, buffer and the physical values come from the product, else its
  category, else Global pricing settings (`pricingLevels.js`). New **Edit price** form
  (`/products/suppliers/{id}/edit-price`, Record market prices, `editPriceController.js`).
- **Suppliers pages (D-360).** **Procurement & suppliers › Suppliers** lists under each supplier the
  products it offers (base price, quoted on / valid until, Offer active, Edit price) and a filter "No
  product prices yet"; **+ Add product** adds an existing product or creates a new one (Inactive) with its
  price (`/suppliers/{id}/add-product`; switch `/suppliers/offers/{id}/active`; Manage suppliers + Record
  market prices).
- **Products and Configure (D-361; `2026-11-13b`).** The configured base price is **fixed**: it never
  changes by itself — when supplier prices change the product shows "Supplier prices changed: highest is
  now ₹X" with **Use ₹X**. **Configure / Edit** (`/products/{id}/configure`, Manage product catalogue,
  `productConfigureController.js`): base price (default the highest active supplier price), the table Global
  | Category | This product | Comes from with a reset per row, Reset all to category / global, Active only
  when quotable. Statuses Configured / Not configured / No active supplier / No supplier yet; **Link a
  supplier** (`/products/{id}/link-supplier`); **Link these prices (N)** (`/products/link-prices`) replaces
  "Link suppliers" (the old route still works, unlinked); the Products page no longer has "+ Add Product" —
  products are added from the supplier. Pick from Products offers only quotable products. The **Supplier
  PO** terms (Step 7a) show every supplier offer for the line's product; choosing one fills the supplier
  and unit price, and a different agreed price needs a reason (`order_supplier_po.supplier_offer_id`,
  `quoted_unit_price_inr`, `price_override_reason`; audit `SUPPLIER_PO_PRICE_OVERRIDE`).
- **Names and one place (D-362).** `/pricing/settings` is **Global pricing settings** and
  `/products/price-rules` is **Category price rules**. They are no longer in the left menu or the menu
  bar ("Price rules", "Pricing settings (margin, buffer, exchange rate)" and "Exchange-rate history" were
  removed): both are buttons in the **Products link bar** — Categories · Category price rules · Global
  pricing settings · Fix HS codes · Link these prices (only while some are left) · Go to Suppliers.
  Products › Pricing keeps Commission levels and ladder, and Specifications. Routes and permissions
  unchanged.
- **Products list, category form, thickness rules (D-363, D-365).** Row actions are small buttons (Edit
  or Configure, View, Duplicate, Link a supplier, Reactivate / Delete); the default columns are Name ·
  Suppliers · Supplier prices (₹) lowest – highest · Base price · Cost (USD) · Min price (USD) · Staff
  lowest · Staff quote · Status · Actions, with **Show all columns** for Associate lowest and Latest
  supplier quote; a product's own thickness % is on **Configure › Thickness rules**. **Pricing ready?**
  checks the configured base price and an active supplier (no longer the old "used for pricing" price).
- **Units SQM · SQFT · CBM (D-364).** Every screen says SQM and SQFT (not m² / sq ft); documents are
  unchanged. A supplier price may be typed per SQFT on + Add product, Edit price and Link a supplier: it is
  saved as the SQM price (× 10.7639, `lineUnits SQFT_PER_M2`) and the note keeps what was typed. "Supplier
  performance" on Procurement is a normal small button.
