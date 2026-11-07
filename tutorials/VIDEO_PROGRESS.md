# Tutorial videos — progress and resume notes

Owner plan (6 Nov 2026): one series of narrated videos per group, in the order
**C → A → D → B → E**. Each video 8–12 minutes, never over 15 (split into parts instead).
US English voice (Piper `en_US-lessac-medium`). Interesting, not boring. No PII: role names,
role e-mails and demo / `TEST` data only. Videos are **not committed**; this file and the
scripts are, so a new session can continue.

Status values: `script` (written, not recorded) · `recorded` (MP4 made, not yet checked) ·
`checked` (watched / frames reviewed, ready to send) · `sent` (sent to the owner).

## Group C — Associates

**Group C complete (6 Nov 2026):** C01–C06 recorded and checked, all recorded with the card fix.

C02–C15 were each 2–5 minutes, so they are joined into five videos of 8–11 minutes (decided 6 Nov 2026).
The part scripts live in `scripts/parts/`; the top-level script joins them with `lib/merge.js`
(each part keeps its own demo data and actors, opens with a "Part n of m" title card, and numbers
its chapters from 1). Cross-references in the narration use the new numbers below.

| No. | File name | Joins (parts) | Covers | Est. | Status | Date | Notes |
|---|---|---|---|---|---|---|---|
| C01 | `C01-Associate-Welcome.mp4` | — | What an associate does; login with password + 6-digit code; Home; every tab; first message and the MD's reply; access levels; golden rules; log out | 7.6 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.75 min, 50 MB, audio −20.2 dB. Home money boxes in USD; the welcome story says the commission is fixed automatically at full payment at the rate of the buyer's payments, never changes, and NexaCrest pays the bank charges. Frames and every scene change checked, no flash. |
| C02 | `C02-Associate-Becoming-An-Associate.mp4` | C02 Onboarding + C03 Profile/Bank | Registration link (MD) → form with browser checks → MD accepts → set password → first login (Invited); NexaCrest adds an associate directly; profile, PAN/GSTIN/address, KYC uploads (Aadhaar last 4), bank typed twice + proof; MD accepts documents, bank check (MD) + verify (ED), Active | 9.1 | checked | 2026-11-06 | Re-recorded 6 Nov with the card fix: 9.27 min, 68 MB, audio −20.2 dB; no card flash. |
| C03 | `C03-Associate-Starter-Level-And-First-Client.mp4` | C04 Starter tour + C05 Inviting clients | Starter page by page (clients, protection, 10-day window, certificate, orders without FOB, commission states, invoice upload, TDS certificates, locked page); buyer registration link, personal code, buyer registers, MD accepts on Quotation intake | 7.8 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.86 min, 54 MB, audio −20.2 dB. The old take showed rupee totals and a rupee estimate; now: commission cell Estimate → Fixed → Paid in the order's currency, "How your commission is fixed" box and USD totals on the Commission page. Frames and every scene change checked, no flash. |
| C04 | `C04-Associate-Prices-Requests-And-Levels.mp4` | C06 Get price + C07 "Other" + C08 Access levels | Price access; catalogue; Get price, quote + lowest price; validity; "Other" request priced by hand by the MD; Starter / Quoting / Full / Custom, upgrade suggestion, MD moves the associate up with a reason | 8.1 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.82 min, 57 MB, audio −20.3 dB. The old take showed rupee totals on the Commission page; now the "How your commission is fixed" box and USD totals (narration unchanged). Frames and every scene change checked, no flash. |
| C05 | `C05-Associate-Commission-Statements-And-Tax.mp4` | C09 Commission + C10 Statements + C11 TDS/GST | Expected → earned → due → paid; basis = invoiced FOB of goods; invoice upload; hold / release / claw-back; CSV + printable statement; 194H threshold and catch-up, no PAN 206AA, GST on top, abroad (195, DTAA, TRC, 10F, No-PE, 15CA/15CB) | 9.7 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 10.80 min, 78 MB, audio −20.2 dB. New: exchange-rate card (fixed once at the rate of the buyer's payments, never changes, NexaCrest pays bank charges); "How your commission is fixed" box; totals in USD; lines marked Fixed with the India fixed-rupee line; "Fixed — due …, paid in the … payout run"; settled claims recomputed at the same fixed rate; MD change with reason. Frames reviewed. |
| C06 | `C06-Associate-Messages-Changes-Privacy-And-FAQ.mp4` | C12 Messages + C13 Changes + C14 Never see + C15 FAQ | Notices and messages; client moved, leaked code blocked, Suspended, Ended; what associates never see; 11 questions and answers; series overview | 10.4 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 11.12 min, 82 MB, audio −19.9 dB. New: notices say "Commission fixed"; home money boxes in USD; the not-found page shown inside the portal with "Back to your home page"; FAQ: fixed by itself at full payment and never changes, never bank charges. Frames reviewed. |

## Group A — Everyone (overview)

| No. | File name | Covers | Status |
|---|---|---|---|
| A01 | `A01-Everyone-NexaCrest-In-Ten-Minutes.mp4` | What NexaCrest does; the portals (staff, client, associate); roles and departments in one picture; the 9 stages / 19 steps; Standard vs Kanban; where help lives | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-265/D-266):** 9.91 min, 72 MB, audio −20.3 dB. Every login (staff, buyers, associates) asks for a 6-digit code by e-mail; MD, ED and Admin keep backup codes; the SOP shows the chapters for your role (Admin › SOP access). Frames and every scene change checked, no flash. |
| A02 | `A02-1-Everyone-One-Order-From-Enquiry-To-Confirmation.mp4` (6.8 min) + `A02-2-Everyone-One-Order-From-Factory-To-Closure.mp4` (5.1 min) | One sample CIF order from enquiry to closure, switching between every role that touches it (associate, Export Executive, Sales Manager, MD, Accounts, Finance Manager, Procurement, Logistics, Export Compliance, the buyer in the portal); routine steps done off camera during cards (scripts/_a02.js); CAFIN named as management-only, never shown to the buyer | Part 1 recorded + checked 7 Nov 2026. **Part 2 re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 5.57 min, 47 MB, audio −20.6 dB. New card "The associate's commission is fixed" after the balance is cleared; the associate's order shows "Fixed — due …, paid in the … payout run" and the India fixed-rupee line; the MD lock remains only for the team incentive. The demo now records each buyer receipt with the rupees credited (as Accounts does), so the commission really is fixed on camera. Frames and every scene change checked, no flash. |

