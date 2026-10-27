# NexaCrest Order System — Standard Operating Procedure

> **Never used this application before?** Read [Getting
> Started](./00-getting-started.md) first instead — a short, screenshot-led
> walkthrough of logging in and finding your way around, written for
> someone on their first day. This chapter is the deeper reference you'll
> want once the application itself already feels familiar.

This is the operational manual for the order-management system: what the
system does on its own, what staff must do by hand, what the client (buyer)
does or is asked to do, and what happens next — screen by screen, for every
module.

This is **not** a developer document. It doesn't reference code files or
explain *how* something is built — only *what happens* and *who does it*.
(A separate technical document, `ARCHITECTURE.md`, covers the "how" for
developers.)

## Who this is for

Anyone who works an order day-to-day — Export Executives, Accounts
Executives, Logistics Executives, the Managing Director, and anyone new
joining the team who needs to know what to do at a given point without
having to ask. If you're re-reading this after months away, start with
"How to read this document" below, then jump straight to the chapter for
whatever stage or module you're dealing with — each chapter stands on its
own.

## The shape of the system

Every order goes through **9 fixed stages**, in order. A stage cannot be
skipped by hand — the system enforces the sequence itself (this is called a
**stage gate**): each stage has one specific action that "passes" its gate,
and passing a gate is what unlocks the next stage. If you try to generate a
later-stage document before its stage is unlocked, the system uses the
**"Force-Generate a Document"** override at the bottom of the order page —
covered in [Document Review & Approval](./13-document-review-approval.md)
— which requires a typed reason and is logged, precisely because skipping
the normal order is meant to be the exception, not the routine.

| # | Stage | What "passes" this gate | Chapter |
|---|-------|--------------------------|---------|
| 1 | Enquiry & Quotation | Quotation (QT) generated | [Chapter 1](./01-stage1-enquiry-quotation.md) |
| 2 | Buyer Purchase Order | Buyer's PO reference confirmed by staff | [Chapter 2](./02-stage2-buyer-po.md) |
| 3 | Proforma Invoice | Advance payment marked cleared | [Chapter 3](./03-stage3-pi-advance.md) |
| 4 | Order Confirmation | Buyer acknowledges the OC (or 48h auto-confirm) | [Chapter 4](./04-stage4-order-confirmation.md) |
| 5 | Supplier Purchase Order | Supplier's signed PO confirmed by staff | [Chapter 5](./05-stage5-supplier-po.md) |
| 6 | Freight Payment | Freight payment cleared (CFR/CIF only — auto-skipped for FOB) | [Chapter 6](./06-stage6-freight.md) |
| 7 | Packing & BL Instruction | Bill of Lading number recorded | [Chapter 7](./07-stage7-packing-bl.md) |
| 8 | Commercial Invoice & Balance | Balance payment cleared | [Chapter 8](./08-stage8-ci-balance.md) |
| 9 | Document Despatch & Closure | Courier tracking number entered, order closed | [Chapter 9](./09-stage9-despatch-closure.md) |

Every order detail page (`/orders/{id}`) shows all 9 at the very top, two
ways at once: first a horizontal line-with-dots **process stepper** —
stage number in each dot, connected by a line, the stage name below it —
for an instant "how far along is this order" read; then, right below it,
the same 9 stages as a row of detail chips — grey (locked), amber (in
progress / unlocked), green (gate passed) — each naming its own status in
words, for when you need more than the quick glance. Both use the same
three colors for the same three states, so they always agree with each
other. Each dot in the stepper is also clickable (and keyboard-focusable)
— clicking one jumps straight to that stage's own card further down the
page.

Below the stepper, the rest of the page is a single vertical sequence of
collapsible **step-cards** — one per step in the buyer's own export
process (QT → Buyer PO → PI & Advance Payment → Order Confirmation →
Supplier PO → Production → Freight → Packing & BL → Commercial Invoice &
Balance → Closure → Communication → Feedback → Complaints → Disputes),
each labelled with that step's own number so the on-screen order always
matches the real order of work — not the 9 internal stage numbers above,
which are a separate, coarser-grained tracker. The order's current step
opens automatically; every other step-card stays collapsed until you
click its heading, so the page never buries one step's actions behind
another's. A persistent panel to the side (stacked below on a phone) always
shows an "At a Glance" summary and the full list of documents generated
so far, regardless of which step-card is open.

## Modules that sit alongside the 9 stages

These aren't stages themselves — they can happen at various points, or run
independently of the main pipeline:

- [**Amendments**](./10-amendments.md) — changing payment terms after the
  PI is already issued, with both parties' sign-off.
- [**Disputes**](./11-disputes.md) — recording and resolving a formal
  disagreement with a buyer.
- [**Client Portal**](./12-client-portal.md) — everything the buyer
  themselves can see and do, gathered in one place.
