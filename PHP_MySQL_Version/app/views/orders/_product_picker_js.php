<?php
/**
 * Owner decision 2 Nov 2026 (D-240): the script behind "Pick from Products" and the
 * live reference-price note on an order line. Included once per page.
 * Node twin: views/orders/_product_picker_js.njk.
 */
?>
<style>
.ref-price-note{display:block;margin-top:4px}
.ref-price-below{color:#8a6d00}
.ref-price-warning{color:#b42318;font-weight:600}
.ref-price-above{color:#1b7f3b}
</style>
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
  function pct(v) { var t = (Math.round(v * 10) / 10).toFixed(1); return t.slice(-2) === '.0' ? t.slice(0, -2) : t; }
  function refreshNote(note, price) {
    var ref = parseFloat(note.dataset.ref);
    if (note.dataset.enabled !== '1' || !(ref > 0) || price === '' || isNaN(parseFloat(price))) { note.textContent = ''; note.className = 'ref-price-note small'; return; }
    var diff = Math.round((parseFloat(price) - ref) / ref * 1000) / 10;
    var text = '', cls = '';
    if (diff < 0 && -diff >= parseFloat(note.dataset.warning)) { text = '⚠ ' + pct(-diff) + '% below reference price (' + note.dataset.label + ')'; cls = 'ref-price-warning'; }
    else if (diff < 0 && -diff >= parseFloat(note.dataset.note)) { text = pct(-diff) + '% below reference price (' + note.dataset.label + ')'; cls = 'ref-price-below'; }
    else if (diff > 0) { text = pct(diff) + '% above reference price (' + note.dataset.label + ')'; cls = 'ref-price-above'; }
    note.textContent = text;
    note.className = 'ref-price-note small ' + cls;
  }
  document.addEventListener('change', function (e) {
    var select = e.target.closest && e.target.closest('select.product-picker');
    if (!select) { return; }
    var opt = select.options[select.selectedIndex];
    var scope = select.closest('.product-row') || select.closest('form');
    var hint = scope.querySelector('.product-picker-hint');
    if (!opt || !opt.value) { if (hint) { hint.textContent = ''; } return; }
    var desc = field(scope, 'description'); if (desc) { desc.value = opt.dataset.name || desc.value; }
    var hs = field(scope, 'hs_code'); if (hs && opt.dataset.hs) { hs.value = opt.dataset.hs; }
    var unit = field(scope, 'unit');
    if (unit && opt.dataset.unit) { for (var i = 0; i < unit.options.length; i++) { if (unit.options[i].value === opt.dataset.unit) { unit.selectedIndex = i; } } }
    var price = field(scope, 'unit_price');
    var text = opt.dataset.specs ? 'Specifications: ' + opt.dataset.specs : '';
    var note = scope.querySelector('.ref-price-note');
    if (opt.dataset.price) {
      if (opt.dataset.currency === orderCurrency(select)) {
        if (price) { price.value = opt.dataset.price; }
        text += (text ? ' · ' : '') + 'Reference price ' + opt.dataset.label + ' filled in — change it if this order differs.';
        if (note) { note.dataset.ref = opt.dataset.price; note.dataset.label = opt.dataset.label; }
      } else {
        if (price) { price.value = ''; }
        text += (text ? ' · ' : '') + 'Reference: ' + opt.dataset.label + ' — different currency, so no price was filled in.';
        if (note) { note.dataset.ref = ''; }
      }
    }
    if (hint) { hint.textContent = text; }
    if (note && price) { refreshNote(note, price.value); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.matches || !e.target.matches('[name="unit_price"], [name="product_unit_price[]"]')) { return; }
    var scope = e.target.closest('.product-row') || e.target.closest('form');
    var note = scope && scope.querySelector('.ref-price-note');
    if (note) { refreshNote(note, e.target.value); }
  });
})();
</script>
