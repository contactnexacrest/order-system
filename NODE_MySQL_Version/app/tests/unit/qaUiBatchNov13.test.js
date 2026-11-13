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

/** UI-012: every typed setting on Settings › Company shows a placeholder (same list on both stacks, no PII). */
describe('UI-012: Settings placeholders', () => {
  const settingLabels = require('../../src/helpers/settingLabels');

  it('the PHP and Node lists are the same', () => {
    const php = read(__dirname, '..', '..', '..', '..', 'PHP_MySQL_Version', 'app', 'src', 'Helpers', 'SettingLabels.php');
    const block = php.slice(php.indexOf('public const PLACEHOLDERS = ['), php.indexOf('];', php.indexOf('public const PLACEHOLDERS = [')));
    const phpMap = {};
    for (const m of block.matchAll(/'([a-z0-9_]+)' => (?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g)) phpMap[m[1]] = (m[2] !== undefined ? m[2] : m[3]).replace(/\\(.)/g, '$1');
    expect(Object.keys(phpMap).length).toBeGreaterThan(30);
    expect(phpMap).toEqual(settingLabels.PLACEHOLDERS);
  });

  it('uses role addresses and placeholder numbers only', () => {
    for (const [key, text] of Object.entries(settingLabels.PLACEHOLDERS)) {
      for (const email of text.match(/[\w.+-]+@[\w.-]+/g) || []) expect([key, email]).toEqual([key, expect.stringMatching(/^(?:info|md|ed|admin)@email\.com$/)]);
      for (const phone of text.match(/\+\d[\d-]{7,}/g) || []) expect([key, phone]).toEqual([key, '+91-0000000000']);
    }
  });

  it('a setting not listed gets one from its value type', () => {
    expect(settingLabels.placeholder('legal_name', 'string')).toBe('e.g. Company Name Private Limited');
    expect(settingLabels.placeholder('some_days', 'number')).toBe('e.g., 30');
    expect(settingLabels.placeholder('new_mail', 'email')).toBe('e.g. info@email.com');
    expect(settingLabels.placeholder('new_list', 'email_list')).toBe('e.g. md@email.com, ed@email.com');
    expect(settingLabels.placeholder('lut_expiry_note', 'string')).toBe('Type the lut expiry note');
  });
});

/**
 * UI-010: a typed field saved to a VARCHAR column carries a maxlength no larger than
 * the column's length (docs/schema.sql), so a too-long value is stopped in the browser
 * beside the field (a stricter format limit, e.g. PAN 10, is fine). The list holds
 * every field name that is one VARCHAR column of one length in the schema (names used for columns of different lengths, e.g. name / email /
 * reason / notes, are not checked here).
 */
const MAXLENGTH_COLUMNS = `acknowledged_note advance_trigger_text amended_balance_terms amendment_reference bank_account_name bank_branch
  bank_name bank_upi_id billing_address_line1 billing_address_line2 billing_city billing_country billing_postcode bl_number buyer_own_reference
  buyers_po_ref clause_title client_unique_number colour_reference company_legal_name consignee_address_line1 consignee_address_line2
  consignee_city consignee_contact_person consignee_country consignee_email consignee_name consignee_phone consignee_postcode
  consignee_vat_eori_tax_no contact_email contact_person contact_person_name contact_person_phone contact_person_whatsapp contact_phone
  container_no container_type_text country_of_destination courier_tracking_number crate_no decision_notes delivery_location dimensions
  est_lead_time_text est_shipment_date_text estimated_package_count finish from_party grade hs_code incoterm_confirmed incoterm_preference
  lc_bank_name lc_number location material_stone_type notify_party notify_party_address_line1 notify_party_address_line2 notify_party_city
  notify_party_contact_person notify_party_country notify_party_email notify_party_phone notify_party_postcode order_reference origin
  packing_requirement pan partner_name payer_bank_details port_of_discharge_text preset_name production_status_text ref_format
  reference_number refund_notes resolved_reason seal_no shipping_line state supplier_legal_name supplier_name transaction_ref unlock_reason
  usage_note vat_eori_tax_no vessel_name vessel_voyage voyage_number whatsapp_number`.split(/\s+/);

describe('UI-010: text fields carry the maxlength of their database column', () => {
  const schema = read(__dirname, '..', '..', '..', 'docs', 'schema.sql');
  function columnLength(name) {
    const lens = new Set([...schema.matchAll(new RegExp(`^\\s*(?:(?:ADD|MODIFY) COLUMN\\s+)?\`?${name}\`?\\s+(VARCHAR\\((\\d+)\\)|TEXT|MEDIUMTEXT|LONGTEXT)`, 'gmi'))].map((m) => m[2] || m[1].toUpperCase()));
    expect([name, lens.size]).toEqual([name, 1]);
    return Number([...lens][0]);
  }
  /** Each opening <input>/<textarea> tag, reading past Nunjucks blocks that contain ">". */
  function tags(text) {
    const out = [];
    for (const m of text.matchAll(/<(input|textarea)\b/g)) {
      let j = m.index + m[0].length;
      while (j < text.length) {
        if (text.startsWith('{{', j) || text.startsWith('{%', j) || text.startsWith('{#', j)) {
          const k = text.indexOf({ '{': '}}', '%': '%}', '#': '#}' }[text[j + 1]], j);
          j = k < 0 ? text.length : k + 2;
          continue;
        }
        if (text[j] === '>') break;
        j++;
      }
      out.push([m.index, text.slice(m.index, j + 1)]);
    }
    return out;
  }

  it('every such field on every view (and the client form\'s company name is 255)', () => {
    const lengths = Object.fromEntries(MAXLENGTH_COLUMNS.map((n) => [n, columnLength(n)]));
    expect(lengths.company_legal_name).toBe(255);
    const wrong = [];
    let checked = 0;
    (function walk(dir) {
      for (const f of fs.readdirSync(dir)) {
        const p = path.join(dir, f);
        if (fs.statSync(p).isDirectory()) { walk(p); continue; }
        if (!p.endsWith('.njk')) continue;
        const text = fs.readFileSync(p, 'utf8');
        for (const [offset, tag] of tags(text)) {
          const type = (tag.match(/\btype\s*=\s*["']([a-zA-Z-]+)["']/) || [null, 'text'])[1].toLowerCase();
          if (tag.startsWith('<input') && !['text', 'email', 'tel', 'url', 'search', 'password'].includes(type)) continue;
          const name = (tag.match(/\bname\s*=\s*["']([A-Za-z0-9_]+)/) || [])[1];
          if (!name || !(name in lengths)) continue;
          checked++;
          const max = (tag.match(/\bmaxlength\s*=\s*["'](\d+)["']/) || [])[1];
          if (!max || Number(max) > lengths[name]) wrong.push(`${path.relative(VIEWS, p)}:${text.slice(0, offset).split('\n').length} ${name} maxlength=${max} (column ${lengths[name]})`);
        }
      }
    })(VIEWS);
    expect(checked).toBeGreaterThan(200);
    expect(wrong).toEqual([]);
  });
});

/** MTX-002: the e2e roles/permissions matrix visits every route, so its inventory lists every route of server.js. */
describe('MTX-002: the QA route inventory is complete', () => {
  it('every app.get / app.post of src/server.js is in e2e/support/qa-inventory.json', () => {
    const inventory = JSON.parse(read(__dirname, '..', '..', '..', '..', 'e2e', 'support', 'qa-inventory.json'));
    const norm = (method, p) => `${method} ${p.replace(/\{\w+\}/g, '{}')}`;
    const listed = new Set(inventory.routes.map((r) => norm(r.method, r.path)));
    const source = read(__dirname, '..', '..', 'src', 'server.js');
    const missing = [];
    let loopValues = null;
    for (const line of source.split('\n')) {
      const loop = line.match(/^\s*for \(const \[a, m\] of \[(.*)\]\) \{/);
      if (loop) { loopValues = [...loop[1].matchAll(/\['(\w+)',/g)].map((m) => m[1]); continue; }
      const m = line.match(/^\s*app\.(get|post)\((['`])([^'`]+)\2,/);
      if (!m) continue;
      const paths = m[3].includes('${a}') ? loopValues.map((v) => m[3].replace('${a}', v)) : [m[3]];
      for (const p of paths) {
        const key = norm(m[1].toUpperCase(), p.replace(/:(\w+)(\([^)]*\))?/g, '{$1}'));
        if (!listed.has(key)) missing.push(key);
      }
    }
    expect(missing).toEqual([]);
    expect(inventory.permissions.map((p) => p.key)).toEqual(expect.arrayContaining(['approve_buyer_details_change', 'view_associate_commission_rules']));
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
