'use strict';
// The Director records one tutorial: one browser page, narrated steps, cards,
// overlays and actor switches, then builds the narrated, subtitled MP4.
//
// A script is a list of steps. Each step is { say, caption?, do?, after?, pause? }:
//   1. the narration (synthesized before recording starts) begins,
//   2. the caption pill shows, 3. `do(d)` runs at human speed while the voice plays,
//   4. the step ends when both the action and the narration are done, plus a short pause.
// Special steps: { card: 'title'|'chapter'|'list', ...card fields, say? } and
// { as: 'actorKey' } to switch the logged-in actor (badge + cookies).
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const cfg = require('./config');
const tts = require('./tts');
const cards = require('./cards');
const { initScript } = require('./overlay');
const { buildVideo } = require('./render');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

class Director {
  constructor(script, { actors = {} } = {}) {
    this.script = script;
    this.actors = actors;           // key -> { label, sub, initials, color, cookies }
    this.clips = [];                // { start, seconds, file, text }
    this.mouse = { x: 960, y: 540 };
    this.chapter = 0;
  }

  // ---------- life cycle ----------
  async open() {
    const raw = path.join(cfg.WORK, 'raw', this.script.code);
    fs.rmSync(raw, { recursive: true, force: true });
    fs.mkdirSync(raw, { recursive: true });
    this.rawDir = raw;
    this.browser = await chromium.launch({ executablePath: fs.existsSync(cfg.CHROMIUM) ? cfg.CHROMIUM : undefined });
    this.context = await this.browser.newContext({
      viewport: { width: cfg.VIEW.width, height: cfg.VIEW.height },
      recordVideo: { dir: raw, size: { width: cfg.VIDEO.width, height: cfg.VIDEO.height } },
      baseURL: cfg.BASE_URL,
      locale: 'en-IN',
      timezoneId: 'Asia/Kolkata',
    });
    await this.context.addInitScript(initScript(cfg.BRAND, cfg.VIEW.zoom));
    this.page = await this.context.newPage();
    if (process.env.TUT_DEBUG) this.page.on('response', (r) => { if (r.request().resourceType() === 'document') console.log('[nav]', r.request().method(), r.status(), r.url()); });
    // "Are you sure?" browser dialogs (data-confirm) are accepted; the narration says so.
    this.page.on('dialog', (dlg) => { this.lastDialog = dlg.message(); dlg.accept().catch(() => {}); });
    this.t0 = Date.now();
    this.page.setDefaultTimeout(20000);
  }

  now() { return (Date.now() - this.t0) / 1000; }

  async close() {
    const video = this.page.video();
    await this.page.close();
    await this.context.close();
    await this.browser.close();
    this.webm = await video.path();
    return this.webm;
  }

  // ---------- running steps ----------
  async run(steps) {
    // Synthesize every narration first so the recording never waits on TTS.
    for (const s of steps) if (s.say) s._tts = tts.synth(s.say);
    for (const [i, s] of steps.entries()) {
      try {
        await this.step(s);
      } catch (e) {
        try { const f = require('path').join(cfg.WORK, 'raw', `${this.script.code}-fail.png`); await this.page.screenshot({ path: f }); console.error(`screenshot of the failed step: ${f}`); } catch (_) { /* page gone */ }
        e.message = `Step ${i + 1} (${(s.say || s.card || s.as || '').toString().slice(0, 60)}): ${e.message}`;
        throw e;
      }
    }
  }

