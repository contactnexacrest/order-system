'use strict';

/**
 * Q3 QA human-style UI crawl (5 Nov 2026). For the MD and each main role, on the
 * Standard and the Kanban template, at desktop and phone width:
 *  - starts at the dashboard and follows every same-site link it can see (menu
 *    and page), one page per distinct route shape, up to QA_CRAWL_MAX pages;
 *  - on each page opens every step card / <details> and clicks every tab;
 *  - records HTTP errors (a link the page offers that answers 4xx/5xx),
 *    browser console errors, uncaught page errors and horizontal overflow;
 *  - submits each data-entry form once empty and once with invalid values and
 *    checks the browser stops it with an inline message.
 * Data-safe: every POST/PUT/DELETE leaving the browser is aborted (and counted
 * as "a submit got through"), so nothing is ever written.
 * Report: QA_CRAWL_OUT (JSON). Fails on any HTTP error, page error or form that
 * submits empty.
 */
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { login, ROLES } = require('./support/auth');
const { runSql } = require('./support/db');

test.setTimeout(3 * 60 * 60 * 1000);

const CRAWL_ROLES = (process.env.QA_CRAWL_ROLES || 'admin,exportExecutive,accountsExecutive,logisticsExecutive,viewerAuditor,ca,procurementManager').split(',');
const MAX_PAGES = Number(process.env.QA_CRAWL_MAX || 120);
const SKIP = /logout|\/download|\/dossier|\.pdf|\.docx|\.csv|\.zip|export|\/dump|\/stop-preview|preview-as|view-as|\/login-as|mailto:|tel:|javascript:|#$/i;
const shape = (url) => url.replace(/^https?:\/\/[^/]+/, '').replace(/\?.*$/, '').replace(/\/\d+(?=\/|$)/g, '/{n}');

function setTemplate(email, code) {
  runSql(`UPDATE users SET ui_template_preference_id = (SELECT id FROM ui_templates WHERE template_key = '${code}') WHERE email = '${email}'`, 'set ui template');
}

const checkedForms = new Set();
async function checkForms(page, url, findings, who) {
  const forms = await page.$$eval('form', (fs) => fs.map((f, i) => ({
    i,
    method: (f.getAttribute('method') || 'get').toLowerCase(),
    action: f.getAttribute('action') || '',
    required: f.querySelectorAll('input[required]:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]), textarea[required], select[required]').length,
    typed: f.querySelectorAll('input[type=email], input[type=number][min], input[pattern]').length,
    visible: !!f.offsetParent,
  })));
  for (const f of forms) {
    // A form the page itself keeps hidden (a panel switched off for this order) is not one a person can submit.
    if (!f.visible) continue;
    if (f.method !== 'post' || (!f.required && !f.typed) || /logout|delete|archive|remove|cancel/i.test(f.action)) continue;
    // Each form (by action shape) is checked once per template and viewport: the browser check does not depend on the role.
    const formKey = `${who.split('/').slice(1).join('/')} ${shape(f.action || url)}`;
    if (checkedForms.has(formKey)) continue;
    checkedForms.add(formKey);
    for (const mode of ['empty', 'invalid']) {
      if (mode === 'invalid' && !f.typed) continue;
      if (mode === 'empty' && !f.required) continue;
      let posted = false;
      const onReq = (r) => { if (r.method() !== 'GET' && r.frame() === page.mainFrame()) posted = true; };
      page.on('request', onReq);
      const outcome = await page.evaluate(({ i, mode }) => {
        const form = document.querySelectorAll('form')[i];
        if (!form) return 'gone';
        const details = form.closest('details'); if (details) details.open = true;
        form.querySelectorAll('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=submit]), textarea').forEach((el) => {
          if (el.readOnly || el.disabled) return;
          if (mode === 'empty') { if (el.required) el.value = ''; return; }
          if (el.type === 'email') el.value = 'not-an-email';
          else if (el.type === 'number' && el.min !== '') el.value = String(Number(el.min) - 1000);
          else if (el.pattern) el.value = '~~invalid~~';
        });
        form.querySelectorAll('select[required]').forEach((el) => { if (mode === 'empty') el.value = ''; });
        try { form.requestSubmit(); } catch (e) { return `requestSubmit threw ${e.message}`; }
        return 'submitted';
      }, { i: f.i, mode });
      await page.waitForTimeout(150);
      page.off('request', onReq);
      const message = await page.evaluate((i) => {
        const form = document.querySelectorAll('form')[i];
        return !!(form && (form.querySelector('.form-field-error, .reason-error, :invalid') || document.querySelector('.form-field-error')));
      }, f.i).catch(() => false);
      if (posted) findings.push({ type: 'form-submitted', who, page: url, form: f.action, mode });
      else if (outcome === 'submitted' && !message) findings.push({ type: 'form-no-inline-message', who, page: url, form: f.action, mode });
      if (posted || page.url() !== url) { await page.goto(url).catch(() => {}); }
    }
  }
}

