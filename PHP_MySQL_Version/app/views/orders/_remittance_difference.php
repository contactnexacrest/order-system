<?php
/**
 * Owner decision (2 Nov 2026, "18 yes"): on every buyer remittance form, when
 * the amount differs from what is still due, the person recording it says
 * why. Follow-up the same day: the reason is chosen from a list Admin keeps
 * (Settings → Remittance difference reasons), each reason mapped to how the
 * difference is treated (bank charges / buyer paid less / buyer paid more),
 * with "Other — type the reason" as the last option. The browser offers only
 * the reasons that fit the amount typed (less → bank charges or short; more
 * → excess); the server checks the same.
 * Expects $rd = ['label' => 'Advance', 'due' => float, 'so_far' => float,
 * 'currency' => 'USD', 'max_pct' => float].
 */
$__rdCur = htmlspecialchars((string) $rd['currency']);
$__rdLabel = htmlspecialchars(strtolower((string) $rd['label']));
$__rdRemaining = max(0.0, round((float) $rd['due'] - (float) $rd['so_far'], 2));
$__rdReasons = \App\Services\RemittanceDifference::reasons();
$__rdGroups = [
    'bank_charges' => 'Bank charges deducted — counted as received in full; the charges become an order cost',
    'short' => 'Buyer paid less (short payment)',
    'excess' => 'Buyer paid more',
];
?>
<?php if ((float) $rd['due'] > 0): // Owner request (3 Nov 2026): read-only, system-calculated — what the buyer should pay now ?>
<p class="full to-pay-now"><strong>Buyer to pay now: <?= $__rdCur ?> <?= number_format($__rdRemaining, 2) ?></strong> <small class="muted">(<?= $__rdLabel ?> due <?= $__rdCur ?> <?= number_format((float) $rd['due'], 2) ?> − already received <?= $__rdCur ?> <?= number_format((float) $rd['so_far'], 2) ?>)</small></p>
<?php endif; ?>
<fieldset class="remit-diff" data-remit-diff data-due="<?= htmlspecialchars(number_format((float) $rd['due'], 2, '.', '')) ?>" data-remaining="<?= htmlspecialchars(number_format($__rdRemaining, 2, '.', '')) ?>" data-currency="<?= $__rdCur ?>" data-label="<?= $__rdLabel ?>" data-max-pct="<?= htmlspecialchars((string) $rd['max_pct']) ?>">
  <label>Why is it different? <small class="muted">(only when the amount differs from the <?= $__rdLabel ?> still due<?= (float) $rd['due'] > 0 ? ', ' . $__rdCur . ' ' . number_format($__rdRemaining, 2) : '' ?>)</small>
    <select name="difference_reason_choice" data-remit-reason>
      <option value="" data-none>Amount as due — no difference</option>
      <?php foreach ($__rdGroups as $__rdT => $__rdGroupLabel): ?>
        <optgroup label="<?= htmlspecialchars($__rdGroupLabel) ?>" data-treatment-group="<?= $__rdT ?>">
          <?php foreach ($__rdReasons as $__rdR): if ($__rdR['treatment'] !== $__rdT) { continue; } ?>
            <option value="<?= (int) $__rdR['id'] ?>" data-treatment="<?= $__rdT ?>"><?= htmlspecialchars($__rdR['label']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
      <option value="other" data-treatment="other">Other — type the reason</option>
    </select>
  </label>
  <div data-show-when="difference_reason_choice=other" class="remit-diff-other">
    <label>Reason in your own words * <input type="text" name="difference_reason_other" minlength="10" maxlength="255" required placeholder="e.g. Buyer's bank deducted a SWIFT fee and a courier charge"></label>
    <label>Treat it as *
      <select name="difference_treatment" required data-remit-treatment>
        <option value="">Choose…</option>
        <option value="bank_charges">Bank charges deducted by the banks</option>
        <option value="short">Buyer paid less (short payment)</option>
        <option value="excess">Buyer paid more</option>
      </select>
    </label>
  </div>
  <label data-remit-charges-wrap hidden style="display:none;">Bank charges <small class="muted">(in <?= $__rdCur ?> — filled with the difference; change it if the bank's advice shows another figure; at most <?= htmlspecialchars((string) $rd['max_pct']) ?>% of the amount due)</small><input type="number" step="0.01" min="0.01" name="bank_charges" data-remit-charges placeholder="e.g. 20.00" disabled></label>
  <p class="muted small" data-remit-diff-preview></p>
</fieldset>
<?php // Owner (2 Nov 2026): our own bank's fee, recorded with the receipt so nobody books it again later. ?>
<label>Our bank's charges on this credit (&#8377;, incl. GST) <small class="muted">(optional — the inward-remittance fee our bank debits separately, from the bank advice; booked as an order cost "Bank Charges" now)</small><input type="number" step="0.01" min="0.01" max="<?= htmlspecialchars(number_format(\App\Services\RemittanceDifference::MAX_OWN_BANK_CHARGES_INR, 2, '.', '')) ?>" name="own_bank_charges_inr" placeholder="e.g. 590.00"></label>