## Group D — Clients (buyers): every scenario

The 18 buyer scenarios, grouped into five videos of 8–12 minutes.

| No. | File name | Scenarios | Status |
|---|---|---|---|
| D01 | `D01-Client-From-Enquiry-To-Proforma.mp4` | 1 quotation details form (no login) · 2 receiving the quotation (e-mail card) · 3+5 Buyer Action Link: download our PO, upload the signed copy with the PO number; questions by replying to the e-mail · 4 PI details form (sections 1–6, lock confirmation) · the PI e-mail and the link moving on to the advance | checked 2026-11-07: 7.46 min, audio -20.2 dB, no card flash |
| D02 | `D02-Client-Paying-And-Your-Login.mp4` | 6 Payment Action Link (advance, proof upload; same link for freight and balance) · 8 login created when the advance clears, set password, sign in, My Account · 9 forgot password (generic message, reset link) · 7 Letter of Credit (PI wording, Buyer Action Link waiting, LC recorded and confirmed usable, both orders in the portal) | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-265):** 7.03 min, 46 MB, audio −20.2 dB. New: every buyer sign-in shows the 6-digit e-mailed login code step (valid 10 minutes), typed while the narration explains it; developer banner hidden. Frames reviewed. |
| D03 | `D03-Client-Inside-Your-Order.mp4` | 10 My Orders (Active / Complete / Disputed / Lost / Cancelled) · 11 Order Confirmation acknowledgement · 12 documents you may download (never internal ones) · 13 Order Updates chat | not started |
| D04 | `D04-Client-Balance-Shipping-And-Closing.mp4` | 14 reporting the balance payment · 15 shipping documents and Commercial Invoice · 16 order feedback · 17 Reorder This | not started |
| D05 | `D05-Client-When-Something-Goes-Wrong.mp4` | 18 amendment, dispute (when switched on), process issue, cancelled / lost orders, staff "Log in as this client" banner | not started |

## Group B — Staff, by role

About 24 roles, one part per role or close pair, 8–12 minutes each; listed in the order of the order life cycle.

