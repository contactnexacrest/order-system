#!/usr/bin/env node
'use strict';
// Writes tutorials/i18n/<lang>/<video>.json with every spoken or shown text of each video, keeping
// translations already there. Usage: node tutorials/i18n-extract.js hi [A01-1 B02 …]
const fs = require('fs');
const path = require('path');
const { stepStrings, fileBase } = require('./lib/i18n');

const lang = process.argv[2];
if (!lang) { console.error('Usage: node tutorials/i18n-extract.js <lang> [code …]'); process.exit(2); }
const only = process.argv.slice(3);
const SCRIPTS = path.join(__dirname, 'scripts');
const outDir = path.join(__dirname, 'i18n', lang);
fs.mkdirSync(outDir, { recursive: true });

for (const f of fs.readdirSync(SCRIPTS).filter((x) => x.endsWith('.js') && !x.startsWith('_')).sort()) {
  const s = require(path.join(SCRIPTS, f));
  if (only.length && !only.some((c) => f === `${c}.js` || f.startsWith(`${c}-`))) continue;
  const texts = [s.group, s.title, s.subtitle];
  for (const st of s.steps(s.planContext || {})) texts.push(...stepStrings(st));
  const file = path.join(outDir, `${fileBase(s)}.json`);
  const old = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : { meta: {}, strings: {} };
  const strings = {};
  for (const t of texts) if (t && !(t in strings)) strings[t] = old.strings[t] || '';
  fs.writeFileSync(file, `${JSON.stringify({ meta: old.meta || {}, strings }, null, 1)}\n`);
  const todo = Object.values(strings).filter((v) => !v).length;
  console.log(`${fileBase(s)}: ${Object.keys(strings).length} texts, ${todo} to translate`);
}
