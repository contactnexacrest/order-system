# NexaCrest Export Operations Webapp — Node/MySQL port (Phases A–E)

This is a full ground-up rebuild of the NexaCrest export-operations webapp
on Node.js/Express/MySQL, replacing the earlier PHP/MySQL delivery. It was
built by re-reading the same three source documents (the spec, the schema,
the architecture doc) and re-running the same phase-by-phase methodology
(A → E) used for the PHP build — not a mechanical line-by-line transpile.
The **database schema and seed data are unchanged** (`docs/schema.sql` /
`docs/seed.sql` are byte-identical to the PHP delivery's), so the two
builds can even share one live database if you ever wanted to run both
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
  templates/              the 11 business-document Nunjucks templates
                          (QT, PI, OC, BUYERPO, SUPPO, FDN, PL, BLI, CI,
                          COOPREP, AMD) rendered to PDF/DOCX
  public/                 static CSS/JS/images served directly by Express
  node_modules/           every npm dependency, already installed — see
                          "About the node_modules folder" below
  package.json / package-lock.json
  .env.example            template — copy to .env and fill in real values
docs/
  schema.sql              the MySQL schema — 52 tables, 76 foreign keys
  seed.sql                seed data: roles/permissions, company settings,
                          lookup tables, one Admin user
  ARCHITECTURE.md         the original architecture document this build
                          (and the PHP build before it) was implemented from
scripts/
  backup_db.sh            daily DB backup template — see "Database backups"
  backup_storage.sh       daily storage/ backup template — see "Backing up storage/"
storage/
  assets/                 5 placeholder brand images (logo, signature,
                          seal, watermark, email header) — see step 3 of
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

- **Node.js 20 LTS** (or 18 LTS) — check with `node --version`. Anything
  older than 18 is not tested against.
- **MySQL 8.0+ or MariaDB 10.6+** — same requirement as the PHP build.
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

1. **Install Node.js 20 LTS.** On Ubuntu/Debian:
   ```bash
   curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
   sudo apt-get install -y nodejs
   ```
   On AlmaLinux/RHEL: `sudo dnf module install nodejs:20`.

2. **Install MySQL/MariaDB** if not already present (`sudo apt install
   mariadb-server` / `sudo dnf install mariadb-server`), then create the
   database and app user:
   ```sql
   CREATE DATABASE nexacrest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'nexacrest_app'@'localhost' IDENTIFIED BY 'a-real-random-password';
   GRANT ALL PRIVILEGES ON nexacrest.* TO 'nexacrest_app'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Apply the schema and seed data:**
   ```bash
   mysql -u nexacrest_app -p nexacrest < docs/schema.sql
   mysql -u nexacrest_app -p nexacrest < docs/seed.sql
   ```
   This creates all 52 tables and seeds: the roles/permissions matrix, one
   Admin login, company settings (with `PLACEHOLDER` values you must
   replace — see below), lookup tables (incoterms, currencies, ports, T&C
   clauses, payment presets), and 5 placeholder brand assets (logo,
   signature, seal, watermark, email header).

   **Fix the storage path in the seeded asset rows.** `seed.sql` inserts
   asset rows with a literal placeholder path token — in BOTH the `assets`
   table (company logo/seal/watermark) and the `user_signature_assets`
   table (per-user signatures and designation seals). Run both, right
   after importing `seed.sql`, using the exact same absolute path
   you'll put in `STORAGE_BASE_PATH` below:
   ```sql
   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/opt/nexacrest_node/storage');
   UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/opt/nexacrest_node/storage');
   ```
   Skipping this step doesn't break anything — every generated document
   just silently shows no logo/signature/seal/watermark image (the code
   checks the file exists before embedding it, so it degrades gracefully
   rather than erroring) — but you do want your real logo on real
   documents, so don't skip it. The 5 actual placeholder PNGs already ship
   in `storage/assets/` in this package, at the exact relative paths this
   UPDATE points at.

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

9. **Log in** at `https://www.example.com/login` with the seeded Admin
   account:
   - Email: `admin@nexacrest.placeholder`
   - Password: `ChangeMe#2026`

   You'll be forced onto `/force-password-change` immediately (by design —
   `force_password_change` is set on the seeded row).

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

Two jobs need to run outside the request/response cycle:

- **`src/jobs/checkAlerts.js`** — run once daily. Checks LUT/RCMC
  certificate expiry against their configured alert/escalation thresholds,
  freight payments overdue (FDN issued, not yet cleared, past
  `company_settings.fdn_overdue_days_c`), and dispute responses past their
  due date — creates in-app notifications for Admin/MD for each, with a
  same-day dedup guard so a still-unresolved condition doesn't re-notify
  on every run.
- **`src/jobs/dispatchDeferredEmails.js`** — run every 5–15 minutes. Sends
  every `email_log` row that's `approved` (Level-2 sign-off already given)
  and due (`scheduled_at <= NOW()`, or `NULL` meaning "immediate"). Safe to
  run as often as you like — each row is only ever picked up once, marked
  `sent`/`failed` immediately.

Both are plain Node scripts, runnable directly and printing a one-line
summary to stdout, exit code 0 on success:

```bash
node src/jobs/checkAlerts.js
node src/jobs/dispatchDeferredEmails.js
```

**Pick exactly one of these two ways to run them — never both, or alerts
and deferred emails double-fire:**

