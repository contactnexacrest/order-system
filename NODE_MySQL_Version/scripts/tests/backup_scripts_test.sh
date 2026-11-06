#!/bin/bash
# Self-test for the backup scripts (D-260). Runs entirely in a temporary folder with fake data:
# never touches the real database, the real storage or a real backup folder.
# Usage: bash scripts/tests/backup_scripts_test.sh   (exit 0 = all passed). Called by PHPUnit / Jest.
set -uo pipefail
# Ignore anything the caller exported (PHPUnit / Jest load the real app/.env into the environment): this test
# must only ever see its own fake .env below.
unset DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD STORAGE_BASE_PATH BACKUP_DIR BACKUP_KEEP_YEARS BACKUP_THIN_AFTER_DAYS BACKUP_OFFSITE_TARGET BACKUP_ENCRYPTION_KEY_FILE BACKUP_RESTORE_TEST_DB BACKUP_TODAY
SCRIPTS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
PASS=0; FAILS=0
ok()   { echo "PASS $1"; PASS=$((PASS+1)); }
bad()  { echo "FAIL $1"; FAILS=$((FAILS+1)); }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
tree_sum() { (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum; find . -printf '%p %T@\n' | sort) | sha256sum | cut -d' ' -f1; }

mkdir -p "$T/live/orders/1" "$T/live/orders/2" "$T/bk"
echo "PI Rev.0 content" > "$T/live/orders/1/pi_rev0.pdf"
head -c 200000 /dev/urandom > "$T/live/orders/1/ci.pdf"
echo "signed PO" > "$T/live/orders/2/po.pdf"
printf 'k3y-for-tests-only-%s' "$RANDOM" > "$T/key"; chmod 600 "$T/key"
cat > "$T/fake_mysqldump" <<'F'
#!/bin/bash
echo "-- fake dump"; echo "CREATE TABLE orders (id INT);"; echo "INSERT INTO orders VALUES (1),(2);"
F
chmod +x "$T/fake_mysqldump"
cat > "$T/env" <<ENV
DB_DATABASE=nexacrest_live
DB_USERNAME=tester
DB_PASSWORD="p@ss # not a comment"
STORAGE_BASE_PATH=$T/live
BACKUP_DIR=$T/bk
BACKUP_KEEP_YEARS=10
BACKUP_THIN_AFTER_DAYS=120
BACKUP_ENCRYPTION_KEY_FILE=$T/key
BACKUP_OFFSITE_TARGET=
ENV
export NEXACREST_ENV_FILE="$T/env" MYSQLDUMP="$T/fake_mysqldump"
LIVE_BEFORE="$(tree_sum "$T/live")"

# 1. Database copy: encrypted, checksummed, decryptable, never overwritten
BACKUP_TODAY=2026-11-06 bash "$SCRIPTS/backup_db.sh" > "$T/out_db" 2>&1
DBF="$(ls "$T/bk/db" | grep -E '^nexacrest_db_2026-11-06_[0-9]{6}\.sql\.gz\.enc$' | head -n1)"
check "db copy is encrypted (.enc) and no plain copy is left" '[ -n "$DBF" ] && ! ls "$T/bk/db" | grep -qE "\.sql\.gz$"'
check "db copy has a valid sha256" '(cd "$T/bk/db" && sha256sum -c --quiet "$DBF.sha256")'
check "db copy decrypts to the dump" 'DUMP="$(openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$T/bk/db/$DBF" -pass "file:$T/key" | gunzip)" && printf "%s\n" "$DUMP" | grep -q "INSERT INTO orders"'
check "db status file says ok + encrypted" 'grep -q "\"result\":\"ok\"" "$T/bk/status/db.json" && grep -q "\"encrypted\":\"yes\"" "$T/bk/status/db.json"'
check "password with # read from .env (not cut as a comment)" 'grep -q "p@ss # not a comment" <(source "$SCRIPTS/backup_lib.sh"; env_get DB_PASSWORD)'

