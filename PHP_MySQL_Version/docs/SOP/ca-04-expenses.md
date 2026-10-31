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

**Since Zoho Books Stage 3 (2026-10-29), once it is switched on:** the
supplier's and logistics partners' tax invoices, the payments made to them
and the expenses recorded on an order are sent to Zoho Books **from this
system** (chapter CA-03). Do **not** also enter those in Zoho Books by hand,
or they are counted twice. Expenses that belong to no order (salaries,
rent, office costs and the like) are still entered in Zoho Books as
before.

## What gets imported

Every expense recorded in Zoho Books is imported: its category (Zoho
Books' own expense/account name — this system doesn't maintain a separate
category list, so whatever chart-of-accounts category is used in Zoho
Books is exactly what shows up here), description, vendor name, amount,
currency, and date. An expense typed in Zoho Books **without a
description** shows a red **No description in Zoho** badge here and on
the order it is linked to (rule D-212): add the description in Zoho Books
(Purchases → Expenses) and run the sync — the app cannot fill it in for
Zoho.

Each Zoho expense is only ever imported once — the system remembers which
Zoho expense IDs it has already pulled, so running a sync repeatedly never
creates duplicates. There's no manual "delete" for an imported expense
here: Zoho Books is where these are corrected.

**Corrections and deletions in Zoho Books follow (owner decision
2026-10-29).** Zoho Books is the source of truth for the expenses the CA
enters there, so every sync also brings over what changed:

- **Changed in Books** (amount, date, category, description, vendor or
  currency) → changed here too. The Sync Log says what changed (for example
  *amount 2350.00 → 2400.00*), and the Audit Log keeps the old and new
  values. A link to an order and the TDS note stay as they were.
- **Deleted in Books** → removed here too, with the old row kept in the
  Audit Log.
- **Not automatically**, logged once in the Sync Log for the CA instead:
  an expense dated in a **locked financial year** (chapter CA-06) is not
  changed or removed here; and an expense **matched to a bank statement
  line** (chapter CA-05) is not removed — unmatch it on the Bank Statement
  page first, and the next sync removes it.
- If most of the imported expenses disappear from Books at once (more than
  ten, and more than half), nothing is removed: that is far more likely the
  app being connected to the wrong Books organisation than real deletions.
  The Sync Log says so; check the connection, then ask the CA.

**Order expenses sent from this system (2026-10-29).** Expenses recorded
on an order are sent to Zoho Books as expenses (chapter CA-03). The import
recognises them and skips them, so they are not listed here a second
time — they stay on the order where they were entered.

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

CHA, transport and freight forwarder bills recorded on an order go to
Zoho Books from this system once Stage 3 is switched on (see above); for
the rest, these are ordinary expenses like any other — as long as your
Accounts team books them in Zoho Books (under whatever category name you use
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

## Linking an expense to the order it belongs to

**Automatically, by the Reference # (owner decision 2026-10-29).** When the
CA enters an order's expense in Zoho Books, put the order's **buyer
enquiry reference or order reference** (for example `NX-1042`) in the
expense's **Reference #**. The import then links it to that order by
itself, and the Sync Log says *"linked to order #n by its Reference #"*.
Capital or small letters do not matter, and other words may stand next to
it. When no order, or more than one, matches, it is not linked — link it
by hand as below.

**By hand:**

An expense like ECGC insurance or a third-party inspection fee is
genuinely tied to one shipment, not the business in general — the
Expenses list lets anyone with the "Add/edit INR actual" permission link
an imported expense to the order it was actually incurred for, by typing
that order's reference number next to the row (an "&times;" button
appears next to a linked expense to undo the link, e.g. to correct a
mis-entry). Zoho Books has no concept of this system's order IDs, so this
is always a manual, local-only step, exactly like the TDS annotation
above — it's never pushed back to Zoho Books.

Once linked, the expense shows up on that order's own detail page too,
under **Order Financials — Government Benefits, Costs & Profitability**
(gated on `manage_order_financials`, not the broader `ca_module_view` —
see [Order Financials](./ca-08-order-financials.md)), alongside any
RODTEP/export-benefit claim linked to the same order (see
[Government Export Benefits](./ca-07-export-benefits.md)), any Order Cost
Entry, and the order's own Profitability Sheet — so opening one order
shows every financial detail tied to it at a glance, not just the
separate CA-module list screens.

**Not counted twice (owner decision 2026-10-29).** On the order page, a
linked Zoho expense that has the same amount and the same date as a cost
already entered in the app for that order (an order expense, an order cost,
or a supplier / logistics tax invoice) is marked *"possible duplicate of a
cost entered in the app — not counted"*; one not in rupees is marked *"not
in rupees — not counted"*. The CA checks it and unlinks the duplicate or
corrects the app entry. Every other linked Zoho expense **counts in the
order's cost**: the order's total costs and profit, the Order Profitability
report, and section C5 of the CA Financial Annexure (owner decision
2026-10-29, CAFIN change confirmed twice). An expense not linked to any
order (salaries, rent) is never part of an order's cost.

## Export CSV

An **Export CSV** link above the Expenses list downloads every imported
expense in the same columns the on-screen table shows (date, category,
vendor, description, amount/currency, linked order, TDS applicable/
amount) — never a separately computed export, so it can never disagree
with what's on screen.

## What this doesn't do yet

- No expense editing or deletion here — corrections happen in Zoho Books,
  and the next sync brings them over (see "What gets imported").
- No automatic expense/revenue reconciliation against your actual bank
  statement yet — that's a later phase (bank-statement import and
  matching). For now, this page is the expense side of the picture;
  Chapter 2's revenue register and reports are the revenue side.
- No payroll or HR functionality — salary only ever appears here as a
  single expense line, exactly as it's entered in Zoho Books. This system
  deliberately doesn't track individual employees, salary structures, or
  payslips.
