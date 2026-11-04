<?php
/**
 * Owner decision D-245 P3: associate commission settings. When commission is paid,
 * the counting-order threshold, buyer registration and GST are the MD's (manage_pricing);
 * TDS and the bank waiting days as before. Levels and the ladder: Admin → Pricing.
 */
use App\Helpers\Csrf;
$__dis = $canPricing ? '' : ' disabled';
?>
<div class="card">
  <h1>Associates — commission settings</h1>
  <p class="muted">Commission is worked out on the final invoiced FOB of the goods, line by line, at the commission level fixed on each order when its Proforma Invoice is issued, and only once the buyer has paid the order in full. The levels and the ladder are set in <a href="/pricing/commission-levels">Admin &rarr; Pricing &rarr; Commission levels</a>.</p>
  <form method="post" action="/associates/ladder">
    <?= Csrf::field() ?>
    <fieldset><legend>When commission is paid <?= $canPricing ? '' : '<small class="muted">(the MD changes these)</small>' ?></legend>
      <div class="field-grid">
        <label>Days after full payment *<input type="number" name="associate_commission_due_days" required min="0" max="365" step="1" value="<?= (int) $commission['due_days'] ?>" placeholder="e.g. 30"<?= $__dis ?>></label>
        <label>Payout run *<select name="associate_payout_run" required<?= $__dis ?>><option value="">Choose…</option><option value="monthly"<?= $commission['run'] === 'monthly' ? ' selected' : '' ?>>Monthly</option><option value="weekly"<?= $commission['run'] === 'weekly' ? ' selected' : '' ?>>Weekly</option></select></label>
        <label>Payout day * <small class="muted">(1–28 for monthly; 1 = Monday … 7 = Sunday for weekly)</small><input type="number" name="associate_payout_day" required min="1" max="28" step="1" value="<?= (int) $commission['day'] ?>" placeholder="e.g. 5"<?= $__dis ?>></label>
        <label><input type="checkbox" name="associate_claim_hold" value="1"<?= $commission['claim_hold'] ? ' checked' : '' ?><?= $__dis ?>> Hold while a buyer claim (raised on or before the due date) is open</label>
      </div>
      <p class="small muted">After payment a commission is never taken back, except for the associate's fraud or breach of the agreement — with a written reason.</p>
    </fieldset>
    <fieldset><legend>Ladder and registration <?= $canPricing ? '' : '<small class="muted">(the MD changes these)</small>' ?></legend>
      <div class="field-grid">
        <label>Counting order: invoiced FOB at least (₹) *<input type="number" name="associate_counting_threshold_inr" required min="0" step="1" value="<?= htmlspecialchars(number_format((float) $commission['threshold_inr'], 0, '.', '')) ?>" placeholder="e.g. 500000"<?= $__dis ?>></label>
        <label>Buyer registration valid (months) *<input type="number" name="associate_protection_months" required min="1" max="60" step="1" value="<?= (int) $commission['registration_months'] ?>" placeholder="e.g. 6"<?= $__dis ?>></label>
        <label>GST on commission (%) * <small class="muted">(GST-registered associates, once their tax invoice is on file)</small><input type="number" name="associate_gst_pct" required min="0" max="28" step="0.01" value="<?= htmlspecialchars(number_format((float) $commission['gst_pct'], 2, '.', '')) ?>" placeholder="e.g. 18.00"<?= $__dis ?>></label>
      </div>
    </fieldset>
    <div class="field-grid">
      <label>TDS on commission, India (section 194H) % *<input type="number" name="associate_tds_pct" required min="0" max="30" step="0.01" value="<?= htmlspecialchars(number_format($tds, 2, '.', '')) ?>" placeholder="e.g. 2.00"></label> <small class="muted">Threshold, rate without PAN and the rules for associates abroad: <a href="/settings/associate-tds">Settings &rarr; Associate TDS</a>.</small>
      <label>Wait before a new bank account is used (days) *<input type="number" name="associate_bank_wait_days" required min="0" max="60" step="1" value="<?= (int) $wait ?>" placeholder="e.g. 7"></label>
    </div>
    <div class="btn-row"><button type="submit">Save</button> <a href="/associates">Back to associates</a></div>
  </form>
</div>
