# CA / Accounting — Bank Statement Reconciliation

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md),
[Forex, FIRC Tracking & Revenue Reports](./ca-02-revenue-reports.md),
[Zoho Books Sync](./ca-03-zoho-sync.md), and [Expenses](./ca-04-expenses.md)
(read those first if you haven't). Phase 5 closes the loop between what
this system and Zoho Books say happened, and what the bank actually shows —
by importing the real bank statement and matching it line by line against
recorded revenue and expenses.

## Why this exists

Everything up to this point — the INR settlement register, the Zoho Books
sync, the imported expenses — is what this system *believes* happened.
None of it is checked against the bank itself. **CA / Accounting → Bank
Statement** is that check: upload the actual bank statement, and see which
recorded amounts genuinely show up in it, and which don't.

## How the bank statement gets in

There is no bank API connection and no manual line-by-line entry. You
export a CSV statement from your bank's own portal and upload it on the
**Bank Statement** page. This system reads common column-name variants —
Date, Description/Narration/Particulars, Reference/UTR, and either separate
Credit/Debit columns or a single Amount column paired with a Dr/Cr Type
column — so most banks' exports work without any reformatting. If a
statement's headers don't match, the upload is rejected with an explanatory
error message rather than silently importing garbage; that bank's column names
just need to be recognized, which is a small code change, not a redesign.

Uploading the same statement twice, or two exports with overlapping date
ranges, never creates duplicate lines — each line is fingerprinted from its
date, description, reference, and amounts, so an already-imported line is
silently skipped on a repeat upload.

## Matching a line

Every imported line starts unmatched. On the Bank Statement page, each
unmatched line with a credit amount gets a dropdown of every settlement leg
(advance/balance/freight) that has an INR actual on record but isn't
matched to some other bank line yet; each unmatched line with a debit
amount gets the same for imported expenses. Picking one and clicking
**Match** links them. A line can be unmatched again at any time, freeing up
both sides to be matched differently if a mistake was made.

A line matches to **at most one thing** — either a revenue leg or an
expense, never both, and never two revenue legs. This is enforced in the
matching logic itself (matching to one side clears the other) rather than
at the database level, consistent with how the rest of this schema
prefers application-level invariants over hard database constraints where
the shape of the data makes either approach workable.

## Reading the Reconciliation Summary

**CA / Accounting → Reconciliation** shows four things, all **all-time**
totals rather than filtered to a financial year or date range (see "What
this doesn't do yet" below):

- Revenue and expense totals as recorded in this system, side by side with
  the bank statement's own credit/debit totals and how much of each has
  actually been matched.
- Every recorded INR actual that has no matching bank line yet.
- Every imported expense that has no matching bank line yet.
- Every imported bank line that hasn't been matched to anything.

A CA reviewing this page is looking for two kinds of gaps: revenue or
expenses recorded here that never actually hit the bank (a sign something
was recorded in error, or hasn't cleared yet), and bank lines that don't
correspond to anything recorded (unrecognized income, an expense missed in
the Zoho Books sync, or simply a bank line that predates this system's
records).

## What this doesn't do yet

- The reconciliation summary is all-time only — it doesn't yet break
  totals down by financial year or by date range the way Chapter 2's
  revenue report does. For now, treat it as a running, cumulative check
  rather than a period-close report.
- No automatic matching — every match is a deliberate choice made on the
  Bank Statement page. There's no attempt to guess a match from the
  amount or date, even when there's only one plausible candidate.
- No support for a bank API feed — this is CSV-import only, by deliberate
  choice (see the module's original brief): it keeps this system fully
  independent of any bank's own integration and its uptime.
