# Stage 8 — Commercial Invoice & Balance Payment

## What this stage is for

Issuing the Commercial Invoice (CI) — the final, legally definitive invoice
for the shipment, replacing the Proforma Invoice — and collecting the
remaining balance payment before the final closure stage.

## The system does this automatically

- The balance amount is already known from the payment preset applied back
  at order creation, so the **Balance Amount Received** field arrives
  pre-filled with the expected figure — staff aren't calculating it by hand,
  though they can still adjust it if what actually landed differs.
- Nothing else progresses without a staff action.

## What staff do

This section bundles two independent things — sending the buyer proof of
shipment, and collecting final payment:

![Stage 8 section — before anything actioned](./images/s8_before.png)

**Mark Scanned BL Copy Sent to Buyer** is a simple confirmation that the
buyer has been sent a copy of the Bill of Lading now that it's been
issued — purely a record-keeping checkbox, it doesn't affect the gate.

**Upload the scanned BL copy first.** On a CFR/CIF order the CI is made
against the BL, so Step 11a shows a reminder until the scanned BL copy is
attached in Step 10f ("Attach Scanned BL Copy"), and a download link to it
once it is. (An FOB order raises its CI before shipment, so no reminder.)

From **Documents**, **Generate Commercial Invoice** produces the CI —
pre-filled with the actual shipped quantities and final figures rather than
the estimates the PI was built on.

The CI's own Section A keeps **FOB Value** as its own separate line
regardless of Incoterm — required for the Indian Shipping Bill and IGST
refund — but on a non-FOB order (CFR/CIF/etc.), **TOTAL COMMERCIAL
INVOICE VALUE** is FOB Value *plus* Freight & Insurance, not FOB Value
alone: the buyer-country customs/import-duty authority assesses duty
against the full CFR/CIF value, and a Commercial Invoice that understates
it risks customs delay or a penalty for the buyer. The Freight & Insurance
figure itself is whichever is on file — the actually-recorded Freight
Debit Note payment if it's cleared, otherwise the Stage 6 confirmed
freight+insurance figure. This only changes the CI; the Quotation,
Proforma Invoice, and Order Confirmation's own "Total ... Value" fields,
and Section B's advance/balance payment math on this same CI, are
unaffected — freight is still paid separately via its own Debit Note, not
folded into the advance/balance schedule. On an FOB order, Total
Commercial Invoice Value is simply the FOB Value, same as before.

The CI's own Section 7 "Balance Due Now" clause states the order's own
payment-preset balance wording — the same sentence the Quotation,
Proforma Invoice, and Buyer PO already print (e.g. "before shipment"
for a Tier 1 preset, "against scanned copy of Bill of Lading" for a
Tier 2 one), including that preset's own day count. External QA report
L-06: this used to hardcode the Tier 2 (against-BL-copy) wording and a
separate company-wide day count on every CI regardless of which preset
the order was actually on, so a Tier 1 order's CI wrongly told the buyer
their balance was due against a scanned BL copy — a term they never
agreed to.

Recording and clearing the balance payment follows the same two-step
pattern as every other payment in this pipeline:

1. **Record Balance Remittance** — amount and date once the buyer's payment
   notification arrives. Refused until the Commercial Invoice above has
   actually been generated — the buyer can't be remitting against an
   invoice they were never sent — and, like every stage-gated action,
   until Stage 8 itself has unlocked.

   ![Stage 8 section — balance recorded, awaiting clearance](./images/s8_recorded_awaiting_clear.png)

2. **Mark Balance Cleared (unlocks Stage 9)** — the confirmation dialog here
   carries an extra warning beyond the usual "confirm the funds have
   landed": *"...and that the BL hasn't been released early if this is a
   post-BL preset"* — a reminder that some payment presets are specifically
   structured so the balance is due against the BL, and releasing the BL to
   the buyer before that payment clears would undermine the whole point of
   that preset. **This is the action that passes Stage 8's gate.**

   ![Stage 8 section — balance payment cleared](./images/s8_cleared.png)

## What the client does (or doesn't)

Nothing inside the system. The buyer receives the scanned BL copy and the
Commercial Invoice through whatever channel staff use to send documents,
and wires the balance payment outside the system — staff verify it against
the bank statement and record it themselves, exactly as with every other
payment stage.

## What happens next

Clearing the balance payment passes Stage 8's gate and unlocks the final
stage:

![Stage tracker — Stage 8 passed, Stage 9 now in progress](./images/s8_stage_tracker_after.png)

See [Chapter 9](./09-stage9-despatch-closure.md) for Document Despatch &
Closure — the final stage.
