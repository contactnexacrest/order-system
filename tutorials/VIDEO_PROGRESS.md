# Tutorial videos — progress and resume notes

> **Hold lifted — owner, 18 Nov 2026:** "I need the tutorial videos for all roles and one general video which tells
> about this application … detailed enough to act as tutorial videos … not more than 10–15 min, break the videos
> in that time span … use the same voice." Every recorded video below is re-recorded against the 18 Nov 2026 app;
> the new staff videos B03 – B11 are written and recorded. Order: A01 (general, 2 parts) → A02 → B01 – B11 → D → C.
> Each finished video is checked (length, audio, sync, frame grid), sent to the owner and then deleted locally
> (disk space).
>
> **Recording set-up since 18 Nov 2026 — a separate tutorial database.** The dev database is full of automated-test
> records ("E2E …", "PHPUnit Test User", hundreds of test suppliers) and a real-looking name in an associate
> registration, none of which may appear on camera (no PII). Videos are now recorded against `nexacrest_tutorial`,
> built from `docs/schema.sql` + `docs/seed.sql` (md@email.com / ed@email.com get the e2e staff password), served by a
> copy of the PHP app (`/home/user/tutorial-stack`: `app` without vendor/tests, `vendor` linked, its own `.env` with
> `DB_DATABASE=nexacrest_tutorial`) on port 8081 with `php -d upload_max_filesize=64M -d post_max_size=64M`. Settings:
> `TUT_BASE_URL=http://127.0.0.1:8081 TUT_DB_NAME=nexacrest_tutorial TUT_PHP_APP=/home/user/tutorial-stack/app`.
> The snapshot (`lib/demo.js`) now leaves out the `documents` table and deletes only the documents a recording added.

