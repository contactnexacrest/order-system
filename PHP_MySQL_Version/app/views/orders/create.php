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
          <select name="incoterm_id" required>
            <?php foreach ($incoterms as $i): ?>
              <option value="<?= (int) $i['id'] ?>" <?= (isset($old['incoterm_id']) && (int) $i['id'] === (int) $old['incoterm_id']) ? 'selected' : '' ?>><?= htmlspecialchars($i['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Loading
          <select name="port_of_loading_id">
            <?php foreach ($loadingPorts as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= isset($old['port_of_loading_id']) ? ((int) $p['id'] === (int) $old['port_of_loading_id'] ? 'selected' : '') : ($p['is_default'] ? 'selected' : '') ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Currency *<small class="muted">(this order's foreign trade currency — everything priced below is in this currency unless marked INR)</small>
          <select name="currency_id" required>
            <?php foreach ($currencies as $cur): ?>
              <option value="<?= (int) $cur['id'] ?>" <?= isset($old['currency_id']) ? ((int) $cur['id'] === (int) $old['currency_id'] ? 'selected' : '') : ($cur['is_default'] ? 'selected' : '') ?>><?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?></option>
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
          <select name="port_of_discharge_id">
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
      </div>
    </fieldset>

    <fieldset>
      <legend>Estimated Weight &amp; Volume <small class="muted">(shown on QT/PI — actuals confirmed later at Packing List)</small></legend>
      <div class="field-grid">
        <label>Total CBM (m&sup3;)<input type="text" name="estimated_total_cbm" value="<?= htmlspecialchars((string) ($old['estimated_total_cbm'] ?? '')) ?>"></label>
        <label>No. of Packages / Crates<input type="text" name="estimated_package_count" value="<?= htmlspecialchars((string) ($old['estimated_package_count'] ?? '')) ?>" placeholder="e.g. 45 crates"></label>
        <label>Gross Weight, kg <span class="tag-hint">product + packing</span><input type="text" name="estimated_gross_weight_kg" value="<?= htmlspecialchars((string) ($old['estimated_gross_weight_kg'] ?? '')) ?>"></label>
        <label>Net Weight, kg <span class="tag-hint">product only</span><input type="text" name="estimated_net_weight_kg" value="<?= htmlspecialchars((string) ($old['estimated_net_weight_kg'] ?? '')) ?>"></label>
        <label class="full">Package Type<input type="text" name="estimated_package_type" value="<?= htmlspecialchars((string) ($old['estimated_package_type'] ?? '')) ?>" placeholder="e.g. Wooden Crates"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Indicative Freight / Insurance <small class="muted">(only shown when Incoterm is not FOB)</small></legend>
      <div class="field-grid">
        <label>Freight — low (<span class="muted">order currency</span>)<input type="text" name="indicative_freight_low" value="<?= htmlspecialchars((string) ($old['indicative_freight_low'] ?? '')) ?>"></label>
        <label>Freight — high (<span class="muted">order currency</span>)<input type="text" name="indicative_freight_high" value="<?= htmlspecialchars((string) ($old['indicative_freight_high'] ?? '')) ?>"></label>
        <label class="full">Insurance (indicative, <span class="muted">order currency</span>)<input type="text" name="indicative_insurance_amount" value="<?= htmlspecialchars((string) ($old['indicative_insurance_amount'] ?? '')) ?>"></label>
      </div>
      <p class="muted small">Why a range, not one figure: ocean freight isn't booked yet at this stage, so rates can move before the actual booking. This low&ndash;high range is indicative only — the real rate is confirmed and recovered by Freight Debit Note once cargo is packed and ready (Stage 6).</p>
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
          <label>Description *<input type="text" name="product_description[]" value="<?= htmlspecialchars((string) $__desc) ?>"></label>
          <label>Dimensions<input type="text" name="product_dimensions[]" value="<?= htmlspecialchars((string) ($old['product_dimensions'][$__i] ?? '')) ?>"></label>
          <label>Finish<input type="text" name="product_finish[]" value="<?= htmlspecialchars((string) ($old['product_finish'][$__i] ?? '')) ?>"></label>
          <label>Qty<input type="text" name="product_quantity[]" value="<?= htmlspecialchars((string) ($old['product_quantity'][$__i] ?? '')) ?>"></label>
          <label>Unit<input type="text" name="product_unit[]" value="<?= htmlspecialchars((string) ($old['product_unit'][$__i] ?? '')) ?>" placeholder="SQM/PCS"></label>
          <label>Unit Price<input type="text" name="product_unit_price[]" value="<?= htmlspecialchars((string) ($old['product_unit_price'][$__i] ?? '')) ?>"></label>
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
      <textarea name="special_requirements" rows="2"><?= htmlspecialchars((string) ($old['special_requirements'] ?? '')) ?></textarea>
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
