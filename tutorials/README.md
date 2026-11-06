# Tutorial videos

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

### One-time set-up

* `pip install piper-tts` and the voice files `en_US-lessac-medium.onnx` + `.onnx.json`
  (from the Hugging Face `rhasspy/piper-voices` repository) in `$TUT_WORK/voices/`.
* `ffmpeg` / `ffprobe` with libass and libx264.
* The PHP dev server running (`php -S 127.0.0.1:8080 _dev_router.php` in `PHP_MySQL_Version/public_html`),
  with the dev seed staff accounts (see `e2e/README.md`).
* Recording writes demo records to the dev database. Do not record while a test run uses the
  same database (in the cloud session: `flock <scratchpad>/test4.lock node tutorials/run.js C01`).

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
  company names start with `TEST`, phone `+91-0000000000`.
* Say what the screen shows; never claim behaviour you have not seen in the app.
* 8–12 minutes per video, 15 at most. Check with `--plan`, then watch the result.
* Update `VIDEO_PROGRESS.md` when a video is recorded and checked.