  async step(s) {
    // A card replaces the page: a following step with no navigation of its own returns to the page
    // that was showing before the card (same person), so it acts on the app, not on the card.
    const back = this.onCard && !s.card && !s.goto && (!s.as || s.as === this.actor)
      && !(s.do && /d\.goto\(/.test(s.do.toString())) && /^https?:/.test(this.beforeCard || "") ? this.beforeCard : null;
    if (s.as) await this.as(s.as);
    if (s.card) await this.card(s);
    else if (back) await this.goto(new URL(back).pathname + new URL(back).search + new URL(back).hash);
    if (s.goto) await this.goto(s.goto);
    if (!s.card && (s.goto || back || s.do)) this.onCard = false;
    const start = this.now();
    const ms = s._tts ? s._tts.seconds * 1000 : 0;
    if (s._tts) this.clips.push({ start, seconds: s._tts.seconds, file: s._tts.file, text: s.say });
    if (s.caption !== undefined) await this.caption(s.caption);
    const t = Date.now();
    if (s.do && !s.after) await s.do(this);
    const left = ms - (Date.now() - t);
    if (left > 0) await sleep(left);
    if (s.do && s.after) await s.do(this);
    await this.ring(null);
    await sleep(s.pause != null ? s.pause : cfg.PAUSE_MS);
  }

  // ---------- overlay helpers ----------
  async overlay(patch) { await this.page.evaluate((p) => window.__tut && window.__tut.set(p), patch).catch(() => {}); }
  async caption(text) { await this.overlay({ caption: text || '' }); }

  async as(key) {
    const a = this.actors[key];
    if (!a) throw new Error(`Unknown actor ${key}`);
    // Keep the session of the actor we leave, so switching back needs no new login.
    if (this.actor && this.actor !== key && this.actors[this.actor]) this.actors[this.actor].cookies = await this.context.cookies();
    this.actor = key;
    if (a.cookies) {
      await this.context.clearCookies();
      await this.context.addCookies(a.cookies);
    }
    await this.overlay({ actor: { label: a.label, sub: a.sub, initials: a.initials, color: a.color } });
  }

  async card(s) {
    if (s.resetChapter) this.chapter = 0;
    if (!this.onCard) this.beforeCard = this.page.url();
    this.onCard = true;
    const html = s.card === 'title' ? cards.titleCard({ ...this.script, ...s })
      : s.card === 'chapter' ? cards.chapterCard({ number: ++this.chapter, ...s })
        : cards.listCard(s);
    await this.page.setContent(html, { waitUntil: 'load' });
  }

  // ---------- human-speed actions ----------
  async goto(url) {
    // Same page with only a different #anchor would not reload (Playwright treats it as a hash
    // change), so after switching person the old person's page and form tokens would stay. Reload.
    const cur = new URL(this.page.url() === 'about:blank' ? 'http://x/' : this.page.url());
    const next = new URL(url, cur.origin === 'null' ? 'http://x' : cur.origin);
    await this.page.goto(url, { waitUntil: 'load' });
    if (/^https?:/.test(cur.href) && cur.pathname === next.pathname && cur.search === next.search) await this.page.reload({ waitUntil: 'load' });
    await this.restore();
  }

  // After a navigation, put the badge and cursor back where they were.
  async restore() {
    const a = this.actor && this.actors[this.actor];
    await this.overlay({ actor: a ? { label: a.label, sub: a.sub, initials: a.initials, color: a.color } : null, mx: this.mouse.x, my: this.mouse.y });
  }

  // Always the first match, so a selector that also matches a second row never stops a recording.
  loc(target) { return typeof target === 'string' ? this.page.locator(target).first() : target.first(); }

  async moveTo(target, { steps = 28 } = {}) {
    const l = this.loc(target);
    await l.scrollIntoViewIfNeeded();
    const b = await l.boundingBox();
    if (!b) throw new Error('element has no box');
    const x = b.x + Math.min(b.width / 2, 60 + b.width / 4);
    const y = b.y + b.height / 2;
    await this.glide(x, y, steps);
    return b;
  }

  async glide(x, y, steps = 28) {
    const { x: x0, y: y0 } = this.mouse;
    for (let i = 1; i <= steps; i++) {
      const k = i / steps; const e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2; // ease in-out
      await this.page.mouse.move(x0 + (x - x0) * e, y0 + (y - y0) * e);
      await sleep(12);
    }
    this.mouse = { x, y };
  }

  async ring(target) {
    if (!target) return this.page.evaluate(() => window.__tut && window.__tut.ring(null)).catch(() => {});
    const l = this.loc(target);
    await l.scrollIntoViewIfNeeded();
    const b = await l.boundingBox();
    if (b) await this.page.evaluate((r) => window.__tut && window.__tut.ring(r), b);
  }

  async point(target, ms = 900) { await this.ring(target); await this.moveTo(target); await sleep(ms); }

  async click(target, { nav = false, ring = true } = {}) {
    const l = this.loc(target);
    if (ring) await this.ring(l);
    await this.moveTo(l);
    await sleep(250);
    await this.ring(null);
    if (nav) {
      await Promise.all([this.page.waitForLoadState('load'), l.click()]);
      await this.page.waitForLoadState('load');
      await this.restore();
    } else {
      await l.click();
    }
    await sleep(300);
  }

  // Click something that loads a new page.
  async clickNav(target) {
    const l = this.loc(target);
    await this.ring(l);
    await this.moveTo(l);
    await sleep(250);
    await this.ring(null);
    await Promise.all([this.page.waitForNavigation({ waitUntil: 'load' }), l.click()]);
    await this.restore();
    await sleep(300);
  }

  async type(target, text, { delay = 55, clear = true } = {}) {
    const l = this.loc(target);
    await this.moveTo(l);
    await l.click();
    if (clear) await l.fill('');
    await l.pressSequentially(text, { delay });
    await sleep(200);
  }

  async select(target, value) {
    const l = this.loc(target);
    await this.ring(l);
    await this.moveTo(l);
    await sleep(300);
    await l.selectOption(value);
    await sleep(400);
    await this.ring(null);
  }

  async upload(target, file) {
    const l = this.loc(target);
    await this.ring(l);
    await this.moveTo(l);
    await sleep(300);
    await l.setInputFiles(file);
    await sleep(500);
    await this.ring(null);
  }

  async check(target) {
    const l = this.loc(target);
    await this.moveTo(l);
    await l.check();
    await sleep(300);
  }

  async scrollTo(target) {
    const l = this.loc(target);
    await l.evaluate((el) => el.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    await sleep(900);
  }

  async scrollBy(dy) {
    await this.page.evaluate((y) => window.scrollBy({ top: y, behavior: 'smooth' }), dy);
    await sleep(900);
  }

  async wait(ms) { await sleep(ms); }

  // A dev-only link/code the app showed (hidden on camera by the overlay), as a same-site path.
  async devLink() {
    const link = await this.page.evaluate(() => window.__tutDevLink || null);
    if (!link) throw new Error('No development link on this page');
    const u = new URL(link, cfg.BASE_URL);
    return u.pathname + u.search;
  }
  async devCode() { return this.page.evaluate(() => window.__tutDevCode || null); }

  // Read the text of something on the page (e.g. a link shown in a success message).
  async text(target) { return (await this.loc(target).innerText()).trim(); }

  // ---------- output ----------
  async render() {
    fs.mkdirSync(cfg.OUT, { recursive: true });
    const out = path.join(cfg.OUT, this.script.file);
    const seconds = await buildVideo({ webm: this.webm, clips: this.clips, out, workDir: this.rawDir });
    if (seconds > cfg.MAX_SECONDS) throw new Error(`${this.script.file} is ${Math.round(seconds)} s, over the 15-minute limit: split it into parts`);
    return { out, seconds };
  }
}

module.exports = { Director, sleep };
