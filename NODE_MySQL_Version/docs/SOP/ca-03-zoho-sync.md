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
anything in this system — and what the app sent is put back in Books if
someone deletes or changes it there (see *Who owns what* below).

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
  printed on it. Books does not give it a number of its own. When Books
  first gets it, the invoice also gets an internal **comment** saying what
  it is — "Commercial Invoice for order NX-12 · CIF · buyer PO PO-778 ·
  <buyer>" (rule D-212). Comments never print on Zoho's own invoice PDF,
  and our documents are unchanged. A supplier bill's line also names the
  Supplier PO it is billed against.
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
- **Associate commission (owner decision D-217, 1 Nov 2026).** Once an
  order's associate commission is fully paid, it goes to Books not as an
  expense but as the **associate's own bill** (the associate a vendor, the
  bill numbered with their invoice, account *Commission Expense*, the
  gross commission) and a **vendor payment** from the bank with the UTR,
  applied to it. The bill's description names the associate, the order,
  the rate, the client number, their invoice and the TDS; the payment's
  gives the gross, the TDS (section and rate), the net and the UTR, and
  names the bill (D-235). With a Zoho TDS tax id set in Settings →
  Associate TDS the bill carries the TDS and the payment is the net;
  without one the TDS deducted stays open on the bill for the CA to book
  against TDS payable, and the Sync Log says so. A claw-back is not sent:
  the CA books the recovery (vendor credit). In test mode only associates
  named `TEST-…` are sent. See [Chapter 24](./24-associates.md).

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

## Clients and products in Zoho Books (owner decision 2 Nov 2026)

Two switches under **Admin → Settings → Zoho Books**, both **off** by
default and each saved with a reason (D-238):

| Switch | When on, at every sync (hourly, or Sync now) |
|---|---|
| `zoho_sync_clients` | Each active client becomes (or stays) a Zoho Books **customer**: legal name, billing address, country, VAT/EORI number (in the customer's notes), contact person, e-mail and phone. A client edited in the app is updated in Zoho. A client made inactive is marked **inactive** in Zoho. |
| `zoho_sync_products` | Each product of the **Products** menu becomes (or stays) a Zoho Books **item**: name, specifications, HS code, **rate 0**. A product made inactive — or deleted — is marked **inactive** in Zoho. |

- **One way, app → Zoho.** The app is the master record. If someone edits a
  customer or item directly in Zoho, the app's values go back the next time
  that client or product changes in the app — or for all of them at once
  with **Re-push all clients and products** on the Zoho Books Sync page.
- **Nothing is deleted in Zoho**, so old invoices stay intact.
- **No duplicates:** before creating, the sync looks for a Zoho customer or
  item with exactly the same name and links to it.
- **A client goes once its first order exists** — the order sets the
  customer's currency, and a Zoho customer's currency can never change.
- **Products and their rate** (owner decision 2 Nov 2026, D-240). **No
  cost is ever sent to Zoho.** A product's **reference selling price**
  becomes the Zoho item's rate when it is in INR (Zoho's base currency);
  in any other currency the rate stays 0 and the item description ends with
  "Reference price: USD 45.00 per SQFT". A product without a reference
  price goes with rate 0, as before. A change to the reference price sends
  the item again.
- **Invoice lines link the item.** A Commercial Invoice line picked from a
  product that is already a Zoho item carries that item, so Zoho's item
  reports show it; the line keeps its own description and its own price.
  Lines typed by hand go as before.
- **"Sync this order"** on an order page does not send clients or products —
  only the full sync does.
- While testing (`ZOHO_ENVIRONMENT=test`) only clients and products whose
  name starts with `TEST-` go to the test organisation.
- Both count in the **Waiting for Zoho** badge while their switch is on, and
  every create, update or inactivation is a row in the Sync Log.

## Who owns what: the app and Zoho Books (owner decision 2026-10-29)

**The app owns what it sends.** Commercial Invoices and Debit Notes, the
buyer's payments (advance, balance, freight) and the LC payment, the
suppliers' and logistics partners' bills, the payments made to them, the
order expenses, the order costs and the export benefit journals are
entered in the app and only copied to Books. Each of these already sent —
dated on or after the go-live date — is checked against Books at the start
of every **Sync Now**, and by the scheduled job **once a day** (its first
run after 23 hours; the hourly runs in between only send what is new, to
stay inside Zoho's daily API limit):

- **Deleted in Books** → sent again. Its Sync Log row starts *"Re-created
  in Zoho Books — it had been deleted there."* A payment is applied to its
  invoice or bill again; an invoice or bill sent again gets its payments
  applied again.
- **A different amount in Books** → put back to the app's amount, in the
  same Books record (never a second one). The Sync Log row starts
  *"Restored to the app's values — it had been changed in Zoho Books."*
  and says what Books had.
- **Dated in a financial year the CA has locked** (chapter CA-06) →
  nothing is changed in Books. The Sync Log says *"… which is locked: not
  restored — CA to decide."* once (not again on every sync while it stays
  that way). The CA decides whether to unlock the year or correct Books by
  hand.

