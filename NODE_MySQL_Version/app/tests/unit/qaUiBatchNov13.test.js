'use strict';

/**
 * QA round 13 Nov 2026 — UI, navigation and form fixes (UI-004 …). Parity:
 * QaUiBatchNov13Test.php.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const VIEWS = path.join(__dirname, '..', '..', 'views');
const PHP_VIEWS = path.join(__dirname, '..', '..', '..', '..', 'PHP_MySQL_Version', 'app', 'views');
const read = (...p) => fs.readFileSync(path.join(...p), 'utf8');

/** A very small DOM: enough for the "+ Add product line" script (rows, cloneNode, appendChild, remove). */
function fakeElement(id) {
  const el = {
    id,
    children: [],
    parent: null,
    listeners: {},
    get firstElementChild() { return el.children[0] || null; },
    cloneNode() { return fakeElement(id); },
    querySelectorAll() { return []; },
    appendChild(child) { child.parent = el; el.children.push(child); return child; },
    remove() { if (el.parent) { el.parent.children = el.parent.children.filter((c) => c !== el); el.parent = null; } },
    addEventListener(type, fn) { el.listeners[type] = fn; },
    click() { el.listeners.click(); },
  };
  return el;
}

describe('UI-004: "+ Add product line" still works after every line was removed', () => {
  function inlineScript(source) {
    const scripts = source.match(/<script>[\s\S]*?<\/script>/g).filter((s) => s.includes("'add-product-row'"));
    expect(scripts).toHaveLength(1);
    return scripts[0].replace(/^<script>|<\/script>$/g, '').replace(/\{\{[\s\S]*?\}\}/g, '1').replace(/<\?=[\s\S]*?\?>/g, '1');
  }

  for (const [label, source] of [
    ['Node buyer-portal Reorder', () => read(VIEWS, 'client_portal', 'reorder.njk')],
    ['PHP buyer-portal Reorder', () => read(PHP_VIEWS, 'client_portal', 'reorder.php')],
  ]) {
    it(`${label}: removing all lines, then "+ Add product line" adds one`, () => {
      const rows = fakeElement('product-rows');
      rows.appendChild(fakeElement('row'));
      const addButton = fakeElement('add-product-row');
      const byId = { 'product-rows': rows, 'add-product-row': addButton };
      vm.runInNewContext(inlineScript(source()), {
        document: { getElementById: (id) => byId[id] },
        Event: function Event() {},
      });
      rows.firstElementChild.remove();
      expect(rows.children).toHaveLength(0);
      expect(() => addButton.click()).not.toThrow();
      expect(rows.children).toHaveLength(1);
    });
  }

  it('no product-line script clones only rows.firstElementChild (Reorder and New order, both stacks)', () => {
    for (const file of [
      path.join(VIEWS, 'client_portal', 'reorder.njk'), path.join(VIEWS, 'orders', 'create.njk'),
      path.join(PHP_VIEWS, 'client_portal', 'reorder.php'), path.join(PHP_VIEWS, 'orders', 'create.php'),
    ]) {
      const text = fs.readFileSync(file, 'utf8');
      expect([file, /rows\.firstElementChild\.cloneNode/.test(text)]).toEqual([file, false]);
      expect([file, /\(rows\.firstElementChild \|\| \w+Template\)\.cloneNode/.test(text)]).toEqual([file, true]);
    }
  });
});

