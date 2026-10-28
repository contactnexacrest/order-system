# Database migrations — upgrading an existing installation

A **new** installation needs none of these files: load the stack's
`docs/schema.sql` and then `docs/seed.sql` (see the stack README). Both
already contain every change listed here.

An **existing** database (the live site, or a dev copy made before a
release) is brought up to date by running the files in this folder that it
has not had yet, **in filename order**. The two stacks share one database
design, so the same files serve PHP and Node.

```bash
mysql -u <user> -p <database> < docs/migrations/2026-10-28e-vendor-bill-proforma-and-payments.sql
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
deploy the code for both stacks. As of 28 Oct 2026 the live site still
needs **27f, 27g, 28a, 28c, 28d and 28e**.

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

There is no `2026-10-28b` file; that letter was not used.

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

Everything before 2026-10-25 was applied to the live site in earlier
releases. Read a file's first lines before running it on an older copy;
not every early file is safe to run twice.