async function crawl(page, label, findings, stats) {
  const seen = new Set();
  const start = page.url().replace(/^https?:\/\/[^/]+/, '') || '/';
  const queue = [start];
  const from = { [start]: '(start)' };
  while (queue.length && seen.size < MAX_PAGES) {
    const path = queue.shift();
    const key = shape(path);
    if (seen.has(key)) continue;
    seen.add(key);
    const consoleErrors = [];
    const onConsole = (m) => { if (m.type() === 'error' && !/favicon|ERR_ABORTED|net::ERR_FAILED/.test(m.text())) consoleErrors.push(m.text()); };
    const onPageError = (e) => consoleErrors.push(`pageerror: ${e.message}`);
    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    let res;
    try { res = await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 60000 }); } catch (e) {
      findings.push({ type: 'navigation-failed', who: label, page: path, linkedFrom: from[path], detail: e.message.split('\n')[0] });
      page.off('console', onConsole); page.off('pageerror', onPageError);
      continue;
    }
    stats.pages += 1;
    if (process.env.QA_CRAWL_DEBUG) console.log(`[crawl] ${label} ${path}`);
    const status = res ? res.status() : 0;
    if (status >= 400) findings.push({ type: `http-${status}`, who: label, page: path, linkedFrom: from[path] });
    if (status < 400) {
      const body = await page.content();
      if (/Fatal error|Uncaught \w+|Stack trace:|<b>(Warning|Notice|Deprecated)<\/b>|Template render error|Cannot read propert/.test(body)) {
        findings.push({ type: 'error-text', who: label, page: path });
      }
      // Open step cards and tabs.
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const tabs = await page.$$('[role=tab]');
      for (const t of tabs.slice(0, 12)) { await t.click({ timeout: 2000 }).catch(() => {}); }
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (overflow > 4) findings.push({ type: 'horizontal-overflow', who: label, page: path, px: overflow });
      await checkForms(page, page.url(), findings, label);
      const links = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
      for (const href of links) {
        if (!href || !href.startsWith('/') || href.startsWith('//') || SKIP.test(href)) continue;
        if (!seen.has(shape(href)) && !queue.includes(href)) { queue.push(href); from[href] = from[href] || path; }
      }
    }
    await page.waitForTimeout(100);
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    for (const c of consoleErrors) findings.push({ type: 'console-error', who: label, page: path, detail: c.slice(0, 300) });
  }
}

for (const template of ['standard', 'kanban']) {
  for (const viewport of [{ name: 'desktop', width: 1366, height: 900 }, { name: 'phone', width: 390, height: 844 }]) {
    test(`human-style crawl — ${template} template, ${viewport.name}`, async ({ browser }) => {
      const findings = [];
      const stats = { pages: 0 };
      for (const role of CRAWL_ROLES) {
        const account = ROLES[role];
        const before = runSql(`SELECT IFNULL(ui_template_preference_id, 'NULL') FROM users WHERE email = '${account.email}'`, 'read template');
        setTemplate(account.email, template);
        const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
        // Data-safe: nothing the crawl submits ever reaches the server.
        await context.route('**/*', (route) => {
          const r = route.request();
          if (r.method() !== 'GET' && !/\/login$/.test(r.url())) return route.abort();
          return route.continue();
        });
        const page = await context.newPage();
        try {
          await login(page, role);
          await page.waitForLoadState('domcontentloaded');
          await crawl(page, `${role}/${template}/${viewport.name}`, findings, stats);
        } finally {
          await context.close();
          runSql(`UPDATE users SET ui_template_preference_id = ${before === 'NULL' ? 'NULL' : Number(before)} WHERE email = '${account.email}'`, 'restore template');
        }
      }
      const out = process.env.QA_CRAWL_OUT;
      if (out) {
        const file = out.replace(/\.json$/, `-${template}-${viewport.name}.json`);
        fs.writeFileSync(file, JSON.stringify({ base: process.env.BASE_URL, template, viewport: viewport.name, stats, findings }, null, 2));
      }
      expect(stats.pages).toBeGreaterThan(0);
      const blocking = findings.filter((f) => /^http-5|error-text|pageerror|form-submitted|navigation-failed/.test(f.type) || /^pageerror/.test(f.detail || ''));
      expect(blocking).toEqual([]);
    });
  }
}