> **Complete set recorded 19 Nov 2026 (owner, 19 Nov: "complete everything first … give me all videos under their
> names … at the end give me all at once").** All 29 videos below were recorded fresh on the tutorial database,
> checked (length, audio level, subtitle end, frame grid, plus a scan for one-frame "tiny page" flashes, which were
> repaired by replacing the flash frame with the clean frame after it) and sent together, grouped A / B / C / D.
> Recorder fixes found on the way (all in `tutorials/lib` or the scripts): the dev login-code box is hidden in every
> stylesheet; the page zoom is applied before the first paint (no tiny-page flash); the set-password link is read
> from the Copy button (D-280/D-282); demo SQL runs in utf8mb4 (dashes were garbled); a video built from several
> parts reuses one demo product; B01 Part 1 keeps Products open while it is narrated; C01 shows the password tip on
> the login page; B10 says test mode is the Super Admin's (MD/ED) instead of opening a 403 page; stated lengths
> ("About N minutes") set from the real takes.
>
> | Group | Video | Length |
> |---|---|---|
> | A | A01-1 NexaCrest in Ten Minutes · A01-2 Everything NexaCrest Can Do · A02-1 One Order, Enquiry to Confirmation · A02-2 One Order, Factory to Closure | 10.5 · 9.5 · 7.1 · 5.7 min |
> | B | B01-1 / B01-2 Export Executive · B02 Sales Manager and Director · B03 Accounts Executive · B04 Finance Manager and Director · B05 Procurement · B06-1 / B06-2 Logistics · B07 Export Compliance · B08 Order Desk · B09-1 / B09-2 MD and ED · B10 Admin and System Administrator · B11 CA, Auditor and Investor | 10.4 / 8.2 · 9.2 · 6.8 · 5.8 · 4.8 · 4.3 / 3.7 · 3.9 · 3.4 · 4.0 / 4.8 · 4.8 · 3.0 min |
> | C | C01 Welcome · C02 Becoming an Associate · C03 Starter Level and First Client · C04 Prices, Requests and Levels · C05 Commission, Statements and Tax · C06 Messages, Changes, Privacy and FAQ | 8.9 · 11.3 · 8.4 · 8.4 · 12.5 · 14.3 min |
> | D | D01 From Enquiry to Proforma · D02 Paying and Your Login · D03 Inside Your Order · D04 Balance, Shipping and Closing · D05 When Something Goes Wrong | 7.5 · 6.9 · 7.1 · 7.0 · 7.6 min |
>
> Open point for the owner (not narrated in any video): the associate's order statement still shows "Counts toward
> your next commission rate: yes / no", although D-292 says associates never see counts or levels.

> **Hindi versions recorded 19 Nov 2026 (owner, 19 Nov: "I need all above videos in Hindi … not more than 15 min,
> split into parts if longer … give me all at once, A, then all A videos, then B …").** Same scripts, recorded with
> `TUT_LANG=hi` and the Piper voice `hi_IN-priyamvada-medium`; every spoken and shown text comes from
> `tutorials/i18n/hi/<video>.json` (guide: `tutorials/i18n/hi/README.md`; `node tutorials/i18n-check.js hi` must pass).
> The app's screens and the e-mail cards stay in English (the app is English); the narration over them is Hindi.
> Files end in `-Hindi.mp4` with Devanagari `.srt` subtitles. Checked like the English set (length, audio level
> −17.2 to −17.6 dB, subtitle end, frame grid, flash scan; flashes repaired in B01-2, B11, C02 and C06 Part 1).
> Hindi speech is a little longer, so C05 and C06 went over 15 minutes and were cut in two at a part title card
> (C05 at 7:47.5, before "Part 2 of 3"; C06 at 7:45.9, before "Part 3 of 4"); the cards inside keep the merged
> numbering ("Part 3 of 4" opens C06 Hindi Part 2).
>
> | Group | Hindi video | Length |
> |---|---|---|
> | A | A01-1 · A01-2 · A02-1 · A02-2 | 13.0 · 12.4 · 8.9 · 7.4 min |
> | B | B01-1 / B01-2 · B02 · B03 · B04 · B05 · B06-1 / B06-2 · B07 · B08 · B09-1 / B09-2 · B10 · B11 | 12.9 / 10.5 · 11.8 · 8.6 · 7.2 · 6.0 · 5.3 / 4.5 · 4.9 · 4.3 · 5.2 / 6.3 · 6.1 · 3.7 min |
> | C | C01 · C02 · C03 · C04 · C05 Part 1 / Part 2 · C06 Part 1 / Part 2 | 11.6 · 14.9 · 11.0 · 10.6 · 7.8 / 8.1 · 7.8 / 9.7 min |
> | D | D01 · D02 · D03 · D04 · D05 | 9.5 · 8.9 · 9.2 · 8.9 · 10.0 min |
>
> A change that alters a recorded video now affects both the English and the Hindi version (ask the owner first,
> as for the English videos); a changed English text needs its Hindi line in the JSON before re-recording.

Owner plan (6 Nov 2026): one series of narrated videos per group, in the order
**C → A → D → B → E**. Each video 8–12 minutes, never over 15 (split into parts instead).
US English voice (Piper `en_US-lessac-medium`). Interesting, not boring. No PII: role names,
role e-mails and demo / `TEST` data only. Videos are **not committed**; this file and the
scripts are, so a new session can continue.

Status values: `script` (written, not recorded) · `recorded` (MP4 made, not yet checked) ·
`checked` (watched / frames reviewed, ready to send) · `sent` (sent to the owner).

## Language rule (owner D-419, 25 Nov 2026)

From 25 Nov 2026 every new or re-recorded video is made in **English only**. Hindi versions are made only when the owner asks for a specific video. Existing Hindi files stay; when one no longer matches the app it is listed as out of date, not re-recorded.

**Known small mismatch (D-423, 25 Nov 2026), reported to the owner, no re-recording unless asked:** B01 Part 2 and A02 Part 1 show the "payment recorded" message with rupees grouped the old Western way (₹151,380.00); the app now writes ₹1,51,380.00. Only the commas differ.

**Waiting to be re-recorded (English only):** C05 (still taught the old 10-day rule, replaced by D-410: every order earns while the agreement runs); C04 (D-426: My clients now shows "Your client", the "Last day for orders that earn commission" column is gone); D-411 wording — F02, F03, C08, C09, C13, C15 (6 recordings; old/new sentences in the owner-approved list of 25 Nov 2026). The Hindi C08, C09, C13 and C15 are out of date on those sentences. Associate hiring video H01 (D-417, D-424, D-425): storyboard approved by the owner 25 Nov 2026 (`tutorials/hiring/H01-storyboard-and-script.md`); recording in English after the push.

**Also out of date after D-421 (25 Nov 2026, payout cut-off day; re-record in English after the push):** C09 says "We pay it 30 days after full payment, on our monthly payment day" → new rule: cleared by the 20th → paid at that month's end, otherwise next month's end; A01-2 shows Commission settings (new fields: waiting days, cut-off day, the "How it works" table); A02-2 mentions when the commission becomes due. Hindi C05/C09 out of date on the same sentence (not re-recorded, D-419).

## Group C — Associates

**Group C complete (6 Nov 2026):** C01–C06 recorded and checked, all recorded with the card fix.

C02–C15 were each 2–5 minutes, so they are joined into five videos of 8–11 minutes (decided 6 Nov 2026).
The part scripts live in `scripts/parts/`; the top-level script joins them with `lib/merge.js`
(each part keeps its own demo data and actors, opens with a "Part n of m" title card, and numbers
its chapters from 1). Cross-references in the narration use the new numbers below.

| No. | File name | Joins (parts) | Covers | Est. | Status | Date | Notes |
|---|---|---|---|---|---|---|---|
| C01 | `C01-Associate-Welcome.mp4` | — | What an associate does; login with password + 6-digit code; Home; every tab; first message and the MD's reply; access levels; golden rules; log out | 7.6 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.75 min, 50 MB, audio −20.2 dB. Home money boxes in USD; the welcome story says the commission is fixed automatically at full payment at the rate of the buyer's payments, never changes, and NexaCrest pays the bank charges. Frames and every scene change checked, no flash. |
| C02 | `C02-Associate-Becoming-An-Associate.mp4` | C02 Onboarding + C03 Profile/Bank | Registration link (MD) → form with browser checks → MD accepts → set password → first login (Invited); NexaCrest adds an associate directly; profile, PAN/GSTIN/address, KYC uploads (Aadhaar last 4), bank typed twice + proof; MD accepts documents, bank check (MD) + verify (ED), Active | 9.1 | checked | 2026-11-06 | Re-recorded 6 Nov with the card fix: 9.27 min, 68 MB, audio −20.2 dB; no card flash. · **Re-recorded 8 Nov 2026 (replacement, owner-approved):** 9.33 min, −20.2 dB; form placeholders now role-style ("e.g. Full name as on PAN card"); staff logins use the e-mailed code off camera; narration unchanged; 12-frame grid and scene changes checked, no flashes. |
| C03 | `C03-Associate-Starter-Level-And-First-Client.mp4` | C04 Starter tour + C05 Inviting clients | Starter page by page (clients, protection, 10-day window, certificate, orders without FOB, commission states, invoice upload, TDS certificates, locked page); buyer registration link, personal code, buyer registers, MD accepts on Quotation intake | 7.8 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.86 min, 54 MB, audio −20.2 dB. The old take showed rupee totals and a rupee estimate; now: commission cell Estimate → Fixed → Paid in the order's currency, "How your commission is fixed" box and USD totals on the Commission page. Frames and every scene change checked, no flash. |
| C04 | `C04-Associate-Prices-Requests-And-Levels.mp4` | C06 Get price + C07 "Other" + C08 Access levels | Price access; catalogue; Get price, quote + lowest price; validity; "Other" request priced by hand by the MD; Starter / Quoting / Full / Custom, upgrade suggestion, MD moves the associate up with a reason | 8.1 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 7.82 min, 57 MB, audio −20.3 dB. The old take showed rupee totals on the Commission page; now the "How your commission is fixed" box and USD totals (narration unchanged). Frames and every scene change checked, no flash. |
| C05 | `C05-Associate-Commission-Statements-And-Tax.mp4` | C09 Commission + C10 Statements + C11 TDS/GST | Expected → earned → due → paid; basis = invoiced FOB of goods; invoice upload; hold / release / claw-back; CSV + printable statement; 194H threshold and catch-up, no PAN 206AA, GST on top, abroad (195, DTAA, TRC, 10F, No-PE, 15CA/15CB) | 9.7 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 10.80 min, 78 MB, audio −20.2 dB. New: exchange-rate card (fixed once at the rate of the buyer's payments, never changes, NexaCrest pays bank charges); "How your commission is fixed" box; totals in USD; lines marked Fixed with the India fixed-rupee line; "Fixed — due …, paid in the … payout run"; settled claims recomputed at the same fixed rate; MD change with reason. Frames reviewed. |
| C06 | `C06-Associate-Messages-Changes-Privacy-And-FAQ.mp4` | C12 Messages + C13 Changes + C14 Never see + C15 FAQ | Notices and messages; client moved, leaked code blocked, Suspended, Ended; what associates never see; 11 questions and answers; series overview | 10.4 | checked | 2026-11-06 | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 11.12 min, 82 MB, audio −19.9 dB. New: notices say "Commission fixed"; home money boxes in USD; the not-found page shown inside the portal with "Back to your home page"; FAQ: fixed by itself at full payment and never changes, never bank charges. Frames reviewed. |

## Group A — Everyone (overview)

| No. | File name | Covers | Status |
|---|---|---|---|
| A01 | `A01-1-Everyone-NexaCrest-In-Ten-Minutes.mp4` (Part 1) + `A01-2-Everyone-Everything-NexaCrest-Can-Do.mp4` (Part 2) | Part 1: what NexaCrest does; the three doors; departments, roles and who sees the money; the 9 stages / 19 steps; Standard vs Kanban; where help lives. Part 2 (new, 18 Nov 2026): a tour of every other area as the MD — Approvals, Clients and Agreements, Associates and commission, Products and pricing, Procurement / Suppliers / Logistics partners, Finance (CA module, INR register, Zoho sync), Reports, cockpit, Sales targets, Audit log, Setup checklist, Company settings, E-mail, Backups, Divisions; what to watch next | **Part 1 re-recorded + checked + sent 18 Nov 2026:** 10.51 min, −20.3 dB, in sync; menu narration now includes Sales targets. **Part 2 recorded + checked + sent 18 Nov 2026:** 9.54 min, −20.5 dB, in sync (second take: the practice-server upload-limit warning on Company Settings is gone; the Backups narration says the practice copy has no backups set up). |
| A02 | `A02-1-Everyone-One-Order-From-Enquiry-To-Confirmation.mp4` (6.8 min) + `A02-2-Everyone-One-Order-From-Factory-To-Closure.mp4` (5.1 min) | One sample CIF order from enquiry to closure, switching between every role that touches it (associate, Export Executive, Sales Manager, MD, Accounts, Finance Manager, Procurement, Logistics, Export Compliance, the buyer in the portal); routine steps done off camera during cards (scripts/_a02.js); CAFIN named as management-only, never shown to the buyer | Part 1 recorded + checked 7 Nov 2026. **Part 2 re-recorded 7 Nov 2026 (replacement, owner-approved, D-264):** 5.57 min, 47 MB, audio −20.6 dB. New card "The associate's commission is fixed" after the balance is cleared; the associate's order shows "Fixed — due …, paid in the … payout run" and the India fixed-rupee line; the MD lock remains only for the team incentive. The demo now records each buyer receipt with the rupees credited (as Accounts does), so the commission really is fixed on camera. Frames and every scene change checked, no flash. |

## Group D — Clients (buyers): every scenario

The 18 buyer scenarios, grouped into five videos of 8–12 minutes.

| No. | File name | Scenarios | Status |
|---|---|---|---|
| D01 | `D01-Client-From-Enquiry-To-Proforma.mp4` | 1 quotation details form (no login) · 2 receiving the quotation (e-mail card) · 3+5 Buyer Action Link: download our PO, upload the signed copy with the PO number; questions by replying to the e-mail · 4 PI details form (sections 1–6, lock confirmation) · the PI e-mail and the link moving on to the advance | checked 2026-11-07: 7.46 min, audio -20.2 dB, no card flash · **Re-recorded 8 Nov 2026 (replacement, owner-approved):** 7.50 min, −20.2 dB; form placeholders now role-style ("e.g., Purchasing Manager"); narration unchanged; 12-frame grid and scene changes checked, no flashes. |
| D02 | `D02-Client-Paying-And-Your-Login.mp4` | 6 Payment Action Link (advance, proof upload; same link for freight and balance) · 8 login created when the advance clears, set password, sign in, My Account · 9 forgot password (generic message, reset link) · 7 Letter of Credit (PI wording, Buyer Action Link waiting, LC recorded and confirmed usable, both orders in the portal) | **Re-recorded 7 Nov 2026 (replacement, owner-approved, D-265):** 7.03 min, 46 MB, audio −20.2 dB. New: every buyer sign-in shows the 6-digit e-mailed login code step (valid 10 minutes), typed while the narration explains it; developer banner hidden. Frames reviewed. |
| D03 | `D03-Client-Inside-Your-Order.mp4` | 10 My Orders (Active / Complete / Disputed / Lost / Cancelled) · 11 Order Confirmation acknowledgement · 12 documents you may download (never internal ones) · 13 Order Updates chat | **Recorded + checked 7 Nov 2026:** 7.07 min, 48 MB, audio −20.3 dB. Buyer signs in on camera once (e-mailed 6-digit code step); My Orders with one order in every group (setup: complete order run to Documents Sent, disputed via Admin status, lost, cancelled); OC e-mail card, "check these" card, acknowledgement in the portal; Documents (final customer-facing only; supplier PO placed off camera and shown absent), a completed order's full list, Buyer PO section closed after the advance; Order Updates: buyer asks for photos, Export Executive replies on the staff page with a placeholder photo, reply shown in the portal. Frames and every scene change checked, no flash. |
| D04 | `D04-Client-Balance-Shipping-And-Closing.mp4` | 14 reporting the balance payment · 15 shipping documents and Commercial Invoice · 16 order feedback · 17 Reorder This | **Recorded + checked 7 Nov 2026:** 6.82 min (11 s under the 7-minute aim), 52 MB, audio −20.0 dB. "What our team has done" card; freight Debit Note, scanned BL and Commercial Invoice e-mail cards (template wording); CI / Packing List / Debit Note in Documents; staff updates in Order Updates; Report a Payment (balance, UTR, amount, date, proof) → "Received — pending review" → after Accounts records and the Finance Manager clears (off camera) "Reviewed by our team"; balance receipt e-mail card; original documents card; order in Complete; Order Feedback (5 stars, suggested testimonial, Update Feedback); Reorder This (lines copied, quantity changed, note) and the Export Executive's Reorder Requests queue and review page. No login code on camera. Frames and scene changes checked. |
| D05 | `D05-Client-When-Something-Goes-Wrong.mp4` | 18 amendment, dispute (when switched on), process issue, cancelled / lost orders, staff "Log in as this client" banner | **Re-recorded + checked 8 Nov 2026 (replacement sent to owner):** 7.17 min, 57 MB; the cancelled order now shows "This order is cancelled, so a Buyer PO can no longer be uploaded." (4:35). First recorded 7 Nov: 7.05 min, 57 MB, audio −19.9 dB. Amendment as two cards + an e-mail card (no portal screen for amendments, SOP 10); Report a Process Issue → Managing Director replies on the order's Complaint Log → buyer answers in the portal; dispute card, MD ticks "Show Raise a Dispute", buyer raises it (logged with the response date in working days), MD's Dispute Log; Lost / Cancelled groups and a cancelled order (documents kept, "no payment to report"); "Log in as this client": three-switch card, MD ticks the client toggle (company switch on off camera), amber staff banner, End impersonation, portal visibility settings; "Locked out?" card. No login code on camera. Frames and scene changes checked. |

## Group B — Staff, by role

About 24 roles, one part per role or close pair, 8–12 minutes each; listed in the order of the order life cycle.

| No. | File name | Roles | Status |
|---|---|---|---|
| B01 | `B01-Staff-Export-Executive-Part-1.mp4` (8.43 min, 69 MB) + `B01-Staff-Export-Executive-Part-2.mp4` (7.98 min, 72 MB) | Export Executive (QT → PI → OC, documents, recording payments) | **Recorded + checked 8 Nov 2026** (scripts `B01-Staff-Export-Executive-Part-1/-2.js`, set-up `_b01.js`; Standard template, Kanban named as the same). **Part 1:** role card (does not approve documents, never sees costs or CAFIN); staff login on camera with password and the e-mailed 6-digit code; Dashboard, My Approvals (0), "Logged in as Sales/Export Executive · Sales & Export" (the demo exec gets a Sales membership), empty My Tasks, Orders cards; Quotation Intake Review → Accept & Create Client (buyer `TEST Gulf Stone Interiors LLC`, purchasing@gulfstone-interiors.example, Dubai/Jebel Ali, FOB) → New Order for this client: FOB, USD, Standard — New Buyer, Pick from Products (quote price and lowest price hint), quantity, Create Order; order page tour (tracker, Owners, At a Glance, Products, Order Communication); Annexure A link; Generate Quotation → draft "no reviewer assigned yet" → Sales Manager assigns and approves on camera → Stage 1 passes, status "sent" (mail off), final PDF; Buyer PO: 4a generate, 4b Buyer Action Link + Copy, 4c signed copy upload, 4d PO number + date received → Stage 3 unlocked. **Part 2:** PI Form Link explained, PI Intake Review page, skip with a reason, Generate Proforma Invoice (approval off camera, D-262 freeze); Step 5d: advance due, Payment Action Link (information only), amount, INR credited → rate worked out 84.10, required bank reference, read-back line, Record; Payment Overview (received, not cleared); 5e for the exec (awaiting Accounts); Finance Manager clears on camera → Stage 4 + portal login; Generate Order Confirmation, 6b OC Confirmation Link, Mark OC Sent to Buyer, buyer's e-mail acknowledgement recorded (exec holds override_buyer_acknowledgment) → Stage 5; Order Communication post; My Tasks "Raise the purchase requirement" → Purchase Requirements form → raised → task gone; Orders card at Stage 5; All Documents quick links; Dashboard. Audio −20.3 / −20.2 dB; 18–20-frame grids and every scene change checked, no card flash. Approvals by the Sales Manager in Part 2 and the Part 1 end state are set up off camera with the app's own services. |
| B02 | `B02-Staff-Sales-Manager-And-Director.mp4` (9.46 min, 71 MB) | Sales/Export Manager, Sales Director (approvals, assignment, cockpit, e-mail approval) | **Re-recorded + checked 19 Nov 2026** on the tutorial database (today's app: Approvals tabs, price check, self-approval rules, cockpit, Staff Productivity); sent to the owner |
| B03 | `B03-Staff-Accounts-Executive.mp4` | Accounts Executive (clearing, supplier payments, INR actuals, remittances) | **Recorded + checked + sent 18 Nov 2026:** 6.86 min, −20.2 dB, in sync (script `B03-Staff-Accounts-Executive.js`). One CIF order; on camera the Accounts Executive records and clears the advance (5d/5e), the freight (9c/9d) and the balance (11c/11d) with the rupees credited and the bank reference (rate worked out, read-back line, auto description), opens Payment Overview, adds the supplier's tax invoice and records its payment with the UTR (10a), then the INR Settlement Register, FIRC/eBRC and Reports › Payments & receivables. Other departments' steps are done off camera between chapters with the app's own services. Frames checked. |
| B04 | `B04-Staff-Finance-Manager-And-Director.mp4` | Finance Manager, Finance Director (order financials, CAFIN, associate bank checks, short-payment approval) | **Recorded + checked + sent 18 Nov 2026:** 5.76 min, −20.4 dB. FD approves the payment terms of our order from Approvals › Payment terms; FD clears a balance that arrived USD 120 short (what the shortfall is + reason); FM Step 12a cost with bank reference, 12b scheme eligibility, 12c profit review (15.5 % on the demo order: the set-up records the rupees credited per leg, as Accounts does); CAFIN generated (internal only); Reports › Finance & CA, cockpit, CA page. Frames checked (fourth take: right order picked, balance amount set, no double "UTR"). |
| B05 | `B05-Staff-Procurement.mp4` | Procurement Executive / Manager / Director (RFQ, quotes, POs, limits, QC) | **Recorded + checked + sent 18 Nov 2026:** 4.79 min, −20.7 dB (script `B05-Staff-Procurement.js`). A purchase requirement from Sales; two supplier quotes recorded and compared cheapest first with GST; award above the executive's ₹0 limit waits; the Procurement Manager approves within ₹5,00,000; Step 7 Supplier PO shown once placed; receipt with the quality check; Suppliers (+ Add product offers) and Supplier performance. |
| B06 | `B06-1-Staff-Logistics-Part-1.mp4` + `B06-2-Staff-Logistics-Part-2.mp4` | Logistics Executive / Manager / Director (packing, freight, BL, partners) | **Both parts recorded + checked + sent 18 Nov 2026.** Part 1 (4.35 min, −20.8 dB): the Logistics Manager's partners directory (executives cannot open it), freight terms 9a, the freight Debit Note (generated by Sales: "The FDN carries prices your department does not see"), packing with one line per crate 10b, Packing List 10c, shipping details 10d, BL Instruction 10e. Part 2 (3.69 min, −20.3 dB): draft BL logged and reviewed, BL issued (Stage 8 opens), shipping bill with the customs rate, partners' bills, scanned BL sent, originals received / endorsed, order closed with courier details, Reports › Freight cost. Two parts because both use the same sample set-up (they cannot be merged into one recording). |
| B07 | `B07-Staff-Export-Compliance.mp4` | Export Compliance Executive / Manager / Director (checklist, COO prep, benefit claims) | **Recorded + checked + sent 18 Nov 2026:** 3.94 min, −20.9 dB. Order-time checklist (ECGC cover, due diligence), the inspection result in 8a (recorded by Logistics, who hold manage_shipping), shipment checklist (inspection, fumigation, phytosanitary), COO prep sheet, the RoDTEP claim filed in 13g once the order is closed ("Claimed — waiting for money"), a card on what managers and directors add. |
| B08 | `B08-Staff-Order-Desk.mp4` | Order Coordinator, Operations Manager (status, owners, My tasks, cockpit) | **Recorded + checked + sent 18 Nov 2026:** 3.38 min, −20.1 dB. Orders without amounts, the order page, setting department owners, My tasks, the management cockpit, the Operations Manager adding an assignee (the Operations-queues scene was dropped: the Order Coordinator has no reports permission). |
| B09 | `B09-1-Staff-MD-And-ED-Part-1.mp4` + `B09-2-Staff-MD-And-ED-Part-2.mp4` | Managing Director, Executive Director (approvals, pricing, associates, incentives, settings) | **Both parts recorded + checked + sent 18 Nov 2026.** Part 1 (3.97 min, −20.5 dB): My Approvals, the Approvals page, a line price below the lowest price approved on the price-approval page with a reason, credit payment terms approved, the quotation review approved, cockpit and Reports. Part 2 (4.81 min, −20.5 dB): Global pricing settings (margin, other costs, negotiation room, exchange rate and cushion, who can do what), category price rules, commission levels, team incentives, users, departments, self-approval rules, field protection, setup checklist, e-mail testing mode, divisions, sample data. |
| B10 | `B10-Staff-Admin-And-System-Administrator.mp4` | Admin, System Administrator (users, roles, departments, settings, templates, backups) | **Recorded + checked + sent 18 Nov 2026:** 4.63 min, −20.2 dB. Create User (the app's one-time temporary password, masked on camera), departments, roles and permissions, task due days, SOP access, e-mail templates, drop-down lists, holidays, company settings, backups, data export, test mode; the System Administrator refused on Data export. |
| B11 | `B11-Staff-CA-Auditor-And-Investor.mp4` | CA, Viewer / Auditor, Investor (read-only views, CA module, reports) | **Recorded + checked + sent 18 Nov 2026:** 2.98 min, −20.3 dB. CA: CA / Accounting, INR register, an order read-only; Internal Auditor: Reports, Audit log; Investor: cockpit and the count-only reports (conversion rate, management cockpit, operations queues). |

## Group E — Divisions

| No. | File name | Covers | Status |
|---|---|---|---|
| E01 | `E01-Divisions-Domestic-Trade.mp4` | DOM: sale to Indian resellers in INR, GST tax invoices (planned division, shown in a test switch-on) | **Recorded + checked + sent 21 Nov 2026 (English and Hindi):** 8.58 min, −20.1 dB; Hindi 10.60 min, −17.3 dB. Domestic Sales Executive: quotation, order confirmation, purchase, dispatch with e-way bill, GST tax invoice, payment. Re-recorded after the Group E fixes (D-400: spaced stage strip, "GST" wording, Settings › Divisions) and the new role (D-399). **Re-sent 22 Nov 2026** (owner asked for all Group E again): recorded again on the current app (after QA Round 10 and D-405–D-407), English 8.58 min, Hindi 10.60 min, both checked. |
| E02 | `E02-Divisions-Import-And-Distribution.mp4` | IMP: import, customs duty, landed cost, stock, local sale | **Recorded + checked + sent 21 Nov 2026 (English and Hindi):** 8.45 min, −19.9 dB; Hindi 10.57 min, −17.4 dB. Distribution Sales Executive: import, landed cost, stock check, local sale with GST tax invoice. Re-recorded after the Group E fixes (D-400: spaced stage strip, "GST" wording, Settings › Divisions) and the new role (D-399). **Re-sent 22 Nov 2026** (owner asked for all Group E again): recorded again on the current app (after QA Round 10 and D-405–D-407), English 8.40 min, Hindi 10.57 min, both checked. |
| E03 | `E03-Divisions-Merchant-Trade.mp4` | MTT: buy abroad, sell abroad, both legs in foreign currency; plus Admin › Divisions activation checklist | **Recorded + checked + sent 21 Nov 2026 (English and Hindi):** 8.56 min, −20.1 dB; Hindi 10.48 min, −17.2 dB. MD switches Merchant Trade on in Settings › Divisions; the Merchant Trade Executive (D-399) records both legs, RBI checks, AD bank checklist. Re-recorded after the Group E fixes (D-400: spaced stage strip, "GST" wording, Settings › Divisions) and the new role (D-399). **Re-sent 22 Nov 2026** (owner asked for all Group E again): recorded again on the current app (after QA Round 10 and D-405–D-407), English 8.55 min, Hindi 10.50 min, both checked. |

## Group F — Associate life cycle under the new rules (D-410) — owner request 24 Nov 2026

One story for all four videos (the owner's 4 sections): TEST Scenario Associate (India), 2 clients, 25 orders Jan–Nov 2026 of
Black Galaxy Granite (demo) at the app's own quote USD 50.93/SQM, app ladder 2/5/7/10 %, client's price includes a fixed 5 %.
Built off camera on the clean tutorial database by the scenario builder (scratchpad `scn410tut/build.sh`: settings, associate,
clients, priced product, 25 orders through the app's services, receipts, Accounts paying each monthly run on the Payouts screen,
stages) and saved as `$TUT_WORK/scenario-ready.sql`; every recording starts from it. Shared set-up: `scripts/_scn410.js`.

| Code | File | Owner's section | Status |
|---|---|---|---|
| F01 | `F01-Associate-Lifecycle-1-What-The-Associate-Sees.mp4` | 1 — what the associate sees: Get price, orders and statuses, order statement, the % rising, Commission page, Payments to you, profile | **Recorded + checked 24 Nov 2026:** 6.20 min. **Hindi** (`…-Hindi.mp4`) recorded + checked 24 Nov 2026: 7.60 min |
| F02 | `F02-Associate-Lifecycle-2-What-The-MD-And-Staff-See.mp4` | 2 — the same year for MD / Accounts / ED / Export Executive: associate page, ladder step per order, payment advice Rev 1, Associates › Settings, payout runs | **Recorded + checked 24 Nov 2026:** 5.41 min |
| F03 | `F03-Associate-Lifecycle-3-How-The-Quote-Price-Is-Worked-Out.mp4` | 3 — how the quote is worked out (MD/ED): pricing settings choice + cost of each choice, product price build-up and associates table, levels, the price on the order | **Recorded + checked 24 Nov 2026:** 4.40 min |
| F04 | `F04-Associate-Lifecycle-4-Profit-And-The-Associates-P-And-L.mp4` | 4 — profit and the associate's P&L (MD/ED): Step 12 profit and margin box (2 / 7 / 10 % orders), Order Profitability, person statement, Associates report, paid from our margin so far | **Recorded + checked 24 Nov 2026** |

English only so far (Hindi on request).

## Out of date after the 11 Nov 2026 build — waiting for the owner's decision

Written 11 Nov 2026, after the owner-approved build of D-268, D-269 and D-270 – D-330 (register rows of
11 Nov 2026). **No video and no script was changed or re-recorded.** Videos stay stopped (owner, 11 Nov
2026) until all the discussed work is done; then, as the video rule requires, the owner decides per
video whether to re-record. The recorded videos below still play, but each shows screens, words or
steps that the app no longer has. Checked against the app's views of 11 Nov 2026 (PHP; the Node views
say the same).

**Changes that touch many videos**

- **Menus (D-320 – D-322):** the left menu went from about 60 entries to 13 (Dashboard, Approvals (n),
  My tasks (n) · Orders, Clients, Associates, Products, Procurement & suppliers, Finance & accounts ·
  Reports · Help & SOP · Users & roles, Settings); every section has a menu bar; the order page has a
  sticky action bar (Edit order · Documents · Full report · Duplicate order · More). "Admin › …" paths
  said in narration are now "Users & roles › …" or "Settings › …".
- **Approvals (D-310):** one Approvals page (tabs Prices · Payment terms · Documents · E-mails · Bank
  details · Other) replaces the separate sidebar items My Reviews and Email Approvals; approvers see
  "Waiting for your approval …" on the order page.
- **E-mail (D-280):** "e-mail sending on / off" is now Testing mode and one switch per e-mail on
  Settings › E-mail; screens never say "e-mailed" when nothing was sent and show a Copy link.
- **Plain language (D-330):** 190 rewrites of what buyers, associates and suppliers read, buyer stage
  names, e-mail texts.
- **Login code screen (D-283):** the tick is now "This is my own office computer"; a "Stay logged in"
  popup comes 2 minutes before an idle logout.

| Video | What no longer matches (screens, wording, numbers, steps) | Script labels / selectors that changed |
|---|---|---|
| A01 Everyone in ten minutes | The left-menu tour (13 entries now, section menu bars), Help links (Getting Started and SOP are now under **Help & SOP**), "Admin › SOP access" (now Users & roles › SOP access), the login-code screen tick, the Approvals entry and counts. | `header.sidebar a[href=…]` for the Help links (the left menu now links the section's first page); narration "Admin › SOP access". |
| A02-1 One order, enquiry to confirmation | Old menus; order page without the action bar and with the old products section (now a compact table with a side panel, D-273); quoting in any unit with piece size (D-270); My Reviews (now Approvals › Documents); the price check now waits on the Approvals page; buyer's portal: "Order Confirmation — Your Acknowledgement Needed" is now **"Please confirm your order"** and the button **"Yes, this is correct — please go ahead"**; buyer stage names. | `name: 'I acknowledge and confirm to proceed'` (narration "I acknowledge"); `#step-products input[name*=…]` (products section rebuilt); caption "My Reviews". |
| A02-2 One order, factory to closure | The associate's commission card: payout now on the last day of the month (not the 5th); associate order wording "Fixed — due …, paid in the … payout run" changed with the new statuses ("Earned — will be paid on <date>" …); menus. | — (no changed label found) |
| B01-1 / B01-2 Export Executive | Old menus (Quotation Intake Review is Orders › Quotation requests; sidebar badges), Dashboard tiles (Approvals), order action bar, compact products table and side panel, units and piece size on New Order and the order page, "sent (mail off)" wording (now "document e-mails to the buyer are held"), Order Communication note (D-268 now gives the true reason), Recent Activity (D-268: only the person's own orders), "Pick from Products" now a button above the options (D-268), My Tasks for staff without a department (D-268). | `select.product-picker` and `#step-products …` (New Order / products section rebuilt); captions "My Approvals", "My Reviews · Approve". |
| B02 Sales Manager and Director | My Reviews and Email Approvals "in the sidebar" (now Approvals › Documents / E-mails, with counts on Approvals); "Admin › Self-approval rules" (Users & roles); the MD's line-price approval now listed on Approvals › Prices with the order-page bar and the requester's "Sent to the MD … waiting"; "mail sending on" wording; D-269 fixes (payment-terms approver wording, Buyer PO note on cancelled or lost orders, Staff Productivity, cockpit shows every department, reason hint only on error); catalogue columns "Staff lowest" / "Your lowest" (D-305). | `locator('nav, aside').getByRole('link', { name: /Email Approvals/ })`; caption/narration "My Reviews … in the sidebar", "Admin › Self-approval rules". |
| C01 Associate welcome | Home and Commission pages: associates now see only their current % ("x% of the FOB value of each order from the clients you introduced"), the 4-step "How it works" and the new statuses — never levels or the ladder (D-292); payout on the last day of the month; the login page has "Forgot password?" (D-282); the bank-confirmation bar when a change waits (D-293); 70 associate wording rewrites (D-330). | — (narration) |
| C02 Becoming an associate | Accepting a registration sends the set-password e-mail and shows the link with Copy (D-282); the associate page has three blocks; KYC: document type first, number 5–30 characters, **ID proof and Address proof are separate items** (D-290); bank: the associate confirms new details (last 4 digits + e-mailed code), then **one** staff member who did not enter them verifies with at least 2 "how I checked" ticks — no longer "bank check (MD) + verify (ED)" (D-293). | `name: 'Send portal login link'` (now **"Send set-password link"**); `name: 'Send for checking'` (now **"Send — then confirm with the e-mailed code"**); `name: 'First check'` and `'Verify (second person)'` appear only with the setting "Two people" (default is one person). |
| C03 Starter level and first client | Commission states now read "Not earned yet — buyer still paying" / "Earned — will be paid on <date>" / "Paid on <date> (ref)" / "On hold — a buyer claim on this order is open"; associate wording (D-330); quotation requests on Orders › Quotation requests. | — (narration; on-screen text) |
| C04 Prices, requests and levels | Associates see no prices in the catalogue; Get price gives their own quote and lowest (7 days) (D-305); the MD's screens for levels moved (Associates › Commission ▾); Products menu bar. | — |
| C05 Commission, statements and tax | "Basis = invoiced FOB of goods" is now worded "FOB value"; payout day "last day of the month" (Sunday / holiday → the working day before) instead of the 5th; the new commission statuses; associates no longer see level or ladder; MD's commission rule (standard ladder / own ladder / fixed, with a reason) (D-291). | — |
| C06 Messages, changes, privacy, FAQ | Notices and e-mails reworded (D-330) and now sent according to Settings › E-mail (D-280); bank change confirmation and "This is not me" (D-293); address-proof grace (60 days) (D-290); FAQ answers on payout day and what associates see. | — |
| D01 From enquiry to proforma | Quotation request form, Buyer Action Link page and PI details form reworded (D-330); e-mail cards show the old texts ("Buyer Inquiry Ref" is now "your enquiry reference"; buyer action link and PI form link subjects changed). | `name: 'Submit PI Details'` (now **"Send my details"**). |
| D02 Paying and your login | Payment Action Link and portal: "UTR" is now **"Bank reference number (transaction ID, UTR or SWIFT reference)"**; the portal invite e-mail (works once, gives the permanent login page) and the password-reset e-mail are reworded; the login-code e-mail has its own buyer wording; a buyer is signed out after 2 hours idle with a "Stay logged in" warning (D-283). | — (labels clicked still exist) |
| D03 Inside your order | My Orders: "View documents" is now **"Open order"**, the group "Lost" is now **"Did not go ahead"**; the acknowledgement box and button reworded (as in A02-1); "Buyer PO" is now **"Your purchase order"**; buyer stage names (Quotation / Order confirmed / In production / Shipping / Shipped). | `name: 'View documents'`; `hasText: 'Your Acknowledgement Needed'`; `name: 'I acknowledge and confirm to proceed'`; `hasText: 'Buyer PO'`; `group(d, 'Lost')`. |
| D04 Balance, shipping and closing | E-mail cards: the freight Debit Note subject is now "Freight bill (debit note) … — please pay before we ship", BL copy, balance received and shipment-readiness texts reworded; Report a Payment field "UTR" → bank reference wording; buyer stage names. | — (labels clicked still exist) |
| D05 When something goes wrong | "Raise a Dispute" is now **"Ask to cancel this order"** (portal heading and button; the staff tick reads "Show 'Ask to cancel this order' (dispute) button"); "Report a Process Issue" is now **"Tell us what we could do better"** (button "Report This Issue" unchanged); "Lost" → "Did not go ahead"; "Buyer PO" → "Your purchase order"; "View documents" → "Open order"; on the staff client page "Log in as" and the portal login moved into the new **Client portal login** block (D-282). | `section(d, 'Raise a Dispute')` and `name: 'Raise a Dispute'`; `section(d, 'Report a Process Issue')`; `section(d, 'Lost')`; `section(d, 'Buyer PO')`; `name: 'View documents'`; `section(d, 'Client Portal Access')` (the Log-in-as part moved). |

Not affected as far as checked: none of the recorded videos is fully unaffected — every one shows the old
left menu or reworded text somewhere. B03 – B11 and Group E were not started; their scripts are to be
written against the new menus.

**Decision needed from the owner:** which of the 17 recorded videos (A01, A02-1, A02-2, B01-1, B01-2, B02,
C01 – C06, D01 – D05) to re-record, and in what order, once the videos may restart. Re-recorded videos are
sent as replacements and marked here.

## Also out of date after the 12 Nov 2026 build (noted only — owner, 12 Nov: "just a note it")

No video or script was changed. Videos stay on hold until the owner lifts the hold.

| Video | What now differs | Decision |
|---|---|---|
| B01 Part 1 (Export Executive) | Order page Step 1 shows the button **"Edit Annexure A ›"** instead of the grey link "Enable / manage Annexure A"; the narration "Enable Annexure A here" no longer matches. The Annexure page has one "Save all changes" (D-341). | Note only (owner) |
| B01 Parts 1 and 2, A02-1, D01, D04 | New Order line form has "No. of pieces" / "Size note"; new orders print the detailed product table on QT/PI/OC/CI/Buyer PO/PL (D-339). | Owner to decide when videos restart |
| B02 | Price check: the floor note in the line's unit (D-339) and the "Prices worked out at ₹… per USD" line (D-340). | Owner to decide when videos restart |
| C04 | Get price shows "Your prices in other units" (D-339). | Owner to decide when videos restart |
| D04 | Reorder This form has "As per annexure" on each line (D-338). | Owner to decide when videos restart |

D-352 (12 Nov 2026: the "Fields marked * are mandatory …" note and the red stars removed from generated
documents) — checked against every script: no recorded video opens a generated QT, PI or CI (they show the
app pages and e-mail cards with the PDF's file name only), and no narration mentions the note or the stars.
No video is affected.

D-353 (12 Nov 2026: the plain "*" after field labels removed from every generated document, PDF and Word) —
checked against every script: no recorded video opens a generated document (they attach or name the PDF
file only), and no narration mentions the label stars. No video is affected.

D-357 (12 Nov 2026: one list of surface-finish names — Settings › Lists › surface_finish names every
finish; "Polished" / "Mirror Finish" is now **"Natural Polish"**, "Natural/Sandblasted" → "Sandblasted",
"Leather/Satin" → "Leather", "Brushed" added; Pricing › Specifications keeps the price only; the "More ›
finish" box is hidden for a line picked from Products) — listed only, **no video or script changed, nothing
re-recorded; the owner decides when the videos restart**:

| Video | What now differs | Decision |
|---|---|---|
| B01 Part 1 (Export Executive) | New Order: after "Pick from Products" the line's **Finish** box (on camera with "Polished" selected) gives way to the note "Finish: the one chosen in the options above (Surface finish)"; the Surface finish option reads "Natural Polish". Demo line data (`_b01.js`) still writes "Polished". | Owner to decide when videos restart |
| B01 Part 2, B02 | Order page products table / line panel: demo lines show finish "Polished" (`_b01.js`, `_b02.js` data); the side panel's More › Finish box is hidden for a picked line. | Owner to decide when videos restart |
| A02-1, A02-2 | Demo order lines and the Supplier PO finish are "Polished" (`_a02.js`); the Settings list and Pick from Products now say "Natural Polish". | Owner to decide when videos restart |
| C04 (Get price part) | The Surface finish choices on Get price: the base option is "Natural Polish" (was "Polished"), plus "Rock-Pitched" and "Bush-Hammered" (no price change yet). The chosen "Leather" is unchanged. | Owner to decide when videos restart |
| D01 – D05 (buyer) | Demo lines (`_buyer.js`) show finish "Polished" on portal pages; D04's Reorder This form shows a Finish drop-down fed by the Settings list, which no longer has "Polished". | Owner to decide when videos restart |

Not affected: A01, C01 – C03, C05, C06 (no finish shown or said).

D-358 (13 Nov 2026: **Duplicate** next to View on each row of Products › All products and next to Edit on the
product page; the confirm screen and the "Copied from …" note on the copy) — checked against every script: no
recorded video opens the staff Products list or a product page (B01 / A02-1 only use "Pick from Products" on an
order, C04 / C06 show the associate's catalogue, which has no Duplicate). An inactive copy is never offered in Pick
from Products. No video is affected; nothing re-recorded.

D-359 (13 Nov 2026: **Edit price** button on each supplier row of Products › a product › Market prices and an
**Edit price** link on each row of a supplier's "Prices given for products"; the new Edit price form; offers of
inactive suppliers no longer used for pricing; pricing values from product → category → global) — checked against
every script: no recorded video opens Market prices, a supplier's page or a supplier-price page, and every price
shown on camera (Pick from Products, Price check, Get price) is unchanged (every product's figures were compared
before and after on both stacks). No video is affected; listed only, nothing re-recorded.

D-360 (13 Nov 2026: Procurement & suppliers › Suppliers lists each supplier's product offers with the base price
quoted, quoted on · valid until, an Offer active switch, Edit price and + Add product, a "No product prices yet"
filter and badge; the supplier page's "Prices given for products" gains the switch, Edit price, Open product and a
+ Add product panel and loses its "Used for pricing" column) — checked against every script: no recorded video opens
the Suppliers list (`/suppliers`) or a supplier's page, and every price shown on camera is unchanged (every product's
figures compared before and after on both stacks). No video is affected; listed only, nothing re-recorded.

D-361 (13 Nov 2026: Products list with Suppliers / Lowest – highest / Base price columns and the statuses Configured ·
Not configured · No active supplier · No supplier yet; "+ Add Product" and "Link suppliers" removed; Configure / Edit with
the three levels and resets; Link a supplier; Link these prices; Pricing settings physical block; Price rules per-category
%; Pick from Products offers only quotable products, others greyed under "Not offered yet"; Supplier PO supplier-choice
table with price prefill and a reason for a changed price) — checked against every script. Affected:
- **B01 Part 1** (recorded) — "Pick from Products" on the new order: the screen and the price on camera are the same, but
  the demo product must now be configured to be offered; the fixture `_b01.js` now fixes its base price at the same
  ₹3,280 (no figure on camera changes). If other inactive, not-configured products exist when it is recorded again, the
  picker also shows them greyed under "Not offered yet" at the bottom of the list.
- **B02** (recorded) — the same demo product fixture (`_b02.js`, same fix); no figure on camera changes.
- **A02-2** — opens Stage 5 after the Supplier PO terms are saved (the terms form with the new supplier table is not
  shown); not affected.
- Not yet recorded but will show these screens: **B05** (Procurement: Supplier PO table, Link these prices),
  **B09** (MD / ED: Products list, Configure, Pricing settings, Price rules).
Listed only — nothing re-recorded; the owner decides whether B01 Part 1 and B02 are re-recorded.

D-362 (13 Nov 2026: "Edit price" / "Open product" drawn as small buttons on a supplier's page, the Suppliers list and
Configure; a Products tab with 0 products opens Active, and after Reactivate / Deactivate from the list the tab that now
holds the product; "Pricing settings" renamed **Global pricing settings** and "Price rules" **Category price rules**, each
with a one-line note, reached only from the Products screen's link bar; the menu items "Price rules", "Pricing settings
(margin, buffer, exchange rate)" and "Exchange-rate history" removed) — checked against every script: no recorded video
opens the Products list, a supplier's page, Configure, Pricing settings or Price rules, or shows the Products menu bar
or the Pricing drop-down; no recorded narration says "Pricing settings" or "Price rules". No recorded video is affected.
Not yet recorded but will show these: **B05** (Procurement: supplier page and Suppliers list buttons) and **B09** (MD /
ED: Products list tabs, Global pricing settings, Category price rules). Listed only — nothing re-recorded.

D-363 (13 Nov 2026: Products list row actions as small buttons — Edit / Configure, View, Duplicate; "Add a category"
with the name alone and the attributes two per row, new placeholders for attributes 4–6; "Also work out from this …
price" as one row per thickness with live price and a category / this product badge, and one "Change the % rules for
this product" link; Configure › Thickness rules) — checked against every script: no recorded video opens the Products
list, Categories, "+ Add / update a price" or Configure. No recorded video is affected. Not yet recorded but will show
these: **B05** (Procurement: "+ Add / update a price") and **B09** (MD / ED: Products list, Categories, Configure ›
Thickness rules). Listed only — nothing re-recorded.

D-364 (13 Nov 2026: Procurement's "Supplier performance" link drawn as a small button beside the Status filter; supplier
prices may be typed per SQFT on "+ Add product", Edit price and Link a supplier — saved as the SQM price, the conversion
shown under the field; every screen label "m²" / "sq ft" now reads SQM / SQFT; documents, e-mails and Zoho unchanged) —
checked against every script: no recorded video opens the Procurement page, a supplier's page, Edit price, Link a
supplier, Market prices, Configure or the product page, and no narration says "square metre", "m²" or "sq ft".
Possibly affected, listed only: **B01 Part 1** (Export Executive) — the order line panel's small grey help line under the
size fields now reads "… SQM and SQFT the thickness …" (was "m² and sq ft"); not narrated, the line on camera is priced
per SQM so its price hint is unchanged. Owner to decide whether to re-record (together with the B01 items noted above).
Not yet recorded but will show these: **B05** (Procurement: Supplier performance button, + Add product per SQFT, Edit
price) and **B09** (MD / ED: Products list, Configure, Market prices with SQM / SQFT labels). Listed only — nothing
re-recorded.

D-365 (13 Nov 2026: Products list short view — Name with HS code · origin · unit under it, supplier prices and base
price with unit and thickness, Cost and Min price highlighted, "Show all columns", fixed Name column; "Pricing ready?"
reworked — configured base price and active supplier lines, ✔ / ✘ / ℹ, a fix button on every ✘ line, associates as
information while none is active) — checked against every script: no recorded video opens the Products list, the
product page or "Pricing ready?", and no narration names these columns or checks. No recorded video is affected. Not yet
recorded but will show these: **B05** (Procurement: the supplier-price lines of "Pricing ready?") and **B09** (MD / ED:
Products list short view and "Show all columns", "Pricing ready?"). Listed only — nothing re-recorded.

## Needs re-recording after the 13 Nov 2026 QA fixes (owner decision D-368, item 10b; recording still ON HOLD)

| Video | What now differs |
|---|---|
| A01 | Getting started: roles other than MD / ED / Admin no longer see the dashboard screenshot; SOP chapters a role may not open are plain text (QA fix UI-005). |
| B01 Part 1 | New order: Currency starts at "Choose…" (UI-013); a quantity of 0 is caught beside the field (UI-011); a very large quantity asks "I confirm this quantity" (item 10a). |
| C02 | New associate: Based in, Currency and Commission start at "Choose…" (UI-013). |

B02 was checked and stays as it is. Do not record until the owner lifts the hold.

## Script update of 13 Nov 2026 — docs audit C (owner request; recording still ON HOLD)

The owner asked (13 Nov 2026) for every training and tutorial document, including the video scripts, to match
the app so the videos are ready to be recorded again later. The scripts below were updated **from the app's
views, controllers and seed data only** (PHP `app/views`, checked against the Node twins where they differ):
nothing was recorded, `run.js` was not run, every script passes `node --check` and builds its step list.
Covered: register rows D-336 – D-365 and the 11 Nov 2026 menu / wording changes listed above (D-268, D-273,
D-280, D-282, D-290, D-293, D-310, D-320, D-330) where a script showed or said them. A recorded video marked
"re-record needed" still plays, but its picture or narration now differs from the app; the owner decides.

**Changes that touch several scripts**

- **D-357 finish names:** every demo order line and the demo Supplier PO now use "Natural Polish" (was
  "Polished") — `_a02.js`, `_b01.js`, `_b02.js`, `_buyer.js`; the purchase-requirement text in B01 Part 2 too.
- **D-360 / D-361 products:** the B01 and B02 demo product's supplier price is now an offer of an active
  directory supplier ("TEST Demo Quarry", created in the set-up, as "+ Add product" makes it) and the product
  is configured, so it is offered in Pick from Products; no figure on camera changes (linking keeps every price,
  as the D-361 migration check showed).
- **D-342 price lock:** narration says the prices, products and exchange rate are fixed when the first
  Quotation is approved (not at the PI); later changes only through a "Products & prices" amendment.
- **D-310 / D-320 menus:** "My Reviews" → Approvals › Documents; "Email Approvals in the sidebar" →
  Approvals › E-mails and the Approvals entry in the left menu; "Admin › …" → "Users & roles › …" or
  "Settings › …"; Quotation Intake Review = Orders › Quotation requests; PI Intake Review = Orders › PI-stage details.
- **D-280 e-mail wording:** "e-mail sending off" → "document e-mails to the buyer are held".
- **D-330 buyer wording:** "Open order" (was View documents), "Please confirm your order" and "Yes, this is
  correct — please go ahead" (was Your Acknowledgement Needed / I acknowledge and confirm to proceed), "Your
  purchase order" (was Buyer PO), "Did not go ahead" (was Lost), "Tell us what we could do better" (was Report a
  Process Issue), "Ask to cancel this order" (was Raise a Dispute), "Bank reference number (transaction ID, UTR
  or SWIFT reference)", buyer stage names (Quotation · Order confirmed · In production · Shipping · Shipped).
- **E-mail cards** (Group D) now copy the seeded templates (`email_templates` in seed.sql): subjects name our
  company ("— NexaCrest International Private Limited"), not the buyer; bodies reworded as in the templates.
- **No PII:** the Group D demo buyer is now "TEST Northsea Stone Trading B.V." (was without the TEST prefix);
  all e-mails stay role-style or on `.example` domains.
- **Documents (D-336, D-339, D-343 – D-345, D-349, D-352 – D-356):** no script opens a generated PDF or Word
  file (they show app pages and e-mail cards with the file name only), so nothing changed for them.

| Video | Recorded? | What changed in the script (13 Nov 2026) | Re-recording |
|---|---|---|---|
| A01 Everyone in ten minutes | yes (7 Nov) | Help link now the left-menu entry **Help & SOP** (selector `nav.sidebar-nav a[data-nav="help"]`; the left menu no longer has a link to `/sop`, so the old `header.sidebar a[href="/sop"]` step would have failed); dashboard narration names the short menu and section menu bars; "Admin › Departments" → Users & roles › Departments; "Admin, SOP access" → Users & roles, SOP access; "Admin, Sample Data" → Settings, Sample data. | **needed** (menus differ on screen) |
| A02-1 One order, enquiry to confirmation | yes (7 Nov) | Products step points at the compact products table (old `#step-products input[…]` selectors gone, D-273) and says lines are fixed after QT approval (D-342); "My Reviews" → Approvals › Documents and the QT approval narration adds the price lock; "held" e-mail wording; PI approval narration (prices fixed at QT, terms frozen at PI); buyer portal: "Open order", "Please confirm your order", button "Yes, this is correct — please go ahead"; demo lines "Natural Polish". | **needed** |
| A02-2 One order, factory to closure | yes (7 Nov) | Supplier PO narration: the supplier-offer table (lowest first, choosing fills supplier and price; a changed price needs a reason kept with the PO — D-361; the form itself is not shown); associate commission card and narration: "Earned — will be paid on <date>", paid on the payout day (default last day of the month, D-292); demo Supplier PO finish "Natural Polish". | **needed** |
| B01 Part 1 Export Executive | yes (8 Nov) | **New step "Thickness and Size / format"** after Pick from Products: both start on "Choose…", no price until both are chosen (D-348); the script chooses "20 mm" and "Gangsaw slab", then points at the price hint and the Surface finish option (the line's finish, "Natural Polish"; no separate finish box — D-357). Pick from Products narration: only configured products with an active supplier, others greyed under "Not offered yet" (D-361). Products step: compact table and "+ Add product" (old input selectors gone). "Edit Annexure A" (D-341). **New step "Prices and products are now fixed"** after the QT approval, pointing at the price-lock note (`#price-lock-note`, D-342). Orders › Quotation requests; Approvals › Documents; "held" wording; Recent Activity = own orders (D-268); recap. Set-up `_b01.js`: supplier offer linked to the active demo supplier; Part 2's line "Natural Polish". | **needed** |
| B01 Part 2 Export Executive | yes (8 Nov) | PI approval narration (prices and rate fixed at the QT; terms and company details frozen at the PI — D-342); PI Intake Review = Orders › PI-stage details; buyer's button "Yes, this is correct — please go ahead"; "document e-mails held"; Order Communication narration; purchase requirement "20 mm, Natural Polish, Grade A"; Recent Activity = own orders. | **needed** |
| B02 Sales Manager and Director | yes (8 Nov) | Dashboard narration (My Approvals counts every kind; same counts on Approvals); "Ask MD" narration: waits on the MD's Approvals › Prices; prices card: after QT approval only a products amendment (D-342); "My Reviews … in the sidebar" → Approvals › Documents; quotation approved = prices fixed; "Admin › Self-approval rules" → Users & roles › Self-approval rules; Sales Director: left-menu **Approvals** entry instead of "Email Approvals" (selector changed), Approvals › E-mails caption; cockpit under Reports; Agreements under Clients, filter "Expiring within" (the old "Expiring soon" chip selector would have failed). Set-up `_b02.js`: supplier offer linked to the active demo supplier; line "Natural Polish". | **needed** |
| C01 Associate welcome | yes (7 Nov) | Not changed in this pass. Still open from 11 Nov: associate Home / Commission wording (current % only, 4-step "How it works", new statuses, payout day — D-292), "Forgot password?" (D-282), bank bar (D-293), D-330 rewrites. | needed (open, see "Still to do") |
| C02 Becoming an associate | yes (8 Nov) | Part C02: "Send portal login link" → **"Send set-password link"** with the login-page link and Copy (D-282). Part C03: KYC checklist names ID proof and Address proof separately and a **new upload step "Address proof"** (D-290; the staff accept loop now covers 6 documents); bank: button "Send — then confirm with the e-mailed code", **new steps on "Please confirm your new bank details"** (E-mail me a code, last 4 digits, code, "These are my bank details", Confirm; "This is not me" explained — D-293); staff side: **one** person verifies with at least two "how I checked" ticks (bank proof + phone) and "Verify" — the "First check" + Finance Manager "Verify (second person)" steps are removed (setting default "one person"). | **needed** |
| C03 Starter level and first client | yes (7 Nov) | Not changed in this pass (no D-336 – D-365 item). Still open from 11 Nov: commission states wording (D-292), D-330 rewrites. | needed (open) |
| C04 Prices, requests and levels | yes (7 Nov) | Part C06: **new step "Your prices in other units"** after Get price (per CBM / SQM / SQFT at the chosen thickness, D-339 / D-364); "What changed the price" now points at the last table (the first table is now the other-units one) and says the standard finish is Natural Polish (D-357). Parts C07 / C08 not changed (open from 11 Nov: D-305 catalogue, D-292 levels hidden from associates). | **needed** |
| C05 Commission, statements and tax | yes (7 Nov) | Not changed in this pass. Still open from 11 Nov: payout day "last day of the month", new statuses, no level / ladder for associates, MD's commission rule (D-291, D-292). | needed (open) |
| C06 Messages, changes, privacy, FAQ | yes (7 Nov) | Not changed in this pass. Still open from 11 Nov: notices / e-mails reworded (D-330, D-280), bank change confirmation and "This is not me" (D-293), address-proof grace (D-290), FAQ on payout day. | needed (open) |
| D01 From enquiry to proforma | yes (8 Nov) | PI details form button **"Send my details"** (was "Submit PI Details" — the old selector would have failed); e-mail cards: Buyer Action Link ("NexaCrest — sign your purchase order and confirm your order", numbered steps), PI form link ("… please confirm your details for the proforma invoice"), QT and PI as the templates; "your own enquiry reference"; demo buyer "TEST Northsea Stone Trading B.V."; demo lines "Natural Polish". The address is typed in parts (D-346 — the public form was already in parts). | **needed** |
| D02 Paying and your login | yes (7 Nov) | Payment link e-mail card and narration: "bank reference number (transaction ID, UTR or SWIFT reference)"; portal-invite and password-reset e-mail cards as the templates; LC PI e-mail card ("Please open the letter of credit (LC) as agreed …"); demo buyer TEST-prefixed. | **needed** |
| D03 Inside your order | yes (7 Nov) | My Orders: "Open order", "Did not go ahead", plain stage names (the active order reads "In production"); "Please confirm your order" box and its button; "Your purchase order" section and its closed-upload text; "Tell us what we could do better"; OC e-mail card as the template; recap. | **needed** |
| D04 Balance, shipping and closing | yes (7 Nov) | E-mail cards: freight bill subject "Freight bill (debit note) … — please pay before we ship", BL copy, CI and balance-received texts as the templates; My Orders says "Shipping"; Report a Payment: bank reference number wording, "Payments you have told us about", "Received — we are checking it"; Reorder This narration: "Quantity not decided yet" and the new **"As per annexure"** tick (D-338); the Finish list has no "Polished" any more (demo lines "Natural Polish", D-357). | **needed** |
| D05 When something goes wrong | yes (8 Nov) | "Tell us what we could do better", "Ask to cancel this order" (portal heading, button and the staff tick wording), "Did not go ahead", "Open order", "Your purchase order" with its new text; "Log in as this client" now in the Client portal login block (D-282); amendment card adds the **Products & prices** amendment (D-342: after QT approval, quantities / sizes / lines only by a signed amendment, a price only goes down). | **needed** |
| B03 – B11 (E01 – E03 done 21 Nov 2026) | not started | — Write against the 13 Nov app: **B05** Procurement (Suppliers list offers, Offer active switch, "+ Add product" with SQFT input, Edit price, Supplier PO supplier-offer table and price-override reason, "Supplier performance" button, "Pricing ready?" supplier lines); **B09** MD / ED (Products list short view and "Show all columns", Configure / Edit, Link a supplier, Link these prices, Global pricing settings, Category price rules, Configure › Thickness rules, Duplicate, price-lock and Products & prices amendments). | not recorded |

**Still to do before the associate videos are recorded again (not done in this pass):** the associate
narration of C01, C03, C04 (parts C07, C08), C05 and C06 on commission statuses, payout day, levels and the
D-330 wording (11 Nov 2026 items D-282, D-290 – D-293, D-305, D-330). These need the associate portal pages
read line by line against each narration and are best done together with the owner's decision on which
associate videos to re-record.

**Checked by reading only — to confirm on the first new take:** (1) B01 Part 1 — the New Order line's options
box (`.product-row .spec-options`) and the hint text once both options are chosen; whether old inactive
dev-test products appear greyed under "Not offered yet" (narrated in general terms, not pointed at). (2) B01
Part 1 — the price-lock note shows for the Export Executive (needs "manage orders"). (3) C02 — the bank code
appears on the confirm page only on a dev server (`showCodeOnScreen`); the "Verify" form needs the default
setting "one person" and a verifier with "manage associate bank". (4) D03 / D04 — the plain stage names follow
`AssociatePortalRepository::stageLabel` (stage 4 = "In production", stage 8 = "Shipping"). (5) A01 — Getting
Started is a stand-alone page without the section menu bar, so only the left-menu entry is pointed at.

## Also out of date after the 14 Nov 2026 build (noted only; recording still ON HOLD)

No video or script was changed. Owner to decide when videos restart.

| Video | What now differs | Decision |
|---|---|---|
| B01 Part 1 (New Order form) | **Container Type** is now required ("Container Type *", starts on "Choose…", D-376); the script picks 20ft Standard (narration unchanged, the field is not mentioned). | Owner to decide when videos restart |
| A02-1, B01 Parts 1 and 2, B02 (order page Products table) | Line numbers stay 1, 2, 3 after a line is deleted and added again (D-373); under each price "Priced at ₹… per USD on …" or "Rate not recorded", and a **Use today's price (…)** button under the Price check when today's quote differs (D-374). | Owner to decide when videos restart |

## Also out of date after the 18 Nov 2026 build — D-380 … D-389 (noted only; recording still ON HOLD)

No video or script was changed. Owner to decide when videos restart (CLAUDE.md video rule). Checked against every
script in `tutorials/scripts/` (and `scripts/parts/`).

| Video | What now differs | Decision |
|---|---|---|
| B01 Part 1 (New Order form, Pick from Products) | After picking "Black Galaxy Granite Slab (HS 68022390)" a required **Sold as \*** drop-down appears ("<specification> · HS <code>", e.g. "Gangsaw slab · HS 68022310"; the demo product has no Sold as ticks, so every specification is offered). The HS code now comes from the Sold as and is shown read-only ("from specification") — the narration "the description, the H S code and the unit fill in by themselves" is no longer exact. Choosing it limits **Unit** to the form's units and **Surface finish** to the form's finishes. With a Sold as an SQM line needs only the Thickness (Size / format no longer required) — the scene "Two options start empty … the price depends on them" no longer matches word for word. The script must choose a Sold as before **Create Order**, or the line is refused (D-385). | Owner to decide when videos restart |
| A02-1, B01 Parts 1 and 2, B02 (order page Products table and line panel) | A line with a Sold as shows "Sold as: <specification> (<form>) · HS <code>" with the tag "from specification" (or "changed by MD/ED — <reason>"); **+ Add product** / **Edit** step 1 has **Sold as** with the HS code. The demo lines made by `_a02.js`, `_b01.js`, `_b02.js` have no Sold as, so they look as before; any line entered on camera shows the new field (D-385). | Owner to decide when videos restart |
| A02-2 (Step 7 · Supplier PO) | The Supplier PO terms form has a new **Product Form** box (filled from the lines' Sold as; empty for the demo lines) and the saved terms show "Product Form" only when filled (D-387). | Owner to decide when videos restart |
| QT / PI / OC / CI / BLI documents (A01, A02-1, A02-2, B01, B02, D01, D03, D04, D05) | Not affected on screen: no video opens a document's pages (only the review queue, e-mail cards and file names). The new wording — Bill of Lading "full set of original Bills of Lading" (D-380), "Product Form: …" (D-386 / D-387), "To Be Confirmed" totals (D-388) — would show only if a future script opens a PDF. The narrations of A02-2 and D04 already say "the complete set of original …", which matches D-380. | No change needed |
| Not in any recorded video | Product › Specifications (old **Forms** ticks → **Sold as**), Products list "Sold as: …", Products › Pricing › **Forms of stone**, Products › **Specifications (HS)**, Settings › Lists › surface_finish (**Offered for forms**, new finishes Roughly trimmed / Sawn), Edit Supplier (**CHA + transport per container**), Edit price (CHA fallback text, **Priced per** Tonne), the option **Order** boxes (D-389). To be covered by the B05 (Procurement) and B09 (MD / ED) scripts, not started yet. | — |
| C04 (associate Price access / Catalogue, "which forms, like slabs or tiles") | Not affected: the associate portal and price access did not change in this build. | No change needed |

## Also out of date after the 20 Nov 2026 build — D-394 quick-quote prospects — RE-RECORDED 20 Nov 2026

**Done (owner 20 Nov: "start on videos … give me all the videos"):** A01 Part 1, A01 Part 2, B01 Part 1, B01 Part 2,
B02, B08 and B10 re-recorded in English and Hindi on the tutorial stack with the D-394 screens (Prospects chip,
Clients Show row, Settings section), each checked (length = subtitles, volume English ≈ −20 dB / Hindi ≈ −17 dB,
frames, flicker scan) and sent to the owner as replacements on 20 Nov 2026. A01 Part 2 was recorded once more
for D-395 below (that recording is the current one). The table below is the note as first written.

No video or script was changed and nothing was recorded (CLAUDE.md video rule: ask the owner first). Checked
against every script in `tutorials/scripts/` (and `scripts/parts/`). No recorded video opens + Add client, a
client page of a prospect, Deactivated Clients, a notification of a prospect, or Mark Lost, so those screens
are new, not changed, on camera.

| Video | What now differs on screen | Decision |
|---|---|---|
| A01 Part 1 (Orders, Standard and Kanban scenes) | The status filter row has a new chip **Prospects (n)** between Overdue and Docs Sent (0 on the demo data, so no Prospect badge or "No reply yet" line on any card). | Owner to decide when videos restart |
| B01 Part 1, B01 Part 2, B02, B08 (the Orders page) | The same new **Prospects (n)** chip in the filter row. | Owner to decide |
| A01 Part 2 (Clients) | The Clients list has a new row **Show: All (n) · Clients (n) · Prospects (n)** above the cards (no badge on the demo buyers). | Owner to decide |
| A01 Part 2, B10 (Settings › Company) | A new section **Quick-quote prospects (3)** in the list of setting sections (alphabetical, between "Orders" and "Quotation defaults"); the videos do not open it. | Owner to decide |
| B01 Part 1, D01, C03 / C05 (Orders › Quotation requests) | Not affected: a request shows "Matches prospect" and "Update this prospect" only when its e-mail is a prospect's; the demo requests are not, so the screen is unchanged. | No change needed |
| Documents (all videos) | Not affected: no document (PDF / Word) change. | No change needed |

## Also out of date after the 20 Nov 2026 build — D-395 CA / Accounting home — RE-RECORDED 20 Nov 2026

**Done (owner 20 Nov: "yes record them all and do not wait for me"):** the `/ca` scenes of A01 Part 2, B03, B04 and
B11 were rewritten for the new page (Needs attention boxes, the four cards with their new headings, the register
summary and quick filters; B11 does not name the boxes, since a CA without CA Manage sees fewer) with the Hindi
translations, then re-recorded in English and Hindi, checked (A01-2 10.07 / 13.13 min, B03 7.09 / 8.96, B04
6.06 / 7.56, B11 3.23 / 4.08 min English / Hindi; length = subtitles; volume ≈ −20 / −17 dB; frames show the new
page per role; flicker-scan hits are ordinary page changes) and sent to the owner as replacements on 20 Nov 2026.
The table below is the note as first written.

No video or script was changed and nothing was recorded (CLAUDE.md video rule: ask the owner first). Four videos
open `/ca`; each still plays and its narration is still true, but the picture shows the old page (a list of links)
instead of the new one (Needs attention boxes, four cards, register summary and filters). English and Hindi alike.

| Video | What it shows of `/ca` | Decision |
|---|---|---|
| A01 Part 2 (MD tour, "CA / Accounting") | The page heading only, for one sentence; the old link list is visible. | Owner to decide |
| B03 Accounts Executive | The INR Settlement Register (now under the boxes and cards, with a summary row and filters). | Owner to decide |
| B04 Finance Manager and Director | The page while expenses, bank, benefits, Zoho sync and year lock are named (now cards and boxes). | Owner to decide |
| B11 CA, Auditor and Investor | The CA's view of the page, naming its sections (now cards; the CA sees no Zoho box or card, as before). | Owner to decide |

## After the 20 Nov 2026 builds D-396 / D-397 (noted)

D-396 (Dimensions follow the sizes): only the New Order form's Dimensions hint is longer ("prints on the documents
— follows the three sizes; your own wording is kept"); B01 Part 1 shows it, says nothing about it, and still plays
correctly (it types the Dimensions text, which stays as typed). Recommended: no re-record; owner not yet answered.
D-397 (price note below the minimum): no video shows the note below the minimum; A01 Part 2, B02, B09 Parts 1–2
describe the minimum-price rule, which is unchanged. No video affected.

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

## Recorder notes (7 Nov 2026, D03–D05)

- Shared buyer moves are in `scripts/_buyer.js` (`ocSent`, `ocAcknowledged`, `shipped`, `balanceCleared`, `docsSent`, `portalPassword`, `signIn`); the setup now also creates the Procurement, Logistics and Compliance demo logins the production moves need.
- New narration must be synthesised before recording (`node tutorials/run.js D04 --plan`): the first D04 take synthesised speech while recording, the browser video lost about 48 s of frames, and the picture ran ahead of the voice. Compare the MP4 length with the last subtitle time after every take.
- Re-entering the same actor (`as: 'buyer'` twice in a row) resets the cookies to the ones from setup; after an on-camera sign-in, do not repeat `as` for that actor.
- Seen in the app while recording (not changed, reported to the owner): the portal's Order Confirmation acknowledgement table shows "—" for Port of Discharge when the order has only a typed port (port_of_discharge_text); Reorder This pre-selects PCS when an order line's unit does not match the unit list exactly (e.g. "sqm" vs "SQM"; D04 demo lines use "SQM"); the Buyer PO upload form still shows on a cancelled order whose advance was never cleared.

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

## Recorder notes (8 Nov 2026, B01)

- B01 set-up (`scripts/_b01.js`) gives the demo Export Executive a Sales & Export membership (as on the live site; without one, My Tasks lists every department's tasks), switches off the old dev-test catalogue products inside the snapshot and adds one priced product (`Black Galaxy Granite Slab`), so Pick from Products fills a quote price above the floor and the quotation passes the price check. Part 2 rebuilds Part 1's end state with the app's own services.
- Part 2 is 7.98 min (1 s under the 8-minute aim); accepted.
- Seen in the app while recording (not changed, reported to the owner): (1) Order Communication labels a staff post "not emailed (no client email on file)" even when the client has an e-mail and only e-mail sending is off; (2) the Dashboard's Recent Activity for an Export Executive lists the latest audit entries of the whole app (other people's logins, stage passes on orders they are not assigned to); (3) on New Order, a picked product's specification options (grade, thickness, finish …) render as a tall column that pushes "Pick from Products" below them; (4) a staff login with a role but no department membership sees every department's tasks in My Tasks.


## Group E re-recorded 21 Nov 2026 (D-400 wording/layout, D-399 role, D-401 freight line)

**Done (owner 20 Nov: "Re-record all 6"):** E01, E02 and E03 in English and Hindi, recorded on the 21 Nov app (stage
strip spaced out, "Generate the GST tax invoice", "Settings › Divisions" in messages, `{DIV}` number format, E03 signed
in as the Merchant Trade Executive). Checked (length against subtitles, loudness, frames, flicker scan — hits are
ordinary page changes) and sent to the owner on 21 Nov 2026, English and Hindi under one heading each.

## After the 21 Nov 2026 builds D-402 / D-403 / D-404 (noted, no re-record)
Calculated marine insurance on CIF orders (Edit / New order freight & insurance box), the insurance cover wording and the "Estimated total CIF / CFR value" line on the QT / PI. Checked against every recorded video: none shows the CIF freight & insurance box being filled or the inside of a CIF QT / PI (D01 shows only the e-mail cards with the attachment name). No video is affected, so nothing is re-recorded.

## B01 Part 2 re-recorded 22 Nov 2026 (QA Round 10, R9-N01) — owner: "yes rerecord B01 part 2"
After the R9-N01 fix the Export Executive no longer sees the rupees the bank credited or the rate (receipts table and the
"Received …" line in Step 5d/5e and the Payment Overview). B01 Part 2 re-recorded on the 22 Nov app (tutorial stack synced,
clean tutorial database with migrations 2026-11-21a and 2026-11-22a): English 8.24 min, Hindi 10.54 min; checked and sent
to the owner as replacements. D-402…D-407 (CIF insurance, Debit Note wording, QT/PI "To Be Confirmed") affect no recorded
video — none shows the CIF freight/insurance box or the inside of a Debit Note / CIF Quotation / PI.

## Out of date after the 23 Nov 2026 build — D-409 — OWNER TO DECIDE

Checked against every script in `tutorials/scripts/` (and `scripts/parts/`). Nothing re-recorded (ask the owner first).

| Video | What now differs on screen | Decision |
|---|---|---|
| A01 Part 2 (Clients scene), English and Hindi | Each client card's footer shows **+ New Order** next to Edit. | Owner to decide |
| All videos (Quotation / Proforma Invoice) | Not affected: no video shows the inside of a CIF / CFR QT or PI, where the LC sentence is now printed only on LC orders. | No change needed |
| C05, C13 (associate My clients) | Not affected: the associate portal's client list is a different page. | No change needed |

## Out of date after the 26 Nov 2026 build — D-431 Brands — DECIDED 26 Nov 2026

Checked against every script in `tutorials/scripts/` (and `scripts/parts/`). Nothing re-recorded (ask the owner first).
The tutorial database has no brand, so the brand name, header line, footer and Brand column do not appear in any video.
What every associate now sees is the tip line "When you talk to buyers, introduce NexaCrest International. Prices and
documents always come from NexaCrest. Never tell a buyer about your commission." at the top of **Home** and **My clients**.
Buyer links now open at plain `/quotation-details` (the recordings show no address bar, so nothing visible changes).

| Video | What now differs on screen | Decision |
|---|---|---|
| H01 Become a NexaCrest associate | My clients scenes show the tip line. | Being re-recorded anyway (owner OK 1–4, 26 Nov) |
| C01 Associate welcome (EN + HI) | Home scenes (Home, menu bar, back to you, log out) show the tip line above the totals. | Re-record, English only (owner 26 Nov 2026: "will go with your recommendation"; D-419) |
| C03 Starter level and first client (EN + HI; parts C03 profile, C04 starter tour, C05 inviting clients) | Home and My clients scenes show the tip line. | Re-record, English only (owner 26 Nov 2026: "will go with your recommendation"; D-419) |
| C04 Prices, requests and levels (EN + HI; part C08 access levels) | Home scenes ("A Starter menu", "Catalogue appears") show the tip line. | Re-record, English only (owner 26 Nov 2026: "will go with your recommendation"; D-419) |
| C06 Messages, changes, privacy, FAQ (EN + HI; parts C12–C15) | Home and My clients scenes show the tip line. | Re-record, English only (owner 26 Nov 2026: "will go with your recommendation"; D-419) |
| F01 Associate life-cycle 1 (EN + HI) | The first scene (associate Home) shows the tip line. | Re-record, English only (owner 26 Nov 2026: "will go with your recommendation"; D-419) |
| A01 Part 1 (EN + HI) | The "What an associate sees" scene (associate Home) shows the tip line. | Keep as is (owner 26 Nov 2026: "will go with your recommendation") |
| A02 Part 1 (EN + HI) | The first scene (associate My clients) shows the tip line. | Keep as is (owner 26 Nov 2026: "will go with your recommendation") |
| Staff associate page (C01, C03, C04, C05, C06 staff scenes; F02, F04) | The Profile and agreement table starts with a new row "Brand: NexaCrest (no brand)" (the "Change brand" link only for the MD / Admin). Small, near the top of the page. | Keep as is (owner 26 Nov 2026: "will go with your recommendation") |
| All other staff and buyer videos | Not affected: Admin › Brands is new (no video shows it); the Brand column on the Associates list / board and the Brand filters appear only once a brand exists. | No change needed |
