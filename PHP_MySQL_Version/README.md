# NexaCrest Export Operations Webapp — Phase A + Phase B + Phase C + Phase D + Phase E

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

- `docs/schema.sql` — full database schema (119 tables, 232 foreign keys as
  of 2026-10-30 — see `docs/schema.sql`'s own trailing `-- SECTION X …
  added YYYY-MM-DD` comments for the running changelog; 52 tables/76 FKs
  at the time of this Phase D delivery specifically), validated against a
  live MariaDB instance after every change in this delivery, including the
  Phase D additions below.
- `docs/seed.sql` — starter data: roles/permissions, one login user,
  company settings (a few are still PLACEHOLDER — see below), lookups,
  payment presets, stages, document types, the 21 real Terms & Conditions
  clauses, 9 email templates for the deferred-send pipeline, and the new
  `cross_verify_documents`/`approve_email_send` permissions.
- `app/cron/` — the four scheduled jobs (see "Bluehost cron setup" below):
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

1. **Database.** Create a MySQL/MariaDB database (utf8mb4). Import in order:
   ```
   mysql -u youruser -p yourdb < docs/schema.sql
   mysql -u youruser -p yourdb < docs/seed.sql
   ```
   Both files start with `SET NAMES utf8mb4;`, so the em-dashes and other
   punctuation in the seeded text (T&C clauses, addresses, the draft
   watermark) import correctly regardless of your MySQL client's own
   default charset. (This was a real bug caught during Phase B testing —
   without that line, `mysql < seed.sql` silently mangled every em-dash
   into mojibake. Don't remove it.)
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
   - Email: `gulmohar.sontakke@nexacrestinternational.com`
   - Email: `arti.sontakke@nexacrestinternational.com`
   - Password: `ChangeMe#2026`

   You'll be forced to set a new password on first login (by design —
   `force_password_change` is set on both seeded users).

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
  "Backing up storage/" below).
- **Notifications** (bell icon, top right, unread count shown) — reviewer
  assignments, amendments awaiting MD approval, email sends awaiting Level-2
  approval, and the alert cron's own findings (below) all land here.

### Bluehost cron setup

Four scheduled jobs, all plain PHP scripts meant to be run via
cPanel → Cron Jobs (Bluehost's equivalent of a systemd timer — there is no
persistent worker process on shared hosting, which is exactly why these
exist as polling scripts rather than a queue consumer):

```
*/10 * * * *  php /home/yourcpaneluser/nexacrest_webapp/app/cron/dispatch_deferred_emails.php >> /home/yourcpaneluser/logs/dispatch_deferred_emails.log 2>&1
*/15 * * * *  php /home/yourcpaneluser/nexacrest_webapp/app/cron/auto_confirm_oc_acknowledgments.php >> /home/yourcpaneluser/logs/auto_confirm_oc_acknowledgments.log 2>&1
0 6 * * *     php /home/yourcpaneluser/nexacrest_webapp/app/cron/check_alerts.php >> /home/yourcpaneluser/logs/check_alerts.log 2>&1
0 * * * *     php /home/yourcpaneluser/nexacrest_webapp/app/cron/zoho_sync.php >> /home/yourcpaneluser/logs/zoho_sync.log 2>&1
```

- `dispatch_deferred_emails.php` — recommended every 5–15 minutes. Sends
  every `email_log` row that's `approved` and due
  (`scheduled_at <= NOW()`). Safe to run more often; each row is only ever
  picked up once (marked `sent`/`failed` immediately).
- `auto_confirm_oc_acknowledgments.php` — recommended every 15 minutes.
  Auto-confirms any Order Confirmation the buyer hasn't acknowledged
  (client portal button, or a staff-recorded email reply) within 48 hours
  of it being emailed, unlocking Stage 5 (docs/schema.sql Section AE).
  Idempotent — a row is only ever picked up once it has `acknowledged_at
  IS NULL AND due_at <= NOW()`, and acknowledging it (by any means) before
  this runs removes it from that set.
- `check_alerts.php` — recommended once daily. Checks LUT/RCMC expiry
  against their configured alert/escalation thresholds, freight payment
  overdue (FDN issued, not yet cleared, past
  `fdn_overdue_days_c`), and dispute responses past their due date, and
  creates in-app notifications for Admin/MD (see "Real bugs found" below —
  this has a same-day dedup guard so it doesn't re-notify on every run
  while a condition remains unresolved).
- `zoho_sync.php` — CA / Accounting module. Recommended hourly. Runs the
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
  or the Zoho Books switch in Admin → Company Settings off) it logs one "skipped"
  row per part and exits 0. The `/ca/zoho-sync` page shows this job's log
  alongside Sync Now's.

All four scripts print a one-line summary to stdout and exit 0 on
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

### Bluehost database backups (Section 14)

Nothing in this codebase can run a backup for you from this sandbox — there's
no server to schedule against — so this is documentation to follow on the
real Bluehost account, not a script that ships pre-wired. Add a third cPanel
→ Cron Job alongside the two above:

