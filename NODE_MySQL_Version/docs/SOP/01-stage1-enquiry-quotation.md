# Stage 1 — Enquiry & Quotation

## What this stage is for

Turning a buyer's interest into a real client record and a first Quotation
(QT) document. This is the only stage where the order doesn't exist yet —
everything before this point is either a public web form or a staff-entered
client record.

## The system does this automatically

- Nothing yet — Stage 1 is the one stage that always starts with a human
  action (either the buyer submitting the public form, or staff creating
  the client directly). There's no order to auto-progress until one exists.
- Once the Quotation is generated, the system immediately marks Stage 1 as
  **gate passed** and unlocks Stage 2 — this happens the moment the PDF is
  generated, not after anyone reviews or sends it (see the "one rule that
  applies to every stage" note in the [introduction](./00-introduction.md)).
- The system auto-generates the **Buyer Inquiry Ref** (e.g.
  `NC/SC/2026/2009001`) the moment a client record is created — staff never
  type this themselves.

## What staff do

An order can start from either of two paths:

### Path A — buyer submits the public form themselves

The buyer fills in `/quotation-details` — a public page, no login needed
(shareable link, e.g. sent from a Zoho lead). It only asks for company/
shipping details, deliberately **not** product details (those come later,
at the PI stage):

![Public Quotation Details form](./images/s1_public_intake_form.png)

That submission lands in **Operations → Quotation Intake Review**
(`/client-intake`) as a pending row. Staff review it here and either:

- **Accept & Create Client** (with a reason, min. 10 characters) — creates
  the real client record and takes you straight to it, ready to create the
  order.
- **Reject** (with a reason) — if it's not a genuine enquiry.

![Quotation Intake Review queue](./images/s1_intake_review_queue.png)

Accepting **only creates the client** — it does not create an order or a
Quotation automatically. Staff still do that next, exactly as in Path B.

### Path B — staff create the client and order directly

For a walk-in enquiry that didn't come through the public form: **Clients →
Add Client** (`/clients/create`), fill in the same details by hand, save.
Then from that client's page, **Add Order** to create the order shell
(buyer inquiry ref, incoterm, payment preset, etc.).

### Buyer / Consignee / Notify Party details (per client)

Every client record carries three separate detail blocks, matching the
Developer Spec's own document numbering and reflecting how export
paperwork actually works — the company paying the invoice, the company
receiving the cargo, and the party to notify on arrival are often three
different entities:

- **Buyer Details** — the client's own company name, structured billing
  address (Line 1/2, City, Postcode — kept alongside the older single
  free-text Billing Address field for backward compatibility), VAT/EORI,
  and contact details. Always independently entered — there's no "same
  as" checkbox here.
- **Consignee Details** — defaults to a checked **Same as Buyer?**
  checkbox. While checked, the consignee fields are disabled on the form
  and every Consignee section on every generated document shows the
  Buyer's own current details, resolved fresh each time a document is
  generated — never a one-time copy, so a later edit to the Buyer block
  is reflected automatically on the next document without anyone having
  to re-enter anything on the Consignee side. Unchecking it enables an
  independent set of fields (company name, structured address, VAT/EORI,
  contact person, phone, email) for a client whose goods genuinely ship
  to a different company than the one paying for them.
- **Notify Party** — defaults to a checked **Same as Consignee?**
  checkbox, resolving from whatever the Consignee block *itself* resolved
  to (so if Consignee is also "same as Buyer", Notify Party shows the
  Buyer's details too) for the same always-fresh reason. Unchecking it
  enables independent fields for a freight-forwarder/agent that needs
  notifying on arrival but isn't the consignee itself. Notify Party only
  appears on the documents the Developer Spec defines it for (Proforma
  Invoice, Packing List, Commercial Invoice) — the Quotation, Order
  Confirmation, and Buyer PO never show a Notify Party section.

**Certificate of Origin Type** is a dropdown (same admin-managed list the
order-creation form itself uses, e.g. Non-Preferential), not a free-text
field — this keeps the value consistent with whatever CAPEXIL actually
issues, rather than depending on each buyer or staff member typing the
same term the same way.

### Agreement T&C Footer (per client)

Some buyers negotiate a clause specific to their own commercial agreement
with NexaCrest — e.g. a pre-shipment inspection right, or a specific
dispute-resolution forum — that isn't part of the company's general
numbered T&C list and shouldn't be added there for every other buyer.

On a client's **Edit** page, a separate **Agreement** section holds a
free-text **T&C Footer**. Whatever is entered there shows as an extra,
unnumbered note — "Special Terms (per Client Agreement)" — on every
Quotation, Proforma Invoice, Order Confirmation, Buyer PO and Commercial
Invoice generated for that client, in addition to the standard numbered
terms. It shows even on a Commercial Invoice, which otherwise carries no
numbered T&C clauses at all.

This field has its own **Save Agreement Footer** button, separate from the
main **Save Changes** button above it, and is never affected by the
client's data lock: once a client's other details lock permanently (see
Stage 3's PI-details consent), the main form can no longer be saved by
anyone but a Super Admin with a reason — but the Agreement T&C Footer stays
editable by any staff member with client-management access at any time,
since it's a staff-authored annotation of an externally-negotiated term,
not a client-submitted identity detail the lock exists to protect. Every
change here is still fully audit-logged.

### Both paths converge here — generating the Quotation

From the order page, under **Documents**, click **Generate Quotation**.
This is the one action that passes Stage 1's gate. The order's stage
tracker updates immediately:

![Stage tracker after QT generated](./images/s1_stage_tracker.png)

The new QT document appears in the Documents table as a **draft** — it
still needs to go through [review and approval](./13-document-review-approval.md)
before it's the final, sendable version. A draft can be deleted (**Delete
Draft**) if it was generated by mistake — see that same chapter for when
that's appropriate.

![Documents table after generating a QT](./images/s1_documents_table.png)

Once the Quotation is approved, send it to the buyer from the order page
(**Send Document**) or compose a one-off email — see
[Document Review & Approval](./13-document-review-approval.md) for the full
send workflow.

## Annexure A — Product Specification and/or Additional Terms

Annexure A is an optional attachment staff can turn on for an order
(**Enable / manage Annexure A** link on the order page). Once enabled, it's
appended to every buyer-facing document that supports it (QT, PI, OC, Buyer
PO, Packing List, Commercial Invoice) as a guaranteed, never-skippable
appendix, and can also be generated as its own standalone document.

From the Annexure A management screen (`/orders/{id}/annexure`), a **content
mode** dropdown decides what Annexure A actually shows:

- **Product Specification (current view)** — the structured per-product
  table (dimensions, finish, components, technical notes, photos) staff fill
  in below the dropdown. This is the original, only mode Annexure A used to
  have.
- **Additional Terms** — a free-form rich-text block, entered through a
  small on-screen WYSIWYG editor (bold/italic/underline/strikethrough,
  headings, quote, bullet/numbered lists, inserted images, links). Use this
  for anything that doesn't fit the product table — a special clause, an
  inspection condition, a one-off note agreed with the buyer.
- **Both** — prints Product Specification first, then Additional Terms,
  in the same document/appendix.

Switching the dropdown only changes what's printed — it never deletes either
section's saved content, so staff can go back and forth without re-entering
anything. If the selected mode has nothing saved yet, the generated document
shows a plain "No product entries/additional terms have been added yet"
line rather than an empty gap.

Additional Terms supports pasted or inserted **images** (via the editor's
Image button) but not video or audio: a generated PDF or Word document
can't play embedded media, so there was nothing to gain by accepting it.
Content is sanitized on save (and again every time a document is generated)
to a fixed set of safe formatting tags — scripts, event handlers, and
remote/relative image sources are stripped automatically.

## What the client does (or doesn't)

- **If they came through Path A**, they filled the public form — that's
  their only action at this stage. They are not notified of acceptance or
  rejection automatically; staff communicate the Quotation itself once it's
  ready (there's no client-portal login yet at this point — that only
  exists from Stage 3 onward, once the client's own portal account is
  provisioned).
- **If they came through Path B**, they've done nothing in the system yet
  — the whole stage is staff-only until the Quotation reaches them (by
  email, outside the system, or via the Send Document flow once built out).
- Either way, the client cannot see or act on anything inside this system
  at Stage 1 — there's no client-portal screen for the Quotation stage, and
  no portal login exists for them yet at all. Their portal account is only
  created automatically once the advance payment clears (Stage 3 → 4) —
  see [Chapter 3](./03-stage3-pi-advance.md).

## What happens next

Stage 2 (Buyer Purchase Order) is unlocked the instant the Quotation is
generated. See [Chapter 2](./02-stage2-buyer-po.md).
