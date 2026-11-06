# Tutorial videos — progress and resume notes

Owner plan (6 Nov 2026): one series of narrated videos per group, in the order
**C → A → D → B → E**. Each video 8–12 minutes, never over 15 (split into parts instead).
US English voice (Piper `en_US-lessac-medium`). Interesting, not boring. No PII: role names,
role e-mails and demo / `TEST` data only. Videos are **not committed**; this file and the
scripts are, so a new session can continue.

Status values: `script` (written, not recorded) · `recorded` (MP4 made, not yet checked) ·
`checked` (watched / frames reviewed, ready to send) · `sent` (sent to the owner).

## Group C — Associates

| No. | File name | Covers | Script | Status | Date | Notes |
|---|---|---|---|---|---|---|
| C01 | `C01-Associate-Welcome.mp4` | What an associate does; login with password + 6-digit code; Home; every tab; first message and the MD's reply; access levels; golden rules; log out | `scripts/C01-Associate-Welcome.js` | checked | 2026-11-06 | Pilot: 7.6 min, 50 MB, 1080p, audio −20 dB mean; frames reviewed |
| C02 | `C02-Associate-Onboarding.mp4` | Associate registration link (MD) → form with browser checks → MD accepts → set password → first login (Invited); NexaCrest adds an associate directly | `scripts/C02-Associate-Onboarding.js` | script | | Actors: MD, future associate |
| C03 | `C03-Associate-Profile-Bank-KYC.mp4` | Profile, PAN/GSTIN/address, KYC uploads (Aadhaar last 4), bank typed twice + proof; MD accepts documents, bank first check (MD) + verify (ED), status Active | `scripts/C03-Associate-Profile-And-Bank.js` | script | | Actors: associate, MD, ED |
| C04 | `C04-Associate-Starter-Tour.mp4` | Starter level page by page: clients, protection, 10-day window, certificate, orders without FOB, order page, commission states, invoice upload, TDS certificates, locked page, way to Quoting | `scripts/C04-Associate-Starter-Tour.js` | script | | Demo buyer + order |
| C05 | `C05-Associate-Inviting-Clients.mp4` | Buyer registration link; personal code, check letter, change code; buyer registers + verifies e-mail; MD accepts on Quotation intake; client list, certificate; protection rules | `scripts/C05-Associate-Inviting-Clients.js` | script | | Actors: associate, buyer, MD |
| C06 | `C06-Associate-Get-A-Price.mp4` | Price access (MD); catalogue without prices; Get price; quote + lowest price; what changed the price; validity; request list; staff 90-day report; golden rules | `scripts/C06-Associate-Get-Price.js` | script | | Demo priced product |
| C07 | `C07-Associate-Price-Request-Other.mp4` | "Other" request, required note (browser check), with our team in 24 h; MD prices it by hand; associate sees the price | `scripts/C07-Associate-Price-Request-Other.js` | script | | Actors: associate, MD |
| C08 | `C08-Associate-Access-Levels-And-Upgrade.mp4` | Starter / Quoting / Full / Custom; level settings grid; upgrade suggestion; MD moves the associate up with a reason; what appears; Custom | `scripts/C08-Associate-Access-Levels.js` | script | | Actors: associate, MD |
| C09 | `C09-Associate-Commission-Life-Cycle.mp4` | Expected → earned → due (30 days) → paid (monthly run); basis = invoiced FOB of goods; below-floor lines; order statement; commission page; period shown (setting); invoice upload; hold / release / claw-back | `scripts/C09-Associate-Commission-Life-Cycle.js` | script | | Demo orders + payouts in 3 states |
| C10 | `C10-Associate-Statements-CSV-Print.mp4` | Statement (CSV) and printable statement; what is inside; levels | `scripts/C10-Associate-Statements.js` | script | | |
| C11 | `C11-Associate-TDS-And-GST.mp4` | India 194H: ₹20,000 threshold, 2 %, catch-up (worked example), Form 16A; no PAN 20 % (206AA); GST 18 % on top when registered; abroad: section 195, DTAA, TRC + Form 10F + No-PE, Form 15CA/15CB | `scripts/C11-Associate-TDS-And-GST.js` | script | | Two associates: India and abroad. Figures follow Settings → Associate TDS; re-check before recording |
| C12 | `C12-Associate-Messages-And-Notices.mp4` | Notices, "(n new)", bold unread, e-mail copies, amounts by level; message → MD reply → notice | `scripts/C12-Associate-Messages-And-Notices.js` | script | | Actors: associate, MD |
| C13 | `C13-Associate-Client-Moved-Suspended-Code.mp4` | Client moved by the MD (reason shown); leaked code blocked + new code; Suspended banner; Ended — login refused | `scripts/C13-Associate-When-Things-Change.js` | script | | Actors: associate, MD |
| C14 | `C14-Associate-What-You-Never-See.mp4` | No costs / suppliers / margins / formulas; staff pages closed; only own orders; associate privacy (masked bank, Aadhaar last 4, two-person bank check) | `scripts/C14-Associate-What-You-Never-See.js` | script | | |
| C15 | `C15-Associate-Questions-And-Answers.mp4` | 11 frequent questions with answers; series overview | `scripts/C15-Associate-FAQ.js` | script | | |

## Group A, D, B, E

Titles follow the owner-approved work plan of 6 Nov 2026 (held in the session that set it up).
Add one row per video here before writing its script, with the same naming pattern:
`<Group><No.>-<Audience>-<Topic-In-Words>.mp4`, e.g. `A01-…`, `D01-…`, `B01-…`, `E01-…`.

| Group | Audience | Videos | Status |
|---|---|---|---|
| A | (from the work plan) | to be listed | not started |
| D | (from the work plan) | to be listed | not started |
| B | (from the work plan) | to be listed | not started |
| E | (from the work plan) | to be listed | not started |

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
6. Scripts C02–C15 were written from the views and controllers but not yet recorded: the first
   recording of each may need a selector fixed. A failing step names itself (`Step 7 (…): …`).
7. Demo data clean-up is automatic (`restoreDb` snapshot, or demo associates deleted). If a run is
   killed half-way, restore with `node -e "require('./tutorials/lib/demo').restore()"` (same TUT_WORK) — only if nothing else has written to the dev database since the snapshot.

## Length check (6 Nov 2026, `--plan` at the final voice pace; C01's estimate matched its real length)

C01 7.6 · C02 4.4 · C03 4.7 · C04 3.9 · C05 3.8 · C06 3.0 · C07 2.0 · C08 3.1 · C09 3.4 · C10 1.6 ·
C11 4.5 · C12 2.2 · C13 2.5 · C14 2.4 · C15 3.4 minutes (total about 53).
C02–C15 are under the 8–12 minute target. Option (recommended): join them into five videos of
8–11 minutes — C02+C03 (becoming an associate and getting active), C04+C05 (Starter tour and
inviting clients), C06+C07+C08 (prices, "Other", access levels), C09+C10+C11 (commission,
statements, TDS/GST), C12+C13+C14+C15 (messages, changes, privacy, Q&A). Joining is a matter of
concatenating the step lists in one script file, with one title and one recap.
