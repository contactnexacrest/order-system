# Stage 6 — Freight Payment

## What this stage is for

Collecting the freight and insurance charges from the buyer on **CFR and
CIF orders only** — where NexaCrest arranges and pays the shipping line,
then recovers that cost from the buyer via a separate Debit Note. On an
FOB order this whole stage is irrelevant, since the buyer books and pays
for their own freight directly.

**Insurance is CIF only** (owner decision, QA Round 5, Q-R5-2). Under
Incoterms 2020 a CFR seller pays freight only and the buyer insures the
cargo. So on a CFR order the Insurance Amount box is not shown, the Debit
Note and its payment link ask for freight only, the Quotation and
Proforma Invoice print insurance as "NIL — buyer arranges insurance
(CFR)" and "Buyer's responsibility", and the Commercial Invoice says
"Freight" instead of "Freight & Insurance". An insurance figure stored on
a CFR order before this rule is ignored everywhere.

The internal code for this document type stays **FDN** (the **Generate
Freight Debit Note** button, the reference number's old `SC/FDN/…`
pattern pre-dating this change, and this chapter's own wording below all
still say "Freight Debit Note") — only the printed document's own title
changed, to plain **"Debit Note"**, since it's a buyer-facing paper and
"Freight" in the name was confusing it with a line item rather than the
document itself. New reference numbers generated after the rename use
`SC/DN/{YY}/{DDMM}{NNN}` instead of `SC/FDN/…`; a Debit Note generated
before the rename keeps its original `SC/FDN/…` reference — it is never
retroactively renumbered.

> This chapter uses a **second example order** (a CIF order to a Norwegian
> buyer) rather than the main running example — SC/ORD/2026/001-2 is on FOB
> terms, so Stage 6 auto-skips for it entirely (see the end of
> [Chapter 5](./05-stage5-supplier-po.md)). Everything else about this
> second order's journey through Stages 1–5 is identical to what the
> earlier chapters already covered.

## The system does this automatically

- **The FOB/CFR/CIF branch is decided automatically**, the instant Stage
  5's gate passes — staff never choose whether to skip this stage; the
  order's own Incoterm decides it. See [Chapter 5](./05-stage5-supplier-po.md)
  for the FOB skip path.
- Nothing else progresses without a staff action — there's no client
  involvement at this stage in either direction.

## What staff do

On a CFR/CIF order, Stage 6 unlocks as a normal stage once Stage 5 passes:

![Stage 6 section — unlocked, no freight terms recorded yet](./images/s6_before_terms.png)

**Save Freight Terms** records the rate NexaCrest actually agreed with the
shipping line/forwarder (confirmed freight rate, insurance amount on CIF
orders — the box is shown on CIF orders only) and the **Freight Forwarder**, chosen from the **Logistics
Partners** directory like the supplier in Step 7a comes from the
Suppliers directory (owner request 2026-10-27). The directory has a
**Freight Forwarder** service type for them (alongside CHA and
Transportation) — add one at Logistics Partners → Add Partner. Their name
and contact are copied onto the order when the terms are saved. This
is internal record-keeping only and doesn't pass any gate. From **Documents**, **Generate Freight Debit
Note** then produces the FDN — the actual invoice sent to the buyer for
these charges.

The Debit Note itself states payment is due within **3 Calendar Days**
of its own date (previously "3 working days" — every buyer payment
obligation across the system now counts in Calendar Days, not Working
Days, for consistency). The "by [date]" it prints is that date plus 3
calendar days, and the overdue-freight alert to MD/Admin fires the day
after it (owner decision 2026-10-28 — both used to count working days, so
the printed date could fall later than the 3 Calendar Days stated beside
it). Its GST/IGST line always reads "NIL — Freight
recovery is a pure cost reimbursement, not a taxable service supply." This
is a fixed, non-configurable statement, not a per-order choice — freight
recovery is never treated as a taxable service supply, so there is no
Freight Terms setting for it to depend on, and no internal CA guidance
text ever reaches a document the buyer actually sees.

Recording and clearing the payment itself follows the exact same
two-step pattern as the advance payment in Stage 3:

