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
export with no GST, and its GST return shows it as one. Each payment goes
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

Until invoices are sent to Zoho Books too (the next stage of the Zoho
integration), each payment shows in Books as received but not yet applied
to an invoice.

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

A **scheduled job** also runs automatically (hourly by default — see the
deployment README for the exact setup on each stack) and does exactly the
same thing as the button, so revenue gets synced even if nobody
remembers to click it.

The **INR Settlement Register** (Chapter 1's main table) also shows a
**Zoho Books** column for each leg — "Synced [date]" once pushed, "Pending"
once an INR actual is recorded but not yet synced, or blank if there's
nothing to sync yet.

## Testing and the clean-up before go-live

Testing uses NexaCrest's real Zoho Books organisation (owner decision,
2026-10-29), so everything made while testing must be removed before
go-live:

- Test customers' names start with **`TEST-`**. With
  `ZOHO_ENVIRONMENT=test`, nothing else can be sent.
- **Keep GST e-invoicing off** and file no GST return from Zoho Books
  until the clean-up is done.
- The clean-up script lists, and with `--apply` deletes, every `TEST-`
  customer and its payments (payments first). With `--from-sync-log` it
  also takes every payment and customer the **test** database pushed —
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
