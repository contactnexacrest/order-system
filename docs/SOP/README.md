# NexaCrest Order System — Standard Operating Procedure

Start with [00 — Introduction](./00-introduction.md) if this is your first
time here: it explains how to read every chapter, the 9-stage pipeline at a
glance, and one rule that applies throughout the whole system (stage gates
and document approval are separate things — don't confuse them).

This is an **operations manual**, not a developer document — it describes
what the system does, what staff do, and what the client does or doesn't,
screen by screen. It is not the place to look for code, file paths, or
implementation detail.

## The 9-stage pipeline

| # | Chapter |
|---|---------|
| 1 | [Enquiry & Quotation](./01-stage1-enquiry-quotation.md) |
| 2 | [Buyer Purchase Order](./02-stage2-buyer-po.md) |
| 3 | [Proforma Invoice / Advance Payment](./03-stage3-pi-advance.md) |
| 4 | [Order Confirmation](./04-stage4-order-confirmation.md) |
| 5 | [Supplier Purchase Order](./05-stage5-supplier-po.md) |
| 6 | [Freight Payment](./06-stage6-freight.md) |
| 7 | [Packing & BL Instruction](./07-stage7-packing-bl.md) |
| 8 | [Commercial Invoice & Balance Payment](./08-stage8-ci-balance.md) |
| 9 | [Document Despatch & Closure](./09-stage9-despatch-closure.md) |

## Modules alongside the pipeline

- [Amendments](./10-amendments.md) — changing payment terms after the PI is issued.
- [Disputes](./11-disputes.md) — recording and resolving a formal disagreement with a buyer.
- [Client Portal](./12-client-portal.md) — everything the buyer can see and do, in one place.
- [Document Review & Approval](./13-document-review-approval.md) — the draft → review → approved → sent lifecycle every document goes through.
- [Reports](./14-reports.md) — every reporting screen and what question it answers.
- [Admin & Settings](./15-admin-settings.md) — roles/permissions, users, signatories, watermarks, HS codes, company settings, email templates.
- [Test Mode](./16-test-mode.md) — a sandboxed way to rehearse the system without touching real data or sending real email.

## The running examples

Two orders are followed live through this manual, with real screenshots
from each real action:

- **SC/OC/2026/001-2** — an FOB order to a repeat buyer, walked through all
  9 stages start to finish (Chapters 1–5, 7–9). Being FOB, it auto-skips
  Stage 6.
- **A second, CIF order to a Norwegian buyer** — used specifically for
  [Chapter 6](./06-stage6-freight.md), since the main example skips that
  stage entirely, and reused for the Amendments and Disputes chapters.

## Still pending

A wet-signature-required flag concept was discussed but not yet built into
the system — see the project's own task tracker, not this manual, for that
item's status.
