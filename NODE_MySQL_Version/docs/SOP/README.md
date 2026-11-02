# NexaCrest Order System — Standard Operating Procedure

**Brand new here?** Start with [Getting Started](./00-getting-started.md) —
not this table of contents. It's a short, screenshot-led first-day guide
to the application itself (logging in, finding your way around, what
Standard vs Kanban means, where the client's own view differs from yours)
written for someone who has never opened this system before. Everything
below assumes you've already read it.

Once you're oriented, [00 — Introduction](./00-introduction.md) is the
next step up: it explains how to read every chapter, the 9-stage pipeline
at a glance, and one rule that applies throughout the whole system (stage
gates and document approval are separate things — don't confuse them).

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
| 9 | [Document Despatch & Closure](./09-stage9-despatch-closure.md) (also: marking an order lost, cancelling an order) |

## Modules alongside the pipeline

- [Amendments](./10-amendments.md) — changing payment terms after the PI is issued.
- [Disputes](./11-disputes.md) — a buyer wanting to cancel this order and not proceed, admin-gated per client.
- [Client Portal](./12-client-portal.md) — everything the buyer can see and do, in one place.
- [Document Review & Approval](./13-document-review-approval.md) — the draft → review → approved → sent lifecycle every document goes through.
- [Reports](./14-reports.md) — every reporting screen and what question it answers.
- [Admin & Settings](./15-admin-settings.md) — roles/permissions, users, signatories, watermarks, HS codes, suppliers, logistics partners, ports, payment presets, company settings (including the mail and Zoho Books connection tests), email templates, UI Template Preference (Standard/Kanban), who may give which rights, Self-approval rules (who may approve their own work, for eight actions), the permissions added 1 Nov 2026 (including Manage approval limits), and the scheduled jobs.
- [Test Mode](./16-test-mode.md) — a sandboxed way to rehearse the system without touching real data or sending real email.
- [Editing, Duplicating & Reordering](./17-order-edit-duplication.md) — changing an order after it's created, cloning a product line or a whole order, and a client's self-service repeat order.
- [Data Export / Migration](./18-data-export-migration.md) — downloading a structure snapshot and a full data export of the live database, to migrate every historical record into a fresh reinstall without loss.
- [Complaints](./19-complaints.md) — a buyer flagging a process issue with no intent to cancel, a two-way conversation separate from Disputes.
- [Incentives](./20-incentives.md) — the incentive cycle, My Incentives, and paying incentives (Accounts).
- [Associates](./24-associates.md) — the associate portal, how a buyer becomes an associate's client, commission, bank checks and payment.
- [Departments & Log in as](./21-departments.md) — departments and their designations, choosing and switching department, Admin's Preview as, a person's memberships, personal exceptions with an end date.
- [Procurement](./22-procurement.md) — purchase requirements from Sales, supplier quotes and comparison, award and approval limits, receipt and quality check, supplier performance.
- [My tasks, owners & cockpit](./23-coordination.md) — each department's task list built from the orders, one owner per department per order, due and escalation days, the management cockpit, the optional Order Coordinator.
- [Export Compliance](./25-export-compliance.md) — the department for government and agency work (ECGC, Certificate of Origin, phytosanitary / fumigation / inspection, export benefit claims, eBRC / FIRC): its three roles, its task list, and what it never sees.
- [Agreements](./26-agreements.md) — the register of agreements with clients, suppliers, logistics partners, associates and staff: signed copies, expiry dates, reminders 30 and 7 days before and on the day, renew and end early.

## Guides to print or share

Stand-alone guideline documents that go with this manual. Each one is also in the app's **Reference Library** (menu → Reference Library) so every user finds it in the same place.

- **Supplier & Logistics Payments Guide** — [PDF](./guides/Supplier_and_Logistics_Payments_Guide.pdf) · [Word](./guides/Supplier_and_Logistics_Payments_Guide.docx). Only supplier and logistics partner (CHA, transport, freight forwarder) advance and balance payments: what to type in each box, with numbered screenshots, a marked-up sample bill, 10 common mistakes and a one-page cheat sheet. The same steps are on screen at **Help → Supplier & logistics partner payments** (`/help/supplier-logistics-payments`), linked from Steps 7e, 10a and the Logistics Partner Bills card.

## The running examples

Two orders are followed live through this manual, with real screenshots
from each real action:

- **SC/ORD/2026/001-2** — an FOB order to a repeat buyer, walked through all
  9 stages start to finish (Chapters 1–5, 7–9). Being FOB, it auto-skips
  Stage 6.
- **A second, CIF order to a Norwegian buyer** — used specifically for
  [Chapter 6](./06-stage6-freight.md), since the main example skips that
  stage entirely, and reused for the Amendments and Disputes chapters.