- [**Document Review & Approval**](./13-document-review-approval.md) — the
  draft → review → approved → sent lifecycle every generated document goes
  through, independent of which stage generated it.
- [**Reports**](./14-reports.md) — every reporting screen and what
  question it answers.
- [**Admin & Settings**](./15-admin-settings.md) — roles/permissions,
  signatories, watermarks, HS codes, company settings, email templates.
- [**Test Mode**](./16-test-mode.md) — a sandboxed way to rehearse the
  system without touching real data or sending real emails.

## How to read each chapter

Every stage/module chapter follows the same shape:

1. **What this stage/module is for** — one paragraph, plain language.
2. **The system does this automatically** — anything that happens without
   a human touching a button.
3. **What staff do** — the exact screen, the exact button or field, in
   order.
4. **What the client does (or doesn't)** — if the buyer is involved at
   all, exactly what they see and what's expected of them; if they're not
   involved, that's stated too, so nobody waits on a client action that
   was never coming.
5. **What happens next** — what gets unlocked or triggered once this step
   is done.

## One rule that applies to every stage

**Passing a stage gate and getting a document approved are two separate
things.** A stage gate passes the moment the specific gating action happens
(e.g. generating the Quotation passes Stage 1, immediately, as a draft) —
not when that document finishes being reviewed, approved, and sent to the
buyer. Review/approval is tracked separately per document (see
[Document Review & Approval](./13-document-review-approval.md)) and never
blocks the pipeline from moving forward. In practice this means you can be
several stages ahead of a document that's still sitting in "draft" or
"in review" — that's normal, not a bug, though it's worth keeping an eye on
so nothing old goes out to a buyer unreviewed.

## How files are named and stored on disk

A question that comes up often enough to answer here once: every file this
system stores — a generated document, an uploaded dispute document, an
Annexure product photo — ends up on disk nested under two reference
numbers, never under the human-readable name you'd expect:

```
{STORAGE_BASE_PATH}/clients/{client_unique_number}/{order_reference}/{step folder}/{random-id}.{ext}
```

**The step folders match the order page** (owner request 2026-10-26), so
the folder a file sits in is the step it belongs to:

```
01-quotation/            generated/ (QT, Annexure A) · annexure-images/
04-buyer-po/             generated/ (Buyer PO) · received/ (the buyer's signed copies)
05-proforma-invoice/     generated/ (PI) · lc/ (LC copies)
06-order-confirmation/   generated/ (OC)
07-supplier-po/          generated/ (Supplier PO) · received/ (supplier's acknowledgment)
09-freight/              generated/ (Freight Debit Note)
10-packing-and-bl/       generated/ (Packing List, BL Instruction) · received/ (draft BLs, scanned BL, packing approvals)
11-commercial-invoice/   generated/ (CI)
12-financials/           generated/ (CA financial annexure — internal)
13-closure/              generated/ (COO Prep Sheet, BL Endorsement)
payments/                client-reported payment proofs
amendments/{ref}/        generated/ · received/ (signed copy)
disputes/{id}/ · order-updates/ · additional-documents/ · checklists/ · reference/
```

A generated document always goes to its **own** step's folder, whatever
stage the order is at — a Quotation regenerated late in the order still
lands in `01-quotation/generated/`. Older files were filed by the stage the
order happened to be at; the one-off tidy-up tool
(`php app/cron/reorganize_order_files.php`, or
`node src/jobs/reorganizeOrderFiles.js` on the Node build) moves them into
this layout — run it without arguments first to see every planned move,
then again with `--apply`. Take a backup of the storage folder and the
database before applying.

- **`client_unique_number`** (shown on screen as **"Buyer Inquiry Ref"**)
  is generated **once**, when the client is first created, and reused on
  every order and document for that client afterward. It identifies the
  original enquiry/relationship, not any one order — a repeat buyer placing
  their fifth order still carries the same Buyer Inquiry Ref as their first.
- **`order_reference`** is generated **once per order** instead, and is
  what actually distinguishes one order from another for the same client.
- **`{step folder}`** is the order-page step the file belongs to, as
  listed above — `generated/` inside it for documents the system produced,
  `received/` for files that came in from the buyer, supplier or CHA.
- **`{random-id}.{ext}`** — the actual filename on disk is always a random
  identifier, generated fresh for that one file, never the document's own
  reference number (e.g. `SC/QT/2026/1809001`) and never the name the file
  was uploaded with. Two reasons: a real document reference contains `/`
  characters a filename can't hold, and giving every file an unpredictable
  name means nothing about what a file is, or which order it belongs to,
  can be guessed from its name alone if a path ever leaked. The
  human-readable identity — the document reference, the original upload
  filename — lives only in the database (`file_store`/`documents`
  tables), looked up at download time; the filename on disk is never
  meant to be read by a person.
- Both `client_unique_number` and `order_reference` naturally contain `/`
  characters of their own (they're reference numbers, not folder names),
  so each is run through the same sanitizer before being used as a path
  segment: anything that isn't a letter, digit, underscore, or hyphen
  becomes a hyphen. `SC/ORD/2026/003-42` on screen becomes
  `SC-ORD-2026-003-42` as a folder name — same identity, just filesystem-safe.

This whole structure sits under whatever `STORAGE_BASE_PATH` is configured
to (see [Admin & Company Settings](./15-admin-settings.md) and the
deployment guide) — always outside the public web root, so none of it is
reachable by a direct URL; every download goes through the application,
which checks permissions before handing back the file.

## Conventions used across every screen

**"To Be Confirmed" is always spelled out, never abbreviated.** Any field
that genuinely isn't known yet — a quantity, a container type, a
certificate of origin type, a shipment date — shows the full words "To Be
Confirmed" on screen and on every generated PDF/DOCX, not the abbreviation
"TBC". This applies everywhere the system falls back to a placeholder for
missing data, so a fresher (or a buyer reading a document) never has to
guess what an unfamiliar three-letter code means.

**"Special Requirements/Instructions" gets its own highlighted section on
every document that carries it** (the Quotation and Supplier Purchase
Order), not just another row in a table — an amber title bar and a tinted
box, the same visual treatment used for other must-not-miss notices like
the Quotation's "VALID UNTIL" banner. The field itself (renamed from just
"Special Requirements" wherever it's shown, on-screen and in the order
and Supplier PO forms) is optional and free text, but when something is
written there, it's meant to be noticed, not buried.

**An order's detail page is one vertical sequence of collapsible
step-cards, numbered to match the buyer's own export process — not the
9 internal stages, and not one long undifferentiated scroll.** Every
order's own page (`/orders/{id}`) always shows the order's title, action
buttons, and 9-stage tracker at the top — those never move. Below that,
the page lays out every section as its own numbered step-card, in the
exact order staff actually work through an order (Quotation → Buyer PO
→ PI-Stage Intake → PI & Advance Payment → Order Confirmation →
Supplier PO (opening with the with-the-Supplier-PO part of the
Compliance Checklist, ending with the supplier's advance payment) →
Production & Estimated Shipment → Freight Payment → Packing & BL
Instruction → Commercial Invoice & Balance → Closure — Financials,
Benefits & Costs → Closure — Documents & Settlement → Additional
Documents → Communication → Feedback → Complaints → Disputes → Order
Complete), each labelled with that step's own number (e.g. "Step 7 ·
Supplier PO") so the screen's own order always matches the real order
of work.

**The steps are grouped into five sections** (owner request
2026-10-26), each a collapsible box with its own status icon, a "x of y
steps done" bar and any alert badges:

| Section | Steps |
|---|---|
| **A · Order Booking** | 1–6: Quotation → send → acceptance → Buyer PO → PI & advance → Order Confirmation |
| **B · Procurement & Production** | 7–8: Supplier PO → production & estimated shipment |
| **C · Shipment & Balance** | 9–11: Freight → packing & BL → Commercial Invoice & balance |
| **D · Closure & Settlement** | 12, 13 and 19: financials & benefits → documents despatched → fully settled → Order Complete |
| **E · After-Sales & Records** | Amendments, 14 and 16–18: additional documents, feedback, complaints, disputes — usable at any time, with open complaints/disputes shown on its header |

Every section starts collapsed except the one holding the order's
current step, which opens and scrolls to that step; inside it, the
current step opens and the rest stay collapsed — click any heading to
open it. Products, Payment Overview, Document Review & Approval and
**Order Communication** (the conversation with the client, Step 15) stay
pinned above the sections. Order Communication is always open, since it's
needed at every step; Document Review opens by itself only when a
document is waiting on someone (otherwise it shows just the number of
documents). This is purely a layout and
navigation change — nothing about what a section does, what permission
it needs, or what button does what is any different; a section still
works exactly as this SOP describes it, it's just reached by clicking
its own heading rather than scrolling to it or picking it from a
separate nav. Step-cards are native, no-JavaScript-required HTML
(`<details>`/`<summary>`): if a browser's JavaScript never runs, every
step-card still opens and closes with a plain click, and nothing is
hidden behind a script that isn't there as a backup. Across the **top of
the page**, above the step-cards (owner request 2026-10-27, so the
step-cards and document tables get the full width), sit the "At a
Glance" summary, the CAFIN box (Admin/MD/ED only — see [CA
Overview](./ca-01-overview.md)) and an **▸ All Documents (N) — quick
links** button that opens the list of documents generated so far.