/** UI-011: an order line's quantity of 0 is stopped in the browser, beside the field (app.js fieldError). */
describe('UI-011: quantity 0 is caught before the order form is sent', () => {
  const APP_JS = [
    path.join(__dirname, '..', '..', 'public', 'js', 'app.js'),
    path.join(__dirname, '..', '..', '..', '..', 'PHP_MySQL_Version', 'public_html', 'assets', 'js', 'app.js'),
  ];

  /** The data-min-exclusive rule of fieldError(), run on its own. */
  function minExclusiveRule(file) {
    const text = fs.readFileSync(file, 'utf8');
    const start = text.indexOf('    // data-min-exclusive="x"');
    expect(start).toBeGreaterThan(0);
    const end = text.indexOf('\n    }\n', text.indexOf('if (value !== \'\' && el.dataset.minExclusive', start)) + 7;
    return new vm.Script(`(function (el, value) {\n${text.slice(start, end)}\n  return null;\n})`).runInNewContext({});
  }

  function field(dataset, tbcTicked = false) {
    return { dataset, form: { elements: { namedItem: (n) => (n === 'quantity_is_tbc' ? { checked: tbcTicked } : null) } } };
  }

  for (const file of APP_JS) {
    it(`${path.basename(path.dirname(path.dirname(file)))} app.js: 0 is refused, more than 0 passes, Qty To Be Confirmed lifts it`, () => {
      const rule = minExclusiveRule(file);
      const qty = { minExclusive: '0', minExclusiveMessage: 'The quantity must be more than 0.' };
      expect(rule(field(qty), '0')).toBe('The quantity must be more than 0.');
      expect(rule(field(qty), '0.000')).toBe('The quantity must be more than 0.');
      expect(rule(field(qty), '0.001')).toBeNull();
      expect(rule(field(qty), '40')).toBeNull();
      expect(rule(field(qty), '')).toBeNull();
      expect(rule(field({ ...qty, minExclusiveUnless: 'quantity_is_tbc' }, true), '0')).toBeNull();
      expect(rule(field({ ...qty, minExclusiveUnless: 'quantity_is_tbc' }, false), '0')).toBe('The quantity must be more than 0.');
      expect(rule(field({ minExclusive: '0' }), '0')).toBe('Enter a number greater than 0.');
    });
  }

  it('the New order and order-line quantity fields carry the rule (both stacks)', () => {
    for (const [file, name] of [
      [path.join(VIEWS, 'orders', 'create.njk'), 'product_quantity[]'], [path.join(PHP_VIEWS, 'orders', 'create.php'), 'product_quantity[]'],
      [path.join(VIEWS, 'orders', '_products_table.njk'), 'quantity'], [path.join(PHP_VIEWS, 'orders', '_products_table.php'), 'quantity'],
    ]) {
      const tag = fs.readFileSync(file, 'utf8').match(new RegExp(`<input type="text" name="${name.replace(/[[\]]/g, '\\$&')}"[^>]*>`))[0];
      expect([file, tag.includes('data-min-exclusive="0"')]).toEqual([file, true]);
    }
  });
});

/**
 * UI-008: every fixed internal link in a view leads to a page route (the TDS
 * register linked to /associates/report, which does not exist). A route
 * parameter named …id only takes digits, as the controllers expect.
 */
describe('UI-008: no fixed link in a view leads to a missing page', () => {
  it('every fixed href="/…" in views/ matches a GET route of src/server.js', () => {
    const source = read(__dirname, '..', '..', 'src', 'server.js');
    const routes = [...source.matchAll(/app\.get\(\s*'([^']+)'/g)].map((m) => new RegExp(`^${m[1]
      .replace(/[.+?^$|[\]\\]/g, '\\$&')
      .replace(/:([A-Za-z_]+)(\\\([^)]*\\\))?/g, (x, name) => (/id$/i.test(name) ? '\\d+' : '[^/]+'))}/?$`));
    expect(routes.length).toBeGreaterThan(200);
    const dead = [];
    (function walk(dir) {
      for (const f of fs.readdirSync(dir)) {
        const p = path.join(dir, f);
        if (fs.statSync(p).isDirectory()) { walk(p); continue; }
        if (!p.endsWith('.njk')) continue;
        for (const m of fs.readFileSync(p, 'utf8').matchAll(/href="(\/[^"{}]*)"/g)) {
          const u = m[1].split(/[?#]/)[0];
          if (/^\/(?:assets|css|js|img|favicon)/.test(u) || u.startsWith('//')) continue;
          if (!routes.some((r) => r.test(u))) dead.push(`${path.relative(VIEWS, p)}: ${m[1]}`);
        }
      }
    })(VIEWS);
    expect(dead).toEqual([]);
  });
});
