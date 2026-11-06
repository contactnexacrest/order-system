# Zoho notes

- `zoho-change-log.csv` — every change made in Zoho (Books, Mail, CRM, API
  Console …), by anyone, test or real, one row each (standing rule,
  2026-10-29).
- **API call limits (owner decision 2 Nov 2026, D-239).** Zoho limits API
  calls per organisation (about 100 a minute and a daily number by plan),
  shared by every Zoho app and integration of the company. This app keeps to
  its own share — Admin → Settings → Zoho Books: `zoho_api_daily_limit`
  (800) and `zoho_api_minute_limit` (30) — counts every call it makes, stops
  a sync at once when Zoho answers "too many requests", and shares one
  access token between all its processes. Usage is on the app's Zoho Books
  Sync page. Changing these two settings is a change in **our app**, not in
  Zoho, so it needs no row in the change log; the app's own Zoho calls on
  the live site are recorded in its Zoho Sync Log.
- **One sync at a time, and look before posting (QA batch 4, D-258, 6 Nov
  2026).** A database lock lets only one sync run at once (hourly job,
  *Sync now*, *Sync this order*, *Send to Zoho Books*); a second one stops
  with one Sync Log row. Before posting a customer payment, vendor payment,
  expense, cost entry or journal, the app looks it up in Books by its
  reference and uses the one already there, so a retry never duplicates.
- **The other divisions (D-257 (6), 5 Nov 2026).** Domestic Trade, Import &
  Distribution and Merchant Trade sync in the same hourly job as Export:
  tax invoices and receipts, import supplier, duty and clearance bills,
  payments abroad, the duty payment, the IGST credit journal, and both
  Merchant Trade legs. Each domestic order, import and deal has *Check
  against Zoho Books*. Live-tested in the TEST organisation 60093754195
  (change log Z-643 to Z-698, including go-live item 0C.2, the GST
  commission bill). The test records are still there for the owner to see
  and must be removed before go-live, each removal logged. Before Import &
  Distribution or Merchant Trade is activated, the live organisation needs
  the accounts *Imported Goods Stock*, *IGST Paid on Import*, *Merchanting
  Trade Sales* and *Merchanting Trade Purchases* (names to be confirmed by
  the CA).
- **Before go-live: remove the TEST records (checked 6 Nov 2026).** 48 rows of
  `zoho-change-log.csv` are marked "must be removed before go-live" and have no
  "removed on" date yet: Z-629–Z-641 (owner test matrix, 31 Oct), Z-648–Z-654,
  Z-656–Z-657, Z-659–Z-661, Z-663, Z-674–Z-688, Z-691–Z-693 and Z-695–Z-698
  (division sync and the GST commission bill, 5 Nov). All are in the TEST
  organisation 60093754195, not the live one. Once the owner has looked at
  them, remove them: the clean-up script (`app/cron/zoho_cleanup_test_records`,
  dry run first) finds `TEST-` contacts, invoices, bills, payments and journals;
  check the new-division records by hand too (the script does not yet know
  their new Zoho IDs — register O-22, ZOH-17). Fill in "removed on / by" for each
  row. The live organisation starts clean.