```
0 2 * * *  /home/yourcpaneluser/nexacrest_webapp/scripts/backup_db.sh >> /home/yourcpaneluser/logs/backup_db.log 2>&1
```

`scripts/backup_db.sh` (create this file on the server — it isn't part of
the app itself, since it needs real production DB credentials that never
belong in the delivered codebase):

```bash
#!/bin/bash
set -euo pipefail

# Bluehost/cPanel database names and usernames are typically prefixed with
# your cPanel account name (e.g. cpaneluser_nexacrest) — use the exact
# values from cPanel → MySQL Databases, which will differ from the
# DB_DATABASE/DB_USERNAME in this app's .env in the sandbox.
DB_NAME="cpaneluser_nexacrest"
DB_USER="cpaneluser_nexacrest"
DB_PASS="REPLACE_WITH_REAL_PASSWORD"     # or read from a chmod-600 file outside public_html
BACKUP_DIR="/home/yourcpaneluser/db_backups"   # outside public_html/ — never web-reachable
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
FILE="$BACKUP_DIR/nexacrest_${STAMP}.sql.gz"

mysqldump --single-transaction --quick --routines --triggers \
  -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$FILE"

# 30-day retention — delete anything older, keep everything newer.
find "$BACKUP_DIR" -name 'nexacrest_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Backup complete: $FILE ($(du -h "$FILE" | cut -f1))"
```

```
chmod 700 scripts/backup_db.sh   # DB password is inside it
chmod 700 /home/yourcpaneluser/db_backups
```

Notes specific to Bluehost shared hosting:

- `--single-transaction` avoids table locks on the live InnoDB tables while
  the dump runs (this schema is 100% InnoDB) — safe to run at 2am while the
  app is technically still reachable.
- Store backups outside `public_html/` (as above) — anything inside
  `public_html/` is web-reachable unless you've separately blocked `.sql.gz`
  downloads, and a stray database dump sitting in a web root is a real
  incident waiting to happen, not a hypothetical one.
- Bluehost's own cPanel → Backup Wizard can additionally take a full-account
  snapshot on its own schedule — this script is the finer-grained,
  app-aware daily backup the spec asks for (30-day retention, DB only, no
  manual step); the two are complementary, not either/or.
- Restoring: `gunzip < nexacrest_YYYYMMDD_HHMMSS.sql.gz | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"`.
  Test this at least once against a scratch database after first setting
  this up — an untested backup is not a backup.
- If Bluehost's plan doesn't expose cron minute-level granularity or
  `mysqldump` isn't on the shell `PATH` for cron's environment (cron often
  runs with a much smaller `$PATH` than an interactive SSH shell), use the
  full path (`which mysqldump` over SSH first) inside the script.

