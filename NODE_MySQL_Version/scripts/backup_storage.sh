#!/bin/bash
set -euo pipefail

# Daily storage/ backup for the NexaCrest Node/MySQL deployment.
#
# A database backup alone is not a backup of this application. Every
# generated PDF/DOCX and every received file (dispute evidence, amendment
# signed copies, buyer PO copies, supplier PO acknowledgments, product
# images, ...) lives on disk under STORAGE_BASE_PATH, outside any
# web-served directory — file_store only holds each one's *path* and
# metadata. Restoring the database without also restoring storage/ leaves
# every file_store row pointing at a file that no longer exists: every
# "Download PDF" link 404s, the "Download Full Dossier (ZIP)" button on an
# order page silently skips every missing file, and a document that was
# ever draft/final-watermark-swapped can never be regenerated to look the
# way it did when a reviewer actually approved it — that exact historical
# PDF is gone, not reconstructible from data alone.
#
# This is documentation to fill in and install on the real server, same
# convention as backup_db.sh — copy this file to the server, fill in the
# two variables below, chmod it, and add it to cron/systemd as described
# in the deployment guide's "Database backups" section (this job belongs
# right next to that one, offset by 30 minutes so they don't compete for
# I/O).

STORAGE_DIR="/opt/nexacrest_node/storage"   # your STORAGE_BASE_PATH from .env
BACKUP_DIR="/var/backups/nexacrest_storage" # outside any web-served directory — never web-reachable
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
FILE="$BACKUP_DIR/nexacrest_storage_${STAMP}.tar.gz"

tar -czf "$FILE" -C "$(dirname "$STORAGE_DIR")" "$(basename "$STORAGE_DIR")"

# Retention — delete anything older than RETENTION_DAYS, keep everything newer.
find "$BACKUP_DIR" -name 'nexacrest_storage_*.tar.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Storage backup complete: $FILE ($(du -h "$FILE" | cut -f1))"

# --- Notes ---
# - A full tar of the whole tree is the simplest correct approach for a
#   cron job that has to run unattended — `rsync -a --delete` to a second
#   location is a reasonable alternative if storage/ grows large enough
#   that a nightly full tarball becomes slow or expensive to keep 30 days
#   of, but loses the point-in-time-snapshot property tar gives you for
#   free (an rsync --delete mirror only has the *current* state, not
#   yesterday's).
# - The DB backup and this storage backup are only consistent with each
#   other if taken close together — schedule both within the same
#   maintenance window (e.g. 2:00 and 2:30) to keep the drift small, or
#   run both back-to-back inside one script instead of two separate cron
#   entries for a stronger guarantee.
# - Restoring: tar -xzf nexacrest_storage_YYYYMMDD_HHMMSS.tar.gz -C "$(dirname "$STORAGE_DIR")"
#   restores the whole tree back to STORAGE_DIR. Test this at least once
#   against a scratch location — verify a handful of file_store.server_path
#   values from a restored DB backup taken the same night actually resolve
#   to real files after extracting.
# - A managed object-storage backend, if storage/ is ever moved to one
#   (S3, GCS, ...) instead of local disk, would have its own
#   versioning/replication story and this script would no longer apply —
#   it's specifically for the local-disk STORAGE_BASE_PATH this app ships
#   with today.