**Option 1 — external cron (recommended: simplest, and independent of the
web process's own uptime).** Add two crontab entries:
```
*/10 * * * *  cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/dispatchDeferredEmails.js >> /var/log/nexacrest/dispatch_deferred_emails.log 2>&1
0 2 * * *     cd /opt/nexacrest_node/app && /usr/bin/node src/jobs/checkAlerts.js >> /var/log/nexacrest/check_alerts.log 2>&1
```
(`sudo mkdir -p /var/log/nexacrest` first.) Use the full path to `node`
(`which node`) — cron's `$PATH` is much smaller than an interactive
shell's.

**Option 2 — in-process, via node-cron.** Set `RUN_JOBS_IN_PROCESS=true`
in `.env` and restart the app (`pm2 restart nexacrest`). `src/server.js`
then starts `src/jobs/scheduler.js`, which runs `checkAlerts` daily at
02:00 and `dispatchDeferredEmails` every 10 minutes inside the same
process — nothing else to configure, but job execution is then tied to
the web process's uptime (a restart briefly pauses both jobs, and if you
run multiple app instances behind a load balancer for scaling, each
instance would independently fire the jobs — stick to a single instance,
or use Option 1, if you ever scale out).

## Database backups

`scripts/backup_db.sh` is a template, not a script that ships pre-wired
with real credentials — those never belong in a delivered codebase. Copy
it to the server, fill in `DB_NAME`/`DB_USER`/`DB_PASS`/`BACKUP_DIR` at the
top (matching your real `.env` values), then:

```bash
chmod 700 scripts/backup_db.sh
sudo mkdir -p /var/backups/nexacrest && sudo chmod 700 /var/backups/nexacrest
```

and add a third cron entry:
```
0 2 * * *  /opt/nexacrest_node/scripts/backup_db.sh >> /var/log/nexacrest/backup_db.log 2>&1
```

It uses `mysqldump --single-transaction` (safe against the live InnoDB
tables while the app keeps running), gzips the dump, and prunes anything
older than 30 days. Restoring: `gunzip < nexacrest_YYYYMMDD_HHMMSS.sql.gz
| mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"`. **Test a restore at least
once against a scratch database** after setting this up — this exact
script and restore command were run against this app's own dev database
during this delivery's own verification (see "Verifying this delivery
yourself" below), so the mechanism is proven; only your server's specific
credentials/paths are new.

## Backing up storage/ — the gap a database-only backup leaves

**A database backup alone is not a backup of this application.** Every
generated PDF/DOCX and every received file (dispute evidence, amendment
signed copies, buyer PO copies, supplier PO acknowledgments, product
images, ...) lives on disk under `STORAGE_BASE_PATH`, outside any
web-served directory — `file_store` only holds each one's *path* and
metadata. Restoring the database after a disaster without also restoring
`storage/` leaves every `file_store` row pointing at a file that no
longer exists: every "Download PDF" link 404s, the "Download Full
Dossier (ZIP)" button on an order page silently skips every missing
file, and a document that was ever draft/final-watermark-swapped can
never be regenerated to look the way it did when a reviewer actually
approved it — that exact historical PDF is gone, not reconstructible
from data alone (see `documentGenerationService.js`'s document-
integrity snapshot columns, which only guarantee correct *content* if
the swapped-final PDF file itself still exists).

`scripts/backup_storage.sh` is a template, same convention as
`backup_db.sh` above — copy it to the server, fill in `STORAGE_DIR`/
`BACKUP_DIR` at the top, then:

```bash
chmod 700 scripts/backup_storage.sh
sudo mkdir -p /var/backups/nexacrest_storage && sudo chmod 700 /var/backups/nexacrest_storage
```

and add a fourth cron entry, offset from the DB backup so they don't
compete for I/O:
```
30 2 * * *  /opt/nexacrest_node/scripts/backup_storage.sh >> /var/log/nexacrest/backup_storage.log 2>&1
```

It `tar`s the whole `storage/` tree and prunes anything older than 30
days, same retention as the DB backup. The two backups are only
consistent with each other if taken close together — scheduling both
within the same maintenance window (2:00 and 2:30 above) keeps the drift
to at most 30 minutes. Restoring:
`tar -xzf nexacrest_storage_YYYYMMDD_HHMMSS.tar.gz -C "$(dirname "$STORAGE_DIR")"`.
**Test a restore at least once** — verify a handful of
`file_store.server_path` values from a restored DB backup taken the same
night actually resolve to real files after extracting.

The full order dossier ZIP (`/orders/:id/dossier`, "Download Full
Dossier (ZIP)" on the order page) is a convenient one-order-at-a-time
export for handing files to someone, or spot-checking that an order's
files are all present — it is not a substitute for backing up
`storage/` as a whole; it only ever reflects the current live files.

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

Everything in `company_settings` marked `PLACEHOLDER` in `docs/seed.sql` —
`director_name`, `bank_pincode`, `lut_expiry_date` (confirm the real date),
`client_number_format`/`order_ref_format` (confirm against your actual
numbering convention), `rcmc_number`/`rcmc_valid_until` — plus the seeded
Admin login email/password, and all five asset images (logo, MD signature,
company seal, watermark, email header) currently showing a labeled
placeholder graphic. Replace assets from `/company-assets`, settings from
`/settings` — no code or file changes needed either way.

## What's deliberately not built yet (by design, not by mistake)

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
- **SMS 2FA** has a service class and gating logic but no provider wired
  in — returns `false` until you pick a provider and add credentials.
  Email 2FA works fully.
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
