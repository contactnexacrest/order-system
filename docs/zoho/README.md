# Zoho notes

- `zoho-change-log.csv` — every change made in Zoho (Books, Mail, CRM, API
  Console …), by anyone, test or real, one row each (standing rule,
  2026-10-29).
- **API call limits (owner decision 2 Nov 2026, D-239).** Zoho limits API
  calls per organisation (about 100 a minute and a daily number by plan),
  shared by every Zoho app and integration of the company. This app keeps to
  its own share — Settings › Zoho: `zoho_api_daily_limit`
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
- **Associate payout costs (D-264, 7 Nov 2026) — live-checked 7 Nov 2026.** Each
  paid associate payout with an exchange difference or transfer charges goes to
  Books as one journal (*Associate payout costs* against the bank; reference
  `<buyer inquiry ref>-APC-<payout id>`; notes start `TEST-` for a test buyer). Live-checked
  in the TEST organisation 60093754195 (change log Z-699 to Z-703): one journal of
  ₹2,662.50 posted with the right sides, accounts and D-212 notes; a second sync
  sent nothing; with the stored id cleared the existing journal was found by its
  reference and updated, never posted twice; the test journal was then deleted.
  **End-to-end live check 7 Nov 2026 (change log Z-704 to Z-711):** an associate
  abroad (USD 1,250.00 fixed at ₹1,04,312.50) — bill ₹1,04,312.50, payment
  ₹1,04,312.50, journal ₹2,662.50, together exactly what the bank paid out
  (₹1,05,125.00 debited + ₹1,850.00 charges); an associate in India — bill
  ₹1,04,312.50 gross, payment ₹83,449.50 net, TDS ₹20,863.00 left on the bill for
  the CA (no Zoho TDS tax chosen), journal ₹15.00 charges. Re-sync: no duplicates.
  Two wording fixes came out of it (both stacks): the payment to an associate
  abroad says "SWIFT ref" (not "UTR"), and the journal uses the buyer inquiry ref
  like the bill and payment. All test records deleted.
  Before go-live the CA
  creates the expense account and its id is entered in Associates › Commission › Commission settings and ladder.
- **Failure e-mails and menus (11 Nov 2026, D-280, D-320).** The e-mail sent when a
  sync run has failures is the "Zoho sync failures" row of Settings › E-mail (on by
  default; Testing mode does not hold it, as it goes to staff). The Zoho pages are
  Finance & accounts › Zoho sync (with the "waiting for Zoho" count beside Finance &
  accounts in the left menu) and Settings › Zoho. A supplier's bank details change
  on the supplier record only after the supplier confirmed them and staff verified
  them (D-294). Nothing was changed in Zoho for the 11 Nov build, so the change log
  has no new rows.
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