| No. | File name | Roles | Status |
|---|---|---|---|
| B01 | `B01-Staff-Export-Executive-Part-1.mp4` / `-Part-2` | Export Executive (QT → PI → OC, documents, recording payments) | not started |
| B02 | `B02-Staff-Sales-Manager-And-Director.mp4` | Sales/Export Manager, Sales Director (approvals, assignment, cockpit, e-mail approval) | not started |
| B03 | `B03-Staff-Accounts-Executive.mp4` | Accounts Executive (clearing, supplier payments, INR actuals, remittances) | not started |
| B04 | `B04-Staff-Finance-Manager-And-Director.mp4` | Finance Manager, Finance Director (order financials, CAFIN, associate bank checks, short-payment approval) | not started |
| B05 | `B05-Staff-Procurement.mp4` | Procurement Executive / Manager / Director (RFQ, quotes, POs, limits, QC) | not started |
| B06 | `B06-Staff-Logistics.mp4` | Logistics Executive / Manager / Director (packing, freight, BL, partners) | not started |
| B07 | `B07-Staff-Export-Compliance.mp4` | Export Compliance Executive / Manager / Director (checklist, COO prep, benefit claims) | not started |
| B08 | `B08-Staff-Order-Desk.mp4` | Order Coordinator, Operations Manager (status, owners, My tasks, cockpit) | not started |
| B09 | `B09-Staff-MD-And-ED.mp4` (Part 1 / Part 2) | Managing Director, Executive Director (approvals, pricing, associates, incentives, settings) | not started |
| B10 | `B10-Staff-Admin-And-System-Administrator.mp4` | Admin, System Administrator (users, roles, departments, settings, templates, backups) | not started |
| B11 | `B11-Staff-CA-Auditor-And-Investor.mp4` | CA, Viewer / Auditor, Investor (read-only views, CA module, reports) | not started |

## Group E — Divisions

| No. | File name | Covers | Status |
|---|---|---|---|
| E01 | `E01-Divisions-Domestic-Trade.mp4` | DOM: sale to Indian resellers in INR, GST tax invoices (planned division, shown in a test switch-on) | not started |
| E02 | `E02-Divisions-Import-And-Distribution.mp4` | IMP: import, customs duty, landed cost, stock, local sale | not started |
| E03 | `E03-Divisions-Merchant-Trade.mp4` | MTT: buy abroad, sell abroad, both legs in foreign currency; plus Admin › Divisions activation checklist | not started |

## Resume notes (for a new session)

1. Read `tutorials/README.md`.
2. Voice: Piper `en_US-lessac-medium` from Hugging Face (`rhasspy/piper-voices`, `en/en_US/lessac/medium/`):
   the `.onnx` and `.onnx.json` files into `$TUT_WORK/voices/`. In the cloud environment
   "order-system" the download host `us.aws.cdn.hf.co` is already allowed.
3. In the cloud session the work folder is the session scratchpad:
   `TUT_WORK=<scratchpad>/video TUT_OUT=<scratchpad>/video/out TUT_VOICE=<scratchpad>/video/voices/en_US-lessac-medium.onnx`.
4. Start the PHP dev server if it is not running, then record **while holding the test lock**:
   `flock <scratchpad>/test4.lock node tutorials/run.js C02`.
5. Check every new MP4: `ffprobe` (duration ≤ 15 min, an audio stream), pull 3 frames with
   `ffmpeg -ss <t> -i <mp4> -frames:v 1 frame.png` and look at them; fix anything ugly; then set the
   status above to `checked` with the date.
6. Try one part alone: `node tutorials/run.js parts/C05-Associate-Inviting-Clients` (on failure a screenshot is saved as `raw/<code>-fail.png`; `TUT_DEBUG=1` logs every page load).
7. The part scripts (`scripts/parts/`) were written from the views and controllers; until a video is recorded: the first
   recording of each may need a selector fixed. A failing step names itself (`Step 7 (…): …`).
8. Demo data clean-up is automatic (`restoreDb` snapshot, or demo associates deleted). If a run is
   killed half-way, restore with `node -e "require('./tutorials/lib/demo').restore()"` (same TUT_WORK) — only if nothing else has written to the dev database since the snapshot.

## Length check

6 Nov 2026, `--plan` (C01's estimate matched its real length): C01 7.6 · C02 9.1 · C03 7.8 · C04 8.1 ·
C05 9.7 · C06 10.4 minutes (Group C about 53 minutes).

## Recorder notes (6 Nov 2026, C01 redo + C03)

- Every video now starts half a second before its first line (the browser's blank page before the first card is cut; narration and subtitles move with it). C01 and C02 were re-rendered with `--rerender`.
- `Director.loc()` always takes the first match, so a selector that also matches a second row (e.g. two "Commission" rows on an order) no longer stops a recording.
- Demo buyers typed into the public intake form need their own e-mail domain when another part of the same video creates a demo client (`@email.com`): the app's same-company check matches on e-mail domain, and the registration would be "Not accepted".
- After recording, stop any Node server on port 3000 so the shared dev/test database is not left with open connections.

## Recorder notes (6 Nov 2026, C04 + C05)

- A card never takes the app overlay: card pages force `body{zoom:1}` and hide `#tut-root`. Before this, a person switch between two cards showed the old card at double zoom with the badge and caption for about half a second.
- `demo-orders.js` gives each demo order its own sequence number (the test fixture always uses 1, so a buyer with several orders failed with a duplicate key).
- In merged videos, part narration says "this part" / "next part", not "this video" / "next video".
