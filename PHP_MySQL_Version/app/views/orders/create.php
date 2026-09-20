<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>New Order</h1>
  <form method="post" action="/orders">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Client</legend>
      <label>Client *
        <select name="client_id" required>
          <option value="">— Select —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === (int) $preselectedClientId) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['company_legal_name']) ?> (<?= htmlspecialchars($c['client_unique_number']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>

    <fieldset>
      <legend>Commercial Terms</legend>
      <label>Incoterm *
        <select name="incoterm_id" required>
          <?php foreach ($incoterms as $i): ?>
            <option value="<?= (int) $i['id'] ?>"><?= htmlspecialchars($i['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Currency *
        <select name="currency_id" required>
          <?php foreach ($currencies as $cur): ?>
            <option value="<?= (int) $cur['id'] ?>" <?= $cur['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Payment Preset *
        <select name="payment_preset_id" required>
          <?php foreach ($paymentPresets as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['preset_name']) ?> (<?= (float) $p['advance_pct'] ?>% adv / <?= (float) $p['balance_pct'] ?>% bal)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Certificate of Origin Type
        <select name="coo_type">
          <option value="">— TBC —</option>
          <?php foreach ($cooTypes as $o): ?>
            <option value="<?= htmlspecialchars($o['option_value']) ?>"><?= htmlspecialchars($o['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>

    <fieldset>
      <legend>Shipping</legend>
      <label>Port of Loading
        <select name="port_of_loading_id">
          <?php foreach ($loadingPorts as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $p['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Port of Discharge <small class="muted">(select if it's a repeat port, or type a new one below)</small>
        <select name="port_of_discharge_id">
          <option value="">— Type below instead —</option>
          <?php foreach ($dischargePorts as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Port of Discharge (new / not listed)
        <input type="text" name="port_of_discharge_text" placeholder="Buyer's nominated discharge port">
      </label>
      <label>Container Type
        <select name="container_type">
          <option value="">— TBC —</option>
          <?php foreach ($containerTypes as $o): ?>
            <option value="<?= htmlspecialchars($o['option_value']) ?>"><?= htmlspecialchars($o['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Est. Lead Time <small class="muted">(free text, shown on the Quotation)</small>
        <input type="text" name="est_lead_time_text" placeholder="e.g. 45-60 days from advance receipt">
      </label>
    </fieldset>

    <fieldset>
      <legend>Estimated Weight &amp; Volume <small class="muted">(shown on QT/PI — actuals confirmed later at Packing List)</small></legend>
      <label>Total CBM (m&sup3;)<input type="text" name="estimated_total_cbm"></label>
      <label>Gross Weight (kg)<input type="text" name="estimated_gross_weight_kg"></label>
      <label>Net Weight (kg)<input type="text" name="estimated_net_weight_kg"></label>
      <label>No. of Packages / Crates<input type="text" name="estimated_package_count" placeholder="e.g. 45 crates"></label>
      <label>Package Type<input type="text" name="estimated_package_type" placeholder="e.g. Wooden Crates"></label>
    </fieldset>

    <fieldset>
      <legend>Indicative Freight / Insurance <small class="muted">(only shown when Incoterm is not FOB)</small></legend>
      <label>Freight — low (<span class="muted">order currency</span>)<input type="text" name="indicative_freight_low"></label>
      <label>Freight — high<input type="text" name="indicative_freight_high"></label>
      <label>Insurance (indicative)<input type="text" name="indicative_insurance_amount"></label>
    </fieldset>

    <fieldset>
      <legend>Products</legend>
      <div id="product-rows">
        <div class="product-row">
          <label>Description *<input type="text" name="product_description[]"></label>
          <label>Dimensions<input type="text" name="product_dimensions[]"></label>
          <label>Finish<input type="text" name="product_finish[]"></label>
          <label>Qty<input type="text" name="product_quantity[]"></label>
          <label>Unit<input type="text" name="product_unit[]" placeholder="SQM/PCS"></label>
          <label>Unit Price<input type="text" name="product_unit_price[]"></label>
          <label>HS Code<input type="text" name="product_hs_code[]" placeholder="6802.93"></label>
          <button type="button" class="remove-row" onclick="this.closest('.product-row').remove()">&times;</button>
        </div>
      </div>
      <div class="btn-row"><button type="button" class="btn-secondary btn-sm" id="add-product-row">+ Add product line</button></div>
    </fieldset>

    <fieldset>
      <legend>Special Requirements</legend>
      <textarea name="special_requirements" rows="2"></textarea>
    </fieldset>

    <fieldset>
      <legend>Annexure A</legend>
      <label><input type="checkbox" name="include_annexure_a" value="1" style="display:inline-block;width:auto;"> Include Annexure A — Product Technical Specifications
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
</script>
