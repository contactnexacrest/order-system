# NexaCrest Export Operations Webapp — Phase A + Phase B + Phase C + Phase D + Phase E

**Start here for the full picture: [docs/APPLICATION_HANDBOOK.md](docs/APPLICATION_HANDBOOK.md)**

Phase A: authentication, RBAC, company settings, asset management.
Phase B: clients, orders, the Stage 1→4 gate pipeline (Quotation → Buyer PO
→ Proforma Invoice → Order Confirmation/Production), and the Twig →
DOMPDF/PHPWord document generation pipeline — proven end-to-end on QT/PI/OC.
Phase C: Stages 4→9 in full — Order Confirmation buyer
acknowledgement, Supplier PO / material procurement, Freight Payment
(CFR/CIF only, auto-skipped for FOB), Packing & BL Instruction, Commercial
Invoice & 60% balance, and Document Despatch & Closure — plus their seven
new document types (BUYERPO, SUPPO, FDN, PL, BLI, CI, COOPREP). Proven
end-to-end over real HTTP requests for **two complete order runs**: one
FOB order (confirms Stage 6 auto-skip) and one CIF order (confirms the
freight-payment path, freight-prepaid BL wording, and "paid via FDN"
Commercial Invoice wording) — both walked from client creation through
order closure, with every one of the 11 document types rendered to PDF and
visually inspected.

Phase D (this delivery): the review/approval workflow (per-document-type
reviewer sign-off plus a separate cross-verification layer, with the
DRAFT→FINAL watermark swap happening automatically on full approval), the
two-level deferred email-send pipeline (Level 1 requests, Level 2/privileged
approves, actual sending deferred to a cron job — there is no persistent
worker on shared hosting), the Payment Terms Amendment (SC/AMD) workflow
(request → MD approval → generate the legal agreement → upload the
wet-signed copy → activate, at which point future PI/CI generations for that
order automatically reflect the amended terms), dispute management, the
audit log viewer, and in-app alert notifications (LUT/RCMC expiry, freight
payment overdue, dispute response overdue). Proven end-to-end over real HTTP
requests, including generating a document, running it through the full
two-reviewer approval chain, confirming the FINAL watermark swap on the
actual rendered PDF, running the full amendment lifecycle twice (once
changing only the advance %, once changing only the balance trigger/days),
and confirming — by generating a **fresh** Proforma Invoice after each
amendment and visually inspecting the rendered PDF — that the new terms
actually appear in future documents, not just in the database.

Phase E (this delivery): a real dashboard (active orders by stage, "my
approvals" tiles gated per-permission, overdue balance/freight payments,
LUT/RCMC expiry warnings, recent activity, client/order search) and a
reports module (per-client, per-order, and a filterable aggregate report
with CSV export and savable/shareable report definitions), plus a
security-hardening pass: data masking for client email/phone (gated on a
permission, so Admin/MD see the real value and everyone else sees a masked
one), a bounded admin field-override system (document reference formats,
T&C clause text, payment presets, a client's Buyer Inquiry Ref, an order's
status/lock, an amendment's reference — every override requires a
mandatory reason and is fully audit-logged; deliberately not a fully
generic "edit any column" tool — see "Two judgment calls" below),
non-guessable (UUID) on-disk filenames for every generated PDF/DOCX across
all three generation paths (draft, approved-final, amendment), enforced
password expiry (`password_expiry_days` now actually gates access instead
of sitting unused), and documented (not automated — there's no server to
schedule against from this sandbox) Bluehost daily DB backup instructions.
Proven end-to-end over real HTTP requests: every new page loaded and
inspected, CSV export verified byte-for-byte clean, a document walked
through generate → review-approve → finalize with the on-disk filename
inspected at each of the three stages, an amendment created → MD-approved →
its document generated with the same UUID-filename inspection, and password
expiry verified by backdating a test account's `password_changed_at` and
confirming the forced-change redirect fires, gets audit-logged, and clears
correctly once the password is changed.

## What's in this folder

- `docs/schema.sql` — full database schema (210 tables, 403 foreign keys as
  of 2026-11-13 — see `docs/schema.sql`'s own trailing `-- SECTION X …
  added YYYY-MM-DD` comments for the running changelog; 52 tables/76 FKs
  at the time of this Phase D delivery specifically), validated against a
  live MariaDB instance after every change in this delivery, including the
  Phase D additions below.
- `docs/seed.sql` — starter data: roles/permissions, one login user,
  company settings (a few are still PLACEHOLDER — see below), lookups,
  payment presets, stages, document types, the 21 real Terms & Conditions
  clauses, 9 email templates for the deferred-send pipeline, and the new
  `cross_verify_documents`/`approve_email_send` permissions.
- `scripts/` — the backup scripts and their self-test (owner decisions D-260; see "Backups" below).
- `app/cron/` — the five scheduled jobs (see "Bluehost cron setup" below):
  `dispatch_deferred_emails.php`, `auto_confirm_oc_acknowledgments.php`,
  `check_alerts.php` and `zoho_sync.php`; plus two scripts run by hand,
  never from cron: `zoho_cleanup_test_records.php` and
  `reorganize_order_files.php`.
- `docs/ARCHITECTURE.md` — the full architecture proposal and the record of
  every open question and how it was resolved.
- `docs/architecture-diagram.html` — the three diagrams (hosting boundary,
  document pipeline, stage gates).
- `app/` — all PHP application code. Outside web root, as required.
- `app/templates/` — the Twig templates, one folder per generated
  document type (QT, PI, OC, BUYERPO, SUPPO, FDN, PL, BLI, CI, COOPREP,
  AMD, ANNEXA, BLE, CAFIN), plus the shared `_layout.html.twig` they all
  extend and a few shared partials. (COOPREP is internal-only —
  never sent to the buyer — and has no reference number of its own by
  design; see `document_types.ref_format` and the note in "Real bugs found
  and fixed during Phase C" below.)
- `public_html/` — the web root. Only this folder is ever URL-reachable.
- `storage/` — asset files (placeholder logo/signature/seal/watermark/email
  header images already in place) and, once you generate documents,
  per-order folders under `storage/clients/<client>/<order>/<step>/generated/` — one folder per order-page step (`01-quotation/`, `04-buyer-po/`, … see SOP chapter 0, "How files are named and stored on disk").
- `.gitignore` — added in the Phase E follow-up: excludes `app/.env` (real
  secrets — SMTP/DB credentials, encryption keys), `app/vendor/`
  (Composer-regenerated), and generated per-order documents under
  `storage/clients/`/`storage/temp/`. Nothing else in this delivery assumes
  git is even in use — this exists only so that if/when you do put the
  project under version control, real credentials can't end up in a commit
  by accident.

## First-time setup (local machine or Bluehost)

> **Installing on Bluehost (cPanel)?** Follow `docs/guides/DEPLOYMENT_GUIDE_BLUEHOST.md` from top to bottom. It is the click-by-click
> version of everything below, written from the live installation of 13–14 Nov 2026: folder layout, cPanel Git, subdomain and SSL,
> databases (including the restore-test database), phpMyAdmin import, `app/.env` line by line, the web lock and its tests, the server
> clock and the ten cron jobs, the backup folders, key and first test run, the final check, the routine checks, updating a live site
> (Git pull + `docs/checks/check_all_migrations.sql`) and troubleshooting.
>
> **Go-live day:** work through `docs/guides/GO_LIVE_CHECKLIST.md`. It is the business view of these steps, with the settings, people, prices and dry run, in order.

1. **Database.** Create a MySQL/MariaDB database (utf8mb4) and import these two files in order —
   that is the whole database; a new installation runs **no** migration (D-241):
   ```
   mysql -u youruser -p -e "CREATE DATABASE yourdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u youruser -p yourdb < docs/schema.sql
   mysql -u youruser -p yourdb < docs/seed.sql
   ```
   Both files start with `SET NAMES utf8mb4;`, so the em-dashes and other
   punctuation in the seeded text (T&C clauses, addresses, the draft
   watermark) import correctly regardless of your MySQL client's own
   default charset. (This was a real bug caught during Phase B testing —
   without that line, `mysql < seed.sql` silently mangled every em-dash
   into mojibake. Don't remove it.)
   The database user needs the **TRIGGER** privilege (cPanel "All Privileges"
   includes it): the schema creates triggers for the founder-login protection
   and the permanent document image store `document_image_blobs` (D-267).
   A fresh install never needs a migration — the tests FreshInstallCompleteTest / freshInstallComplete.test.js check that every file in docs/migrations is already in schema.sql/seed.sql.
2. **Fix the storage path in seed data.** `seed.sql` inserts asset rows with
   a literal placeholder path — in BOTH the `assets` table (company
   logo/seal) and the `user_signature_assets` table (per-user signatures and
   designation seals). After importing, run both:
   ```sql
   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
   UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
   UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
   ```
   using the same absolute path you'll put in `.env` below. Skipping the
   second statement doesn't error anywhere — every document generates fine —
   it just silently renders every signature/seal block blank, since
   `is_file()` fails on the literal placeholder path. If a generated PDF is
   ever missing a signature or seal, check this first.
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
   *Backups*, *This year's holidays*, *The MD's real e-mail*, *Session folder
   storage/sessions is writable* and *Settings at the recommended values*
   (with **Apply recommended**). Enter the real values on **Settings ›
   Company** (Unlock on the protected rows) until every item is Done; the
   placeholders print on the documents until then.

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
3. **Environment file.** Copy `app/.env.example` to `app/.env` and fill in:
   DB credentials, `STORAGE_BASE_PATH` (absolute path to the `storage/`
   folder in this project), and SMTP details when you have them. Leave the
   `SMS_GATEWAY_*` values blank unless you've signed up for an SMS
   provider — the app works fine without them (email 2FA only).
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
   and the `BACKUP_*` lines (see "Backups").
   **Session folder (D-283, 11 Nov 2026).** PHP keeps its session files in
   `storage/sessions/` (under `STORAGE_BASE_PATH`), so the host's own clean-up
   (often after about 24 minutes) no longer signs people out early. The app
   creates the folder on first use; on the live server make sure it exists and
   the web server can write to it (`mkdir -p storage/sessions && chmod 700
   storage/sessions`, owned by the PHP user). It is outside `public_html/`, so
   it is never served. Settings › Setup checklist shows **Needed** while it is
   not writable — staff would then be signed out after about 24 minutes again.
   **Outbound HTTPS (D-301, 11 Nov 2026).** The daily exchange-rate job calls
   `https://api.frankfurter.app` (European Central Bank rates) and, if that
   fails, `https://open.er-api.com`. Both are free and need no key. Ask the
   host to allow outbound HTTPS to both if the server is firewalled; check it
   once with **Fetch today's rate now** on Products › Global pricing settings
   (button at the top of the Products page) › Exchange Rate (USD → INR). Without it the rate in use stays as it is and the page and
   the Setup checklist warn.
4. **Dependencies — already included, nothing to run.** `app/vendor/` in
   this delivery is a real, working copy of DOMPDF, PHPWord, Twig, and
   PHPMailer — the document generation pipeline needs all four.
   `app/bootstrap.php` already picks up `vendor/autoload.php` automatically;
   there is no Composer command to run and nothing to install here. See
   "About the app/vendor/ folder in this delivery" below for how it was
   built and when (if ever) you'd need to touch it.
5. **Web server.**
   - **Bluehost / Apache:** point the domain/subdomain's document root at
     this project's `public_html/` folder. `.htaccess` handles routing.
   - **Local testing:** Apache's `.htaccess` rewrite rules are NOT read by
     PHP's built-in server unless you use a router script. Use the one
     provided:
     ```
     cd public_html
     php -S 127.0.0.1:8000 _dev_router.php
     ```
     Do not run `php -S` without `_dev_router.php` — without it, a URL that
     happens to share a name with a real folder under `public_html/` will
     404 instead of reaching the app (this bit us once during Phase A
     testing — see `docs/ARCHITECTURE.md`).
6. **Log in.** Default seeded accounts (both Super Admin, both the same
   password):
   - Email: `md@email.com`
   - Email: `ed@email.com`
   - Password: `ChangeMe#2026`

   You'll be forced to set a new password on first login (by design —
   `force_password_change` is set on both seeded users).

   **Every login also asks for a 6-digit code sent by e-mail** (owner decision
   D-265, 7 Nov 2026 — every staff member and every buyer, at every login). So
   **set up e-mail (SMTP in `app/.env`) before the first login**; until mail works
   the code only appears in the server error log. On a local machine
   (`APP_ENV=local`) the code is also shown on the code page. Right after the
   first login, the MD and the Admin each make their **emergency backup codes**
   (My Account › Backup codes: 10 one-time codes, shown once) and keep them
   offline — they are the way in if e-mail ever stops working.

## Using Phase B: clients, orders, documents

1. **Clients** (`/clients`) — create a client once per buyer relationship.
   A Buyer Inquiry Ref is generated automatically and reused on every order
   and document for that client — you never re-enter it.
2. **Orders** (`/clients/<id>` → "New Order", or `/orders/create`) — pick
   the client, incoterm, currency, payment preset, ports, and enter product
   lines (description, dimensions, finish, qty, unit price, HS code — add
   as many rows as needed with "+ Add product line"). This creates the
   order, initializes all 9 stage rows (Stage 1 unlocked, the rest locked),
   and initializes its payment-status row.
3. **Order detail** (`/orders/<id>`) drives the whole Phase B pipeline from
   one screen: a stage-track strip across the top, the product table, a
   documents table with PDF/DOCX download links, and — depending on which
   stage is currently unlocked — the relevant action:
   - **Generate Quotation** → renders and stores the QT PDF (+ internal
     DOCX). Generating a QT automatically passes Stage 1's gate and
     unlocks Stage 2. You can re-generate a QT later (e.g. after buyer
     negotiation) — it's stored as the next revision, not a new document.
   - **Confirm Buyer PO Received** (Stage 2, manual gate — deliberately a
     flag + reference number, not a full document-upload subsystem; see
     the open-question log in `ARCHITECTURE.md`) → unlocks Stage 3.
   - **Generate Proforma Invoice** → available once Stage 3 is unlocked.
     Automatically cites the Quotation's own reference number.
   - **Record Advance Remittance** / **Mark Advance Cleared** (Stage 3
     gate) → recording captures the amount and date; marking it cleared is
     the actual gate — it computes the balance amount, sets the balance due
     date from the client's payment preset, and unlocks Stage 4.
   - **Generate Order Confirmation** → available once Stage 4 is unlocked.
     Automatically cites the Proforma Invoice's own reference number, and
     its banner reflects the real advance-cleared date and percentage.
   - **Update production status / estimated shipment** — free-text fields
     shown on the Order Confirmation, editable any time Stage 4 is open.

Every generated PDF carries the live "DRAFT — NOT FOR RELEASE" watermark
from `watermark_settings` (turn it off there once you're ready to issue
real documents) and embeds your actual logo/signature/seal from
`/company-assets`, not the placeholder graphics, once you've replaced them.

## Using Phase C: Stages 4→9

Continuing from the same `/orders/<id>` screen — each new section only
appears once its stage is unlocked:

