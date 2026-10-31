# Owner Decisions Register — NexaCrest export order system

**Last updated 30 Oct 2026**

Owner: Gulmohar Sontakke, Founder & MD, NexaCrest International. Applies to both stacks (`PHP_MySQL_Version` and `NODE_MySQL_Version`). All dates are 2026.

## How to use this register

1. **Check every new test finding here first.** Look up the finding ID in the [Test finding index](#4-test-finding-index), or its topic in [Decisions by area](#3-decisions-by-area).
2. **If it matches a decided point, apply the decision without asking again** and cite the register row (for example "per D-041"). If a decided point has regressed, fix it back to the decision. That is not a new question.
3. **Raise with the owner only:**
   - genuinely new points that no row covers, and
   - points listed under [Open / undecided points](#5-open--undecided-points).
4. **Never re-ask a decided point.** The owner's words (28 Oct): "STICK TO THE DECISIONS WHICH WE HAD ALREADY TAKEN, DO NOT REPEAT THE SAME QUESTION AGAIN."
5. **When the owner makes a new decision, record it here** the same day:
   - Add a new D-row at the end of the right area table. Do not renumber existing rows; D-numbers are stable.
   - Add the finding to the index.
   - If it replaces an earlier decision, update that row and fill in "Was".
6. The standing rules (R1, R2, …) apply to every change, whatever the finding.

How to read the tables:
- **Decision** is the *current* decision: what the app does, or what the rule is.
- **Was** is filled in only when the owner changed an earlier decision.
- **Source** lists the finding IDs the row settles and the commit(s) that built it. Commit hashes are short and come from branch `claude/app-creation-review-lxxexc`.
- "S1 / S2 / S3" are owner-session transcripts: S1 is 24–25 Oct, S2 is 25–29 Oct, S3 is 29–30 Oct. Labels like "S2 D-13" are the item numbers in those session extracts.
- "Confirmed twice" means the owner gave the document double confirmation (R6).

---

## 2. Standing rules

These apply to every change, in addition to the decisions below.

| # | Rule | Since | Source |
|---|---|---|---|
| R1 | **Parity.** PHP and Node must always behave the same; every change goes into both. Mirror every UI change to all of its counterparts (PHP/Node, Standard/Kanban views) without being asked: "DO THIS ALWAYS. DON'T WAIT FOR ME TO ASK." | before 24 Oct | CLAUDE.md; S1 standing rule 1 |
| R2 | **Verify before pushing.** Run PHPUnit, Jest and the Playwright e2e suite (`e2e/`) against both stacks. Never push code that has not been verified, even to keep it safe. | 25 Oct | CLAUDE.md; S1 DP-4 |
| R3 | **Ask before every push.** Never push to git without asking the owner first. When asking, give a short summary: what is being pushed, which files change, and which functionality is affected. Was: "push and push always, do not wait for me" (26 Oct), changed to ask-first (27 Oct) after the Supplier PO PDF mistake. | 27 Oct | CLAUDE.md (326d7ad, d47ca81); S2 P-6, P-7, P-8 |
| R4 | **Review pack with every push request.** It has 9 parts: (1) what changed, one plain line per fix; (2) before/after screenshots on both stacks for everything visible; (3) "try it yourself" steps: user/role, which order, what to click, what should happen and what should now be refused; (4) roles affected; (5) test results on both stacks, with failures explained; (6) risks and what was deliberately not changed; (7) live-site steps (migrations, in order); (8) files changed, grouped; (9) the decision asked: push / don't push / change first. | 28 Oct | CLAUDE.md; S2 P-9 |
| R5 | **One branch.** Work and push only on `claude/app-creation-review-lxxexc`. Never create a new branch. The owner will rename it (to `main`) later. | 25 Oct, 29 Oct | S2 P-1, P-14 |
| R6 | **No change to a generated document without double confirmation.** This covers PDF or Word output, templates, the document data assembler and document builders. First show the owner what the document shows now and what it will show after; then get confirmation twice. All current PDFs are correct as they are. Data may change, but the **format of client-facing documents must stay intact**. | 27 Oct | CLAUDE.md; S2 D-5, D-6, D-7 |
| R7 | **Mockups first.** For a larger UI redesign, show mockup images or screenshots and get confirmation (usually twice) before building. | 26 Oct | CLAUDE.md; S2 U-9, U-16, C-2 |
| R8 | **Discuss before writing on business-sensitive changes.** When the owner asks "do not write until I double confirm", present the plan and the recommendation and wait for an explicit go ("go ahead", "proceed", "yes, will go with your recommendation"). This covers permissions on financial data, CAFIN, payment wording and the placement of benefits. | 24 Oct onwards | S1 standing rule 2; S2 standing rule 14 |
| R9 | **Give recommendations point by point**, even against the owner's own first answer ("give my answers and your recommendation immaterial what i answered"). | 24 Oct | S1 standing rule 3 |
| R10 | **CAFIN must never reach the client, at any cost**: not by download, link, email or portal. CAFIN data is never sent to Zoho either. | 26 Oct | CLAUDE.md; S2 C-1; S2 Z-1 |
| R11 | **Supplier and buyer never cross.** Vendors and CHA never see the client's details, terms or amounts, and every link is specific to its function. The owner confirmed on 28 Oct that this is "already taken care". It is not an open item, and no document change follows from it (D-063). | 27 Oct; closed 28 Oct | S2 R-1, R-2 |
| R12 | **Browser-side validation on every form, existing or new.** The message shows beside the field, and the server checks stay as well. The check lives once, sitewide, in `public_html/assets/js/app.js` (PHP) and `public/js/app.js` (Node); extend `fieldError()` there for a new rule. Use HTML attributes for simple rules. A "Re-enter …" field is named `<name>_confirm` (or `confirm_password`) or carries `data-match`, and is checked against the field it confirms. | 27 Oct | CLAUDE.md; S2 V-1, V-2, V-3 |
| R13 | **MySQL-safe database changes.** The host runs MySQL 8 while dev runs MariaDB. Never use `ADD/DROP/CHANGE COLUMN IF [NOT] EXISTS` or `CREATE INDEX IF NOT EXISTS`; guard with information_schema + PREPARE instead (see `docs/migrations`). Queries must pass ONLY_FULL_GROUP_BY. Migrations must be safe to run twice, set the character set, and run in filename order. | 27 Oct | CLAUDE.md; S2 X-1; L-31, L-32, L-20, R5-08 |
| R14 | **SOP.** Edit `docs/SOP`, then copy it to `PHP_MySQL_Version/docs/SOP` and `NODE_MySQL_Version/docs/SOP`. | 27 Oct | CLAUDE.md |
| R15 | **Zoho change log.** Every change made in Zoho is logged at the time it is made in `docs/zoho/zoho-change-log.csv` (UTF-8 with BOM), with the full column set in CLAUDE.md. This applies whoever makes the change, whether by hand or by script, and whether the data is test or real. Read-only checks get a row too. Test records start with `TEST-` and are removed before go-live, and the removal is logged. | 29 Oct | CLAUDE.md; S2 Z-10 |
| R16 | **Zoho never affects core functionality.** With Zoho off, the app works exactly as before. Nothing from development or testing may ever reach the GST/government portal. | 29 Oct | S2 Z-11 to Z-13; S3 |
| R17 | **Secrets never in chat or git.** Zoho keys, passwords and tokens go only in the server's environment settings. | 29 Oct | S2 Z-8; S3 |
| R18 | **Never re-ask a decided question.** Check this register first (see "How to use"). | 28 Oct | S2 P-10 |
| R19 | **New QA round method.** (1) Compare every file and detail in the QA package (deep audit, not superficial). (2) Ask the owner all needed questions first, in one go. (3) Fix everything priority-wise on both stacks. | 28 Oct | S2 P-11, P-12; S2 §15 |
| R20 | **Describe changes by what they do**, not by internal batch or option labels. | 28 Oct | S2 P-13 |
| R21 | **Owner material stays on owner hosting.** Do not publish owner documents or guides as claude.ai artifacts. Screenshots and gallery images are not committed to git. | 25 Oct, 27 Oct | S1 G-3, G-4; S2 P-15 |
| R22 | **No skipped tests.** Fix a test or remove it. Tests that need a PDF tool fail loudly instead of skipping. | 26 Oct | S2 Q-1, Q-2 |
| R23 | **Everyone sees only what they can use.** Links, menus, buttons and forms are shown only to users whose permission allows the action. The server refuses the action regardless. | 22 Oct | U-01; R5-04, R5-19, R5-20, R5-22 |
| R24 | **Indian Standard Time everywhere** (Owner Decision #5): server process, database session, timestamps and documents. | 27 Sep | DEF-04; L-21; UR-6 |
| R25 | **The order record is self-contained.** Every amount and every snapshot (client, supplier, incoterm, currency, port, terms) is stored against the order when used. The order never depends on a master record that may change later. | 22 Oct, 25–26 Oct | S2 B-5, M-6; 1260e2b, 8255d62, 2358013 |
| R26 | **Reasons and skips.** Every typed reason is at least 10 characters (sitewide). A prompt that is too short asks again and says the length needed. A skip always needs a reason and is audit-logged. Nothing is silently skipped or silently set to 0. | 24–25 Oct | ReasonValidator; S2 V-4; S1 C-1 |

---

## 3. Decisions by area

### 3.1 Stage gates and order-page flow

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-001 | **A stage passes only once its document is approved.** A stage that produces a document is complete only when that document is approved (mail off) or sent (mail on). The confirm buttons are refused until then, and the Generate tick appears only after it. Internal documents (Supplier PO, BLI) need approval only. | 25 Oct | 27 Sep: "approved" only (Owner Decision #1) | GATE-01; S2 S-7, S-8; 4217178, 5d7909b |
| D-002 | **A gate can pass only on a stage that has been reached** (in progress), and only on an active order; lost or completed orders cannot be pushed through gates. Actions sent out of sequence are refused. | 27 Sep | | QA-1, GATE-06, L-35; 3d2d7e7, 19739b1, 0fa1309 |
| D-003 | **Steps run in sequence.** A step stays locked (controls disabled) until the step before it is *resolved*, meaning completed **or skipped**. This is enforced in the screen and on the server. Exceptions: Steps 1, 2 and 3 are independent of each other, and Freight is skipped automatically on FOB. | 25 Oct | | S1 S-7; S1 S-1; 579e32b |
| D-004 | **Sub-steps lock strictly in order.** The next sub-step opens only when the previous one is done; for example, Buyer PO generated → approved → uploaded → next. Every Skip button needs a reason and is audit-logged. | 26 Oct | | S2 S-9; 2032cb6 |
| D-005 | **A later step done means the earlier steps are done.** The exception is honest statuses that stay visibly open, such as Step 8 or compliance tasks still outstanding. | 25 Oct | | S1 S-9; S2 S-4; 197850e |
| D-006 | **"One door at a time."** After any action the page returns to the step being worked on, with the sub-step ticked, or opens the next step if this one is done. | 25 Oct | | S1 S-11; S2 S-1, S-6; 197850e, c650d52, d414d72 |
| D-007 | **Step numbering.** Steps are numbered 1–19, and each parent step groups its sub-steps (4a, 4b …). A **numbered step is mandatory; an unnumbered card is optional or for reference** (Amendments and Payment Overview stay unnumbered). Every badge uses the same style. | 24 Oct | | S1 S-1, S-3, S-4; 6eb294a, e5db2e5 |
| D-008 | **Step 4 (Buyer PO):** 4a Generate Buyer PO → 4b Send the Buyer PO Action Link → 4c Attach the signed Buyer PO copy (**required**; staff upload it or the buyer uploads it) → 4d Confirm Buyer PO received. The buyer's own upload completes Step 4 automatically. Step 7 follows the same pattern: attach the supplier's signed copy, then confirm. | 26 Oct | 25 Oct: 4a Generate → 4b Attach → 4c Received; the link was moved into Step 4 on 26 Oct. The owner chose "4b required" against the "optional" recommendation | S1 S-9; S2 S-2, S-3, S-17; 3cbb7dc, 7cc280a |
| D-009 | **Step 5c (Generate PI)** stays locked until the client's PI details form is applied. If the client will not use the form, staff can skip it with a reason ("Client won't use the form"), and the skip is audit-logged. | 26 Oct | | S2 S-10; 2032cb6 |
| D-010 | **Step 6 (OC):** 6b Generate the Buyer Action Link for the OC; 6b turns green only once the OC is approved or sent. 6c Record the buyer's acknowledgement, done by staff or by the client. The OC must be approved or sent before the acknowledgement; the 6c wording says so. | 26 Oct; 30 Oct wording | | S2 S-13, S-18; S3; 2032cb6, 9523924, 2bca566 |
| D-011 | **Step 7 (Supplier PO):** 7c Supplier acknowledgement, either through the no-login Supplier Action Link (valid 60 days) or by Skip with a reason. 7d Confirm supplier signed, which then opens 7e. Step 7 stays open while the supplier advance is unpaid. | 26–27 Oct | | S2 S-16, S-20; 0cd9459, 1f6eb6d, 339c63d |
| D-012 | **Supplier payments:** 7e advance, 10a balance (paid before the packing list). 10a waits for 7e whenever the Supplier PO plans an advance. **At 0% supplier advance, 7e completes by itself** ("No advance on this order"). A blank supplier advance counts as 0 on both stacks. | 26–28 Oct | | S2 S-12, S-19, M-10; R5 extra (7e parity); 2032cb6, b3cce3e, 7ae7c5a, 7916efd |
| D-013 | **Step 8 (production)** is ticked only after a real entry; "Not yet commenced" does not count. **Group C waits for Group B.** | 27 Oct | | S2 S-20, S-21; 339c63d |
| D-014 | **The compliance checklist runs in two phases.** Due Diligence and ECGC open Step 7; Fumigation, Phyto and PSI open Step 10. Admin can change a task's phase, and each task is marked independently. While tasks are open, Step 8 stays amber with a count ("N compliance tasks still open"). | 26 Oct | 24 Oct: a single card, Step 8b | S1 S-3; S2 S-5, S-11; 6eb294a, 2032cb6 |
| D-015 | **Mark Fully Settled is blocked** while any compliance task or eligible government-benefit scheme is unresolved. The override needs `edit_locked_data` and a reason. | 24 Oct; 27 Oct | | S1 C-4; S2 S-24; 6eb294a, 04cf180 |
| D-016 | **Step 13 order:** 13c Save BL Endorsement details → 13d Generate/print the BL Endorsement → 13e Mark original BLs endorsed → 13f Close Order (document despatch) → 13g Government Benefits — Claimed & Received → 13h Mark Fully Settled. 13h never shows green before it is actually done. | 27 Oct | 26 Oct: 13c–13g without the benefits step | S2 S-14, S-15, S-24, F-4; e96cbf4, 04cf180 |
| D-017 | **Close Order requires the BLI and COOPREP to be approved or sent.** The override needs `edit_locked_data` and a reason. | 22 Oct | 14 Oct: a non-blocking hint only (#57); reversed at the owner's request on 22 Oct | #57; c333da1 (25987a3) |
| D-018 | **Close Order does not check that original BLs or the endorsement are in.** By design: this is a staff-trust decision. | 26 Sep | | QA-4 P0.5; 8543e0c |
| D-019 | **Locked and closed orders.** Every order-changing action is refused on a closed order. Editing a locked order needs `edit_locked_data` and a reason. On a settled order, a production-status change needs an override and a reason. An order can no longer be marked Lost once a BL exists. | 22 Oct; 28 Oct | | L-28, R5-01; 9973a14, c333da1, cdee07c |
| D-020 | **Unpaid vendor bills at 13h are a warning, not a block** ("accounts may settle vendor bills later"; CHA and logistics are paid in India). | 27 Oct | | S2 S-23, F-7; e8006f4 |
| D-021 | **FOB orders.** Step 9 (Freight Payment) is a greyed-out "Not applicable — FOB" card that cannot be opened. Indicative Freight and Insurance are hidden. The "Letter of Credit & Bank Loan" block shows only on LC orders. | 25 Oct | Earlier on 25 Oct the freight section was simply hidden on FOB (267cf24); the owner then asked for it greyed out | S1 S-1(7), S-12; S2 S-22; 267cf24, 904c9df |
| D-022 | **The payment preset cannot be edited on Edit Order (by design).** An order's terms change only through an Amendment. | 24 Oct | | S1 S-2 |
| D-023 | **Every gating step shows its document checklist before the click.** Steps 4d, 5e, 6c, 7d, 9d, 10f and 11d say which documents still need generating, approving or sending, and the button stays disabled until they are done ("we need this for every document generated"). | 30 Oct | | S3; 2bca566 |
| D-024 | **Step 8a hint:** only the estimated shipment date notifies the client; the production status is internal. | 30 Oct | | S3; 2bca566 |
| D-025 | **Signed copies are required by default.** The Buyer PO (Step 2/4) and Supplier PO (Step 5/7) gates need the signed copy (wet-signature flags on by default). The supplier-signed and balance gates need their document. | 26 Sep; 14 Oct | | #106, #52/#53; 26660e0, b1db466 |
| D-026 | **OC acknowledgement override.** A Super Admin can override the OC acknowledgement, and the override is visible even before the OC has been emailed. The acknowledgement is asked from the buyer only once the OC is sent (or approved, with mail off). | 14–15 Oct; 21 Oct | | P0 blocker, #30/#23, UR-10; b70ae70, 5de1c66, e8fba9c |
| D-027 | **Regenerating an earlier document after later stages have passed** needs a mandatory confirmation, and the later stages then show a persistent "DATA MAY BE STALE" flag. Later stages are **not** cancelled automatically (open: see O-1). | 23 Oct | | M-08 (partial); 9c0b3f4 |

### 3.2 Order page UI

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-028 | **The 19 steps sit in five colour-coded sections:** A Order Booking (1–6) blue; B Procurement & Production (7–8) green; C Shipment & Balance (9–11) amber; D Closure & Settlement (12, 13, 19) purple; E After-Sales & Records (14–18 + Amendments) teal. Only the current section is open on load, and the current step has a gold outline (the active section has a gold border and tint). | 26–27 Oct | | S2 U-3, U-9, U-17; eb5f482, 784dba1, 0b111ab |
| D-029 | **Cards pinned above the sections:** Payment Overview, Document Review and Order Communication. Order Communication is always open. Document Review opens by itself only when a document is waiting on someone. | 26 Oct | | S2 U-9, U-12; 784dba1, 0cd9459 |
| D-030 | **Documents section:** open if documents exist, otherwise collapsed. A pending-action badge shows documents not assigned, not approved, or approved but not sent. Regenerating shows an "already generated" note first. | 26 Oct | 24 Oct: hard-coded open (bug) | S1 S-1(1); S2 U-5; 2032cb6 |
| D-031 | **"At a Glance" is a full-width strip at the top** (5 columns). The All Documents quick links sit behind a ▸ button. | 27 Oct | | S2 U-19; 8fe99f8 |
| D-032 | **Collapsible toggles are visible buttons sitewide** (light blue, bold navy, ▸/▾). | 27 Oct | | S2 U-18; 8fe99f8 |
| D-033 | **Order Financials is one card with 5 tabs:** Costs, Export Benefits, Zoho Expenses, Profitability and Internal Annexure. Inspection fee, travel, LC & bank loan and other costs sit under Costs. | 25 Oct | The recommendation said "a separate tabbed card"; the tabbed card inside Order Financials was built and the owner has not objected | S1 S-8; 579e32b |
| D-034 | **One save button per form section:** one Save for paired fields, one "Save Checklist" per compliance card (all or nothing), and one "Save All" for export-benefit schemes. | 26 Oct | | S2 U-8, U-11, U-13; e96cbf4, 0cd9459, 75d4fb3 |
| D-035 | **Clear action buttons:** "₹ Mark Paid" is a solid green button. "+ Add a cost" is collapsible like "+ Add an expense". Money amounts always show thousands separators (for example in the 10a warning). | 27–28 Oct | | S2 U-21, U-22, U-23; 8fdafcd, 4fdf553, 0cf8905 |
| D-036 | **Crate rows take the HS code from the order's products**; it cannot be edited there. | 26 Oct | | S2 U-14; 1f6eb6d, 0cd9459 |
| D-037 | **Step 11a tells staff to upload the BL copy first.** | 26 Oct | | S2 U-15; 0cd9459 |
| D-038 | **Order feedback form:** the text box is pre-filled with a generated sample testimonial (different per order), with an instruction line below it. | 26 Oct | | S2 U-7; e96cbf4 |
| D-039 | **The supplier-type picker on the order page stays as it is.** The proposal to remove it was withdrawn by the owner. | 27 Oct | | S2 U-20, B-13 |
| D-040 | **Small layout rules:** the client name links to the client's page; assignee names wrap instead of being cut; step connector arrows are not cramped; there is padding below "Add assignee"; ETD/ETA are spelled out; screens use plain words (no developer jargon, "Debit Note" not "Freight Debit Note"). | 22–26 Oct | | S1 S-6, S-12, S-13; S2 U-1, U-6; U-08, U-09, U-10, #20/#79; 579e32b, e96cbf4, 1e211b0, f2b4af1 |
| D-041 | **Badges and counters:** My Reviews, QT intake, PI intake, Email Approvals and the navigation all show number badges. The order page shows sub-step badges, and Generate Packing List has a step badge. | 14–26 Oct | | S2 U-10; UR-16, UR-19, #80; eca8062, 1bf4c5f, 32f16e4, cdba905, 712f510 |
| D-042 | **Lists and order of items:** the Orders list shows 25 per page. Equal timestamps sort in a stable order. | 22 Oct | | U-02, U-14; 1eeae4a, 1e211b0 |
| D-043 | **Phone, older Safari and accessibility.** Minimum text is 11/12px, tap targets at least 24px, and inputs 16px (no iOS zoom). The stepper has a scroll hint and short labels on phones. A focus-ring fallback and class-based fallbacks are built for Safari/iOS 14–15.3. Every field has a visible label, an accessible name and a placeholder. The Poppins font is served from our own server. | 22–28 Oct | R5-10: first "No, ignore" (28 Oct 03:39 UTC), then "Both" (28 Oct 08:34 UTC), so the fallbacks were built | U-03, U-04, U-05, U-12, U-13, U-20, U-22, R5-06, R5-10, R5-11; S2 U-24; b4d997c, 74cccbd, 81f14ca, eb48ef3, 1e89633, 7916efd |
| D-044 | **Favicon:** design B3 (gold square, navy "OS", navy border). | 25 Oct | | S2 U-4; 85feb2c |
| D-045 | **Kanban and order-page redesign (Concept C) kept.** The order screens were redesigned to Concept C, and a Kanban board exists alongside the standard view; both follow R1 (mirror changes). | 30 Sep; 23 Oct | Task #36 0–17 sequence later renumbered 1–19 (D-007) | Feedback 5–10; Task #36, #37, #40–#44; 76263cc, 795db8c, 5ab4883 |

### 3.3 Documents (PDF / Word)

Every change in this area follows R6 (before/after, confirmed twice). All current documents are the approved baseline.

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-046 | **No checkboxes on any generated document.** Print the firm value already chosen in the app; on the BLI this means container load type, container size and freight terms. **The only exception is the COOPREP physical tick-list, which keeps its checkboxes.** | 25 Oct | | S1 D-3, D-4; 82ca9c0, f03bdd2 |
| D-047 | **The BL Endorsement (BLE) is final and print-ready.** It carries no "how to use" box and no disclaimer. | 25 Oct | | S1 D-2; 82ca9c0, f03bdd2 |
| D-048 | **FOB documents print just "NIL" for freight and insurance**, without "arranged by buyer" notes. | 25 Oct | | S2 D-2; 956cf07 |
| D-049 | **CFR: the buyer insures.** Insurance prints as NIL / "Buyer's responsibility". The Debit Note total and the profit exclude insurance, and insurance inputs are shown only on CIF. The CFR line reads "Freight is paid separately against our Debit Note." CIF and FOB wording is unchanged. Confirmed twice. | 28 Oct | | Q-R5-2; S2 D-11, D-16; 380c2e0, a9d9a10 |
| D-050 | **PI "ADVANCE PAYABLE NOW" box (amber).** The box shows the amount, the due date (the PI's valid-until date) and the balance "not payable now" with its terms. It also holds the bank-charges sentence in bold: *"All bank charges outside India are for the Buyer's account — please remit the full amount (charges option "OUR")."* The same sentence, from one place in the code, appears as a **Bank Charges row on the CI**. The PI also gets a cleared-funds line in its Payment Terms, and a 6-page PI is accepted. Confirmed twice. | 28 Oct | | S2 D-13, D-14; 6510161 |
| D-051 | **Word documents count "Calendar Days"** for buyer payment periods (QT/PI/OC/FDN), and the FDN due date and overdue alert count calendar days. The Word CI balance follows the order's real terms. "Working Days" stays only for NexaCrest's own obligations (BL courier, inspection notices, dispute replies, defined contract terms). The Sales & Supply Agreement is unchanged. Confirmed twice. | 28 Oct | | S2 D-13, D-15; 6510161 |
| D-052 | **Letter of Credit orders (0% advance) never show "0%" wording.** The PI box reads "NO ADVANCE PAYABLE — the full FOB value … is payable by Letter of Credit", with no cleared-funds line. Payment Terms read "100% Payment on FOB Value by Letter of Credit"; production starts once the LC is opened and confirmed by NexaCrest's bank. The QT carries the same wording. The OC banner reads "confirmed upon receipt of the Letter of Credit", with a single "Payable by Letter of Credit" row. The Legal Terms definitions and the OC "BL released only after 100% balance … CLEARED" line are unchanged. Confirmed twice. | 28–29 Oct | | S2 D-16, D-17, D-18; a9d9a10, fdebcae, 0813e9c |
| D-053 | **OC and CI payment figures:** the OC shows the FOB order value and the advance actually received; balance = order value − actual advance. The CI uses the same balance, and labels are in the order's currency. | 26 Oct | | S2 D-3; 9523924 |
| D-054 | **The CI balance clause follows the order's own payment terms** (`balance_terms_text`). The separate "CI balance days post-BL" setting was removed. | 23 Oct | First rejected as "by design" (22 Oct); fixed in the Round 4 retest (23 Oct) | L-06; ed09827, c2999bf |
| D-055 | **CI invoice date:** today by default. It may be backdated (not before the order was created) but never set in the future. The COO prep sheet prints the same date, and the old BL-date warning is gone. Confirmed twice. | 27 Oct | | S2 D-8; e155c74 |
| D-056 | **Supplier PO at 0% advance** prints "Advance (0.00%) * — 0.00" and the full balance, not "To Be Confirmed". **A 0% GST** prints "0.00% — 0.00" on both stacks. Confirmed twice. | 27–28 Oct | | S2 D-9, D-10; R5 extra (0% GST); 7ae7c5a, 7916efd |
| D-057 | **PHP and Node documents have the same page breaks and page counts** on all 12 document types. Only the page breaks were changed; wording, figures and fonts were not. Confirmed twice. | 28 Oct | 23 Oct: page counts pinned as they were (owner decision), then matched on 28 Oct | L-17, N-14, R5-12; S2 D-12; eb09fb7, 38c6610, 380c2e0 |
| D-058 | **Signatures and seals print as images**, not black boxes. Every signature block carries text identifying the document. | 25 Oct; 21 Oct | | S1 D-6; S2 D-1; aab4334, f7caafa |
| D-059 | **RBI purpose codes: no change.** Buyers are not told to confirm them. NexaCrest checks the codes with its own bank or CA from time to time. The PI carries one RBI code, and the settings use dropdowns. | 25 Oct | | S1 D-1; #31; 82aa690, d3af636 |
| D-060 | **Certificate of Origin type** carries everywhere (client, PI, order). The client forms offer a dropdown plus "Not sure"; loose spellings are matched, and a blank answer keeps the value already on file. The server accepts only valid values ("TBC" as bare text is refused). | 26 Oct | | S2 L-2; L-08, R-13; 485fd59, e18da55 |
| D-061 | **The Buyer PO, BLI and COOPREP use the consignee's details**, not the buyer's. The "Same as Buyer?" rows were removed from documents. | 15 Oct; 21 Oct | | #11, UR-17; a68a0a8, 49810b1, 22087cf |
| D-062 | **Buyer-facing documents say "Debit Note"**, not "FDN". The freight Debit Note is a pure cost reimbursement with no GST on the PDF; Zoho books it as a zero-rated export invoice (D-166). | 21–22 Oct | | L-07; 14157a8, 8908356 |
| D-063 | **The Supplier PO keeps printing as it does now, including the buyer's references.** A version without client data was built and reverted on 27 Oct ("revert immediately … all PDFs are correct"). On 28 Oct the owner said the separation is "already taken care". | 28 Oct | 27 Oct: built (31e6036) and reverted the same day (c09a3fb) | S2 D-5, R-1, R-2, L-8 |
| D-064 | **Fixed document wording and layout.** The Natural Stone clause reads "quality … is as agreed" (no "Grade A"). The QT has the late-acceptance clause after "Valid Until". The Legal Terms are set per document type and are the same on draft and final. The client agreement T&C footer and the markdown-lite T&C remain. The Annexure prints its number, and editing or resending an approved Annexure warns first. | 14–22 Oct | | L-05, L-09, #17, #71, #73/#74, Batch 3 #12; ed09827, ee175f1, 78a42d5, 309dd7a, 9b004d5, 2b85421, 2a66d23 |
| D-065 | **Every page carries the reference and the watermark.** Images are optimised, so files are small, and there are no "?" glyphs. The CI total includes freight and insurance on non-FOB orders. The watermark "both" mode uses its image, and a blank watermark setting means the default. | 21–28 Oct | | L-04, L-45, UR-7, UR-18, #7, R5-28; c754d58, 56e5b14, daad077, 3a74557, 2b619c3 |
| D-066 | **The generated-document disclaimer is OFF by default.** | 15 Oct | | #21; ef34a5e |
| D-067 | **The Amendment signatory defaults to the MD**; admin can override it. | 21 Oct | | Phase Q2; 8ce4e6e |
| D-068 | **Internal documents never go to the buyer.** This covers the Supplier PO, BLI, COO Prep Sheet, CAFIN and the inspection report: they cannot be emailed or composed to the buyer and cannot be downloaded by the client. | 22 Oct; 26 Oct; 28 Oct | | L-40, R5-23; S2 C-1; 873a828, 0cd9459, d570d23 |
| D-069 | **The COOPREP PDF gets no reference number** (no PDF change). Its review emails and notifications name it by the order reference. | 28 Oct | | R5-25; S2 D-19; 2b619c3 |
| D-070 | **Document review rules.** An inactive user cannot be assigned as reviewer, and the same reviewer cannot be assigned twice. The person who generated a document may assign its reviewer, since self-review is already guarded (kept as is). Superseded PI revisions do not stay "in review". | 27 Sep; 14 Oct; 23 Oct | | REV-06, REV-07, Q-ROLE-4, P1 #47; 53e2632, cea17e1, 69b6ce0 |
| D-071 | **The BLI shows the BL type and consignee instruction per preset.** The BLI fields are optional, and the BLI and COO Prep Sheet have their own sections on the order page. | 14–21 Oct | | #32, #38/#55; 41bf8a2, d6e4d17, 1621372 |

### 3.4 Payments, terms and exchange rates

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-072 | **Each order keeps its own copy of the payment terms** when it is created, so several splits (40/60, 30/70, 25/75 …) can run at the same time. | 24 Oct | | S1 P-1 |
| D-073 | **Five payment presets, all protected.** Preset names are internal only and never shown to the client. The LC preset is "Irrevocable Letter of Credit at Sight", 0% advance / 100% balance (the stored method stays `LC`). There are two Extended Terms presets: "Extended Terms — Before Shipment" (30/70, cash advance, before shipment, 3 days), followed in order by "Extended Terms — Post-BL". | 24 Oct | The "30/70" preset was renamed "Post-BL" | S1 P-2, P-3, P-4, P-5, P-7; 555d9f9, 01c9388 |
| D-074 | **Zero advance.** The owner's rule: "advance payment will only and only be zero in case of LC". The admin form accepts exactly 0% on any preset. A per-preset "may run without advance" switch was **not** built (open: O-4). | 24 Oct | | S1 P-5, P-6; 01c9388 |
| D-075 | **Risky terms need MD/ED approval before the Quotation** (Option A). Presets marked "Requires MD approval" must be approved by MD/ED (`approve_payment_terms`) before the QT is generated or sent. "Extended Terms — Before Shipment" no longer requires approval; a migration unticks it on the live site. | 28 Oct | | R5-03; S2 S-25, T-4; cdee07c (migration 2026-10-28c) |
| D-076 | **Recording and clearing a payment are done by different people.** Clearing needs `clear_payments` (Accounts, Admin, MD, ED); the Export Executive only records. Payments, shipping and closing are separate permissions (`manage_payments`, `manage_shipping`, `close_orders`) under the role + permission rule (Owner Decision #3). | 27 Sep; 23 Oct | 27 Sep: one `manage_orders` covered all of it | RBAC-03/04, Q-ROLE-1; S1 R-2; fe3d5b2, 69b6ce0 |
| D-077 | **A payment cannot be cleared before money is recorded.** The balance is cleared only after the remittance is recorded. | 27 Sep; 23 Oct | | GATE-04/05/10, N-05; 011663a, 8b2b6e4 |
| D-078 | **Recording the advance:** the amount due is pre-filled. More than the order value is refused, and a difference of more than 1% from the amount due needs a tick to confirm. Step 5d checks the advance amount. | 26 Oct | | S2 D-3; 9523924 |
| D-079 | **Amount and exchange rate are recorded together**, with the read-back "Received X at today's exchange rate of Y". There is one rate field, labelled "@ Exchange Rate * (₹ per 1 USD)"; the separate balance-rate box was removed. Once recorded, the rate is changed only through a privileged, collapsed **"Correct a wrongly typed rate"**, which needs a reason and is audit-logged. Remittance inputs show their currency. | 26 Oct | 26 Oct: an open "Update Rate" button was hidden | S2 M-1, M-2, M-3; #82; 2032cb6, e96cbf4, 0cd9459, 1f6eb6d, cfb2595, fa9a01e |
| D-080 | **Payment Action Link (no login).** The client uses it for payments only, one payment at a time, and a screenshot is required: the PI advance (box at 5d), the Debit Note freight on CFR/CIF (9c) and the CI balance (11c). The link never shows the Buyer PO or the OC. Its instructions are included in the PI, Debit Note and CI emails. | 26 Oct | | S2 M-4, L-5; 9523924 (migration 2026-10-27b) |
| D-081 | **The order ledger is complete and gated by permission.** Money out and INR/profit figures show only to holders of the Order Financials permissions. | 26 Oct | | S2 M-5; e96cbf4 |
| D-082 | **Formal order cancellation:** a reason, approval by another person (strict: only a Super Admin may approve their own), refund handling and audit. | 23 Oct | | M-09, N-04; 15b6ce5, 8b2b6e4 |
| D-083 | **Foreign-currency amounts are converted at the order's rate before being treated as INR** (for example the travel %). The four financial formulas are pinned by tests, and Supplier PO payments are validated. | 27 Sep; 23 Oct | | QA-4 P1, N-03, R-11; 870d1c8, c7c3ec9, 2a22f6f |
| D-084 | **Buyer money arrives in INR in the SBI current account only** (no EEFC account). This is the Zoho deposit account. | 29 Oct | | S2 M-11 |
| D-205 | **Payment terms are never approved on a cancelled or lost order.** The approval is refused with a message and nothing is recorded. | 30 Oct | | R6-N13; R6 Batch 4, 92512ba |

### 3.5 Suppliers, logistics partners and vendor bills

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-085 | **Suppliers directory**, managed like Logistics Partners: contact fields, **several types per supplier** (the owner chose this against the recommendation), and delete only if never used (otherwise deactivate). The new permission "Manage suppliers" defaults to Admin, MD, ED and Super Admin. | 25–26 Oct | | S2 B-1, B-2, B-3; fc62b43 |
| D-086 | **No supplier editing on the order page.** The quick "Add Supplier" form and "Manage factory addresses" were removed; factory addresses are managed only on the supplier page. The order stores its own snapshot of the supplier details (R25). | 25–26 Oct | | S2 B-4, B-5, B-6; b3cce3e |
| D-087 | **The freight forwarder is picked from the Logistics Partners directory** ("Freight Forwarder" type). | 27 Oct | 26 Oct: first built as a supplier type | S2 B-7; b3cce3e, 04cf180 |
| D-088 | **Vendor bank details** (suppliers and logistics partners): the account number is stored encrypted, shown masked and typed twice, and the IFSC is checked. "Pay to:" shows at 7e/10a. A no-login **Vendor Details Link** (single use, 30 days) lets the vendor fill them in; staff may also fill them in. Either way they stay "not verified" until staff tick verified. | 27 Oct | | S2 B-8; 04cf180 |
| D-089 | **Bill first, then pay** (with the UTR). Supplier bills come in at 7e and 10a. A **Logistics Partner Bills & Payments** card is available on every incoterm, including FOB; CHA and transport can be billed separately or combined. | 27 Oct | | S2 B-10; e8006f4, fcca02e |
| D-090 | **Logistics bill timing:** each logistics bill is marked **Advance** or **Final**. An Advance bill may be paid any time. On CFR/CIF a Final bill waits for Step 9d (freight payment cleared): logistics are paid only after the Debit Note money is received. Existing bills count as Final. The rule is enforced on the server. | 27 Oct | | S2 M-7, M-8, M-9; e8006f4, 7ae7c5a |
| D-091 | **Proforma versus tax invoice (Option B).** Each bill is a Proforma (not a cost) or a Tax invoice (the cost), and a tax invoice links to and replaces its proforma. A bill can take several payments; overpayment is refused, and a bill with a payment cannot be removed. "Amount asked now" is optional. A proforma payment counts as cost until its invoice arrives, and a supplier payment counts to the step it was recorded from. Existing bills became tax invoices. | 28 Oct | | S2 B-12; 929ffff (migration 2026-10-28e) |
| D-092 | **GST is always picked as a % from a dropdown and the amount is calculated** (the server recalculates). The bill form's GST Rate list is 0/5/12/18/28%, with 18% preselected (admin-editable). | 27 Oct | | S2 V-5; ce06cbf |
| D-093 | **Reverse charge applies only to road transport by a GTA that charges no GST.** It is a choice on transport bills; GST must then be 0%, and Books records it as RCM 5% (CA advice). | 29 Oct | | S3 CA rec 2; 0a12641 |
| D-094 | **Supplier PO entry aids:** dropdowns and automatic calculation, field hints, and lead time with a minimum, maximum and unit. | 15–21 Oct | | #51a, #78; 1bf0cdf, 36fb8cb, ebd63f2, 4624f00 |

### 3.6 Financials, expenses and government benefits

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-095 | **Every order always has a row for every compliance task and every export-benefit scheme.** The rows are created with the order and backfilled for older orders. A skipped or not-eligible item is stored as 0 with its state and reason, never left out, "so it is consistent across the reports". | 24 Oct | | S1 C-1, C-2, C-3; 6eb294a, e5db2e5 (migration 2026-10-24d) |
| D-096 | **Skip choices.** "Not Applicable" fills in its own reason ("Not applicable to this order."), while "Skipped (give a reason)" needs a typed reason; both are saved as skipped. "Mark All N/A" comes pre-filled with the same text. On a benefit scheme, "Not Eligible" fills in "Not eligible for this order." (editable). | 27 Oct | 24 Oct: every skip needed a typed reason | S1 C-1; S2 V-6; 4fdf553 |
| D-097 | **Step 12 (closure financials) uses guided sub-steps** (Option A): 12a Costs, 12b Eligibility, 12c Review, plus 12d Team incentive from 30 Oct. It shows summary numbers and a single "+ Add a cost". | 26–27 Oct; 30 Oct | | S2 U-16, F-4; f0eea6b, 04cf180, 9027f23 |
| D-098 | **Skipping Step 12 needs a reason, and the values stay blank** (no 0 is written). Pending schemes must still be answered before Mark Fully Settled, and "Reopen Step 12" is available. | 26 Oct | | S2 F-1; 1f6eb6d, 0cd9459 |
| D-099 | **Government benefits are claimed and received at Step 13g**, right after Close Order. Every eligible scheme must be received, or skipped with a reason, before 13h; "Skip all" and "Undo skip" are available. The override needs "Override locked data". | 27 Oct | 26 Oct: entered at Step 12c | S2 F-2, F-4, S-24; f0eea6b, 04cf180 |
| D-100 | **A wrongly recorded benefit claim can be corrected or deleted.** It needs a reason of at least 10 characters, is audit-logged, and respects the CA financial-year lock. | 26 Oct | | S2 F-3; b3cce3e |
| D-101 | **All finances are entered order by order in the app**, including order expenses (with a receipt or a reason), for full profit and loss per order. Expenses are de-duplicated against those imported from Zoho. | 27 Oct | | S2 F-6; e8006f4 |
| D-102 | **Travel cost.** A provisional travel % applies until real vouchers exist; the actual travel then replaces the %. A late (back-dated) voucher is allowed with a reason and an "added later" stamp, but never into a CA-locked financial year. | 27 Oct | | S2 F-5; Task #38; e8006f4, 785d807 |
| D-103 | **Cost entries say what they were paid from** ("Paid from"). Only **real paid costs** count as expenses (and go to Books). | 29 Oct | | S3; 1c91e08 |
| D-104 | **Profitability includes export benefits and the CFR/CIF freight leg** in revenue. Report breakdowns are as built in the phased plan. | 15 Oct; 21 Oct | | #54/#85, #83; d6d3e0b, 9490eef, 862cfb4 |
| D-105 | **Every amount is stored against the order**; no duplicated totals sit on the orders row. | 26 Oct | | S2 M-6, B-5 |

### 3.7 CA module and CAFIN

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-106 | **How CAFIN is kept from the client (R10).** The client portal downloads only customer-facing types; CAFIN, Supplier PO, inspection report and BLI are blocked even by ID. Email compose refuses internal documents, and the general file download checks order access, removed files and the CAFIN permission. Export file names end in `_INTERNAL`. Nothing from CAFIN goes to Zoho. | 26–30 Oct | | S2 C-1, C-9; R5-21, R5-23; 0cd9459, 75d4fb3, d570d23 |
| D-107 | **CAFIN can be generated at any time** from a CAFIN box at the top of the order page, at any stage and even on a closed or locked order; there is no "Enable" tick. Generating manually stays with Admin/MD/ED. | 27–28 Oct | Earlier: a per-order "enable" tick | S2 C-3, C-5; e155c74 |
| D-108 | **CAFIN is generated automatically at 13h Mark Fully Settled**, with or without benefits. A failure never blocks settlement, and the manual Generate stays. | 28 Oct | | S2 C-6; R5 Batch 1; d570d23 |
| D-109 | **Seeing and downloading CAFIN needs the "View & download CAFIN" permission** (`view_cafin`), which can be set per role or per person. Seed default: Admin, MD, ED, Accounts Executive, CA. Non-holders see neither the box nor CAFIN in document lists, and every download path refuses them. | 28 Oct | 28 Oct 03:28 UTC: "Admin, MD, ED only + an admin grant option"; final at 03:51 UTC | Q-R5-5; S2 C-4, C-5; d570d23 (migration 2026-10-28a) |
| D-110 | **CAFIN content (sections A–F, approved mockup):** order details, supplier cost, expenses (CHA/transport, ECGC and all others), government benefits, profit, and F. Open items for the CA. CAFIN profit equals the order-page profit. Confirmed twice. | 27 Oct | | S2 C-2; dee767f |
| D-111 | **CAFIN with Option B bills:** Proforma and Tax invoice labels; "asks ₹X now"; "replaced by tax invoice, not a cost"; C2 counts tax invoices only, and total cost and profit follow. The supplier total and cash position are unchanged. The PHP CAFIN may run to 4 pages. Confirmed twice. | 28 Oct | | S2 C-7; 929ffff |
| D-112 | **What CAFIN counts** (all confirmed twice): LC money as money received (revenue, cash position, INR Settlement Register); expenses booked in Zoho Books for the order (C5), with a duplicate guard; on an order switched to LC after its cash advance, the LC as the balance; team incentive and associate commission as Commission cost lines (C3). | 29–30 Oct | | S3; e091d3e, beb849e, 9027f23 |
| D-113 | **CAFIN CSV export** for all orders: a summary file and a detail file, filtered by financial year and status. It has the same access as CAFIN (`view_cafin`), the file names end `_INTERNAL`, and every download is audit-logged. | 30 Oct | | S3; 7b4fd92 |
| D-114 | **The CA role is read-only and sees every order** (`view_all_orders`). The CA cannot edit ("a third-party person"). Order references on CA pages link only when the order can be opened. | 30 Oct | 23 Oct: view-only, no order access; 28 Oct: `view_orders`, assigned orders only; 30 Oct: all orders | Q-ROLE-2; S2 C-8; S3; 69b6ce0, 50c9b1c, 2bca566 |
| D-115 | **CA module features kept:** the financial-year lock is enforced; the INR Settlement Register has an exchange-rate column; CA reports export as CSV. | 26 Sep; 15–21 Oct | | QA-4 P0.3, #56/#84; 701b73d, af7fde1, f996cf8 |

### 3.8 Roles, permissions, order access and confidentiality

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-116 | **Access is role + permission based** (Owner Decision #3). Holding a permission never bypasses the resource's state: for example, a dispute status must be valid, a Supplier PO needs Stage 5, and an amendment reject needs "pending". | 26–27 Sep | | QA-4 P0.5, AMD-03, DSP-03, RBAC-03/04; 8543e0c, fe3d5b2 |
| D-117 | **Maker-checker.** A different person must approve what someone made (reviews, sends, amendments), except a Super Admin or a holder of `manage_permissions`. **Admin/MD/ED may approve their own reviews and sends** (Q-R5-1: "Keep as is"). Cancellations are strict: only a Super Admin may approve their own. | 27 Sep; 23 Oct; 28 Oct | | REV-05/EML-03/AMD-05, N-04, Q-R5-1; S2 R-5; 53e2632, 8b2b6e4 |
| D-118 | **Order visibility by assignment.** An order has 0, 1 or more assignees; the creator is assigned automatically; only Admin/MD/ED add or remove assignees. Holders of `view_all_orders` see every order: Admin/MD/ED, Viewer/Auditor, Accounts Executive, and the CA from 30 Oct. Assignment controls visibility only, not who may act. The rule applies on every order page and action (one shared access check), and archived orders are included. | 24 Oct; 28 Oct | 24 Oct (early): "one owner per order"; changed the same day to a multi-assignee list | S1 R-1, R-3; R5-24; S2 R-6; afe7538, 5019ab4, 9172d56, d19d95e, cdee07c |
| D-119 | **Payment and shipping permissions are enforced on the server**, whatever the assignment. A staff member without the permission cannot advance a payment step; it waits for someone who holds it. | 24 Oct | | S1 R-2 |
| D-120 | **Who handles supplier payments:** `manage_supplier_payments` (Accounts has it) confirms supplier payments, and the Outgoing section of reports is hidden without it. **Profit and margin are visible only to Admin/MD/ED.** | 23 Oct | | Q-ROLE-3, Q-RPT-1; 69b6ce0 |
| D-121 | **The wider confidential-data permission plan stays parked ("leave it as it is").** It proposed "View profit and costs" and "View supplier prices". The owner closed it on 28 Oct as "already taken care"; the CAFIN part was handled by D-109. Do not raise it again. | 28 Oct | 27 Oct: parked "as for now we don't have much staff" | S2 R-3, R-4 |
| D-122 | **Staff can act for a client.** Staff can reset a client's portal password (a one-time temporary password, logged to Admin/MD/ED oversight) and record feedback on the client's behalf (kept separate from feedback the client submits). Admin can view the portal as the client (impersonation, with a visible banner). | 25 Oct | | S1 CP-1, CP-2; cef64e6, 572e6e0 |
| D-123 | **Two-level send approval (mail approval queue) is on by default.** | 25 Oct | | S2 R-8, T-3 |
| D-124 | **User and role administration safeguards.** Saving a role with nothing ticked does not wipe its permissions, and the audit lists the changed permissions. Re-granting a permission is audited correctly. A user delete is refused with a message saying where the user still appears; a password-reset token alone does not block it. Viewer/Auditor has an Operations menu, and the Admin dropdown shows for every role entitled to it. | 14 Oct; 22–23 Oct; 28 Oct | | L-33, L-37, R-08, R5-09, U-18, #34; e18da55, f0adebe, 32dcc3d, 1e89633, eb48ef3, 1da67a4 |
| D-214 | **Organisation design: departments → designations → roles → permissions (accepted; phase 1 built 1 Nov 2026, phase 2 built 1 Nov 2026, phase 3 built 1 Nov 2026, phase 4 built 1 Nov 2026, phase 5 in progress).** Phase 4 (built, both stacks, migration 2026-11-01d): My tasks per department built from order state (hand-off = the next department's task appears when a step is done), due and escalation days per task kind in Admin, one owner per department per order set by that department's manager (MD/ED, Admin, Order Coordinator: any), owner notified and added to the order, management cockpit (open/overdue/escalated per department, unowned, orders by status, escalations, supplier delays — no amounts); the optional Order Coordinator sees every order and task, no money. Phase 3 (built, both stacks, migration 2026-11-01c): purchase requirements raised by Sales on the order (no prices), Procurement queue, supplier quotes compared cheapest-with-GST first, award with approval limits per designation (Executive ₹0, Manager ₹5,00,000, Director none — editable in Admin; no self-approval), receipt with quality check, supplier performance, cancel with reason. Phase 2 (built, both stacks, migration 2026-11-01b): one visibility rule (MoneyVisibility) for selling prices, buyer payments, purchase prices and logistics costs, used by the order page (products, FOB, payment amounts, remittances, Supplier PO values, supplier bills, freight rate), the orders list and kanban, the catalogue costs, the dashboard overdue amounts, the freight-cost report, every document download (money class per type + order access, not only CAFIN), every file-store download and the dossier ZIP; Supplier PO + production need manage_supplier_po (Procurement), freight remittance manage_payments, freight cleared clear_payments, mark fully settled settle_orders (Finance). Phase 1 (built, both stacks, migration 2026-11-01a): departments, designations, memberships on the user page, "Log in as" + switch, Admin "Preview as" (no Super Admin bypass and no personal exceptions while previewing), the acting department on every audit row, personal exceptions with an end date; Admin accounts keep their own role (no membership). Departments: Company Management (MD/ED), Sales & Export, Procurement (new), Logistics, Accounts & Finance, each with its own Executive → Manager → Director roles; plus Audit & Compliance (Internal Auditor, External CA), Administration (System Administrator, no money) and an optional Order Desk (Order Coordinator, status only, switched off). A person can hold several department memberships and chooses "Log in as" after login (switchable). Strict money separation enforced by the server: Sales sees selling side only, Procurement buying side only, Logistics CHA/transport/freight only, Finance + MD/ED + auditors everything. Decisions D1–D12 of the proposal taken as recommended: departments as listed; Executive/Manager/Director ladder, a department Director may also act; Procurement must NOT see any financials beyond purchasing; optional target purchase cost per order; the CI for customs is sent to the CHA by Sales, with Logistics CI download as a logged per-person exception; Logistics sees the client name, Procurement only the order number; Sales sees buyer payment status and amounts due, Finance alone records and clears; only Accounts pays suppliers and logistics; approval limits per level in Admin (Manager → Director → MD/ED); no coordinator now (the system routes hand-offs); MD/ED always see everything and can "view as" a department; build order phases 1+2 (departments + money separation), 3 (Procurement), 4 (coordination), 5 (goods/services engine), each with mockups first. | 31 Oct | D-116, D-121 parked plan | Owner 31 Oct: "every depart mane have there own role"; "go with your suggestion and recommendation for all decisions"; design page https://claude.ai/artifact/3vmZKRGz37kecuqt93pmNS |
| D-215 | **Phase 5 of D-214, first step only (confirmed twice).** Build 5a: order line types Goods / Service / Charge, payment milestone schedules, configurable product attributes per category. Service lines on an export order are invoiced on a separate Export Service Invoice (the Commercial Invoice stays goods-only for customs). Domestic sale (GST invoice in INR) later, when the first domestic order is near. Stone orders keep today's stone wording on every document; goods-only orders with today's presets print word for word as now. 5b (domestic) and 5c (service delivery) not started. | 1 Nov | D-214 | Owner 1 Nov: "yes confirm 5a, D2 separate invoices, D3 later, D4 Stone working / build 5a, separate invoice, domestic later, keep stone wording". Document before/after for the second confirmation: https://claude.ai/artifact/DjSEaGDpG8vbGLD6bZUPhd. Owner 1 Nov: "confirmed 5a documents". Then: "DO NOT ASK ME ANY MORE QUESTIONS, I WILL GO WITH YOUR RECOMMENDATIONS" — so, as recommended: services are paid against their own Export Service Invoice as their own amount (advance and balance stay a % of the goods FOB), and every payment milestone is its own tracked payment step (remittance with bank reference, clear, Zoho). CAFIN not changed for services or milestones (needs its own double confirmation). Built and pushed: 5a-1 line types, 5a-2 Export Service Invoice, 5a-3 payment milestones, 5a-4 product categories with their attributes (Products → Categories; Natural Stone = today's Dimensions/Finish, unchanged). |

### 3.9 Client portal and no-login links

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-125 | **The Buyer Action Link shows one step at a time:** first "Your Purchase Order"; Advance Payment only after the PI; then the OC. The same link serves 4b and 6b. Before acknowledging, the buyer downloads the exact OC and ticks "I have read Order Confirmation … and confirm to proceed". | 26–27 Oct | | S2 L-4, S-17, D-4; 48fba36, 7cc280a, 1c961a6 |
| D-126 | **The link pages look like the QT/PI details pages** (not a single mobile column). The supplier link page has a "Download PO" button. | 26 Oct | | S2 L-3; 7cc280a |
| D-127 | **The buyer's PO upload in the portal closes once the advance clears** (kept as is, recommendation accepted). | 30 Oct | Built 14 Oct; confirmed 30 Oct | #19/#48; S3; 339d7f1 |
| D-128 | **Buyer link security.** The Buyer Action Link token is stored encrypted, and the link stops working once the order is closed. Staff are notified when the buyer acts through it. | 22–23 Oct | | L-24, L-25, L-30/M-07, N-13; 61df7bd, de69df4, ef55f8b, 19fa846 |
| D-129 | **One email address per client.** Duplicates are refused at create, duplicate, edit and intake. A read-only check lists existing duplicates on the live site. | 28 Oct | 27 Sep: an ambiguous login was refused (CP-08) | R5-02, CP-08, L-11; S2 L-7; cdee07c, 02290e9 |
| D-130 | **Client forms carry everything forward.** The PI details form pre-fills everything the client gave at the QT stage. The intake forms have an Incoterm dropdown, a structured billing address and an auto-filled "Acceptance of Quotation No.". Incoterm, container and port carry from the QT intake into New Order, and staff can resend an intake link. | 14–25 Oct | | S2 L-1; UR-2, UR-8, #45, P1 #41/#46; 604c781, c5540a8, f6ca53b, 166f00f, 51c9336, dc06645 |
| D-131 | **Client document downloads** can be configured globally and per client, limited to customer-facing types. | 23 Oct | | M-02; b90d57e, 75d4fb3 |
| D-132 | **Disputes are raised per order (as now).** Complaints are separate from disputes. | 28 Oct; 23 Oct | | Q-R5-3; S2 L-6; Task #39 |
| D-133 | **Client feedback opens only after the shipping documents are sent or the order is complete.** | 23 Oct | | N-12; f88f6e0 |
| D-134 | **Portal account rules.** The portal has forgot-password, an idle timeout, and sessions that end when the client is deactivated. Staff and portal sessions do not interfere with each other, and clients receive self-service notifications. | 27 Sep; 14 Oct; 23 Oct | | CP-06, CP-12, CP-13, L-10, #72/#88, #6, Task #35; aec5939, 26ccdcc, 3306a43, f29c6a5, 518b012 |
| D-206 | **The buyer can report a payment only once the Proforma Invoice has been issued, and never on a cancelled or lost order.** The portal shows a one-line reason instead of the form; the server refuses it on the portal and on the e-mailed buyer link. A file sent under an unexpected field name on the buyer's message is refused with a message and the typed text is kept (both stacks). | 30 Oct | | R6-N24, R6-N03; R6 Batch 4, 92512ba |

### 3.10 Email and notifications

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-135 | **Email only when someone must act, or when a client acts.** No transaction notification may be missed: client-facing sends, client actions and reminders always notify. The audit log is a record only and is never emailed (one order produced about 90 emails). The catch-all "Notification Email" setting and the system-wide action emails were removed. Confirmed on 28 Oct (Q-R5-4: "only when action needed, as now"). | 26 Oct; 28 Oct | 21 Oct: notify every action ("non-negotiable", UR-20); 25 Oct: five-tier routing; 26 Oct: targeted only | UR-20, M-06, N-08 note, Q-R5-4; S1 E-1 to E-7; S2 E-3, E-4; 6174699, 10ddffc, ea559b7 |
| D-136 | **Who receives a notification.** Order events go to the order's assignees plus Admin/MD/ED (oversight), not to all staff. A change to shared data goes to holders of the permission that governs it (ports → `manage_orders`, HS codes → `manage_hs_codes`, security changes → Admin/MD/ED). Critical notifications cannot be switched off. Noise (logins, permission-denied, sample data, Test Mode, bare field edits) sends no email, but the audit row is always written. Disputes and cancellations have targeted notifications, and reminders are not duplicated. | 25 Oct; 23 Oct | | S1 E-4, E-5, E-6, E-7; N-07, N-09; 10ddffc, 36a5cf1, f88f6e0 |
| D-137 | **No hidden copies.** The `.env` default-CC fallback was removed; only the Company Settings Default CC remains. | 25 Oct | Phase 0–6: a default CC from `.env` | S2 E-2; 57e8327, 9d0bb09 |
| D-138 | **With email sending off** (`mail_sending_enabled = 0`, the seed default): "Send to Buyer" is hidden; a buyer-sendable document counts as sent once approved ("auto-complete on approval"); messages never say "emailed". With mail on, sending uses the client's current email when the stored copy is empty or out of date. | 25 Oct; 28 Oct | | S1 E-8, E-9, E-10, S-10; S2 U-2, E-1, T-5; R5-16, #12; 1a88fe4, cdee07c |
| D-139 | **Email hosting stays on Bluehost.** Mail goes through Bluehost SMTP; Zoho Mail is only a mail client. The app's Zoho Mail code is kept but switched off in code, with no setting. | 29 Oct | | S2 E-7; 733d601 |
| D-140 | **A blank SMTP encryption setting means encrypted (STARTTLS/TLS)** on both stacks. | 28 Oct | | R5-17; S2 E-5; 1e89633 |
| D-141 | **Email format.** Customer emails carry a Reply-To (setting `reply_to_email`), readable headers and UTF-8 text. The signature uses the sender's own title and role, never a fallback to the MD's. The first send is Rev.0, and the internal "(approved)" tag is not shown to the buyer. | 27 Sep; 14 Oct; 22 Oct | | EML-07, EML-08, L-12, L-43, L-46, #44; 4be48ce, de69df4, a0350e2, d17fab3 |
| D-142 | **Template and send rules.** A document must be sent with its own template. All system emails are editable templates. "Send to Buyer" exists for every buyer-facing document type (when mail is on). A queued send is cancelled if its document is rejected, and overlapping dispatch runs cannot send twice. `bl_copy_sent` is refused when no BL copy is on file. Email subjects are readable for every entity type. | 27 Sep; 14 Oct; 22–23 Oct | | L-41, L-42, #12, #37, QA-4 P0.4, EML-06, N-06, N-10; eb48ef3, e3bd3c2, 5b1644a, 7822ff6, 3ed1b1c, 19739b1, 8b2b6e4, f88f6e0 |

### 3.11 Form validation

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-143 | **Every form is checked in the browser before submit** (R12), and the server checks stay. A "Re-enter" field must match the field it confirms (bank account numbers are typed twice). Conditional rules (`data-required-if` / `data-required-unless` / `data-zero-if`) and bank-detail rules live in the sitewide app.js. Convention tests enforce this. | 27 Oct; 28 Oct | | S2 V-1, V-2, V-3, V-7; R5-27; 68592e4, 2b619c3 |
| D-144 | **Typed values are kept after an error, and bad input never gives a blank error page.** Too-long text, an impossible date, an invalid choice, a non-numeric amount, list-shaped input, an oversized form or a bad number on New/Edit Order and Freight Terms all show a friendly message with the input kept. Raw database errors are never shown. | 22 Oct; 25 Oct; 28 Oct | | S2 V-1; R-01 to R-07, R-09, R-12, R-14, R-15, R5-29; 569e74a, 636447c, 719e589, 1c7b4b1, 119d62f, c221d87, 2b619c3 |
| D-145 | **The server enforces the same rules when the browser is bypassed.** Examples: emails, amendment numbers, the watermark, container count of at least 1, LC/loan interest no more than 100%, quantity and price positive, an inactive client refused on a new order, an order never left half-created, and setting value types. Reference documents and dropdown options cannot be saved empty. | 27 Sep; 23 Oct; 28 Oct | | ORD-04/05/06, SET-02, N-11, R5-28, R5-30; 3f80b39, b88824a, f88f6e0, 2b619c3 |
| D-146 | **Batch 6 choices.** Settings number boxes refuse letters but are not required. A custom reference entry may be a file only. There is no phone-number format rule. Forgot-password with a blank or malformed email answers "Enter your e-mail address." on the form. | 28 Oct | | S2 §15 (Batch 6 choices, Extra); 2b619c3 |
| D-147 | **Fixed lists are dropdowns, not free text.** This covers country, Incoterm, container type, unit, package type, finish, dimensional tolerance, factory address, RBI purpose code, GST rate and CoO type. Admin maintains them through the generic dropdown admin. | 14–27 Oct | | U-06, M-05, #31, #36, #43/#66, UR-12; 74cccbd, 59453f2, 6a40a03, bdd0119, ad7b4b7, 761a42e |
| D-148 | **Dates:** the Buyer PO received date cannot be in the future or before the order. It is recorded at Step 4d (or as the buyer's own upload day when they confirm through the link or portal), and can be corrected later with a reason (audit-logged). For CI date rules see D-055. | 30 Oct | | S3; a40cd19, 381638b |
| D-207 | **Values the database cannot hold, and impossible ones, are refused with a message — never a 500, never stored silently as something else.** Record ids in a URL must be whole positive numbers (anything else is "not found"); unit prices and exchange rates within the column size and not rounding to zero; client payment reports positive, not in the future, within range; inverted min/max ranges refused (lead time, sailing time, net vs gross weight); impossible months and page numbers ignored. A payment amount and its exchange rate are saved together or not at all. Browser rules added where a form allows it (`data-max-decimals`, `data-max-value`). | 30 Oct | | R6-N05, N07, N14, N15, N19, N20, N21, N22, N23, N28, N29, N30; R6 Batch 1–2, 854a866, aa10739 |

### 3.12 Uploads and files

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-149 | **Order files are stored by order-page step.** Folders are named by step (`01-quotation` … `13-closure`), split into `generated/` and `received/`, and keep random names on disk. A reorganise tool tidies older files. Every generated PDF or Word file goes into its proper folder. A new order gets its current stage at once, so Stage 1 files land in the right place. | 26 Oct | | S2 X-2, D-20; L-14; 9c5740f, 3f79582, 3d50aea |
| D-150 | **Draft and scanned BL uploads accept any common document:** PDF, Word, Excel, images, eml/msg and zip. Programs and scripts stay blocked. | 26 Oct | 26 Oct: PDF only | S2 X-3; L-22; e96cbf4, aee00eb |
| D-151 | **The server checks the real file type.** It reads the leading bytes and refuses a mismatch, with nosniff headers sitewide. Empty (0-byte) files are refused. An oversized or rejected upload gets a clear message ("Upload not accepted"); a PHP limit below the setting shows a warning. | 27 Sep; 22 Oct; 28 Oct | | UP-02, L-15, UP-03, L-16, L-34, R-10, R5-07; affaa75, 26f1b19, 4f59e28, 8268774, e18da55, 1e89633 |
| D-152 | **Removing an additional document needs a reason** (at least 10 characters), and there is a "show removed" view. | 22 Oct | | L-18; 2b5f47e |
| D-153 | **Gallery and screenshots.** Screenshots are not committed to git. The gallery lives in the app at `/gallery` and lists `docs/gallery/` from disk, with no upload form and one seed image in git; the owner uploads the other images to the hosting. | 25 Oct | | S1 G-1 to G-4; 9021c4f, 4533f2d |
| D-154 | **Storage paths.** `storage_base_path` is read-only and documented. Reference Library seeded documents resolve their path at download. | 15 Oct; 28 Oct | | #29/#63, #33, R5-18; c8cf5a3, c73de2c, cdee07c |

### 3.13 Settings and seed defaults

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-155 | **Email sending is off by default** in the seed (`mail_sending_enabled = 0`). | 25 Oct | | S1 E-8; S2 T-5 |
| D-156 | **Order estimate defaults are real values, not placeholders:** CBM 8.2, gross weight 27,000 kg, net weight 26,000 kg, in every form where they apply. The Step 10a packing actuals stay blank (see O-9). | 25 Oct | Earlier default CBM/weights (528f88a) | S2 T-1; d46e46e |
| D-157 | **Company Settings is collapsed by default.** Its categories are collapsible, and sensitive settings carry an alert badge. | 15 Oct; 25 Oct | | S1 DP-5; S2 U-1; #27, #28; ff5b9c6, 3a3df41 |
| D-158 | **The seed includes sample vendors for testing:** Sample Stone Supplier, Sample CHA & Transport and Sample Freight Forwarder. Deactivate them after testing. | 27 Oct | | S2 B-9; 806db9b |
| D-159 | **Sample data supports quick testing:** the pending PI intake sits on order A2, and "Clear sample data" works. | 25 Oct | | S1 DP-6; S2 T-2 |

### 3.14 Zoho Books

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-160 | **Scope.** NexaCrest has Zoho One. **Zoho Books is the CA's official ledger and GST filing tool**, in the zoho.in region. The plan runs in stages 1 → 2 → 3 (existing sync, sales, purchases); CRM comes later. Our PDFs do not change for Zoho, Books invoices are not emailed from Books, and nothing from CAFIN goes to Zoho. | 29 Oct | | S2 Z-1 to Z-4, Z-6; 733d601 |
| D-161 | **Zoho is optional and fenced off** (R16). Three server settings are always written out: `ZOHO_INTEGRATION_ENABLED`, `ZOHO_ENVIRONMENT` (test/production) and `ZOHO_EINVOICE_ENABLED`; a missing or misspelt value counts as the safe choice. In test, only `TEST-` customers go to Books; in production, test and sample orders are skipped. Government features (e-invoice/GST filing) are blocked unless production and e-invoice are both true. The e-way bill is disabled, and the e-invoice tick was moved out of Settings. | 29 Oct | Earlier on 29 Oct: an e-invoice tick in Settings | S2 Z-7, Z-11, Z-12, Z-13; 733d601 |
| D-162 | **Go-live date** (`zoho_books_sync_start_date`): nothing dated before it is sent, and in production nothing is sent until it is set. The CA books the opening position by hand. | 29 Oct | | S3; e29a212 |
| D-163 | **"Either we sync completely or don't sync."** Books must match the app per order. The CI and Debit Note go as export invoices with payments applied. Real paid costs go as expenses, and vendors, bills and vendor payments are synced. LC money goes as a customer payment applied to the CI, with bank charges ("LC payment received" step). Export benefits go as journals **when received**: Drawback to bank; RoDTEP/RoSCTL to "Export Incentive e-Scrips"; credit "Export Incentive Income". A Custom Duty account is used. | 29 Oct | | S3; 1c91e08, e398e75, 09ce9cb |
| D-164 | **Two-way sync by source of truth.** For what the app sends, the app is the source: a record changed or deleted in Books is put back on the next sync. For expenses the CA books there, Books is the source: they are imported order by order and never double-counted in profit, audit or reports. The restore check runs once a day (API limits), and "Sync Now" always runs it. Order expenses removed in the app do not linger in Books (already decided; do not ask). | 29 Oct | | S3; e091d3e |
| D-165 | **Exchange rate for the CI and Debit Note in Books:** the shipping-bill (customs) rate recorded on the order at Step 10f, never the leg rates. Invoices wait for that rate, and it is frozen once the invoice is in Books. | 29 Oct | First answer 29 Oct: "the rate the app records"; changed the same day to the customs rate (CA recommendation 4) | S3; 0a12641, e29a212 |
| D-166 | **The Debit Note in Books is a zero-rated export invoice** with the goods' HS code on its lines. This is Books only; the Debit Note PDF is unchanged. | 29 Oct | | S3 CA rec 1; 0a12641 |
| D-167 | **GST is built for the live (GST-registered) organisation.** Bills carry their GST: IGST, or CGST + SGST for Karnataka, at 0/5/12/18/28/40%. GST from a vendor without a GSTIN, or at an odd rate, is refused with a message. Full input credit is claimed on bills; expenses without GST need nothing built. Invoice numbers are at most 16 characters. No LUT is entered (Books shows no LUT field). | 29 Oct | | S3 CA recs 2, 3; e29a212 |
| D-168 | **Overseas buyers are set up in their own currency.** A "Test Books Connection" button in Admin → Settings only reads. A per-order "App vs Zoho" check page exists. The Sync Log names imported expenses "Expense #n". | 29 Oct | | S2 Z-9; S3; 733d601, 1c91e08 |
| D-169 | **Zoho testing.** Stage 1 was tested in the real organisation with the `TEST-` prefix, a sync log and a clean-up script. Stages 2/3 were tested in a separate 30-day trial account; the owner made the test organisation GST-registered. All test records were removed and logged (R15), and nothing from testing needs removing from the real organisation. Testing is to finish within the week so that the owner can test for the following two weeks. | 29 Oct | First "I'll create a test org"; then "use the existing organisation, clean before go-live"; then a separate trial account | S2 Z-5, Z-14, P-17; S3; 8015ed9, 8868330 |
| D-170 | **"With this we are done with Zoho integration."** What remains is the owner's go-live checklist (O-12). | 29 Oct | | S3; 1846c2c |
| D-212 | **Every money record has a correct description, in the app and in Zoho (hard rule).** Payments received and made, bills, expenses, costs, payouts, refunds, export benefits and journals each say what they are, for which order and party, what they settle, and the bank reference (UTR / SWIFT / FIRC / scrip no.) whenever money moved through a bank. The app builds the description from the facts; staff notes only add to it; money forms refuse to save without the bank reference where one exists. Supplier and logistics payments in Zoho done 31 Oct (UTR as reference + description). The 31 Oct audit lists the rest: buyer remittances and LC money (no bank reference, no Zoho description), incentive / associate payouts (no payment reference), staff-added costs (description optional), export benefit received and cancellation refunds (no credit reference), plus generic texts (inspection fee, LC interest, travel %), invoice notes, and Zoho-typed expenses without a description (flagged). **Batch 1 built 31 Oct (owner approved the mockups):** a required bank reference on the advance / freight / balance remittances and on the LC money received; their Zoho payment carries the built description ("Advance received from <buyer> for order <ref> · USD 1,600.00 at ₹84.10 · against Commercial Invoice <no.> · bank ref <ref>"); incentive payouts need Paid via + reference (added to the Commission cost line, so to the Zoho expense); a staff-added cost needs a description of 10+ characters; an export benefit received needs its UTR / e-scrip no. (added to the Zoho journal notes with the amount); a refund needs its paid date and bank reference. Migration 2026-10-31b. **Batch 2 built 31 Oct (owner: "yes go ahead with batch 2"):** the inspection fee needs the agency and its invoice no. (migration 2026-10-31c) and is described from them; the LC loan interest line names the LC and the loan; the travel % line says how it was worked out; a supplier bill in Zoho names its Supplier PO; each CI / Debit Note in Zoho gets an internal comment (order, Incoterm, buyer PO, buyer — not printed); an expense typed in Zoho without a description is flagged red in the app (Expenses register and the order). The benefit journal notes (Z7) were done in Batch 1. | 31 Oct | | Owner 31 Oct; ede1d90e; audit PAYMENT_DESCRIPTION_AUDIT; Batch 1 mockups approved 31 Oct |
| D-213 | **Purchase GST under LUT (option A).** NexaCrest exports under LUT and sells nothing in India, so the GST paid on purchases comes back as a refund claimed per period (RFD-01), not per order. The order's cost, profit and incentive keep the GST (it left the bank; a refund is never counted before it is in the bank, and the incentive is never raised by one). The order shows the GST on its tax invoices for information. CA → GST refunds (LUT) records each claim, each bank credit (RFD-05 / UTR reference required, rule D-212) and any amount refused; each credit goes to Zoho Books at the next sync as a journal (bank debited, GST Refund Receivable credited) with a built description. Refused GST is a real cost, booked in Zoho by the CA. The Revenue Report shows the refunds credited in the period. CAFIN gains one information line under MARGIN (owner confirmed it twice, 31 Oct). Migration 2026-10-31d. | 31 Oct | | Owner 31 Oct: option A, send refunds to Zoho automatically; before/after for order 1 shown; confirmed twice ("yes confrimed and go ahead with option A", "yes confirmed and add teh CAFIN line") |

### 3.15 Team incentives and associates

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-171 | **Team incentive program (Step 12d), "being a rewarder".** The program has an on/off switch and a minimum order profit, below which there is no incentive. The reward comes from a global reward table (for example ₹40,000 from ₹3 lakh profit) or from an amount set on the order. The per-order amount defaults to 0, and only an amount above 0 overrides the table. Mockups were approved. | 30 Oct | | S3; 9027f23 |
| D-172 | **Who sees what.** Nobody else sees the company's profit or the % basis. Only owner and MD set and see amounts and profit (`manage_incentives`, not ED). Accounts sees what to pay (`pay_incentives`, Admin/MD + Accounts Executive). Each person sees only their own amount (`view_own_incentives`). | 30 Oct | | S3; 9027f23 |
| D-173 | **Split and timing.** The split is by role and configurable: sales 40 / export 20 / marketing 15 / logistics 15 / accounts 10. Each role's share is divided equally between the people in it, and one person may hold several roles. An incentive is paid only once the **buyer has fully paid**; it then becomes the order's Commission cost and goes to Zoho as Commission Expense **only once paid**. The new-client bonus is a global setting (not per order) and goes to sales and marketing. | 30 Oct | | S3; 9027f23, a40cd19 |
| D-174 | **Incentive cycle:** from the 21st of last month to the 20th of this month, paid with salary on the last day of the month. The cut-off day is configurable by Admin. Wording: salary is "fixed pay"; the incentive is "**Incentive pay**" (not "variable pay"); the setting is the "**incentive cut-off day**" (not "salary cut-off day"). | 30 Oct | | S3; 9027f23 |
| D-175 | **Associates (outside agents, India or abroad)** earn commission per their agreement: a ladder of 0.70 / 1.00 / 1.50 / 1.75 / 2.00% of product FOB value. It is deducted from the profit first, before the team incentive. Only the client's first 10 days count, from the first Buyer PO received date (Day 1 = Step 4d). Repeat orders earn nothing. | 30 Oct | | S3; 9027f23, a40cd19 |
| D-176 | **The signed associate agreement is uploaded as a PDF with its signing date** (each requires the other). Only holders of `manage_incentives` (owner/MD) can open it, and every opening is audit-logged. | 30 Oct | | S3; a40cd19 |
| D-208 | **Payout and reward-table checks.** A payout cannot be marked paid above its amount or before its incentive month; "Mark all paid" with nothing selected is a warning ("Nothing was recorded…"); two reward-table rows may not start at the same order profit (checked beside the field and on the server). | 30 Oct | | R6-N10, N11, N12; R6 Batch 2 and 4, aa10739, 92512ba |

### 3.16 Reference numbers

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-177 | **Every reference number uses a 2-digit year** (for example `SC/CI/26/2910001`), to stay within the 16-character GST invoice-number limit. Numbers already issued keep "2026". Confirmed twice. | 29 Oct | Earlier: 4-digit year | S3; d4041f6 |
| D-178 | **Prefixes and sequences.** The short form is "SC" (SC/ORD …), and "NexaCrest" is the defined short company name. The first number in a new scope is 001, not 000. Numbers are reserved atomically, so two users never get the same one, and the sequences pass MySQL strict mode. | 27 Sep; 21–22 Oct; 28 Oct | | CONC-01/02/03, CONC-04, L-01, L-13, R5-08; 3b3b071, e3e3287, 3ca6a6b, 6c9b55b, 4bf5a58, cfaea9f, 1e89633 |

### 3.17 Test Mode and security

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-179 | **Test Mode freezes every decision-making screen; only operational screens stay available** (Owner Decision #4). Frozen screens include HS codes, email templates, roles, permission definitions and saved reports. It cannot be bypassed through letter case in the path. In Test Mode email never goes to a real address. | 27 Sep; 23 Oct | | TM-03/05, PAR-02, TM-06, TM-07/08; 7204944, 7e79f30, 2a22f6f, 2fadc63 |
| D-180 | **Login and session security.** The password is checked before the account state is revealed, and the account locks at the right attempt. Changing the password ends other sessions, and deactivating or archiving a user or client ends their sessions. Logout needs POST + CSRF, and an expired CSRF token redirects to login. Staff can change their own password. | 27 Sep; 14–15 Oct; 22 Oct | | AUTH-04/05, CP-07, AUTH-10, AUTH-15, L-03, L-26, L-27, #25, #26; f969a7f, aec5939, b994495, f111d2a, fb7e875, 5deb74e, 2b01eae |
| D-181 | **Passwords set by an admin are shown once and never emailed.** Password-reset links are capped per account, not per IP. (These are builder decisions recorded in the README; not challenged.) | — | | README "Judgment calls" |
| D-182 | **Sitewide protections:** security headers (frame, referrer, HSTS, nosniff); CSRF on every POST; a rate limit on the public quotation-intake form; CSV cells neutralised against formula injection; no insecure secret fallbacks in `.env`; route-level permission tests. | 27 Sep; 15 Oct; 28 Oct | | AUTH-14, QA-4 P2, INT-05, RPT-02, R5-14; 93c55b8, 1a83a80, 10eec13, 7d3df98, a9c69cc, 09613c1 |
| D-183 | **Deliberately not built yet (builder scope).** No SMS 2FA provider; reports export as CSV, not .xlsx; no scheduled emailing of saved reports; no generic amendment of other fields; an existing user's name, email or role cannot be edited from /users. A new test failing on these is a known gap, not a regression. | — | | README |
| D-209 | **An action on a record that does not exist says "not found" and changes and logs nothing** — on every route, after the route's own permission check (so a user without the permission never learns whether the record exists). Batch 5 found and fixed three more routes that still said "success": unmatching a bank line, setting TDS on an expense, and saving an associate. | 30 Oct | | R6-N04, N06; R6 Batch 2 aa10739, Batch 5 |

### 3.18 Data, migrations and deployment

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-184 | **Database changes are MySQL-safe and safe to run twice** (R13). Migrations set the character set and run in filename order, and every schema column has its migration. | 22 Oct; 27 Oct | | L-19, L-20, L-31, L-32, N-13; S2 X-1; 61df7bd, f758c84, 19fa846, 793dd84 |
| D-185 | **First install versus upgrade.** A new installation loads `schema.sql` + `seed.sql` and needs no migrations. An existing database (the live site) runs the migrations it lacks, in order. After go-live every database change ships as a new MySQL-safe, run-twice-safe migration, and old migrations stay as history. (This was the assistant's guidance, now in `docs/migrations/README.md`; the owner did not object.) | 29 Oct | | S2 X-4 |
| D-186 | **The order is standalone.** Incoterm, currency, port, preset, client and supplier are copied onto the order when it is created (R25). | 22 Oct | | 1260e2b, 2358013, 8255d62 |
| D-187 | **Core order events are audit-logged** on every order-changing route (57 routes added in Round 4). An order's own audit log can be downloaded as CSV in one click. | 22–23 Oct | | L-23, N-08, M-03; 6a44a6b, be7cba8 … 8ca4c91, 88e9821 |
| D-188 | **IST in the database and the server** (R24). Node sets the database session time zone and runs in Asia/Kolkata; timestamps never mix UTC and IST. | 27 Sep; 21–22 Oct | | DEF-04, AUTH-03/07, PI-01, TZ-01/02, L-02, L-21, UR-6; 62dcc85, 3a54c17, db76c5e, d8f1295 |
| D-189 | **Deploys reach browsers at once.** app.js and app.css load with a version stamp. | 28 Oct | | R5-26; 2b619c3 |
| D-190 | **Read-only live checks after deploy:** duplicate client emails, blank dropdowns/reference documents, and Reference Library paths. They are in `docs/checks/`. | 28 Oct | | R5-02, R5-18, R5-30 |
| D-191 | **Dependencies.** `sanitize-html` is deliberately pinned at 2.17.0 (2.18 breaks Jest/require, and the advisory does not apply). Non-major npm security fixes are applied; 5 low-severity issues remain in Jest (development only). | 30 Oct | | S3; 9e02119, 5c316c2 |
| D-210 | **Simultaneous and repeated saves.** One email per client is enforced by the database (unique index, migration `2026-10-31a-client-email-unique.sql`, applied only when no duplicates remain). Repeated clicks record a payment leg, a payout or an approval once. Only one generation of a document type per order runs at a time. A save of order details, a client or a supplier is refused when someone else saved that form after it was opened, naming who and when. | 30 Oct | | R6-N16, N17, N18, N27; R6 Batch 3, e34782c |

### 3.19 Testing

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-192 | **Test tooling:** PHPUnit and Jest + Supertest, each with a disposable test database, plus the Playwright e2e suite. Stage-gate regression tests cover all stages, and a QA test plan is kept in `docs/QA/TEST_PLAN.md`. | 26 Sep | | QA-2, QA-3, QA-4 inc.1, QA-4 P3; 35b0da2, 591dc64, 733659b, 3c0deb4 |
| D-193 | **No skipped tests** (R22). The skipped PHPUnit test was fixed, and PDF-tool tests fail loudly (poppler-utils documented). A test that failed only when run alone was fixed. | 26 Oct; 30 Oct | | S2 Q-1, Q-2; S3; 929d2e1, e4cfcc8, 381638b |
| D-194 | **Tests never send real mail or depend on the developer's machine.** e2e paths and database settings come from the environment, and specs restore the mail setting. Seeded ui-test users exist, and flaky order-dependent tests were fixed. | 23 Oct; 28 Oct | | TI-05, TI-06, TI-08, TI-09, TI-10, R5-13; eb48ef3, 38c6610, 76996bd, bbabd20, 1e89633 |
| D-195 | **Route coverage:** a route-matrix test (378 permission-checked routes) and a test of the 88 routes with no permission check, on both stacks. | 28 Oct | | R5-14; S2 Q-6; 1e89633, 09613c1 |
| D-196 | **Payment-wording scenario tests:** 5 presets × FOB/CFR/CIF × USD/EUR/GBP, PDF and Word, on both stacks, plus e2e PI/CI download checks. This was the owner's "batch 8 scenario tests". | 28 Oct | | S2 Q-4, Q-5; 480db14, a9d9a10 |
| D-197 | **No PHP deprecation in the test run** (the Payments report with an order that has no currency yet was fixed). | 29 Oct | | S3; 88275e6 |
| D-211 | **Every route has a behaviour test on both stacks (R5-14 closed).** `RouteBehaviourTest.php` / `routeBehaviour.test.js` drive each of the 124 routes QA Round 6 listed (plus 5 found since) as a permitted user and check the change, the audit row or the page, and that a missing id is "not found" with nothing written. PHPUnit also gained the 21 routes only Jest covered (file uploads are checked by their refusal, since PHP cannot upload inside PHPUnit). QA's own route search now finds 0 untested routes. Extends D-195 (which proves the refusals). | 30 Oct | | R5-14; R6 Batch 5 |

### 3.20 Documentation and process

| ID | Decision | Date | Was | Source |
|---|---|---|---|---|
| D-198 | **Docs are kept current with every change:** SOP, READMEs, ARCHITECTURE, role matrix, deployment guides, migrations guide, test plan and Getting Started. Deep audits were run on 24 Oct, 27 Oct, 28 Oct, 29 Oct and 30 Oct. The SOP follows R14. | 24–30 Oct | | S1 DP-1; S2 G-3, G-4; S3; fccddec, 28b0517, 1846c2c, 86706bd |
| D-199 | **The Workflow Guide (HTML) and Workflow Specification (Word, one file with changes accepted) are kept in `docs/workflow/`.** The specification was renamed StoneCrest → NexaCrest and carries a section "Owner decisions recorded — 27 Oct 2026". Neither is published on claude.ai (R21). | 27 Oct; 30 Oct | | S2 G-1, G-2, P-15, P-16; S3; 9e02119 |
| D-200 | **Continuity between sessions.** Consult the previous session's record before re-investigating, cross-check it against the current code and git, don't redo work, and summarise at each major stage. This register is now the single place to check. | 25 Oct | | S2 P-2 |
| D-201 | **Work order on a QA round:** do the highest-priority fixes first and batch the rest. Each batch is pushed only after the owner says "push" (R3). | 28 Oct | | S2 §15 |
| D-202 | **QA Round 6 handling (30 Oct):** five batches — access and High first, then input bounds, concurrency, smaller items, route coverage. Each batch: failing tests first, both stacks, full suites and e2e, review pack, push only on "push". Owner: "push and go with recommendationa nd plan". | 30 Oct | | R6 FIX_BRIEF; Batch 1 |
| D-203 | **Export Executive keeps `respond_to_disputes` / `respond_to_complaints` (Q-R6-3).** Either permission opens the Disputes / Complaints list, showing only records on orders the user may see (D-118). Recommendation accepted 30 Oct. | 30 Oct | | R6-N09 |
| D-204 | **Already decided, answered from this register (30 Oct):** Q-R6-2 → CAFIN download also needs access to the order (D-118); Q-R6-4 → reports and client pages show only orders the user may see (D-118); Q-R6-1 / Q-NEW-3 / Q-MAIL-3 → Admin/MD/ED may approve their own (D-117), non-admins refused; Q-R5-2 → insurance cleared on non-CIF orders when saved (D-049), no document change. | 30 Oct | | R6 owner questions |

---

## 4. Test finding index

Every finding ID found in the repository history and the session transcripts, sorted by round (oldest first) and then by ID.

**Outcomes:**
- **Fixed**: changed or built.
- **By design**: no change, deliberately.
- **Kept as is**: already correct, or the owner chose no change.
- **Deferred**: paused on purpose.
- **Open**: needs a decision; see section 5.
- **Superseded**: replaced by a later decision.

**Rounds:**
1. External QA cross-verification ("QA-1" to "QA-5", 26–27 Sep).
2. Owner feedback and backlog (30 Sep – 23 Oct).
3. External QA report (L / M / R / U / TI / Q series, 22–23 Oct).
4. QA Round 4 (N series, 23 Oct).
5. QA Round 5 (R5 and Q-R5 series, 28 Oct).
6. QA Round 6 (R6-N and Q-R6 series, 30 Oct).

**IDs not found anywhere** (repository or transcripts): H-xx, K-xx, M-01, M-04, U-15, TI-01 to TI-04, and Owner Decision #2 (probably the maker-checker rule, D-117). If new statistics use these IDs, their earlier handling cannot be shown; treat them as new points.

### 4.1 Round 1: External QA cross-verification (26–27 Sep)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| QA-1 | QA-1 | A gate could pass on a stage never reached | Fixed | D-002 | 3d2d7e7 |
| QA-2 | QA-2 | No test tooling | Fixed | D-192 | 35b0da2 |
| QA-3 | QA-3 | No QA test plan (CA/Reports gap and wet-signature flag closed later) | Fixed | D-192, D-025 | 591dc64, f7f7462, 26660e0 |
| QA-4 inc.1 | QA-4 | Stage-gate tests covered only a few stages | Fixed | D-192 | 733659b |
| QA-4 P0.2 | QA-4 | Cross-client isolation in the portal | Kept as is | Already correct; tests added | ce49c5a |
| QA-4 P0.3 | QA-4 | CA financial-year lock enforcement | Kept as is | Already correct; tests added; D-115 | 701b73d |
| QA-4 P0.4 | QA-4 | Gates on amendments, review and email approval; a queued send went out after its document was rejected | Fixed | D-142 | 3ed1b1c |
| QA-4 P0.5 | QA-4 | Permission checked but not the resource's state; Close Order does not check BL originals | Fixed; Close Order part By design | D-116, D-018 | 8543e0c |
| QA-4 P1 | QA-4 | Financial formulas | Kept as is | Already correct; pinned by tests; D-083 | 870d1c8 |
| QA-4 P2 | QA-4 | CSRF, IDOR and input validation | Kept as is | Tests added (CSRF on all POST routes); D-182 | 1a83a80 |
| QA-4 P3 | QA-4 | CRUD correctness per module | Fixed | Test suite added; D-192 | 3c0deb4 |
| AMD-03, DSP-03 | QA-5 | Permission-versus-state gaps on amendments and disputes | Fixed | D-116 | 8543e0c, 19739b1 |
| AMD-06 | QA-5 | Unknown amendment id gave a malformed redirect | Fixed | Returns 404 | 732f777 |
| AUTH-03/07, PI-01, TZ-01/02 | QA-5 | Errors in Node from the UTC/IST mix | Fixed | D-188 | 62dcc85 |
| AUTH-04/05, CP-07 | QA-5 | Login revealed account state before checking the password | Fixed | D-180 | f969a7f |
| AUTH-10, CP-06, CP-12 | QA-5 | Deactivation did not end sessions; no portal idle timeout | Fixed | D-180, D-134 | aec5939 |
| AUTH-14 | QA-5 | No security headers | Fixed | D-182 | 93c55b8 |
| AUTH-15 | QA-5 | Logout by GET open to CSRF | Fixed | D-180 | b994495 |
| CONC-01/02/03 | QA-5 | Races on reference, client and order numbers | Fixed | D-178 | 3ca6a6b |
| CONC-04 | QA-5 | Race on document revision numbers | Fixed | D-178 | 6c9b55b |
| CP-08 | QA-5 | Ambiguous login when two clients share an email | Superseded | Now one email per client, D-129 | 02290e9 |
| CP-13 | QA-5 | Staff and portal sessions wiped each other | Fixed | D-134 | 26ccdcc |
| DEF-04 | QA-5 | Node not running in IST | Fixed | R24, D-188 (Owner Decision #5) | 62dcc85 |
| EML-06 | QA-5 | Overlapping dispatch could send twice | Fixed | D-142 | 19739b1 |
| EML-07 | QA-5 | Non-ASCII email text garbled | Fixed | D-141 | 4be48ce |
| EML-08 | QA-5 | `{sender_title}` wrong | Fixed | D-141 | 4be48ce |
| GATE-01 | QA-5 | Stage 1 passed on a QT draft | Fixed | D-001 (Owner Decision #1) | 4217178 |
| GATE-04/05/10 | QA-5 | Payment cleared with no money recorded | Fixed | D-077 | 011663a |
| GATE-06 | QA-5 | Gates forced on lost/completed orders | Fixed | D-002 | 19739b1 |
| INT-05 | QA-5 | No rate limit on the public intake form | Fixed | D-182 | 10eec13 |
| ORD-04/05/06 | QA-5 | Order creation accepted an inactive client or bad quantity/price; half-created orders | Fixed | D-145 | 3f80b39 |
| RBAC-03/04 | QA-5 | One `manage_orders` covered payments, shipping and closing | Fixed | D-076 (Owner Decision #3) | fe3d5b2 |
| REV-05 / EML-03 / AMD-05 | QA-5 | No maker-checker separation | Fixed | D-117 | 53e2632 |
| REV-06 | QA-5 | Inactive user could be assigned as reviewer | Fixed | D-070 | 53e2632 |
| REV-07 | QA-5 | Same reviewer could be assigned twice | Fixed | D-070 | 53e2632 |
| RPT-02 | QA-5 | CSV formula injection | Fixed | D-182 | 7d3df98 |
| SET-02 | QA-5 | Setting value type not enforced | Fixed | D-145 | b88824a |
| TM-03 / TM-05 / PAR-02 | QA-5 | Test Mode bypass through path case (Node) | Fixed | D-179 | 7204944 |
| TM-06 | QA-5 | HS codes, templates, roles and permissions not frozen in Test Mode | Fixed | D-179 (Owner Decision #4) | 7e79f30, 2a22f6f |
| TM-07 / TM-08 | QA-5 | Email leaked out of Test Mode | Fixed | D-179 | 2fadc63 |
| UP-02 | QA-5 | No nosniff header | Fixed | D-151 | affaa75 |
| UP-03 | QA-5 | Oversized upload said "session expired" | Fixed | D-151 | 4f59e28 |

### 4.2 Round 2: Owner feedback and backlog (30 Sep – 23 Oct)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| Feedback 5–10 | Owner feedback (30 Sep) | Order screens redesign (Concept C) | Fixed | D-045 | 76263cc |
| Batch 3 #12 | Owner batch (7 Oct) | Client agreement T&C footer | Fixed | D-064 | 23df795 |
| Batch 3 #13a | Owner batch | RBAC scoping and view-only tier | Fixed | D-116 | 572d140, 96b253b |
| Batch 3 #13b | Owner batch | Additional documents CRUD | Fixed | D-152 | 4da44fe, 5b9d3e3 |
| Batch 3 #14 | Owner batch | Clickable stepper | Fixed | D-043 | 873683d, bd2a02b |
| #106 | Task tracker (26 Sep) | Buyer/Supplier PO gates passed without a signed copy | Fixed | D-025 | 26660e0 |
| P0 blocker | 90-item backlog | OC-acknowledgement override invisible until the OC was emailed | Fixed | D-026 | b70ae70 |
| P1 #40 | 90-item | PHP 8.1 deprecations on PI Intake Review | Fixed | | 3841ae9 |
| P1 #41/#46 | 90-item | Incoterm, container and port not carried from QT intake | Fixed | D-130 | 51c9336 |
| P1 #47 | 90-item | PI revisions stuck in review | Fixed | D-070 | cea17e1 |
| #6 | 90-item | Stale impersonation flag after a real client login | Fixed | D-134 | f29c6a5 |
| #7 | 90-item | Watermark "both" mode missing its image | Fixed | Seed-data gap; D-065 | 9d9f073 |
| #11 | 90-item | Buyer PO, BLI and COOPREP used buyer instead of consignee | Fixed | D-061 | a68a0a8, 49810b1 |
| #12 | 90-item | "Send to Buyer" for every document type | Fixed | D-142; hidden when mail is off, D-138 | 5b1644a, 1a88fe4 |
| #16 | 90-item | Ghost placeholder on PI intake | Fixed | | 63b1fec |
| #17 | 90-item | Legal Terms differed between draft and final | Fixed | D-064 | 78a42d5 |
| #19/#48 | 90-item | Client Buyer PO self-upload; no-login advance report | Fixed; Kept as is 30 Oct | D-127 | 339d7f1 |
| #20/#79 | 90-item | Payment-screenshot label; ETD/ETA spelled out | Fixed | D-040 | f2b4af1 |
| #21 | 90-item | Generated-document disclaimer | Fixed | Default OFF; D-066 | ef34a5e |
| #25 | 90-item | Raw 419 page on CSRF expiry | Fixed | D-180 | 5deb74e |
| #26 | 90-item | Staff self-service password change | Fixed | D-180 | 2b01eae |
| #27 | 90-item | Settings categories collapsible | Fixed | D-157 | ff5b9c6 |
| #28 | 90-item | Sensitive-setting alert badges | Kept as is | Verified; D-157 | 3a3df41 |
| #29/#63 | 90-item | `storage_base_path` | Kept as is | No code change; D-154 | c8cf5a3 |
| #30/#23 | 90-item | Super Admin OC-acknowledgement override | Kept as is | Pinned by tests; D-026 | 5de1c66 |
| #31 | 90-item | RBI purpose codes as dropdowns | Fixed | D-059 | d3af636 |
| #32 | 90-item | BL type / consignee instruction on BLI per preset | Fixed | D-071 | 41bf8a2 |
| #33 | 90-item | `storage_base_path` read-only | Fixed | D-154 | c8cf5a3 |
| #34 | 90-item | Admin dropdown disappeared for some roles | Fixed | D-124 | 1da67a4 |
| #36 | 90-item | Container Type free text | Fixed | D-147 | 6a40a03 |
| #37 | 90-item | 7 hard-coded system emails | Fixed | Editable; D-142 | 7822ff6, 76ecb5d |
| #38/#55 | 90-item | BLI and COO Prep Sheet sections | Fixed | D-071 | d6e4d17 |
| #43/#66 | 90-item | Unit and Package Type as dropdowns | Fixed | D-147 | bdd0119 |
| #44 | 90-item | Internal "(approved)" tag; Rev.0 on first send | Fixed | D-141 | d17fab3 |
| #45 | 90-item | Structured billing address on PI intake | Fixed | D-130 | 166f00f |
| #51a | 90-item | Supplier PO dropdowns and automatic calculation | Fixed | D-094 | 1bf0cdf |
| #52/#53 | 90-item | Supplier-signed and balance gates without document | Fixed | D-025 | b1db466 |
| #54/#85 | 90-item | Export benefits not in profitability | Fixed | D-104 | d6d3e0b |
| #56/#84 | 90-item | Exchange-rate column in INR Settlement Register | Fixed | D-115 | af7fde1 |
| #57 | 90-item | Hint for outstanding BLI/COOPREP before closure | Superseded | Now a hard requirement with override, D-017 | 25987a3, c333da1 |
| #59 | 90-item | Ports admin broken | Fixed | | 7a8696d |
| #67 | 90-item | Single indicative freight figure | Fixed | | de3d787 |
| #69 | 90-item | Add Product Entry layout and image | Fixed | | 387c715 |
| #71 | 90-item | Annexure did not print its number | Fixed | D-064 | 9b004d5 |
| #72/#88 | 90-item | No forgot-password in the portal | Fixed | D-134 | 3306a43 |
| #73/#74 | 90-item | Warn before editing/resending an approved Annexure | Fixed | D-064 | 2b85421 |
| #75 | 90-item | Buyer Action Link (OC acknowledgement and PO upload) | Fixed | D-125 | 648cdb8 |
| #78 | 90-item | Supplier PO field hints | Fixed | D-094 | 36fb8cb |
| #80 | 90-item | Sub-step badges | Fixed | D-041 | cdba905 |
| #82 | 90-item | Currency labels on remittance inputs | Fixed | D-079 | cfb2595 |
| #83 | 90-item | CFR/CIF freight leg missing from profitability | Fixed | D-104 | 9490eef |
| 90-item (named) | 90-item | Timezone, sort order, nav badges, `.env` fallbacks, Finish dropdown, expiry alerts, Legal Terms per type, SOP RBAC, collapsibles, rate on receipt, 2-column layouts, 30/70 preset, ports city, lead-time min/max, default CBM/weights, SC/ORD prefix, resend intake link, CSV on 6 reports, Annexure note, standalone orders | Fixed | D-188, D-042, D-041, D-182, D-147, D-064, D-079, D-073, D-094, D-156 (values changed 25 Oct), D-178, D-130, D-186 | d8f1295 … 8255d62 |
| UR-2 | User-reported (21 Oct) | Incoterm dropdown on intake forms | Fixed | D-130 | c5540a8 |
| UR-5 | User-reported | Annexure edit form in 2 columns | Fixed | | 338d834 |
| UR-6 | User-reported | UTC/IST mix in timestamps | Fixed | D-188 | db76c5e |
| UR-7 | User-reported | "?" glyphs in PDFs | Fixed | D-065 | daad077 |
| UR-8 | User-reported | Autofill "Acceptance of Quotation No." | Fixed | D-130 | f6ca53b |
| UR-10 | User-reported | OC acknowledgement shown when the OC was never sent | Fixed | D-026 | e8fba9c |
| UR-12 | User-reported | Dimensional tolerance and factory-address dropdowns | Fixed | D-147 | ad7b4b7 |
| UR-16 | User-reported | Badge on Email Approvals | Fixed | D-041 | 1bf4c5f |
| UR-17 | User-reported | "Same as Buyer?" rows on documents | Fixed | Removed; D-061 | 22087cf |
| UR-18 | User-reported (BLOCKER) | CI total excluded freight/insurance on non-FOB | Fixed | D-065 | 3a74557 |
| UR-19 | User-reported | Step badge on Generate Packing List | Fixed | D-041 | 32f16e4 |
| UR-20 | User-reported ("non-negotiable") | Notify on every action | Superseded | Targeted notifications only, D-135 | 6174699, 10ddffc, ea559b7 |
| Phase 0–6 | Owner's phased plan (21 Oct) | NexaCrest short form; FDN without GST; containers; report breakdowns; one RBI code; packing unit; optional BLI fields + scanned BL; user filter; expiry badge; default CC from `.env` (removed 25 Oct); CA CSVs; sailing days; lead-time unit; Send Now; T&C markdown; client Duplicate; history visibility; signature text; drift tests | Fixed (default CC Superseded) | D-178, D-062, D-104, D-059, D-071, D-137, D-115, D-094, D-058 | e3e3287 … 4455d3c |
| Phase Q2 | Owner's phased plan | Amendment signatory | Fixed | D-067 | 8ce4e6e |
| Task #35 | Owner task list (23 Oct) | Client self-service notifications | Fixed | D-134 | 518b012 |
| Task #36 | Owner task list | Order page in 0–17 sequence | Superseded | Steps 1–19, D-007 | 795db8c |
| Task #37, #40–#44 | Owner task list | Kanban view | Fixed | D-045 | 5ab4883 … d79404b |
| Task #38 | Owner task list | Travel-expense % | Fixed | D-102 | 785d807 |
| Task #39 | Owner task list | Complaint separated from Dispute | Fixed | D-132 | 83892ef |

### 4.3 Round 3: External QA report, L / M / R / U / TI / Q series (22–23 Oct; retests v1–v4)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| L-01 | Ext. QA | First reference number in a scope was 000 | Fixed | D-178 | 4bf5a58 |
| L-02 | Ext. QA | Node wrote UTC into DB wall-clock values | Superseded | Fixed under L-21; D-188 | 3a54c17 |
| L-03 | Ext. QA | Account locked one attempt too late | Fixed | D-180 | f111d2a |
| L-04 | Ext. QA | Later PDF pages lacked reference and watermark | Fixed | D-065 | c754d58 |
| L-05 | Ext. QA | "Grade A quality" wording in Natural Stone clause | Fixed | D-064 | ed09827, c2999bf |
| L-06 | Ext. QA | CI balance clause ignored the payment tier | Fixed | D-054 (first "by design", then fixed) | c2999bf |
| L-07 | Ext. QA | Buyer-facing templates said "FDN" | Fixed | D-062 | 8908356 |
| L-08 | Ext. QA | `coo_type` could hold bare "TBC" | Fixed | Via R-13; D-060 | eb48ef3 |
| L-09 | Ext. QA | QT lacked late-acceptance clause | Fixed | D-064 | ee175f1 |
| L-10 | Ext. QA | No portal idle timeout | Kept as is | Already fixed by CP-12; D-134 | eb48ef3 |
| L-11 | Ext. QA | Duplicate client email at login | Superseded | D-129 | eb48ef3 |
| L-12 | Ext. QA | Signature fell back to the MD's title | Fixed | D-141 | de69df4 |
| L-13 (CONC-03) | Ext. QA | Concurrent order creation deadlocked (PHP) | Fixed | D-178 | cfaea9f |
| L-14 | Ext. QA | New order had no current stage; Stage-1 files misfiled | Fixed | D-149 | 3d50aea |
| L-15 (UP-02) | Ext. QA | Stored MIME type came from the browser | Fixed | D-151 | 26f1b19 |
| L-16 | Ext. QA | Bare 413 page above the PHP limit | Fixed | D-151 | 8268774 |
| L-17 | Ext. QA | PHP and Node PDF page counts differed | Fixed | D-057 (first pinned, then matched 28 Oct) | eb09fb7, 38c6610, 380c2e0 |
| L-18 | Ext. QA | Removing an additional document took no reason | Fixed | D-152 | 2b5f47e |
| L-19 (Critical) | Ext. QA | Migrations missing for schema columns | Fixed | D-184 | 61df7bd |
| L-20 | Ext. QA | Migrations ran in wrong order | Fixed | D-184 | 61df7bd |
| L-21 | Ext. QA | Node never set the DB session time zone | Fixed | D-188 | 3a54c17 |
| L-22 | Ext. QA | Scanned BL upload always failed | Fixed | D-150 | aee00eb |
| L-23 | Ext. QA | Core order events not audit-logged | Fixed | D-187 | 6a44a6b |
| L-24 | Ext. QA | Buyer Action token stored in plain text | Fixed | D-128 | 61df7bd |
| L-25 | Ext. QA | Buyer Action Link worked after closure | Fixed | D-128 | de69df4 |
| L-26 | Ext. QA | Password change did not end other sessions | Fixed | D-180 | fb7e875 |
| L-27 | Ext. QA | Archived user's session stayed open | Kept as is | Already covered by AUTH-10; D-180 | eb48ef3 |
| L-28 | Ext. QA | Closed-order lock missed most routes | Fixed | D-019 | 9973a14 |
| L-29 | Ext. QA | Node switch for notification emails | Kept as is | Already correct | e18da55 |
| L-30 / M-07 | Ext. QA | No staff notification when buyer acts via link | Fixed | D-128 | ef55f8b |
| L-31 | Ext. QA | Migrations corrupted UTF-8 | Fixed | D-184 | f758c84 |
| L-32 | Ext. QA | 6 migrations not safe to run twice | Fixed | D-184 | f758c84 |
| L-33 | Ext. QA | User delete blocked only by a reset token | Fixed | D-124 | e18da55 |
| L-34 | Ext. QA | PHP limit 8 MB versus setting 10 MB | Fixed | Warning; D-151 | 8268774 |
| L-35 (OOS-01) | Ext. QA | Order actions accepted out of sequence | Fixed | D-002, D-003 | 0fa1309 |
| **L-36** | Ext. QA | Not described in the repository or transcripts; held for an owner decision | **Open** | O-2 | — |
| L-37 | Ext. QA | Saving a role with nothing ticked wiped permissions | Fixed | D-124 | f0adebe |
| L-38 | Ext. QA | `confirm()` dialogs broken by quotes (PHP) | Fixed | | bb297dd |
| L-39 | Ext. QA | Same in Node | Fixed | | bb297dd |
| L-40 | Ext. QA | Internal documents could be emailed to the buyer | Fixed | D-068 | 873a828 |
| L-41 | Ext. QA | Any template with any document | Fixed | D-142 | eb48ef3 |
| L-42 (EM-NODOC) | Ext. QA | 4 templates could never be sent | Fixed | D-142 | e3bd3c2 |
| L-43 | Ext. QA | No Reply-To on customer emails | Fixed | D-141 | a0350e2 |
| L-44 | Ext. QA | Email-approval notifications | Kept as is | Covered by M-06 | eb48ef3 |
| L-45 | Ext. QA | PDF/DOCX 2.2–2.7 MB | Fixed | D-065 | 56e5b14 |
| L-46 | Ext. QA | Email headers unreadable | Fixed | D-141 | a0350e2 |
| M-02 | Ext. QA (owner req #16) | Client downloads not configurable | Fixed | D-131 | b90d57e |
| M-03 | Ext. QA (owner req B-23) | No CSV of an order's audit log | Fixed | D-187 | 88e9821 |
| M-05 | Ext. QA | Admin screen for dropdowns | Kept as is | Already built; D-147 | 81f14ca |
| M-06 | Ext. QA (owner req B-17) | In-app notifications not emailed | Fixed, later narrowed | Email only where action is needed, D-135 | 709e7c0 |
| M-08 | Ext. QA (owner req B-6) | Regenerating an earlier document after later stages | Fixed in part; rest **Open** | D-027; auto-cancel O-1 | 9c0b3f4 |
| M-09 | Ext. QA | Formal cancellation workflow | Fixed | D-082 | 15b6ce5 |
| M-10 | Ext. QA | Zoho Sign | Deferred | Paused by the owner; O-3 | — |
| R-01 | Ext. QA | Over-long text gave a 500 | Fixed | D-144 | 636447c |
| R-02 | Ext. QA | Raw SQL error in flash message | Fixed | D-144 | 719e589 |
| R-03 | Ext. QA | Impossible date gave a 500 | Fixed | D-144 | 1c7b4b1 |
| R-04 | Ext. QA | Invalid foreign key gave a 500 | Fixed | D-144 | 1c7b4b1 |
| R-05 | Ext. QA | Oversized form body gave a 500 (Node) | Fixed | D-144 | 1c7b4b1 |
| R-06 / R-15 | Ext. QA | Array-shaped input saved "Array" | Fixed | D-144 | 119d62f |
| R-07 / R-14 | Ext. QA | PHP undefined-key warnings | Fixed | D-144 | c221d87 |
| R-08 | Ext. QA | Permission re-grant audited against record 0 | Fixed | D-124 | 32dcc3d |
| R-09 | Ext. QA | Non-numeric amount gave a 500 | Fixed | D-144 | 1c7b4b1 |
| R-10 | Ext. QA | 0-byte signed PO accepted | Fixed | D-151 | e18da55 |
| R-11 (SPO-PAY-01) | Ext. QA | Supplier PO payment validation | Fixed | D-083 | 2a22f6f, 1c7b4b1 |
| R-12 | Ext. QA | Port name too long | Fixed | Via R-01; D-144 | 1c7b4b1 |
| R-13 | Ext. QA | `coo_type` not validated on server | Fixed | D-060 | e18da55 |
| U-01 | Ext. QA | Links shown to roles that then got a 403 | Fixed | R23 | 98a1759 |
| U-02 | Ext. QA | Orders list not paginated | Fixed | D-042 | 1eeae4a |
| U-03 | Ext. QA | Placeholders missing | Fixed | D-043 | b4d997c |
| U-04 | Ext. QA | Fields without a visible label | Fixed | D-043 | b4d997c |
| U-05 | Ext. QA | Placeholders cut off on phones | Fixed | D-043 | 74cccbd |
| U-06 | Ext. QA | Country free text | Fixed | D-147 | 74cccbd |
| U-07 | Ext. QA | Cramped product-row layout | Fixed | | 81f14ca |
| U-08 | Ext. QA | Wrong "only shown when not FOB" text | Fixed | D-040 | 1e211b0 |
| U-09 | Ext. QA | "Freight Debit Note" on staff screen | Fixed | D-040 | 1e211b0 |
| U-10 | Ext. QA | Developer jargon on screen | Fixed | D-040 | 1e211b0 |
| U-11 | Ext. QA | 4 sidebar groups missing on one page (Node) | Fixed | | 5ad3624 |
| U-12 | Ext. QA | Stepper on phones | Fixed | D-043 | 81f14ca |
| U-13 | Ext. QA | Poppins loaded from Google | Fixed | D-043 | 1e211b0 |
| U-14 | Ext. QA | Unstable order for equal timestamps | Fixed | D-042 | 1e211b0 |
| U-16 / U-19 / U-21 | Ext. QA | Table scroll hints, tap targets on text links, phone table cues | Open | Flagged, not done; tap targets partly covered by R5-11; O-6 | eb48ef3 |
| U-17 | Ext. QA | Indicative Freight at half width | Fixed | | eb48ef3 |
| U-18 | Ext. QA | Viewer/Auditor had no Operations menu | Fixed | D-124 | eb48ef3 |
| U-20 | Ext. QA | About 340 text elements under 11px | Fixed | D-043 | eb48ef3 |
| U-22 | Ext. QA | No focus ring on Safari 14–15.3 | Fixed | D-043 | eb48ef3 |
| TI-05 | Ext. QA | Tests could send real mail | Fixed | D-194 | eb48ef3 |
| TI-06 | Ext. QA | Order-dependent flaky test | Fixed | D-194 | 38c6610 |
| TI-07 | Ext. QA | CI coverage gate | Open | No CI pipeline; the owner's infrastructure call; O-5 | — |
| TI-08 | Ext. QA | PSR-4 namespace warning | Fixed | D-194 | 76996bd |
| TI-09 | Ext. QA | Unit tests depended on developer's environment | Fixed | D-194 | bbabd20 |
| TI-10 | Ext. QA | e2e drift; ui-test users not seeded | Fixed | D-194 | bbabd20 |
| Q-ROLE-1 | Ext. QA question | Export Executive could record and clear a payment | Fixed | D-076 | 69b6ce0 |
| Q-ROLE-2 | Ext. QA question | Should the CA be able to edit? | Kept as is | CA read-only; D-114 | 69b6ce0 |
| Q-ROLE-3 / Q-RPT-1 | Ext. QA question | Accounts could not confirm supplier payments; supplier cost visible to all report users | Fixed | D-120 | 69b6ce0 |
| Q-ROLE-4 | Ext. QA question | Document generator assigns reviewer? | Kept as is | D-070 | 69b6ce0 |

### 4.4 Round 4: QA Round 4, N series (23 Oct)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| N-01 | QA R4 | Unclosed div on the Node order page | Fixed | | 2c38ddb |
| N-02 | QA R4 | BL actions lost data (missing shipping row) | Fixed | | 05c1ef9 |
| N-03 | QA R4 | Travel % stored in foreign currency as INR | Fixed | D-083 | c7c3ec9 |
| N-04 | QA R4 | Admin/MD/ED could approve their own cancellation | Fixed | D-117, D-082 | 8b2b6e4 |
| N-05 | QA R4 | Balance cleared without remittance recorded | Fixed | D-077 | 8b2b6e4 |
| N-06 | QA R4 | `bl_copy_sent` with no BL copy on file | Fixed | D-142 | 8b2b6e4 |
| N-07 | QA R4 | No targeted notification for disputes/cancellations | Fixed | D-136 | 36a5cf1 |
| N-08 | QA R4 | 57 routes not audit-logged | Fixed | D-187 (the email fan-out from the audit log was later removed, D-135) | be7cba8 … 8ca4c91 |
| N-09 | QA R4 | Duplicate reminder emails | Fixed | D-136 | f88f6e0 |
| N-10 | QA R4 | Unreadable subjects | Fixed | D-142 | f88f6e0 |
| N-11 | QA R4 | LC/loan validation (interest above 100%) | Fixed | D-145 | f88f6e0 |
| N-12 | QA R4 | Feedback possible before shipment | Fixed | D-133 | f88f6e0 |
| N-13 | QA R4 | Token column widen never reached upgraded databases | Fixed | D-184 | 19fa846 |
| N-14 (= L-17) | QA R4 | PHP QT 6 pages against Node 5 | Fixed | D-057 (pinned 23 Oct, matched 28 Oct) | 38c6610, 380c2e0 |

### 4.5 Round 5: QA Round 5, R5 and Q-R5 series (28 Oct; package v2–v5, v5 identical to v4)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| R5-01 | QA R5 | Production status of a settled order changeable without a reason | Fixed | D-019 | cdee07c |
| R5-02 | QA R5 | Two clients could share an email | Fixed | D-129 | cdee07c |
| R5-03 | QA R5 | "Requires MD approval" presets never approved | Fixed | D-075 (Option A) | cdee07c |
| R5-04 | QA R5 | Links to pages the user cannot open | Fixed | R23 | cdee07c |
| R5-05 | QA R5 | SOP `./README.md` links gave 404 | Fixed | | 1e89633 |
| R5-06 | QA R5 | Fields without an accessible name | Fixed | D-043 | 1e89633 |
| R5-07 | QA R5 | Node upload errors other than size | Fixed | D-151 | 1e89633 |
| R5-08 | QA R5 | Sequence queries failed under ONLY_FULL_GROUP_BY | Fixed | D-178, R13 | 1e89633 |
| R5-09 | QA R5 | User-delete refusal did not say where the user appears | Fixed | D-124 | 1e89633 |
| R5-10 | QA R5 | Safari/iOS 14–15.3 ignore `:has()` / `:focus-visible` | Fixed | D-043 (first "ignore", then built) | 7916efd |
| R5-11 | QA R5 | Phone sizes: tap targets, text, input zoom | Fixed | D-043 | 1e89633 |
| R5-12 | QA R5 | PHP documents 1–2 pages longer than Node | Fixed | D-057 | 380c2e0 |
| R5-13 | QA R5 | e2e paths and settings hard-coded; mail left on | Fixed | D-194 | 1e89633 |
| R5-14 | QA R5 | Not every route tested | Fixed | D-195; re-opened by QA R6, closed by D-211 | 1e89633, 09613c1, R6 Batch 5 |
| R5-15 | QA R5 | Node flash message sometimes missing | Fixed | | d570d23 |
| R5-16 | QA R5 | "Emailed" message while mail off | Fixed | D-138 | cdee07c |
| R5-17 | QA R5 | Blank SMTP encryption differed between stacks | Fixed | D-140 | 1e89633 |
| R5-18 | QA R5 | Reference Library seeded paths | Fixed | D-154; live check D-190 / O-13 | cdee07c |
| R5-19 | QA R5 | Ports / Port Routes menu group (PHP) | Fixed | R23 | cdee07c |
| R5-20 | QA R5 | Order-page buttons shown without permission | Fixed | R23 | cdee07c |
| R5-21 | QA R5 | `/file-store/{id}/download` served any file | Fixed | D-106 | d570d23 |
| R5-22 | QA R5 | CA page links; routes needing manage_orders | Fixed | R23, D-114 | cdee07c |
| R5-23 | QA R5 | Email compose offered internal documents | Fixed | D-068, D-106 | d570d23 |
| R5-24 | QA R5 | Archived/assignment rule not on every order page | Fixed | D-118 | cdee07c |
| R5-25 | QA R5 | "COOPREP null" in messages | Fixed | D-069 (no PDF change) | 2b619c3 |
| R5-26 | QA R5 | Deploy did not reach browsers (cache) | Fixed | D-189 | 2b619c3 |
| R5-27 | QA R5 | Browser rules missing on several forms | Fixed | D-143 | 2b619c3 |
| R5-28 | QA R5 | Server rules missing (emails, amendment numbers, watermark, container count) | Fixed | D-145, D-065 | 2b619c3 |
| R5-29 (High) | QA R5 | Bad numbers on New/Edit Order and Freight Terms gave a 500 | Fixed | D-144 | 2b619c3 |
| R5-30 | QA R5 | Reference docs / dropdown options saved empty | Fixed | D-145; live check D-190 | 2b619c3 |
| R5 extra (Batch 5) | QA R5 | PHP Supplier PO 0% GST printed "To Be Confirmed" | Fixed | D-056 (confirmed twice) | 7916efd |
| R5 extra (Batch 5) | QA R5 | Node Step 7e read a blank supplier advance as unknown | Fixed | D-012 | 7916efd |
| R5 extra (Batch 6) | QA R5 | Forgot password with blank or malformed email | Fixed | D-146 | 2b619c3 |
| R5 Batch 6 choices | QA R5 | Settings numbers not required; file-only reference entries; no phone-format rule | Kept as is | D-146 | 2b619c3 |
| R5 scenario rows (10) | QA R5 | Incoterm × preset life cycle, single-permission matrix, mail-options matrix, MySQL strict-mode check | Fixed in part; rest Open | PI/CI wording tests built (D-196); other matrices O-5 | 480db14 |
| Q-R5-1 | QA R5 question | Admin/MD/ED approve their own reviews and sends | Kept as is | D-117 | — |
| Q-R5-2 | QA R5 question | Who insures on CFR? | Fixed | "Buyer insures", D-049 (confirmed twice) | 380c2e0 |
| Q-R5-3 | QA R5 question | Dispute button per order or per client | Kept as is | Per order, D-132 | — |
| Q-R5-4 | QA R5 question | Notify only when action is needed, or on every action | Kept as is | Only when action is needed, D-135 | — |
| Q-R5-5 | QA R5 question | Hide CAFIN from staff without CA rights | Fixed | `view_cafin`, D-109 | d570d23 |

### 4.6 Round 6: QA Round 6, R6-N and Q-R6 series (30 Oct; fixed in five batches, D-202)

| Finding ID | Round | One-line finding | Outcome | Register row / note | Commit |
|---|---|---|---|---|---|
| R6-N01 | QA R6 | Client and report pages listed and linked orders the user may not open | Fixed | D-118 (Q-R6-4, D-204) | 854a866 |
| R6-N02 | QA R6 | Links still shown that answer 403 | Fixed | R23 | 854a866 |
| R6-N03 | QA R6 | Buyer's message with an unexpected file field: dropped (PHP) / bare 400 (Node) | Fixed | D-206 | 92512ba |
| R6-N04 | QA R6 | Actions on missing records reported success and wrote audit rows | Fixed | D-209 (3 more routes in Batch 5) | aa10739, Batch 5 |
| R6-N05 | QA R6 | Negative or huge ids gave a 500 | Fixed | D-207 | aa10739 |
| R6-N06 (Node) | QA R6 | Production status on a missing order crashed Node | Fixed | D-209 | aa10739 |
| R6-N07 | QA R6 | Malformed ids (1.5, 1') acted on another record | Fixed | D-207 | aa10739 |
| R6-N08 (High) | QA R6 | Assignment rule bypassed through dispute, complaint and amendment routes | Fixed | D-118 | 854a866 |
| R6-N09 | QA R6 | Export Executive held respond permissions but the lists answered 403 | Fixed | D-203 (Q-R6-3) | 854a866 |
| R6-N10 | QA R6 | Payout marked paid above its amount and on any past date | Fixed | D-208 | aa10739 |
| R6-N11 | QA R6 | "Mark all paid" with nothing selected showed green "0 recorded" | Fixed | D-208 | 92512ba |
| R6-N12 | QA R6 | Reward table accepted two rows with the same profit | Fixed | D-208 | 92512ba |
| R6-N13 | QA R6 | Payment terms approved on a cancelled order | Fixed | D-205 | 92512ba |
| R6-N14 | QA R6 | Unit price above the column size gave a 500 | Fixed | D-207 | aa10739 |
| R6-N15 | QA R6 | Unit price below 0.01 stored as 0.00 | Fixed | D-207 | aa10739 |
| R6-N16 | QA R6 | Duplicate client email under simultaneous saves | Fixed | D-210, D-129 (live migration) | e34782c |
| R6-N17 | QA R6 | Repeated clicks recorded several times | Fixed | D-210 | e34782c |
| R6-N18 | QA R6 | Rapid "Generate Quotation" made several revisions | Fixed | D-210 (no document content change) | e34782c |
| R6-N19 | QA R6 | Buyer could report a negative payment | Fixed | D-207 | aa10739 |
| R6-N20 | QA R6 | Buyer could report a payment dated in the future | Fixed | D-207 | aa10739 |
| R6-N21 | QA R6 | Huge reported amount gave the buyer a 500 | Fixed | D-207 | aa10739 |
| R6-N22 (High) | QA R6 | Exchange rate too large: 500 after the advance was already saved | Fixed | D-207 (saved together or not at all) | 854a866 |
| R6-N23 | QA R6 | Exchange rate 0.00001 stored as 0.0000 | Fixed | D-207 | aa10739 |
| R6-N24 | QA R6 | Buyer could report a payment before the PI or on a cancelled order | Fixed | D-206 | 92512ba |
| R6-N25 (Node) | QA R6 | Dropdown Options: invalid values ignored with "updated" — in fact no Node edit there was saved | Fixed | Node form reading matches PHP (`qs`, live: `npm install`) | 92512ba |
| R6-N26 | QA R6 | "Skip PI form" empty reason showed only the browser bubble | Kept as is | Works on an open step; QA submitted the copy inside a locked step | — |
| R6-N27 | QA R6 | Second editor silently undid the first person's changes | Fixed | D-210 | e34782c |
| R6-N28 | QA R6 | Order edit accepted inverted min/max ranges | Fixed | D-207 | aa10739 |
| R6-N29 | QA R6 | Payouts page with month 13–99 gave a 500 | Fixed | D-207 | aa10739 |
| R6-N30 (Node) | QA R6 | Audit log with page=abc gave a 500 | Fixed | D-207 | aa10739 |
| R5-14 (re-test) | QA R6 | 124 routes still without a behaviour test | Fixed | D-211 | Batch 5 |
| R5-02 / R5-04 / R5-27 / R5-28 / R5-29 (re-test "partly") | QA R6 | Remaining parts re-raised as R6-N16, N01/N02, N26, N25, N14/N21/N22 | Fixed / Kept as is | See those rows | — |
| Q-R6-1 | QA R6 question | Creator approving their own payment terms | Fixed | D-117: only Admin/MD/ED may (D-204) | 92512ba |
| Q-R6-2 | QA R6 question | CAFIN download without access to the order | Fixed | D-118, D-109 (D-204) | 854a866 |
| Q-R6-3 | QA R6 question | Export Executive respond permissions | Fixed | D-203 | 854a866 |
| Q-R6-4 | QA R6 question | Reports listing orders the user is not assigned to | Fixed | D-118 (D-204) | 854a866 |
| Q-NEW-3 / Q-MAIL-3 | QA R6 question | Generator approving their own document; requester approving their own send | Kept as is | D-117 (Admin/MD/ED may; others refused) | — |
| Q-R5-2 (re-raised) | QA R6 question | CFR order kept an insurance amount | Fixed | D-049: cleared on save for non-CIF, no document change (D-204) | 92512ba |

---

## 5. Open / undecided points

Only points that are genuinely still open on 30 Oct, with duplicates across sources merged. Anything not listed here and covered by a D-row is decided. **Needed from** names who must act.

### 5.1 Decisions still needed

| # | Point | What is needed | Needed from | Raised |
|---|---|---|---|---|
| O-1 | **Regenerating an earlier document after later stages have passed**: should later stages be cancelled automatically? The confirmation and the "DATA MAY BE STALE" flags exist (D-027). | A decision on what "cancel the later stages" should do, or confirmation that the flags are enough. Any document effect falls under R6. | Owner | 23 Oct (M-08, owner req B-6) |
| O-2 | **L-36**: the finding text is not in the repository or the transcripts. A commit (9c0b3f4) records that it was held for an explicit owner decision. | Find the L-36 text in the external QA report, then put it to the owner. | QA report, then owner | 22–23 Oct |
| O-3 | **Zoho Sign** (M-10): paused by the owner. | The owner says when to resume. | Owner | 23 Oct |
| O-4 | **"Zero advance only for LC": should Admin choose per preset whether it may run without an advance?** Today the form accepts 0% on any preset (D-074). | Yes/no on a per-preset "allow zero advance" switch. | Owner | 24 Oct |
| O-5 | **Test infrastructure:** no CI pipeline exists (coverage gate, TI-07). Also not built: the larger QA scenario matrices (incoterm × preset life cycle, single-permission matrix, mail-options matrix) and a MySQL strict-mode check on every push. | Whether to set up CI on the hosting, and whether to build these matrices. | Owner | 23 Oct; 28 Oct |
| O-6 | **Responsive leftovers** (U-16 / U-19 / U-21): scroll hints on tables, tap targets on text links, table cues on phones. Flagged, not done; tap targets were partly covered by R5-11. | Priority call (it needs a markup sweep). | Owner | 23 Oct |
| O-7 | **Notification recommendations offered but never answered:** an assignee on every order, a daily per-person digest, and folding review-request emails into that digest. | Yes/no on each. | Owner | 26 Oct |
| O-8 | **Should Export Executives get "Manage suppliers" by default?** The default stays Admin/MD/ED/Super Admin. | Yes/no. | Owner | 25 Oct |
| O-9 | **Should the Step 10a packing actuals be pre-filled with the estimate defaults?** They are left blank today. | Yes/no. | Owner | 25 Oct |
| O-10 | **Add the FOB case to the Step 7d / Step 8 tests?** This is tests only. | A go, or simply include it in the next test batch. | Owner (low) | 27 Oct |
| O-11 | **Workflow Specification section 13 questions.** What is the basis of the 10%/15% travel rule? When must the Leg-2 freight Debit Note be paid? What is the definition of "Client Closed"? Which roles get which emails? How does Leg 1/Leg 2 map to the payment presets? ECGC, LC and eBRC checks need the bank or advisor. A spec-versus-app gap list was also offered. | Answers, or a go for the gap list. | Owner; CA / bank for ECGC, LC and eBRC | 27 Oct |
| O-12 | **Zoho go-live checklist** (`docs/SOP/ca-03-zoho-sync.md`, "Go-live checklist"). (a) Back up the database. (b) Run every migration the live database lacks, in filename order (the newest are 2026-10-29d/e/f and 2026-10-30a/b/c/d). (c) The CA adds the chart-of-accounts entries and currencies and books the opening position. (d) Set the `.env` switches and the Books login, organisation and go-live date. (e) Test the connection, switch on, run Sync Now and check App vs Zoho. | Owner, developer/host and CA actions, in that order. | Owner, CA, developer | 29–30 Oct |
| O-13 | **Live-site read-only checks after deploy:** `docs/checks/2026-10-28-duplicate-client-emails.sql`, `docs/checks/2026-10-28-blank-dropdowns-and-reference-docs.sql`, and the R5-18 query on `reference_library_documents`. | Run them and report the results. | Owner / host | 28 Oct |
| O-14 | **Mask the Zoho client secret and refresh token** on Admin → Company Settings. They show in plain text to "Manage company settings" users; this was not seen built. | Yes/no. | Owner | 29 Oct |
| O-15 | **A buyer who orders in two currencies** is stopped by the Zoho sync, because a Books customer has one currency (the Sync Log says "ask the CA"). | How to book it in Books. | CA | 29 Oct |
| O-16 | **CFR wording not covered by the "buyer insures" change:** the T&C clause "For CFR/CIF orders, shipment booking will be confirmed only after full freight and insurance payment…" and the Buyer PO's "Freight & Insurance (CFR/CIF)" line. | Whether to change them. This is a document change, so before/after and confirmation twice apply (R6). | Owner | 28 Oct |
| O-17 | **Manual Safari / Firefox check** of the order page (5-step checklist). Only Chromium could be automated. | The owner runs the checklist and reports. | Owner | 26 Oct; 28 Oct |
| O-18 | **Test-data checks.** One order's CAFIN shows about ₹172/USD (a likely typing error). The OC example shows 7,300 against 40% of 7,500. | The owner checks the recorded rates and amounts and regenerates the OC if needed. | Owner | 26–27 Oct |
| O-19 | **Associate agreement and internal margin reference:** tracked-change suggestions were prepared for the lawyer. | The lawyer's outcome, then any app change. | Lawyer, then owner | 30 Oct |
| O-20 | **Housekeeping (owner actions, low priority).** (a) Rename the branch to `main` before go-live ("I will rename the branch later"). (b) Retry the fresh-install check on MySQL 8. (c) Optionally archive old migration files. (d) Delete the old claude.ai gallery artifact ("we will delete this later"). (e) Next time the Zoho token is generated, leave out the Zoho Mail scopes (Books only). | Owner when convenient. | Owner | 25 Oct; 29 Oct |
| O-21 | **Zoho CRM (Stage 4)**: planned after Books; not started. | When to start. | Owner | 29 Oct |

### 5.2 Raised earlier but since resolved (do not re-ask)

| Earlier point | Resolution |
|---|---|
| Supplier and buyer separation; Supplier PO without client data (S2 R-1, D-5; repo Part C 6) | Closed by the owner on 28 Oct: "already taken care — no need of this anymore". See R11 and D-063. |
| Confidential-data (profit/cost) permission plan (S2 R-3) | Parked 27 Oct; closed 28 Oct as "already taken care". See D-121. |
| Work left uncommitted when the session-1 container broke: Step 2 completion with mail off; client-name link; FOB freight; assignee wrap; mail off by default; current client email; Company Settings collapsed; signature black box (S1 S-10, S-12, S-13, E-8, E-10, DP-5, D-6) | Redone and built on 25 Oct ("Yes, redo all", S2 U-1). See D-138, D-040, D-021, D-155, D-157, D-058. |
| Step 4 sub-steps (S1 S-9); return to the same step after refresh and false green ticks (S1 S-11); hide "Send to Buyer" when mail is off (S1 E-9); usable sample data (S1 DP-6) | Built 25–26 Oct. See D-008, D-006, D-138, D-159. |
| Self-notification default for one account or system-wide (S1 E-2, open point 1) | Moot: the action/audit emails were removed on 26 Oct (D-135), and no self-copy setting exists in the code now. |
| RBI purpose-code wording tweak ("for your bank's reference") | Not chosen; no change (D-059). |
| Production `users` table empty after a fresh deploy (S1 DP-3) | No reply recorded, but the owner was using the live site on 27 Oct (S2 S-21). Raise again only if a fresh install fails to log in. |
| Live-site migrations of 24 Oct (S1 DP-2); correcting the migrations guide, which said live needs 27f…28e (S2 O-12 part 1) | Replaced by D-185. `docs/migrations/README.md` now says a new install needs no migrations. The current live steps are in O-12. |
| Zoho next-session setup: Self Client, env vars, scopes, non-GST test org (S2 O-15) | Done in session 3. Stages 2 and 3 were built and tested and test records removed (D-169). |
| Reporting the stuck old-session sandbox to support (S2 O-18) | Moot; work continued in a new session. |
| Older Safari fallbacks (R5-10), first "ignore" | Built on 28 Oct (D-043). |
| PHP/Node page counts (L-17 / N-14), first "pinned" | Matched on 28 Oct (D-057). |
| Order Financials as tabs inside the card rather than a separate card (S1 S-8 mismatch) | Built as tabs; the owner has worked with it since without objection (D-033). Raise only if the owner asks. |
| Repo Part C "Recorded as not yet built" (SMS 2FA, .xlsx, scheduled reports, generic amendments, user edit) | Not owner questions: builder scope, listed in D-183 so a failing test is recognised. |

---

## 6. Source note

**Sources merged** (all compiled 30 Oct 2026; treated as data):

1. **Repository register** (`repo_register.md`). Built from the full git history: 566 commits, 20 Sep – 30 Oct, read from a full bare clone because the working clone is shallow. It also used code and test comments, the READMEs, `docs/QA/TEST_PLAN.md`, `docs/SOP` and `docs/migrations`. It covers about 225 finding IDs (QA-1 to QA-5, the owner backlog, UR, L/M/R/U/TI/Q, N, R5), 108 decisions without an ID, and 13 open items. Commit hashes were verified there; several were re-checked for this register.
2. **Session 1 extract** (`session1.md`). This is **partial**: the transcript was read only for 24 Oct 08:39 UTC to 25 Oct 09:55 UTC, plus compaction summaries for late 23 / early 24 Oct.
3. **Session 2 extract** (`session2.md`). The full transcript, 25 Oct 11:14 UTC to 29 Oct 06:25 UTC: 191 items, the QA Round 5 index, 30 standing rules, 18 open points, and the owner's question-tool answers. Event ids are cited there.
4. **Session 3 extract** (`session3.md`). 29–30 Oct: Zoho, CAFIN, incentives, associates, stage gates and docs.
5. **CLAUDE.md** (the repository's standing rules, as in force on 30 Oct). Spot checks were made in the current code and docs (seed GST list, migrations README, Zoho go-live checklist, settings view).

**How conflicts were resolved.** The later decision wins, and the reversal is recorded in the "Was" column. Main reversals:
- Push cadence: "push always" was replaced by ask-first (R3).
- Step 4 order (D-008).
- FOB freight: hidden, then a greyed card (D-021).
- Compliance: one card, then two phases (D-014).
- Benefits: Step 12c, then 13g (D-099).
- BLI/COOPREP before closure: a hint, then a hard requirement (D-017).
- Page counts: pinned, then matched (D-057).
- CI balance clause: "by design", then fixed (D-054).
- Notifications: every action, then tiers, then action-needed only (D-135).
- CAFIN access: Admin/MD/ED only, then the `view_cafin` permission (D-109).
- CA access: no orders, then assigned orders, then all orders (D-114).
- Order ownership: one owner, then multi-assignee (D-118).
- Zoho invoice rate: the app's rate, then the shipping-bill rate (D-165).
- Older Safari: ignore, then build (D-043).
- Supplier/buyer separation: built, reverted, then closed (D-063).

**Coverage gaps.**
- **26 Sep – 23 Oct:** decisions come only from commit messages and code and doc notes; no transcript was read for this period. Owner Decisions #1, #3, #4 and #5 are quoted in the code; **Decision #2 is never quoted** (probably maker-checker).
- **Session 1 before 24 Oct 08:39 UTC** (about 20 Sep – 23 Oct) was not read. An owner decision made there that never reached a commit message or code comment may be missing.
- **Session 1, 25 Oct 09:55 – 11:14 UTC:** the gap until session 2 began. Session 2 starts by redoing that window's lost work, so little should be missing.
- **IDs never found:** H-xx, K-xx, M-01, M-04, U-15, TI-01 to TI-04, and the text of L-36.
- Labels like S-7, C-2 and P-5 are item numbers *inside the session extracts*, not QA finding IDs. The two sessions reuse the same letters for different items (for example S1 D-1 ≠ S2 D-1), so the Source column always prefixes them with S1/S2/S3.

**Not reconciled / caveats.**
- GST rates: the bill form's GST list holds 0/5/12/18/28% (seed), but the Zoho bill sync also accepts 40%. If a 40% bill is ever needed, the rate has to be added to the GST Rate list by Admin; this is not a decision conflict.
- D-181 (admin-set passwords shown once; reset links capped per account) and D-185 (first install versus migrations) are builder or assistant decisions the owner did not explicitly confirm. They are recorded as the current working rule.
- D-033 (Order Financials tabs) differs from the recommendation as worded; the owner never commented.