The steps are grouped into five coloured sections — **A** Order Booking,
**B** Procurement & Production, **C** Shipment & Balance, **D** Closure &
Settlement, **E** After-Sales & Records — each with its own colour.
Every smaller open/close toggle on the page (for example "+ Add a bill",
"+ Add an expense", "Linked from Zoho Books") is a clear navy button with
a ▸ / ▾ marker, so none of them is easy to miss.

**Each stage's own action buttons still carry a small sub-step badge**
(e.g. "7a", "7b", "7c") immediately before the button itself, wherever a
stage involves more than one action a staff member presses in
sequence — for example Supplier PO's "Save Supplier PO Terms" (7a) →
"Generate Supplier PO" (7b) → "Attach Supplier PO Acknowledgment" (7c)
→ "Confirm Supplier Signed & Returned PO" (7d). This shows the literal order of operations *within* a step, not
just which of the 9 internal stages the order is on overall (that's
still the process stepper above) — badge numbers follow the step-card's
own on-screen number, not the stage number.

**Sub-steps open one at a time.** Inside a step, a sub-step can't be
opened or used until the one before it is complete — it shows greyed
with a 🔒 "Complete Step 5a first" note. Where a sub-step genuinely may
not apply, it offers its own way past: e.g. the PI-stage form (5a/5b)
has **"Skip PI Form — go to Generate PI"** with a short reason. The
Compliance Checklist cards are the exception: they run alongside and
never lock anything. The badge is purely a labelling aid — it
changes no permission, no gate, and no button's underlying behaviour.