(This backup job is Phase E work — it's grouped with the other two cron
jobs above rather than under "Using Phase E" below because it's the same
cPanel → Cron Jobs mechanism, not because it's a Phase D feature.)

### Backing up storage/ — the gap a database-only backup leaves (Section 3, 14)

**A database backup alone is not a backup of this application.** Every
generated PDF/DOCX and every received file (dispute evidence, amendment
signed copies, buyer PO copies, supplier PO acknowledgments, product
images, ...) lives on disk under `STORAGE_BASE_PATH`, outside
`public_html/` — `file_store` only holds each one's *path* and metadata.
Restoring the database after a disaster without also restoring
`storage/` leaves every `file_store` row pointing at a file that no
longer exists: every "Download PDF" link 404s, the "Download Full
Dossier (ZIP)" button on an order page silently skips every missing
file, and a document that was ever draft/final-watermark-swapped can
never be regenerated to look the way it did when a reviewer actually
approved it — that exact historical PDF is gone, not reconstructible
from data alone (see DocumentGenerationService's document-integrity
snapshot columns, which only guarantee correct *content* if the swapped-
final PDF file itself still exists).

Add a fourth cPanel → Cron Job alongside the three above, offset from
the DB backup so they don't compete for I/O:

```
30 2 * * *  /home/yourcpaneluser/nexacrest_webapp/scripts/backup_storage.sh >> /home/yourcpaneluser/logs/backup_storage.log 2>&1
```

`scripts/backup_storage.sh` (create this file on the server, same reason
as `backup_db.sh` — it references real paths that shouldn't be baked
into the delivered codebase):

```bash
#!/bin/bash
set -euo pipefail

STORAGE_DIR="/home/yourcpaneluser/nexacrest_webapp/storage"   # same value as STORAGE_BASE_PATH in .env
BACKUP_DIR="/home/yourcpaneluser/storage_backups"              # outside public_html/ — never web-reachable
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
FILE="$BACKUP_DIR/nexacrest_storage_${STAMP}.tar.gz"

tar -czf "$FILE" -C "$(dirname "$STORAGE_DIR")" "$(basename "$STORAGE_DIR")"

find "$BACKUP_DIR" -name 'nexacrest_storage_*.tar.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Storage backup complete: $FILE ($(du -h "$FILE" | cut -f1))"
```

```
chmod 700 scripts/backup_storage.sh
chmod 700 /home/yourcpaneluser/storage_backups
```

Notes:

- A full `tar` of the whole tree is the simplest correct approach for a
  cron job that has to run unattended — `rsync -a --delete` to a second
  location is a reasonable alternative if `storage/` grows large enough
  that a nightly full tarball becomes slow or expensive to keep 30 days
  of, but loses the point-in-time-snapshot property `tar` gives you for
  free (an `rsync --delete` mirror only has the *current* state, not
  yesterday's).
- The DB backup and the storage backup are **only consistent with each
  other if taken close together** — a `file_store` row inserted between
  the two backup runs will reference a file the storage backup doesn't
  have yet (recoverable — worst case that one file is missing on
  restore) or, worse, a storage backup taken well after the DB backup
  can contain files whose `file_store` row was itself part of an order
  that later changed. Scheduling both within the same maintenance window
  (2:00 and 2:30 above) keeps the drift to at most 30 minutes; for a
  stronger guarantee, take both backups back-to-back inside one script
  instead of two separate cron entries.
- Restoring: `tar -xzf nexacrest_storage_YYYYMMDD_HHMMSS.tar.gz -C /home/yourcpaneluser/nexacrest_webapp/`
  restores the whole tree back to `storage/` at that path. As with the DB
  backup, test this at least once against a scratch location — verify a
  handful of `file_store.server_path` values from a restored DB backup
  taken the same night actually resolve to real files after extracting.
- Bluehost's cPanel → Backup Wizard full-account snapshot (mentioned
  above for the database) already includes `storage/` if it sits inside
  the account's home directory — this script is still worth having as
  the finer-grained, retained-on-your-own-schedule backup, same
  reasoning as the DB backup script being complementary to it, not a
  replacement.
- The full order dossier ZIP (`/orders/{id}/dossier`, "Download Full
  Dossier (ZIP)" on the order page) is a convenient one-order-at-a-time
  export for handing files to someone, or spot-checking that an order's
  files are all present — it is not a substitute for backing up
  `storage/` as a whole; it only ever reflects the current live files.

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

Everything in `company_settings` marked `PLACEHOLDER` in `docs/seed.sql` —
`director_name`, `bank_pincode`, `lut_expiry_date` (assumed FY-end; confirm
the real date) — plus the login email/password above, and all four asset
images (logo, MD signature, company seal, watermark)
currently showing a labeled placeholder graphic. Replace assets from the
in-app **Assets** screen (`/company-assets`) — no code changes needed, just
upload the real file. Replace settings from the **Company Settings** screen
(`/settings`).

Also placeholder, specific to Phase B: `client_number_format` and
`order_ref_format` in `company_settings` — confirm these against your
actual numbering convention; they're used as-is to generate every new
client's and order's reference number.

New in Phase C, also placeholder: `rcmc_number` and `rcmc_valid_until` in
`company_settings` (category `capexil`) — the COO Prep Sheet's Section 4
needs your real CAPEXIL RCMC certificate number and its expiry date; the
alert-threshold settings for it (`rcmc_alert_days_a`/`rcmc_escalation_days_b`)
were already seeded in Phase A but the actual number/date fields were
missing until now (a genuine gap in the original 51-table design — added
this phase, not a placeholder left from before).

One still-open item carried over from Phase A: whether a Director
signature (in addition to the MD signature) is actually needed anywhere in
the document set — flagged in `company_settings.director_name`'s
description, not yet resolved.

## What's deliberately not built yet (by phase, not by mistake)

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
- SMS 2FA has a service class and gating logic (`SmsService`) but no actual
  provider wired in — it's a placeholder that returns `false` until you
  pick a provider and add the API key. Email 2FA is fully working.
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
- Actual execution of the Bluehost DB backup — Phase E documents the exact
  cron job and script (see "Bluehost database backups" above) but can't run
  or test it from this sandbox, since there's no real Bluehost account or
  cron scheduler to run it against.
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

**Current matrix (2026-10-30):** 8 roles, 63 permissions. Admin and
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

**Upgrading an existing database:** run the files in `docs/migrations/`
the database has not had yet, in filename order — the run order and the
read-only checks for this release are in the repository's
`docs/migrations/README.md`.

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

**Upgrading an existing database:** run `2026-10-29a` to `2026-10-29f` and
`2026-10-30a` from `docs/migrations/` (run order in the repository's
`docs/migrations/README.md`). Before switching Zoho on, set the go-live
date in Admin → Company Settings (Zoho Books).

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
   "Zoho Books enabled" switch are company settings (Admin → Company Settings,
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
  - `clients.email` is unique (index `uq_clients_email`, migration
    `docs/migrations/2026-10-31a-client-email-unique.sql` — run the
    duplicate-email check first). Two saves of one email at once: the second
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
