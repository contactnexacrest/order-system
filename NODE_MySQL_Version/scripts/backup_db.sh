#!/bin/bash
set -euo pipefail

# Daily database backup for the NexaCrest Node/MySQL deployment.
#
# This is documentation to fill in and install on the real server, not a
# script that ships pre-wired with real credentials (those never belong in
# a delivered codebase). Copy this file to the server (or edit it in place
# after extracting the deployment package), fill in the four variables
# below, chmod it, and add it to cron/systemd as described in the
# deployment guide's "Database backups" section.

DB_NAME="nexacrest"                        # your DB_DATABASE from .env
DB_USER="nexacrest_app"                    # your DB_USERNAME from .env
DB_PASS="REPLACE_WITH_REAL_PASSWORD"       # your DB_PASSWORD from .env — or read from a chmod-600 file outside this script
BACKUP_DIR="/var/backups/nexacrest"        # outside any web-served directory — never web-reachable
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
FILE="$BACKUP_DIR/nexacrest_${STAMP}.sql.gz"

mysqldump --single-transaction --quick --routines --triggers \
  -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$FILE"

# Retention — delete anything older than RETENTION_DAYS, keep everything newer.
find "$BACKUP_DIR" -name 'nexacrest_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Backup complete: $FILE ($(du -h "$FILE" | cut -f1))"

# --- Notes ---
# - --single-transaction avoids table locks on the live InnoDB tables while
#   the dump runs (this schema is 100% InnoDB) — safe to run at 2am while
#   the app is still reachable.
# - Store backups outside any directory Nginx/Apache serves — a stray
#   database dump sitting in a web root is a real incident waiting to
#   happen, not a hypothetical one.
# - Restoring: gunzip < nexacrest_YYYYMMDD_HHMMSS.sql.gz | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"
#   Test this at least once against a scratch database after first setting
#   this up — an untested backup is not a backup.
# - If cron/systemd runs with a smaller $PATH than your interactive shell
#   and can't find mysqldump, use its full path (`which mysqldump` first).
# - A managed-MySQL host (RDS, Cloud SQL, a managed VPS provider's own DB
#   add-on) usually offers its own automated-snapshot feature — this script
#   is the finer-grained, app-aware daily backup (30-day retention, DB only,
#   no manual step) and is complementary to that, not a replacement for it.
