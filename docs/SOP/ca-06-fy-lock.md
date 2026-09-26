# CA / Accounting — Financial Year Lock

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md),
[Forex, FIRC Tracking & Revenue Reports](./ca-02-revenue-reports.md),
[Zoho Books Sync](./ca-03-zoho-sync.md), [Expenses](./ca-04-expenses.md),
and [Bank Statement Reconciliation](./ca-05-bank-reconciliation.md) (read
those first if you haven't). Phase 6 is the year-end control that ties the
whole module together: once a CA has closed the books for a financial
year, this system stops accepting any further changes to that year's
figures.

## Why this exists

Every earlier phase in this module records CA data against a date —
a settlement leg's cleared-on date, an expense's expense date, a bank
statement line's transaction date. Without a lock, any of that data
could still be edited or newly matched at any time, even years after a
CA has signed off the annual return for that period. A "backdated entry"
made after close — a forgotten INR actual finally entered, an old exchange
rate corrected, a stale bank line finally matched — would silently change
numbers a CA has already certified. The Financial Year Lock exists purely
to make that impossible without a deliberate, logged decision to reopen
the year first.

## Locking a year

**CA / Accounting → Financial Year Lock** lists every financial year with
any CA activity (revenue or expenses) plus the current year, each marked
**Open** or **Locked**. Locking one is a single click, gated on the "Manage
CA / Accounting integrations" permission — the same administrative tier
that gates the Zoho Books sync page, since closing a year is an
administrative decision, not routine day-to-day data entry.

## What gets blocked once a year is locked

Every CA write path checks the date of the record it's about to touch —
never today's date, always the record's own date — against the lock list.
If that date falls in a locked year, the action is refused with a message
naming the year and pointing back to this page:

- Recording, correcting, or removing an advance/balance/freight leg's
  **INR actual** (blocked against that leg's cleared-on date).
- Recording or updating the **assumed exchange rate** for an order
  (blocked if *any* of its cleared legs falls in a locked year, since the
  rate feeds every leg's forex gain/loss figure at once).
- Recording a **FIRC/eBRC reference** for a leg (blocked against that
  leg's cleared-on date).
- Setting an expense's **TDS annotation** (blocked against the expense's
  own date).
- **Matching or unmatching** a bank statement line against a revenue leg
  or an expense (blocked against whichever date applies) — and a
  locked-year leg or expense is left off the matching dropdowns
  altogether, so there's nothing to even attempt matching against a
  closed year.

Everywhere one of these is blocked, the page shows a lock notice in place
of the edit form rather than simply hiding the control, so it's always
clear *why* something can no longer be changed.

Note what this does **not** touch: the order pipeline itself (stage
gates, marking a payment cleared, generating documents) is untouched by
any financial year lock — the lock is scoped entirely to this module's
own bookkeeping data, consistent with the CA module's independence from
the order pipeline established in Chapter 1.

## Reopening a year

Unlocking requires a reason — the text box is mandatory, so there's
always a written note explaining why a closed year was reopened. Once
reopened, every write path above works normally again for that year until
it's locked again.

## The lock/unlock history

The same page's **History** table is a complete, append-only record of
every lock and every unlock for every financial year: who locked it and
when, who unlocked it, when, and why. Re-locking a year after an unlock
adds a fresh row rather than overwriting the old one, so nothing about a
year's lock history is ever lost — independent of, and never touching,
the main system audit log.
