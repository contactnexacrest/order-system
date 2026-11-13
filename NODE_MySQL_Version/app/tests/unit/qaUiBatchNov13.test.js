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
