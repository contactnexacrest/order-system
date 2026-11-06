#!/bin/bash
# Nightly documents backup (owner decisions D-260). Cron: 30 2 * * * (IST) — see README "Backups".
# Reads only from STORAGE_BASE_PATH (every generated PDF/DOCX and every uploaded file).
#  - Daily snapshot: BACKUP_DIR/storage/snapshots/nexacrest_files_YYYY-MM-DD/ — rsync --link-dest to the
#    previous snapshot, so a file that has not changed is stored ONCE (hard link, no extra space).
#  - Monthly full archive (first run of each month): BACKUP_DIR/storage/archives/nexacrest_files_full_YYYY-MM-DD.tar.gz[.enc]
#    + .sha256 — the encrypted copy that goes off-site.
# Never writes to STORAGE_BASE_PATH; never uses --delete against anything but a brand-new snapshot folder.
source "$(dirname "${BASH_SOURCE[0]}")/backup_lib.sh"
load_config
guard_backup_dir

SRC="$(abs_path "$STORAGE_BASE_PATH")"
[ -d "$SRC" ] || { write_status storage failed "STORAGE_BASE_PATH ($SRC) does not exist"; fail "STORAGE_BASE_PATH ($SRC) does not exist"; }
SNAPS="$BACKUP_DIR/storage/snapshots"
DEST="$SNAPS/nexacrest_files_${TODAY}"
if [ -e "$DEST" ]; then
  log "Today's snapshot already exists ($DEST) — not overwritten."
else
  PREV="$(ls -1d "$SNAPS"/nexacrest_files_[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] 2>/dev/null | sort | tail -n 1 || true)"
  LINK=(); [ -n "$PREV" ] && LINK=(--link-dest="$PREV")
  if ! rsync -a "${LINK[@]}" "$SRC"/ "$DEST.partial"/; then
    rm -rf -- "$DEST.partial"
    write_status storage failed "rsync of the documents failed — no new snapshot (older ones are untouched)"
    fail "rsync failed"
  fi
  mv "$DEST.partial" "$DEST"
fi

ARCH_DIR="$BACKUP_DIR/storage/archives"
ENCRYPTED=no; ARCH_NOTE=""
if ! ls -1 "$ARCH_DIR" 2>/dev/null | grep -qE "^nexacrest_files_full_${TODAY:0:7}-[0-9]{2}\.tar\.gz(\.enc)?$"; then
  ARCH="$ARCH_DIR/nexacrest_files_full_${TODAY}.tar.gz"
  tar -czf "$ARCH" -C "$DEST" .
  if [ -n "$BACKUP_ENCRYPTION_KEY_FILE" ]; then encrypt_file "$ARCH"; ARCH="$ARCH.enc"; ENCRYPTED=yes; fi
  write_checksum "$ARCH"
  ARCH_NOTE="; monthly full archive $(basename "$ARCH")"
fi

apply_retention "$SNAPS" '^nexacrest_files_[0-9]{4}-[0-9]{2}-[0-9]{2}$' 1
apply_retention "$ARCH_DIR" '^nexacrest_files_full_[0-9]{4}-[0-9]{2}-[0-9]{2}\.tar\.gz(\.enc)?$' 0
OFFSITE="$(offsite_copy "$ARCH_DIR" storage)"
FILES="$(find "$DEST" -type f | wc -l)"
SIZE="$(du -sb "$DEST" | cut -f1)"
write_status storage ok "Documents snapshot made ($FILES files)$ARCH_NOTE" "$(basename "$DEST")" "$SIZE" "$ENCRYPTED" "$OFFSITE"
log "Documents snapshot: $DEST ($FILES files)$ARCH_NOTE; off-site: $OFFSITE"
