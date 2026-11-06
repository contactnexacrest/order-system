# 32 — Backups and restore

**Owner decisions D-260 (6 Nov 2026).** Every night the server makes an **extra copy** of the database and of every order document, and keeps the copies for **10 years**. The backup only **reads** the live system: it never changes, moves or deletes a live record or file, and it never overwrites an older backup.

## What is copied, and how long it is kept

| What | When | Kept |
|---|---|---|
| The whole database (orders, clients, money, users, settings …) | every night, 02:00 IST | 10 years. Copies older than 120 days are thinned to the first copy of each month |
| Every order document (generated PDFs/Word files and every uploaded file) | every night, 02:30 IST | 10 years. A file that has not changed is stored **once**, however many nights it is backed up |
| A full archive of all documents | the first night of each month | 10 years |
| A test-restore that proves the newest copy really opens | the 1st of each month, 04:00 IST | — (result shown in Admin › Backups) |

- The rule is set in `app/.env`: `BACKUP_KEEP_YEARS=10` and `BACKUP_THIN_AFTER_DAYS=120` (0 keeps every nightly database copy).
- Copies are **encrypted** with a key file only the company holds. **Keep a copy of the key away from the server** (for example in the MD's safe or password manager): without it the copies cannot be opened.
- **Off-site copy:** not chosen yet, so for now the copies stay on the server. When the owner chooses a provider, the administrator sets `BACKUP_OFFSITE_TARGET` and the copies are sent there automatically (copy only — nothing is ever deleted there).

## Checking that backups run (MD / Admin)

1. Open **Admin › Backups**. Each job shows **OK**, **Too old**, **Failed**, **No run yet** or **Not set up**, with the time of the last run, the size, whether it is encrypted and whether the off-site copy went.
2. If a backup is late (more than 26 hours), failed, is not encrypted, or the monthly test-restore is late or failed, the **daily digest e-mail** of everyone who manages company settings says **BACKUP WARNING** and lists the problem.
3. Ask the server administrator to check the log of that job (`backup_db.log`, `backup_storage.log`, `backup_test_restore.log`).

The page only shows what the jobs report. Nobody can start, change or delete a backup from the app.

## Restoring (server administrator, on the MD's instruction)

Restoring is **never automatic**. It replaces live data with the copy, so decide first **what** to restore and **from which date**, and write it in the audit note.

### A single document

1. Find the order's file in a snapshot: `BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/` keeps the same folders as the live storage.
2. Copy that one file back to its place under `STORAGE_BASE_PATH` (same path). Nothing else changes.
3. Most documents never need this: the app keeps every revision of a document (an old revision is stamped SUPERSEDED and still opens from the order's document history — SOP 13).

### The whole database (for example after a server loss)

1. Put the app in maintenance (stop the web server or the Node service) so nobody types while you restore.
2. Pick the copy: `BACKUP_DIR/db/nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz.enc`.
3. Check it: `cd BACKUP_DIR/db && sha256sum -c nexacrest_db_….sql.gz.enc.sha256`
4. Take a copy of the current database first (`scripts/backup_db.sh`), so the restore itself can be undone.
5. Decrypt and load:
   ```
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in nexacrest_db_….sql.gz.enc -pass file:/path/to/key | gunzip | mysql -u <user> -p <database>
   ```
6. Restore the documents from the snapshot of the **same night** (next section), so the files match the records.
7. Start the app again, log in as MD and open a few orders and their documents.

### All documents

1. Stop the app as above.
2. Copy the snapshot of the chosen night back: `rsync -a BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/ STORAGE_BASE_PATH/`
   (or, from a monthly archive: check its `.sha256`, decrypt with the same `openssl … -d` command, then `tar -xzf - -C STORAGE_BASE_PATH`).
3. Start the app and open a few documents.

## Set-up (server administrator, once)

The exact commands are in the README, section **Backups**: create `BACKUP_DIR` and the key file, add the `BACKUP_…` lines to `app/.env`, add the three cron lines, run the two nightly scripts once by hand and check **Admin › Backups**. The scripts refuse to run if `BACKUP_DIR` is inside the live storage or the web root.