**"At a Glance" is the order's own persistent summary panel, not just
its commercial terms.** Open at all times across the top of the page (never
collapsed, since it's how staff get back their bearings while stepping
through the page) is a quick row of counts — **Current Stage**,
**Products** (line count), **Payment Legs** (how many of
advance/freight/balance are cleared, with a jump link to
[Payment Snapshot](#payment-snapshot-and-payment-ledger)), **Compliance
Checklist** (resolved count, shown only to whoever holds `close_orders`
— same gate as the checklist itself, see
[Stage 9](./09-stage9-despatch-closure.md)), **Documents Generated** —
followed by Client, Buyer Inquiry Ref, Incoterm/Port of Loading and
Quotation Date, and the rest of the order's own commercial terms. The
idea is that opening an order answers "where does this stand?" without
scrolling or opening every step-card first; every number here is a
read-only reflection of data recorded elsewhere on the same page, never
something edited from this panel. The fields run in five columns on a
wide screen and fewer on a narrow one. The "All Documents" quick-links list
(every document generated so far, newest first) is covered in
[Document Review & Approval](./13-document-review-approval.md).

**A creation or edit form groups related fields two to a row, not one.**
The New Order form (and other detail-entry screens like it) pairs related
fields side by side — Incoterm next to Port of Loading, Total volume next
to package count, and so on — instead of stacking every single field on
its own line. A field that needs the full row to itself (a free-text note,
an explanatory line) still gets one. Where a figure could be read two ways
(e.g. a weight that could mean the product alone or the product plus its
packing), the field's label carries a small tag spelling out exactly which
one it is, so nobody has to guess or check twice at the Packing List stage.

**A signature block never appears bare.** Every generated document's
signature block (the "For [Company] / Authorised Signatory" block most
document types carry, and the Buyer/Supplier PO's own "fill, sign and
return" acceptance table) always carries a line identifying exactly which
document it belongs to — document type, document reference, date, and
Buyer Inquiry Ref — immediately above the signature itself, kept together
with it as one unit that can never be split across a page break. If a
long document pushes the signature block onto a fresh page by itself,
that page still clearly states which document it is, so a signature page
can never be mistaken for, or substituted into, a different document.

## Roles in this system

Six roles exist today (Admin → Settings → Roles & Permissions): **Admin**,
**Managing Director**, **Export Executive**, **Accounts Executive**,
**Logistics Executive**, **Viewer / Auditor**. Who can do what is governed
by permissions attached to a role (e.g. `manage_orders`, `generate_documents`,
`approve_documents`, `view_reports`) — a Super Admin bypasses every
permission check. This SOP describes actions by *what* they are, not which
role does them, since that assignment is configurable per organisation —
see [Admin & Settings](./15-admin-settings.md) for how to check or change
who can do what.

## The CA / Accounting module

A separate module exists for Chartered Accountant / accounting work,
entirely independent of the 9 stages above — it has its own section in
this SOP's chapter list ("CA / Accounting"), starting with
[CA / Accounting — Overview & Permissions](./ca-01-overview.md).

## A running example

Several chapters follow one real order end-to-end (referred to as
**SC/ORD/2026/001-2**) as it's walked through the pipeline, so you can see
the same order at each stage rather than a different disconnected example
every time.
