# Deployment guide — Bluehost (cPanel shared hosting), PHP version

Written 14 Nov 2026 from the live installation done on 13–14 Nov 2026, step by step as it was
actually done and checked on the server. Follow it top to bottom for a **fresh installation**;
section 14 is for **updating** a site that is already live.

- The **business** side of go-live (company settings, people, prices, dry run) is in
  `docs/guides/GO_LIVE_CHECKLIST.md`. This guide is the **server** side. Do this guide first,
  then the checklist from section C.
- The long explanations behind each step stay in `PHP_MySQL_Version/README.md` ("First-time
  setup", "Web lock", "Bluehost cron setup", "Backups"). This guide tells you **what to click and
  type, in which order, and what you should see**.
- **No real values in this file.** Paths, user names and passwords below are placeholders.
  Real values are typed only on the server (no-PII rule). Never paste a password, the
  `app/.env` contents, the backup key or a log containing them into a chat, an e-mail or a file
  in the repository.

Placeholders used throughout:

| Placeholder | Meaning | Example (not real) |
|---|---|---|
| `<home>` | your cPanel home folder (File Manager shows it at the top) | `/home3/abcduser` |
| `<cpuser>` | your cPanel user name — cPanel puts it in front of every database and database-user name | `abcduser` |
| `<project>` | the folder that holds the code from Git (the folder with `PHP_MySQL_Version/` in it) | `<home>/nexacrest_webapp` |
| `<app>` | `<project>/PHP_MySQL_Version` — it holds `app/`, `public_html/`, `scripts/`, `storage/` | |
| `<site>` | the address staff use | `https://ops.example.com` |
| `<other-domain>` | any other domain on the same hosting account (used only for the lock tests) | `https://www.example.com` |

---

## 0. Before you start — what you need

1. A Bluehost (cPanel) account with: **File Manager**, **Git Version Control**, **MySQL
   Databases**, **phpMyAdmin**, **Cron Jobs**, **Domains**, **SSL/TLS Status**,
   **MultiPHP Manager**. **Terminal** (Advanced › Terminal) is useful but not required — every step
   below has a way without it.
2. Server software (checked by Bluehost, nothing to install):
   - **PHP 8.1 or newer** with the extensions `pdo_mysql`, `mbstring`, `gd`, `curl`, `zip`,
     `openssl`, `fileinfo`, `dom`/`xml`.
   - **MySQL 5.7 or newer** (the live host runs MySQL 5.7.44, checked 14 Nov 2026 with `SELECT VERSION();`; queries pass ONLY_FULL_GROUP_BY; no MySQL 8-only SQL such as `ROW_NUMBER() OVER` or `WITH`).
   - **Apache with mod_rewrite** (`.htaccess` routing).
   - Outbound HTTPS to `api.frankfurter.app` and `open.er-api.com` (the daily exchange rate).
   - Nothing else: PDF and Word files are made by pure-PHP libraries already in `app/vendor/`
     (no Composer, no Chromium on the server).
3. The **GitHub repository** address and the **branch** to deploy, and permission to add a
   read-only deploy key to the repository (section 2).
4. An **e-mail account for sending** (SMTP host, port, user, password) — every login asks for a
   code sent by e-mail, so mail must work **before** the first login.
5. A **password manager** (or a safe place) for: the database password, `APP_ENCRYPTION_KEY`,
   the backup key, and the MD's / Admin's backup login codes.
6. About 2 hours, outside working hours if a site is already live.

---

## 1. Decide where the folders go

**Recommended (safest):** the project **outside** `public_html`, only the app's own web folder
reachable from the internet:

```
<home>/nexacrest_webapp/                      ← <project>  (from Git)
<home>/nexacrest_webapp/PHP_MySQL_Version/    ← <app>
<home>/nexacrest_webapp/PHP_MySQL_Version/public_html/   ← the ONLY folder the subdomain points at
<home>/nexacrest_backups/                     ← BACKUP_DIR          (0700)
<home>/nexacrest_backup_key/                  ← the key folder      (0700)
<home>/nexacrest_backup_key/.nexacrest_backup_key   ← the key file (0600)
<home>/logs/                                  ← cron logs
```

**Also supported (how the current live site is set up, 13 Nov 2026):** the project **inside**
`public_html` (for example `<home>/public_html/<some folder>/ops/nexacrest_webapp`). That is safe
only because of the **web lock** (section 7): the project's top `.htaccess` denies everything and
only `PHP_MySQL_Version/public_html` re-opens itself. Backups and the key may then also sit inside
`public_html` **only** with folder permission **0700** plus their own `.htaccess`
(`Require all denied`) — section 11.

Rules that hold either way:

- `BACKUP_DIR` must **not** be inside `<app>/public_html`, `<app>/app` or the storage folder —
  the backup scripts refuse to run otherwise.
- The **key folder sits next to the backup folder, never inside it**: whoever gets a copy of
  the backups must not get the key with it (and an off-site copy of the backups must never carry
  the key).
- The `logs` folder sits in `<home>`, **not** inside `public_html`.

Write your chosen paths down (password manager) — you will type them in sections 6, 10 and 11.

---

## 2. Get the code with cPanel Git Version Control

1. cPanel › **Files › Git Version Control** › **Create**.
2. Switch **Clone a Repository** on.
3. **Clone URL:** the repository's SSH address (`git@github.com:<owner>/<repo>.git`).
   For a private repository cPanel must be allowed to read it:
   1. cPanel › **Security › SSH Access › Manage SSH Keys** › **Generate a New Key** (no
      passphrase), then **Manage › Authorize**, and **View/Download** the **public** key.
   2. GitHub › the repository › **Settings › Deploy keys › Add deploy key**: paste the public key,
      title "Bluehost", leave **Allow write access** off.
4. **Repository Path:** `<project>` (from section 1). **Repository Name:** `nexacrest_webapp`.
5. **Create**. Wait until the list shows the repository.
6. **Manage** › **Basic Information** › **Checked-Out Branch:** choose the branch you deploy.
   **Update**.
7. Check in File Manager (top right **Settings › Show Hidden Files (dotfiles)** ON): `<project>`
   contains `.htaccess`, `PHP_MySQL_Version/`, `NODE_MySQL_Version/`, `docs/`.

**From now on the server's code changes only through Git** ("Update from Remote", section 14).
Never edit a file that came from Git directly on the server — the next update then fails
("Your local changes would be overwritten"). The only files you create or edit by hand are the
ones Git does not hold: `app/.env`, the backup and key folders, and their `.htaccess` files.

---

## 3. Point the address at the app's web folder

1. cPanel › **Domains** › **Create A New Domain**.
2. **Domain:** the staff address, e.g. `ops.example.com`.
3. Untick **Share document root**.
4. **Document Root:** `<app>/public_html` — the app's own `public_html` inside
   `PHP_MySQL_Version`, **not** `<project>` and **not** `<app>`.
5. **Submit**.
6. cPanel › **Security › SSL/TLS Status** › tick the new domain › **Run AutoSSL**. Wait until it
   shows a valid certificate. (The app's `.htaccess` sends every `http://` request to `https://`.)
7. cPanel › **Software › MultiPHP Manager** › tick the domain › choose **PHP 8.1 or newer** ›
   **Apply**. If cPanel has **Select PHP Version › Extensions**, make sure the extensions in
   section 0 are ticked.

Do not open the site yet — it needs the database and `app/.env` first.

---

## 4. Create the databases (cPanel › MySQL Databases)

1. **Create New Database:** e.g. `ops` → cPanel names it `<cpuser>_ops`. Write the **full** name
   down.
2. **Create New Database** again for the monthly backup test: e.g. `ops_restore_test` →
   `<cpuser>_ops_restore_test`. It stays empty; the test fills and empties it by itself.
3. **MySQL Users › Add New User:** e.g. `opsapp` → `<cpuser>_opsapp`. Use **Password Generator**,
   save the password in the password manager. Never reuse it anywhere.
4. **Add User To Database:** user `<cpuser>_opsapp`, database `<cpuser>_ops` › **Add** › tick
   **ALL PRIVILEGES** › **Make Changes**. (ALL PRIVILEGES includes **TRIGGER**, which the schema
   needs.)
5. **Add User To Database** again: the same user, database `<cpuser>_ops_restore_test` ›
   **ALL PRIVILEGES** › **Make Changes**. The test uses the app's own login from `app/.env`, so no
   second user or password is needed.

---

## 5. Load the database (phpMyAdmin)

A fresh installation runs **no migration**: `schema.sql` and `seed.sql` are the whole database.

1. cPanel › **Databases › phpMyAdmin**. Click `<cpuser>_ops` in the left list.
2. **Import** › choose `<app>/docs/schema.sql` (download it first from File Manager to your
   computer) › character set **utf-8** › **Import**. It must end with a green "Import has been
   successfully finished".
3. **Import** again with `<app>/docs/seed.sql`. Green message again.
4. **SQL** tab: replace the storage-path placeholder. Use the real path of `<app>/storage`
   (the same value you put in `STORAGE_BASE_PATH` in section 6), no slash at the end:
   ```sql
   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '<app>/storage');
   UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '<app>/storage');
   UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '<app>/storage');
   ```
   Skipping this does not show an error, but every signature and seal on the documents prints
   blank.
5. **SQL** tab: the **founder block** — README "First-time setup", step 2, "Set the real logins
   and company details". It lifts the founder-protection trigger for a moment, puts in the real
   MD / ED names and e-mails, the company phone, e-mail and registered office, and puts the
   trigger back. In phpMyAdmin: paste the whole block; if phpMyAdmin complains at `DELIMITER`,
   delete the two `DELIMITER` lines, type `$$` in the **Delimiter** box under the SQL text, and
   press **Go** again. Then run `SHOW TRIGGERS LIKE 'users';` — `trg_users_protected_bu` must be
   listed.
6. **SQL** tab (optional, or do it later on Settings › Company): the **company details block** —
   README "First-time setup", "Set the company's real details by SQL" (identity, bank, GSTIN,
   IEC/PAN, LUT, RCMC, ECGC). Its last query must return **0**.
7. **SQL** tab: run the read-only migration check `docs/checks/check_all_migrations.sql` (paste
   all of it › **Go**). On a fresh install **every** row must say **already done** (or, while no migration has been added since the fresh install of 14 Nov 2026, the one row "(none yet) … nothing to run"). (The green
   bar may say "0 total" — a phpMyAdmin display quirk; the table below it is what counts.)

---

## 6. Create `app/.env`

1. File Manager › `<app>/app/` › select `.env.example` › **Copy** › new name `.env` (same
   folder).
2. **Edit** `.env` and set every line below. Type values without quotes.

| Line | Value |
|---|---|
| `APP_ENV` | `production` (never `local` on the server) |
| `DEV_SERVER` | `false` |
| `APP_URL` | `<site>` (e.g. `https://ops.example.com`, no slash at the end) |
| `APP_ENCRYPTION_KEY` | a new random value of 64 hex characters, made once and **never changed** (section 6.4) |
| `DB_HOST` | `localhost` |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | `<cpuser>_ops` |
| `DB_USERNAME` | `<cpuser>_opsapp` |
| `DB_PASSWORD` | the password from section 4 |
| `DB_CHARSET` | `utf8mb4` |
| `STORAGE_BASE_PATH` | `<app>/storage` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `SMTP_FROM_ADDRESS`, `SMTP_FROM_NAME` | the sending mailbox (Bluehost: cPanel › Email Accounts › Connect Devices shows host and port; `tls` with 587, `ssl` with 465) |
| `ZOHO_INTEGRATION_ENABLED` | `false` until the live Zoho Books organisation is ready, then `true` |
| `ZOHO_ENVIRONMENT` | `test` until then, `production` on the live site afterwards |
| `ZOHO_EINVOICE_ENABLED` | `false` |
| `SMS_GATEWAY_*` | leave empty |
| `BACKUP_DIR` | the backup folder from section 1 (filled in section 11) |
| `BACKUP_KEEP_YEARS` | `10` |
| `BACKUP_THIN_AFTER_DAYS` | `120` |
| `BACKUP_ENCRYPTION_KEY_FILE` | the key file from section 1 (filled in section 11) |
| `BACKUP_OFFSITE_TARGET` | empty |
| `BACKUP_RESTORE_TEST_DB` | `<cpuser>_ops_restore_test` (the full name cPanel showed) |

3. Never comment out the three `ZOHO_` lines; keep them written out. The Zoho Books client ID,
   secret and organisation are **not** put here — they go into Settings › Zoho in the app.
4. **Making `APP_ENCRYPTION_KEY`** — one of:
   - Terminal: `php -r "echo bin2hex(random_bytes(32));"` and copy the 64 characters; or
   - a password manager's generator: 64 characters, only `0-9` and `a-f`.
   Save it in the password manager. Changing it later breaks every Buyer Action Link already sent.
5. **Save.** Right-click `.env` › **Change Permissions** › **0600** (owner read/write only; Bluehost
   runs PHP as your own account). If the site then shows a server error, use **0640**.
6. Never put a copy of `.env` (or `.env.bak`, `.env.old`) anywhere else.

---

## 7. Web lock and folders — check before the first login

1. File Manager (hidden files shown): `<project>/.htaccess` exists and contains
   `Require all denied` (with comment lines starting with `#`). It comes from Git.
2. `<app>/public_html/.htaccess` starts with a comment line and then `Require all granted`.
   Also from Git.
3. Create `<app>/storage/sessions` if it is missing (File Manager › **+ Folder**), permission
   **0700**. Without it staff are signed out after about 24 minutes.
4. Create `<home>/logs` (in the home folder, **not** inside `public_html`) — every cron job writes
   its log there; without it the jobs fail.
5. **Test in a private browser window** (expected result in brackets):
   | Address | Must show |
   |---|---|
   | `<site>/login` | the sign-in page |
   | `<site>/.env` | 404 Not Found, or Not Acceptable |
   | `<site>/README.md` | 404 Not Found |
   | only if the project is inside `public_html`: `<other-domain>/<path to project>/PHP_MySQL_Version/README.md` | **403 Forbidden** |
   | only if inside `public_html`: `<other-domain>/<path to project>/PHP_MySQL_Version/app/.env` | **Not Acceptable** (Bluehost's firewall, Mod_Security) or 403 Forbidden |
   | only if inside `public_html`: `<other-domain>/<path to project>/PHP_MySQL_Version/storage/` | 403 Forbidden |

   Any address that **downloads a file or shows text** is a failure: stop, and fix the lock
   before going further. Do not paste what you saw anywhere.
6. Note: opening many "secret-looking" addresses in a row can make Bluehost's firewall slow down
   or pause **your own** connection for 15–60 minutes (all your sites then load slowly). That is
   the firewall working; wait, or test from a phone on mobile data.

---

## 8. First login and company setup

1. Open `<site>/login`. Log in with the **real MD e-mail** you put in the founder block and the
   seeded password `ChangeMe#2026`.
2. Enter the **6-digit code** from the e-mail. (No e-mail? SMTP in `.env` is wrong — fix it
   first; the code is only written to the server error log.)
3. Set a new password (forced on first login).
4. **My Account › Backup codes:** make the 10 one-time codes, keep them offline. The Admin does
   the same at their first login.
5. Continue with `docs/guides/GO_LIVE_CHECKLIST.md` from section **C** (Setup checklist, company
   settings, e-mail, prices, people …). The **Setup checklist** page in the app
   (Settings › Setup checklist) must end with every item **Done**.

---

## 9. Find the server's clock (needed for the cron times)

Bluehost servers normally run on **US Mountain Time**, not India time. Check once:

1. cPanel › **Advanced › Cron Jobs**. At the top, **Cron Email**: set an admin address and
   **Update Email** — every cron failure is mailed there.
2. **Add New Cron Job:** all five time boxes `*`, **Command:**
   ```
   date > <home>/servertime.txt
   ```
   **Add New Cron Job**.
3. Wait 2 minutes. File Manager › `<home>/servertime.txt` › View. Example:
   `Fri Nov 13 04:22:01 PM MST 2026`.
4. **Delete this cron job** at once, and delete `servertime.txt`.
5. Read the zone:
   - **MST / MDT** (Mountain Time): use the **Server hour** columns in section 10. India is
     **12 h 30 min ahead** of MST (November–March) and **11 h 30 min ahead** of MDT
     (March–November).
   - **IST**: use the **India time** column directly.
   - **UTC**: India time minus 5 h 30 min.

`CRON_TZ=Asia/Kolkata` (shown in the README) only helps where the host honours it; cPanel's
form has no place for it, so on Bluehost type the server's own hours as below.

---

## 10. The ten cron jobs

cPanel › **Advanced › Cron Jobs** › **Add New Cron Job**, once per row. Type the five boxes
exactly; paste the command into **Command** with your real `<app>` and `<home>`.

| # | Job | Minute | Hour (server, MST) | Day | Month | Weekday | India time (IST) |
|---|---|---|---|---|---|---|---|
| 1 | E-mails waiting to be sent | `0` | `*` | `*` | `*` | `*` | every hour |
| 2 | OC auto-confirm | `30` | `20` | `*` | `*` | `*` | 09:00 daily |
| 3 | Zoho Books sync | `30` | `10` | `*` | `*` | `*` | 23:00 daily |
| 4 | Alerts | `30` | `17` | `*` | `*` | `*` | 06:00 daily |
| 5 | Exchange rate | `35` | `18` | `*` | `*` | `*` | 07:05 daily |
| 6 | Morning digest e-mail | `45` | `19` | `*` | `*` | `*` | 08:15 daily |
| 7 | Document clean-up | `45` | `13` | `*` | `*` | `*` | 02:15 daily |
| 8 | Database backup | `30` | `13` | `*` | `*` | `*` | 02:00 daily |
| 9 | Documents backup | `0` | `14` | `*` | `*` | `*` | 02:30 daily |
| 10 | Backup test-restore | `30` | `15` | `1` | `*` | `*` | 04:00 on the 2nd of each month |

Row 10's **Day must be `1`** (not `*`), or the test-restore runs every day.

If the server clock is **IST**, type the India time instead (e.g. row 6: Minute `15`, Hour `8`;
row 10: Minute `0`, Hour `4`, Day `2`).

**Commands** (same numbers):

```
1  php <app>/app/cron/dispatch_deferred_emails.php >> <home>/logs/dispatch_deferred_emails.log 2>&1
2  php <app>/app/cron/auto_confirm_oc_acknowledgments.php >> <home>/logs/auto_confirm_oc_acknowledgments.log 2>&1
3  php <app>/app/cron/zoho_sync.php >> <home>/logs/zoho_sync.log 2>&1
4  php <app>/app/cron/check_alerts.php >> <home>/logs/check_alerts.log 2>&1
5  php <app>/app/cron/fetch_fx_rate.php >> <home>/logs/fetch_fx_rate.log 2>&1
6  php <app>/app/cron/daily_digest.php >> <home>/logs/daily_digest.log 2>&1
7  php <app>/app/cron/document_copy_cleanup.php >> <home>/logs/document_copy_cleanup.log 2>&1
8  <app>/scripts/backup_db.sh >> <home>/logs/backup_db.log 2>&1
9  <app>/scripts/backup_storage.sh >> <home>/logs/backup_storage.log 2>&1
10 <app>/scripts/backup_test_restore.sh >> <home>/logs/backup_test_restore.log 2>&1
```

(Do not type the row number at the front of the command.)

Notes:

- Add rows **8–10 only after section 11** (the backup set-up). Until then they fail.
- If a log or the cron e-mail says `php: command not found` (or shows an old PHP version), replace
  `php` with `/usr/local/bin/php` in rows 1–7.
- **How often** — the owner's choice for the live site (13 Nov 2026): e-mails **hourly** (an
  approved e-mail may wait up to an hour; login codes are sent at once, not by this job),
  OC auto-confirm **daily** (a buyer's 48-hour deadline is acted on at the next 09:00 run),
  Zoho **daily** (staff press **Sync now** on Zoho Books Sync, or **Sync this order**, for anything
  urgent). The README's general recommendation is more frequent (e-mails every 10 minutes,
  auto-confirm every 15 minutes, Zoho hourly); either works.
- **Summer time:** from the second Sunday of March to the first Sunday of November Mountain Time
  is one hour ahead (MDT), so every job runs **one hour earlier** in India (digest 07:15, backups
  01:00/01:30). Either accept it, or take 1 off every **Hour** in March and add it back in
  November.
- **Never** schedule `zoho_cleanup_test_records.php` or `reorganize_order_files.php` — they are
  run by hand only.
- **First check:** after the next full hour `<home>/logs/dispatch_deferred_emails.log` exists and
  shows no error.

---

## 11. Backups — one-time set-up

The backups only **read** the live database and documents; they never change them. Records are
kept 10 years.

### 11.1 Folders

1. File Manager › create the backup folder (section 1), e.g. `<home>/nexacrest_backups`.
2. Create the key folder **next to it** (not inside), e.g. `<home>/nexacrest_backup_key`.
3. Right-click each folder › **Change Permissions** › only **User** Read/Write/Execute ticked →
   **0700**.
4. **Only if the folders are inside `public_html`:** in **each** of the two folders create a file
   named `.htaccess` containing exactly:
   ```
   Require all denied
   ```
   With the folder at 0700 a browser then gets *"Forbidden … Server unable to read htaccess file,
   denying access to be safe"* — that is the **expected, safe** answer: the web server cannot even
   enter the folder. The `.htaccess` is the spare lock if the permission is ever loosened.

### 11.2 The encryption key (created once, on the server)

Option A — **Terminal** (cPanel › Advanced › Terminal), two lines:

```
openssl rand -base64 48 > <keyfolder>/.nexacrest_backup_key
chmod 600 <keyfolder>/.nexacrest_backup_key
```

Option B — **a one-time cron job** (no Terminal): all five boxes `*`, Command:

```
[ -f <keyfolder>/.nexacrest_backup_key ] || (openssl rand -base64 48 > <keyfolder>/.nexacrest_backup_key && chmod 600 <keyfolder>/.nexacrest_backup_key)
```

Wait 2 minutes, then **delete that cron job** (it never overwrites an existing key, but do not
leave it).

Then:

1. File Manager (hidden files shown): `.nexacrest_backup_key` exists, about **65 bytes**,
   permission **0600**.
2. **Save a copy off the server:** open the file, copy its one line into the password manager
   (or download it to a USB stick kept in the office safe). **Without this copy the backups can
   never be opened if the server is lost.** Never e-mail it, never put it in the repository,
   never paste it into a chat.
3. Never create a second key over the first: backups made with the old key would only open with
   the old key.

### 11.3 `app/.env`

Set the backup lines (section 6) to the real paths:

```
BACKUP_DIR=<backupfolder>
BACKUP_KEEP_YEARS=10
BACKUP_THIN_AFTER_DAYS=120
BACKUP_ENCRYPTION_KEY_FILE=<keyfolder>/.nexacrest_backup_key
BACKUP_OFFSITE_TARGET=
BACKUP_RESTORE_TEST_DB=<cpuser>_ops_restore_test
```

The restore test needs no separate user or password — it uses `DB_USERNAME` / `DB_PASSWORD`
through a temporary login file it deletes afterwards. It refuses to run if
`BACKUP_RESTORE_TEST_DB` is the live database.

### 11.4 Make the scripts runnable

File Manager › `<app>/scripts/` › right-click each of `backup_db.sh`, `backup_storage.sh`,
`backup_test_restore.sh`, `backup_lib.sh` › **Change Permissions** › **0700** (User
Read/Write/Execute only). Leave the `tests` folder as it is. Without this the backup cron jobs fail
with "Permission denied". (Terminal: `chmod 700 <app>/scripts/*.sh`.)

### 11.5 First run, now

1. Add a **one-time** cron job, all boxes `*`:
   ```
   <app>/scripts/backup_db.sh >> <home>/logs/backup_db_test.log 2>&1
   ```
   Wait 2 minutes, then **delete it**.
2. Check:
   - `<backupfolder>/` now has `db/`, `storage/` and `status/` (0755 inside a 0700 folder is fine);
   - `<backupfolder>/db/` has `nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc` (a real size, e.g. a few
     MB) and the same name ending `.sha256`. The time in the name is **server** time;
   - the last line of `<home>/logs/backup_db_test.log` reports the file and its size, no `ERROR`;
   - in the app, **Settings › Backups**: the database row says **OK**.
3. Do the same once with `backup_storage.sh` (log `backup_storage_test.log`) — `storage/snapshots/`
   gets today's folder; Settings › Backups shows documents **OK**.
4. Do the same once with `backup_test_restore.sh` (log `backup_test_restore_test.log`) — the log
   ends `Test-restore OK: … tables, … orders`; Settings › Backups shows the test-restore **OK**.
   Between runs the test database may be missing from cPanel's list — normal; the user keeps its
   rights on that name.
5. Delete the three one-time cron jobs if not already done.
6. Now add cron rows **8, 9 and 10** (section 10). Row 10: **Day `1`**.

### 11.6 Test that the backups cannot be reached from the web

Only if the backup and key folders are inside `public_html`, in a private window:

| Address | Must show |
|---|---|
| `<other-domain>/<path>/<backup folder>/` | 403 Forbidden |
| `<other-domain>/<path>/<backup folder>/db/` | 403 Forbidden |
| `<other-domain>/<path>/<key folder>/` | 403 Forbidden |
| `<other-domain>/<path>/<key folder>/.nexacrest_backup_key` | 403 Forbidden, Not Acceptable, or a page that never loads |

A download or a line of text is a failure: make a **new** key at once (11.2), delete the old key
and every backup made with it, and run 11.5 again.

---

## 12. Final check — the installation is complete when

- [ ] `<site>/login` shows the sign-in page over **https**; the MD logged in with the e-mailed code.
- [ ] Every address in sections 7.5 and 11.6 gives the expected answer.
- [ ] `check_all_migrations.sql` shows every row **already done** (or "nothing to run").
- [ ] Ten cron jobs are listed, with row 10 Day = `1`; the one-time jobs (server time, key, test
      runs) are deleted; the Cron Email is set.
- [ ] `<backupfolder>/db/` holds an encrypted backup with its `.sha256`; Settings › Backups shows
      database, documents and test-restore **OK**.
- [ ] A copy of the backup key, the database password and `APP_ENCRYPTION_KEY` is in the
      password manager, **not** on the server only.
- [ ] Settings › Setup checklist: every item **Done**; GO_LIVE_CHECKLIST sections C–F done.
- [ ] cPanel › **Metrics › Raw Access**: **Archive logs in your home directory** switched on, so
      access history is kept.

---

## 13. After installation — routine checks

**The next morning:**

1. `<backupfolder>/db/` has a new file from the night; `storage/snapshots/` has today's folder.
2. Settings › Backups: database and documents **OK**.
3. The morning digest e-mail arrived (around 08:15 IST).
4. `<home>/logs/`: open each log; the last lines show no `ERROR`, `Fatal` or `command not found`.
5. Products › Global pricing settings › Exchange Rate: fetched today.

**Every day:** Zoho Books Sync page free of errors (once Zoho is on); the digest arrives.

**Every month (after the 2nd):** Settings › Backups shows the test-restore **OK** and not older
than 35 days (the digest warns otherwise).

**Twice a year:** if you shift cron hours for summer time, do it in March and November.

**Once a quarter:** cPanel › **Metrics › Raw Access** — download the log of every domain on the
account and search for the project folder name and `.env`; anything answered **200** that is not
your own visit must be looked into. Requests answered 403/404/406 are blocked scanners and
harmless.

---

## 14. Updating a live site (every new release)

1. **Before:** in the app, Settings › Backups shows last night's backup **OK** (or run
   `backup_db.sh` once with a one-time cron job, section 11.5). Do the update outside working
   hours.
2. cPanel › **Git Version Control** › **Manage** › **Pull or Deploy** › **Update from Remote**.
   It must finish without an error.
3. **If it fails with "Your local changes … would be overwritten"** (a file from Git was edited by
   hand on the server — on 13 Nov 2026 this happened with the two hand-made `.htaccess` lock files):
   1. Note the file names in the error.
   2. For each: in File Manager rename it (e.g. `.htaccess` → `.htaccess.manual`) or undo the hand
      edit, so the file is exactly as Git left it, or gone.
   3. **Update from Remote** again — it must finish.
   4. Check the files came back from Git (for the lock: `<project>/.htaccess` = `Require all
      denied`; `<app>/public_html/.htaccess` starts with `Require all granted`).
   5. Repeat the lock tests (7.5). Then delete the `.manual` copies.
4. **Database:** phpMyAdmin › `<cpuser>_ops` › **SQL** › paste all of
   `docs/checks/check_all_migrations.sql` › **Go**. Every new migration has its own row.
   - every row **already done** (or the single row "(none yet) … nothing to run") → nothing to run;
   - a row **NEEDED** (or **PARTLY done**) → open that file from `docs/migrations/`, paste it into
     the SQL tab, **Go**. Several → **in date order** (the file names sort in the right order).
   - **#1060 Duplicate column name** on a file marked `PLAIN ALTER (owner D-371)` means that column
     is already there — harmless; carry on with the next statement or file.
   - phpMyAdmin refuses `PREPARE` with information_schema (**#1044**); migrations are written
     without it (rule D-371). If a file ever stops with #1044, stop and ask the developer.
   - Run the check again until **every** row says **already done**.
5. **Read the release notes** (the README "Follow-up" section of that date, and GO_LIVE_CHECKLIST
   section H) for settings to check after the update.
6. **Permissions after the pull:** `<app>/scripts/*.sh` still **0700** (set them again if a pull
   reset them — backup job 8 failing with "Permission denied" is the sign). `app/.env` still
   **0600**.
7. Open `<site>/login`, log in, open an order. Next morning do the section 13 checks.

Never: edit Git files on the server, upload files with FTP over a Git checkout, run
`zoho_cleanup_test_records.php --apply` without looking at its dry run first (it is a dry run
unless `--apply` is given), or run a migration whose row already says "already done" (most are
safe twice, but there is no need).

---

## 15. Optional hardening (recommended, not blocking)

- **Move the project outside `public_html`** (section 1, recommended layout). It needs: the new
  Git path, the subdomain's document root changed to the new `<app>/public_html`,
  `STORAGE_BASE_PATH` changed in `.env`, the stored file paths in the database changed the same
  way (the section 5.4 `UPDATE`s with the old path in place of `__STORAGE_BASE_PATH__`, plus any
  table holding absolute paths — check with the developer first), and every cron command
  changed. Plan it with the developer; do it outside working hours after a backup.
- **Off-site copy of the backups:** when a provider is chosen, install `rclone`, configure an
  encrypted remote and set `BACKUP_OFFSITE_TARGET` (README "Backups"). The key never goes with it.
- **Change the database password** if it was ever shown outside cPanel: cPanel › MySQL Databases
  › Current Users › Change Password, then the same value in `app/.env` `DB_PASSWORD`.
- **Before go-live with Zoho:** remove every `TEST-` record from Zoho Books
  (`zoho_cleanup_test_records.php`, dry run first) and log each removal in
  `docs/zoho/zoho-change-log.csv`.

---

## 16. Troubleshooting

| You see | Cause | Fix |
|---|---|---|
| Cron log / e-mail: `php: command not found`, or an old PHP version | cron's `php` is not PHP 8.1+ | use `/usr/local/bin/php` in the command |
| Cron e-mail: `No such file or directory` for `…/logs/….log` | `<home>/logs` missing | create the folder |
| Backup job: `Permission denied` | scripts not executable (or reset by a pull) | 11.4 again |
| Backup log: `BACKUP_DIR is not set` / key file not found | `.env` lines missing or path typed wrong | 11.3; check the exact path in File Manager |
| Backup log: `BACKUP_DIR … is inside …` | backup folder inside the app's `public_html`, `app/` or storage | move it (section 1) |
| Test-restore: access denied | the user lost its rights on the test database | section 4 step 5 again |
| phpMyAdmin **#1044 Access denied … information_schema** | a statement with `PREPARE` | migrations are written without it (D-371); ask the developer |
| phpMyAdmin **#1060 Duplicate column name** | the column already exists | harmless on `PLAIN ALTER` files |
| Browser: *Forbidden … Server unable to read htaccess file* on a backup/key folder | folder is 0700 | expected and safe |
| Browser: *Not Acceptable … Mod_Security* on `.env` | Bluehost's firewall blocked it | expected and safe |
| All your sites suddenly slow after testing many addresses | the firewall is slowing your own connection | wait 15–60 minutes or use mobile data |
| 504 / very slow pages on every site of the account | Bluehost server problem | wait; contact Bluehost if it lasts |
| Git **Update from Remote**: "local changes would be overwritten" | a Git file was edited on the server | section 14 step 3 |
| No login-code e-mail | SMTP wrong in `.env` | fix SMTP; Settings › E-mail › send a test e-mail |
| Staff signed out after ~24 minutes | `storage/sessions` missing or not writable | section 7 step 3 |
| Signatures / seals blank on PDFs | storage-path `UPDATE`s not run | section 5 step 4 |
