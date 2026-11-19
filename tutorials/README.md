# Tutorial videos

> **Recording restarted — owner, 18 Nov 2026:** videos for every role plus one general video (A01, two parts), each
> 10–15 minutes at most, same voice. Re-recording a video after a later app change still needs the owner's go-ahead
> (CLAUDE.md). Recording now uses a separate clean tutorial database — see "Tutorial database" below.

Narrated, subtitled 1080p tutorial videos of the application, recorded automatically
with Playwright against the **PHP stack** on the dev database, with an offline voice (Piper).
The videos are **not committed** (see `.gitignore`); the scripts and `VIDEO_PROGRESS.md` are,
so any session can re-record or continue the series.

## Run

```bash
node tutorials/run.js C01                # record one video
```

```bash
node tutorials/run.js C01 C02 C03        # several, in order
```

```bash
node tutorials/run.js C --plan           # no recording: synthesize narration, print estimated lengths
```

The finished MP4 (plus an `.srt` subtitle file) goes to `$TUT_OUT`.

### Settings (environment variables)

| Variable | Default | Meaning |
|---|---|---|
| `TUT_BASE_URL` | `http://127.0.0.1:8080` | PHP dev server |
| `TUT_WORK` | `tutorials/out` | cache (narration clips), raw recordings, DB snapshot |
| `TUT_OUT` | `$TUT_WORK/videos` | finished videos |
| `TUT_VOICE` | `$TUT_WORK/voices/en_US-lessac-medium.onnx` | Piper voice (owner choice: US English) |
| `TUT_CHROMIUM` | `/opt/pw-browsers/chromium` | browser |
| `TUT_DB_USER` / `TUT_DB_PASSWORD` / `TUT_DB_NAME` | dev values | dev database (never the live one) |
| `TUT_STAFF_PASSWORD` | e2e staff password | password of the md@email.com / ed@email.com dev accounts |
| `TUT_LENGTH_SCALE` | `1.1` | voice speed (higher = slower; 1.1 is a calm, clear pace) |
| `TUT_LANG` | `en` | `hi` records the Hindi version: texts from `tutorials/i18n/hi/<video>.json`, file name ends `-Hindi.mp4` (use with `TUT_VOICE=…/hi_IN-priyamvada-medium.onnx`) |

### Hindi versions (since 19 Nov 2026)

`TUT_LANG=hi TUT_VOICE=<path>/hi_IN-priyamvada-medium.onnx node tutorials/run.js C01` (voice + `.onnx.json` from the
Hugging Face `rhasspy/piper-voices` repository, like the English one) records the same
script in Hindi. Every spoken and shown text is looked up in `tutorials/i18n/hi/<video file base>.json`; a missing
text stops the recording (a video is never half English). After a script change run
`node tutorials/i18n-extract.js hi <code>` (adds the new English texts, keeps the old translations), translate the
empty values following `tutorials/i18n/hi/README.md`, and check with `node tutorials/i18n-check.js hi`. Hindi speech
runs longer: use `--plan` for the estimate, and split a take over 15 minutes at a part title card.

### One-time set-up

* `pip install piper-tts` and the voice files `en_US-lessac-medium.onnx` + `.onnx.json`
  (from the Hugging Face `rhasspy/piper-voices` repository) in `$TUT_WORK/voices/`.
* `ffmpeg` / `ffprobe` with libass and libx264.
* The PHP dev server running (`php -S 127.0.0.1:8080 _dev_router.php` in `PHP_MySQL_Version/public_html`),
  with the dev seed staff accounts (see `e2e/README.md`).
* Recording writes demo records to the dev database. Do not record while a test run uses the
  same database (in the cloud session: `flock <scratchpad>/test4.lock node tutorials/run.js C01`).
* The dev database must be built from the current `schema.sql` + `seed.sql` (or have had every migration up to
  `2026-11-13b-products-fixed-base-and-link-legacy.sql`, removed 14 Nov 2026 by D-372 and kept in git history; the 12 Nov ones add "As per annexure", the detailed line table, the price lock,
  "Natural Polish" and the e-mail Testing mode default; 13a / 13b add supplier offers and configured base prices).
  The scripts expect the app of 13 Nov 2026: a product is offered in "Pick from Products" only when it is
  configured and has an active supplier (the set-ups `_b01.js` / `_b02.js` create one, linked to the demo
  supplier "TEST Demo Quarry"); Thickness and Size / format start on "Choose…" and must be chosen before a price
  shows (D-348); the finish of a picked line is its Surface finish option ("Natural Polish", D-357).
