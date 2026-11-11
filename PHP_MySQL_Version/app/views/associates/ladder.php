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
        <label>Days after full payment *<input type="number" name="associate_commission_due_days" required min="0" max="365" step="1" value="<?= (int) $commission['due_days'] ?>" placeholder="e.g. 30"<?= $__dis ?>><span class="help-line">Example: 30 → buyer pays in full on 4 Nov, commission due on 4 Dec.</span></label>
        <label>Payment run *<select name="associate_payout_run" required<?= $__dis ?>><option value="">Choose…</option><option value="monthly"<?= $commission['run'] === 'monthly' ? ' selected' : '' ?>>Monthly</option><option value="weekly"<?= $commission['run'] === 'weekly' ? ' selected' : '' ?>>Weekly</option></select><span class="help-line">Example: monthly → commission due on 4 Dec is paid in the run on 31 Dec (the first run on or after the due date).</span></label>
        <?php // D-292 (owner 11 Nov 2026): monthly 1st–28th or the last day of the month; weekly Monday–Sunday. ?>
        <label>Pay on (monthly run)<select name="associate_payout_day" data-required-when="associate_payout_run=monthly" data-required-when-message="Choose the day of the month."<?= $__dis ?>><option value="">Choose…</option>
          <?php for ($__i = 1; $__i <= 28; $__i++): ?><option value="<?= $__i ?>"<?= $commission['run'] === 'monthly' && !$commission['last_day'] && (int) $commission['day'] === $__i ? ' selected' : '' ?>><?= \App\Services\AssociateCommission::ordinal($__i) ?> of the month</option><?php endfor; ?>
          <option value="last"<?= $commission['run'] === 'monthly' && $commission['last_day'] ? ' selected' : '' ?>>Last day of the month</option></select>
          <span class="help-line">Choices: 1st … 28th of the month, or "Last day of the month" (28/29/30/31 as the month has).</span></label>
        <label>Pay on (weekly run)<select name="associate_payout_weekday" data-required-when="associate_payout_run=weekly" data-required-when-message="Choose the day of the week."<?= $__dis ?>><option value="">Choose…</option>
          <?php foreach (\App\Services\AssociateCommission::WEEKDAYS as $__n => $__w): ?><option value="<?= $__n ?>"<?= $commission['run'] === 'weekly' && (int) $commission['day'] === $__n ? ' selected' : '' ?>><?= $__w ?></option><?php endforeach; ?></select></label>
        <label>If the payment day is a Sunday or holiday *<select name="associate_payout_holiday_rule" required<?= $__dis ?>><option value="">Choose…</option>
          <option value="before"<?= $commission['holiday_rule'] === 'before' ? ' selected' : '' ?>>Pay on the working day before</option>
          <option value="after"<?= $commission['holiday_rule'] === 'after' ? ' selected' : '' ?>>Pay on the working day after</option></select>
          <span class="help-line">Uses the <a href="/holidays">Holiday Calendar</a>. The associate portal and e-mails always show the actual date (e.g. "31 Dec 2026").</span></label>
        <label>Reason for changing the payment run <small class="muted">(needed when the run, the day or the Sunday/holiday rule changes; kept in the Audit Log)</small><input type="text" name="payout_reason" minlength="10" maxlength="300" placeholder="e.g. Finance pays all vendors on the last working day"<?= $__dis ?>></label>
        <label><input type="checkbox" name="associate_claim_hold" value="1"<?= $commission['claim_hold'] ? ' checked' : '' ?><?= $__dis ?>> Hold while a buyer claim (raised on or before the due date) is open</label>
        <label>Commission period shown to associates *<select name="associate_portal_commission_months" required<?= $__dis ?>><option value="">Choose…</option><?php foreach (\App\Services\AssociateCommission::PORTAL_MONTH_CHOICES as $__m): ?><option value="<?= $__m ?>"<?= (int) $commission['portal_months'] === $__m ? ' selected' : '' ?>><?= $__m ?> months</option><?php endforeach; ?></select><span class="help-line">Example: 12 → in the portal an associate sees only the commission of the last 12 months (totals and list); commission still waiting to be paid is always shown. Staff screens show everything.</span></label>
      </div>
      <div class="field-grid">
        <label>Bank charges of a transfer abroad *<select name="associate_abroad_charges" required<?= $__dis ?>><option value="">Choose…</option><option value="OUR"<?= $commission['abroad_charges'] === 'OUR' ? ' selected' : '' ?>>OUR — NexaCrest pays its own and the intermediary bank's charges (the associate receives the full amount)</option><option value="SHA"<?= $commission['abroad_charges'] === 'SHA' ? ' selected' : '' ?>>SHA — NexaCrest pays its own bank's charges; the associate's bank may charge on receipt</option></select><span class="help-line">D-264: bank charges are never deducted from commission. In India NexaCrest pays the (small) charges.</span></label>
        <label>Minimum payout abroad (USD) *<input type="number" name="associate_abroad_min_payout_usd" required min="0" max="100000" step="0.01" value="<?= htmlspecialchars(number_format((float) $commission['abroad_min_usd'], 2, '.', '')) ?>" placeholder="e.g. 100.00"<?= $__dis ?>><span class="help-line">Example: 100 → an associate abroad with only USD 60 due in a run is paid in the next run, so one transfer's charges are not spent on a small amount.</span></label>
      </div>
      <p class="small muted">After payment a commission is never taken back, except for the associate's fraud or breach of the agreement — with a written reason.</p>
    </fieldset>
    <fieldset><legend>Ladder and registration <?= $canPricing ? '' : '<small class="muted">(the MD changes these)</small>' ?></legend>
      <div class="field-grid">
        <label>Counting order: invoiced FOB at least (₹) *<input type="number" name="associate_counting_threshold_inr" required min="0" step="1" value="<?= htmlspecialchars(number_format((float) $commission['threshold_inr'], 0, '.', '')) ?>" placeholder="e.g. 500000"<?= $__dis ?>><span class="help-line">Example: ₹5,00,000 → an order of ₹6 lakh counts toward the next ladder step; one of ₹2 lakh earns the current rate but does not count.</span></label>
        <label>Buyer registration valid (months) *<input type="number" name="associate_protection_months" required min="1" max="60" step="1" value="<?= (int) $commission['registration_months'] ?>" placeholder="e.g. 6"<?= $__dis ?>><span class="help-line">Example: 6 → a buyer registered on 1 Jan who has not ordered by 1 Jul is no longer linked to the associate.</span></label>
        <label>GST on commission (%) * <small class="muted">(GST-registered associates, once their tax invoice is on file)</small><input type="number" name="associate_gst_pct" required min="0" max="28" step="0.01" value="<?= htmlspecialchars(number_format((float) $commission['gst_pct'], 2, '.', '')) ?>" placeholder="e.g. 18.00"<?= $__dis ?>><span class="help-line">Example: 18 → commission ₹10,000 + GST ₹1,800 when the associate is GST-registered and their tax invoice is on file.</span></label>
        <label>Zoho GST tax for commission <small class="muted">(the Zoho Books tax id booked on the associate's bill when GST is paid on top; empty = the sync refuses such a bill)</small><input type="text" name="zoho_gst_commission_tax_id" maxlength="40" pattern="[0-9A-Za-z_\-]*" value="<?= htmlspecialchars((string) $commission['zoho_gst_tax_id']) ?>" placeholder="e.g. 460000000012345"<?= $__dis ?>><span class="help-line">Example: an associate's tax invoice of ₹10,000 + GST ₹1,800 → the Zoho bill shows ₹11,800 with this GST tax, so the bill matches the payment and the ₹1,800 input credit is booked.</span></label>
        <label>Zoho account for associate payout costs <small class="muted">(D-264: the Zoho Books account id the exchange difference and transfer charges of each associate payout are booked to, against the bank; empty = they stay in the app only)</small><input type="text" name="zoho_payout_costs_account_id" maxlength="40" pattern="[0-9A-Za-z_\-]*" value="<?= htmlspecialchars((string) $commission['zoho_payout_costs_account_id']) ?>" placeholder="e.g. 460000000067890"<?= $__dis ?>><span class="help-line">Example: USD 1,250.00 sent abroad cost ₹1,05,125.00 + ₹1,850.00 charges against the fixed ₹1,04,312.50 → one Zoho journal of ₹2,662.50, Associate payout costs debited, the bank credited.</span></label>
      </div>
    </fieldset>
    <fieldset><legend>Personal code and portal access <?= $canPricing ? '' : '<small class="muted">(the MD changes these)</small>' ?></legend>
      <div class="field-grid">
        <?php // Owner decision 11 Nov 2026 (P4): the two settings that were only in the database are editable here. ?>
        <label>Personal code changes allowed per year *<input type="number" name="associate_code_changes_per_year" required min="0" max="12" step="1" value="<?= (int) $codeChanges ?>" placeholder="e.g. 3"<?= $__dis ?>><span class="help-line">Example: 3 → an associate may choose a new personal code up to 3 times in 12 months (D-254).</span></label>
        <label>Suggest "Quoting" access after this many paid orders *<input type="number" name="associate_upgrade_after_orders" required min="1" max="50" step="1" value="<?= (int) $upgradeAfter ?>" placeholder="e.g. 3"<?= $__dis ?>><span class="help-line">Example: 3 → after a Starter associate's 3rd completed, paid order the MD is asked "Ready for Quoting?" (D-261).</span></label>
      </div>
    </fieldset>
    <fieldset><legend>Bank details (associates and suppliers)</legend>
      <?php // D-293 (owner 11 Nov 2026): who checks new bank details and how long before a verified account is used. ?>
      <div class="field-grid">
        <label>Bank details check *<select name="bank_details_check_people" required><option value="">Choose…</option>
          <option value="one"<?= $bankPeople === 'one' ? ' selected' : '' ?>>One person (not the one who entered them)</option>
          <option value="two"<?= $bankPeople === 'two' ? ' selected' : '' ?>>Two people</option></select>
          <span class="help-line">"Two people" — for later, when the finance team is bigger. Either way the holder confirms first, and MD/ED may verify their own entry with a reason (Admin › Self-approval rules › Bank details).</span></label>
        <label>Wait before a FIRST bank account is used (days) *<input type="number" name="associate_bank_wait_first_days" required min="0" max="90" step="1" value="<?= (int) $waitFirst ?>" placeholder="e.g. 0"></label>
        <label>Wait before a CHANGED bank account is used (days) *<input type="number" name="associate_bank_wait_change_days" required min="0" max="90" step="1" value="<?= (int) $waitChange ?>" placeholder="e.g. 15"><span class="help-line">Example: 15 → details verified on 1 Dec are used from 16 Dec; until then payments go to the old account.</span></label>
      </div>
    </fieldset>
    <div class="field-grid">
      <label>TDS on commission, India (section 194H) % *<input type="number" name="associate_tds_pct" required min="0" max="30" step="0.01" value="<?= htmlspecialchars(number_format($tds, 2, '.', '')) ?>" placeholder="e.g. 2.00"></label> <small class="muted">Threshold, rate without PAN and the rules for associates abroad: <a href="/settings/associate-tds">Settings &rarr; Associate TDS</a>.</small>
    </div>
    <div class="btn-row"><button type="submit">Save</button> <a href="/associates">Back to associates</a></div>
  </form>
</div>
