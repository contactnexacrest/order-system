'use strict';
// Other-language versions of the tutorial videos (owner, 19 Nov 2026: "I need all above videos in Hindi").
// TUT_LANG=hi records the same scripts with every spoken and shown text taken from
// tutorials/i18n/hi/<video file base>.json ({ "meta": {...}, "strings": { "<English>": "<Hindi>" } }).
// The app's own screens stay in English; e-mail cards show the app's real English e-mail (narration translated).
// A missing translation stops the recording: a video is never half English.
const fs = require('fs');
const path = require('path');

const LANG = process.env.TUT_LANG || 'en';
const DIR = path.join(__dirname, '..', 'i18n');

// Fixed words on the cards (cards.js).
const UI = {
  hi: {
    video: 'वीडियो', about: (m) => `लगभग ${m} मिनट`, part: 'भाग', recap: 'सारांश', next: 'अगला वीडियो →',
    inbox: 'खरीदार के इनबॉक्स में', foot: 'NexaCrest International · ट्यूटोरियल · केवल डेमो डेटा', suffix: 'Hindi',
  },
};

const ui = (key, ...a) => {
  const t = (UI[LANG] || {})[key];
  return typeof t === 'function' ? t(...a) : t;
};

// Text fields of a step that are spoken or shown; e-mail cards keep the app's real English wording.
const FIELDS = ['say', 'caption', 'kicker', 'title', 'subtitle', 'next'];

function stepStrings(step) {
  const out = [];
  for (const f of FIELDS) if (typeof step[f] === 'string' && step[f].trim()) out.push(step[f]);
  if (Array.isArray(step.items)) for (const it of step.items) if (typeof it === 'string' && it.trim()) out.push(it);
  return out;
}

const fileBase = (script) => script.file.replace(/\.mp4$/, '');

function tableFor(script) {
  const f = path.join(DIR, LANG, `${fileBase(script)}.json`);
  if (!fs.existsSync(f)) throw new Error(`No ${LANG} translation file: ${f}`);
  return JSON.parse(fs.readFileSync(f, 'utf8'));
}

// The script as recorded in LANG: same actions, translated texts, its own file name.
function localise(script) {
  if (LANG === 'en') return script;
  const table = tableFor(script);
  const tr = (s) => {
    const t = table.strings[s];
    if (typeof t !== 'string' || !t.trim()) throw new Error(`${script.code}: no ${LANG} text for "${String(s).slice(0, 80)}"`);
    return t;
  };
  const step = (s) => {
    const c = { ...s };
    // E-mail cards show the app's real English e-mail, but the narration over them is spoken in LANG.
    if (s.card === 'email') {
      for (const f of ['say', 'caption']) if (typeof c[f] === 'string' && c[f].trim()) c[f] = tr(c[f]);
      return c;
    }
    for (const f of FIELDS) if (typeof c[f] === 'string' && c[f].trim()) c[f] = tr(c[f]);
    if (Array.isArray(c.items)) c.items = c.items.map((it) => (typeof it === 'string' && it.trim() ? tr(it) : it));
    return c;
  };
  return {
    ...script,
    group: tr(script.group),
    title: tr(script.title),
    subtitle: tr(script.subtitle),
    minutes: (table.meta && table.meta.minutes) || script.minutes,
    file: `${fileBase(script)}-${ui('suffix')}.mp4`,
    rawKey: `${script.code}-${LANG}`,
    steps: (ctx) => script.steps(ctx).map(step),
  };
}

module.exports = { LANG, UI, ui, FIELDS, stepStrings, fileBase, localise };