1. **Record Freight Remittance** — the amount and date once the buyer's
   payment notification comes in. Does not pass the gate yet:

   ![Stage 6 section — freight recorded, awaiting clearance](./images/s6_recorded_awaiting_clear.png)

2. **Mark Freight Cleared (unlocks Stage 7)** — only once the funds are
   verified against the actual bank statement, same caution as every other
   payment-clearing action in this system. **This is the action that passes
   Stage 6's gate.**

   ![Stage 6 section — freight payment cleared](./images/s6_cleared.png)

## Paying the logistics partners (CHA, transport, forwarder)

The **Logistics Partner Bills & Payments** card (in section C, alongside
Steps 9–11 — it never locks them) records what NexaCrest pays its own
logistics partners, as opposed to the freight the buyer pays NexaCrest.
It is shown to staff with the **Manage supplier payments** or **Order
Financials** permission; bills are added and paid by **Manage supplier
payments** holders (Admin, MD, ED and Accounts Executive by default).
CHA/logistics payments are made in India.

**+ Add a bill** — one row per bill from the partner (a partner doing both
CHA and transport can send one combined bill or separate bills):

- **Logistics partner** (from the Logistics Partners directory) and
  **For** — CHA charges, Transport, CHA + Transport (one bill), Ocean
  freight or Other;
- **Payment** — **Advance** (paid before the service) or **Final**
  (against the completed service) (owner decision 2026-10-27);
- **Bill type** (owner decision 2026-10-28, Option B) — **Proforma /
  advance request** (asks for money now — not a cost; enter the full
  charges and, in **Amount asked now**, the part asked for now) or **Tax
  invoice / final bill** (the cost). A tax invoice can be linked to an
  earlier proforma from the same partner, so what was paid on the
  proforma counts towards it and the proforma stops counting;
- bill number, bill date, taxable value, **GST rate (%)** — GST (₹) and
  the bill total fill in by themselves — the bill file and a note.
- **GST on this bill** (Transport bills only, CA advice 2026-10-29) —
  **Charged on the bill** (the usual case) or **Reverse charge — no GST on the bill**, for a road transporter whose bill
  shows no GST: then pick GST rate **0 %**. We pay the 5 % to the
  government ourselves and claim it back; Zoho Books books it that way
  (see CA-03). The bill shows "Reverse charge" in the list.

Each bill then gets the green **₹ Mark Paid** button (amount, date, mode,
UTR), exactly like the supplier bills in Steps 7e / 10a: prefilled with
what is still due, never more, and a bill can be paid in parts (**₹ Record
another payment**).

**When a bill can be paid:**

| Order | Advance bill | Final bill |
|---|---|---|
| CFR / CIF | any time | only after the buyer's Debit Note payment has cleared (**Step 9d**) |
| FOB | any time | any time |

Until 9d, a Final bill shows no Mark Paid and the card explains why;
Advance bills stay payable. Bills recorded before this choice existed
count as Final, and as tax invoices. The table tags each row **Advance**
or **Final** and **Proforma** or **Tax invoice**, and totals the cost
(tax invoices, plus what is paid on a proforma until its tax invoice
arrives), what is paid and what is still due. Every logistics bill flows into the
order's financials (Step 12) as logistics cost.

## What the client does (or doesn't)

The buyer pays the freight charge by wire transfer against the Debit Note.
The Debit Note email carries the **Payment Action Link** (see
[Chapter 3](./03-stage3-pi-advance.md#the-payment-action-link-owner-request-2026-10-27)),
where the buyer downloads the Debit Note and sends the payment screenshot
and transaction details; staff can also send the link from Step 9c. Staff
still verify against the bank statement and record it themselves.

## What happens next

Clearing the freight payment passes Stage 6's gate and unlocks Stage 7:

![Stage tracker — Stage 6 passed on this CIF order, Stage 7 now in progress](./images/s6_stage_tracker_after.png)

This is the same place the FOB path from Chapter 5 also arrives at — from
here on, FOB and CFR/CIF orders follow an identical process. See
[Chapter 7](./07-stage7-packing-bl.md) for Packing & BL Instruction.
