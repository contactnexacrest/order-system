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
  Endorsement, BL Reasoning Guide). Never shown to a buyer.
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
runtime. The app's own Twig templates (`PHP_MySQL_Version/app/templates/`)
and DOCX builders are what actually render buyer-facing PDFs/DOCX files, and
they are expected to match this folder's content. When this folder is
updated, the application templates need a corresponding update pass — they
are not generated from these files automatically.
