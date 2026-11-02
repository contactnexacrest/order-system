# NexaCrest Reference Document Set (v5 — October 2026)

This folder is the single source of truth for every reference document the
application's buyer-facing/internal document templates are built from. It is
bundled directly into the repository so nothing needed to understand or verify
the document templates lives only in an external upload or a past
conversation — everything is here.

**Start with `reference/NexaCrest_Developer_Spec.txt`.** It declares itself
authoritative and supersedes any conflicting instruction elsewhere, including
in `NexaCrest_Change_Log.txt` (which is kept for full history/reasoning, but
where the two disagree, the Developer Spec wins — it says so explicitly).

## Layout

- `assets/` — brand assets embedded in every document (logo, seals, signature).
- `buyer_documents/01_Tier1_NewBuyer/` and `02_Tier2_EstablishedBuyer/` — the
  9 buyer-facing document types (QT, PI, OC, PL, CI, Debit Note, BL
  Instruction, Buyer PO, Annexure A), one copy per payment tier. Document
  codes and filenames are identical between tiers; only the Balance Payment
  trigger wording differs (see Developer Spec Section 1.1).
- `contract/` — master NDA and Sales & Supply Agreement templates. Issued per
  relationship, not generated per order.
- `internal_documents/` — staff-only documents (COO prep sheet, checklists,
  Supplier PO, Payment Terms Amendment, SOPs, Stage Gate, Wall Reference, BL
  Endorsement, BL Reasoning Guide; the BL Reasoning Guide and Checklists
  were aligned on 2 Nov 2026 with the buyer documents' rule: all 3 originals
  come to NexaCrest, 2 go to the buyer once the balance clears, 1 is kept).
  Never shown to a buyer.
- Staff guidelines that are not document templates live outside this folder:
  the *Supplier & Logistics Payments Guide* (PDF + Word, 2 Nov 2026) is in
  `docs/guides/` and `docs/SOP/guides/`, and in the app's Reference Library
  (`storage/assets/reference_library/`, migration `2026-11-02u`).
- `reference/` — the governing text and lookup files:
  - `NexaCrest_Developer_Spec.txt` — **authoritative.** Single source of truth
    for section structure, field lists, exact clause text, and legal-language
    rules.
  - `NexaCrest_Change_Log.txt` — full edit history (including superseded
    approaches, explicitly marked as such) and a master terminology
    find/replace table.
  - `NexaCrest_Terminology_Standard.xlsx` / `NexaCrest_Master_Reference.xlsx` —
    supporting lookup data referenced by the spec.

## Relationship to the live application

These are reference/source documents, not something the app reads at
runtime. The app's own document templates (Twig in
`PHP_MySQL_Version/app/templates/`, Nunjucks in
`NODE_MySQL_Version/app/templates/`) and DOCX builders are what actually
render buyer-facing PDFs/DOCX files, and
they are expected to match this folder's content. When this folder is
updated, the application templates need a corresponding update pass — they
are not generated from these files automatically.

## Owner decisions made after v5

Since this set was frozen, the owner has changed some document wording and
added document types through the decisions register
(`docs/decisions/owner-decisions-register.md`), each confirmed twice, for
example: the PI/CI payment wording (28 Oct), the CA Financial Annexure
(CAFIN, internal), the Export Service Invoice (ESI, D-215), payment
milestones on the PI/OC (D-215), and the CFR wording on the Shipment
Booking clause and the Buyer PO (C-16, D-219). Where this folder and the
register disagree, the register — and the app's templates that follow it
— win. This folder was not edited for those changes.

**Exception — 1–2 November 2026 (D-223, D1–D5):** these changes were made in
this folder too, so the reference Word documents, the app's PDFs and the
app's Word output say the same thing (owner: "reference document + system
generated word + pdf all must be in sync"): the Proforma Invoice and Order
Confirmation carry "Buyer's PO / Ref No."; the Debit Note's To (Buyer) has
the full buyer field list; the BL Instruction's section 8 has no grey notes
under the copies and ends with "Original BL release: CHA to hand all 3
originals to NexaCrest only — never to the buyer or anyone else."
(`buyer_documents/` both tiers and `internal_documents/BL_Instruction.docx`);
the Commercial Invoice no longer mentions an IGST refund and its amount in
words is a bold placeholder the app fills. See `reference/NexaCrest_Change_Log.txt`
(1–2 November 2026) and `reference/NexaCrest_Developer_Spec.txt`.

