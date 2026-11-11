'use strict';

const fs = require('fs');
const path = require('path');

/**
 * QA Round 8, R8-N08: every form field has a label, and a label shows "*"
 * exactly when its field must be filled.
 *
 *  - Every visible input, select and textarea has a <label> (wrapping it or
 *    label[for]) or an aria-label / aria-labelledby.
 *  - A label written with "*" belongs to a field marked `required` (or
 *    required by a rule: data-required-if / data-required-unless).
 *  - A required field whose label has no "*" gets one in the browser:
 *    markRequiredLabels() in the sitewide app.js, run on load and whenever a
 *    field becomes (or stops being) required.
 *  - Required drop-downs with no sensible default start with "Choose…";
 *    number fields where a negative makes no sense have a min.
 *
 * Port of FormLabelConventionsTest.php.
 */
const VIEWS = path.join(__dirname, '../../views');
const APP_JS = path.join(__dirname, '../../public/js/app.js');
const NOT_FIELDS = new Set(['hidden', 'submit', 'button', 'reset', 'image']);

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? walk(path.join(dir, d.name)) : [path.join(dir, d.name)]));
}

/** Views with <script> blocks blanked out (keeping line numbers): "<select>" in a JS comment is not a field. */
function views() {
  return walk(VIEWS).filter((f) => f.endsWith('.njk')).sort().map((f) => [
    path.relative(VIEWS, f),
    fs.readFileSync(f, 'utf8').replace(/<script\b[\s\S]*?<\/script>/g, (m) => '\n'.repeat(m.split('\n').length - 1)),
  ]);
}

/** The opening tag that starts at `start`, reading past Nunjucks {{ }} / {% %} blocks that contain ">". */
function openingTag(text, start) {
  let j = start + 1;
  while (j < text.length) {
    if (text.startsWith('{{', j) || text.startsWith('{%', j)) {
      const k = text.indexOf(text[j + 1] === '{' ? '}}' : '%}', j);
      j = k < 0 ? text.length : k + 2;
      continue;
    }
    if (text[j] === '>') break;
    j++;
  }
  return text.slice(start, j + 1);
}

const count = (s, sub) => s.split(sub).length - 1;
const lineOf = (text, offset) => text.slice(0, offset).split('\n').length;
const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