So a correction belongs in the app: change it there, and the next sync
updates Books. A change made only in Books is undone. Each restore is its
own Sync Log row, and the counts in the Sync Now message include them.

**Books owns the expenses the CA enters there** (chapter CA-04): the
import follows Books for those — new ones come in, changed ones are
changed here, deleted ones are removed here.

**Removing an order expense in the app removes it from Books too.** If
Books cannot be reached at that moment, nothing is removed and the message
says why; try again later.

## The server switches in `.env` (2026-10-29)

Three lines in the server's `.env` file decide what this server may do
with Zoho. They are always written out, never commented out, and no
screen can change them:

| Line | Values | What it does |
|---|---|---|
| `ZOHO_INTEGRATION_ENABLED` | `true` / `false` | `false`: this server never contacts Zoho, whatever Admin → Company Settings says. |
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

Under **Admin → Company Settings**, a `zoho_books` group of settings needs filling
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

**When a record reaches Zoho Books (owner decision 2 Nov 2026, D-233).**
Saving a receipt, bill, payment, expense, cost or benefit in the app
**never** sends it to Zoho Books at that moment: it is saved in the app and
waits for the next sync — the **hourly scheduled job**, **Sync Now**, or
**Sync this order to Zoho now**. (Removing an expense or cost that is
already in Books is the one exception: it is removed from Books straight
away.) While something is waiting:

- the order page shows **Waiting for Zoho: N** (in At a Glance, and in
  Step 12 beside the Zoho buttons) with what is waiting, e.g. "1 buyer
  payment, 2 order costs"; "Waiting for Zoho: 0 — everything on this order
  is in Zoho Books" once all is sent. People with "Manage CA module" can
  click it to open the order's Zoho check; people who can only view the CA
  module see the count;
- the **Zoho Sync** menu item carries the number of records waiting across
  all orders (worked out at most once a minute), and the Zoho Sync page
  lists them by kind.

Nothing is shown while Zoho Books is off. **The hourly scheduled job must
be installed on the live server** (see the deployment README); without it,
records only reach Zoho Books when someone presses Sync Now.

Owner decision 2 Nov 2026: **Zoho Sync** is in the main menu for everyone
with "Manage CA module", and each order's Step 12 has **Sync this order to
Zoho now** beside "Check this order against Zoho Books". The order button
sends only that order's invoices, buyer payments, bills, vendor payments,
expenses, costs and export benefit journals — with the same duplicate
guards, each one in the Zoho Sync Log. The repairs of records changed in
Books, the expense import from Books and the GST refund journals (per
claim, not per order) run with the full sync. When Zoho Books is not
connected it says "Zoho is not connected — Settings → Zoho Books" and
sends nothing.

**CA / Accounting → Zoho Books sync** shows:

- **Status** — whether Zoho Books is currently enabled, how many
  settlement legs are waiting to be pushed, and **Waiting for Zoho (all
  records)** with what is waiting by kind.
- A **Sync Now** button — runs the sync immediately, gated on the "Manage
  CA / Accounting integrations" permission (a step up from just viewing
  the register, since this calls an external API and can surface error
  detail).
- The **Sync Log** — every sync attempt ever made, manual or scheduled,
  success or failure, with the Zoho reference created (on success) or the
  error message (on failure). A row about an order links to the order; a
  row about an imported expense shows *Expense #n* and links to the
  Expenses page. This is its own independent log, separate from the main
  system's Audit Log, exactly as this module was scoped to keep it.

Each Sync Now (and the scheduled job once a day) first checks what was already sent (see *Who owns what* above),
then sends approved CIs and Debit Notes, then payments (cleared payment milestones too — D-215 — with reference `<buyer ref>-m<step>`, and LC payments),
then applies any held payment whose invoice is now in Books; then bills,
vendor payments, order expenses and order costs; then the expense import
(new, changed and deleted expenses); then received export benefits as
journals. Zoho lets a login
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

## Go-live checklist (owner request 2026-10-29)

Do these in order on the **live** server. Each step names who does it.

**Before switching on**

1. **Developer / host:** install the database from the stack's
   `docs/schema.sql` then `docs/seed.sql` (a first installation needs no
   migration — they are already included, D-241); on an existing database,
   back it up and run any newer file in `docs/migrations` (see the README
   there). Deploy the code and restart the Node app (PHP needs no
   restart).
2. **CA:** in the real Zoho Books organisation, check that the Chart of
   Accounts has every account the sync uses, spelled exactly like this
   (add any that are missing, or ask the developer to map to another):
   Commission Expense, Company Card, Consultant Expense, Cost of Goods
   Sold, Employee Reimbursements, Export Documentation Charges, Export
   Incentive Income, Export Incentive e-Scrips, Inspection Charges,
   Insurance Expense, Other Expenses, Packing Material, Postage,
   Transportation Expense, Travel Expense, and **GST Refund Receivable**
   (an asset: GST refunds under LUT are taken out of it — see
   [CA-09](./ca-09-gst-refunds.md)). A missing one stops only the
   records that need it; the Sync Log names it.
