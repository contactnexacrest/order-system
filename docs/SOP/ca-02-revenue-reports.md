# CA / Accounting — Forex, FIRC Tracking & Revenue Reports

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md)
(read that first if you haven't). Phase 2 adds three things to the same
INR Settlement Register: a forex gain/loss figure per settlement leg, a
place to record each leg's FIRC/eBRC realization proof, and a revenue
report you can view by financial year or by calendar year.

## INR credited with the remittance (D-244, 4 Nov 2026)

Since 4 Nov 2026 (owner decision D-244) the person recording a buyer
remittance — advance, freight, balance or a payment milestone — types the
**INR credited by the bank** from the credit advice, and the leg's
exchange rate is **worked out** from it (INR ÷ foreign amount). So:

- The leg's **INR actual** is filled at once — the sum of the receipts'
  INR when the buyer paid in parts. A receipt recorded with **"INR not
  known yet — enter the rate"** leaves it empty (and empties a value
  already there, since it no longer covers the whole leg); enter it in the
  INR actual box once known.
- While the INR actual is empty, the **INR Settlement Register**, the
  revenue report's legs table and the CSV export show the amount at the
  recorded rate, marked **"estimated at recorded rate"** (CSV column
  "INR Estimated (at recorded rate)"). Estimates are never added to the
  INR totals; the summary says how much is estimated.
- When the bank's own rate was typed and the bank kept rupee charges
  ("The bank deducted its charges"), the gap is an order cost **Bank
  Charges** and the leg's rate is the bank's rate; the Zoho Books customer
  payment carries the gap as bank charges, so the deposit equals the INR
  credited. Otherwise the Zoho payment's rate is INR credited ÷ foreign
  amount.
- Because the rate now comes from the INR itself, the **forex gain/(loss)**
  of a leg recorded this way is nil; it still shows a real figure for legs
  recorded with a typed rate and a later INR actual.
- Rupees the bank kept ("The bank deducted its charges") are **not** shown
  as a forex loss (owner, 4 Nov 2026): the register and the revenue report
  label them "₹… bank charges" in the Forex Gain/(Loss) column, the report
  summary shows "Bank Charges Deducted from Credits", and the CSV has a
  column "Bank Charges (deducted from credit)". They are counted once, as
  the order cost Bank Charges.

## Exchange Rate (per settlement leg)

Every order is quoted and settled in a foreign currency, but for
accounting purposes it's useful to know whether the INR your bank actually
credited was better or worse than what was expected when that leg was
priced. That comparison needs a baseline — the leg's own **exchange
rate**.

- Advance, Balance and Freight each have their **own** exchange rate box,
  right alongside that leg's INR Actual widget on the order's Payment
  Status section — not one rate shared by the whole order. Advance and
  Balance can genuinely clear months apart at a different market rate
  each time, so a single order-wide rate would misstate the gain/loss on
  whichever leg it didn't match.
- Each box is titled **"Advance/Balance/Freight Exchange Rate"** —
  visible to anyone with the "View INR actual" permission. It reads back
  the rate recorded **with the remittance itself** ("@ Exchange Rate" on
  the Record Remittance form) — there is no separate "Record/Update Rate"
  box any more, since the rate the payment was received at doesn't change
  later.
- If a rate was simply typed wrong, anyone with "Add/edit INR actual" can
  open the collapsed **"Correct a wrongly typed rate"** under it: enter the
  right rate and a reason (at least 10 characters). The correction is
  audit-logged with the old rate, the new one and the reason. (An older
  entry that has no rate at all shows **"Add the missing rate"** instead.)
- **It never changes what INR actual gets recorded.** The INR actual is
  always the real, bank-confirmed figure (Phase 1). The rate only exists
  to compute what you *expected* to receive for that leg, so the
  register can show the difference.
- It can be corrected at any time by re-submitting the same form — each
  update is audit-logged.

## Forex Gain/(Loss)

Once both a leg's own exchange rate and its INR actual are on record,
the system shows:

> Forex gain/(loss) = INR actual − (foreign amount × that leg's own exchange rate)

This appears directly under the INR actual figure on the order page, and
as its own column in the CA module's INR Settlement Register and revenue
reports. A positive figure means you received more INR than expected
(the currency moved in your favour between quoting and receiving); a
negative figure ("loss") means less. Neither is an error — it's simply
what actually happened to the exchange rate, and it's exactly the number
your CA needs to book as a realized forex gain or loss for that
transaction.

## FIRC / eBRC Reference

Below the INR actual box for each cleared leg, a **FIRC / eBRC Reference**
box lets anyone with "Add/edit INR actual" record the bank's Foreign
Inward Remittance Certificate (or the RBI's electronic Bank Realisation
Certificate) reference number and the date it was received, once the bank
issues it. On a Letter of Credit order, the FIRC / eBRC for the money
the bank paid under the LC is entered under **LC Payment Received** on
the order page (Step 12a) instead — see
[Order Financials](./ca-08-order-financials.md#4-letter-of-credit--bank-loan).

If a cleared leg's FIRC/eBRC reference is still missing after the number
of days configured in **Admin → Company Settings** as `fema_realization_alert_days`
(seeded at 270 days, i.e. roughly the RBI/FEMA export-proceeds realization
window), the CA module's Settlement Register flags that leg with a
**"Pending — flag for follow-up"** badge, so nothing silently falls
through the cracks waiting on the bank to issue the certificate.

This is a practical proof-of-realization reminder, not a legal RBI/EDPMS
shipment-date calculation — the system doesn't currently track the
shipment date that rule technically runs from. Treat the badge as "chase
the bank/forwarder for this certificate," not as a compliance verdict.

## Revenue Report (FY / Calendar Year)

**CA / Accounting → FY / calendar-year revenue reports** opens a report
that:

1. Lets you switch between **Financial Year** (1 April – 31 March, the
   Indian FY) and **Calendar Year**, and pick which one to view — only
   years that actually have cleared settlement legs appear in the picker.
2. Shows a **GST / LUT Export Declaration** block — your company's GSTIN,
   LUT number, and the financial year the LUT is valid for (pulled
   directly from Admin → Company Settings), with a plain statement that every
   revenue entry below is a zero-rated LUT export, not a domestic sale.
3. Shows a **summary**: total settlement legs, total INR actually
   realized, net forex gain/(loss) for the period, and how many legs are
   still missing an INR actual — followed by a breakdown by currency.
4. Lists every settlement leg that falls in the selected period, with its
   INR actual and forex gain/(loss), each linking back to its order.

An **Export CSV** link above that list downloads the same legs, in the
same columns, for the currently selected financial/calendar year — never
a separately computed export, so it can never disagree with what's on
screen.

This report is computed entirely from the same Settlement Register data —
never a separate query — so it can never disagree with what the register
itself shows. It shares only the FY date-bucketing logic with the
order-pipeline Reports module (the "which financial year does this date
fall in" calculation); no tables, queries, or totals are shared between
the two, exactly as required.
