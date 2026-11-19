#!/usr/bin/env node
'use strict';
// Checks translation files before recording: every text translated, spoken texts free of Latin
// letters, codes and amounts kept. Usage: node tutorials/i18n-check.js hi [file-prefix …]
const fs = require('fs');
const path = require('path');
const { FIELDS } = require('./lib/i18n');

const lang = process.argv[2];
const only = process.argv.slice(3);
const SCRIPTS = path.join(__dirname, 'scripts');
const dir = path.join(__dirname, 'i18n', lang);
let problems = 0;
const bad = (f, msg) => { problems++; console.log(`${f}: ${msg}`); };

for (const f of fs.readdirSync(SCRIPTS).filter((x) => x.endsWith('.js') && !x.startsWith('_')).sort()) {
  if (only.length && !only.some((c) => f.startsWith(c))) continue;
  const s = require(path.join(SCRIPTS, f));
  const base = s.file.replace(/\.mp4$/, '');
  const file = path.join(dir, `${base}.json`);
  if (!fs.existsSync(file)) { bad(base, 'no translation file'); continue; }
  let t;
  try { t = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { bad(base, `invalid JSON: ${e.message}`); continue; }
  const said = new Set();
  for (const st of s.steps(s.planContext || {})) if (st.card !== 'email' && typeof st.say === 'string') said.add(st.say);
  for (const [en, hi] of Object.entries(t.strings || {})) {
    if (typeof hi !== 'string' || !hi.trim()) { bad(base, `untranslated: "${en.slice(0, 70)}"`); continue; }
    if (said.has(en)) {
      if (/[A-Za-z]/.test(hi)) bad(base, `Latin letters in spoken text: "${hi.slice(0, 90)}"`);
    } else {
      for (const code of en.match(/\b(?:[A-Z]{2,}\d*|TEST-[\w-]+|[A-Z]\d{2}(?:-\d)?)\b/g) || []) {
        if (!hi.includes(code) && !/^(?:QT|PI|OC|CI|PO|BL|LC|MD|ED|CA|GST|TDS|FOB|CIF|CHA|HS|PDF|KYC|UTR|CAFIN|INR|USD|SOP|PAN|ID|RM|OK|FX|CSV|COO|DN|PL|FIRC|ECGC|IEC|LUT|SQM|CBM|SQFT|ESI|BRC|RCMC|RODTEP|IGST)$/.test(code)) {
          bad(base, `code "${code}" missing in shown text: "${hi.slice(0, 80)}"`);
        }
      }
    }
  }
}
console.log(problems ? `${problems} problem(s)` : 'all translations look complete');
process.exit(problems ? 1 : 0);
