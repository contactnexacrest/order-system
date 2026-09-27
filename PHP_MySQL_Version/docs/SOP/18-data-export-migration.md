# Data Export / Migration

## What this chapter covers

A Super Admin (or a role granted the `data_export_run` permission) can
download a real, live snapshot of the entire application database from
**Admin → Data Export / Migration** — as two separate `.sql` files — so a
fresh reinstall never loses years of historical records.

## Why this exists

A fresh reinstall happens for real reasons — a new server, a major code
update, a database structure change — and each time, the alternative to
this feature is hoping nothing was missed while hand-copying records
between two live databases. That's the scenario this closes: a business
that has been trading through this system for years must be able to
reinstall it without losing a single client, order, document, or
financial record.

## The two files, and why they're separate

- **Structure snapshot** (`nexacrest-schema-*.sql`) — every table's exact
  columns, types, and indexes, generated with `mysqldump --no-data`. No
  rows are in it at all.
- **Full data export** (`nexacrest-data-*.sql`) — every row in every
  table, generated with `mysqldump --no-create-info --complete-insert
  --single-transaction`. No structure is in it at all.

They're kept apart on purpose. After a reinstall, the new code's own
`docs/schema.sql` already creates the current, up-to-date structure —
including any columns or tables added since this export was taken. The
structure file isn't meant to be run against that fresh install; it's a
**reference** an operator (or Claude, when asked to help with a
migration) diffs against the new `docs/schema.sql` first, to confirm
nothing the old data depends on — a column's name, type, or size — was
renamed, dropped, or narrowed. Only *additive* schema changes (new
columns/tables) are safe to assume without checking. The data file is
what actually gets imported.

## How to restore into a fresh installation

1. Set up the new installation normally — new code, then run its own
   `docs/schema.sql` to create empty, current-version tables. Do **not**
   import the structure file over this.
2. If the new code's schema has changed since the structure file was
   taken, confirm every change is additive (see above). If a column the
   old data relies on was removed, renamed, or shrunk, that has to be
   reconciled by hand before importing — this is exactly the situation
   the structure file exists to catch.
3. Import the data file into the new installation:
   ```
   mysql -u <user> -p <database> < nexacrest-data-YYYYMMDD-HHMMSS.sql
   ```
   Every historical record — clients, orders, documents, financial
   entries, users — comes back exactly as it was at export time.

## Who can use it, and what gets logged

Gated on the `data_export_run` permission at the route level, the same
way every other sensitive admin action in this app is gated — a Super
Admin always has it via the unconditional bypass; anyone else needs it
explicitly granted through Roles & Permissions. It's deliberately **not**
folded into `manage_company_settings`: the data file can expose every
record in the system, including staff login password hashes, so who
holds this permission is a decision worth making on its own, not a side
effect of an unrelated grant.

Every download — structure or data — writes a `DATA_EXPORT_SCHEMA` or
`DATA_EXPORT_DATA` row to the audit log (who, when, which filename), the
same as any other irreversible or sensitive action tracked in this
system.

## Handling the downloaded files

Both files are real dumps of live data, taken at the moment of download —
treat them accordingly:

- Store them somewhere access-controlled, never a shared or public
  location.
- Delete them once a migration is confirmed successful — there's no
  reason to keep a stale full-data export sitting around indefinitely.
- The data file in particular should never be emailed or uploaded to a
  third-party service; move it the same way you'd move a database
  backup.
