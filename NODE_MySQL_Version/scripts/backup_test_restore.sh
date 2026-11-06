#!/bin/bash
# Monthly test-restore (owner decisions D-260). Cron: 0 4 1 * * (IST).
# Proves the newest database copy really restores: checksum, decrypt, load into a SEPARATE scratch database
# (BACKUP_RESTORE_TEST_DB, default <DB_DATABASE>_restore_test), count tables, then drop the scratch database.
# Also checks the newest monthly documents archive opens. Never touches the live database or live files.
source "$(dirname "${BASH_SOURCE[0]}")/backup_lib.sh"
load_config
guard_backup_dir

LIVE_DB="$(env_get DB_DATABASE '')"
SCRATCH="$(env_get BACKUP_RESTORE_TEST_DB "${LIVE_DB}_restore_test")"
[ -n "$LIVE_DB" ] || fail "DB_DATABASE is not set"
[ "$SCRATCH" != "$LIVE_DB" ] || { write_status restore_test failed "BACKUP_RESTORE_TEST_DB is the live database — refused"; fail "scratch database must not be the live database"; }
[[ "$SCRATCH" =~ ^[A-Za-z0-9_]+$ ]] || fail "BACKUP_RESTORE_TEST_DB may only contain letters, digits and _"

LATEST="$(ls -1 "$BACKUP_DIR/db" 2>/dev/null | grep -E '^nexacrest_db_.*\.sql\.gz(\.enc)?$' | sort | tail -n 1 || true)"
[ -n "$LATEST" ] || { write_status restore_test failed "No database copy found to test"; fail "no database copy"; }
(cd "$BACKUP_DIR/db" && sha256sum -c --quiet "$LATEST.sha256") || { write_status restore_test failed "Checksum mismatch on $LATEST"; fail "checksum mismatch"; }

OPTS="$(mysql_options_file)"
TMP="$(mktemp)"
# the scratch database is always dropped again, then the temporary files removed (in that order)
trap '"${MYSQL:-mysql}" --defaults-extra-file="$OPTS" -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" >/dev/null 2>&1 || true; rm -f "$OPTS" "$TMP"' EXIT
if [[ "$LATEST" == *.enc ]]; then
  [ -n "$BACKUP_ENCRYPTION_KEY_FILE" ] || fail "the copy is encrypted but BACKUP_ENCRYPTION_KEY_FILE is not set"
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$BACKUP_DIR/db/$LATEST" -pass "file:$BACKUP_ENCRYPTION_KEY_FILE" | gunzip > "$TMP"
else
  gunzip -c "$BACKUP_DIR/db/$LATEST" > "$TMP"
fi
M="${MYSQL:-mysql}"
"$M" --defaults-extra-file="$OPTS" -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
if ! "$M" --defaults-extra-file="$OPTS" "$SCRATCH" < "$TMP"; then
  write_status restore_test failed "Loading $LATEST into the scratch database failed"; fail "load failed"
fi
TABLES="$("$M" --defaults-extra-file="$OPTS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$SCRATCH'")"
LIVE_TABLES="$("$M" --defaults-extra-file="$OPTS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$LIVE_DB'")"
ORDERS="$("$M" --defaults-extra-file="$OPTS" -N -e "SELECT COUNT(*) FROM \`$SCRATCH\`.orders" 2>/dev/null || echo '?')"
[ "$TABLES" -gt 0 ] || { write_status restore_test failed "$LATEST restored no tables"; fail "no tables restored"; }

ARCH_NOTE="no documents archive yet"
ARCH="$(ls -1 "$BACKUP_DIR/storage/archives" 2>/dev/null | grep -E '^nexacrest_files_full_.*\.tar\.gz(\.enc)?$' | sort | tail -n 1 || true)"
if [ -n "$ARCH" ]; then
  (cd "$BACKUP_DIR/storage/archives" && sha256sum -c --quiet "$ARCH.sha256") || { write_status restore_test failed "Checksum mismatch on $ARCH"; fail "archive checksum mismatch"; }
  if [[ "$ARCH" == *.enc ]]; then
    N="$(openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$BACKUP_DIR/storage/archives/$ARCH" -pass "file:$BACKUP_ENCRYPTION_KEY_FILE" | tar -tzf - | wc -l)"
  else
    N="$(tar -tzf "$BACKUP_DIR/storage/archives/$ARCH" | wc -l)"
  fi
  ARCH_NOTE="documents archive $ARCH opens ($N entries)"
fi
write_status restore_test ok "$LATEST restored into $SCRATCH: $TABLES tables (live has $LIVE_TABLES), $ORDERS orders; $ARCH_NOTE. Scratch database dropped." "$LATEST" "$(stat -c %s "$BACKUP_DIR/db/$LATEST")"
log "Test-restore OK: $TABLES tables, $ORDERS orders; $ARCH_NOTE"