# 2. Documents: snapshot, dedup (hard links), monthly archive, live untouched
BACKUP_TODAY=2026-11-05 bash "$SCRIPTS/backup_storage.sh" > "$T/out_s1" 2>&1
BACKUP_TODAY=2026-11-06 bash "$SCRIPTS/backup_storage.sh" > "$T/out_s2" 2>&1
S1="$T/bk/storage/snapshots/nexacrest_files_2026-11-05"; S2="$T/bk/storage/snapshots/nexacrest_files_2026-11-06"
check "two daily snapshots exist" '[ -f "$S1/orders/1/ci.pdf" ] && [ -f "$S2/orders/1/ci.pdf" ]'
check "unchanged file stored once (same inode, link count 2)" '[ "$(stat -c %i "$S1/orders/1/ci.pdf")" = "$(stat -c %i "$S2/orders/1/ci.pdf")" ] && [ "$(stat -c %h "$S2/orders/1/ci.pdf")" = 2 ]'
check "second snapshot adds no space for unchanged files" '[ "$(du -sb "$S1" "$S2" | awk "{s+=\$1} END{print s}")" -lt "$(( $(du -sb "$S1" | cut -f1) + 20000 ))" ] || [ "$(du -sbc "$S1" "$S2" | tail -n1 | cut -f1)" -lt "$(( $(du -sb "$S1" | cut -f1) * 2 ))" ]'
check "one monthly archive for November, encrypted + checksummed" '[ "$(ls "$T/bk/storage/archives" | grep -cE "^nexacrest_files_full_2026-11-[0-9]{2}\.tar\.gz\.enc$")" = 1 ] && (cd "$T/bk/storage/archives" && sha256sum -c --quiet *.sha256)'
# Read the whole listing first: "grep -q" on a pipe stops early and tar then reports "stdout: write error" on some systems (CI).
check "archive decrypts and lists the documents" 'LIST="$(openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$(ls "$T"/bk/storage/archives/*.enc)" -pass "file:$T/key" | tar -tzf -)" && printf "%s\n" "$LIST" | grep -q "orders/2/po.pdf"'
check "live documents untouched (content + timestamps identical)" '[ "$(tree_sum "$T/live")" = "$LIVE_BEFORE" ]'
echo "changed" >> "$T/live/orders/2/po.pdf"; LIVE_BEFORE="$(tree_sum "$T/live")"
BACKUP_TODAY=2026-11-07 bash "$SCRIPTS/backup_storage.sh" > "$T/out_s3" 2>&1
S3="$T/bk/storage/snapshots/nexacrest_files_2026-11-07"
check "a changed file gets a new copy, the old snapshot keeps the old version" 'grep -q changed "$S3/orders/2/po.pdf" && ! grep -q changed "$S2/orders/2/po.pdf"'
check "re-running the same day never overwrites the snapshot" 'BACKUP_TODAY=2026-11-07 bash "$SCRIPTS/backup_storage.sh" >/dev/null 2>&1; [ "$(ls "$T/bk/storage/snapshots" | grep -c 2026-11-07)" = 1 ]'
check "live still untouched after three runs" '[ "$(tree_sum "$T/live")" = "$LIVE_BEFORE" ]'

# 3. Guard: BACKUP_DIR inside the live storage (or containing it) is refused and nothing is written there
cp "$T/env" "$T/env_bad"; sed -i "s#^BACKUP_DIR=.*#BACKUP_DIR=$T/live/backups#" "$T/env_bad"
NEXACREST_ENV_FILE="$T/env_bad" BACKUP_TODAY=2026-11-08 bash "$SCRIPTS/backup_storage.sh" > "$T/out_bad" 2>&1; RC=$?
check "backup dir inside storage is refused" '[ "$RC" != 0 ] && grep -q "inside" "$T/out_bad" && [ ! -e "$T/live/backups" ]'
cp "$T/env" "$T/env_bad2"; sed -i "s#^BACKUP_DIR=.*#BACKUP_DIR=$T#" "$T/env_bad2"
NEXACREST_ENV_FILE="$T/env_bad2" bash "$SCRIPTS/backup_db.sh" > "$T/out_bad2" 2>&1; RC=$?
check "backup dir that contains the storage is refused" '[ "$RC" != 0 ] && grep -q "contains" "$T/out_bad2"'
check "live still untouched after refused runs" '[ "$(tree_sum "$T/live")" = "$LIVE_BEFORE" ]'

