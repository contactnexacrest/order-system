<?php use App\Helpers\Csrf; $protected = (int) $preset['is_protected'] === 1; ?>
<div class="card">
  <p class="muted small"><a href="/payment-presets">&larr; Back to Payment Presets</a></p>
  <h1>Edit Payment Preset</h1>

  <?php if ($protected): ?>
    <div class="review-banner bad" style="margin-bottom:14px">
      &#128274; <span><strong>This preset is protected</strong> — it drives where money is actually sent/received, so it can't be edited until unlocked. Submit an unlock request via <a href="/admin/field-protection">Field Protection</a> first.</span>
    </div>
  <?php endif; ?>
  <?php if ($inUse): ?>
    <div class="review-banner warn" style="margin-bottom:14px">
      &#8505; <span>At least one order is currently assigned to this preset — changes here affect every document generated for those orders from now on.</span>
    </div>
  <?php endif; ?>

  <form method="post" action="/payment-presets/<?= (int) $preset['id'] ?>/update">
    <?= Csrf::field() ?>
    <fieldset <?= $protected ? 'disabled' : '' ?>>
      <legend>Preset</legend>
      <label>Preset Name *<input type="text" name="preset_name" required value="<?= htmlspecialchars($preset['preset_name']) ?>" placeholder="e.g. Trial Order — 20% Advance"></label>
      <label><input type="checkbox" name="is_default" value="1" <?= (int) $preset['is_default'] === 1 ? 'checked' : '' ?>> Default preset (pre-selected on the order-creation form)</label>
      <label><input type="checkbox" name="requires_md_approval" value="1" <?= (int) $preset['requires_md_approval'] === 1 ? 'checked' : '' ?>> Requires MD approval before an order can use this preset</label>
    </fieldset>

    <fieldset <?= $protected ? 'disabled' : '' ?>>
      <legend>Advance Payment</legend>
      <div class="field-grid">
        <label class="full">Advance Trigger Text *
          <input type="text" name="advance_trigger_text" required value="<?= htmlspecialchars($preset['advance_trigger_text']) ?>" placeholder="against Proforma Invoice before production commences">
        </label>
        <label>Advance % *
          <input type="number" name="advance_pct" step="0.01" min="0.01" max="99.99" required value="<?= htmlspecialchars((string) $preset['advance_pct']) ?>" placeholder="e.g., 40.00">
        </label>
      </div>
      <p class="muted small">Printed as "{advance_pct}% advance T/T on FOB Value {advance_trigger_text}" — this is the second half of that sentence.</p>
    </fieldset>

    <fieldset <?= $protected ? 'disabled' : '' ?>>
      <legend>Balance Payment</legend>
      <div class="field-grid">
        <label class="full">Balance Trigger Wording
          <textarea name="balance_trigger_wording" rows="3" placeholder="Payable before shipment — within {days} Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest."><?= htmlspecialchars($preset['balance_trigger_wording'] ?? '') ?></textarea>
        </label>
        <label>Balance % *
          <input type="number" name="balance_pct" step="0.01" min="0.01" max="99.99" required value="<?= htmlspecialchars((string) $preset['balance_pct']) ?>" placeholder="e.g., 60.00">
        </label>
        <label>Balance Trigger Option *
          <select name="balance_trigger_option" required>
            <?php foreach ($triggerOptions as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $preset['balance_trigger_option'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Balance Days *
          <input type="number" name="balance_days" min="1" required value="<?= (int) $preset['balance_days'] ?>" placeholder="e.g., 3">
        </label>
      </div>
      <p class="muted small">Advance % + Balance % must add up to 100.</p>
      <p class="muted small">Printed as-is on the Quotation, Proforma Invoice, and Buyer PO — the only 3 documents whose balance clause varies by preset. Must include the literal token <code>{days}</code>, substituted with Balance Days above. Leave blank to use the built-in default sentence for the Balance Trigger Option selected.</p>
    </fieldset>

    <fieldset <?= $protected ? 'disabled' : '' ?>>
      <legend>Currency</legend>
      <label>Currency *
        <select name="currency_id" required>
          <?php foreach ($currencies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $preset['currency_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>

    <button type="submit" <?= $protected ? 'disabled' : '' ?>>Save Changes</button>
  </form>
</div>
