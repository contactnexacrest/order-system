<?php
/**
 * The script behind "Pick from Products" (D-240; owner decision D-245 P2: fills the
 * quote price of whoever sells the order and sets the line's floor for the
 * sitewide data-floor note in app.js). Owner decisions of 11 Nov 2026: D-270 (point 25)
 * piece size, thickness <-> Thickness option, Dimensions text, prices per the line's unit;
 * D-271 (point 13) a switched-off option is shown, never replaced; D-273 (point 17) the
 * side panel. D-339 (12 Nov 2026): a typed price (data-auto="0") is never overwritten by a
 * filled or converted quote. D-348 (12 Nov 2026, owner "go"): on a new pick the "Thickness" and
 * "Size / format" options start on "Choose…" (required), no price until both are chosen; a typed
 * Thickness (cm) sets the Thickness option unless the user chose it by hand. Included once per page.
 * Node twin: views/orders/_product_picker_js.njk.
 */
?>
<script>
(function () {
  function field(scope, base) {
    return scope.querySelector('[name="' + base + '"]') || scope.querySelector('[name="product_' + base + '[]"]');
  }
  function orderCurrency(select) {
    if (select.dataset.orderCurrency) { return select.dataset.orderCurrency; }
    var cur = document.getElementById('order-currency-select');
    var opt = cur && cur.options[cur.selectedIndex];
    return opt ? (opt.getAttribute('data-code') || '') : '';
  }
  // Whoever sells the order: fixed on the order page; on the New Order form, the chosen client's.
  function priceKey(select) {
    if (select.dataset.priceKey) { return select.dataset.priceKey; }
    var client = document.querySelector('select[name="client_id"]');
    var opt = client && client.options[client.selectedIndex];
    return (opt && opt.getAttribute('data-price-key')) || 'staff';
  }
  function money(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // ---- D-348: Thickness and Size / format are chosen, never preselected ----------------------
  // Only the specifications with EXACTLY these names start on "Choose…" (required) when a product
  // is picked; every other specification keeps its base option. Server twin:
  // OrderLinePricing::REQUIRED_SPEC_NAMES (PHP) / REQUIRED_SPEC_NAMES (Node).
  var REQUIRED_SPECS = ['Thickness', 'Size / format'];
  var CHOOSE_HINT = 'Choose the Thickness and Size / format to see the price.';
  function needsSpecChoice(opt) {
    if (!opt || !opt.value || !opt.dataset.specOptions) { return false; }
    try { return JSON.parse(opt.dataset.specOptions).some(function (sp) { return REQUIRED_SPECS.indexOf(sp.name) !== -1 && sp.options.length > 0; }); } catch (err) { return false; }
  }
  function specsMissing(scope) {
    return Array.prototype.some.call(scope.querySelectorAll('.spec-options select[data-spec-required]'), function (x) { return x.value === ''; });
  }
  // The chosen options as {spec id: option id}; a specification still on "Choose…" is left out.
  function writeSelection(scope) {
    var json = scope.querySelector('input.spec-json');
    var sel = {};
    scope.querySelectorAll('.spec-options select').forEach(function (x) { if (x.value !== '') { sel[x.getAttribute('data-spec-id')] = Number(x.value); } });
    if (json) { json.value = JSON.stringify(sel); }
  }
  // While Thickness or Size / format is on "Choose…": no quote price (a filled one is taken back,
  // a typed one stays — D-339), no floor note, and the hint says what is missing.
  function waitForSpecs(scope) {
    var price = field(scope, 'unit_price');
    var hint = scope.querySelector('.product-picker-hint');
    var refBox = scope.querySelector('.price-ref-box');
    if (hint) { hint.textContent = CHOOSE_HINT; }
    if (refBox) { refBox.textContent = CHOOSE_HINT; }
    if (!price) { return; }
    if (price.dataset.auto === '1') { price.value = ''; }
    price.removeAttribute('data-floor');
    updateTotal(scope);
    price.dispatchEvent(new CustomEvent('floor-changed', { bubbles: true }));
  }

  document.addEventListener('change', function (e) {
    var select = e.target.closest && e.target.closest('select.product-picker');
    if (!select) { return; }
    var opt = select.options[select.selectedIndex];
    var scope = select.closest('.product-row') || select.closest('form');
    var hint = scope.querySelector('.product-picker-hint');
    var price = field(scope, 'unit_price');
    if (!opt || !opt.value) { if (hint) { hint.textContent = ''; } renderSpecs(select, false); return; }
    var desc = field(scope, 'description'); if (desc) { desc.value = opt.dataset.name || desc.value; }
    var hs = field(scope, 'hs_code'); if (hs && opt.dataset.hs) { hs.value = opt.dataset.hs; }
    var unit = field(scope, 'unit');
    if (unit && opt.dataset.unit) { for (var i = 0; i < unit.options.length; i++) { if (unit.options[i].value === opt.dataset.unit) { unit.selectedIndex = i; } } }
    var text = opt.dataset.specs ? 'Specifications: ' + opt.dataset.specs : '';
    if (needsSpecChoice(opt) && price && select.dataset.selling === '1' && orderCurrency(select) === 'USD') {
      // D-348: no price yet — renderSpecs below asks for the Thickness and Size / format.
    } else if (opt.dataset.prices && price) {
      var prices = JSON.parse(opt.dataset.prices);
      var pair = prices[priceKey(select)] || null;
      if (orderCurrency(select) !== 'USD') {
        text += (text ? ' · ' : '') + 'Prices are built in USD — no price filled in on an order in another currency.';
        price.removeAttribute('data-floor');
      } else if (pair) {
        // D-339: a price the staff member typed is never overwritten (data-auto="0").
        if (price.dataset.auto !== '0') {
          price.value = pair[0].toFixed(2);
          price.dataset.auto = '1';
        }
        price.setAttribute('data-floor', pair[1].toFixed(2));
        text += (text ? ' · ' : '') + 'Quote price USD ' + pair[0].toFixed(2) + ' filled in; your lowest price is USD ' + pair[1].toFixed(2) + '.';
      }
      price.dispatchEvent(new CustomEvent('floor-changed', { bubbles: true }));
    } else if (price && select.dataset.selling === '1') {
      price.removeAttribute('data-floor');
      price.dispatchEvent(new CustomEvent('floor-changed', { bubbles: true }));
      text += (text ? ' · ' : '') + 'No price set for this product yet — the line will need the MD\'s approval.';
    }
    if (hint) { hint.textContent = text; }
    // Owner decision D-255: the product's specification options; the price follows them.
    renderSpecs(select, true);
  });

  // ---- D-255: specification options on the order line ------------------------------------
  function specScope(select) { return select.closest('.product-row') || select.closest('form'); }
  function specJsonField(select) { return specScope(select).querySelector('input.spec-json'); }
  function currentSelection(select) {
    var f = specJsonField(select);
    try { return f && f.value ? JSON.parse(f.value) : {}; } catch (err) { return {}; }
  }
  // D-357: the "Finish" select is for lines typed by hand; a picked line's finish is its Surface finish option.
  function finishBox(scope, fromOption) {
    var box = scope.querySelector('[data-finish-box]');
    var note = scope.querySelector('[data-finish-note]');
    if (box) { box.hidden = fromOption; }
    if (note) { note.hidden = !fromOption; }
  }
  function renderSpecs(select, refresh) {
    var scope = specScope(select);
    var box = scope.querySelector('.spec-options');
    var json = specJsonField(select);
    var emptyHint = scope.querySelector('.spec-empty-hint');
    if (!box || !json) { return; }
    var opt = select.options[select.selectedIndex];
    box.innerHTML = '';
    if (emptyHint) { emptyHint.hidden = !!(opt && opt.value && opt.dataset.specOptions); }
    if (!opt || !opt.value || !opt.dataset.specOptions) { json.value = ''; finishBox(scope, false); return; }
    var specs = JSON.parse(opt.dataset.specOptions);
    // D-357: a product with the Surface finish option takes the line's finish from it — the "More › finish" box is hidden.
    finishBox(scope, specs.some(function (sp) { return sp.finish && sp.options.length > 0; }));
    var chosen = refresh ? {} : currentSelection(select);
    // D-348: a saved (or sent back) selection without Thickness / Size / format shows "Choose…";
    // a line saved with no options at all (before D-255) shows the base options as before.
    var hadSelection = !refresh && json.value.trim() !== '';
    specs.forEach(function (spec) {
      var label = document.createElement('label');
      label.className = 'spec-option';
      label.appendChild(document.createTextNode(spec.name + ' '));
      var s = document.createElement('select');
      s.setAttribute('data-spec-id', spec.id);
      s.setAttribute('aria-label', spec.name);
      if (spec.thickness) { s.setAttribute('data-thickness', '1'); s.setAttribute('data-cbm-from', spec.cbm_from || 50); }
      var required = REQUIRED_SPECS.indexOf(spec.name) !== -1;
      var empty = required && chosen[spec.id] === undefined && (refresh || hadSelection);
      if (required) {
        s.required = true;
        s.setAttribute('data-spec-required', '1');
        s.setAttribute('data-required-message', 'Choose the ' + spec.name + ' — the price depends on it.');
        var none = document.createElement('option');
        none.value = '';
        none.textContent = 'Choose…';
        s.appendChild(none);
      }
      var pick = null;
      spec.options.forEach(function (o) {
        var el = document.createElement('option');
        el.value = o.id;
        el.textContent = o.name + (o.cbm ? ' (priced per CBM)' : '');
        if (o.cbm) { el.setAttribute('data-cbm', '1'); }
        if (o.mm !== null && o.mm !== undefined) { el.setAttribute('data-mm', o.mm); }
        if (String(chosen[spec.id]) === String(o.id) || (pick === null && !empty && o.base && chosen[spec.id] === undefined)) { pick = o.id; }
        s.appendChild(el);
      });
      // D-271 (point 13): an option switched off since is shown, never replaced silently.
      if (pick === null && chosen[spec.id] !== undefined) {
        var gone = document.createElement('option');
        gone.value = String(chosen[spec.id]);
        gone.textContent = 'The saved option is no longer offered — choose again';
        s.insertBefore(gone, required ? s.options[1] || null : s.firstChild);
        pick = chosen[spec.id];
        label.classList.add('spec-option-gone');
      }
      if (pick === null && !empty && spec.options.length) { pick = spec.options[0].id; }
      s.value = pick === null ? '' : String(pick);
      label.appendChild(s);
      if (spec.thickness) {
        // D-348: says when the option was set from the typed Thickness (cm).
        var note = document.createElement('small');
        note.className = 'spec-auto-note';
        note.hidden = true;
        label.appendChild(note);
      }
      box.appendChild(label);
    });
    writeSelection(scope);
    // D-348: a thickness typed before the product was picked sets the Thickness option at once.
    if (refresh) { syncThicknessOption(scope); syncThicknessBox(scope); refreshPrice(select, true); }
  }

  // ---- D-270 (point 25): piece size, thickness ↔ Thickness option, Dimensions text -------
  function num(v) { var n = Math.round(Number(v) * 100) / 100; return String(n); }
  function sizeVal(scope, name) { var f = field(scope, name); var v = f ? f.value.trim() : ''; return v !== '' && !isNaN(Number(v)) && Number(v) > 0 ? Number(v) : null; }
  function syncThicknessBox(scope) {
    var s = scope.querySelector('.spec-options select[data-thickness]');
    var box = field(scope, 'piece_thickness_cm');
    if (!s || !box) { return; }
    var o = s.options[s.selectedIndex];
    if (o && o.getAttribute('data-mm')) { box.value = num(Number(o.getAttribute('data-mm')) / 10); fillDimensions(scope); }
  }
  // D-348: the typed Thickness (cm) sets the Thickness option while it is still on "Choose…" or was
  // set this way before — never over a choice made by hand. Exact match (2 → 20 mm, 1.5 → 15 mm …);
  // otherwise from the per-CBM thickness (5 cm) up the "priced per CBM" option ("Other, 50 mm or
  // more"); below it, no option. True when the option changed.
  function autoNote(s, text) {
    var note = s.parentNode && s.parentNode.querySelector('.spec-auto-note');
    if (note) { note.textContent = text; note.hidden = text === ''; }
  }
  function syncThicknessOption(scope) {
    var s = scope.querySelector('.spec-options select[data-thickness]');
    if (!s || (s.value !== '' && s.getAttribute('data-auto-set') !== '1')) { return false; }
    var t = sizeVal(scope, 'piece_thickness_cm');
    var clear = function () {
      autoNote(s, '');
      s.removeAttribute('data-auto-set');
      if (s.value === '') { return false; }
      s.value = '';
      writeSelection(scope);
      return true;
    };
    if (t === null) { return clear(); }
    var mm = Math.round(t * 100) / 10;
    var from = Number(s.getAttribute('data-cbm-from') || 50);
    var exact = null, perCbm = null;
    for (var i = 0; i < s.options.length; i++) {
      var o = s.options[i];
      var omm = o.getAttribute('data-mm');
      if (omm !== null && Math.abs(Number(omm) - mm) < 0.05) { exact = o.value; }
      if (o.getAttribute('data-cbm') === '1' && (perCbm === null || omm === null)) { perCbm = o.value; }
    }
    var pick = exact !== null ? exact : (mm >= from ? perCbm : null);
    if (pick === null) { return clear(); }
    s.setAttribute('data-auto-set', '1');
    autoNote(s, 'set from Thickness (cm) ' + num(t));
    if (s.value === pick) { return false; }
    s.value = pick;
    writeSelection(scope);
    // app.js takes back a "Choose the Thickness" message once the field is valid ("input", not "change").
    s.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }
  function fillDimensions(scope) {
    var dims = field(scope, 'dimensions');
    if (!dims || (dims.dataset.dimsAuto === '0' && dims.value.trim() !== '')) { return; }
    var parts = ['piece_length_cm', 'piece_width_cm', 'piece_thickness_cm'].map(function (n) { return sizeVal(scope, n); }).filter(function (v) { return v !== null; });
    if (parts.length) { dims.value = parts.map(num).join(' × ') + ' cm'; dims.dataset.dimsAuto = '1'; }
  }
  function updateTotal(scope) {
    var out = scope.querySelector('output.line-total');
    if (!out) { return; }
    var q = field(scope, 'quantity'), p = field(scope, 'unit_price');
    var qv = q ? Number(q.value) : NaN, pv = p ? Number(p.value) : NaN;
    out.textContent = q && p && q.value !== '' && p.value !== '' && !isNaN(qv) && !isNaN(pv) ? money(Math.round(qv * pv * 100) / 100) : '—';
  }
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.name) { return; }
    var scope = el.closest('.product-row') || el.closest('form');
    if (!scope) { return; }
    var base = el.name.replace(/^product_/, '').replace(/\[\]$/, '');
    if (base === 'dimensions') { el.dataset.dimsAuto = el.value.trim() === '' ? '1' : '0'; }
    if (base === 'unit_price') { el.dataset.auto = '0'; }
    if (base === 'piece_length_cm' || base === 'piece_width_cm' || base === 'piece_thickness_cm') { fillDimensions(scope); }
    if (base === 'quantity' || base === 'unit_price') { updateTotal(scope); }
  });
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.name) { return; }
    var scope = el.closest('.product-row') || el.closest('form');
    var base = el.name.replace(/^product_/, '').replace(/\[\]$/, '');
    if (!scope || ['piece_length_cm', 'piece_width_cm', 'piece_thickness_cm', 'unit'].indexOf(base) === -1) { return; }
    var picker = scope.querySelector('select.product-picker');
    // D-348: an option set from the typed thickness prices the line as if it had been picked.
    var picked = base === 'piece_thickness_cm' ? syncThicknessOption(scope) : false;
    if (picker && picker.value) { refreshPrice(picker, picked); }
  });

  function refreshPrice(select, fill) {
    var scope = specScope(select);
    var price = field(scope, 'unit_price');
    var unit = field(scope, 'unit');
    var hint = scope.querySelector('.product-picker-hint');
    var refBox = scope.querySelector('.price-ref-box');
    var opt = select.options[select.selectedIndex];
    var selection = currentSelection(select);
    if (select.dataset.selling !== '1' || !price || orderCurrency(select) !== 'USD' || !opt || !opt.value) { return; }
    if (specsMissing(scope)) { waitForSpecs(scope); return; }
    var q = 'product_id=' + encodeURIComponent(opt.value) + '&spec=' + encodeURIComponent(JSON.stringify(selection));
    if (unit && unit.value) { q += '&unit=' + encodeURIComponent(unit.value); }
    ['piece_length_cm', 'piece_width_cm', 'piece_thickness_cm'].forEach(function (n) { var f = field(scope, n); if (f && f.value.trim() !== '') { q += '&' + n + '=' + encodeURIComponent(f.value.trim()); } });
    if (select.dataset.orderId) { q += '&order_id=' + encodeURIComponent(select.dataset.orderId); }
    else { var client = document.querySelector('select[name="client_id"]'); if (client && client.value) { q += '&client_id=' + encodeURIComponent(client.value); } }
    fetch('/pricing/line-quote?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) { return; }
        var per = d.unit ? ' per ' + d.unit : '';
        if (d.ok) {
          // D-270 / D-339: the quote converted into the line's unit, only while the price was not typed.
          if (price.value.trim() === '' || (price.dataset.auto !== '0' && (fill || price.dataset.auto === '1'))) {
            price.value = Number(d.quote).toFixed(2);
            price.dataset.auto = '1';
          }
          price.setAttribute('data-floor', Number(d.floor).toFixed(2));
          var text = 'Quote USD ' + money(d.quote) + ' · your lowest ' + money(d.floor) + per + (d.spec_text ? ' (' + d.spec_text + ')' : '');
          if (d.factor && Math.abs(d.factor - 1) > 1e-9) {
            text += ' — product reference per ' + d.ref_unit + ': quote ' + money(d.ref_quote) + ' · lowest ' + money(d.ref_floor) + '; 1 ' + d.unit + ' = ' + (Math.round(d.factor * 10000) / 10000) + ' ' + d.ref_unit + '.';
          } else { text += '.'; }
          var pv = Number(price.value);
          var badge = price.value.trim() === '' ? '' : (pv >= Number(d.floor) - 0.004 ? ' ✓ OK — above your lowest' : ' ✗ Below your lowest price');
          if (hint) { hint.textContent = text; }
          if (refBox) { refBox.textContent = text + badge; }
        } else {
          price.removeAttribute('data-floor');
          var msg = d.reason + (d.field ? '' : ' The line will need the MD\'s approval.');
          if (hint) { hint.textContent = msg; }
          if (refBox) { refBox.textContent = msg; }
        }
        updateTotal(scope);
        price.dispatchEvent(new CustomEvent('floor-changed', { bubbles: true }));
      })
      .catch(function () {});
  }
  document.addEventListener('change', function (e) {
    var s = e.target.closest && e.target.closest('.spec-options select');
    if (!s) { return; }
    var scope = s.closest('.product-row') || s.closest('form');
    var picker = scope.querySelector('select.product-picker');
    writeSelection(scope);
    // D-348: a Thickness chosen by hand is never changed by the typed thickness again.
    if (s.hasAttribute('data-thickness')) { s.removeAttribute('data-auto-set'); autoNote(s, ''); syncThicknessBox(scope); }
    if (picker) { refreshPrice(picker, true); }
  });

  // ---- D-273 (point 17): the side panel for "Edit" and "+ Add product" --------------------
  function closePanels() { document.querySelectorAll('.line-panel').forEach(function (p) { p.hidden = true; }); document.body.classList.remove('line-panel-open'); }
  document.addEventListener('click', function (e) {
    var open = e.target.closest && e.target.closest('[data-open-panel]');
    if (open) {
      var panel = document.getElementById(open.getAttribute('data-open-panel'));
      if (!panel) { return; }
      closePanels();
      panel.hidden = false;
      document.body.classList.add('line-panel-open');
      var first = panel.querySelector('select.product-picker') || panel.querySelector('input, select, textarea');
      if (first) { first.focus(); }
      return;
    }
    if (e.target.closest && e.target.closest('[data-close-panel]')) { closePanels(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.classList.contains('line-panel-open')) { closePanels(); } });

  // An edited line shows its saved options without changing its price.
  document.querySelectorAll('select.product-picker').forEach(function (select) { if (select.value) { renderSpecs(select, false); } });
})();
</script>