3. **CA:** turn on in Books every currency the buyers pay in (Settings →
   Currencies). Keep e-Invoicing and e-Way Bills **off**.
4. **CA:** book by hand, as the opening position, everything dated
   **before** the go-live date (open invoices, advances received, bills
   not yet paid). The app sends only what is dated on or after it.
5. **Developer / host:** in the live server's `.env`, set
   `ZOHO_INTEGRATION_ENABLED=true` and `ZOHO_ENVIRONMENT=production`.
   Keep `ZOHO_EINVOICE_ENABLED=false` until NexaCrest must e-invoice.
6. **Admin:** in **Admin → Company Settings**, Zoho Books section, fill in the real
   organisation's login details and organization ID
   (60057714676 — NexaCrest International), the deposit account
   (SBI Bank – Current Account) and the **go-live date**
   (`zoho_books_sync_start_date`: the 1st of a month, ideally the start of
   a GST return period). Press **Test Books Connection**: it must show the
   organisation name and the deposit account, with no warnings.
7. **Admin:** switch **Zoho Books enabled** on.

**After switching on**

8. **Accounts / CA:** press **Sync Now** once and read the Sync Log. Fix
   anything it names (usually a missing account or currency) and sync
   again.
9. **CA:** for the first few orders, open **App vs Zoho** on the order
   page: every line should say it matches.
10. **Everyone:** from now on, correct anything the app sends **in the
    app**; a change made only in Books to such a record is undone on the
    next sync. Expenses the CA books in Books are the exception: they
    follow Books (put the order's reference in **Reference #** to link
    them to the order).
11. **Developer:** log in `docs/zoho/zoho-change-log.csv` every manual
    change made in Zoho while going live, as for testing. Records the app
    creates on the live site stay in its own Sync Log.

The test Zoho organisation (60093754195) was emptied of every test record
on 2026-10-29 (last clean-up: change log Z-370 to Z-383); nothing from testing needs
removing from the real organisation.

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

## Zoho's call limits — this app's share (owner decision 2 Nov 2026, D-239)

Zoho Books limits how many API calls an **organisation** may make — about
**100 a minute** and a **daily** number that depends on the plan (roughly
1,000 Free, 2,000 Standard, 5,000 Professional, 10,000 Premium and above;
check your own plan). Every Zoho app and integration of the company draws on
the same limit, so this app keeps to its own share:

| Setting (Admin → Settings → Zoho Books) | Default | What happens when it is reached |
|---|---|---|
| `zoho_api_daily_limit` — max Zoho calls per day for this app (midnight to midnight, India time) | 800 | Syncing **pauses until midnight**. The run stops with "Paused: daily Zoho allowance for this app (800 calls) used — resumes tomorrow."; the "Waiting for Zoho" badges say "paused — daily Zoho allowance used, resumes tomorrow". |
| `zoho_api_minute_limit` — max Zoho calls per minute for this app | 30 | The **scheduled (hourly) sync waits** for the next minute and carries on. **Sync Now**, **Sync this order**, **Check against Zoho** and a delete wait at most 5 seconds; otherwise they stop with "Zoho speed limit for this app reached — try again in a minute." |

- **Every call counts** — reads (checks, look-ups), the login token and the
  sends. The **Zoho Sync** page shows "Zoho calls today: X of N · this
  minute: Y of M" and the last 7 days.
- **Zoho says "too many requests"** (because other apps used the limit, or
  Zoho is busy): the run **stops at once** — it does not keep trying the
  remaining records — with one Sync Log line "Stopped: Zoho rate limit —
  remaining records will be sent at the next sync." and one failure e-mail
  to Finance (D-233). Nothing is lost: everything not sent stays waiting.
- **One login token for everyone.** The token Zoho issues is valid an hour;
  the app keeps it (encrypted) and every part of the app — the hourly sync,
  Sync Now, the order buttons, the checks — uses the same one for 50
  minutes, instead of asking Zoho for a new one each time (Zoho also limits
  how many tokens it hands out). If Zoho stops accepting it, the app fetches
  a new one once and carries on.
- **Choosing the numbers.** Keep the daily allowance well below your plan's
  limit so the company's other Zoho apps keep working; a normal day of this
  app is a few hundred calls. Raise it only if the Zoho Sync page shows the
  app regularly pausing.

## What to do if a sync fails

**Finance is told (owner decision 2 Nov 2026, D-233).** When a sync run —
scheduled, Sync Now, or Sync this order — has any failure, everyone with
"Manage CA module" gets **one e-mail for that run** (subject "Zoho Books
sync: N records could not be sent") listing each failed record's kind, its
order and Zoho's error message — never amounts — plus an in-app
notification. With business e-mail switched off (Settings), only the
in-app notification is sent; the Sync Log has every detail either way. A
run with no failures sends nothing.

Check the Sync Log's message column — most failures will be either a
configuration problem (a missing or expired credential) or a genuine Zoho
Books API error (e.g. the deposit account ID no longer exists). A failed
leg stays "Pending" and is retried automatically on the next sync run
(manual or scheduled) — nothing needs to be manually reset. If the same
leg keeps failing, the message column is the place to start diagnosing
why.
