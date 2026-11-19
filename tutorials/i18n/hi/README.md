# Hindi versions of the tutorial videos — translation guide

Owner request (19 Nov 2026): every tutorial video also in Hindi. One JSON file per video, made by
`node tutorials/i18n-extract.js hi`: `"strings": { "<English text>": "<Hindi text>" }`. Fill only the
values; never change a key. Record with `TUT_LANG=hi TUT_VOICE=…/hi_IN-priyamvada-medium.onnx`.
`node tutorials/i18n-check.js hi` must report no problem before recording.

The app's screens stay in English (the app is English). The Hindi version explains them in Hindi.

## Who listens
Indian office staff, buyers' contacts, and associates. Simple, polite, everyday spoken Hindi with
**आप**, the way people talk at work in India. English business words that everyone uses in
Indian offices stay English (written as below): invoice, order, payment, buyer, supplier, approval,
dashboard, upload, login, password, commission, report. Do not invent pure-Hindi words for them.

## Narration (texts that are spoken — the long sentences, usually starting "This video…",
"Open…", "Here…", and every recap sentence)
- **Devanagari only. No Latin letters at all** (the Hindi voice reads Latin letters badly).
  English words are written as they sound: प्रोफ़ॉर्मा इनवॉइस, डैशबोर्ड, अप्रूवल्स, ऑर्डर पेज.
- Button, menu and page names in quotes become the Devanagari sound of the English name, so the
  listener recognises the button on screen: "Mark as Lost" → "मार्क ऐज़ लॉस्ट".
- Short forms letter by letter: GST → जी एस टी, PDF → पी डी एफ, MD → एम डी, ED → ई डी, PI → पी आई,
  QT → क्यू टी, OC → ओ सी, CI → सी आई, BL → बी एल, FOB → एफ़ ओ बी, CIF → सी आई एफ़, TDS → टी डी एस,
  UTR → यू टी आर, KYC → के वाई सी, CAFIN → कैफ़िन, CHA → सी एच ए, HS → एच एस, LC → एल सी, PO → पी ओ.
- NexaCrest → नेक्सा-क्रेस्ट. Numbers as digits are fine (6,480 is read correctly); keep ₹ amounts as "₹ 1,250" → "1,250 रुपये".
- End sentences with "।" (or "?"). Same meaning and same order as the English; add nothing, leave
  nothing out.
- **Length: not longer than the English when spoken.** Several videos are already close to the
  15-minute limit. Prefer short sentences; drop filler words.

## Shown texts (captions, card titles, list items, kickers, "next" lines, group, title, subtitle)
- Hindi in Devanagari, but **screen names stay exactly as on screen, in English letters**:
  menu paths, page titles, button and field names, status names, document codes, video codes.
  `Approvals › Prices` stays `Approvals › Prices`; "Orders: commission per order" →
  "Orders: हर ऑर्डर का कमीशन". A caption that is only a screen name is copied unchanged.
- Keep list items short (they are shown on a card).

## Never
- No real names, e-mails or phone numbers (role names only; no PII).
- Do not translate e-mail addresses, URLs, codes like TEST-TUT-0901, or amounts.