- **Stage 4 gate — Confirm Buyer Acknowledged Order.** Phase B generated
  the Order Confirmation but never built the "buyer acknowledges"
  half of that gate (StageGate.docx: *"NexaCrest confirms order and
  advance received → Buyer acknowledges"*) — this was a genuine gap, not a
  deferred feature, and is fixed here. One button, unlocks Stage 5.
- **Stage 5 — Supplier PO.** Add a supplier (one-time per supplier, reused
  across orders) or pick an existing one, fill the material specs and
  commercial terms (all in INR, per the source template), then **Generate
  Supplier PO**. Once the supplier signs and returns it, **Confirm Supplier
  Signed** passes the gate — and, for an FOB order, *also* auto-skips
  Stage 6 in the same action (see below).
- **Stage 6 — Freight Payment.** CFR/CIF only. For an FOB order this whole
  section replaces itself with a one-line explanation and shows the
  `skip_reason` once skipped — nothing to do. For CFR/CIF: save the
  confirmed freight rate/insurance/forwarder, **Generate Freight Debit
  Note**, then record and clear the freight remittance to unlock Stage 7.
- **Stage 7 — Packing & BL Instruction.** Record actual packed quantity,
  crate count, weights, CBM, and the full crate-level breakdown (add one
  row per physical crate — this feeds both the Packing List's own table
  and the Marks & Numbers line on the BL Instruction Sheet). Save shipping
  details (vessel, voyage, ETD/ETA, container/seal numbers), generate PL
  and BLI, then **Confirm BL Issued** with the real BL number/date — the
  gate that unlocks Stage 8, and the date the Commercial Invoice must
  match.
- **Stage 8 — Commercial Invoice & Balance.** Mark the scanned BL copy
  sent to the buyer, generate the CI (its Section B payment settlement
  reads the real advance-cleared and freight-cleared figures from Stage
  3/6 and verifies advance + balance = FOB value), then record and clear
  the 60% balance payment to unlock Stage 9.
- **Stage 9 — Document Despatch & Closure.** Generate the COO Prep Sheet
  (internal-only checklist for CAPEXIL/CHA — never rendered with a
  document reference of its own, since the source template has none),
  record the 3 original BLs received from CHA, mark them endorsed, then
  **Close Order** with the courier tracking number. This sets
  `orders.status = 'complete'` and `is_locked = 1` (Business Rule #17) —
  there is no further action after this on that order in this delivery.

FOB vs. CFR/CIF was tested as two complete, separate order runs (see the
top of this file) specifically to prove the Stage 6 skip logic actually
branches correctly rather than merely reading correctly in code.

## Using Phase D: review/approval, deferred send, amendments, disputes

Continuing from the same `/orders/<id>` screen — a new "Review, Approval &
Send to Buyer" section appears per generated document, plus new
"Amendments & Disputes" links:

- **Review & approval.** Assign one or more reviewers to a generated
  document (defaults to `document_types.min_reviewers_default` reviewers
  needed, configurable per document type). Each assigned reviewer sees it
  under **My Reviews** (`/reviews`, in the top nav for every logged-in
  user) and approves or rejects with comments. A single rejection sends the
  document back to `draft` status. Once every assigned review is approved
  (and none rejected), the document is auto-finalized: the same
  `document_reference`/revision keeps its number, but a **new** PDF is
  rendered with the FINAL watermark (`watermark_settings WHERE
  is_draft_mode=0`) replacing "DRAFT — NOT FOR RELEASE" — the original
  draft PDF stays on record for audit, nothing is overwritten. Separately,
  anyone with `cross_verify_documents` can record a pass/fail
  cross-verification on any document at any time — this is a second,
  independent quality-check layer, not a substitute for reviewer approval.
- **Deferred email send.** Once a document is `approved`, "Send to Buyer"
  opens a compose screen with a live preview built from `email_templates`
  (per-document-type subject/body/footer, `{token}` placeholders filled from
  the order/document). Submitting requests the send (Level 1) — it does
  **not** send immediately, even if you leave "Send now" selected. Anyone
  with `approve_email_send` (Admin/MD by default) reviews the queue at
  `/email-approvals` and approves or rejects. Only the cron job
  (`dispatch_deferred_emails.php` — see below) actually sends: this is
  deliberate, not a missing feature — Bluehost shared hosting has no
  persistent worker process, so even an "immediate" approved send waits for
  the next cron tick. The buyer only ever receives the watermarked **FINAL**
  PDF — the pipeline reads `documents.pdf_file_id` exclusively, never the
  DOCX and never a draft.
- **Payment Terms Amendment (SC/AMD).** From `/orders/<id>/amendments`:
  raise a request (reason, who requested it, and whichever of advance %,
  balance trigger/days, or balance amount actually changed — leave the rest
  blank to keep them as-is), get MD approval, generate the Amendment
  Agreement PDF, print it, get it wet-signed and stamped by the importer,
  then upload the signed copy. **Only at that upload step** are payment
  terms actually updated in the system (per spec Section 8) — not at
  request time, not at MD-approval time. From that point, every future
  document generated for that order (a new PI revision, the CI, etc.)
  automatically reflects the amended terms, with no per-document-type
  special-casing (see "Real bugs found" below for the one place this
  didn't work correctly on first implementation, and how it was fixed).
- **Disputes.** From `/orders/<id>/disputes` (or `/disputes` for the
  cross-order queue): raise a dispute with a description; the response due
  date is computed automatically from
  `company_settings.dispute_response_days_n`. Attach supporting documents
  (photos, correspondence) and update status as it progresses
  (`dropdown_options('dispute_status')`: Open, Under Review, Resolved,
  Escalated).
- **Audit log viewer** (`/audit-log`, gated on `view_audit_log`) — every
  `AuditLogRepository::log()` call made throughout the app (there are many —
  logins, document generation, every review/approval/amendment/dispute
  action) is filterable by entity type, entity id, user, action type, and
  date range, paginated 100 rows at a time.
- **Per-order audit log** (`/orders/{id}/audit-log`, "Audit Log" link on
  the order page) — everything logged against one specific order, its
  documents, disputes, amendments, and buyer email sends in one place,
  without needing to already know every document/dispute/amendment id to
  filter the main audit log by one at a time.
- **Full order dossier ZIP** (`/orders/{id}/dossier`, "Download Full
  Dossier (ZIP)" on the order page) — every live file on record for that
  order (every generated document revision, every received/uploaded file)
  bundled into one ZIP, organized into `Generated Documents/` and
  `Received Documents/` folders. This is a convenient one-order export for
  handing files to someone or spot-checking that everything's on file —
  it is not a substitute for backing up `storage/` as a whole (see
  "Backups" below).
- **Notifications** (bell icon, top right, unread count shown) — reviewer
  assignments, amendments awaiting MD approval, email sends awaiting Level-2
  approval, and the alert cron's own findings (below) all land here.

### Web lock — keep everything except `public_html` off the web (13 Nov 2026)

If the project folder sits inside the hosting account's web root (for example under
`/home/<user>/public_html/...`), every file in it could be fetched by URL through another
domain on the same account — including `app/.env`, `storage/` (order documents, CAFIN) and
backups. Two `.htaccess` files prevent that and travel with every upload:

- the project's top folder: `.htaccess` containing `Require all denied`;
- `PHP_MySQL_Version/public_html/.htaccess` starts with `Require all granted`.

Check after an upload, in a private browser window:

| Address | Must show |
|---|---|
| `https://<the app's address>/login` | the sign-in page |
| `https://<another domain on the account>/<path to the project>/PHP_MySQL_Version/README.md` | **403 Forbidden** |
| `https://<another domain on the account>/<path to the project>/PHP_MySQL_Version/app/.env` | **Not Acceptable** (Bluehost's firewall, Mod_Security, blocks any `.env` request) or 403 Forbidden |
| `https://<another domain on the account>/<path to the project>/PHP_MySQL_Version/storage/` | 403 Forbidden |

A download or a page of text is a failure. Best of all, keep the project outside `public_html` and point the
(sub)domain at `PHP_MySQL_Version/public_html` only.

Both `.htaccess` files come from Git. **Never edit them (or any other file that came from Git) by hand on the
server**: the next cPanel *Update from Remote* then stops with "Your local changes … would be overwritten". If that
happens, rename or undo the hand-edited file, update again, check the two files came back and repeat the tests
(`docs/guides/DEPLOYMENT_GUIDE_BLUEHOST.md`, section 14).

**Updating a live site:** cPanel › Git Version Control › Manage › Pull or Deploy › **Update from Remote**; then run
`docs/checks/check_all_migrations.sql` in phpMyAdmin (read-only, one row per migration) and run every file whose row
says **NEEDED**, in date order, until every row says **already done**; then check `scripts/*.sh` are still 0700.
Full steps: the deployment guide, section 14, and `docs/migrations/README.md`.

### Bluehost cron setup

Seven scheduled jobs, all plain PHP scripts meant to be run via
cPanel → Cron Jobs (Bluehost's equivalent of a systemd timer — there is no
persistent worker process on shared hosting, which is exactly why these
exist as polling scripts rather than a queue consumer):

```
CRON_TZ=Asia/Kolkata
*/10 * * * *  php /home/yourcpaneluser/nexacrest_webapp/app/cron/dispatch_deferred_emails.php >> /home/yourcpaneluser/logs/dispatch_deferred_emails.log 2>&1
*/15 * * * *  php /home/yourcpaneluser/nexacrest_webapp/app/cron/auto_confirm_oc_acknowledgments.php >> /home/yourcpaneluser/logs/auto_confirm_oc_acknowledgments.log 2>&1
0 6 * * *     php /home/yourcpaneluser/nexacrest_webapp/app/cron/check_alerts.php >> /home/yourcpaneluser/logs/check_alerts.log 2>&1
0 * * * *     php /home/yourcpaneluser/nexacrest_webapp/app/cron/zoho_sync.php >> /home/yourcpaneluser/logs/zoho_sync.log 2>&1
15 8 * * *    php /home/yourcpaneluser/nexacrest_webapp/app/cron/daily_digest.php >> /home/yourcpaneluser/logs/daily_digest.log 2>&1
15 2 * * *    php /home/yourcpaneluser/nexacrest_webapp/app/cron/document_copy_cleanup.php >> /home/yourcpaneluser/logs/document_copy_cleanup.log 2>&1
5 7 * * *     php /home/yourcpaneluser/nexacrest_webapp/app/cron/fetch_fx_rate.php >> /home/yourcpaneluser/logs/fetch_fx_rate.log 2>&1
```

Plus the three backup lines (database 02:00, documents 02:30, monthly test-restore) — see "Backups" below.

- `fetch_fx_rate.php` — once a day at 07:05 IST (owner decision D-301, 11 Nov
  2026): fetches the USD → INR market rate from Frankfurter (European Central
  Bank reference rate of the previous working day) or, if that fails, from
  open.er-api.com, and keeps it in the history (`fx_rates`). The pricing rate
  is the market rate minus the cushion (Products › Global pricing settings:
  2 % by default, 0–5 %). A failed fetch keeps the rate in use and shows a
  warning (exit code 1, so cron mails the failure); a change of more than 2 %
  against the rate in use waits for the MD on the Approvals page. Prices on
  Quotations and Proforma Invoices already issued never change. In
  **Manual** mode the rate is still fetched for the history, but pricing uses
  the rate the MD typed (`pricing_fx_rate`). Needs outbound HTTPS to
  `api.frankfurter.app` and `open.er-api.com`. Safe to run twice a day (one
  row per date).

- `document_copy_cleanup.php` — once a day (owner points 5/8/9, 2 Nov 2026):
  removes the document copies Settings › Document clean-up lists in its
  preview (drafts of approved documents, never-sent superseded revisions,
  old CAFIN copies). Since D-313 (11 Nov 2026) the rules are **on** by
  default: drafts 90 days after approval, never-sent superseded revisions
  after 365 days (the SUPERSEDED copy is kept), CAFIN latest only; set a rule
  to 0 / "keep all" to switch it off. Never removes anything sent to the
  client, the current copy of a document, uploaded files, files of an order
  with an open dispute, or documents in a CA-locked financial year.

- `daily_digest.php` — once every morning (owner decision 1 Nov 2026): one
  e-mail per person listing their open tasks and documents to review. Set
  the cron time zone to IST (or adjust 08:15 to the server's zone). Safe to
  run twice; nobody gets two digests a day. The same run also sends the
  associates' reminders (D-217): 30 and 7 days before a client's 12-month
  protection ends (if the client has not ordered) and before an
  associate's agreement ends, each once, and marks one-off invitation
  links past their 30 days as expired.

- `dispatch_deferred_emails.php` — recommended every 5–15 minutes. Sends
  every `email_log` row that's `approved` and due
  (`scheduled_at <= NOW()`). Safe to run more often; each row is only ever
  picked up once (marked `sent`/`failed` immediately).
- `auto_confirm_oc_acknowledgments.php` — recommended every 15 minutes.
  Auto-confirms any Order Confirmation the buyer hasn't acknowledged
  (client portal button, or a staff-recorded email reply) within 48 hours
  of it being emailed, unlocking Stage 5 (docs/schema.sql Section AE).
  Gap list G-6 (1 Nov 2026): while buyer e-mails are not sent (Testing
  mode on, or the buyer documents e-mail switched off in Settings › E-mail),
  the 48 hours start only when staff press "Mark Order Confirmation Sent to Buyer"
  (Step 6c), not when the OC is approved.
  Idempotent — a row is only ever picked up once it has `acknowledged_at
  IS NULL AND due_at <= NOW()`, and acknowledging it (by any means) before
  this runs removes it from that set.
- `check_alerts.php` — recommended once daily. Checks LUT/RCMC expiry
  against their configured alert/escalation thresholds, freight payment
  overdue (FDN issued, not yet cleared, past
  `fdn_overdue_days_c`), balance payments overdue (the day after the
  balance due date — for post-BL and LC orders that date is set when the
  scanned BL is sent, gap list G-2; also to the order's assignees), the
  Letter of Credit's last shipment date, deadline to present documents and
  expiry (once each, 7 days before, gap list G-4; also to the assignees),
  quotation/PI validity expired, and dispute responses past their due
  date, and creates in-app notifications for Admin/MD (see "Real bugs found" below —
  this has a same-day dedup guard so it doesn't re-notify on every run
  while a condition remains unresolved).
  Since 2 Nov 2026 it also sends the Agreements register reminders (D-228):
  30 days, 7 days and on the expiry day, once each, to holders of
  `manage_agreements` (client agreements included).
- `zoho_sync.php` — CA / Accounting module. **Must be installed, hourly**
  (owner decision 2 Nov 2026, D-233): saving a receipt, bill, payment,
  expense or cost in the app never sends it to Zoho Books at that moment —
  it waits for the next sync ("Waiting for Zoho: N" on the order page and
  beside Zoho Sync in the menu). Without this job records reach Books only
  when someone presses Sync Now. A run with failures e-mails every
  "Manage CA module" holder once (and notifies them in the app). Runs the
  same `CaSyncService::runFullSync()` as the "Sync Now" button on
  `/ca/zoho-sync`: sends Zoho Books the Commercial Invoices and Debit Notes
  (as export invoices), the buyer's payments and the LC payment (applied
  to their invoice; an advance is held until the CI is in Books), the
  suppliers and logistics partners (as vendors) with their tax-invoice
  bills and payments, order expenses and paid cost entries (as expenses)
  and received export benefits (as journals); then imports the expenses
  the CA enters in Books. At most once every 23 hours a scheduled run also
  checks every record already sent and re-creates or restores any that
  was deleted or changed in Books (Sync Now always checks). Only records
  dated on or after the go-live date (`zoho_books_sync_start_date`) are
  sent; with `ZOHO_ENVIRONMENT=production` nothing is sent until it is
  set. While Zoho is off (`ZOHO_INTEGRATION_ENABLED` not `true` in `.env`,
  or the Zoho Books switch in Settings › Zoho off) it logs one "skipped"
  row per part and exits 0. The `/ca/zoho-sync` page shows this job's log
  alongside Sync Now's.

**On Bluehost (cPanel), as set up on the live server on 13 Nov 2026** — step by step in
`docs/guides/DEPLOYMENT_GUIDE_BLUEHOST.md`, sections 9 and 10:

- cPanel's Cron Jobs form has no place for `CRON_TZ`. Find the server's clock first: a one-time job
  `date > <home>/servertime.txt` (all boxes `*`), read the file after 2 minutes, delete the job. Bluehost
  normally shows **MST** (Mountain Time): India is 12 h 30 min ahead (11 h 30 min from March to November,
  when the server is on MDT — every job then runs one hour earlier in India time).
- Create `<home>/logs` (outside `public_html`) before adding the jobs, and set the **Cron Email** at the top of
  the page so failures reach an admin.
- The ten jobs, typed in **server (MST)** hours — Minute / Hour / Day / Month / Weekday (India time):
  e-mails `0 * * * *` (every hour); OC auto-confirm `30 20 * * *` (09:00); Zoho sync `30 10 * * *` (23:00);
  alerts `30 17 * * *` (06:00); exchange rate `35 18 * * *` (07:05); digest `45 19 * * *` (08:15);
  document clean-up `45 13 * * *` (02:15); database backup `30 13 * * *` (02:00); documents backup
  `0 14 * * *` (02:30); test-restore `30 15 1 * *` (04:00 on the 2nd — **Day must be 1**, not `*`).
- Those frequencies are the owner's choice for the live site: e-mails hourly, OC auto-confirm and Zoho once a
  day (staff press **Sync now** / **Sync this order** when something is urgent). The lines above, with
  e-mails every 10 minutes, auto-confirm every 15 minutes and Zoho hourly, are the general recommendation;
  both work.
- If a log says `php: command not found` (or shows an old PHP), use `/usr/local/bin/php`.

All seven scripts print a one-line summary to stdout and exit 0 on
success — redirect that to a log file (as above) so a Bluehost cron failure notice
actually tells you something.

Two more scripts in `app/cron/` are run by hand, never from cron:

- `zoho_cleanup_test_records.php` — removes the records made in Zoho Books
  while testing: customers and vendors named `TEST-…` with their invoices,
  bills and payments, and expenses and journals marked `TEST-`. A dry run
  by default (lists, changes nothing); `--apply` deletes; `--from-sync-log`
  also removes everything this database sent (refused when
  `ZOHO_ENVIRONMENT=production` — test database only); `--by "Name"` and
  `--log ../docs/zoho/zoho-change-log.csv` add one row per deletion to the
  Zoho change log (without `--log` the rows are printed for pasting in).
  Run it before go-live.
- `reorganize_order_files.php` — one-off tidy-up that moves older order
  files into the per-step folders (`01-quotation/`, …). A dry run by
  default; `--apply` moves them. Back up `storage/` and the database first.

### Backups — database and every order document (owner decisions D-260, 6 Nov 2026)

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

**Where the copies go is set in `app/.env`, not in the cron line.** "Outside the web root" means: not inside this
stack's `public_html/`, `app/` or `STORAGE_BASE_PATH` — the scripts refuse those. A folder elsewhere inside the
hosting account's `public_html` is accepted by the scripts but is only safe with folder permission **0700** plus its
own `.htaccess` (`Require all denied`); a browser then gets "Forbidden … Server unable to read htaccess file", which is
the expected, safe answer. Keep the **key in its own folder next to** the backup folder, never inside it.

```
BACKUP_DIR=/home/yourcpaneluser/nexacrest_backups            # outside the web root and outside STORAGE_BASE_PATH (the scripts refuse otherwise)
BACKUP_KEEP_YEARS=10                     # everything kept 10 years, then removed
BACKUP_THIN_AFTER_DAYS=120               # older database copies thinned to one per month; 0 = keep every nightly copy
BACKUP_ENCRYPTION_KEY_FILE=/home/yourcpaneluser/.nexacrest_backup_key
BACKUP_OFFSITE_TARGET=                   # empty until the owner chooses; then an rclone remote, e.g. b2crypt:nexacrest
```

**Retention in one rule:** everything is kept `BACKUP_KEEP_YEARS` (10) years. To save space, database copies
older than `BACKUP_THIN_AFTER_DAYS` (120) days are thinned to the first copy of each month; documents never need
thinning because each file is stored once. Only files named like a backup, inside `BACKUP_DIR`, are ever removed.

**One-time set-up on the server:**

1. Create the folder and the key (keep a copy of the key somewhere safe **away from the server** — without it the
   encrypted copies cannot be opened):
   ```
   mkdir -p /home/yourcpaneluser/nexacrest_backups && chmod 700 /home/yourcpaneluser/nexacrest_backups
   openssl rand -base64 48 > /home/yourcpaneluser/.nexacrest_backup_key && chmod 600 /home/yourcpaneluser/.nexacrest_backup_key
   ```
   Without cPanel Terminal: create the folders in File Manager (permission 0700) and make the key with a one-time cron
   job (all boxes `*`, delete it after 2 minutes):
   `[ -f <keyfile> ] || (openssl rand -base64 48 > <keyfile> && chmod 600 <keyfile>)`. The key file is about 65 bytes,
   permission 0600. Copy its one line into a password manager; never e-mail it or put it in the repository.
2. Add the keys above to `app/.env`.
3. Make the scripts executable: `chmod 700 /home/yourcpaneluser/nexacrest_webapp/scripts/*.sh`
4. Add the cron lines (the scripts read everything else from `app/.env`):
   ```
   CRON_TZ=Asia/Kolkata
   0 2 * * *   /home/yourcpaneluser/nexacrest_webapp/scripts/backup_db.sh >> /home/yourcpaneluser/logs/backup_db.log 2>&1
   30 2 * * *  /home/yourcpaneluser/nexacrest_webapp/scripts/backup_storage.sh >> /home/yourcpaneluser/logs/backup_storage.log 2>&1
   0 4 1 * *   /home/yourcpaneluser/nexacrest_webapp/scripts/backup_test_restore.sh >> /home/yourcpaneluser/logs/backup_test_restore.log 2>&1
   ```
5. Run `scripts/backup_db.sh` and `scripts/backup_storage.sh` once by hand (without Terminal: a one-time cron job each,
   deleted after 2 minutes), then open **Settings › Backups** — both rows should say OK. `BACKUP_DIR` then holds
   `db/` (a `nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc` file — server time in the name — and its `.sha256`),
   `storage/` and `status/`.
6. The test-restore needs a MySQL user allowed to create and drop the scratch database
   (`BACKUP_RESTORE_TEST_DB`). On Bluehost create that database once in cPanel → MySQL Databases (cPanel puts your
   account prefix in front — put the **full** name in `.env`) and, under *Add User To Database*, give the app's own
   database user (`DB_USERNAME`) **ALL PRIVILEGES** on it. No separate user or password: the script uses `DB_USERNAME` /
   `DB_PASSWORD` through a temporary login file it deletes. Run `scripts/backup_test_restore.sh` once the same way; its
   log ends `Test-restore OK: … tables, … orders`. Between runs the database may be missing from cPanel's list — normal.
7. A cPanel Git update can reset the scripts' permissions: after each update check they still show **0700** (a backup
   job failing with "Permission denied" is the sign).

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

## Using Phase E: dashboard, reports, security hardening

- **Dashboard** (`/` — the default landing page after login) — every widget
  is gated independently by permission, so what you see depends on your
  role: active-order counts by stage and "My Approvals" tiles (documents
  awaiting your review, emails awaiting your Level-2 approval, amendments
  awaiting MD approval) need `manage_orders`/`approve_email_send`/
  `approve_documents` as appropriate; overdue balance/freight payments and
  LUT/RCMC expiry warnings need `view_reports`. A client/order search box
  sits at the top regardless of permission. Recent activity pulls from the
  same audit log the Phase D audit viewer reads.
- **Reports** (`/reports`, nav link shown only with `view_reports`) — three
  views: per-client (`/reports/client/{id}`, also linked from a client's own
  page), per-order (`/reports/order/{id}`), and a filterable aggregate view
  (`/reports/aggregate` — filter by date range, stage, incoterm, country)
  with a **Download CSV** link (`?format=csv`) on the aggregate view. A user
  with `manage_report_definitions` can save the current aggregate filter set
  as a named report (private to them, or shared with everyone) and re-run
  or delete it later from `/reports` — saved definitions are deliberately
  scoped to the aggregate report type only (see "Two judgment calls" below).
  Export is CSV, not `.xlsx` — it opens natively in Excel/Sheets and avoids
  pulling in a spreadsheet-writing library.
- **Data masking** — a client's email and phone are shown masked
  (`b***@example.com`, `+91-XXXXX-1234` style) to anyone without
  `view_client_email_full`; Admin/MD have it by default per the seeded RBAC
  matrix. Masking happens in the view layer against the same query everyone
  else uses — there's no separate "restricted" query path to keep in sync.
- **Admin overrides** (`/admin/overrides`, nav link shown only with
  `edit_locked_data`) — batch-edit screens for document reference formats,
  T&C clause text, and payment presets, each requiring a mandatory reason
  before saving. The same mandatory-reason pattern is also wired onto three
  existing screens/actions that needed it retrofitted: **Company Settings**
  (`/settings` — only asks for a reason when something in the form actually
  changed), a client's Buyer Inquiry Ref override (bottom of `/clients/{id}`),
  and an order's status/lock override and an amendment's reference override
  (bottom of `/orders/{id}` and `/orders/{id}/amendments` respectively).
  Every override is confirmed client-side (JS blocks submission with an
  empty reason) and re-validated server-side (the actual gate — never trust
  the client-side check alone), then written to `audit_log` with the reason
  as `audit_log.reason`, the old and new values, and who did it.
- **Non-guessable document filenames** — every generated PDF/DOCX's
  on-disk filename is now a random 32-character hex string
  (`bin2hex(random_bytes(16))`), not `qt_SC-QT-2026-1809001_rev0.pdf`. This
  applies at all three file-write sites in `DocumentGenerationService`:
  initial generation, the approved/FINAL swap, and amendment agreements.
  The human-readable name still appears in the download's
  `Content-Disposition` header (via `file_store.original_filename`) and in
  every on-screen list — only the physical file sitting in `storage/` is
  now unguessable. `storage/` sits outside `public_html/` regardless, so
  this is defense in depth, not the only thing preventing direct access.
- **Password expiry** (`company_settings.password_expiry_days`, seeded at
  90) — enforced on every request now, not just stored. `SessionAuth`
  checks `password_changed_at` against the policy and, if exceeded, flips
  the same `force_password_change` flag a fresh/reset account uses (so it's
  one gate, one screen, not two separate "you must change your password"
  code paths), logs `PASSWORD_EXPIRED_FORCED_CHANGE` to the audit log with
  the exact number of days over policy, and redirects to
  `/force-password-change` exactly like a brand-new account. A user whose
  `password_changed_at` is still `NULL` (never yet completed their first
  forced change) is correctly left alone by this check — they're already
  caught by the ordinary `force_password_change` gate.

## Phase E follow-up: user management and self-service password reset

A gap surfaced immediately after Phase E shipped: there was no way — not
even for Admin — to create a new login, deactivate one, or reset someone
else's forgotten password short of a direct SQL statement, and no
self-service "forgot password" at all. Neither the original spec nor
`ARCHITECTURE.md` ever asked for these (checked both before building), so
this isn't a phase that was skipped — it's a real hole in the user
lifecycle that only became obvious once password expiry (above) made
"I need to change my password" something people would actually hit.

- **User management** (`/users`, nav link shown only with `manage_users` —
  already seeded in Phase A and granted to Admin/Managing Director, just
  never had a screen behind it): list every user (active or not, role,
  2FA status, last login, lock/force-change status), create a new user
  (name, email, phone, role), deactivate/reactivate one, and force-reset
  anyone's password. A created or force-reset user gets a random one-time
  temporary password shown **once**, in the success message, to hand to
  them directly — it is never emailed and never logged anywhere except as
  its bcrypt hash in `users.password_hash`; `force_password_change = 1` is
  always set, so it only has to survive a single login. Force-resetting
  requires a mandatory reason, same pattern as every other Phase E
  override, and logs `USER_CREATED`/`USER_DEACTIVATED`/`USER_REACTIVATED`/
  `PASSWORD_ADMIN_RESET` to the audit log. An Admin can't deactivate their
  own account (checked server-side, not just hidden in the UI). Editing an
  existing user's name/email/role isn't included — deliberately bounded to
  the actions the gap analysis actually named; add it the same targeted
  way if it turns out to be needed.
- **Self-service password reset** (`/forgot-password`, `/reset-password/{token}`
  — both public, unauthenticated routes): enter your email, and if it
  matches an active account, a single-use link valid for 45 minutes is
  emailed via the same `EmailService::sendPlainText()` Phase A's 2FA and
  Phase D's deferred sends already use. The link's raw token is 32 random
  bytes (`random_bytes(32)`); only its SHA-256 hash ever touches the
  database (new `password_reset_tokens` table — mirrors how
  `password_hash` never stores a real password), so a database leak alone
  is never enough to hand out a working reset link. The response message
  is **identical** whether the email matched an account or not, to avoid
  telling an attacker which addresses are real logins. Capped at 5 requests
  per account per hour — this is a public, unauthenticated form that
  triggers an outbound email, i.e. a mail-bombing vector against anyone
  whose address you know, not just an inconvenience. Completing a reset
  marks that token used, invalidates any other outstanding unused tokens
  for the same account (so an old forgotten link can't still be sitting
  there valid), logs `PASSWORD_RESET_VIA_EMAIL`, and — like every other
  password change in this app — runs through the same DB-driven
  `password_min_length`/`password_complexity_json` policy, now extracted
  into `PasswordPolicyService::validate()` so there's one place these
  rules live instead of three copies that could quietly drift apart.
  Needs `SMTP_HOST`/`SMTP_USERNAME`/`SMTP_PASSWORD`/`APP_URL` actually
  configured in `.env` to send for real — with none configured, exactly
  like every other email in this app, it logs instead of sending. This was
  verified without a real mailbox, and that's not a shortcut: `Env::get()`
  re-reads `.env` from scratch on every single request (there's no
  in-memory cache and no long-running worker on typical shared hosting —
  every request is a fresh PHP process), so SMTP credentials are already
  fully deployment-time configurable with zero code changes. Fill in the
  real `SMTP_*` values in `.env` whenever you're ready — no redeploy, no
  restart, nothing to ask a developer to change — and the very next
  password-reset request (or 2FA email, or Phase D deferred send) sends
  for real, using the exact same `EmailService::sendPlainText()` path this
  session already proved works end-to-end for everything except the actual
  SMTP handshake. `app/.env` itself is also gitignored (see `.gitignore`) —
  only `app/.env.example`, with placeholder values, is meant to reach
  version control.

## Phase E follow-up 2: Sample Data Playground

User request (2026-09-19): "can we have some sample records to play with...
this effects only the test data and not actual data... so anybody who is new
on the system can first play with sample data - any any no of users can use
the same data any number of time, but our actual data is always protected" —
a Zoho-style, shared, load-and-clear sample dataset, reachable only through
the UI, that can never put real data at risk.

- **`/sample-data`** (nav link + route both gated on a new `manage_sample_data`
  permission, granted to Admin/Managing Director by the same wildcard every
  other admin permission gets): one button when nothing is loaded ("Load
  Sample Data"), one button when something is ("Clear Sample Data"), and a
  summary table of what's currently loaded. Loading creates 2 clients and 2
  orders, both prefixed `[SAMPLE]` everywhere they're displayed (dashboard,
  reports, order/client lists) so they're never visually confused with real
  records even before you check the flag: one order left brand-new at Stage 1
  (nothing generated yet — for practicing the create-order-and-generate-QT
  flow from scratch), one carried through to Stage 5 with a QT, PI and OC
  actually generated and an advance payment recorded and cleared (so there's
  something with real financials to look at on the dashboard/reports).
  Loading again while sample data is already loaded is refused with a clear
  message ("clear it first") rather than silently creating duplicates.
- **How it stays safe**: every sample client/order is flagged
  `is_sample_data = 1` (new column on `clients` and `orders` — see
  `docs/schema.sql`) the moment it's created, and `SampleDataRepository::clearAll()`
  — the only hard-delete routine in this entire codebase; everything else in
  this app is soft-delete-only via `is_active` — deletes *only* rows reachable
  from a `WHERE is_sample_data = 1` client/order, following the full FK
  dependency graph in `docs/schema.sql` (order_stages, order_products,
  documents, amendments, disputes, file_store, and everything hanging off
  those) in a dependency-safe order, inside one transaction — it either
  clears everything or (on any failure) rolls back to leaving everything
  exactly as it was, never a half-deleted state. It cannot touch a real
  client or order because it never selects by name, id range, or "recently
  created" — only by that one flag, which nothing else in the app ever sets.
  Physical generated PDF/DOCX files are deleted from disk (and their now-empty
  `storage/clients/{...}` folders cleaned up) only *after* the database
  transaction commits, so a failed clear never leaves `file_store` rows
  pointing at files that no longer exist.
- **How the sample data is built**: reuses the exact same repository/service
  calls a real user's request would make — `OrderRepository::create()`,
  `OrderStageRepository::initializeForOrder()`, the same
  `DocumentGenerationService::generate()` every real QT/PI/OC goes through,
  `StageGateService::passAndUnlockNext()` — rather than a parallel fake-data
  insertion path. The point of a playground is that it behaves exactly like
  the real system, because it *is* the real system, just flagged and
  cleanly removable.
- Verified live end-to-end this session: loaded, confirmed both sample orders
  and all 6 generated files (QT/PI/OC × PDF+DOCX) appear on disk and in the
  dashboard/reports, cleared, confirmed zero sample rows remain in any of the
  ~20 affected tables, zero orphaned files on disk, and the one pre-existing
  real client/order (from Phase B's own testing) completely untouched
  throughout — then repeated the whole load → clear cycle twice more to
  confirm it's genuinely repeatable, not a one-shot demo.

### Follow-up (added 2026-09-21): a third order, all the way to closure

This file's own text above flagged the two-order scope as bounded and
"easy to extend ... if you want a third order further along later." Added
exactly that: a third sample client/order on the CIF incoterm and the
"Established Buyer — Post-BL" payment preset (the tier requiring MD
approval and a BL-triggered balance — SOP Tier B), pushed through Supplier
PO, the CFR/CIF-only Freight Payment stage, Packing & BL Instruction,
Commercial Invoice + balance, and Document Despatch & Closure — leaving it
`status = 'complete'` with all nine document types (QT, PI, OC, SUPPO, FDN,
PL, BLI, CI, COOPREP) generated at least once. A Stage-5+ sample order
needs a supplier to attach its Supplier PO to; `suppliers` gained its own
`is_sample_data` flag (SECTION T, `docs/schema.sql`) for exactly this, and
`SampleDataRepository::clearAll()` now also hard-deletes sample suppliers
— otherwise one would leak into the real, global supplier dropdown
permanently every time sample data is loaded.

Real bug this surfaced (fixed both stacks): `DocumentDataAssembler`'s
`incoterm_label` field — the "Incoterm \*" row printed on every single
document type — always named the *loading* port, even for CFR/CIF orders,
where Incoterms® 2020 requires naming the port of *discharge* instead. No
CFR/CIF order had ever been carried through full document generation
before this session, so nothing had exercised the bug: a CIF document's
own "Incoterm \*" field read "CIF Chennai, India" (the seller's own port)
instead of the buyer's actual discharge port. Fixed in
`DocumentDataAssembler`/`documentDataAssembler.js` and the same
copy-pasted line in `AmendmentService`/`amendmentService.js`. (The small
"CIF CHENNAI, INDIA" badge next to the doctype label in the shared
document header is unrelated and untouched — that one's an intentional,
documented quirk copied from the real source templates, not a computed
Incoterms field.)

Verified live end-to-end on both stacks again after the extension: loaded,
confirmed all 3 clients/orders and all 12 generated files for the new
order appear correctly, spot-checked the FDN/CI/BLI PDFs to confirm the
discharge-port fix rendered correctly ("CIF Rotterdam, Netherlands"),
confirmed the pre-existing FOB order's PDFs still correctly show the
loading port (no regression), cleared, confirmed zero sample rows
including the new sample supplier remain, and repeated load → clear once
more to confirm repeatability.

## Protected fields (added 2026-09-19)

User request: all the content that gets baked into generated documents —
starting with Terms & Conditions, but not limited to it — is "necessary,"
none of it can be casually omitted, and over time (staff turnover,
forgotten rationale) someone could edit or delete something critical with
no warning that it mattered. Confirmed scope after discussion: all 27 T&C
clauses (no exceptions — every current clause is mandatory), both Payment
Presets, and 17 Company Settings (the 12 banking/GST/LUT/RCMC fields plus
the 3 document reference-format strings) ship **protected** by default.
One shared mechanism (`is_protected`), reused across all three tables —
not a bespoke rule per table:

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

Verified live end-to-end this session: a scratch database confirmed the
seeded counts (27/27 clauses, 2/2 presets, 17/48 settings protected); a
protected field's edit was rejected without an unlock and accepted with
one, both correctly logged; a second, independently created test user
approved a peer's lock/unlock request while self-approval was blocked
server-side (not just hidden in the UI); a rejection left the flag
untouched; a duplicate pending request for the same field was refused; and
the database triggers themselves rejected a direct DELETE and a
direct blanking UPDATE against a protected row. This exact feature — same
schema, same enforcement, same UI pattern — also exists in the Node/MySQL
port of this app; the two are built and verified in parallel, never one
without the other.

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

## What's deliberately not built yet (by phase, not by mistake)

> **Update 6 Nov 2026:** this list was written phase by phase. Since then several items were built:
> payment presets have their own Admin page (`/payment-presets`) and T&C clause text is edited under
> Settings › Admin overrides; the alert job e-mails as well as notifying in the app; the Buyer PO and supplier
> PO have upload flows; most document types now also have a Word copy; users can be edited from
> `/users`. Items below that are not covered by this note still stand.

- Actually sending Phase D's deferred emails requires real SMTP
  credentials in `.env` — with none configured, `EmailService::sendWithAttachment()`
  logs and returns `false` (the same dev-fallback pattern
  `sendPlainText()` already used in Phase A), and the cron marks the row
  `failed` rather than silently pretending it worked. This is intended
  behavior, confirmed during this phase's own testing, not a bug to fix —
  point `SMTP_HOST`/`SMTP_USERNAME`/`SMTP_PASSWORD` at a real account and
  it sends for real with no code changes.
- A management UI for Terms & Conditions clauses and payment presets
  themselves (both are edited via direct SQL today, same as Phase A–C) —
  deliberately out of Phase D's scope, per `docs/ARCHITECTURE.md`'s own
  description of this phase.
- A fully generic field-level override UI for amendments — Phase D only
  covers the fields the spec's Section 8 worked example actually needs
  (advance %, balance trigger option + days, balance amount). Amending a
  field outside that set (e.g. Incoterm, currency) isn't wired up; add a
  column + form field + `OrderRepository::applyAmendmentOverride()` case by
  case if you need one.
- Automatic email-based escalation for overdue alerts (LUT/RCMC expiry,
  freight overdue, dispute overdue) — `check_alerts.php` creates in-app
  notifications only (see "Using Phase D" above). Wiring the same
  conditions to outbound email is a small, mechanical follow-up against
  `EmailService::sendPlainText()` whenever that's wanted; cut here to keep
  this phase bounded rather than building a second templated-email path
  alongside the buyer-facing deferred-send pipeline.
- Login codes are e-mail only (owner decision D-265, 7 Nov 2026): every
  staff member and every buyer gets a 6-digit code by e-mail at each login;
  MD / ED / Admin can make backup codes on My Account. `SmsService` is kept
  but not used for login codes.
- Editing/voiding a generated document, and a proper file-upload flow for
  the Buyer PO itself (Stage 2 currently just records its reference number,
  per your Phase B scope answer) — flagged as open questions, not bugs.
- DOCX (internal-copy) generation stays QT/PI/OC-only — the new Phase C
  document types (BUYERPO, SUPPO, FDN, PL, BLI, CI, COOPREP) are PDF-only.
  None of their source templates carry the same "content-parity internal
  Word copy" requirement pattern QT/PI/OC had, so `docx_generation_settings`
  was deliberately not expanded for them — not an oversight.
- `order_freight.freight_cleared_at`/`freight_cleared_by` exist in the
  schema but are not written by any Phase C controller — the canonical
  "freight payment cleared" flag is `order_payment_status.freight_cleared_at`
  (matching how advance/balance clearing already worked), which is what the
  Stage 6→7 gate and the CI's payment settlement both actually read. Left
  in the schema for a possible future per-shipment freight-reconciliation
  view rather than dropped, since removing a column is a migration, not a
  code change.
- Excel (`.xlsx`) report export — Phase E ships CSV only (see "Judgment
  calls made in Phase E" below for why); swap in PhpSpreadsheet later if a
  native Excel binary (formulas, multiple sheets, formatting) turns out to
  actually matter, since `ReportRepository`'s query methods already return
  plain arrays independent of the export format.
- Scheduled/emailed delivery of a saved report (e.g. "email me the
  aggregate report every Monday") — saved report definitions can be
  re-run on demand from `/reports`, but nothing cron-driven sends one out
  automatically. A mechanical follow-up against `check_alerts.php`'s
  existing cron pattern if wanted.
- A management UI for adding a wholly new admin-override *category* beyond
  the six Phase E ships — see "Judgment calls made in Phase E" below for
  why this was scoped to named fields rather than built generically.
- Running the backups on the real Bluehost account — since D-260 (6 Nov 2026)
  the scripts are in `scripts/` and are tested end to end in the sandbox
  (self-test plus a real dump, restore and documents run), but the cron lines
  and the key file must be set up on the server (see "Backups" above).
- Editing an existing user's name/email/role from `/users` — the Phase E
  follow-up covers create/list/deactivate/reactivate/force-reset only (see
  "Judgment calls made in the Phase E follow-up" below).
- 2FA-via-SMS for a self-service password reset, or any second factor on
  the reset flow itself — the emailed link's 45-minute expiry plus
  single-use enforcement is the only safeguard. If you want a reset to
  additionally require the account's existing 2FA method, that's a
  targeted addition to `AuthController::resetPassword()`, not a redesign.
- An actual test send through a real SMTP account — deliberately not done
  from this sandbox (see "Phase E follow-up" above): the credentials are
  yours to add at deployment, and `Env::get()`'s per-request re-read means
  no code change is needed when you do.

## Real bugs found and fixed during Phase B's own end-to-end testing

Building Phase B included actually generating real documents against a
live database and a running server (not just reading the code), which
caught a few genuine defects — fixed in this delivery, not left for you to
find:

- `company_settings.description` was `VARCHAR(255)`, too short for several
  of the longer explanatory notes seeded in Phase B (e.g. why
  `lut_expiry_date` is a placeholder). Widened to `TEXT`.
- Missing `SET NAMES utf8mb4;` at the top of `seed.sql` caused every
  em-dash and similar punctuation to double-encode into mojibake on
  import, depending on the importing client's own default charset — not
  visible until you actually opened a generated PDF. Fixed (see step 1
  above) and re-verified byte-for-byte via `HEX()`.
- `DocumentDataAssembler`'s money-formatting helpers were typed
  `?string` but were actually called with `float` values (order totals,
  computed advance/balance amounts) — a real `TypeError` on every document
  generation attempt. Widened to accept `string|int|float|null`.
- The Proforma Invoice template cited the Quotation's reference number,
  and the Order Confirmation template cited the Proforma Invoice's, but
  nothing populated those two fields — fixed by having
  `DocumentDataAssembler` look up each prior document's own reference from
  the `documents` table.
- A generated document's on-disk filename embedded the document reference
  verbatim (e.g. `SC/QT/2026/1809001`), including its `/` characters. That
  broke the download's `Content-Disposition` filename (`basename()` cut it
  down to just the last segment). Fixed: the reference shown *on* the
  document keeps its real `/` separators; the filename uses a dash-joined
  version instead.

## Real bugs found and fixed during Phase C's own end-to-end testing

Same standard as Phase B — actually generating every one of the 11
document types against a live database, over real HTTP requests, for two
full order runs (FOB and CIF) — caught these, all fixed in this delivery:

- `DocumentGenerationService`'s filename builder called
  `sanitizePathSegment(string $value)` on the document reference
  unconditionally — but COOPREP's `document_types.ref_format` is `NULL` by
  design (it's internal-only and has no reference number on the real
  source template), so `$documentReference` is legitimately `null` for it.
  First-ever COOPREP generation attempt threw a `TypeError` and silently
  failed (redirected back to the order screen with a flash error — easy to
  miss without checking the server log, which is exactly how this was
  caught). Fixed: falls back to a timestamp for the filename, and the
  on-document filename fallback (`$filenameSafeReference`) uses the
  document type code instead of a null reference.
- `DocumentDataAssembler::supplierPoBlock()` selected `supplier_type` from
  the database join but never mapped it into the array handed to the
  template — every Supplier PO rendered "Supplier Type: TBC" regardless of
  what was actually recorded for that supplier. Fixed: added the missing
  key.
- The Freight Debit Note's "TOTAL AMOUNT DUE" field concatenated the
  already-comma-formatted freight rate and insurance amount as a display
  string (e.g. literally printing "2,200.00 + 280.00" as the total, not
  the sum) for CIF orders. Fixed: the actual numeric total is now computed
  in `DocumentDataAssembler::freightBlock()` (`total_freight_and_insurance`)
  before formatting, and the template just prints that one number.
- SUPPO needed its own reference number to exist *before*
  `DocumentGenerationService::generate()` ever runs for it — the material/
  commercial terms captured in `order_supplier_po.supplier_po_reference`
  (`NOT NULL`) have to be entered before the document can render them, but
  `generate()` only knew how to mint a *new* reference number, which would
  have produced a second, different SUPPO reference disagreeing with the
  one already saved on that row. Fixed by adding
  `DocumentGenerationService::preAssignedReferenceFor()`, a narrow special
  case that reuses the reference already on `order_supplier_po` for a
  SUPPO's first-ever generation, rather than minting a second one from the
  same day's sequence.
- The Stage 4→5 gate ("Confirm Buyer Acknowledged Order") didn't exist at
  all going into this phase — Phase B built "generate the Order
  Confirmation" but never the buyer's side of that gate per StageGate.docx
  (*"NexaCrest confirms order and advance received → Buyer acknowledges"*).
  Without it, nothing could ever unlock Stage 5, permanently stalling every
  order at Stage 4. Added as `OrderController::confirmBuyerAcknowledged()`.

## Real bugs found and fixed during Phase D's own end-to-end testing

Same standard as every phase before it — nothing below was visible from
reading the code; each was caught by actually driving the full workflow
over real HTTP requests, then generating a real downstream document and
looking at the real rendered output:

- **The amendment "balance terms" override had no effect on future
  documents — the single most important thing this feature has to do, and
  it silently didn't do it.** The original design added
  `orders.balance_trigger_text_override` as a free-text `VARCHAR(500)`
  column, on the assumption that the PI/CI templates displayed the balance
  payment terms as literal stored text. In fact, `balance_trigger_option`
  is an ENUM (`A_BEFORE_SHIPMENT` / `B_AGAINST_BL`) that the QT/PI/OC
  templates switch on to show an entirely hardcoded sentence, substituting
  only the day count (`balance_days`, an integer). The free-text override
  column was never read by any template, so an activated amendment updated
  the database correctly but a freshly generated PI kept showing the *old*
  terms — caught by activating a test amendment, generating a fresh PI
  immediately afterward, and visually inspecting the rendered PDF (it still
  read "within 3 days of BL date", not the amended "within 15 days").
  Fixed by replacing the free-text column with two structured ones —
  `orders.balance_trigger_option_override` (same ENUM) and
  `orders.balance_days_override` (INT) — matched by
  `amendments.amended_balance_trigger_option`/`amended_balance_days`, with
  `OrderRepository::find()`'s `COALESCE()` updated to resolve both. Re-ran
  the same activate-then-regenerate-PI test afterward and confirmed the new
  terms actually appear (`docs/schema.sql`'s `amended_balance_terms`
  free-text column is kept, unchanged — it correctly serves the Amendment
  Agreement PDF's own legal prose, a separate concern from the live-system
  override this bug was about).
- **The Amendment Agreement PDF's own "Original Payment Terms" section
  leaked the raw ENUM code** (literally the text `A_BEFORE_SHIPMENT`) into
  a document meant to be signed by the buyer — `AmendmentService`'s
  snapshot-building code concatenated `$order['balance_trigger_option']`
  directly into the display sentence instead of rendering it. Caught by
  visually inspecting the generated AMD PDF. Fixed by extracting the
  QT/PI/OC templates' own sentence logic into a single shared helper,
  `DocumentDataAssembler::balanceTriggerSentence()`, and using it in both
  places — so the buyer-facing templates and the amendment snapshot can
  never again say two different things (or one say something unreadable)
  for the same underlying value.
- **`check_alerts.php` had no same-day dedup guard** — every daily run
  would create a brand-new "LUT expires in N days" (or RCMC/FDN/dispute)
  notification for as long as the condition stayed true, flooding the
  recipient's notification bell with near-identical duplicates day after
  day. Caught by running the cron script twice in a row during testing and
  watching the notification count double. Fixed by adding
  `NotificationRepository::existsToday()` and having the cron skip a
  (user, alert type, related order) combination that already fired today —
  confirmed by re-running the cron immediately after a fresh alert and
  seeing it correctly create zero new rows, then confirmed a genuinely new
  condition (a second, separately-overdue dispute) still alerts.

## Real bugs found and fixed during Phase E's own end-to-end testing

- **CSV exports were silently corrupted by a PHP 8.4 deprecation notice.**
  `fputcsv()` in 8.4 emits a deprecation warning when called without an
  explicit `$escape` argument, and with dev-mode `display_errors=1`, that
  warning text was printed directly into the response body *before* the
  CSV's own header row — so every exported file opened with a garbled first
  line instead of clean column headers. Caught by actually downloading a
  CSV and looking at the raw bytes, not by reading the code (the code
  looked completely ordinary). Fixed by passing the delimiter/enclosure/
  escape arguments explicitly (`,`, `"`, `\`) to every `fputcsv()` call in
  `app/src/Helpers/Csv.php`; re-downloaded and confirmed a clean header row
  with no warning text ahead of it.
- **`.btn-danger` was referenced by four Phase D views but never actually
  defined** — `amendments/index.php`, `email/approvals.php`,
  `orders/show.php`, and `reviews/queue.php` all used
  `class="btn-sm btn-danger"` for reject/danger actions, but `app.css` only
  ever defined `.btn-success`; the buttons silently fell back to the
  default navy button style with no visual distinction for a destructive
  action. A latent gap from Phase D, only surfaced now while extending
  those same views with the new admin-override forms (which also use
  `btn-danger`). Fixed by adding `.btn-danger`/`.btn-danger:hover` to
  `app.css`, matching the existing `.btn-success` pattern.
- **Downloading an amendment's PDF silently truncated the filename to just
  the last path-like segment** (`1809001.pdf` instead of the intended
  `AMD SC/AMD/2026/1809001.pdf`) — found while verifying the UUID-filename
  hardening above, which required actually downloading a freshly generated
  amendment document rather than just checking the database row.
  `DocumentController::download()` built the `Content-Disposition` header
  with `basename($file['original_filename'])`, but `original_filename` is a
  **display name**, not a filesystem path — `basename()` treats any `/` in
  it as a directory separator and keeps only what's after the last one, and
  every amendment reference (`SC/AMD/2026/1809001`) contains three of them.
  This bug predates Phase E — it was always latent in `DocumentController`
  — but only an amendment document's `original_filename` was ever built
  from a raw, un-sanitized reference (`"AMD {$amendment['amendment_reference']}.pdf"`;
  every other document type already replaced `/` with `-` when building
  its own `original_filename`), so it never surfaced until this session
  actually generated and downloaded one. Fixed in two places, deliberately:
  the immediate cause (`DocumentGenerationService::generateAmendment()` now
  replaces `/` with `-` before building the filename, matching every other
  document type), and the underlying weakness
  (`DocumentController::download()` no longer uses `basename()` on a
  display name at all — it strips slashes/backslashes/control characters
  directly, so no future document type or reference format can reintroduce
  the same class of bug). Re-verified by re-downloading the same amendment
  document and confirming the full filename now arrives intact, then
  re-checked QT and OC downloads (whose references never contained the bug
  to begin with) to confirm the header-construction change didn't alter
  their already-correct filenames.

## Two judgment calls made in Phase A, beyond schema.sql/ARCHITECTURE.md

1. **Dropped `vlucas/phpdotenv` as a dependency.** `app/src/Config/Env.php`
   is a ~70-line hand-rolled `.env` parser instead. One fewer Composer
   package to install on shared hosting for a few lines of KEY=VALUE
   parsing. Swap it for the real package later if you'd rather — nothing
   else in the app needs to change, since everything reads through
   `Env::get()`.
2. **Admin/RBAC-facing screens (login, settings, assets, clients, orders)
   use plain PHP templates, not Twig.** Twig is reserved for the actual
   business documents (QT/PI/OC/CI/...) where "zero business value
   hardcoded in a template" is the whole point of the schema. The app's own
   screens carry no business data of that kind, so pulling in Twig for them
   just adds a dependency with no corresponding benefit.

## Judgment calls made in Phase E

1. **Section 13's "every field editable, with mandatory reason" was
   deliberately NOT built as a generic "edit any column of any table" tool.**
   An unrestricted raw-column editor driven from a web form is itself a
   security anti-pattern — doubly so inside a *security-hardening* pass —
   so instead every concretely-named category in the spec that had no
   existing edit path got its own targeted, validated edit action: document
   reference formats, T&C clause text, payment presets, a client's Buyer
   Inquiry Ref, an order's status/lock, an amendment's reference. Company
   Settings and Company Assets already covered several other named
   categories (bank details, RBI codes, LUT, GSTIN/IEC/PAN, watermark,
   signature/seal) from Phase A — those just needed the mandatory-reason
   requirement retrofitted, not new screens. If a genuinely new "editable"
   field turns up later that isn't one of these, it needs its own
   validated action added the same way — not a blanket column editor.
2. **Reports export as CSV, not `.xlsx`.** CSV opens natively in Excel,
   Sheets, and every spreadsheet tool without a plugin, and it avoids
   pulling in a spreadsheet-writing library (PhpSpreadsheet) — a real
   dependency with a real install cost on shared hosting — for a feature
   the spec doesn't actually require to be a native Excel binary.
3. **Saved report definitions (`report_definitions`) are scoped to the
   aggregate report type only** — not per-client or per-order reports.
   Those two are keyed to one specific client/order id each time you view
   them; there's no filter set to save and re-run, so "saved report"
   doesn't mean anything for them the way it does for the aggregate view's
   date/stage/incoterm/country filters.
4. **Password expiry reuses the existing `force_password_change` flag and
   redirect rather than adding a second, parallel "your password expired"
   gate.** One flag, one screen, one code path a developer has to reason
   about for "can this user do anything but change their password right
   now" — an expired-password user and a freshly-reset-password user end
   up needing the exact same restriction, so they share the exact same
   mechanism instead of two gates that would need to stay in sync forever.

## Judgment calls made in the Phase E follow-up (user management + password reset)

1. **An admin-created or admin-force-reset password is shown once in a
   flash message, never emailed.** The alternative — emailing a temp
   password — puts a real (if temporary) credential in a mail server's
   logs and the recipient's inbox indefinitely; a flash message that's
   gone the moment you navigate away, backed by `force_password_change = 1`
   so it can only ever be used once, is the smaller attack surface. The
   *self-service* reset flow is different on purpose: it emails a
   single-use, hashed, 45-minute link — never a password.
2. **User management doesn't include editing an existing user's name,
   email, or role.** Scoped to exactly what the gap analysis named
   (create, list, deactivate/reactivate, force-reset) rather than a full
   user-editor screen, matching the same bounded-scope call Section 13's
   admin overrides made in Phase E proper — see judgment call #1 above.
3. **Password reset tokens are capped at 5 requests per account per hour,
   not per IP.** A per-IP cap would lock out an entire office or VPN exit
   node over one person's mistake; a per-account cap only throttles
   requests for one specific mailbox, which is also the actual abuse
   vector (mail-bombing a real inbox) this guard exists to stop.
4. **`PasswordPolicyService` was extracted from `AuthController::forcePasswordChange()`'s
   inline validation rather than left copy-pasted a third time** into the
   new reset-password action. Three independent copies of the same
   min-length/complexity checks is how a policy quietly drifts — change it
   in one place and the other two silently fall behind — so this was
   worth the small refactor before adding a third caller, not after.

## Judgment calls made in the Sample Data Playground

1. **Three sample orders — Stage 1, Stage 5, and full Stage 9 closure —
   not a scenario for every possible variant.** One left untouched at
   Stage 1; one FOB order pushed to Stage 5 with three documents and a
   cleared advance payment; one CIF order (on the MD-approval-tier payment
   preset) pushed all the way to a closed Stage 9 with all nine document
   types generated, exercising the CFR/CIF-only Freight Payment stage no
   earlier sample order ever touched. Enough to see every stage and every
   document type at least once without trying to also manufacture a
   dispute, an amendment, and a quantity-shortfall buyer-approval upload
   into the same fixed dataset — those are each real scenarios, but
   bolting all of them onto one "Load Sample Data" button would make it
   slower and harder to reason about for what it's actually for: a new
   user's first walkthrough. Still easy to extend later the same way (add
   another `createSampleOrder()` + a longer "advance to stage N" helper in
   `SampleDataService`) if a specific scenario's UI needs its own sample to
   look at.
2. **This is the first hard-delete in the entire codebase.** Every other
   table in this app is soft-delete-only (`is_active`, `superseded`, etc.) —
   deliberately, per the original spec's audit/history requirements. Sample
   data is the one deliberate exception, and it's fenced off as tightly as
   possible: scoped to a dedicated `is_sample_data` flag that only
   `SampleDataService::load()` ever sets, checked at the *client and order*
   level (not re-derived from each child table), and run inside a single
   transaction. `audit_log` rows referencing a since-cleared sample
   client/order are deliberately left in place rather than also purged —
   `entity_id` there was never a real foreign key (checked: no constraint in
   `docs/schema.sql`), so they remain valid, harmless history instead of
   orphaned references, exactly like the DOCUMENT_GENERATED/PERMISSION_DENIED
   history that already survives Phase A's own account deactivations.
3. **Shared, not per-user.** Loading doesn't namespace the sample data to
   whichever admin clicked the button — it's one dataset the whole
   organization shares, matching the literal request ("any any no of users
   can use the same data any number of time"). Two people loading it back to
   back will hit the "already loaded, clear it first" guard rather than
   silently doubling up — the simplest behavior that still satisfies "any
   number of times," without inventing a multi-tenant sample-data model this
   single-organization app has no other use for.
4. **Client names carry a `[SAMPLE]` prefix in addition to the DB flag.**
   Belt-and-suspenders: the flag is what actually protects real data from
   `clearAll()`, but a human skimming the client list, dashboard, or a report
   should never have to check a hidden column to know a row isn't real.
5. **Sample orders use generic placeholder geography** ("Test Country"),
   matching the placeholder convention already established for Phase E's own
   test account, rather than naming any real country or region.

## RBAC matrix note

The roles/permissions seeded in `docs/seed.sql` (Admin, Managing Director,
Export Executive, Accounts Executive, Logistics Executive, Viewer/Auditor)
are a first-cut working default, not a verbatim transcription of a
role-by-role table from the spec. Easy to correct — it's all DB rows, no
code changes needed. Phase B's new routes are gated on `manage_orders`
(create/advance clients and orders), `generate_documents`, and
`download_pdf` — all three already assigned to every operational role in
the seeded matrix. Phase D adds two new permissions: `cross_verify_documents`
(assigned to Export/Accounts/Logistics Executive) and `approve_email_send`
(Admin/MD-only, via the existing wildcard) — review both against your real
org chart before going live.

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

## Document template fidelity verification pass (added 2026-09-21)

Standing tracked item to re-verify every document type's fidelity, not just
the ones a specific feature task happened to touch. Used the just-extended
Sample Data Playground (see above) to generate a full CIF order through
every one of the nine document types plus the Buyer PO acceptance letter,
then visually inspected every rendered PDF page by page (`pdftoppm` +
direct image review, not just `pdftotext`) on both stacks. Four real,
previously-undiscovered bugs found and fixed, all four because this was the
first time a CFR/CIF order with real crate/shipping data had ever been
carried through every document type in one continuous run:

1. **`OrderRepository::setPiDates()`/`orderRepository.setPiDates()` existed
   but was never called anywhere.** Every Proforma Invoice ever generated,
   in the whole history of the app, showed "VALID UNTIL * TBC" on the
   document itself, and the PI-send email template's `{pi_valid_until}`
   placeholder (`docs/seed.sql`) would have rendered blank in every email
   ever sent to a real buyer. Fixed by computing and storing `pi_date`/
   `pi_valid_until` (today + `pi_validity_days`, mirroring how
   `quotation_valid_until` is already computed at order creation) at the
   moment `DocumentGenerationService::generate()`/
   `documentGenerationService.generate()` actually generates a PI.
2. **The Packing List's crate-level breakdown skipped the number-trimming
   every other quantity/weight field in the same document gets.**
   `order_crates`' DECIMAL columns were passed straight to the template,
   so every Packing List ever generated showed "10.00" pcs / "1600.00" kg /
   "9.2500" m³ in the crate table instead of the trimmed "10" / "1600" /
   "9.25" used everywhere else on the same page. Fixed by running the same
   trailing-zero trim the rest of `DocumentDataAssembler`/
   `documentDataAssembler.js` already uses.
3. **The BL Instruction Sheet's "Container Type / Size" field read
   `order.container_type`** (the rough estimate entered at order creation,
   before a container is even booked) **instead of `shipping.container_type`**
   (the actual, confirmed container type entered on the same Packing/BL
   Instruction form this exact document is generated from) — every sibling
   field in the same table row (Container No., Seal No.) already correctly
   read from `shipping.*`; this one field alone hadn't been updated to
   match. A real order that changed container type between quotation and
   actual booking (not unusual — the order-creation estimate is explicitly
   "TBD at packing" by default) would have shipped a BL Instruction Sheet
   telling the CHA/shipping line the wrong container size.
4. **The Buyer PO Acceptance letter's payment-terms sentence was a static
   string that only ever described the "before shipment" balance trigger**,
   regardless of which one the order's actual payment preset uses. Every
   order on the "Established Buyer — Post-BL" preset (balance payable
   against the Bill of Lading, not before shipment) would have had its
   buyer sign and return a Purchase Order Acceptance letter stating
   payment terms that were flatly wrong — a real, legally-significant
   defect, since this specific document exists for the buyer to
   countersign and confirm agreement to. `DocumentDataAssembler`/
   `documentDataAssembler.js` already had a single-source-of-truth
   `balanceTriggerSentence()` helper for exactly this sentence (used by
   `AmendmentService`'s frozen snapshot text) — the Order Confirmation
   template had its own independent, correct copy of the same if/else
   instead of calling it, and the Buyer PO template had no branch at all.
   Added a `financial.balance_terms_text` field computed once via the
   existing helper; both templates now read it, eliminating the
   duplicate-logic drift risk the helper's own docblock had already
   flagged as the reason it existed.

Also reconfirmed (no new issues): the CIF-specific `incoterm_label` fix from
the Sample Data Playground follow-up above renders correctly across QT, PI,
OC, FDN, PL, BLI, and CI; the Established Buyer/Post-BL preset's advance/
balance math is internally consistent end to end (verified via the CI's own
"Advance + Balance = FOB Value" self-check line); and the FOB order's
"payable before shipment" branch still renders correctly (checked
specifically to rule out a regression from fix #4). Every fix verified live
on both stacks: regenerated the affected document, `pdftotext`'d or
`pdftoppm`'d the actual output, confirmed the fixed text appears, and
confirmed the pre-existing correct branch (FOB / A_BEFORE_SHIPMENT) still
renders unchanged.

## Top-tier UI/UX pass (added 2026-09-21)

Standing item on the tracked task list: give the whole staff app a proper
UI/UX pass rather than leaving it at "functional but plain." Scoped to the
highest-leverage, lowest-risk fixes first — everything below lives in the
shared layout (`layout/base.php`/`base.njk`) or the shared stylesheet
(`app.css`), so every one of the ~60 views in both stacks gets it for free,
with zero per-view template changes needed for most of it:

- **The navigation bar was the single biggest problem in the app.** A user
  with most permissions saw ~20 links crammed into one unwrapped row —
  unreadable on a normal laptop screen, let alone a tablet. Regrouped into
  three permission-gated dropdowns (`Operations`, `Insights`, `Admin`) built
  from plain `<details>/<summary>` — no JavaScript at all, so nothing new
  for either stack to keep in sync: native keyboard support, native
  tap-to-open on mobile, and a group only renders if at least one of its
  links is actually visible to the current user's permissions. Dashboard,
  Reference Library, My Reviews, and Super Admin (a distinct high-privilege
  mode switch, not just another settings page) stay as standalone top-level
  links.
- **Nothing in the app degraded gracefully below desktop width before this
  pass**, despite the viewport meta tag already being present. Added: a
  checkbox-driven hamburger toggle (still no JS) that collapses the whole
  nav + user menu behind a "☰" on screens under 900px; the nav's dropdowns
  switch from floating panels to a plain nested list on mobile; the
  8-column product-line grid on the order create/edit forms collapses to
  one stacked column; `.card` (which nearly every table lives inside)
  scrolls horizontally on its own when content is wider than the screen,
  instead of the whole page gaining a horizontal scrollbar.
- **Real, live bug found and fixed**: the "Notifications" link in the
  top-right user menu had no color rule of its own, so it inherited the
  global link color (a dark navy) against the dark navy header — nearly
  unreadable, on every single page, for every logged-in user. Every other
  topbar link was already explicitly white; this one was missed. Fixed with
  an explicit `.topbar-user a` rule.
- **Real, live inconsistency found and fixed**: the width/focus rules for
  form inputs only ever listed `text`/`email`/`password`/`file` — every
  `type="date"` (20 of them), `type="number"` (4), and `type="datetime-local"`
  (2) field across the app rendered at the browser's tiny native default
  width next to full-width siblings in the same form (most visible on the
  Audit Log filter form: `Entity Type` was full width, `Entity ID`/`User ID`
  were not). Extended the same width + focus-ring rules to cover them.
- **Accessibility basics that were entirely missing**: no visible
  `:focus-visible` state anywhere (keyboard-only navigation had nothing to
  show where focus was), and no focus styling on form fields beyond the
  browser's inconsistent default outline. Added a brand-colored focus ring
  on every interactive element and a matching focus/border treatment on
  every text-like input, `select`, and `textarea`.
- **Small polish**: a subtle shadow on `.card` for depth (it was a flat
  bordered box), a `transition` on buttons/links/inputs so hover/focus
  states don't snap, and a dimmed/`not-allowed` cursor style for disabled
  buttons.

Verified live in a real browser (Playwright + the pre-installed headless
Chromium) on both stacks, not just by reading the CSS: logged in as the
seeded Admin, screenshotted the nav at desktop width (all three groups
present, "Notifications (4)" now legible), opened the Admin dropdown and
confirmed all nine of its links render, resized to a 390px phone viewport
and confirmed the hamburger menu opens/closes and every link is reachable,
confirmed the Audit Log's wide activity table scrolls inside its own card
with the page itself at zero horizontal overflow (`scrollWidth ===
clientWidth` at the `<html>` level), confirmed the order-create form's
product-line grid collapses to one column on mobile and is unchanged at
desktop width, and confirmed the login page has zero horizontal overflow
down to a 320px-wide viewport (the narrowest phone screens still sold).
Not a claim that every one of the ~60 views is now individually polished —
this pass targeted the shared layout/stylesheet fixes that reach every
view at once; a view-by-view pass is a reasonable next increment if you
want one.

## Verifying this delivery yourself

Every claim above was checked, not assumed. Phase B's checks (below) were
re-run against the final schema, Phase C added two full order runs of its
own, and Phase D added its own workflow-level verification, including
re-running it after the balance-terms bug fix above to confirm the fix
actually works, not just that it compiles:

- `docs/schema.sql` + `docs/seed.sql` applied cleanly to a fresh MariaDB
  instance (52 tables, 76 foreign keys, zero errors) as the very last step
  before this delivery was packaged.
- **Phase E follow-up (user management + password reset), end-to-end over
  real HTTP requests:** created a user through `/users`, logged in with its
  one-time temporary password, confirmed it correctly landed on
  `/force-password-change`. Confirmed an Admin can't deactivate their own
  account (server-side, not just a hidden button), deactivated a different
  user, and confirmed that account's own login attempt was actually
  rejected — not just flagged in the database. Force-reset that user's
  password without a reason (rejected) and with one (succeeded, logged to
  `audit_log`). For the reset-password flow: requested a reset, pulled the
  raw token out of the (dev-fallback) logged email body, confirmed its
  SHA-256 hash matched the stored `password_reset_tokens` row exactly,
  completed the reset, logged in with the new password, then replayed the
  same token and confirmed it was correctly rejected as already used.
  Fired the request 6 times in one hour against the same account and
  confirmed only 5 token rows were created — the 6th silently did nothing
  but still show the same success message (not a bug: the enumeration
  guard requires an identical response either way). Confirmed a garbage
  token and a valid-but-now-deactivated-user's token both fail the same
  way. All of this was run without real SMTP configured, by design — see
  "Phase E follow-up" above for why that's a complete proof, not a
  shortcut: `Env::get()` re-reads `.env` on every request, so plugging in
  real `SMTP_*` credentials at deployment time needs no further testing of
  the application logic itself, only of the mail server's own reachability.
  Every table this follow-up touched or added was returned to its exact
  pre-testing state before packaging — the test user, its audit log
  entries, and every `password_reset_tokens` row were deleted, and the
  seeded Admin account's `force_password_change` flag was restored to `1`.
- **Sample Data Playground, end-to-end over real HTTP requests:** loaded
  sample data via `/sample-data`, confirmed 2 clients / 2 orders appeared
  with the `[SAMPLE]` prefix on the dashboard and in `/reports`, confirmed
  the second sample order's 3 documents (QT/PI/OC) and 6 generated files
  (PDF+DOCX each) existed both in `file_store` and on disk, and confirmed
  its `order_payment_status` row showed the correct 40%/60% advance/balance
  split computed from its own product lines. Cleared it and confirmed, by
  direct SQL count, that every one of the ~20 affected tables had zero
  `is_sample_data = 1` rows left, every one of those 6 files was gone from
  disk, and the per-client storage folders were removed — while the one
  pre-existing real client and order (from Phase B's own testing) were
  untouched throughout, confirmed by id and by row count before and after.
  Also deliberately tried loading twice in a row without clearing in
  between (correctly refused, "clear it first"), and ran the full
  load → clear cycle three times total to confirm it's genuinely
  repeatable rather than a one-shot demo. One real bug was caught and
  fixed during this verification: the storage-folder cleanup was
  originally looking up each sample client's folder name *after* the
  client row (and the `client_unique_number` it needed) had already been
  deleted, so it silently cleaned up nothing — moved that lookup to before
  the delete transaction runs; re-verified clean afterward.
- **Phase E, end-to-end over real HTTP requests** (cookies + scraped CSRF
  tokens, logged in as Admin against the live database, `php -S` front
  controller): loaded the dashboard and every report view (`/reports`,
  `/reports/client/{id}`, `/reports/order/{id}`, `/reports/aggregate`) and
  confirmed 200s and real data; downloaded the aggregate CSV export and
  inspected the raw bytes for a clean header row (this is what caught the
  `fputcsv()` bug above). Generated an Order Confirmation document,
  confirmed its on-disk filename was a 32-character hex string (not
  `oc_SC-OC-2026-1809001_rev0.pdf`) via a direct `ls` and a matching
  `file_store.uuid_filename` DB row, and downloaded it to confirm the
  `Content-Disposition` header still showed the human-readable name.
  Assigned a reviewer and approved the document as that reviewer to trigger
  `finalizeApproval()`, then repeated the same on-disk-filename + download
  check against the new approved/FINAL file. Created a fresh amendment
  request, MD-approved it, and generated its Amendment Agreement document
  to trigger `generateAmendment()`, repeating the same check a third time —
  this is what caught the `Content-Disposition` truncation bug above, since
  the amendment reference is the one document reference that contains `/`
  characters. Verified password expiry by backdating a real user's
  `password_changed_at` to 100 days ago (policy: 90), confirming the very
  next request redirected to `/force-password-change`, confirming
  `force_password_change` flipped to `1` and a `PASSWORD_EXPIRED_FORCED_CHANGE`
  audit-log row was written with the exact day count, then completing the
  forced change and confirming the flag cleared and `password_changed_at`
  updated — before restoring the seeded Admin account to its original
  password hash/flag state (this account is the one the delivered
  `docs/seed.sql` documents as the login, so it had to end this phase
  exactly as it started, not mid-test).
- **Phase D workflow, end-to-end over real HTTP requests** (cookies +
  scraped CSRF tokens, two separate logged-in users to prove the
  reviewer/requester boundary is real, `php -S` front controller):
  generate a QT → assign a reviewer → log in as that reviewer → approve →
  confirm (via direct DB check and a rendered/visually-inspected PDF) the
  document flipped to `approved` and the **FINAL** watermark replaced
  "DRAFT — NOT FOR RELEASE" on a newly-rendered file, with the original
  draft PDF left untouched on disk. Compose an email → request send (Level
  1) → approve as Level 2 → run `dispatch_deferred_emails.php` → confirm it
  attempted the send and marked the row accordingly (failing gracefully
  with no SMTP configured, exactly as designed). Record a cross-verification
  on a document. Run the full amendment lifecycle **twice** — once changing
  only the advance %, once changing only the balance trigger option and
  days — through request → MD approval → generate the Amendment Agreement
  PDF (visually inspected, both pages) → upload a signed copy → activate,
  then generate a **fresh** Proforma Invoice after each and visually
  confirm the new terms actually render (this is what caught the two bugs
  above). Raise a dispute → attach a document → change its status. Run
  `check_alerts.php` against a deliberately-triggered near-expiry LUT date
  and a deliberately-overdue dispute, confirm real notification rows appear
  with the right recipients, then confirm re-running it immediately creates
  zero duplicates (the dedup fix above). Filtered the audit log viewer by
  entity type and action type and confirmed it surfaces the many
  `AuditLogRepository::log()` calls this same test run generated.
- **Phase B lifecycle** — create client → create order with two product
  lines → generate QT → confirm Buyer PO → generate PI (citing the QT
  ref) → record and clear the advance payment → generate OC (citing the PI
  ref, correct advance/balance figures) — was run twice: once by calling
  the services directly, and once end-to-end over real HTTP requests
  against `php -S` with cookies and CSRF tokens, exactly as a browser
  would.
- **Phase C lifecycle, run twice end-to-end over real HTTP requests**
  (cookies + scraped CSRF tokens, `php -S` front controller, no shortcuts)
  — once for an **FOB** order and once for a **CIF** order, so the
  incoterm-conditional logic (freight stage auto-skip, insurance wording,
  CIF-only fields) was exercised both ways, not just read in code:
  - FOB order: created → QT → Buyer PO → PI → advance cleared → OC →
    **buyer acknowledgement (Stage 4)** → Supplier PO created/signed
    (Stage 5) → **freight stage auto-skipped** (Stage 6, confirmed via a
    direct DB check of `order_stages.status='skipped'` and the recorded
    `skip_reason`) → packing/crates/shipping/BL instruction (Stage 7) →
    Commercial Invoice, balance recorded and cleared (Stage 8) → BL
    originals received/endorsed, order closed (Stage 9) — confirmed via a
    direct DB check of `orders.status='complete'` and `is_locked=1`.
  - CIF order: same path through Stage 8, this time with the freight stage
    actually gated (payment recorded and cleared, not skipped) and CIF's
    insurance/freight fields populated, verified through Commercial
    Invoice generation.
  - All 11 document types (QT, PI, OC, BUYERPO, SUPPO, FDN, PL, BLI, CI,
    COOPREP, plus BL originals as a manual milestone with no document of
    its own) were generated for real against the live database across the
    two runs.
- Every generated PDF was rendered to an image (`pdftoppm`) and visually
  inspected; every generated DOCX was confirmed as a real
  `Microsoft Word 2007+` file (`file` command) and opens correctly.
- The download endpoint was hit over HTTP and the returned bytes compared
  identical (`cmp`) to the file on disk.
- A full PHP lint pass (`php -l`) was run over every file in `app/` — not
  just the files touched in this phase — as the last step before packaging.

This process is what actually caught the bugs listed in each phase's own
section above — none of them were visible from reading the code; all were
found by generating the real document, running the real workflow, or
looking at the real database row.

Before packaging, the database was reset to a clean, seed-only state (one
seeded Admin user, zero test clients/orders/amendments/disputes/reviewers) —
the users and data created during this phase's own testing (a second test
login, a test client/order, two test amendments, a test dispute, etc.) were
not left in `docs/seed.sql` or shipped in any database dump.

## About the app/vendor/ folder in this delivery

Earlier deliveries of this project deliberately shipped without an
`app/vendor/` folder, because this development sandbox's network policy
blocks direct access to `packagist.org`/`getcomposer.org`, and the standard
practice is not to ship dependency trees built against a workaround. Each
of those earlier deliveries told you to run `composer install` yourself in
a real environment before the app would run.

This package is different, by request: it ships with `app/vendor/`
**already built and included**, specifically so a non-developer can deploy
without installing Composer or running any command-line step. It contains
real, complete copies (not symlinks) of DOMPDF 3.0.2, PHPWord 1.3.0, Twig
3.9.0, PHPMailer 6.9.1, and their own sub-dependencies (php-font-lib,
php-svg-lib, masterminds/html5, phpoffice/math, sabberworm/php-css-parser,
and the small Symfony polyfill/deprecation-contracts packages Twig needs).
It was built from each library's own source, then verified directly —
Dompdf actually rendering a PDF, PhpWord actually writing a .docx, Twig
actually rendering a template — not just that the classes exist. `app/composer.json`
itself is left in its clean, normal form (the same `require` block any
Composer install would use); nothing sandbox-specific was left in it.

You do **not** need Composer to deploy this package. You would only need
it later if you want to upgrade one of these four libraries to a newer
version, or add a new PHP dependency of your own — at that point, install
Composer normally and run `composer update` (or `composer require ...`) in
`app/`, from a machine with real internet access to Packagist. That is a
you're-changing-the-code situation, not a first-time-setup one.

## Final end-to-end verification pass (added 2026-09-21)

Closing item on the tracked task list, after the Sample Data Playground
extension, UI/UX pass, and document fidelity pass above. Two parts:

1. **Re-verified nothing regressed.** Restarted both the PHP and Node
   servers fresh, confirmed both live databases still carry their expected
   table count (65 on this stack; the Node stack's 66th table is
   `sessions`, its own MySQL-backed session store — not a schema drift),
   logged in as the seeded Admin on both stacks, and hit ten representative
   authenticated pages (dashboard, orders, clients, reports, audit log,
   settings, reference library, sample data, users, holiday calendar) on
   each — every one returned HTTP 200 with no PHP fatal/warning or Node
   error output in either server log. Confirmed both databases end this
   session with zero sample-data rows left loaded (clients, orders, and
   the supplier the CIF sample order needs) and the seeded Admin's real
   password hash restored to what it was before this session's live
   testing temporarily swapped it in for headless-browser login — both
   swaps were local-only DB writes for testing, always reverted after.
2. **Regenerated `NexaCrest_PHP_Deployment_Guide.docx`** (and the Node
   stack's equivalent) **from the current `README.md`**, which had grown
   substantially since these were first exported and was the actual
   up-to-date source of truth this whole time. Wrote a small one-off
   Markdown → OOXML converter using PHPWord (already a proven dependency
   in this exact codebase) rather than hand-editing a Word document —
   headings, numbered/bulleted lists (including nested and multi-line
   items), and fenced code blocks all map to real Word styles, not just
   monospaced-looking plain text. Caught and fixed a real bug in the
   converter itself before trusting its output: PHPWord defaults to
   `writeRaw()` (no XML escaping) for backward compatibility, so every
   literal `&` in the source Markdown (e.g. "Packing & BL Instruction")
   produced an invalid, uncorrupted-looking-until-you-try-to-open-it
   OOXML file — `Settings::setOutputEscapingEnabled(true)` fixes it.
   Verified the regenerated files open cleanly with `python-docx` (not
   just "the zip didn't error") and spot-checked heading styles, list
   formatting, and code-block line breaks in the actual document XML.

> **Go-live note (2 Nov 2026, D-241):** the migration files named in the follow-up sections below were folded into
> `docs/schema.sql` / `docs/seed.sql` before the first live installation and removed from `docs/migrations/`
> (git history keeps them). A new installation runs **no** migration — `schema.sql` then `seed.sql` is the whole
> database. Those names stay below only as history of what changed and why. From go-live on, see
> `docs/migrations/README.md` for how a change ships.

## Follow-up (added 2026-10-23): UI Templates/Kanban, Complaints split, two-phase closure, LC & travel-expense tracking

This delivery's own eight new tables are `ui_templates`, `order_lc_details`,
`order_draft_bl_reviews`, `order_inspection_fees`, `order_feedback`,
`order_travel_expenses`, `order_complaints`, and `order_complaint_replies`
— both stacks now stand at **106 tables** in 49 lettered schema sections
(A–BX); the table count quoted in the verification pass above is long
superseded by this and the delivery work between it and this one. All
items below are proven in both stacks, with matching PHPUnit/Jest coverage
(full suite: 1362/1362 PHP, 1407/1407 Node).

1. **UI Template Preference (Standard vs. Kanban).** A per-user choice
   (My Account → company default in Settings → `standard` fallback,
   resolved by one shared `UiTemplateService::resolve()` /
   `uiTemplateService.resolve()` function) that switches six staff-side
   queues — Orders, Disputes, Reorder Requests, Client Intake Review, PI
   Intake Review, and the new Complaints queue — between the existing
   list view and a real drag-free Kanban board (status columns seeded
   from each queue's actual status source, always rendering even when
   empty). The client portal deliberately does not get a Kanban option —
   see item 4.
2. **Letter of Credit / bank loan interest, and factory-visit travel
   expenses**, as two new categories on the existing Order Cost Entry
   screen (Order Financials, permission-gated same as the Profitability
   Sheet) — `bank_loan_interest` and `travel_expenses`/`inspection_fee`
   join the existing cost category list.
3. **CHA Draft BL Review loop** (Stage 7) — the draft Bill of Lading the
   CHA sends back for checking now goes through its own
   upload → Approve/Request-Revision round, repeating until a round is
   approved, before "Confirm BL Issued" is reached. Purely a staff/CHA
   back-and-forth with no gate of its own.
4. **Dispute vs. Complaint split.** The Dispute module (cancel-intent,
   admin-gated per client, unchanged response-due clock) now has a
   genuinely separate sibling: **Complaints**, for a process flaw with no
   cancel intent, always available on the client portal regardless of
   order status or the Dispute toggle, with its own
   `manage_complaints`/`respond_to_complaints` permissions, Open →
   Acknowledged → Resolved status, standalone staff nav page, and a real
   two-way conversation thread (staff replies email the client; client
   replies notify staff in-app) — not the Dispute thread's one-directional
   staff-only replies. The client portal's own "My Orders" dashboard also
   picked up a lighter grouped-by-status view (not a Kanban board) as part
   of this same pass, grouping by the label the client actually sees so
   `shipped_docs_sent` and `complete` share one "Complete" group.
5. **Two-phase order closure.** Stage 9's "Close Order" button no longer
   marks an order complete by itself — it sets `shipped_docs_sent` (the
   client portal already shows "Complete" from that moment) while the
   order stays open internally for a separate **Mark Fully Settled**
   action, which is the real final lock and is blocked while a government
   export-benefit claim on the order is still awaiting receipt.
6. **Order Feedback** — a 1-5 star rating plus optional comments on the
   client portal and that order's own staff-side page, upsertable (no
   limit on resubmitting), notifying every `manage_orders` holder in-app
   on submission.
7. **Pre-shipment inspection reminder** — a daily check for any active
   order whose estimated shipment date is within 7 days and still has an
   open Pre-Shipment Inspection compliance task; changing the estimated
   shipment date itself now also auto-posts into that order's Client
   Portal conversation.
8. **Full documentation audit** (this entry's own occasion): every SOP
   chapter affected by the above was corrected or extended, a new SOP
   chapter (`docs/SOP/19-complaints.md`) was added, a pre-existing,
   unrelated one-file drift in `ca-06-fy-lock.md` was fixed, and all three
   SOP mirrors (root, PHP, Node) were re-synced to byte-identical. Root
   `ARCHITECTURE.html` was updated (state machine, RBAC, Client Portal,
   Admin Tooling, and reference-table sections) for the same set of
   features.

## Follow-up (added 2026-10-24): order assignee visibility, order-page step renumbering, always-materialized Compliance Checklist & Export Benefit Scheme Eligibility, LC preset rename

Two new tables this delivery — `order_assignees` and
`order_export_benefit_schemes` — both stacks now stand at **108 tables**.
All items below are proven in both stacks, with matching PHPUnit/Jest
coverage (full suite: 1485/1485 PHP, 1533/1533 Node) and a full
Playwright e2e run green on both `BASE_URL`s (40/40 each).

1. **Order assignee visibility — replaces the single-owner model.** An
   order now has 0, 1, or more assignees (`order_assignees`), not one
   `orders.assigned_to` column (that column existed for under a day,
   superseded the same day it was added once the real org structure
   turned out to need concurrent assignees — e.g. an Export Executive on
   office work and a Logistics Executive on CHA/factory work, on the
   *same* order at the same time). The creator is auto-assigned at
   creation; only `assign_order_owner` holders (Admin/MD/ED) can add or
   remove an assignee afterward. `view_all_orders` holders (Accounts
   Executive, Viewer/Auditor, plus Admin/MD/ED's existing blanket grant)
   bypass assignee scoping entirely; everyone else only sees orders
   they're assigned to, in the list, the Kanban board, and direct access
   to the order's own page. Purely a visibility control — never consulted
   by the stage-gate/permission system that decides who may act.
2. **Order detail page: two real bugs fixed.** The "Document Review &
   Approval" card was hardcoded `open` regardless of state (now renders
   collapsed like every other reference card unless it's genuinely the
   current step); the "Letter of Credit & Bank Loan" reference card
   rendered on every order regardless of payment method (now correctly
   guarded on `payment_method = 'LC'`, the same check the real Step 5 LC
   fork already used).
3. **Order detail page: step-card renumbering, 1–19.** Steps 1/2/3 (QT
   prepare/send/accept) now gate independently instead of sharing one
   open/closed state. Two genuine numbering collisions are fixed — Freight
   Payment and Packing & BL Instruction both used to show "STEP 9"; two
   closure sections both showed "STEP 11" — by renumbering the sequence
   cleanly (Packing & BL → 10, Commercial Invoice & Balance → 11 with the
   old FOB-dependent dual lettering removed, Financials → 12,
   Documents & Settlement → 13, the six trailing steps → 14–19) and
   unifying every top-level step on the same pill-badge style. The
   Compliance Checklist moved from an unnumbered bottom-of-page card into
   Step 8 as "8b" (alongside "8a · Production & Estimated Shipment") —
   reusing Step 8's own number rather than inserting a new one and
   cascading every step after it.
4. **Compliance Checklist and Export Benefit Scheme Eligibility are now
   always-materialized** — a real row for every active compliance-task
   type / export-benefit scheme is written the moment the order is
   created (mirroring the `order_assignees` auto-insert pattern), never
   left as a missing row silently standing in for "not applicable." A new
   bulk **"Mark all remaining tasks Not Applicable"** action resolves
   every outstanding compliance task in one submit with one shared
   reason, for the repeat-order case. **Mark Fully Settled** (Stage 9's
   second closure phase) now blocks on three checks in order — an
   outstanding export-benefit claim (existing), an unresolved Compliance
   Checklist task, or a pending Export Benefit Scheme Eligibility
   decision (both new) — sharing the same reason-required override escape
   hatch as every other hard gate in this system.
5. **LC payment preset renamed** to "Irrevocable Letter of Credit at
   Sight" (display name only — `payment_method = 'LC'`, the actual
   conditional-rendering gate, is untouched) via a data-only migration;
   fresh-DB seed data updated to match.
6. **Full documentation audit** (this entry's own occasion): the order
   detail page's own step list, the Compliance Checklist chapter, and the
   Order Financials chapter were corrected across all three SOP mirrors;
   `ca-07-export-benefits.md` gained a new section distinguishing scheme
   eligibility (resolved early, blocks closure) from a recorded claim
   (filed later, once the order is complete and paid); root
   `ARCHITECTURE.html` was updated (schema section, state machine, and a
   new order-visibility callout) for this same set of features.

## Follow-up (added 2026-10-28): 25–28 Oct — order page flow, vendor bills, CAFIN, QA Round 5, PI/CI payment wording

Four new tables (`supplier_type_links`, `order_vendor_bills`,
`order_vendor_bill_payments`, `order_expenses`) — both stacks now stand at
**112 tables**, **60 permissions** and **466 routes** (157 GET, 309 POST),
route-for-route identical. Full suite on both stacks at the time of
writing: 1730 PHPUnit / 1795 Jest tests, and the Playwright e2e suite green
on both `BASE_URL`s (41 + 6 + 1 each).

**Database changes:** before go-live every migration was folded into `docs/schema.sql` / `docs/seed.sql`
(D-241). After go-live a change ships as a new file in the repository's `docs/migrations/` — see its
`README.md`.

What changed, by area (owner decisions; every item is in both stacks):

1. **Order page flow.** The nine stages are worked as about 19 steps with
   lettered sub-steps in five colour-coded sections (A–E). Every sub-step
   locks until the one before it is done; a stage gate passes only once the
   stage's own document is approved (email off) or sent (email on). New or
   reworked steps: 4b Buyer PO copy (or the buyer's upload through the
   Buyer Action Link), 7c Supplier Action Link, 7e / 10a supplier advance
   and balance, Step 12 in sub-steps with the money picture on top, 13g
   government benefits received or skipped, 13h Mark Fully Settled. Order
   files are stored one folder per order-page step (a reorganise tool moves
   existing files).
2. **Links that need no login.** Buyer Action Link (one step at a time),
   Payment Action Link (the client reports advance / Debit Note / balance
   payments), Supplier Action Link, Vendor Details Link (a supplier or
   logistics partner fills in its own bank details).
3. **Suppliers and logistics partners.** Suppliers directory with several
   types per supplier; logistics partners include freight forwarders;
   Step 9a picks the forwarder from the directory.
4. **Vendor bills and expenses.** Supplier bills (7e / 10a) and logistics
   partner bills (CHA, transport, forwarder; Advance or Final — a Final
   bill on CFR/CIF waits for Step 9d). Each bill is a **Proforma** (not a
   cost; may say what it asks for now) or a **Tax invoice** (the cost); a
   tax invoice can replace an earlier proforma from the same vendor; a bill
   takes several payments and overpayment is refused. GST is picked as a
   rate. Order expenses carry a receipt or a reason; actual travel replaces
   the provisional travel %. Step 12, the ledger and CAFIN count tax
   invoices plus payments on proformas not yet replaced.
5. **CAFIN.** A complete internal order snapshot (order, supplier cost with
   every bill and payment, every expense, benefits, profit, open items),
   generated from the CAFIN box at any stage and automatically at Mark
   Fully Settled. Seeing or downloading it needs the new **View & download
   CAFIN** permission; it never reaches the client.
6. **QA Round 5.** One order-access guard on every `/orders/{id}/*` route;
   payment-terms approval before the Quotation (permission **Approve
   payment terms**); one email per client; the CA role can open orders
   read-only; links shown only where the viewer can open them; no server
   error on bad numbers and no empty saves; matching page breaks between
   PDF and Word; CFR documents say the buyer insures; a 0% GST Supplier PO
   prints 0.00; versioned `app.js` / `app.css`; every route covered by a
   test.
7. **PI / CI payment wording** (each change shown to the owner as
   before/after previews and confirmed twice). The PI says what to pay now
   — an ADVANCE PAYABLE NOW box with the amount and due date, the balance
   not payable now, freight against the Debit Note and who bears bank
   charges — or, with no advance (Letter of Credit), NO ADVANCE PAYABLE —
   the full FOB value is payable by Letter of Credit. The CI ends its bank
   details with the same bank-charges sentence. Word copies use Calendar
   Days like the PDFs.

Docs updated in the same release: `docs/ARCHITECTURE.md`, the root
`ARCHITECTURE.html`, `docs/QA/TEST_PLAN.md` (§9), `e2e/README.md`, the SOP
(`docs/SOP`, copied into both stacks) and `docs/migrations/README.md`.

## Follow-up (added 2026-10-29): Zoho Books integration, shipping bill, LC payment received, 2-digit-year references

One new table (`order_zoho_invoices`) — both stacks now stand at
**113 tables**, **60 permissions** and **471 routes** (158 GET, 313 POST),
route-for-route identical. Full suite on both stacks at the time of
writing: 1,803 PHPUnit / 1,868 Jest tests (336 suites), and the 48-test
Playwright e2e suite green on both `BASE_URL`s.

**Database:** these changes are part of `docs/schema.sql` / `docs/seed.sql` — the first live install runs
no migration (D-241; the file names above are history). Before switching Zoho on, set the go-live
date in Settings › Company (Zoho Books).

What changed, by area (owner decisions; every item is in both stacks):

1. **Zoho safety switches.** `ZOHO_INTEGRATION_ENABLED`, `ZOHO_ENVIRONMENT`
   and `ZOHO_EINVOICE_ENABLED` in `app/.env` (see "First-time setup",
   step 3) are read only by `ZohoGuard` — no screen can change them — and
   every Zoho request goes through it. A missing or unknown value counts
   as off / test / off. In `test`, only customers named `TEST-…` may be
   sent. GST e-invoice, e-way bill and GST filing requests are blocked
   unless the integration is on, the environment is `production` and
   `ZOHO_EINVOICE_ENABLED=true`. Zoho's Indian data centre (`zoho.in`) is
   the default. Zoho Mail is not used to send (`ZohoMailService::IN_USE`
   is `false`); all mail goes through SMTP.
2. **Zoho Books connection.** The Books credentials (self-client ID,
   secret and refresh token, organisation, deposit account) and the
   "Zoho Books enabled" switch are company settings (Settings › Company,
   Zoho Books), with a "Test Books connection" button
   (`POST /settings/test-zoho-books`). Books syncs only when both the
   `.env` switch and this setting are on.
3. **What goes to Books** (`CaSyncService::runFullSync()`, from Sync Now on
   `/ca/zoho-sync` or the hourly `cron/zoho_sync.php`; requests built by
   `ZohoBooksService`, invoice content by `ZohoInvoiceData`):
   - buyers as "overseas" customers in the order's currency; payments in
     the foreign amount at the actual rate;
   - the Commercial Invoice and the freight Debit Note as export invoices
     numbered with our own references, at the shipping bill's customs
     exchange rate; payments applied to them (the advance held until the
     CI is in Books);
   - suppliers and logistics partners as vendors, their tax invoices as
     bills (a proforma is not sent), payments to them as vendor payments,
     order expenses as expenses (a transport bill marked reverse charge
     goes with 5% GST as reverse charge);
   - paid order cost entries as expenses, the LC payment as a customer
     payment applied to the CI, received export benefits as journals.
   Expenses the CA enters in Books are imported (Books owns those: their
   changes and deletions follow; a Reference # naming an order links the
   expense to it).
4. **The app is the source of truth for what it sends**
   (`ZohoRestoreService`). A record deleted in Books is sent again; one
   changed there is put back. A record in a financial year the CA has
   locked is never changed automatically — it is logged for the CA. A
   scheduled run checks at most once every 23 hours (Zoho limits API calls
   per day); Sync Now always checks.
5. **Go-live date** (`zoho_books_sync_start_date`): only records dated on
   or after it go to Books; anything earlier is booked by the CA. With
   `ZOHO_ENVIRONMENT=production`, nothing is sent until it is set.
6. **App vs Zoho check** — `/orders/{id}/zoho-check` (`ca_module_manage`,
   `OrderZohoMatchService`): for each of the order's records, whether
   Books matches, differs, is missing it, or holds one the app does not.
   Read only.
7. **Test clean-up** — `cron/zoho_cleanup_test_records.php` (see "Bluehost
   cron setup"). Every change made in Zoho, by anyone, is logged in the
   repository's `docs/zoho/zoho-change-log.csv`.
8. **Shipping bill** — its number, date and customs exchange rate on the
   order page (`POST /orders/{id}/shipping-bill`, `manage_shipping`); the
   CI and Debit Note wait for it before going to Books.
9. **LC payment received** — what the bank paid under the LC (amount,
   rupees credited, bank charges; `POST /orders/{id}/lc-received`) and its
   FIRC / eBRC (`POST /orders/{id}/payment/lc/firc`). It is its own leg in
   the INR Settlement Register, and CAFIN counts it as money received.
   CAFIN also counts expenses booked in Books and linked to the order,
   except a possible duplicate of a cost entered in the app.
10. **2-digit year in reference numbers** — `SC/CI/26/2910001` instead of
    `SC/CI/2026/2910001` (a GST invoice number may have at most 16
    characters). Numbers already issued keep `2026`; `{YYYY}` still works
    in a format set by hand.

Tests added (both stacks): `ZohoServerGuardTest`, `ZohoBooksStage1Test` …
`ZohoBooksStage5Test`, `CafinLcAndZohoExpensesTest`, `ShippingBillTest`
(Node: the same names in camelCase, `.test.js`). Every Zoho request in
them goes to a fake Zoho; they never reach the real one.

Docs updated in the same release: `docs/ARCHITECTURE.md`, the root
`ARCHITECTURE.html`, `docs/QA/TEST_PLAN.md` (§9),
`docs/migrations/README.md` and the SOP chapter ca-03 (Zoho Books Sync).

## Follow-up (added 2026-10-30): team incentives and associates

Owner decision 2026-10-30 (mockups approved). `IncentiveService` /
`IncentiveRepository` / `AssociateRepository` / `IncentiveController`, the
views in `views/incentives/` and Step 12d (`views/orders/_incentive.php`),
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
**119 tables**, **231 foreign keys**, **63 permissions** and **487 routes**
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
- **Route coverage (Batch 5, R5-14).** `RouteBehaviourTest` drives each of
  the 124 routes QA listed as untested (plus 5 found since) through the real
  Router as a permitted user and checks what it does — the record changed,
  the audit row, the page — and that a missing id is "not found" with
  nothing written. It also covers the 21 routes only Jest tested (uploads by
  their refusal: PHP cannot complete an upload inside PHPUnit). Found and
  fixed while writing it: unmatching a bank line, setting TDS on an expense
  and saving an associate reported success for a record that does not exist.
  Node: `routeBehaviour.test.js` (its `/sample-data` checks live in
  `sampleDataLoadClear.test.js`, since Jest runs files side by side).

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
- **Tests:** `tests/Integration/DepartmentsTest.php`; e2e `e2e/tests/10-departments.spec.js`.

## Follow-up (added 2026-11-01): Money separation (owner decision D-214, phase 2)

- **Migration:** `docs/migrations/2026-11-01b-money-separation.sql` —
  `view_selling_prices`, `view_buyer_payments`, `view_purchase_prices`,
  `view_logistics_costs`, `manage_supplier_po`, `settle_orders`, granted by
  department.
- **One rule:** `App\Services\MoneyVisibility` — document type → money class (QT, BUYERPO, PI, OC,
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
- **Tests:** `tests/Integration/MoneySeparationTest.php` (role matrix, document and file downloads, order page).

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
  of the designation the person is working as (`App\Services\PurchasingService`); above it, an
  approver with "Approve purchases" whose own limit covers it approves it
  (never the awarder, unless the maker-checker rule allows).
- **Tests:** `tests/Integration/PurchasingTest.php`.

## Follow-up (added 2026-11-01): My tasks, owners and cockpit (owner decision D-214, phase 4)

- **Migration:** `docs/migrations/2026-11-01d-coordination.sql` — tables
  `order_department_owners`, `department_task_types`; permissions
  `assign_department_owner`, `view_cockpit`, `manage_task_types`; aligns the
  1 Nov tables to the database collation.
- **Tasks:** `App\Repositories\DepartmentTaskRepository` derives each department's open work from order state
  in one query (advance cleared without a requirement, requirements to quote
  / approve / chase, received goods without freight, remittances to clear,
  unpaid bills, documents to send), with due and escalation dates from
  `department_task_types`. A department with task kinds of its own sees its
  own; management, the Order Desk and Audit see all.
- **Screens:** `/my-tasks`, `/cockpit`, `/admin/task-types`, and the Owners
  panel on the order page (`POST /orders/{id}/department-owners`).
- **Tests:** `tests/Integration/CoordinationTest.php`.

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
- **Tests:** `tests/Integration/LineTypesTest.php`,
  `ExportServiceInvoiceTest.php`,
  `PaymentMilestoneTest.php`,
  `ProductCategoryTest.php`.

## Follow-up (added 2026-11-01): Standard and Kanban templates kept parallel (owner hard rule)

- Every list page (Orders, Disputes, Complaints, Reorder Requests, Quotation Intake,
  PI Intake, Procurement, My tasks, Associates) offers on its Kanban board every
  record link, action, filter and count of its Standard list, and the other way round;
  each switches to the other ("Switch to list view" / "Switch to board view").
- The Orders board shows 25 cards per column, then "Show more (N more)" (A-5).
- **Tests:** `tests/Integration/KanbanParityTest.php` (enforces the rule),
  `KanbanCoverageTest.php`.

## Follow-up (added 2026-11-01): QA Round 7 and the owner decisions of 1 Nov (A, B, C)

- **QA Round 7:** fixes R7-N01 … N16 (milestone bound, client name on lists,
  file links, Node resilience to a lost database connection, amendment date check,
  procurement saved atomically …) with tests first.
- **Owner decisions (D-216):** nobody approves their own payment terms (MD/ED
  excepted, audit-logged), reviews their own document or approves their own e-mail
  send; nobody grants a permission they do not hold or changes their own rights
  (`GrantGuard`); System Administrator has no data export; LC-only zero advance;
  GST refund periods within a financial year; Zoho secrets masked; every order keeps
  an assignee; the daily digest (`cron/daily_digest.php`, 08:15 IST).
- **Migration:** `2026-11-01i-owner-decisions.sql`.
- **CI:** `.github/workflows/tests.yml` runs PHPUnit and Jest on every push against
  MySQL 8.
- **Tests:** `tests/Integration/OwnerDecisionsNov1Test.php`.

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
  middleware `AssociateAuth`: home, my clients (one-off links, personal code,
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
- **Tests:** `tests/Integration/AssociateSystemTest.php`, the route, ungated-route and
  Kanban parity tests, `e2e/tests/11-associates.spec.js`. SOP chapter 24.

> **Replaced by D-245 (4 Nov 2026):** the reward table, minimum profit, new-client bonus, cut-off day and automatic lock are gone — the team incentive is a % of profit after the associate's commission or a fixed amount (global, product or per order), shared over editable roles and locked by the MD (P4). Associate commission is on the invoiced FOB of the goods, full payment only (no pro rata), at the level fixed at the PI, due N days after full payment and paid in the payout run (P3). See SOP 20 and 24.

## Follow-up (added 2026-11-01): gap list G-1 … G-6 (owner decision D-218)

- **Migrations:** `2026-11-02b-gap-shipment-gates.sql`, `2026-11-02c-gap-due-dates-lc.sql`,
  `2026-11-02d-gap-part-payments.sql`.
- **G-1:** a "before shipment" balance is recorded and cleared (Steps 11c/11d) before
  the Bill of Lading; Step 10f waits for it.
- **G-2:** a post-BL or LC balance is due from the day the scanned BL is sent (Step
  11b) plus the balance days; `check_alerts.php` sends a daily overdue reminder.
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
- **Tests:** `tests/Integration/GapShipmentGatesTest.php`, `GapDueDatesLcTest.php`,
  `GapPartPaymentsTest.php`.

## Follow-up (added 2026-11-01): CFR wording (C-16, confirmed twice) and Q-1 … Q-5 (D-219)

- The "Shipment Booking (CFR/CIF)" clause asks for freight only on CFR orders, freight
  and the insurance premium on CIF (`2026-11-02e-cfr-wording.sql`, changes only an
  unedited clause). The Buyer PO's freight sentence follows the incoterm (CIF
  freight & insurance, CFR freight with "insurance arranged by the buyer", FOB none),
  PDF and Word.
- **Tests:** `tests/Integration/CfrWordingTest.php`.

## Follow-up (added 2026-11-01): placeholders, self-approval rules, rate carry-forward (D-220, D-221) and QA Round 8 (D-222)

- **Migrations (run in this order):** `2026-11-02f-self-approval-rules.sql`,
  `2026-11-02g-qa-r8-associates.sql`, `2026-11-02h-qa-r8-approvals.sql`. All are safe to
  run more than once. (History — now part of schema.sql / seed.sql, D-241.)
- **Placeholders and labels (D-220, R8-N08):** every typed input and text area shows a
  placeholder with an example or the expected format; every visible field has a label.
  The sitewide ``public_html/assets/js/app.js`` (`markRequiredLabels()`) puts " *" on the label of every required
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
  exemption. ``MakerCheckerGuard`` → ``SelfApprovalRules`` is the one check for every self-approval. Each
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
  through `Helpers/Csv` (`stream()`, and `send()` where the caller continues). A cell starting =, +, - or @ is written as text.
- **Invitation links (R8-N04):** a one-off invitation link is used up by the first
  registration sent through it.
- **Suspended associates (Q-R8-2):** a suspended associate gets a read-only portal.
- **Bank e-mails (R8-N01):** bank e-mails say "No payment can be made until new bank
  details are verified." when no verified account is in use.
- **Bill of Lading (R8-N13):** the number of original BL copies is required, 1 to 10.
- **Daily digest (R8-N02):** the digest counts only e-mails that actually left. With
  e-mail sending off it reports "Daily digest: e-mail sending is off — nothing sent." and
  the day is not used up.
- **Tests:** `tests/Unit/FormPlaceholderTest.php`, `tests/Unit/FormLabelConventionsTest.php`, `tests/Integration/SelfApprovalRulesTest.php`, `SelfApprovalActionsTest.php`, `QaRound8Part1Test.php`, `QaRound8Part2Test.php`, `AssociatePortalRoutesTest.php`, plus updates to `MakerCheckerGuardTest.php`, `DepartmentsTest.php` and `AssociateSystemTest.php`. The 15 routes that had no behaviour test (D-211) now have one.

## Follow-up (added 2026-11-02): owner's 2 Nov list — documents, money safety, compliance, agreements, remittances

Migrations, in this order after `2026-11-02h`: `2026-11-02m-generated-file-links.sql`, `2026-11-02n-document-copy-cleanup.sql`, `2026-11-02r-export-compliance.sql`, `2026-11-02q-remittance-difference.sql`, `2026-11-02s-agreements-register.sql`, `2026-11-02t-remittance-difference-reasons.sql`, `2026-11-02u-reference-library-payment-guide.sql` (history — now part of schema.sql / seed.sql, D-241). Schema: **148 tables** at that point (**153** at go-live, after D-238 – D-240).

- **Documents (D-223, D-224 — owner confirmed twice):** Quotation and Proforma Invoice have no untitled CoO / Port of Discharge table; the PI prints Buyer's PO / Ref No. in Buyer Details only when entered; the Order Confirmation shows the Buyer's PO; the Debit Note's "To (Buyer)" is the full buyer block; BL Instruction section 8 no longer gives payment terms ("Original BL release: CHA to hand all 3 originals to NexaCrest only"); the Commercial Invoice says "the value declared on the Indian Shipping Bill" (no "IGST refund") and prints the total in words (`Helpers/AmountInWords`). PDF and Word alike; the reference Word files (`reference_templates/`) match. Tests: `QuotationPiLayoutTest`, `DocumentWordingD224Test`.
- **Freight in rupees (D-225):** Step 9a freight and insurance are order currency; `OrderProfitabilityService` converts them (freight receipt rate → freight leg → advance/balance rate), flags a missing rate instead of adding foreign units as rupees, and the working line ("EUR 950.00 × 90.50 = ₹85,975.00") shows in Step 12c and CAFIN C1. Fixed incentives are not recalculated. Test: `FreightCostInInrTest`.
- **Money never leaks (owner points 6/15):** every generated file carries `file_store.linked_document_id`, so drafts, superseded copies and older CAFIN copies follow the same money rule as the current copy (download, dossier ZIP, All Documents); `Services/AuditMoneyMask` hides amounts in the Audit Log, an order's audit log, the order report's Full Audit Trail (and their CSVs) and the dashboard's Recent Activity; reports with selling prices or buyer payments refuse people who may not see that money; product list prices need selling rights; document counts count only what the person may see. Proof: `e2e/tests/12-money-matrix.spec.js` (every role × ~75 screens, files, ZIP; 0 violations). Test: `MoneyLeakClosuresTest`.
- **All Documents panel:** generated documents and every uploaded file of the order, only those the person may open (Sales sees no supplier or logistics papers). Tests: `OrderAllDocumentsPanelTest`.
- **Bill forms and guide:** Steps 7e/10a start from the Supplier PO (taxable value, GST rate, amount asked now); Step 9a pre-selects the forwarder already on the order; `/help/supplier-logistics-payments` explains supplier and logistics payments with screenshots; the 21-page *Supplier & Logistics Payments Guide* (PDF + Word) is in `docs/guides/`, `docs/SOP/guides/` and the Reference Library (`storage/assets/reference_library/supplier_and_logistics_payments_guide.*`). Tests: `BillPrefillAndForwarderTest`, `HelpGuideTest`.
- **Order page (points 10/13/14/16):** no associate controls when the client has no associate; Step 7c starts with the Supplier Link; the COO Prep Sheet can be generated once Stage 8 is open (before the balance clears); "Zoho Sync" in the menu and "Sync this order to Zoho now" (`POST /orders/{id}/zoho-sync`, `ca_module_manage`, also on closed orders) next to the Zoho check. Test: `OrderPageOwnerPointsNov2Test`.
- **Document copies clean-up (points 5/8/9):** Settings › Document clean-up (`/settings/document-cleanup`), off by default: drafts of approved documents, never-sent superseded revisions, older CAFIN copies, after a chosen number of days, with preview and audit; never anything sent to the client, current copies, uploads, open disputes or CA-locked years. The exact PDF the buyer received is now marked `sent_to_client`. Cron `document_copy_cleanup.php` (daily). Test: `DocumentCopyCleanupTest`.
- **Export Compliance department (D-226):** Executive / Manager / Director roles for ECGC, COO, phytosanitary/fumigation/inspection, benefit claims and eBRC follow-up; permissions `manage_compliance_checklist`, `manage_export_benefit_claims`, `generate_compliance_documents`, `upload_order_documents`; its own My tasks queue; no purchase, logistics, buyer-payment, profit, incentive or CAFIN figures. SOP 25. Test: `ExportComplianceTest`.
- **Remittance differences (D-227):** the advance, freight, balance and milestone remittance forms ask "Why is it different?" from Admin-managed reasons (Settings › Lists › Remittance difference reasons) or "Other — type the reason"; bank charges become an order cost "Bank Charges" (D-212 description, Zoho `bank_charges`) and the leg counts as fully received (cap `max_bank_charges_pct`, 3%); short and excess are recorded and explained. Tests: `RemittanceDifferenceTest`, `RemittanceDifferenceReasonsTest`.
- **Agreements register (D-228):** `/agreements` (Standard + Kanban) for client, supplier, logistics partner, associate and staff agreements — signed copy, dates, renew, end early; reminders 30 / 7 / 0 days before expiry from `check_alerts.php`; a card on each party's page; client and associate agreements listed automatically. Permissions `manage_agreements`, `view_agreements`. SOP 26. Test: `AgreementsRegisterTest`.

### TDS on associate commission (D-235, added 2026-11-02)

- Migration `docs/migrations/2026-11-02x-associate-tds.sql` (after 02v).
- The app works out the TDS on each associate payout: section 194H at 2 % once the associate's commission in the financial year passes ₹20,000 (catch-up on the crossing payment), 20 % (206AA) without an accepted PAN, none for associates abroad once TRC / Form 10F / no-PE are accepted (each payment abroad needs Form 15CA, and 15CB when required). Values: Settings › Lists › Associate TDS rules (`/settings/associate-tds`). Register by quarter with challans: `/associates/tds`.
- Reminders to *Pay incentives* holders come from the existing daily alert job (`check_alerts`): deposit by the 7th, Form 26Q after each quarter.
- **Deployment note for the CA:** to have Zoho Books book the TDS on the associate's bill, the CA turns on TDS in Zoho Books (Settings → Taxes → TDS in Zoho, a manual Zoho change — log it in `docs/zoho/zoho-change-log.csv` when done) and puts the 194H tax's id into Settings › Lists › Associate TDS rules → Zoho Books TDS tax id. Until then the bill is the gross, the payment the net, and the Sync Log says the TDS is left on the bill for the CA.

### Follow-up (added 2026-11-02): clients and products in Zoho Books (D-238)

Migration `docs/migrations/2026-11-02za-zoho-clients-products.sql` (history — now part of schema.sql / seed.sql, D-241). It adds two switches under **Settings › Zoho**, both **off**: `zoho_sync_clients` (clients as Zoho Books customers) and `zoho_sync_products` (products of the Products menu as Zoho Books items, rate 0 — no cost is ever sent). One way, app → Zoho, done by `app/src/Services/ZohoMasterDataSync.php` inside the existing hourly Zoho sync (`app/cron/zoho_sync.php`) and Sync now — **no new scheduled job**. "Re-push all clients and products" on the Zoho Books Sync page (`POST /ca/zoho-sync/repush-master-data`, `ca_module_manage`) sends everything again. Nothing is deleted in Zoho: inactive or deleted records are marked inactive there. A client goes once its first order has set its currency. See SOP `ca-03-zoho-sync.md` → "Clients and products in Zoho Books". Tests: `ZohoClientsProductsSyncTest` / `zohoClientsProductsSync.test.js`.

### Follow-up (added 2026-11-02): Zoho API call limits for this app (D-239)

Migration `docs/migrations/2026-11-02zb-zoho-api-limits.sql` (history — now part of schema.sql / seed.sql, D-241). Zoho limits API calls **per organisation** (about 100 a minute and a daily number by plan) and every Zoho app of the company shares that limit, so this app keeps to its own share: **Settings › Zoho** → `zoho_api_daily_limit` (**800**) and `zoho_api_minute_limit` (**30**). Every Zoho Books call — reads, the OAuth token and sends — is counted in `zoho_api_usage` by `app/src/Services/ZohoApiBudget.php` (meter) and `ZohoBooksService::request()` / `getAccessToken()`. When the day's allowance is used, syncing pauses until midnight (India time); the scheduled sync (`app/cron/zoho_sync.php`) waits out the per-minute limit, while Sync now / Sync this order / the Zoho check stop and ask to try again in a minute. Zoho's own "too many requests" (HTTP 429, codes 44/45, the OAuth "too many requests continuously") **stops the run at once** with one Sync Log line and one failure e-mail (D-233); nothing is lost. The access token is shared (encrypted, `zoho_token_cache`) by every process for 50 minutes and refreshed under a database lock. Usage today and the last 7 days are on the Zoho Books Sync page. See SOP `ca-03-zoho-sync.md` → "Zoho's call limits". Tests: `ZohoApiLimitsTest`.

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
  office computer" and the default is 30 days — see the 11 Nov section (7 days since D-369, 13 Nov 2026). Associates keep their own code.
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
mysql -u <user> -p <database> < docs/migrations/2026-11-13f-remember-office-computer-7-days.sql
mysql -u <user> -p <database> < docs/migrations/2026-11-13g-freeze-prices-at-pi.sql
```

Before `2026-11-13a`, run the two read-only checks in `docs/migrations/README.md` ("Before
`2026-11-13a`": products priced from an inactive supplier, categories without their own weight or
50 mm+ factor) and keep the output. Before `2026-11-13g`, run its read-only list ("Before `2026-11-13g`":
orders with an approved Proforma Invoice whose prices are not fixed yet) and show it to the owner.

Each file is safe to run twice and MySQL-compatible. They keep the company's real identity, bank,
GSTIN, LUT and RCMC values (only a fresh install starts with placeholders). Then add the `fetch_fx_rate.php` cron line (07:05 IST, see "Bluehost cron setup"), make sure `storage/sessions/` is writable, check outbound HTTPS to `api.frankfurter.app` and `open.er-api.com` (Products › Global pricing settings › Fetch today's rate now), and
once, as the MD / a product editor: **Products › HS codes › Fix HS codes on products** and
**Products › Link these prices** (D-361: shown only while supplier prices typed before the Suppliers directory still need a supplier record; migration 13b links exact names itself).

**Menus (D-320–D-322, SOP 00).** The left menu has 13 entries — Dashboard, Approvals (n), My tasks
(n) · Orders, Clients, Associates, Products, Procurement & suppliers, Finance & accounts · Reports ·
Help & SOP · Users & roles, Settings — and every section has a menu bar at the top of its pages
(`app/src/Config/navigation.json`, the same file on both stacks). The menu paths in the older sections of this README were brought up to date on 11 Nov 2026: what was under Admin is now under *Users & roles* or *Settings* (HS codes and Pricing under Products, Suppliers and Logistics partners under Procurement & suppliers, Team incentives under Finance & accounts); the old Operations pages are under Orders, Clients or Procurement & suppliers, and the old Insights pages under Reports. The order page has a sticky action bar: Edit order ·
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
(`login_remember_browser_days`, default **7** since D-369, 0–90); "Forget all remembered computers" on My
Account, and for Admin on Users › Edit. PHP now keeps its session files in `storage/sessions/` and sets its own garbage-collection lifetime and cookie to the longest idle timeout, so the host default (about 24 minutes) no longer signs people out early — the folder must be writable (Setup checklist item).

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
with the files of the previous section, `2026-11-11a` … `2026-11-13g`, in that order — before
`2026-11-13a` run the two read-only checks in `docs/migrations/README.md` ("Before 2026-11-13a") and
keep their output. Then the `fetch_fx_rate.php` cron line at 07:05 IST (see "Bluehost cron setup") must be in place, and `docs/guides/GO_LIVE_CHECKLIST.md`
section H lists what to check afterwards (Link these prices, the Products status filters, Testing mode
after `2026-11-12i`, Settings › Lists › surface_finish). The test suites were not run for D-348 … D-365
on the owner's instruction (syntax checks and browser checks on both stacks).

**Documents** (every change confirmed twice by the owner; documents already issued are never touched):

- **Annexure B and "As per annexure" (D-336, D-341; `2026-11-12c`).** An order line can say "As per
  annexure" (no size, quantity still required); next to Annexure A (as quoted) there is **Annexure B (as
  shipped)**, made from the Commercial Invoice step; Step 11a chooses the annexure printed with the CI and
  Packing List (`orders.ci_annexure`, `AnnexureChoice`). The annexure page has one **Save all
  changes** and the buttons Edit / Regenerate Annexure A.
- **Detailed product table (D-339; `2026-11-12g`).** QT, PI, OC, CI, Buyer PO and Packing List print
  # · Material / Colour · Surface / Finish · L W H · measure per piece · No. of pcs · measure of all pieces
  · price per unit · price per piece · total (`templates/_line_table.html.twig`, `LineTable`, `UnitPrices`). Orders that
  existed before `12g` keep the classic table (`orders.line_table_layout`).
- **Money and numbers (D-343, D-345).** Indian digit grouping (1,75,000.00) for every amount of an INR
  order and for every Supplier PO (`MoneyFormat::amount()`); Total CBM worked out from the lines; the Word copy has
  the same section numbers as the PDF.
- **Wording (D-344, D-349, D-352, D-353).** "Commercial Invoice" and "Packing List" without "(digitally
  authorised)"; the Certificate of Origin line reads "3. Certificate of Origin Issued by CAPEXIL" while the
  COO type is empty or "To Be Confirmed" (`DocumentDataAssembler::cooLine()`); no "Fields marked * are mandatory …" note and no
  "*" after labels on any generated document (the app's input forms keep their "*").
- **Layout (D-354, D-355, D-356).** Headings and column headers stay with their first rows (PDF and Word);
  the Payment Terms on the QT and PI sit in a light grey-blue box with the amounts in bold; a clean
  spacer under Word headings (PHP).

**Orders and clients:**

- **Buyer details follow the client (D-337; `2026-11-12d`, `2026-11-12f`).** While nothing of the order
  is issued, a client edit updates the order at once; after that, by a request approved by a holder of
  **Approve buyer details change** (`approve_buyer_details_change`: MD, ED) — `BuyerDetailsRefreshService`,
  `BuyerDetailsController`, table `order_buyer_detail_requests`.
- **Reorders (D-338; `2026-11-12e`).** Reorder lines can say "As per annexure"; duplicating an order copies
  Annexure A; a worked-out per-CBM price goes to the MD like a price below the floor.
- **Price lock and the "Products & prices" amendment (D-342; `2026-11-12h`).** From the first approved
  Quotation the order keeps its exchange rate and every line price (`PriceLock`); lines change only
  through **Amendments › Products & prices** (prices only go down, MD approval, AMD document
  `templates/AMD/products_amendment.html.twig`; `ProductsAmendmentService`, table `amendment_product_lines`). `12h` fixes existing orders that
  already have an approved Quotation.
- **Exchange rate shown (D-340).** Global pricing settings has the box "Rate used for prices right now";
  the order page shows MD and ED the rate the prices were worked out at.
- **Client billing address in parts (D-346).** Line 1, City / Town and Country required; composed by
  `ClientAddress::compose()`.
- **Order lines (D-348).** Thickness and Size / format start on "Choose…" — the price fills only once both
  are chosen; typing Thickness (cm) sets the Thickness option.
- **MD name (D-349, D-350).** Changing the MD's name or title on Settings › Company offers to apply it to
  the open orders and Draft / In-review documents (`MdNameSyncService`); approved and sent documents
  never change; the founder logins still change only by the founder SQL block (First-time setup).
- **Buttons (D-350, D-351).** On a form with two choice buttons the pressed button's choice always reaches
  the server, and a question written on a button is asked (`public_html/assets/js/app.js`: `keepSubmitterChoice()`,
  `initConfirmDialogs()`).

**Products, suppliers and pricing:**

- **Defaults and options (D-347; `2026-11-12i`).** Size / format "Monument (cut to size)" at 0 %; a fresh
  install starts with e-mail **Testing mode ON** (switch it off on go-live day), team incentive on, other
  costs 5.5 %, margin 15 %. Part 2 of `12i` sets the same four values on a live database.
- **One list of surface finishes (D-357; `2026-11-12j`).** Settings › Lists › surface_finish names every
  finish; Products › Pricing › Specifications › Surface finish keeps only its price
  (`SurfaceFinishList`). The base finish is "Natural Polish" (was "Polished" / "Mirror Finish").
- **Duplicate a product (D-358).** **Duplicate** on the Products list and the product page
  (`/products/{id}/duplicate`, Manage product catalogue, `ProductDuplicateService`); the copy starts
  Inactive.
- **Supplier offers and the configured base price (D-359; `2026-11-13a`).** Each supplier price of a
  product is an *offer* with an **Offer active** switch (`catalog_product_suppliers.offer_active`); an offer
  is used only when it is on and its supplier is active (`SupplierOffers`). Each product has a configured
  base price; margin, other costs, buffer and the physical values come from the product, else its
  category, else Global pricing settings (`PricingLevels`). New **Edit price** form
  (`/products/suppliers/{id}/edit-price`, Record market prices, `EditPriceController`).
- **Suppliers pages (D-360).** **Procurement & suppliers › Suppliers** lists under each supplier the
  products it offers (base price, quoted on / valid until, Offer active, Edit price) and a filter "No
  product prices yet"; **+ Add product** adds an existing product or creates a new one (Inactive) with its
  price (`/suppliers/{id}/add-product`; switch `/suppliers/offers/{id}/active`; Manage suppliers + Record
  market prices).
- **Products and Configure (D-361; `2026-11-13b`).** The configured base price is **fixed**: it never
  changes by itself — when supplier prices change the product shows "Supplier prices changed: highest is
  now ₹X" with **Use ₹X**. **Configure / Edit** (`/products/{id}/configure`, Manage product catalogue,
  `ProductConfigureController`): base price (default the highest active supplier price), the table Global
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
  saved as the SQM price (× 10.7639, `LineUnits::SQFT_PER_M2`) and the note keeps what was typed. "Supplier
  performance" on Procurement is a normal small button.
