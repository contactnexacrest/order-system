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
system creates (or finds, if one already exists) a matching Contact in
Zoho Books and remembers its ID — every later sync for that client reuses
it rather than creating duplicates.

## If Zoho Books isn't configured

**This is never a blocker.** Every screen, report, and permission in the
CA module works exactly the same whether or not Zoho Books is connected.
If the connection isn't set up yet (or breaks later), the sync simply logs
that it has nothing to do and moves on — nothing else in the system
depends on it, in the same way SMTP being unconfigured never stops the
rest of the app from working.

## Setting up the connection

Under **Admin → Settings**, a `zoho_books` group of settings needs filling
in (this is a separate Zoho API registration from the one used for Zoho
Mail, even though both live under the same Zoho One subscription — they
use different access scopes):

- `zoho_books_client_id`, `zoho_books_client_secret`, `zoho_books_refresh_token`
  — from a self-client OAuth app registered in the Zoho API Console against
  your Zoho Books organization.
- `zoho_books_organization_id` — your Zoho Books organization ID.
- `zoho_books_deposit_account_id` — which account in Zoho Books' chart of
  accounts synced payments should be recorded as deposited into (e.g. your
  bank account, as Zoho Books already has it set up).
- `zoho_books_accounts_domain` / `zoho_books_api_domain` — leave these on
  their defaults unless your Zoho One account is in a non-default region
  (EU/India/China/Australia have their own domains).
- `zoho_books_enabled` — flip this on once everything above is filled in.
  This is the master switch the sync checks before doing anything.

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

## What to do if a sync fails

Check the Sync Log's message column — most failures will be either a
configuration problem (a missing or expired credential) or a genuine Zoho
Books API error (e.g. the deposit account ID no longer exists). A failed
leg stays "Pending" and is retried automatically on the next sync run
(manual or scheduled) — nothing needs to be manually reset. If the same
leg keeps failing, the message column is the place to start diagnosing
why.
