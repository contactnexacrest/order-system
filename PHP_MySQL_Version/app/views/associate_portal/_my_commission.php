<?php
/**
 * Owner decisions 11 Nov 2026, point 16 (D-292): what the associate sees about their commission — ONLY
 * the current % ("x% of the FOB value of each order from the clients you introduced") and a 4-step
 * "How it works". Never a level, the ladder, counts or the next step. Expects $me, $headline
 * (AssociatePortalController::commissionHeadline), $commissionSettings. Node twin: _my_commission.njk.
 */
use App\Services\AssociateCommission;

$__cur = ['INR' => 'Indian rupees', 'USD' => 'US dollars', 'EUR' => 'euros', 'GBP' => 'pounds sterling'][(string) ($me['currency_code'] ?? 'INR')] ?? (string) ($me['currency_code'] ?? '');
?>
<?php if ($headline !== null): ?>
  <div class="alert alert-info" data-commission-headline>
    <span class="stat-value" style="font-size:2.2em"><?= htmlspecialchars($headline['rate']) ?></span> <b><?= htmlspecialchars($headline['text']) ?></b><br>
    <small class="muted">This is the % for your next order. Each order keeps the % it was given when its Proforma Invoice was sent.</small>
  </div>
<?php else: ?>
  <p>Your commission is as set out in your agreement.</p>
<?php endif; ?>
<div data-how-it-works>
  <h3>How it works</h3>
  <ol>
    <li>Your % is <b>fixed for an order</b> when we send the buyer its Proforma Invoice — it does not change later.</li>
    <li>It is worked out on the <b>FOB value on the Commercial Invoice</b>.</li>
    <li>It becomes yours when the buyer has <b>paid in full</b>.</li>
    <li>We pay it <b><?= (int) $commissionSettings['due_days'] ?> days later</b>, in our <b><?= htmlspecialchars(AssociateCommission::payDayText($commissionSettings)) ?></b>, in <?= htmlspecialchars($__cur) ?> to your verified bank account.</li>
  </ol>
  <?php if (trim((string) ($me['gstin'] ?? '')) !== ''): ?><p class="muted small">GST-registered? We add GST on top once your tax invoice is uploaded.</p><?php endif; ?>
</div>
