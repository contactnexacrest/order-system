# Order workflow documents

The order workflow as the owner set it, kept here so they are updated
together with the application.

- `NexaCrest_Export_Order_Application_Workflow_Specification_v3.docx` — the
  workflow specification: the steps, branches, controls and email policy
  (sections 1–13), the owner decisions built into the application
  (section 14, 27 Oct 2026; section 15, 28–30 Oct 2026), where each step
  is on the order page (section 16) and a summary of the decisions of
  2–13 Nov 2026 that change the workflow (section 17, added 13 Nov 2026). v2 was titled "StoneCrest"; v3 uses the
  company name, NexaCrest.
- `NexaCrest_Order_Workflow_Guide.html` — the staff checklist: every step of
  the order page in order, by role, with a tick box per order (ticks are
  kept in the browser on that device). Open it in any browser. Updated
  1 Nov 2026 for the gap-list gates: the inspection result (8a), the Bill
  of Lading waiting for a Passed inspection and a before-shipment balance
  (10f), the balance due from the scanned BL (11b), part payments and
  short-pay sign-off, LC dates and amendments, and the OC "sent" clock (6c).
  Updated again 1 Nov 2026 for D-221/D-222: who may approve their own work
  (Users & roles › Self-approval rules), original BL copies 1 to 10, and the "*"
  on required fields.
  Updated 2 Nov 2026: 7c starts with the Supplier Link; 7e / 10a start from
  the Supplier PO; 9a pre-selects the forwarder; the "why is it different?"
  reason on the advance, freight and balance remittances (5d, 9c, 11c); the
  COO Prep Sheet (13a) from Stage 8, before the balance clears; the
  Export Compliance department (filter chip, compliance checklist, 13a);
  "Sync this order to Zoho now".
  Updated 6 Nov 2026 (D-258, ORD-14): Close Order (13f) needs the original
  BLs received (11a) and endorsed (13e).
  Updated 11 Nov 2026 (D-280, D-320): menu names (Users & roles ›
  Self-approval rules) and "Testing mode" in place of "e-mail sending off".
  The order page itself also gained a sticky action bar (Edit order ·
  Documents · Full report · Duplicate order · More), a compact products table
  with a side panel (D-273) and, for approvers, a "Waiting for your approval"
  bar (D-310); the step numbers are unchanged.
  Updated 13 Nov 2026 (D-336–D-365): new rules for every order (only
  configured products can be quoted — suppliers › + Add product, Products ›
  Configure; prices and the exchange rate fixed by the approved Quotation;
  buyer details following the client); Step 1 (pick lines from Products,
  Thickness and Size / format "Choose…", "As per annexure" with Annexure A);
  5c (the MD approves a line below the staff lowest price, in place of the
  old D-240 reference-price discount); 7a (the Supplier PO starts from the
  table of supplier offers; a different price needs a reason); 11a (choose
  the annexure printed with the CI and Packing List; Annexure B made there);
  Amendments (Products & prices) and a new side step "Buyer details changed
  on the client".

The specification (v3) reflects the decisions up to 30 Oct 2026 in full;
section 17 summarises the later ones that change the workflow (to 13 Nov
2026, D-365). The decisions taken since 30 Oct — D-212 (money descriptions) to D-241 (gap list, CFR
wording, associates, self-approval rules, QA Round 8, document layout and
wording, freight in rupees, Export Compliance, remittance differences,
Agreements register, money safety, document copies clean-up, associate TDS,
ECGC / eBRC tracking, Logistics entering logistics bills, Zoho client / product
sync and call limits, the reference price with discount approval (since
replaced by D-245 pricing), go-live without migrations, D-245 pricing,
commission and team incentive, D-257 divisions, D-258 the QA programme, and D-259 to D-365 up to 13 Nov 2026) — are in `docs/decisions/owner-decisions-register.md`
and in the guide and the SOP; where they differ from the specification,
the register wins.

When a change adds, removes or renames a step on the order page, or the
owner makes a workflow decision, update both files here (and the SOP in
`docs/SOP/`) in the same change.
