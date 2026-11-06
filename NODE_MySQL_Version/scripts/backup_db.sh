#!/bin/bash
# Nightly database backup (owner decisions D-260). Cron: 0 2 * * * (IST) — see README "Backups".
# Reads only: mysqldump --single-transaction takes a consistent read-only snapshot; staff keep working.
# Writes only inside BACKUP_DIR/db: nexacrest_db_YYYY-MM-DD_HHMMSS.sql.gz[.enc] + .sha256.
source "$(dirname "${BASH_SOURCE[0]}")/backup_lib.sh"
load_config
guard_backup_dir

DB_NAME="$(env_get DB_DATABASE '')"
[ -n "$DB_NAME" ] || { write_status db failed "DB_DATABASE is not set in app/.env"; fail "DB_DATABASE is not set"; }
STAMP="$(date +%H%M%S)"
FILE="$BACKUP_DIR/db/nexacrest_db_${TODAY}_${STAMP}.sql.gz"
[ ! -e "$FILE" ] && [ ! -e "$FILE.enc" ] || fail "$FILE already exists — refusing to overwrite a backup."

OPTS="$(mysql_options_file)"
trap 'rm -f "$OPTS" "$FILE.partial"' EXIT
if ! "${MYSQLDUMP:-mysqldump}" --defaults-extra-file="$OPTS" --single-transaction --quick --routines --triggers --no-tablespaces "$DB_NAME" | gzip > "$FILE.partial"; then
  write_status db failed "mysqldump failed — no new copy was made (older copies are untouched)"
  fail "mysqldump failed"
fi
mv "$FILE.partial" "$FILE"
ENCRYPTED=no
if [ -n "$BACKUP_ENCRYPTION_KEY_FILE" ]; then encrypt_file "$FILE"; FILE="$FILE.enc"; ENCRYPTED=yes; fi
write_checksum "$FILE"
SIZE="$(stat -c %s "$FILE")"

apply_retention "$BACKUP_DIR/db" '^nexacrest_db_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz(\.enc)?$' 1
OFFSITE="$(offsite_copy "$BACKUP_DIR/db" db)"
MSG="Database copy made"; [ "$ENCRYPTED" = yes ] || MSG="$MSG (NOT encrypted: BACKUP_ENCRYPTION_KEY_FILE is not set)"
write_status db ok "$MSG" "$(basename "$FILE")" "$SIZE" "$ENCRYPTED" "$OFFSITE"
log "$MSG: $FILE ($SIZE bytes); off-site: $OFFSITE"
