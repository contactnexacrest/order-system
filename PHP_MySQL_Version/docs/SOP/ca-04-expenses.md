# CA / Accounting — Expenses

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md),
[Forex, FIRC Tracking & Revenue Reports](./ca-02-revenue-reports.md), and
[Zoho Books Sync](./ca-03-zoho-sync.md) (read those first if you haven't).
Phase 4 adds the other half of the accounting picture: expenses — salary,
supplier payments, travel, and anything else the business spends money on.

## Where expenses are entered

**Expenses are entered in Zoho Books, never in this system.** This
mirrors the revenue direction exactly in reverse: Phase 3 pushes revenue
from this system to Zoho Books; Phase 4 imports expenses from Zoho Books
into this system. There is no "Add Expense" button here on purpose — this
system's Expenses page is a read-only mirror, built so a CA or the
Accounts team can see revenue and expenses side by side for reconciliation
without needing separate logins or separate exports.

## What gets imported

Every expense recorded in Zoho Books is imported: its category (Zoho
Books' own expense/account name — this system doesn't maintain a separate
category list, so whatever chart-of-accounts category is used in Zoho
Books is exactly what shows up here), description, vendor name, amount,
currency, and date.

Each Zoho expense is only ever imported once — the system remembers which
Zoho expense IDs it has already pulled, so running a sync repeatedly never
creates duplicates. There's no manual "delete" for an imported expense
either; if an expense is corrected or removed in Zoho Books, that
correction doesn't currently flow back automatically (see "What this
doesn't do yet" below).

## TDS — a local-only note

Zoho Books' own TDS (Tax Deducted at Source) handling isn't assumed to be
present or imported. Instead, **CA / Accounting → Expenses** lets anyone
with the "Add/edit INR actual" permission mark an imported expense as
TDS-applicable and record the TDS amount, directly in this system. This
is a **local-only annotation** — it's for this system's own reconciliation
and reporting, and it never writes anything back to Zoho Books. If your
Zoho Books setup already tracks TDS in its own way, treat this as a
convenience for cross-checking, not a replacement.

## TDS Payable Summary

**CA / Accounting → TDS Payable Summary** rolls up every TDS-applicable
expense (the local annotation above) by month and by financial-year
quarter (Q1 Apr-Jun, Q2 Jul-Sep, Q3 Oct-Dec, Q4 Jan-Mar) — total expense
amount and total TDS amount in each bucket, so a CA can see at a glance
what's due for a given quarter's TDS return without re-filtering the
Expenses list by hand. Expenses never marked TDS-applicable are excluded
entirely, exactly as they are everywhere else in this module.

## How to run an import

Exactly the same way revenue gets synced — **CA / Accounting → Zoho Books
sync → Sync Now** runs both directions in one pass: it pushes any pending
revenue, then imports any new expenses, and logs each outcome separately
in the same Sync Log. The hourly scheduled job does the same thing
automatically. See [Zoho Books Sync](./ca-03-zoho-sync.md) for the full
detail on configuring the connection and reading the log.

## Export-related costs (ECGC insurance, COO fees, CHA charges, Transport, etc.)

These are ordinary expenses like any other — as long as your Accounts
team books them in Zoho Books (under whatever category name you use
there, e.g. "ECGC Insurance", "CHA Charges", "Freight/Transport", "COO
Certification Fees"), the next sync pulls them in here exactly like any
other expense, with that category name intact for filtering. There is
nothing special to configure — this system doesn't maintain its own
separate list of expense categories on purpose (see "What gets imported"
above), so it never falls out of sync with however your CA actually
categorizes things in Zoho Books.

This is different from **government export benefits** (RODTEP and similar
schemes) — see [Government Export Benefits](./ca-07-export-benefits.md).
Those are money owed *to* the company, not an expense, and Zoho Books has
no equivalent for them, so they're entered directly in this system
instead.

## What this doesn't do yet

- No expense editing or deletion here — corrections happen in Zoho Books;
  this system doesn't currently detect or re-import a correction to an
  expense it has already pulled.
- No automatic expense/revenue reconciliation against your actual bank
  statement yet — that's a later phase (bank-statement import and
  matching). For now, this page is the expense side of the picture;
  Chapter 2's revenue register and reports are the revenue side.
- No payroll or HR functionality — salary only ever appears here as a
  single expense line, exactly as it's entered in Zoho Books. This system
  deliberately doesn't track individual employees, salary structures, or
  payslips.
