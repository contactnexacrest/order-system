# Stage 7 — Packing & BL Instruction

## What this stage is for

Recording exactly what was physically packed and shipped (crate-by-crate),
capturing the vessel/shipping details, and confirming the Bill of Lading
(BL) once the vessel actually departs — the point at which the goods are
formally handed to the carrier.

This is the stage where **FOB and CFR/CIF orders converge again** — whichever
path an order took through Stage 5/6, they arrive here identically.

## The system does this automatically

- **The shortfall tolerance check runs automatically.** The moment staff
  open this section, the system already knows the ordered quantity (from
  the order's product lines) and the configured tolerance percentage, and
  computes the shortfall live as staff enter the actual quantity packed —
  staff never calculate this by hand:

  ![Packing section — ordered quantity and tolerance shown automatically](./images/s7_packing_before.png)

- If the shortfall comes out **within tolerance**, nothing further is
  required. If it's **over tolerance**, the system blocks proceeding until
  the buyer's written approval of that shortfall is uploaded as evidence —
  this is a hard rule, not a warning.

## What staff do

Three things happen in this section, each building toward the BL:

1. **Save Packing & Crates** — actual quantity packed (with its own unit,
   defaulting to the order's own product unit when the order has one
   comparable unit across its lines), crate count, weights, CBM, packing
   date, and a crate-by-crate breakdown (marks & numbers, dimensions,
   pieces, weight, HS code per crate). Once saved, **Generate Packing
   List** produces the PL document, which prints the quantity with its
   unit.
2. **Save Shipping Details** — shipping line, vessel name, voyage number,
   ETD/ETA, number of containers, container number(s), and seal number.
   Number of Containers is a simple count distinct from Container No. —
   it covers multi-container shipments (e.g. one product split across 2
   containers) without needing a structured per-product-line assignment;
   staff still record the actual container number(s) as free text:

   ![Shipping / Vessel Details form](./images/s7_shipping_bl_before.png)

   Right below this form, its own labeled **"BL Instruction Sheet"**
   section explains what the sheet covers and where each field on it comes
   from (shipper/consignee/notify party, shipment and container details
   from above, cargo description from the order's products, and the
   mandatory BL-type instruction from Settings) before offering **Generate
   BL Instruction Sheet**, which produces the instruction document sent to
   the shipping line/forwarder telling them exactly how to issue the Bill
   of Lading. Anything left blank in Shipping/Packing above shows as "To Be
   Confirmed" on the generated sheet.

   The BL Type and BL Consignee Instruction rows only appear when the
   order's payment preset has **"Include BL Type / BL Consignee
   Instruction"** checked (Payment Presets, under Admin & Settings) — some
   payment terms (e.g. balance paid before shipment) don't need NexaCrest
   to retain the BL as leverage, so that preset can have the checkbox
   unchecked and the sheet simply omits both rows for every order on it.
   Both seeded presets ship with it checked (unchanged behavior).
3. **Confirm BL Issued** — once the shipping line actually issues the BL,
   staff type its number in here. **This is the action that passes Stage
   7's gate.**

   ![Confirm BL Issued form](./images/s7_bl_confirm_before.png)

Once confirmed:

![BL recorded](./images/s7_bl_recorded.png)

## What the client does (or doesn't)

Nothing at this stage — packing, vessel booking, and BL issuance are all
internal/carrier-side activities with no client-portal screen or action
involved. The buyer finds out what shipped and how only once the relevant
documents (Packing List, and later the scanned BL copy in Stage 8) are sent
to them.

## What happens next

Confirming the BL number passes Stage 7's gate and unlocks Stage 8:

![Stage tracker — Stage 7 passed, Stage 8 now in progress](./images/s7_stage_tracker_after.png)

See [Chapter 8](./08-stage8-ci-balance.md) for Commercial Invoice & Balance
Payment.
