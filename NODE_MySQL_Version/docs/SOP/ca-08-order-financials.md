# CA / Accounting — Order Financials, Reorder-to-Supplier Linking & Profitability

## What this chapter covers

This builds on [CA / Accounting — Overview & Permissions](./ca-01-overview.md),
[Government Export Benefits](./ca-07-export-benefits.md), and
[Expenses](./ca-04-expenses.md) (read those first if you haven't). It
covers everything added under docs/schema.sql Section AP:

1. The **Order Financials** box on the order's own page — a stricter,
   read-write version of the Phase 1 read-only box, gated on a new
   permission.
2. **Order Cost Entries** — a small table of manually-recorded costs
   (CHA, documentation, port charges, bank charges, commission, ECGC
   insurance, buyer due diligence, and more) not already tracked
   anywhere else in the system.
3. The **Order Profitability Sheet** — revenue vs. every direct cost for
   one order, down to a profit figure and margin %, and its own report
   rolling that up across orders for any date range.
4. **Reorder-to-supplier linking** — when a repeat order is created (by
   staff directly, or by approving a client's reorder request), its
   source order's Supplier PO is automatically carried forward as a
   draft.
5. The CA Financial Annexure (`CAFIN`) document, extended to also show
   supplier cost and the profitability summary.

## Why this is a stricter permission than the rest of the CA module

Every other CA-module screen is gated on `ca_module_view` (or
`inr_actual_edit`/`inr_actual_delete` for writes) — deliberately broad,
since a CA or Accounts Executive needs to see and enter this data as
their day-to-day job. Order Financials is different: it surfaces
**per-order margin data** — what NexaCrest actually pays for materials
and what it actually makes on a shipment — which many businesses treat
as more sensitive than raw revenue or expense figures on their own.

`manage_order_financials` (see the permission table in
[Overview](./ca-01-overview.md#roles-and-permissions)) is **not**
auto-granted to the Accounts Executive or CA / Chartered Accountant
roles, unlike `ca_module_view`/`inr_actual_edit`/`inr_actual_delete`.
Only Admin, Managing Director, Executive Director, and Super Admin hold
it by default — the same tier as `ca_internal_doc_manage`. Anyone else
needs an explicit grant via **Admin → Permissions**
or a per-user override.

## The Order Financials box (order's own page)

Anyone holding `manage_order_financials` sees a section titled **Closure
— Financials, Benefits & Costs** (Step 12 on the order's own page) on the
order's own page, just below Payment Status. **Owner decision
(2026-10-26):** it is laid out like every other step — four guided
sub-steps, worked top to bottom, with the next unfinished one opened for
you (they never lock each other, because benefit money can arrive months
later):

| Sub-step | What you do | Done when |
|---|---|---|
| **12a · Order Costs** | One table of every cost on the order, and a **+ Add a cost** button that opens and closes like **+ Add an expense** (owner request 2026-10-27), with a type picker inside (other cost, travel, inspection fee, LC/bank loan — each opens its own short form; parts 4–6 below). Actual order expenses with receipts are added under **+ Add an expense** (see "Order expenses" below); supplier and logistics bills come in by themselves. Expenses booked in Zoho Books and linked to the order are listed under it and count in its cost, except a possible duplicate (part 3 below). | You click **Costs are complete** (you can still add a cost later, or **Reopen costs**). |
| **12b · Export Benefit Eligibility** | Answer Eligible / Not Eligible for every scheme (part 1). | No scheme is still Pending. |
| **12c · Review Profit & Complete** | Read the Profitability Summary (part 7), generate the Internal Annexure if needed, then **Mark Step 12 complete** (needs 12a and 12b done). | Marked complete — or skipped with a reason. |
| **12d · Team Incentive** (only people with "Manage team incentives": the owner and the MD) | The order's team incentive and any associate commission: the amount set on this order or the reward table, who gets what, Exclude, and associate commission Automatic / Yes / No. Fixed when the buyer has fully paid; it then shows in 12a as the order's **Commission** cost lines and goes to Zoho Books once paid. See [chapter 20](./20-incentives.md). | Nothing to tick: it is fixed by itself when the buyer has fully paid. |

**Government benefits claimed & received moved to Step 13g** (owner
decision 2026-10-27) — just before Mark Fully Settled, because the money
usually lands after the buyer has paid in full. See part 2 below.

Above the sub-steps, four numbers give the order's money picture at a
glance: **received from buyer**, **total costs**, **govt benefits
received** (of claimed), and **net profit** with margin. Step 12's own
status icon turns ✓ once 12c is marked complete (or skipped).

The parts below describe each piece in detail.

**Nothing to record? Skip it.** At the bottom of 12c,
**Skip financials — mark Step 12 complete** (reason of at least 10
characters, audit-logged) marks Step 12 done **without writing any
figure** — costs, benefits and expenses stay blank rather than being
saved as 0, so reports never mistake "not recorded" for "zero". Figures
can still be added later, and **Reopen Step 12** undoes the skip. The
export-benefit schemes still have to be answered before the order can be
marked fully settled.

### 1. Export Benefit Scheme Eligibility

Not every order qualifies for every government export-benefit scheme, so
**a real row for every active scheme is written the moment the order is
created** (RODTEP, Duty Drawback, RoSCTL, Other by default — Admin can
add more schemes the same way as any other dropdown option) — never left
as a missing row standing in for "not applicable," the same
always-materialized pattern the Compliance Checklist uses (see [Stage
9](./09-stage9-despatch-closure.md#compliance-checklist)). Each row
starts **Pending** and is resolved to either **Eligible** (then a claim
can be recorded against it in "Government Export Benefits Claimed"
below) or **Not Eligible** (a reason is required, same "not applicable
still needs recording" rule as everywhere else in this system; choosing
Not Eligible fills in "Not eligible for this order." for you, still
editable — owner request 2026-10-27) — there
is no path back to Pending once resolved.

All schemes are edited in **one table and saved together** with **Save
All Schemes** (owner request 2026-10-26): each row shows its current
value from the database, a row's reason is required (10+ characters)
only while it's set to Not Eligible, rows left Pending are untouched,
and only the rows you actually changed are updated. If any row is
invalid, nothing is saved and the message names each scheme to fix.

Resolving a scheme is logged to the order's own
[Audit Log](./13-document-review-approval.md) under
`EXPORT_BENEFIT_SCHEME_STATUS_UPDATED`. **Closing the order (Mark Fully
Settled, Stage 9's second phase) is blocked while any scheme here is
still Pending** — see [Stage
9](./09-stage9-despatch-closure.md#closing-the-order--two-phases) for
the full list of what Mark Fully Settled checks, in order.

### 2. Government Export Benefits Claimed

Step 13g (moved from Step 12 — owner decision 2026-10-27). Every
RODTEP/export-benefit claim linked to this order (see
[Government Export Benefits](./ca-07-export-benefits.md)) is a row of one
table, with an empty row for each eligible scheme not claimed yet — type
the claim (reference, amount, date) and, when it arrives, the **received
amount and date** on the same row, then **Save Benefits**. It opens for
claims once the documents are sent and the balance remittance is
received. **Record another claim** (under the table) covers a second
claim on the same scheme or a scheme not listed. An eligible scheme the
company won't claim is **skipped with a reason** ("Skip — not claiming",
with **Undo skip**). 13g is done — and 13h Mark Fully Settled opens —
once every eligible scheme is received or skipped.

**Typed a wrong amount, or recorded a claim twice?** Open **Correct or
delete a recorded claim** under the table (owner request 2026-10-27). Each
claim there can be corrected (claimed amount, date, reference, received
amount and date — empty the received amount if the money hasn't actually
arrived) with **Save Correction**, or removed with **Delete Claim**. Both
need a reason of at least 10 characters, both are written to the audit log
with the old and new figures, and both respect the CA financial-year lock.

### Order expenses — actual, with receipts (owner decisions 2026-10-27)

Step 12a lists the order's **actual expenses** and has **+ Add an
expense** (open by default while none are recorded). Each expense has:

- **Date** (today or earlier) and **Category** — Travel — fuel / tolls,
  Travel — train / flight / bus, Local conveyance (taxi / auto), Hotel,
  Food, Courier, Samples, Other;
- **Details** and **Amount (₹)**;
- **Paid by** — Company account, Company card, or Staff (to reimburse),
  with the staff member's name;
- the **receipt / bill** — or, only when no bill exists, a reason of at
  least 10 characters.

Dates inside a locked financial year are refused (see [FY
lock](./ca-06-fy-lock.md)). A voucher entered more than 30 days after the
expense keeps its real date and is marked "added later". Staff-paid
amounts are totalled as "to reimburse".

Once Zoho Books is switched on, each expense goes to Books on the next
sync. **Removing** one that is already in Books removes it from Books too
(owner decision 2026-10-29): the confirmation says so, and if Books cannot
be reached at that moment nothing is removed and the message says why. An
expense changed or deleted directly in Books is put back from the app on
the next sync (see [CA-03](./ca-03-zoho-sync.md)).

**Travel:** the Travel Expense % (part 5) is a **provisional** budget.
Once any actual travel expense is recorded (fuel/tolls, tickets, local
conveyance, hotel or food), the actual figures replace the provisional %
in the profit — the summary then says "travel % replaced by actual
travel".

**Supplier and logistics bills** (Steps 7e / 10a and the Logistics
Partner Bills card — see [Chapter 5](./05-stage5-supplier-po.md) and
[Chapter 6](./06-stage6-freight.md)) flow into the profit by themselves:
CHA / transport / other bills as **Logistics Bills — CHA / Transport**,
and ocean-freight bills replace the Step 9 freight quote as **Ocean
Freight — from the forwarder's bills**. GST on every bill is picked as a
rate (%), with the amount worked out automatically. Owner decision
(2026-10-28, Option B): only **tax invoices** count at their total; a
**proforma** counts only for what was paid on it, and not at all once its
tax invoice is added — so a proforma and the invoice for the same charges
are never both counted. The ledger shows one line per payment, and the
CAFIN annexure prints each bill as "Proforma …" or "Tax invoice …" with
every payment against it.

### 3. Expenses Imported from Zoho Books

Every expense linked to this order (see
[Expenses](./ca-04-expenses.md#linking-an-expense-to-the-order-it-belongs-to)).
Read-only here — linking/unlinking an expense is still done from the
Expenses register, since that's also where the TDS annotation lives. An
expense whose Reference # in Zoho Books names this order is linked by
itself (owner decision 2026-10-29).

**Not counted twice (owner decision 2026-10-29).** A linked Zoho expense
with the same amount and date as a cost already entered here (an order
expense, an order cost, or a supplier / logistics tax invoice) shows the
note *"possible duplicate of a cost entered in the app — not counted"*;
one not in rupees shows *"not in rupees — not counted"*. Every other
linked Zoho expense counts in the order's cost and profit, and in section
C5 of the CA Financial Annexure (owner decision 2026-10-29, CAFIN change
confirmed twice); the Step 12c summary shows the total as **Expenses Booked
in Zoho Books (INR)**.

### 4. Letter of Credit & Bank Loan

Only relevant if the buyer's payment method for this order is a Letter
of Credit. A small **LC Number** / **LC Document** (upload) / **Bank
Loan Amount** / **Interest Rate** / **Total Interest Paid** form, shown
as read values once saved with an **Edit** link to revise them. Interest
Rate is a plain percentage, editable at any time as the bank's own
figure is confirmed or changes.

Saving a **Total Interest Paid** amount here automatically keeps a
matching **Bank Loan Interest (LC order)** row in Other Order Costs
(below) in sync — editing the cost-entries table directly for this one
category isn't needed; change the figure here and the cost entry
updates itself.

**LC Payment Received** (owner decision 2026-10-29) — shown in Step 12a
on an LC order once the LC is confirmed usable: when the bank pays under
the LC, record the **date the bank paid**, the **amount** in the order
currency, the **rupees credited** (from the bank's credit advice) and the
**bank charges deducted**. It goes to Zoho Books as the buyer's payment
against the Commercial Invoice (see
[CA-03](./ca-03-zoho-sync.md)); until then it can be corrected here, after
that only in Books.

The LC payment is **money received from the buyer** (owner decision
2026-10-29, CAFIN change confirmed twice): it counts in the order's
revenue at the rupees credited (so the bank charges are already out of the
profit), in the cash position, as the first line of "Money received from
the buyer" in the CA Financial Annexure, and as its own *LC payment* line
in the INR Settlement Register (chapter CA-01). Once the bank issues the
FIRC / eBRC for it, enter its **reference** and **date** in the small
**FIRC / eBRC** form under the LC payment (users who may edit INR actual
amounts).

### 5. Travel Expenses — Factory Visits

Every order needs several trips to the factory during production (a
minimum ~175 km round trip is the baseline the business works from).
Rather than itemizing each specific trip, staff record the one-way
**Factory Distance** (km, for reference only — it doesn't drive the
calculation) and choose a **Travel Expense %** of the order's FOB value
to charge: **5%, 7%, 10%, 15%**, or **Other** for a custom figure when
none of the presets fit. The system computes and records the resulting
amount automatically from that percentage and the order's own FOB
value — staff never calculate it by hand.

Like the LC/bank-loan interest above, saving this automatically keeps a
matching **Travel Expenses (Factory Visits)** row in Other Order Costs
(below) in sync; it is never entered twice. See also
[Stage 5](./05-stage5-supplier-po.md#production--estimated-shipment--and-where-its-history-lives),
where production progress is tracked for the same order during this
same window.

### 6. Other Order Costs (Order Cost Entries)

A small table of costs this order incurred that aren't tracked anywhere
else in the system:

| Category | Meaning |
|---|---|
| ECGC Insurance | Export Credit Guarantee Corporation cover for this shipment/buyer. |
| Buyer Due Diligence | The cost of verifying a buyer before extending credit terms. |
| Inland Transportation | Domestic freight to the port, before ocean freight. |
| CHA Charges | Customs House Agent fees. |
| Documentation | Certificate of Origin, chamber attestation, and similar document fees. |
| Port Charges | Terminal handling and similar port-side charges. |
| Bank Charges | Charges the bank deducts on an inward/outward remittance. |
| Commission | Any agent/broker commission on this order. |
| Packing / Wooden Crates | Export packing beyond what's already captured elsewhere. |
| Bank Loan Interest (LC order) | Auto-synced from Letter of Credit & Bank Loan above — see there. |
| Pre-Shipment Inspection Fee | The 3rd-party inspection fee tied to this order's Pre-Shipment Inspection compliance task. |
| Travel Expenses (Factory Visits) | Auto-synced from Travel Expenses above — see there. |
| Other | Anything not covered above. |

Each entry records a category, amount (INR), date, what it was **paid
from** (company account or company card — owner decision 2026-10-29),
and an optional free-text description, and can be removed with a
**Remove** button (a
correction, not a routine action — same reasoning as INR-actual delete
being its own permission elsewhere in this module). The three
auto-synced categories (Bank Loan Interest, Travel Expenses) are driven
from their own forms above rather than typed here directly, but still
show up in this same table and count the same way toward the
Profitability Summary below.

**Zoho Books (owner decision 2026-10-29).** ECGC, due diligence,
documentation, commission, packing, the inspection fee and Other go to
Zoho Books as expenses on the next sync (the table says "In Zoho Books"
once sent); removing one here removes it there too. CHA, inland
transport, port charges, bank charges, bank-loan interest and the travel
% are not sent — the table says why under each. CHA, transport and port
charges belong on logistics partner bills instead. The pre-shipment
inspection fee's own form asks what it was paid from when NexaCrest
pays. See [CA-03](./ca-03-zoho-sync.md).

**Supplier cost, ocean freight, and insurance are never entered here** —
see "What feeds the Profitability Sheet" below for where those come
from automatically.

### 7. Profitability Summary

A live summary computed from everything above plus the order's own
Supplier PO and freight/insurance figures — see the next section for the
exact calculation. If revenue is currently an estimate rather than a
settled figure, a note says so and links to the
[Order Profitability report](#the-order-profitability-report) for every
order side by side.

## The Order Profitability Sheet — how each figure is computed

The Profitability Sheet deliberately **reuses figures the system already
tracks** rather than asking staff to re-enter them:

- **Revenue.** This order's product FOB value, converted to INR, using a
  rule so a viewer always knows whether a figure is exact or an estimate.
  Advance and Balance each carry their **own** exchange rate (not one
  rate shared by the whole order — see
  [Forex, FIRC Tracking & Revenue Reports](./ca-02-revenue-reports.md#exchange-rate-per-settlement-leg)):
  1. If **both** legs have already cleared with their real INR actual
     recorded (see
     [Overview — Recording an INR actual amount](./ca-01-overview.md#recording-an-inr-actual-amount)),
     revenue is their exact sum — never an estimate.
  2. Before the advance/balance amounts are even split out (a pre-PI
     order), revenue falls back to the full FOB value × whichever leg's
     rate is on file — flagged as **estimated**.
  3. Once split, a leg that has already cleared contributes its real INR
     actual; a leg that hasn't yet contributes its own amount × its own
     rate. A real figure for one leg is never re-estimated just because
     the other leg hasn't cleared — only the outstanding leg is
     estimated, using its own rate, not the other leg's.
  4. If nothing exists yet for either leg, revenue is shown as zero, also
     flagged estimated.
  5. On a **CFR/CIF order**, the freight remittance leg (Stage 6 —
     [Freight Payment](./06-stage6-freight.md)) is folded into revenue
     too, using the same rule: a cleared leg contributes its real INR
     actual, an outstanding one contributes its own amount × its own
     rate as an estimate. This is the buyer's reimbursement for the
     freight NexaCrest pays the shipping line, and it was previously
     missing from this total even though the matching freight cost
     below was always deducted — understating profit on every CFR/CIF
     order by the exact freight amount recovered. On an **FOB** order
     this leg is never recorded at all (Stage 6 auto-skips), so it
     contributes nothing and never marks revenue estimated on its own
     account.
  6. On an **LC order**, the LC payment the bank made (section 4 above)
     counts at the rupees credited, in place of the first buyer leg no
     money has come in for yet: the advance, otherwise the balance (an
     order switched to LC after its cash advance came in). If both had
     already come in, it is added on top. Revenue therefore always equals
     the money received from the buyer (owner decision 2026-10-29, CAFIN
     change confirmed twice).
- **Government export benefits.** Every claim linked to this order (see
  "Government Export Benefits Claimed" above). A claim already marked
  **received** contributes its real `received_amount`; one still only
  **claimed** contributes its `claimed_amount` as an estimate, flagging
  the figure estimated — the same settled-vs-estimated convention
  revenue itself uses. Like freight/insurance, these are summed as INR
  directly: `ca_export_benefits` has no exchange-rate column of its own,
  and in practice every scheme (RODTEP and similar) pays out in INR.
- **Supplier cost** — `order_supplier_po.total_payable_inr`, already in
  INR, pulled automatically. Nothing to enter here.
- **Ocean freight and insurance** — `order_freight.confirmed_freight_rate`
  and `insurance_amount`, pulled automatically. Insurance counts on CIF
  orders only; on FOB and CFR the buyer insures (QA Round 5, Q-R5-2). **Assumption** (not
  verified against a real invoice): both are treated as INR figures,
  the same convention `order_supplier_po` already uses, since in
  practice NexaCrest pays its freight forwarder domestically. If a
  forwarder is ever paid in a foreign currency instead, the
  profitability figures for that order will be off by whatever the
  actual conversion difference is — there is currently no separate
  foreign-currency freight cost field to handle that case.
- **Other order costs** — the sum of every Order Cost Entry above.
- **Expenses booked in Zoho Books** — the linked Zoho expenses that count
  (section 3 above: not a possible duplicate, in rupees).
- **Total order cost** = supplier cost + freight + insurance + other
  costs + logistics bills + order expenses + expenses booked in Zoho
  Books.
- **Order profit** = revenue + government export benefits − total order
  cost.
- **Order margin %** = profit ÷ revenue × 100, shown as "—" (not 0%)
  when revenue is zero, since a margin percentage is meaningless without
  a revenue base to divide by.

This is the **one place** in the system this calculation is done — the
order page's Profitability Summary, the CA Financial Annexure's
Profitability section, and the Order Profitability report (below) all
call the same underlying calculation, so they can never quietly diverge
from each other.

## The Order Profitability report

**Reports → Order Profitability** (also gated on `manage_order_financials`,
not the general `view_reports` — same reasoning as the order-page box).
Filter by a creation-date range and get a totals row plus one row per
order in that range: revenue, total cost, profit, and margin %, with an
"(est.)" marker on any row whose revenue is still an estimate. CSV export
follows the same convention as every other report in this system.

There's no separate screen for "monthly," "quarterly," "half-yearly," or
"financial year" views — pick April–March for an FY, any three months for
a quarter, and so on. It's the same date-range filter every other report
in this system already uses.

## Reorder-to-supplier linking

Before this, creating a repeat order (via staff's **Duplicate Order**
button, or approving a client's reorder request — see
[Order Edit & Duplication](./17-order-edit-duplication.md)) copied the
client, commercial terms, and product lines, but left the new order with
no Supplier PO at all — staff had to remember, separately, that a repeat
client order almost always means a repeat order to the same supplier
too.

Now, whenever an order is duplicated and its source order has a Supplier
PO, the new order automatically gets a **draft** Supplier PO carrying
forward the same supplier, material, pricing, and terms — **nothing is
sent to the supplier**. Staff review, adjust if needed, and confirm it
through the normal Stage 5 flow once the new order reaches that stage,
exactly as if they were creating it from scratch, except the typing is
already done. The delivery date fields are deliberately **not** carried
over — those are specific to the source shipment's own timeline, and
staff set fresh dates for the new order.

Both directions of this relationship are visible on the order page:

- A duplicated order shows a banner: *"This order was created as a
  repeat order from [source order]"* — with a note that its Supplier PO
  terms were carried over as a draft, if one was.
- The **source** order shows a banner listing every order duplicated
  from it, if any.
- Before Stage 5 is unlocked, the order page already shows a read-only
  summary of the draft Supplier PO (supplier, reference, material, total
  payable, status) rather than nothing, so staff can see what was
  carried over without having to unlock Stage 5 first just to look.

This applies identically whether the duplication happened via staff's
own **Duplicate Order** button or via an approved client reorder request
— both paths go through the same underlying duplication step, so this
carry-forward behavior is never accidentally skipped for one path but
not the other.

## The CA Financial Annexure, extended

The `CAFIN` document (see
[Overview — The internal-only CA Financial Annexure](./ca-01-overview.md#the-internal-only-ca-financial-annexure--and-why-it-can-never-reach-a-client))
now includes three more sections beyond the original government export
benefits and expenses: **Supplier Cost** (the order's Supplier PO
figures), **Other Order Costs** (every Order Cost Entry, with a total),
and **Order Profitability Summary** (the same figures as the order
page's Profitability Summary). Every structural guarantee that already
kept the original CAFIN content off client-facing documents applies
identically to this new content — see Overview's fourth guarantee for
the specific mechanism (supplier cost is never even assembled for a
non-`CAFIN` document type in the first place).

## Financial year lock

Recording a claim, marking one received, and recording or deleting an
Order Cost Entry are all financial-year-locked actions, same as every
other CA write path (see
[Financial Year Lock](./ca-06-fy-lock.md)) — the date used is the
claim's or cost entry's own date, never today's date, so a backdated
entry into an already-locked year is refused unless the
`ca_fy_lock_override` permission is used (logged to the audit trail).
The Profitability Sheet itself is a read-only calculation, not a write,
so it has no lock of its own — it simply reflects whatever figures are
on record at the moment it's viewed.

## What this doesn't do yet

- No handling for a freight forwarder paid in a foreign currency instead
  of INR — see the assumption noted above.
- No separate multi-currency support in the Profitability Sheet: revenue
  is always converted to INR, and every cost is assumed INR, so there is
  currently no way to see a profitability figure in the order's own
  quoted currency.
- The draft Supplier PO carried forward on reorder is not automatically
  re-sent to the supplier, generated as a document, or notified anywhere
  — it's a data carry-forward only, reviewed and confirmed by staff
  through the normal Stage 5 flow.
