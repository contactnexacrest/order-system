<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <h1>Edit Order Details — <?= htmlspecialchars($order['order_reference']) ?></h1>

  <?php if ($isPostConfirmation): ?>
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

  <?php if (!$isPostConfirmation || $canOverride): ?>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/edit">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Commercial Terms</legend>
      <label>Incoterm *
        <select name="incoterm_id" required>
          <?php foreach ($incoterms as $i): ?>
            <option value="<?= (int) $i['id'] ?>" <?= (int) $i['id'] === (int) $order['incoterm_id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Currency *
        <select name="currency_id" required>
          <?php foreach ($currencies as $cur): ?>
            <option value="<?= (int) $cur['id'] ?>" <?= (int) $cur['id'] === (int) $order['currency_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Certificate of Origin Type
        <select name="coo_type">
          <option value="">— TBC —</option>
          <?php foreach ($cooTypes as $o): ?>
            <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['option_value'] === $order['coo_type'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Buyer's Own PO Ref <small class="muted">("NIL" if none)</small>
        <input type="text" name="buyers_po_ref" value="<?= htmlspecialchars($order['buyers_po_ref'] ?? '') ?>">
      </label>
    </fieldset>

    <fieldset>
      <legend>Shipping</legend>
      <label>Port of Loading
        <select name="port_of_loading_id">
          <option value="">—</option>
          <?php foreach ($loadingPorts as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) ($order['port_of_loading_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Port of Discharge <small class="muted">(select if it's a repeat port, or type a new one below)</small>
        <select name="port_of_discharge_id">
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
          <option value="">— TBC —</option>
          <?php foreach ($containerTypes as $o): ?>
            <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= $o['option_value'] === $order['container_type'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Est. Lead Time <small class="muted">(free text, shown on the Quotation)</small>
        <input type="text" name="est_lead_time_text" value="<?= htmlspecialchars($order['est_lead_time_text'] ?? '') ?>">
      </label>
    </fieldset>

    <fieldset>
      <legend>Estimated Weight &amp; Volume <small class="muted">(shown on QT/PI — actuals confirmed later at Packing List)</small></legend>
      <label>Total CBM (m&sup3;)<input type="text" name="estimated_total_cbm" value="<?= htmlspecialchars((string) ($order['estimated_total_cbm'] ?? '')) ?>"></label>
      <label>Gross Weight (kg)<input type="text" name="estimated_gross_weight_kg" value="<?= htmlspecialchars((string) ($order['estimated_gross_weight_kg'] ?? '')) ?>"></label>
      <label>Net Weight (kg)<input type="text" name="estimated_net_weight_kg" value="<?= htmlspecialchars((string) ($order['estimated_net_weight_kg'] ?? '')) ?>"></label>
      <label>No. of Packages / Crates<input type="text" name="estimated_package_count" value="<?= htmlspecialchars((string) ($order['estimated_package_count'] ?? '')) ?>"></label>
      <label>Package Type<input type="text" name="estimated_package_type" value="<?= htmlspecialchars((string) ($order['estimated_package_type'] ?? '')) ?>"></label>
    </fieldset>

    <fieldset>
      <legend>Indicative Freight / Insurance <small class="muted">(only relevant when Incoterm is not FOB)</small></legend>
      <label>Freight — low<input type="text" name="indicative_freight_low" value="<?= htmlspecialchars((string) ($order['indicative_freight_low'] ?? '')) ?>"></label>
      <label>Freight — high<input type="text" name="indicative_freight_high" value="<?= htmlspecialchars((string) ($order['indicative_freight_high'] ?? '')) ?>"></label>
      <label>Insurance (indicative)<input type="text" name="indicative_insurance_amount" value="<?= htmlspecialchars((string) ($order['indicative_insurance_amount'] ?? '')) ?>"></label>
    </fieldset>

    <fieldset>
      <legend>Special Requirements</legend>
      <textarea name="special_requirements" rows="2"><?= htmlspecialchars($order['special_requirements'] ?? '') ?></textarea>
    </fieldset>

    <p class="muted small">
      Payment terms (advance/balance %, balance trigger, balance days) aren't edited here — use
      <a href="/orders/<?= (int) $order['id'] ?>/amendments">Amendments</a> for those.
    </p>

    <?php if ($isPostConfirmation): ?>
      <fieldset>
        <legend>Reason <small class="muted">(required — this order has already reached Order Confirmation)</small></legend>
        <textarea name="reason" rows="2" placeholder="Why is this changing now, after confirmation? (e.g. client requested a change)" required minlength="10"></textarea>
      </fieldset>
    <?php endif; ?>

    <button type="submit"><?= $isPostConfirmation ? 'Override & Save' : 'Save Changes' ?></button>
  </form>
  <?php endif; ?>
</div>