* The first recording after a long pause should be checked step by step: the scripts were updated from the
  views and controllers only (no recording was allowed), so a selector may still need a small fix. A failing
  step names itself (`Step 7 (…): …`).

## Tutorial database (since 18 Nov 2026)

The dev database holds thousands of automated-test records (and must never show on camera), so recordings run against
a clean database, `nexacrest_tutorial`, built from `docs/schema.sql` + `docs/seed.sql`, served by a copy of the PHP app:

```bash
mysql -uroot -e "CREATE DATABASE nexacrest_tutorial CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
mysql -uroot nexacrest_tutorial < PHP_MySQL_Version/docs/schema.sql && mysql -uroot nexacrest_tutorial < PHP_MySQL_Version/docs/seed.sql
```

* Give `md@email.com` and `ed@email.com` the e2e staff password (`TUT_STAFF_PASSWORD`), `force_password_change = 0`.
* Copy `PHP_MySQL_Version/app` (without `vendor` and `tests`; link `vendor`) and `public_html` to a folder of its own; in
  its `.env` set `DB_DATABASE=nexacrest_tutorial` and its own `STORAGE_BASE_PATH`.
* Serve it on port 8081: `php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:8081 _dev_router.php`.
* Record with `TUT_BASE_URL=http://127.0.0.1:8081 TUT_DB_NAME=nexacrest_tutorial TUT_PHP_APP=<copy>/app`.

The snapshot taken before each recording leaves out the `documents` table (generated files); `restore()` deletes only the
documents the recording added.

## How it works

* `lib/director.js` — opens one 1920×1080 recording (viewport 1280×720 at device scale 1.5, so text
  stays readable), plays the steps, keeps the start time of every narration clip.
* A **step** is `{ say, caption, do }`: narration starts, the caption pill shows, `do(d)` runs at human
  speed (smooth cursor, highlight ring, typing), and the step ends when both the action and the voice
  are done, plus a short pause. Special steps: `{ card: 'title' | 'chapter' | 'list' }`, `{ as: 'md' }`
  (switch the logged-in person: badge top right + their cookies), `{ goto: '/path' }`.
* `lib/overlay.js` — actor badge, caption pill, highlight ring, cursor and click ripple; hides the
  dev banner and "Development only" helpers (login codes, links) — the script reads them invisibly,
  the narration says they arrive by e-mail, as on the live site.
* `lib/merge.js` — joins several short part scripts (`scripts/parts/`) into one 8–12 minute video:
  each part keeps its own demo data and actors, opens with a "Part n of m" title card and numbers its
  chapters from 1. A top-level script is then just `merge({ code, file, title, … }, [part1, part2])`.
* `lib/cards.js` — title, chapter and recap cards in the brand colours (Poppins, navy and gold).
* `lib/tts.js` — Piper narration, cached by text; abbreviations are spoken letter by letter
  (TDS, GST…) while the subtitles keep the normal spelling.
* `lib/render.js` — places every clip at its start time in one audio track, burns subtitles (ASS),
  encodes H.264/AAC with faststart, and **fails if a video is over 15 minutes** (split it into parts).
* `lib/demo.js` — demo data: associates with role e-mails (`associate.*@email.com`), off-camera
  logins, sample PDFs that say SAMPLE, a priced demo product, buyers with orders and payouts
  (`lib/demo-orders.js`). Scripts with `restoreDb: true` snapshot the whole dev DB first and put it
  back afterwards; others delete their demo associates.

## Writing a script

Copy a script in `scripts/` (files starting with `_` are helpers). Rules:

* No PII: role names and role e-mails only (`md@email.com`, `associate.starter@email.com`),
  company names start with `TEST` (or `[SAMPLE]` for the app's own Sample Data), buyer e-mails on
  `.example` domains, phone `+91-0000000000`.
* Use the app's current names: the left menu has 13 entries with a menu bar per section (D-320), so say
  "Approvals › Documents" (not My Reviews), "Users & roles › …", "Settings › …"; buyer-facing wording follows
  D-330 ("Open order", "Please confirm your order", "Did not go ahead" …). E-mail cards copy the seeded
  e-mail templates (`docs/seed.sql`, `email_templates`); their subjects name our company, not the buyer.
* Say what the screen shows; never claim behaviour you have not seen in the app.
* 8–12 minutes per video, 15 at most. Check with `--plan`, then watch the result.
* Update `VIDEO_PROGRESS.md` when a video is recorded and checked.
