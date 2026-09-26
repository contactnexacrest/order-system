# CA / Accounting — Forex, FIRC Tracking & Revenue Reports

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md)
(read that first if you haven't). Phase 2 adds three things to the same
INR Settlement Register: a forex gain/loss figure per settlement leg, a
place to record each leg's FIRC/eBRC realization proof, and a revenue
report you can view by financial year or by calendar year.

## Assumed Exchange Rate

Every order is quoted and settled in a foreign currency, but for
accounting purposes it's useful to know whether the INR your bank actually
credited was better or worse than what was expected when the order was
priced. That comparison needs a baseline — the **assumed exchange rate**.

- This is entered once per order (not once per leg), on the order's
  Payment Status section, in a box titled **"Assumed Exchange Rate
  (CA/Accounting)"** — visible to anyone with the "View INR actual"
  permission, editable by anyone with "Add/edit INR actual".
- It's the INR-per-unit-foreign-currency rate you're booking the order
  against — typically entered at quotation or PI stage, whenever your
  accounts team fixes a working rate for that order.
- **It never changes what INR actual gets recorded.** The INR actual is
  always the real, bank-confirmed figure (Phase 1). The assumed rate only
  exists to compute what you *expected* to receive, so the register can
  show the difference.
- It can be corrected at any time by re-submitting the same form — each
  update is audit-logged.

## Forex Gain/(Loss)

Once both the assumed exchange rate and the INR actual are on record for
a leg, the system shows:

> Forex gain/(loss) = INR actual − (foreign amount × assumed exchange rate)

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
issues it.

If a cleared leg's FIRC/eBRC reference is still missing after the number
of days configured in **Admin → Settings** as `fema_realization_alert_days`
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
   directly from Admin → Settings), with a plain statement that every
   revenue entry below is a zero-rated LUT export, not a domestic sale.
3. Shows a **summary**: total settlement legs, total INR actually
   realized, net forex gain/(loss) for the period, and how many legs are
   still missing an INR actual — followed by a breakdown by currency.
4. Lists every settlement leg that falls in the selected period, with its
   INR actual and forex gain/(loss), each linking back to its order.

This report is computed entirely from the same Settlement Register data —
never a separate query — so it can never disagree with what the register
itself shows. It shares only the FY date-bucketing logic with the
order-pipeline Reports module (the "which financial year does this date
fall in" calculation); no tables, queries, or totals are shared between
the two, exactly as required.
