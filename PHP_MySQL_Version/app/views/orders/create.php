<?php use App\Helpers\Csrf; $old = $old ?? []; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders">&larr; Back to Orders</a></p>
  <h1>New Order</h1>
  <form method="post" action="/orders">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Client</legend>
      <label>Client *
        <select name="client_id" required>
          <option value="">— Select —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === (int) ($old['client_id'] ?? $preselectedClientId)) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['company_legal_name']) ?> (<?= htmlspecialchars($c['client_unique_number']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>

    <fieldset>
      <legend>Commercial &amp; Shipping Terms</legend>
      <div class="field-grid">
        <label>Incoterm *
          <select name="incoterm_id" id="incoterm-select" required onchange="updateFreightFieldsAvailability(true)">
            <?php foreach ($incoterms as $i): ?>
              <option value="<?= (int) $i['id'] ?>" <?= (isset($old['incoterm_id']) && (int) $i['id'] === (int) $old['incoterm_id']) ? 'selected' : '' ?> data-code="<?= htmlspecialchars($i['code']) ?>"><?= htmlspecialchars($i['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Loading
          <select name="port_of_loading_id" id="port-of-loading-select" onchange="lookupSailingLeadTime()">
            <?php foreach ($loadingPorts as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= isset($old['port_of_loading_id']) ? ((int) $p['id'] === (int) $old['port_of_loading_id'] ? 'selected' : '') : ($p['is_default'] ? 'selected' : '') ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Currency *<small class="muted">(this order's foreign trade currency — everything priced below is in this currency unless marked INR)</small>
          <select name="currency_id" id="order-currency-select" required onchange="updateFreightCurrencyLabels(this)">
            <?php foreach ($currencies as $cur): ?>
              <option value="<?= (int) $cur['id'] ?>" data-code="<?= htmlspecialchars($cur['code']) ?>" <?= isset($old['currency_id']) ? ((int) $cur['id'] === (int) $old['currency_id'] ? 'selected' : '') : ($cur['is_default'] ? 'selected' : '') ?>><?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Payment Preset *
          <select name="payment_preset_id" required>
            <?php foreach ($paymentPresets as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= (isset($old['payment_preset_id']) && (int) $p['id'] === (int) $old['payment_preset_id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['preset_name']) ?> (<?= (float) $p['advance_pct'] ?>% adv / <?= (float) $p['balance_pct'] ?>% bal)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Certificate of Origin Type
          <select name="coo_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($cooTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= (($old['coo_type'] ?? '') === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Container Type
          <select name="container_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($containerTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= (($old['container_type'] ?? '') === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Discharge <small class="muted">(select if it's a repeat port, or type a new one below)</small>
          <select name="port_of_discharge_id" id="port-of-discharge-select" onchange="lookupSailingLeadTime()">
            <option value="">— Type below instead —</option>
            <?php foreach ($dischargePorts as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= (isset($old['port_of_discharge_id']) && (int) $p['id'] === (int) $old['port_of_discharge_id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Discharge (new / not listed)
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars((string) ($old['port_of_discharge_text'] ?? '')) ?>" placeholder="Buyer's nominated discharge port">
        </label>
        <label class="full">Est. Lead Time <small class="muted">(free text, shown on the Quotation)</small>
          <input type="text" name="est_lead_time_text" value="<?= htmlspecialchars((string) ($old['est_lead_time_text'] ?? '')) ?>" placeholder="e.g. 45-60 days from advance receipt">
        </label>
        <label>Production Lead Time Min (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="lead_time_min_days" value="<?= htmlspecialchars((string) ($old['lead_time_min_days'] ?? '')) ?>" placeholder="e.g. 45">
        </label>
        <label>Production Lead Time Max (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="lead_time_max_days" value="<?= htmlspecialchars((string) ($old['lead_time_max_days'] ?? '')) ?>" placeholder="e.g. 60">
        </label>
        <label>Sailing Lead Time Min (days) <small class="muted">(internal only — auto-suggested from <a href="/port-routes" target="_blank">Port Routes</a> once both ports are picked, still editable)</small>
          <input type="number" min="0" name="sailing_lead_time_min_days" id="sailing-lead-time-min" value="<?= htmlspecialchars((string) ($old['sailing_lead_time_min_days'] ?? '')) ?>" placeholder="e.g. 18">
        </label>
        <label>Sailing Lead Time Max (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="sailing_lead_time_max_days" id="sailing-lead-time-max" value="<?= htmlspecialchars((string) ($old['sailing_lead_time_max_days'] ?? '')) ?>" placeholder="e.g. 24">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Estimated Weight &amp; Volume <small class="muted">(shown on QT/PI — actuals confirmed later at Packing List)</small></legend>
      <div class="field-grid">
        <label>Total CBM (m&sup3;)<input type="text" name="estimated_total_cbm" value="<?= htmlspecialchars((string) ($old['estimated_total_cbm'] ?? '')) ?>"></label>
        <label>No. of Packages / Crates<input type="text" name="estimated_package_count" value="<?= htmlspecialchars((string) ($old['estimated_package_count'] ?? '')) ?>" placeholder="e.g. 45 crates"></label>
        <label>Gross Weight, kg <span class="tag-hint">product + packing</span><input type="text" name="estimated_gross_weight_kg" value="<?= htmlspecialchars((string) ($old['estimated_gross_weight_kg'] ?? '')) ?>"></label>
        <label>Net Weight, kg <span class="tag-hint">product only</span><input type="text" name="estimated_net_weight_kg" value="<?= htmlspecialchars((string) ($old['estimated_net_weight_kg'] ?? '')) ?>"></label>
        <label class="full">Package Type
          <select name="estimated_package_type">
            <?php foreach ($packageTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= (($old['estimated_package_type'] ?? '') === $o['option_value']) ? 'selected' : ((($old['estimated_package_type'] ?? '') === '' && $o['is_default']) ? 'selected' : '') ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset id="freight-insurance-fieldset">
      <legend>Indicative Freight / Insurance <small class="muted">(printed on the Quotation only for CFR/CIF — not needed for FOB)</small></legend>
      <div class="field-grid">
        <label>Freight (indicative, <span class="muted">in <span id="freight-currency-unit">order currency</span></span>)<input type="text" name="indicative_freight_amount" value="<?= htmlspecialchars((string) ($old['indicative_freight_amount'] ?? '')) ?>" placeholder="e.g. 950"></label>
        <label>Insurance (indicative, <span class="muted">in <span id="insurance-currency-unit">order currency</span></span>)<input type="text" name="indicative_insurance_amount" value="<?= htmlspecialchars((string) ($old['indicative_insurance_amount'] ?? '')) ?>" placeholder="e.g. 120"></label>
      </div>
      <p class="muted small">Ocean freight isn't booked yet at this stage, so rates can move before the actual booking. This is the current indicative rate only — the real rate is confirmed by Debit Note once cargo is packed and ready (Stage 6). Ocean freight rates are changing often at the moment, so the rate confirmed at the time of vessel booking may be lower or higher. Freight and insurance are paid in full in advance against the Debit Note, before the vessel is booked.</p>
    </fieldset>

    <fieldset>
      <legend>Products</legend>
      <?php
        // Batch 3 #4 — on a validation failure (e.g. a bad HS code on one line) the old
        // product rows are rebuilt here instead of losing every line the user had typed.
        // Falls back to a single empty row, same as before this feature existed.
        $__oldDescriptions = $old['product_description'] ?? [''];
      ?>
      <div id="product-rows">
        <?php foreach ($__oldDescriptions as $__i => $__desc): ?>
        <div class="product-row">
          <label>Description *<input type="text" name="product_description[]" value="<?= htmlspecialchars((string) $__desc) ?>" placeholder="e.g. Granite Memorial Headstone"></label>
          <label>Dimensions<input type="text" name="product_dimensions[]" value="<?= htmlspecialchars((string) ($old['product_dimensions'][$__i] ?? '')) ?>" placeholder="e.g. 24x12x3 inches"></label>
          <label>Finish
            <select name="product_finish[]">
              <?php $__finishVal = (string) ($old['product_finish'][$__i] ?? ''); ?>
              <option value="">—</option>
              <?php foreach ($surfaceFinishes as $o): ?>
                <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__finishVal === $o['option_value'] || ($__finishVal === '' && $o['is_default'])) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Qty<input type="text" name="product_quantity[]" value="<?= htmlspecialchars((string) ($old['product_quantity'][$__i] ?? '')) ?>" placeholder="e.g. 100"></label>
          <label>Unit
            <select name="product_unit[]">
              <?php $__unitVal = (string) ($old['product_unit'][$__i] ?? ''); ?>
              <?php foreach ($units as $o): ?>
                <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($__unitVal === $o['option_value'] || ($__unitVal === '' && $o['is_default'])) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Unit Price<input type="text" name="product_unit_price[]" value="<?= htmlspecialchars((string) ($old['product_unit_price'][$__i] ?? '')) ?>" placeholder="e.g. 45.00"></label>
          <label>HS Code<input type="text" name="product_hs_code[]" value="<?= htmlspecialchars((string) ($old['product_hs_code'][$__i] ?? '')) ?>" list="hs_code_list" placeholder="Type to search…" autocomplete="off" required></label>
          <div class="product-row-actions">
            <button type="button" class="btn-sm btn-secondary duplicate-row" title="Clone this line with its values — handy when only the name or dimensions differ">Duplicate</button>
            <button type="button" class="remove-row" onclick="this.closest('.product-row').remove()">&times;</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="btn-row"><button type="button" class="btn-secondary btn-sm" id="add-product-row">+ Add product line</button></div>
      <datalist id="hs_code_list">
        <?php foreach ($hsCodes as $hc): ?>
          <option value="<?= htmlspecialchars($hc['code']) ?>"><?= htmlspecialchars($hc['description']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <?php if (empty($hsCodes)): ?>
        <p class="muted small">No HS codes on the master list yet — <a href="/hs-codes">add one</a> before creating this order.</p>
      <?php endif; ?>
    </fieldset>

    <fieldset>
      <legend>Special Requirements/Instructions</legend>
      <textarea name="special_requirements" rows="2" placeholder="e.g. Fragile items — extra cushioning required in crate"><?= htmlspecialchars((string) ($old['special_requirements'] ?? '')) ?></textarea>
    </fieldset>

    <fieldset>
      <legend>Annexure A</legend>
      <label><input type="checkbox" name="include_annexure_a" value="1" <?= !empty($old['include_annexure_a']) ? 'checked' : '' ?> style="display:inline-block;width:auto;"> Include Annexure A — Product Technical Specifications
        <small class="muted">Referenced from the Quotation/PI/OC/Buyer PO as an attached, integral document. Manage its product entries and images from the order page once this order is created.</small>
      </label>
    </fieldset>

    <button type="submit">Create Order</button>
  </form>
</div>
<script>
// 90-item backlog #67: the Freight/Insurance hints used to say the literal
// placeholder text "order currency" instead of the actual currency the
// staff member just picked above — reflect the selected <option>'s
// data-code live, since the order hasn't been saved yet at this screen.
function updateFreightCurrencyLabels(selectEl) {
  var opt = selectEl.options[selectEl.selectedIndex];
  var code = (opt && opt.getAttribute('data-code')) || 'order currency';
  var freightLabel = document.getElementById('freight-currency-unit');
  var insuranceLabel = document.getElementById('insurance-currency-unit');
  if (freightLabel) { freightLabel.textContent = code; }
  if (insuranceLabel) { insuranceLabel.textContent = code; }
}
updateFreightCurrencyLabels(document.getElementById('order-currency-select'));

// User report: Freight/Insurance is only ever relevant when the seller
// is on the hook for it (CFR/CIF etc.) — under FOB the buyer arranges
// and pays freight directly, so these fields have nothing to record.
// Disabling the whole <fieldset> (rather than each input) reuses the
// existing fieldset[disabled] greyed-out styling for free, and disabled
// inputs are simply left out of the form submission. Matches the app's
// own is_fob rule everywhere else: strtoupper(incoterm_code) === 'FOB'.
function updateFreightFieldsAvailability(clearOnDisable) {
  var incotermSelect = document.getElementById('incoterm-select');
  var fieldset = document.getElementById('freight-insurance-fieldset');
  if (!incotermSelect || !fieldset) { return; }
  var opt = incotermSelect.options[incotermSelect.selectedIndex];
  var code = ((opt && opt.getAttribute('data-code')) || '').toUpperCase();
  var isFob = code === 'FOB';
  fieldset.disabled = isFob;
  if (isFob && clearOnDisable) {
    var freightInput = fieldset.querySelector('input[name="indicative_freight_amount"]');
    var insuranceInput = fieldset.querySelector('input[name="indicative_insurance_amount"]');
    if (freightInput) { freightInput.value = ''; }
    if (insuranceInput) { insuranceInput.value = ''; }
  }
}
// Page load: just set the disabled state — don't clear a value the user
// (or a failed-validation resubmit) already has sitting in the field.
updateFreightFieldsAvailability(false);

// 90-item backlog item 15: once both ports are picked, offer (never
// force — the fields stay freely editable) a sailing lead-time suggestion
// from the Port Routes reference document. Only fills in currently-blank
// fields, so it never clobbers a value staff already typed.
function lookupSailingLeadTime() {
  var fromSel = document.getElementById('port-of-loading-select');
  var toSel = document.getElementById('port-of-discharge-select');
  var minField = document.getElementById('sailing-lead-time-min');
  var maxField = document.getElementById('sailing-lead-time-max');
  if (!fromSel || !toSel || !minField || !maxField) { return; }
  var from = fromSel.value;
  var to = toSel.value;
  if (!from || !to) { return; }
  fetch('/port-routes/lookup?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to))
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.found) {
        if (minField.value === '') { minField.value = data.min; }
        if (maxField.value === '') { maxField.value = data.max; }
      }
    })
    .catch(function () { /* best-effort suggestion only — a failed lookup never blocks the form */ });
}

document.getElementById('add-product-row').addEventListener('click', function () {
  var rows = document.getElementById('product-rows');
  var clone = rows.firstElementChild.cloneNode(true);
  clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
  rows.appendChild(clone);
});
// Point 6 — same idea as the "Duplicate" button on the post-confirmation
// edit screen, but client-side since these rows aren't saved yet: clone a
// line WITH its values, for when only the name or dimensions differ and
// retyping everything else would be pointless.
document.getElementById('product-rows').addEventListener('click', function (e) {
  if (!e.target.classList.contains('duplicate-row')) return;
  var sourceRow = e.target.closest('.product-row');
  var clone = sourceRow.cloneNode(true);
  document.getElementById('product-rows').appendChild(clone);
});
</script>
