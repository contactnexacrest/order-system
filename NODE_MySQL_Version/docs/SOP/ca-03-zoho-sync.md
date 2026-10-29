# CA / Accounting — Zoho Books Sync

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md)
and [Forex, FIRC Tracking & Revenue Reports](./ca-02-revenue-reports.md)
(read those first if you haven't). Phase 3 connects the CA module to Zoho
Books, so the revenue this system records doesn't have to be re-typed
into Zoho by hand.

## How the connection works

This system is the source of truth for revenue — every order, its
payments, and its INR actuals live here. Zoho Books is treated as the
statutory/filing copy: revenue flows **one way, from this system to
Zoho Books**, never the other way. Nothing you do in Zoho Books changes
anything in this system.

Concretely: once a settlement leg has an **INR actual** recorded (see
Chapter 1), the sync pushes it to Zoho Books as a **Customer Payment**
against that order's client. The first time a given client is synced, the
system creates (or finds, if one already exists) a matching Customer in
Zoho Books and remembers its ID — every later sync for that client reuses
it rather than creating duplicates.

**Export buyers are set up as overseas customers** (2026-10-29). The
customer is created with GST treatment **Overseas** and in the order's
own currency (USD, EUR or GBP), so Zoho Books treats the sale as an
export with no GST, and its GST return shows it as one. (A Books
organisation that is not GST-registered — like the test one — refuses the
GST fields, so there the customer goes in without them and an HS code is
written into the invoice line's description instead.) Each payment goes
in as the **foreign amount at the actual rate** — for example USD 100.00
at 83.955 — so the bank account in Books receives exactly the INR actual
(₹8,395.50) recorded here.

Two things stop a payment, with a message in the Sync Log, instead of
booking it wrongly:

- **The currency is not turned on in Zoho Books.** Add it in Zoho Books →
  Settings → Currencies, then sync again.
- **The buyer is already in Zoho Books in another currency.** A Zoho
  Books customer has one currency, so a EUR payment cannot go onto a
  customer set up in USD. Ask the CA how to book it.

## Commercial Invoices and Debit Notes in Zoho Books (2026-10-29)

Once a **Commercial Invoice** or a freight **Debit Note** is approved (or
sent), the next sync sends it to Zoho Books as an **export invoice** for
the buyer:

- **Same number and date as our document.** The Books invoice carries the
  document's own reference (for example `SC/CI/26/2810001`) and the date
  printed on it. Books does not give it a number of its own.
- **The CI lists the products only** — one line per product, with its
  quantity, unit, unit price and HS code, so its total is the FOB value.
  On CFR / CIF orders, ocean freight (and insurance on CIF) are billed on
  the **Debit Note**, which goes to Books as its own invoice. Nothing is
  counted twice.
- **In the order's currency, at the shipping bill's customs rate**
  (CA advice, 2026-10-29). The CI and the Debit Note use the **customs
  exchange rate printed on the shipping bill**, so the GST return matches
  the shipping bill. Record it on the order page: **Shipping Bill**
  (number, date, customs exchange rate — staff with the shipping
  permission). Until it is recorded the invoice is not sent and the Sync
  Log says so. **The rate is fixed once the invoice is in Books:**
  correcting it afterwards does not change the invoice — each payment
  still goes in at its own actual rate (the INR Settlement Register), and
  Books books the difference as exchange gain or loss.
- **GST (2026-10-29).** In a GST-registered Books organisation the
  invoice goes in as an export to an overseas customer **without payment
  of IGST** (under LUT), with each product's HS code. The **Debit Note**
  is a zero-rated export invoice too: freight billed with export goods
  is part of the export, so its lines carry the goods' HS code (the
  first product's). A GST invoice
  number may have at most 16 characters: CIs and Debit Notes numbered
  before the 2-digit-year change (`SC/CI/2026/…`, 17 characters) are
  refused with a message — book those by hand if they fall after the
  go-live date.
- **Marked as sent in Books, but no email goes out.** This only lets
  payments be applied to it.

**Payments are applied to their invoice:** the advance and the balance to
the CI, the freight payment to the Debit Note.

- The **advance** usually arrives long before the CI. It goes to Books as
  a payment received and is **held there, unapplied**, until the CI is in
  Books; the next sync after that applies it to the CI.
- The **balance** and the **freight** payment usually arrive after their
  document, so they are applied as soon as they are sent.
- The Zoho Books Sync page shows how many payments are still held.

**A revised CI or Debit Note** (a new revision approved) updates the same
Books invoice — never a second one — and Books records why it changed
("Revised in the order system: …"). While a newer revision is still in
draft or review, Books keeps the last approved version. A document
flagged on the order page as possibly out of date is not sent until a
revised one is approved.

**What stops an invoice, with a message in the Sync Log** (it is retried
on every sync until fixed):

- no customs exchange rate recorded on the order (Shipping Bill, on the
  order page);
- a product line without a confirmed quantity or unit price, or whose
  quantity × unit price does not equal its value;
- no confirmed freight rate (Debit Note);
- the invoice number is already used in Books for another customer.

**Letter of Credit orders (owner decision 2026-10-29).** On an LC order
the bank pays under the LC once the documents are presented. Record that
payment on the order page (Step 12a, **LC Payment Received**): the date
the bank paid, the amount in the order currency, the rupees credited and
the bank charges deducted. The next sync sends it as a **customer
payment applied to the Commercial Invoice** (held until the CI is in
Books, like the advance): the full order-currency amount, at (rupees
credited + bank charges) / amount, with the bank charges booked as bank
charges. Once it is in Books it cannot be changed in the app — ask the
CA to correct it in Books. The bank loan against the LC and its interest
are **not** sent: the CA books them from the bank statement.

## Bills, vendor payments and order expenses in Zoho Books (2026-10-29)

What the order pays out goes to Zoho Books too, on the same sync:

- **Vendors.** A supplier or logistics partner becomes a **vendor** in
  Books the first time one of its bills or payments is sent (with its
  PAN; in a GST-registered organisation also its GSTIN). The system
  remembers the vendor, so it is never created twice.
- **Bills.** A supplier's or logistics partner's **tax invoice** becomes a
  Books **bill**, with the vendor's own bill number and date, at its total
  in INR. A **proforma** is an advance request, not a bill, so it is not
  sent. Supplier bills are booked to *Cost of Goods Sold*; CHA, transport
  and ocean freight bills to *Transportation Expense*; other logistics
  bills to *Other Expenses*.
- **Payments to vendors.** Each payment recorded against a bill becomes a
  Books **vendor payment** from the deposit bank account, with its UTR /
  reference. It is applied to the bill when the bill is in Books. A
  payment made on a **proforma** is held in Books as an **advance** to
  the vendor, and is applied to the tax invoice that replaces the
  proforma once that tax invoice is sent.
- **Order expenses.** Each expense recorded on an order becomes a Books
  **expense**: travel, hotel, food and local conveyance to *Travel
  Expense*, courier to *Postage*, samples and other to *Other Expenses*.
  Paid from the company account: the deposit bank account; paid by
  staff: *Employee Reimbursements*; paid by company card: an account
  named *Company Card* (add it in Books before the first card expense).
  The expense import (chapter CA-04) **skips** these, so an order expense
  is never brought back in a second time.
- **Order costs (owner decision 2026-10-29).** The real paid costs in
  the order's cost lines (Step 12a) go as Books **expenses** too, from
  what they were paid from (a new **Paid from** field: company account =
  the deposit bank account, company card = *Company Card*): ECGC to
  *Insurance Expense*, buyer due diligence to *Consultant Expense*,
  documentation to *Export Documentation Charges*, commission to
  *Commission Expense*, packing / wooden crates to *Packing Material*,
  the pre-shipment inspection fee to *Inspection Charges* and other to
  *Other Expenses* (the CA adds the accounts that do not exist yet).
  A cost corrected later (for example the inspection fee) is updated in
  Books; a cost removed in the app is removed from Books at the same
  time. **Not sent:** the travel % (a budget only), bank-loan interest
  and bank charges (the CA books them from the bank statement), and CHA,
  inland transport and port charges entered as cost lines (record those
  as logistics partner bills, which go to Books themselves).

**What stops a bill, payment or expense, with a message in the Sync Log**
(retried on every sync until fixed):

- a Books account above does not exist (add it in Zoho Books →
  Accountant → Chart of Accounts, or ask the CA which to use);
- in a GST-registered Books organisation, a bill whose GST is not 5, 12,
  18, 28 or 40 % of its taxable value, or a bill with GST from a supplier
  or logistics partner with **no GSTIN** in the app (add the GSTIN, or
  correct the bill).

**GST on bills (2026-10-29).** In a GST-registered Books organisation a
bill goes in at its **taxable value plus GST at the bill's own rate**:
**IGST** when the supplier's GSTIN is from another state, **CGST + SGST**
when it is from Karnataka, like NexaCrest's. Books works out the place of
supply from the GSTIN. A bill without GST from a registered supplier goes
in at 0 %; from an unregistered one, without tax. Full input credit is
taken on bills. Order expenses go in without GST (Books marks them "out
of scope").

**Reverse charge on road transport (CA advice, 2026-10-29).** A road
transporter (goods transport agency) that charges no GST is booked under
**reverse charge**: we pay the 5 % GST to the government ourselves and
claim it back. When adding a **Transport** bill under Logistics partner
bills, choose **GST on this bill → Reverse charge — no GST on the bill** and pick GST rate **0 %** (the app refuses anything
else, and offers reverse charge only for Transport). The bill shows
"Reverse charge" in the list. In Books it goes in at its taxable value
with **reverse charge at 5 %** — IGST 5 % for a transporter registered in
another state, else CGST + SGST 2.5 % each — and the Sync Log says
"reverse charge". Every other bill: leave it at **Charged on the bill**.

## Export benefits in Zoho Books (owner decision 2026-10-29)

When a claim is marked **received** (chapter CA-07), the next sync sends
it to Books as a **journal**, dated the day it was received, against
*Export Incentive Income*:

- **Duty Drawback** is paid into the bank: the journal debits the deposit
  bank account.
- **RoDTEP** and **RoSCTL** arrive as e-scrips in the ICEGATE ledger, not
  in the bank: the journal debits *Export Incentive e-Scrips* (an asset
  account; using or selling the scrips later is for the CA).
- Any other scheme is not sent (the Sync Log says so): the CA books it by
  hand.

A claim only filed, not yet received, is not booked. A corrected receipt
updates the journal; a receipt cleared in the app removes it from Books.
A claim whose receipt is in Books cannot be deleted straight away: clear
its receipt first, run the sync (which removes the journal), then delete
it. The CA adds *Export Incentive Income* (income) and *Export Incentive
e-Scrips* (other current asset) in Books before the first one.

## Checking an order against Zoho Books (owner decision 2026-10-29)

On the order page, Step 12, **Check this order against Zoho Books** (for
whoever runs the Zoho sync) opens a page that puts every record of the
order beside what Books holds for it: the Commercial Invoice and Debit
Note, the buyer's payments and the LC payment, the bills and the payments
made against them, the order expenses and costs, and the export benefit
journals. Each line says one of:

| Status | Meaning |
|---|---|
| Matches | Books has it, with the same amount. |
| Not in Zoho Books yet | It goes on the next sync; the note gives the last error, if any. |
| Before the go-live date | The CA books it by hand (opening position). |
| Different in Zoho Books | Books has it with another amount — someone changed it there, or the app changed after sending. |
| Missing in Zoho Books | The app sent it, but Books no longer has it. |
| Only in Zoho Books | An invoice, payment or bill in Books under this order's reference that the app did not send. |

The page only reads: it changes nothing in the app or in Books. Correct
whichever side is wrong.

## The server switches in `.env` (2026-10-29)

Three lines in the server's `.env` file decide what this server may do
with Zoho. They are always written out, never commented out, and no
screen can change them:

| Line | Values | What it does |
|---|---|---|
| `ZOHO_INTEGRATION_ENABLED` | `true` / `false` | `false`: this server never contacts Zoho, whatever Admin → Settings says. |
| `ZOHO_ENVIRONMENT` | `test` / `production` | `test`: only customers whose name starts with `TEST-` may go to Zoho. `production`: only real orders — test and sample orders are never sent. Use `production` on the live server only. |
| `ZOHO_EINVOICE_ENABLED` | `true` / `false` | GST e-invoice (IRN), e-way bill and GST filing. Allowed only when this is `true` **and** `ZOHO_ENVIRONMENT=production`. Keep it `false` until NexaCrest must e-invoice (turnover above ₹5 crore) — an IRN cannot be deleted. |

A missing or misspelt value counts as the safe choice (off / test / off),
and the Settings page shows a warning naming it. **Nothing from
development or testing can reach the GST portal or any other government
portal**: in `test`, every e-invoice, e-way bill and GST-filing request is
refused before it leaves the server, whatever Zoho Books' own settings
say. In Zoho Books itself, keep e-Invoicing and e-Way Bills switched off
until go-live and the test clean-up are done.

**Zoho Mail is not used to send.** NexaCrest's mail is hosted with
Bluehost; all mail goes through the SMTP server in `.env`. The Zoho Mail
code is kept but switched off in code, and its settings are not shown.

## If Zoho Books isn't configured

**This is never a blocker.** Every screen, report, and permission in the
CA module works exactly the same whether or not Zoho Books is connected.
If the connection isn't set up yet (or breaks later), the sync simply logs
that it has nothing to do and moves on — nothing else in the system
depends on it, in the same way SMTP being unconfigured never stops the
rest of the app from working.

## Setting up the connection

Under **Admin → Settings**, a `zoho_books` group of settings needs filling
in. NexaCrest uses one Zoho API Console **Self Client** (scope
`ZohoBooks.fullaccess.all`; the Zoho Mail scopes are not needed, because
the app does not send through Zoho Mail):

- `zoho_books_client_id`, `zoho_books_client_secret`, `zoho_books_refresh_token`
  — from the Self Client in the Zoho API Console (api-console.zoho.in).
- `zoho_books_organization_id` — your Zoho Books organization ID (Zoho
  Books → Settings → Organization Profile).
- `zoho_books_deposit_account_id` — the Zoho Books bank account that
  synced payments are recorded as deposited into. For NexaCrest this is
  **SBI Bank – Current Account**: all export money arrives there in INR.
- `zoho_books_accounts_domain` / `zoho_books_api_domain` — these default to
  Zoho's Indian data centre (`accounts.zoho.in` / `www.zohoapis.in`), where
  NexaCrest's Zoho One account lives. Change them only if the account
  moves region.
- `zoho_books_sync_start_date` — the **go-live date** (2026-10-29). Only
  CIs, Debit Notes, payments, bills, vendor payments and order expenses
  dated on or after it go to Zoho Books; the CA books everything earlier
  (for example an advance received before go-live) by hand as the opening
  position. **On the live server nothing is sent until it is set.** Pick
  the 1st of a month, ideally the start of a GST return period.
- `zoho_books_enabled` — flip this on once everything above is filled in.
  This is the master switch the sync checks before doing anything.

Then press **Test Books Connection** at the bottom of the Settings page.
It logs in and shows the organisation name and the deposit account, or
the exact problem (for example a wrong organization ID, listing the
organisations the login can see). It also lists any of this system's
currencies that Zoho Books has not turned on. It only reads — nothing is
created in Zoho Books.

## Running a sync

**CA / Accounting → Zoho Books sync** shows:

- **Status** — whether Zoho Books is currently enabled, and how many
  settlement legs are waiting to be pushed.
- A **Sync Now** button — runs the sync immediately, gated on the "Manage
  CA / Accounting integrations" permission (a step up from just viewing
  the register, since this calls an external API and can surface error
  detail).
- The **Sync Log** — every sync attempt ever made, manual or scheduled,
  success or failure, with the Zoho reference created (on success) or the
  error message (on failure). This is its own independent log, separate
  from the main system's Audit Log, exactly as this module was scoped to
  keep it.

Each run first sends approved CIs and Debit Notes, then payments (and LC
payments), then applies any held payment whose invoice is now in Books;
then bills, vendor payments, order expenses and order costs; then the
expense import; then received export benefits as journals. Zoho lets a login
be renewed only a few times in ten minutes, so pressing **Sync Now** or
**Test Books Connection** many times in a row can be refused for a few
minutes ("too many requests" in the Sync Log); wait and run it again.

A **scheduled job** also runs automatically (hourly by default — see the
deployment README for the exact setup on each stack) and does exactly the
same thing as the button, so revenue gets synced even if nobody
remembers to click it.

The **INR Settlement Register** (Chapter 1's main table) also shows a
**Zoho Books** column for each leg — "Synced [date]" once pushed, "Pending"
once an INR actual is recorded but not yet synced, or blank if there's
nothing to sync yet.

## Testing and the clean-up before go-live

Stage 1 was tested in NexaCrest's real Zoho Books organisation (and its
test records removed). From Stage 2 on, testing uses a **separate test
Zoho account** with its own Books organisation, which is **not
GST-registered**, so nothing from testing can reach the GST portal (owner
decision, 2026-10-29). Whichever organisation is used, everything made
while testing is removed before go-live:

- Test customers' names start with **`TEST-`**. With
  `ZOHO_ENVIRONMENT=test`, nothing else can be sent; a bill or vendor
  payment also needs a vendor whose name starts with `TEST-`.
- **Keep GST e-invoicing off** and file no GST return from Zoho Books
  until the clean-up is done.
- The clean-up script lists, and with `--apply` deletes, every `TEST-`
  customer with its payments and invoices, every `TEST-` vendor with its
  payments and bills, every expense whose description starts with
  `TEST-`, and every journal whose notes start with `TEST-` (export
  benefits) — payments first, then invoices, bills, expenses and
  journals. With
  `--from-sync-log` it also takes every record the **test** database
  pushed — a record already gone is reported as such, not logged as a
  deletion —
  it refuses to run where `ZOHO_ENVIRONMENT=production`. It is a dry run
  unless `--apply` is given:
  - PHP: `php app/cron/zoho_cleanup_test_records.php [--from-sync-log] [--apply] [--by "Name"] [--log docs/zoho/zoho-change-log.csv]`
  - Node: `node src/jobs/zohoCleanupTestRecords.js` with the same options.

**Every change made in Zoho is logged** in `docs/zoho/zoho-change-log.csv`
(it opens in Excel): when, who, on whose instruction, how, where in Zoho,
what exactly (with the Zoho ID), before and after, why, whether it is
test data, and when it was removed. The clean-up script writes its own
rows there with `--log`.

## What to do if a sync fails

Check the Sync Log's message column — most failures will be either a
configuration problem (a missing or expired credential) or a genuine Zoho
Books API error (e.g. the deposit account ID no longer exists). A failed
leg stays "Pending" and is retried automatically on the next sync run
(manual or scheduled) — nothing needs to be manually reset. If the same
leg keeps failing, the message column is the place to start diagnosing
why.
