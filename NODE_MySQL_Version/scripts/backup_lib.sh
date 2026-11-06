#!/bin/bash
# Shared helpers for the NexaCrest backup scripts (owner decisions D-260, 6 Nov 2026).
#
# Rules the owner set:
#   - A backup is only an extra copy. The live database and the live files are only READ, never changed.
#   - Keep everything BACKUP_KEEP_YEARS (default 10) years, then remove it.
#   - Database copies older than BACKUP_THIN_AFTER_DAYS (default 120) are thinned to one per month
#     (the first copy of each month). 0 = keep every nightly copy.
#   - Documents are stored once: daily snapshots hard-link unchanged files (rsync --link-dest).
#   - Where the copies go is set in app/.env (BACKUP_DIR, BACKUP_OFFSITE_TARGET, BACKUP_ENCRYPTION_KEY_FILE),
#     never in the cron line.
# This file is sourced by backup_db.sh, backup_storage.sh and backup_test_restore.sh (identical copy on
# both stacks).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${NEXACREST_ENV_FILE:-$STACK_ROOT/app/.env}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

# Reads KEY=VALUE from app/.env without executing it (no `source`): quotes stripped, comments ignored.
env_get() {
  local key="$1" default="${2:-}" line value
  if [ -n "${!key:-}" ]; then printf '%s' "${!key}"; return; fi   # an exported variable wins (tests, one-off runs)
  if [ -f "$ENV_FILE" ]; then
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" | tail -n 1 || true)"
    if [ -n "$line" ]; then
      value="$(printf '%s' "${line#*=}" | sed -e 's/^[[:space:]]*//')"
      case "$value" in
        \"*) value="${value#\"}"; value="${value%%\"*}" ;;     # "quoted" — everything inside the quotes
        \'*) value="${value#\'}"; value="${value%%\'*}" ;;     # 'quoted'
        *) value="${value%%[[:space:]]#*}"; value="$(printf '%s' "$value" | sed -e 's/[[:space:]]*$//')" ;;  # bare: drop a trailing " # comment"
      esac
      printf '%s' "$value"; return
    fi
  fi
  printf '%s' "$default"
}

# Absolute, symlink-resolved path, even when the last part does not exist yet.
abs_path() {
  local p="$1"
  if [ -e "$p" ]; then (cd "$p" 2>/dev/null && pwd -P) || realpath -m "$p"; else realpath -m "$p"; fi
}

# true when $1 is equal to or inside $2
path_within() {
  local a b; a="$(abs_path "$1")"; b="$(abs_path "$2")"
  [ "$a" = "$b" ] || [ "${a#"$b"/}" != "$a" ]
}

load_config() {
  BACKUP_DIR="$(env_get BACKUP_DIR '')"
  [ -n "$BACKUP_DIR" ] || fail "BACKUP_DIR is not set in $ENV_FILE. Set it to a folder outside the web root and outside STORAGE_BASE_PATH."
  case "$BACKUP_DIR" in /*) ;; *) fail "BACKUP_DIR must be an absolute path (got '$BACKUP_DIR')." ;; esac
  BACKUP_KEEP_YEARS="$(env_get BACKUP_KEEP_YEARS 10)"
  BACKUP_THIN_AFTER_DAYS="$(env_get BACKUP_THIN_AFTER_DAYS 120)"
  BACKUP_OFFSITE_TARGET="$(env_get BACKUP_OFFSITE_TARGET '')"
  BACKUP_ENCRYPTION_KEY_FILE="$(env_get BACKUP_ENCRYPTION_KEY_FILE '')"
  STORAGE_BASE_PATH="$(env_get STORAGE_BASE_PATH "$STACK_ROOT/storage")"
  [[ "$BACKUP_KEEP_YEARS" =~ ^[0-9]+$ ]] && [ "$BACKUP_KEEP_YEARS" -ge 1 ] || fail "BACKUP_KEEP_YEARS must be a whole number of years, 1 or more."
  [[ "$BACKUP_THIN_AFTER_DAYS" =~ ^[0-9]+$ ]] || fail "BACKUP_THIN_AFTER_DAYS must be a whole number of days (0 = keep every copy)."
  if [ -n "$BACKUP_ENCRYPTION_KEY_FILE" ] && [ ! -r "$BACKUP_ENCRYPTION_KEY_FILE" ]; then
    fail "BACKUP_ENCRYPTION_KEY_FILE '$BACKUP_ENCRYPTION_KEY_FILE' does not exist or cannot be read."
  fi
  TODAY="${BACKUP_TODAY:-$(date +%F)}"   # BACKUP_TODAY only for tests
}

# Refuses a BACKUP_DIR that is (or is inside, or contains) the live storage, the web root or the app code.
guard_backup_dir() {
  local web
  for web in "$STACK_ROOT/public_html" "$STACK_ROOT/app/public" "$STACK_ROOT/app" "$STORAGE_BASE_PATH"; do
    [ -n "$web" ] || continue
    if path_within "$BACKUP_DIR" "$web"; then fail "BACKUP_DIR ($BACKUP_DIR) is inside $web. Backups must sit outside the live files and the web root."; fi
    if path_within "$web" "$BACKUP_DIR"; then fail "BACKUP_DIR ($BACKUP_DIR) contains $web. Backups must sit outside the live files and the web root."; fi
  done
  mkdir -p "$BACKUP_DIR"/{db,storage/snapshots,storage/archives,status}
  chmod 700 "$BACKUP_DIR" 2>/dev/null || true
}

# Writes status/<job>.json for the app's Admin › Backups page and the daily health check.
write_status() {
  local job="$1" result="$2" message="$3" file="${4:-}" size="${5:-0}" encrypted="${6:-no}" offsite="${7:-not configured}"
  local tmp="$BACKUP_DIR/status/.${job}.json.tmp"
  message="$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' ')"
  file="$(printf '%s' "$file" | sed 's/\\/\\\\/g; s/"/\\"/g')"
  offsite="$(printf '%s' "$offsite" | sed 's/\\/\\\\/g; s/"/\\"/g')"
  cat > "$tmp" <<JSON
{"job":"$job","result":"$result","finished_at":"$(date '+%Y-%m-%dT%H:%M:%S%z')","message":"$message","file":"$file","size_bytes":$size,"encrypted":"$encrypted","offsite":"$offsite","keep_years":$BACKUP_KEEP_YEARS,"thin_after_days":$BACKUP_THIN_AFTER_DAYS}
JSON
  mv -f "$tmp" "$BACKUP_DIR/status/${job}.json"
}

# Encrypts $1 to $1.enc with the owner's key file (AES-256, PBKDF2) and removes the plain copy.
encrypt_file() {
  local f="$1"
  openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in "$f" -out "$f.enc" -pass "file:$BACKUP_ENCRYPTION_KEY_FILE"
  rm -f -- "$f"
}

write_checksum() { (cd "$(dirname "$1")" && sha256sum "$(basename "$1")" > "$(basename "$1").sha256"); }

# Date (YYYY-MM-DD) carried in a backup name, or empty.
name_date() { printf '%s' "$1" | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -n 1 || true; }

days_between() { echo $(( ( $(date -d "$2" +%s) - $(date -d "$1" +%s) ) / 86400 )); }

# Retention for one folder of dated backups. $1 = folder, $2 = name regex (whole name), $3 = thin (1/0).
# Only entries whose name matches the regex, directly inside the folder, are ever removed.
# A file's .sha256 companion goes with it.
apply_retention() {
  local dir="$1" regex="$2" thin="$3" keep_days entry name d age month
  keep_days=$(( BACKUP_KEEP_YEARS * 365 + BACKUP_KEEP_YEARS / 4 ))
  declare -A first_of_month=()
  [ -d "$dir" ] || return 0
  # pass 1: the earliest copy of each month is the one kept when thinning
  while IFS= read -r name; do
    [[ "$name" =~ $regex ]] || continue
    d="$(name_date "$name")"; [ -n "$d" ] || continue
    month="${d:0:7}"
    if [ -z "${first_of_month[$month]:-}" ] || [[ "$d" < "${first_of_month[$month]}" ]]; then first_of_month[$month]="$d"; fi
  done < <(ls -1 "$dir")
  # pass 2: remove what is past the rules
  while IFS= read -r name; do
    [[ "$name" =~ $regex ]] || continue
    d="$(name_date "$name")"; [ -n "$d" ] || continue
    age="$(days_between "$d" "$TODAY")"
    entry="$dir/$name"
    if [ "$age" -gt "$keep_days" ]; then
      remove_backup_entry "$entry" "older than $BACKUP_KEEP_YEARS years"
    elif [ "$thin" = 1 ] && [ "$BACKUP_THIN_AFTER_DAYS" -gt 0 ] && [ "$age" -gt "$BACKUP_THIN_AFTER_DAYS" ] && [ "$d" != "${first_of_month[${d:0:7}]}" ]; then
      remove_backup_entry "$entry" "thinned to one copy per month after $BACKUP_THIN_AFTER_DAYS days"
    fi
  done < <(ls -1 "$dir")
}

remove_backup_entry() {
  local entry="$1" why="$2"
  path_within "$entry" "$BACKUP_DIR" || fail "refusing to remove $entry: not inside BACKUP_DIR"
  [ "$(abs_path "$entry")" != "$(abs_path "$BACKUP_DIR")" ] || fail "refusing to remove BACKUP_DIR itself"
  log "Removing $(basename "$entry") ($why)"
  rm -rf -- "$entry"
  rm -f -- "$entry.sha256"
}

# Off-site copy, only when BACKUP_OFFSITE_TARGET is set. `rclone copy` only adds — it never deletes at the target.
offsite_copy() {
  local src="$1" sub="$2"
  if [ -z "$BACKUP_OFFSITE_TARGET" ]; then echo "not configured"; return 0; fi
  if ! command -v rclone >/dev/null 2>&1; then echo "failed: rclone is not installed"; return 0; fi
  if rclone copy --immutable "$src" "${BACKUP_OFFSITE_TARGET%/}/$sub" >/dev/null 2>&1; then echo "copied"; else echo "failed: rclone copy returned an error"; fi
}

# A MySQL client options file (password never on the command line). Prints its path; caller removes it.
mysql_options_file() {
  local f; f="$(mktemp)"; chmod 600 "$f"
  {
    echo "[client]"
    echo "host=$(env_get DB_HOST 127.0.0.1)"
    echo "port=$(env_get DB_PORT 3306)"
    echo "user=$(env_get DB_USERNAME '')"
    echo "password=$(env_get DB_PASSWORD '')"
  } > "$f"
  printf '%s' "$f"
}
