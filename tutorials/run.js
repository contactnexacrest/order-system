#!/usr/bin/env node
'use strict';
// Records tutorial videos.
//   node tutorials/run.js C01            record one video
//   node tutorials/run.js C01 C02 C03    record several, in order
//   node tutorials/run.js C --plan       no recording: synthesize narration, print estimated lengths
//   node tutorials/run.js C01 --rerender rebuild the MP4 from the last raw recording (subtitle/audio changes)
// See tutorials/README.md for settings (TUT_WORK, TUT_OUT, TUT_VOICE, TUT_BASE_URL).
const fs = require('fs');
const path = require('path');
const cfg = require('./lib/config');
const tts = require('./lib/tts');
const demo = require('./lib/demo');
const { Director } = require('./lib/director');

const SCRIPTS = path.join(__dirname, 'scripts');

function find(ids) {
  const files = fs.readdirSync(SCRIPTS).filter((f) => f.endsWith('.js') && !f.startsWith('_')).sort();
  const picked = [];
  for (const id of ids) {
    if (id.includes('/')) { picked.push(id.replace(/\.js$/, '') + '.js'); continue; } // e.g. parts/C03-Associate-Profile-And-Bank (try one part alone)
    const m = files.filter((f) => f === `${id}.js` || f.startsWith(`${id}-`) || (id.length === 1 && f.startsWith(id)));
    if (!m.length) throw new Error(`No script for ${id}`);
    picked.push(...m);
  }
  return [...new Set(picked)].map((f) => require(path.join(SCRIPTS, f)));
}

// Rough length without recording: narration + pauses + typical action time.
function plan(script) {
  const steps = script.steps(script.planContext || {});
  let s = 0;
  for (const st of steps) {
    const say = st.say ? tts.synth(st.say).seconds : 0;
    const act = st.do ? (st.est || 2.5) : 0;
    s += (st.after ? say + act : Math.max(say, act)) + (st.pause != null ? st.pause : cfg.PAUSE_MS) / 1000 + (st.card ? 0.3 : 0) + (st.goto ? 0.8 : 0);
  }
  return s;
}

async function record(script) {
  const started = Date.now();
  // Videos that create buyers, orders or payouts put the whole dev database back afterwards.
  const snap = script.restoreDb ? demo.snapshot() : null;
  let ctx;
  try { ctx = script.setup ? await script.setup(demo) : {}; } catch (e) { if (snap) demo.restore(snap); throw e; }
  const d = new Director(script, { actors: ctx.actors || {} });
  let result;
  try {
    const steps = script.steps(ctx);
    await d.open();
    await d.run(steps);
  } finally {
    if (d.page) await d.close().catch((e) => console.error('close failed:', e.message));
    try { if (script.teardown) await script.teardown(demo, ctx); demo.cleanup(); } catch (e) { console.error('cleanup failed:', e.message); }
    if (snap) { try { demo.restore(snap); } catch (e) { console.error('restore failed:', e.message); } }
  }
  result = await d.render();
  const mb = (fs.statSync(result.out).size / 1e6).toFixed(1);
  console.log(`✔ ${script.code} → ${result.out} (${(result.seconds / 60).toFixed(2)} min, ${mb} MB, made in ${Math.round((Date.now() - started) / 1000)} s)`);
  return result;
}

(async () => {
  const args = process.argv.slice(2);
  const ids = args.filter((a) => !a.startsWith('--'));
  if (!ids.length) { console.error('Usage: node tutorials/run.js C01 [C02 …] [--plan]'); process.exit(2); }
  const scripts = find(ids);
  if (args.includes('--rerender')) {
    // Rebuild the MP4 from the last raw recording (e.g. after a subtitle or audio change).
    const { buildVideo } = require('./lib/render');
    for (const s of scripts) {
      const dir = path.join(cfg.WORK, 'raw', s.code);
      const { webm, clips } = JSON.parse(fs.readFileSync(path.join(dir, 'clips.json'), 'utf8'));
      const out = path.join(cfg.OUT, s.file);
      const secs = await buildVideo({ webm, clips, out, workDir: dir });
      console.log(`✔ ${s.code} re-rendered → ${out} (${(secs / 60).toFixed(2)} min)`);
    }
    return;
  }
  if (args.includes('--plan')) {
    let total = 0;
    for (const s of scripts) { const t = plan(s); total += t; console.log(`${s.code}  ~${(t / 60).toFixed(1)} min  ${s.file}`); }
    console.log(`Total ~${(total / 60).toFixed(0)} min`);
    return;
  }
  for (const s of scripts) await record(s);
})().catch((e) => { console.error(e.stack || e.message); process.exit(1); });
