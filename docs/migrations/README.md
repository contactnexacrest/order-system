# Database migrations — upgrading an existing installation

A **new** installation needs none of these files: load the stack's
`docs/schema.sql` and then `docs/seed.sql` (see the stack README). Both
already contain every change listed here.

An **existing** database (the live site, or a dev copy made before a
release) is brought up to date by running the files in this folder that it
has not had yet, **in filename order**. The two stacks share one database
design, so the same files serve PHP and Node.

```bash
mysql -u <user> -p <database> < docs/migrations/2026-10-31a-client-email-unique.sql
mysql -u <user> -p <database> < docs/migrations/2026-10-31b-payment-references.sql
```

## Rules every file follows

- **MySQL-safe.** The live host runs MySQL 8, dev runs MariaDB. No
  `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS` and no `CREATE INDEX IF NOT
  EXISTS`. Column, index and foreign-key changes are guarded with an
  `information_schema` lookup and a `PREPARE`d statement instead.
- **Safe to run twice.** Every file from 2026-10-25 onward checks before it
  changes anything, so running one again does nothing. Most files say this
  in their first lines.
- **Back up first.** Take a database backup before a release's migrations.

## Latest releases — run in this order

Run each file below that the database has not had yet, in this order, then
deploy the code for both stacks. As of 30 Oct 2026 the live site still
needs **27f, 27g, 28a, 28c, 28d, 28e, 29a, 29b, 29c, 29d, 29e, 29f, 30a, 30b, 30c, 30d, 31a and 31b**.

| # | File | What it does |
|---|---|---|
| 1 | `2026-10-27a-balance-due-after-actual-advance.sql` | One-off fix: balance due = FOB − the advance actually received, for orders whose balance is not in yet |
| 2 | `2026-10-27b-payment-action-link.sql` | No-login Payment Action Link for the client (PI advance, Debit Note, CI balance) |
| 3 | `2026-10-27c-logistics-partner.sql` | Logistics Partners directory; Step 9a picks the freight forwarder from it |
| 4 | `2026-10-27d-benefits-at-settlement-and-vendor-bank.sql` | Government benefits recorded at settlement (Step 13g); supplier / logistics partner bank details |
| 5 | `2026-10-27e-vendor-bills-and-order-expenses.sql` | Supplier and logistics partner bills with payments; order expenses with receipts |
| 6 | `2026-10-27f-document-date.sql` | The Commercial Invoice can carry its own date |
| 7 | `2026-10-27g-logistics-bill-payment-kind.sql` | A logistics bill is an Advance or the Final bill |
| 8 | `2026-10-28a-view-cafin-permission.sql` | Permission "View & download CAFIN" (Admin, MD, ED, Accounts Executive, CA) |
| 9 | `2026-10-28c-payment-terms-approval.sql` | Payment-terms approval before the Quotation (permission "Approve payment terms": Admin, MD, ED) |
| 10 | `2026-10-28d-ca-view-orders.sql` | The CA role can open orders read-only |
| 11 | `2026-10-28e-vendor-bill-proforma-and-payments.sql` | Bills are a Proforma or a Tax invoice; a bill can take several payments. Existing paid bills are copied into the new payments table |
| 12 | `2026-10-29a-zoho-stage2-invoices.sql` | Zoho Books Stage 2: remembers which Books invoice each order's Commercial Invoice and Debit Note became, and when each payment was applied to it |
| 13 | `2026-10-29b-zoho-stage3-purchases.sql` | Zoho Books Stage 3: remembers each supplier's and logistics partner's Books vendor, and which bills, vendor payments and order expenses are in Books |
| 14 | `2026-10-29c-two-digit-year-references.sql` | Every new reference number uses the 2-digit year (`SC/CI/26/2910001`) — a GST invoice number may have at most 16 characters. Numbers already issued keep `2026` |
| 15 | `2026-10-29d-zoho-sync-start-date.sql` | Zoho Books go-live date setting (`zoho_books_sync_start_date`): only records dated on or after it go to Books; on the live server nothing is sent until it is set |
| 16 | `2026-10-29e-shipping-bill-and-reverse-charge.sql` | The shipping bill's number, date and customs exchange rate on the order (the CI and Debit Note go to Zoho Books at that rate), and the reverse-charge mark on transport bills |
| 17 | `2026-10-29f-zoho-costs-lc-benefits.sql` | Order costs say what they were paid from and remember their Zoho Books expense; LC orders record the money the bank paid under the LC; export benefits remember their Zoho Books journal |
| 18 | `2026-10-30a-lc-payment-firc.sql` | The LC payment's FIRC / eBRC reference and date, so it joins the INR Settlement Register like the other legs; the setting `zoho_books_restore_checked_at` (when the scheduled Zoho sync last checked the records already in Books — once a day) |
| 19 | `2026-10-30b-team-incentives.sql` | Team incentives and associates: the settings (switched **off** until management turns them on), each person's incentive role, each order's incentive and people, the payouts by incentive month, the associate register and the client's associate, the marker on the order's commission cost line, and the permissions Manage team incentives (Admin, MD), Pay incentives (Admin, MD, Accounts Executive) and View own incentives |
| 20 | `2026-10-30c-ca-view-all-orders.sql` | The CA / Chartered Accountant role sees every order, read-only (`view_all_orders`), not only the orders assigned to it |
| 21 | `2026-10-30d-associate-agreement-po-date.sql` | The associate's signed agreement kept as a PDF (upload context `associate_agreement`), and the date the buyer's PO was received (Step 4d) — Day 1 of the associate's 10 days |
| 22 | `2026-10-31a-client-email-unique.sql` | One email per client, enforced by the database (unique index `uq_clients_email`). **First run `docs/checks/2026-10-28-duplicate-client-emails.sql`** and give any client sharing an email its own; until then this file adds nothing and prints which emails are shared. A blank email becomes "no email" |
| 23 | `2026-10-31b-payment-references.sql` | Rule D-212: the bank / payment reference on buyer remittances (advance, balance, freight), the money paid under an LC, incentive and associate payouts (how paid + reference), export benefits received and cancellation refunds (date + bank ref). Columns only; records already saved keep their data |

There is no `2026-10-28b` file; that letter was not used.

Switching Zoho Books on after these migrations follows the **Go-live
checklist** in `docs/SOP/ca-03-zoho-sync.md` (`.env` switches, then the
go-live date and login in Admin → Company Settings, Zoho Books section). Without the
go-live date, a production server sends nothing to Zoho Books.

## Checks after 2026-10-28

Two read-only queries in `docs/checks/` change nothing. Run them once on
the live database after the 28 Oct release:

- `2026-10-28-duplicate-client-emails.sql` lists every email shared by more
  than one client. The portal now refuses a shared email, so give each
  client its own (Clients → Edit).
- `2026-10-28-blank-dropdowns-and-reference-docs.sql` lists dropdown options
  and reference documents saved empty by the earlier bug. Re-enter the text
  (Reference Library → the document → Edit) or the option name (Admin →
  Dropdown options).

After `2026-10-28e`, this should show the same count and total as the bills
already marked paid:

```sql
SELECT COUNT(*), SUM(amount_inr) FROM order_vendor_bill_payments;
SELECT COUNT(*), SUM(paid_amount_inr) FROM order_vendor_bills WHERE paid_at IS NOT NULL;
```

## Older files

Everything before 2026-10-27a (including `2026-10-25` and the
`2026-10-26` files) was applied to the live site in earlier releases.
Read a file's first lines before running it on an older copy; not every
early file is safe to run twice.
