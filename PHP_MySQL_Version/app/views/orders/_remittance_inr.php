<?php
/**
 * Owner decision D-244 (4 Nov 2026, "C yes"): a buyer remittance is recorded
 * with the rupees the bank actually credited; the exchange rate is worked out
 * from them (₹ ÷ amount) and shown here live, read-only. The rate printed on
 * the bank's advice is optional: when it and the INR differ by more than ₹1
 * the person recording says why (the bank deducted its charges / a typo /
 * another reason) — the browser asks here and the server checks the same.
 * "INR not known yet" brings back the typed rate; the INR is then entered
 * later with the INR-actual box.
 * Expects $ri = ['prefix' => 'advance_' (or '' on a milestone form), 'currency' => 'USD'].
 */
$__riP = (string) $ri['prefix'];
$__riCur = htmlspecialchars((string) $ri['currency']);
?>
<fieldset class="remit-inr" data-remit-inr data-currency="<?= $__riCur ?>" data-tolerance="<?= htmlspecialchars(number_format(\App\Services\RemittanceInr::TOLERANCE_INR, 2, '.', '')) ?>">
  <div data-remit-inr-known>
    <label>INR credited by the bank (&#8377;) * <small class="muted">(the rupees that reached our account, from the bank's credit advice)</small><input type="number" step="0.01" min="0.01" max="999999999999.99" name="<?= $__riP ?>inr_credited" data-remit-inr-credited required data-refuse-when="rate_gap_choice=typo" data-refuse-message="You said a figure is wrong: correct the amount received, the INR credited or the bank's rate — the question goes away once they agree (within &#8377;1)." placeholder="e.g. 149850.00"></label>
    <p class="remit-derived">Exchange rate (worked out: &#8377; &divide; <?= $__riCur ?>): <strong data-remit-rate-derived>—</strong></p>
    <label>Bank's rate on the advice <small class="muted">(optional — &#8377; per 1 <?= $__riCur ?>, as printed on the bank's advice; it checks the INR credited)</small><input type="number" step="0.0001" min="0.0001" max="999999.9999" name="<?= $__riP ?>bank_rate" data-remit-bank-rate placeholder="e.g. 83.2500"></label>
    <div class="remit-gap" data-remit-gap hidden style="display:none;">
      <p class="remit-gap-note" data-remit-gap-text></p>
      <label>Why is the INR different from the bank's rate? *
        <select name="rate_gap_choice" data-remit-gap-choice required disabled>
          <option value="">Choose…</option>
          <?php foreach (\App\Services\RemittanceInr::CHOICES as $__riV => $__riL): ?>
            <option value="<?= $__riV ?>"><?= htmlspecialchars($__riL) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label data-remit-gap-reason-wrap hidden style="display:none;">Reason * <input type="text" name="rate_gap_reason" minlength="10" maxlength="255" required disabled placeholder="e.g. The bank applied the previous day's card rate"></label>
    </div>
  </div>
  <label class="checkbox-inline"><input type="checkbox" name="<?= $__riP ?>inr_unknown" value="1" data-remit-inr-unknown> INR not known yet — enter the rate</label>
  <label data-remit-rate-typed-wrap hidden style="display:none;">Exchange rate * <small class="muted">(&#8377; per 1 <?= $__riCur ?> — the INR is entered later in the "INR actual" box)</small><input type="number" step="0.0001" min="0.0001" max="999999.9999" name="<?= $__riP ?>exchange_rate" data-remit-rate-typed required disabled placeholder="e.g. 83.2500"></label>
</fieldset>