# 4. Retention: 10 years, thinning to the first copy of each month after 120 days, only backup names removed
R="$T/ret/db"; mkdir -p "$R"
for d in 2016-10-01 2016-11-10 2019-03-01 2019-03-02 2019-03-15 2026-06-01 2026-06-20 2026-07-02 2026-07-03 2026-09-10 2026-09-11 2026-11-05; do
  touch "$R/nexacrest_db_${d}_020000.sql.gz.enc" "$R/nexacrest_db_${d}_020000.sql.gz.enc.sha256"
done
touch "$R/notes.txt" "$R/nexacrest_db_backup_manual.sql" "$R/my_nexacrest_db_2010-01-01_020000.sql.gz.enc"
(
  export BACKUP_DIR="$T/ret" BACKUP_TODAY=2026-11-06
  source "$SCRIPTS/backup_lib.sh"; load_config
  apply_retention "$R" '^nexacrest_db_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz(\.enc)?$' 1
) > "$T/out_ret" 2>&1
has() { [ -e "$R/nexacrest_db_$1_020000.sql.gz.enc" ]; }
check "older than 10 years removed (2016-10-01) with its .sha256" '! has 2016-10-01 && [ ! -e "$R/nexacrest_db_2016-10-01_020000.sql.gz.enc.sha256" ]'
check "just inside 10 years kept (2016-11-10)" 'has 2016-11-10'
check "old month thinned to its first copy (2019-03-01 kept, 03-02 and 03-15 removed)" 'has 2019-03-01 && ! has 2019-03-02 && ! has 2019-03-15'
check "older than 120 days thinned (2026-06-01 kept, 06-20 removed; 07-02 kept, 07-03 removed)" 'has 2026-06-01 && ! has 2026-06-20 && has 2026-07-02 && ! has 2026-07-03'
check "within 120 days every copy kept (09-10, 09-11, 11-05)" 'has 2026-09-10 && has 2026-09-11 && has 2026-11-05'
check "files not named like a backup are never removed" '[ -e "$R/notes.txt" ] && [ -e "$R/nexacrest_db_backup_manual.sql" ] && [ -e "$R/my_nexacrest_db_2010-01-01_020000.sql.gz.enc" ]'
R2="$T/ret2/db"; mkdir -p "$R2"; for d in 2026-01-01 2026-01-02 2026-01-03; do touch "$R2/nexacrest_db_${d}_020000.sql.gz"; done
( export BACKUP_DIR="$T/ret2" BACKUP_TODAY=2026-11-06 BACKUP_THIN_AFTER_DAYS=0; source "$SCRIPTS/backup_lib.sh"; load_config
  apply_retention "$R2" '^nexacrest_db_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz(\.enc)?$' 1 ) > /dev/null 2>&1
check "BACKUP_THIN_AFTER_DAYS=0 keeps every nightly copy" '[ "$(ls "$R2" | wc -l)" = 3 ]'

# 5. Test-restore refuses to use the live database as its scratch database
NEXACREST_ENV_FILE="$T/env" BACKUP_RESTORE_TEST_DB=nexacrest_live bash "$SCRIPTS/backup_test_restore.sh" > "$T/out_tr" 2>&1; RC=$?
check "test-restore refuses the live database" '[ "$RC" != 0 ] && grep -q "\"result\":\"failed\"" "$T/bk/status/restore_test.json"'
DBF2="$(ls "$T/bk/db" | grep -E "\.enc$" | head -n1)"; cp "$T/bk/db/$DBF2" "$T/db_backup_copy"; echo x >> "$T/bk/db/$DBF2"
NEXACREST_ENV_FILE="$T/env" bash "$SCRIPTS/backup_test_restore.sh" > "$T/out_tr2" 2>&1; RC=$?
check "test-restore detects a damaged copy (checksum mismatch)" '[ "$RC" != 0 ] && grep -q "Checksum mismatch" "$T/bk/status/restore_test.json"'

echo "RESULT $PASS passed, $FAILS failed"
[ "$FAILS" = 0 ]