describe('form labels (QA Round 8, R8-N08)', () => {
  test('every visible field has a label', () => {
    const missing = [];
    for (const [rel, text] of views()) {
      const labelledIds = new Set([...text.matchAll(/<label[^>]*\bfor="([^"]+)"/g)].map((m) => m[1]));
      for (const m of text.matchAll(/<(input|select|textarea)\b/g)) {
        const tag = openingTag(text, m.index);
        const t = tag.match(/\btype\s*=\s*["']([a-zA-Z-]+)["']/);
        const type = t ? t[1].toLowerCase() : 'text';
        if (tag.startsWith('<input') && NOT_FIELDS.has(type)) continue;
        if (tag.includes('aria-label')) continue;
        const id = tag.match(/\bid\s*=\s*"([^"]+)"/);
        if (id && labelledIds.has(id[1])) continue;
        // client_intake's country picker is a macro called inside <label>Country *</label>.
        if (tag.includes('idPrefix')) continue;
        const before = text.slice(0, m.index);
        if (count(before, '<label') - count(before, '</label>') > 0) continue;
        missing.push(`${rel}:${lineOf(text, m.index)}`);
      }
    }
    expect(missing).toEqual([]);
  });

  test('a label written with "*" belongs to a required field', () => {
    const wrong = [];
    for (const [rel, text] of views()) {
      for (const m of text.matchAll(/<label\b[^>]*>([\s\S]*?)<\/label>/g)) {
        const body = m[1];
        const c = body.match(/<(input|select|textarea)\b/);
        if (!c) continue;
        const own = body.slice(0, c.index).replace(/\{\{[\s\S]*?\}\}|\{%[\s\S]*?%\}|\{#[\s\S]*?#\}/g, '').replace(/<[^>]*>/g, '');
        if (!/(^|\s)\*(\s|$)/.test(own)) continue;
        if (/\brequired\b|data-required-(if|unless)/.test(openingTag(body, c.index))) continue;
        wrong.push(`${rel}:${lineOf(text, m.index)} ${own.replace(/\s+/g, ' ').trim()}`);
      }
    }
    expect(wrong).toEqual([]);
  });

  test('the sitewide script stars every required label once', () => {
    const js = fs.readFileSync(APP_JS, 'utf8');
    expect(js).toContain('function markRequiredLabels()');
    expect(js).toContain("star.className = 'req-star';");
    expect(js).toContain("star.textContent = ' *';");
    // Never a second star: a label already written with "*" is left alone, and the added one goes when the field stops being required.
    expect(js).toContain("writtenLabelText(label).indexOf('*') !== -1");
    expect(js).toContain('added.remove();');
    expect(js).toContain("attributeFilter: ['required', 'disabled']");
    expect(js).toMatch(/function init\(\) \{[\s\S]*initRequiredLabels\(\);/);
  });

  test.each([
    ['cross-verify result', 'orders/show.njk', '<select name="result" required aria-label="Result">'],
    ['logistics bill for', 'orders/show.njk', '<select name="bill_for" required>'],
    ['expense / cost category', 'orders/show.njk', '<select name="category" required>'],
    ['travel expense %', 'orders/show.njk', '<select name="expense_pct" required>'],
    ['inspection fee payer', 'orders/show.njk', '<select name="fee_payer" required>'],
    ['export benefit scheme', 'orders/show.njk', '<select name="scheme_name" required>'],
    ['force-generate document type', 'orders/show.njk', '<select name="document_type" required>'],
    ['new order incoterm', 'orders/create.njk', '<select name="incoterm_id" id="incoterm-select" required onchange="updateFreightFieldsAvailability(true)">'],
    ['new order payment preset', 'orders/create.njk', '<select name="payment_preset_id" required>'],
    ['new port role', 'ports/create.njk', '<select name="port_role" required>'],
    ['new designation level', 'departments/admin.njk', '<select name="level" required>'],
  ])('required drop-down without a default starts with Choose… (%s)', (_name, view, opening) => {
    const text = fs.readFileSync(path.join(VIEWS, view), 'utf8');
    const n = count(text, opening);
    expect(n).toBeGreaterThan(0);
    expect(text.match(new RegExp(`${escapeRe(opening)}\\s*<option value="">Choose…</option>`, 'g'))).toHaveLength(n);
  });

  // Every drop-down's empty first option reads "Choose…" (or "Choose a …"), on
  // any drop-down, required or not — never the older "Select…", "— Select —",
  // "— choose —" or "Please select". Checks the views (scripts included: an
  // option built in JS) and the sitewide app.js.
  test('no empty option uses the old "Select" wording', () => {
    const old = /^\s*(?:[—–-]+\s*)?(?:please\s+)?(?:select|pick)\b|^\s*[—–-]+\s*choose\s*[—–-]*\s*$/i;
    const decode = (s) => s.replace(/&hellip;/g, '…').replace(/&mdash;/g, '—').replace(/&ndash;/g, '–').replace(/&amp;/g, '&');
    const sources = walk(VIEWS).filter((f) => f.endsWith('.njk')).sort().map((f) => [path.relative(VIEWS, f), fs.readFileSync(f, 'utf8')]);
    sources.push(['public/js/app.js', fs.readFileSync(APP_JS, 'utf8')]);
    const wrong = [];
    for (const [rel, text] of sources) {
      const found = [...text.matchAll(/<option\b[^>]*\bvalue=(["'])\1[^>]*>([^<]*)</g)].map((m) => [m[2], m.index]);
      // An option built in JS: var x = document.createElement('option'); … x.textContent = '…';
      const vars = new Set([...text.matchAll(/\b(\w+)\s*=\s*document\.createElement\(['"]option['"]\)/g)].map((m) => m[1]));
      for (const v of vars) {
        for (const m of text.matchAll(new RegExp(`\\b${escapeRe(v)}\\.(?:textContent|text|innerText)\\s*=\\s*(['"])(.*?)\\1`, 'g'))) found.push([m[2], m.index]);
      }
      for (const [label, at] of found) {
        if (old.test(decode(label))) wrong.push(`${rel}:${lineOf(text, at)} ${label.trim()}`);
      }
    }
    expect(wrong).toEqual([]);
  });

  test('number fields that cannot be negative have a min', () => {
    const audit = fs.readFileSync(path.join(VIEWS, 'audit_log/index.njk'), 'utf8');
    expect(audit).toContain('<input type="number" min="1" step="1" name="entity_id"');
    expect(audit).toContain('<input type="number" min="1" step="1" name="user_id"');
    const form = fs.readFileSync(path.join(VIEWS, 'products/_form.njk'), 'utf8');
    const show = fs.readFileSync(path.join(VIEWS, 'products/show.njk'), 'utf8');
    for (const cost of ['factory', 'transportation', 'packing', 'loading', 'cha']) {
      expect(form).toContain(`<input type="number" step="0.01" min="0" name="default_${cost}_cost"`);
      expect(count(show, `<input type="number" step="0.01" min="0" name="${cost}_cost"`)).toBe(2);
    }
    expect(count(show, '<input type="number" step="0.01" min="0" name="fob_value"')).toBe(2);
  });
});
