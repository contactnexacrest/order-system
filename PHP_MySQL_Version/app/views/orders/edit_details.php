<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <h1>Edit Order Details — <?= htmlspecialchars($order['order_reference']) ?></h1>

  <?php if ($isLocked): ?>
    <?php if ($canOverrideLock): ?>
      <p class="notice notice-warning">
        This order is LOCKED (closed or marked lost). Saving a change here will be logged as a
        locked-order edit override — a reason is required below.
      </p>
    <?php else: ?>
      <p class="notice notice-error">
        This order is LOCKED (closed or marked lost) — editing it now needs the
        "Override locked data" permission. Contact a Super Admin if this genuinely needs to change.
      </p>
    <?php endif; ?>
  <?php elseif ($isPostConfirmation): ?>
    <?php if ($canOverride): ?>
      <p class="notice notice-warning">
        This order has already reached Order Confirmation. Saving a change here will be logged as a
        post-confirmation edit — a reason is required below.
      </p>
    <?php else: ?>
      <p class="notice notice-error">
        This order has already reached Order Confirmation — editing it now needs the
        "Edit an order after confirmation" permission. Contact an Admin, Managing Director, or
        Executive Director if this genuinely needs to change (e.g. the client requested it).
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ((!$isLocked || $canOverrideLock) && (!$isPostConfirmation || $canOverride)): ?>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/edit">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Commercial Terms</legend>
      <div class="field-grid">
        <label>Incoterm *
          <select name="incoterm_id" id="incoterm-select" required onchange="updateFreightFieldsAvailability(true)">
            <?php foreach ($incoterms as $i): ?>
              <option value="<?= (int) $i['id'] ?>" <?= (int) $i['id'] === (int) $order['incoterm_id'] ? 'selected' : '' ?> data-code="<?= htmlspecialchars($i['code']) ?>"><?= htmlspecialchars($i['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Currency *
          <select name="currency_id" id="order-currency-select" required onchange="updateFreightCurrencyLabels(this)">
            <?php foreach ($currencies as $cur): ?>
              <option value="<?= (int) $cur['id'] ?>" data-code="<?= htmlspecialchars($cur['code']) ?>" <?= (int) $cur['id'] === (int) $order['currency_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Certificate of Origin Type
          <select name="coo_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($cooTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['option_value'] === $order['coo_type'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Buyer's Own PO Ref <small class="muted">("NIL" if none)</small>
          <input type="text" name="buyers_po_ref" value="<?= htmlspecialchars($order['buyers_po_ref'] ?? '') ?>" placeholder="Write NIL if none">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Shipping</legend>
      <div class="field-grid">
        <label>Port of Loading
          <select name="port_of_loading_id" id="port-of-loading-select" onchange="lookupSailingLeadTime()">
            <option value="">—</option>
            <?php foreach ($loadingPorts as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= (int) ($order['port_of_loading_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Discharge <small class="muted">(select if it's a repeat port, or type a new one below)</small>
          <select name="port_of_discharge_id" id="port-of-discharge-select" onchange="lookupSailingLeadTime()">
            <option value="">— Type below instead —</option>
            <?php foreach ($dischargePorts as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= (int) ($order['port_of_discharge_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Port of Discharge (new / not listed)
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($order['port_of_discharge_text'] ?? '') ?>" placeholder="Buyer's nominated discharge port">
        </label>
        <label>Container Type
          <select name="container_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($containerTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['option_value'] === $order['container_type'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="full">Est. Lead Time <small class="muted">(free text, shown on the Quotation)</small>
          <input type="text" name="est_lead_time_text" value="<?= htmlspecialchars($order['est_lead_time_text'] ?? '') ?>" placeholder="e.g. 45-60 days from advance receipt">
        </label>
        <label>Production Lead Time Min (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="lead_time_min_days" value="<?= htmlspecialchars((string) ($order['lead_time_min_days'] ?? '')) ?>" placeholder="e.g. 45">
        </label>
        <label>Production Lead Time Max (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="lead_time_max_days" value="<?= htmlspecialchars((string) ($order['lead_time_max_days'] ?? '')) ?>" placeholder="e.g. 60">
        </label>
        <label>Sailing Lead Time Min (days) <small class="muted">(internal only — auto-suggested from <a href="/port-routes" target="_blank">Port Routes</a> once both ports are picked, still editable)</small>
          <input type="number" min="0" name="sailing_lead_time_min_days" id="sailing-lead-time-min" value="<?= htmlspecialchars((string) ($order['sailing_lead_time_min_days'] ?? '')) ?>" placeholder="e.g. 18">
        </label>
        <label>Sailing Lead Time Max (days) <small class="muted">(internal only, not shown on documents)</small>
          <input type="number" min="0" name="sailing_lead_time_max_days" id="sailing-lead-time-max" value="<?= htmlspecialchars((string) ($order['sailing_lead_time_max_days'] ?? '')) ?>" placeholder="e.g. 24">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Estimated Weight &amp; Volume <small class="muted">(shown on QT/PI — actuals confirmed later at Packing List)</small></legend>
      <div class="field-grid">
        <label>Total CBM (m&sup3;)<input type="text" name="estimated_total_cbm" value="<?= htmlspecialchars((string) ($order['estimated_total_cbm'] ?? '')) ?>"></label>
        <label>No. of Packages / Crates<input type="text" name="estimated_package_count" value="<?= htmlspecialchars((string) ($order['estimated_package_count'] ?? '')) ?>" placeholder="e.g. 45 crates"></label>
        <label>Gross Weight (kg)<input type="text" name="estimated_gross_weight_kg" value="<?= htmlspecialchars((string) ($order['estimated_gross_weight_kg'] ?? '')) ?>"></label>
        <label>Net Weight (kg)<input type="text" name="estimated_net_weight_kg" value="<?= htmlspecialchars((string) ($order['estimated_net_weight_kg'] ?? '')) ?>"></label>
        <label class="full">Package Type
          <select name="estimated_package_type">
            <?php foreach ($packageTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ((string) ($order['estimated_package_type'] ?? '') === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset id="freight-insurance-fieldset">
      <legend>Indicative Freight / Insurance <small class="muted">(only relevant when Incoterm is not FOB)</small></legend>
      <div class="field-grid">
        <label>Freight (indicative, <span class="muted">in <span id="freight-currency-unit"><?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?></span></span>)<input type="text" name="indicative_freight_amount" value="<?= htmlspecialchars((string) ($order['indicative_freight_amount'] ?? '')) ?>" placeholder="e.g., 950"></label>
        <label class="full">Insurance (indicative, <span class="muted">in <span id="insurance-currency-unit"><?= htmlspecialchars((string) ($order['currency_code'] ?? '')) ?></span></span>)<input type="text" name="indicative_insurance_amount" value="<?= htmlspecialchars((string) ($order['indicative_insurance_amount'] ?? '')) ?>" placeholder="e.g., 150"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Special Requirements/Instructions</legend>
      <textarea name="special_requirements" rows="2" placeholder="Write NIL if none"><?= htmlspecialchars($order['special_requirements'] ?? '') ?></textarea>
    </fieldset>

    <p class="muted small">
      Payment terms (advance/balance %, balance trigger, balance days) aren't edited here — use
      <a href="/orders/<?= (int) $order['id'] ?>/amendments">Amendments</a> for those.
    </p>

    <?php if ($isLocked): ?>
      <fieldset>
        <legend>Reason <small class="muted">(required — this order is locked)</small></legend>
        <textarea name="reason" rows="2" placeholder="Why does this locked order need to change? (e.g. correcting a genuine mistake found after closure)" required minlength="10"></textarea>
      </fieldset>
    <?php elseif ($isPostConfirmation): ?>
      <fieldset>
        <legend>Reason <small class="muted">(required — this order has already reached Order Confirmation)</small></legend>
        <textarea name="reason" rows="2" placeholder="Why is this changing now, after confirmation? (e.g. client requested a change)" required minlength="10"></textarea>
      </fieldset>
    <?php endif; ?>

    <button type="submit"><?= $isLocked ? 'Override Lock & Save' : ($isPostConfirmation ? 'Override & Save' : 'Save Changes') ?></button>
  </form>
  <?php endif; ?>
</div>
<script>
// 90-item backlog #67: reflect the currently-selected currency live next to
// Freight/Insurance, same as the New Order creation screen — this order
// already has a saved currency, so the labels start correct on load, but
// a staff member can still change the Currency dropdown here before saving.
function updateFreightCurrencyLabels(selectEl) {
  var opt = selectEl.options[selectEl.selectedIndex];
  var code = (opt && opt.getAttribute('data-code')) || 'order currency';
  var freightLabel = document.getElementById('freight-currency-unit');
  var insuranceLabel = document.getElementById('insurance-currency-unit');
  if (freightLabel) { freightLabel.textContent = code; }
  if (insuranceLabel) { insuranceLabel.textContent = code; }
}

// User report: Freight/Insurance is only ever relevant when the seller
// is on the hook for it (CFR/CIF etc.) — under FOB the buyer arranges
// and pays freight directly, so these fields have nothing to record.
// Hiding and disabling the whole <fieldset> (rather than each input)
// takes the section off the page and its inputs out of the form
// submission in one place. Matches the app's
// own is_fob rule everywhere else: strtoupper(incoterm_code) === 'FOB'.
function updateFreightFieldsAvailability(clearOnDisable) {
  var incotermSelect = document.getElementById('incoterm-select');
  var fieldset = document.getElementById('freight-insurance-fieldset');
  if (!incotermSelect || !fieldset) { return; }
  var opt = incotermSelect.options[incotermSelect.selectedIndex];
  var code = ((opt && opt.getAttribute('data-code')) || '').toUpperCase();
  var isFob = code === 'FOB';
  // Owner request: not shown at all for FOB (hidden), and disabled so its
  // inputs are left out of the submission.
  fieldset.hidden = isFob;
  fieldset.disabled = isFob;
  if (isFob && clearOnDisable) {
    var freightInput = fieldset.querySelector('input[name="indicative_freight_amount"]');
    var insuranceInput = fieldset.querySelector('input[name="indicative_insurance_amount"]');
    if (freightInput) { freightInput.value = ''; }
    if (insuranceInput) { insuranceInput.value = ''; }
  }
}
// Page load: just set the disabled state — toggling the incoterm back and
// forth without saving never wipes a value already in the fields. Saving
// the form while FOB is selected DOES clear them: disabled inputs aren't
// submitted, and the update stores a missing value as empty — intended,
// since FOB never prints freight/insurance anywhere.
updateFreightFieldsAvailability(false);

// 90-item backlog item 15: same auto-suggest as the New Order creation
// screen — offers, never forces, a sailing lead-time range once both
// ports are picked. Only fills in currently-blank fields.
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
</script>
