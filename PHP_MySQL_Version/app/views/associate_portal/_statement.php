<?php
/**
 * Owner decision D-245 P3: the commission statement of one order — the invoiced FOB of
 * the goods line by line, the level, the rupee basis at the realised rate, GST and TDS,
 * and when it is due and paid. Shown to the associate (portal) and to staff with
 * "Manage associates". Expects $c = AssociateCommission::forOrder().
 */
use App\Services\AssociateCommission;
$__cur = htmlspecialchars((string) $c['currency']);
$__n = static fn ($v): string => number_format((float) $v, 2);
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="statement">
  <table class="list">
    <?php $__fob = $c['show_fob'] ?? true; // D-261: the associate portal hides the FOB value unless their access level shows it ?>
    <tr><th>Line</th><?php if ($__fob): ?><th class="r">Invoice value of the goods (<?= $__cur ?>)</th><?php endif; ?><th>Price within range?</th><th class="r">Commission (<?= $__cur ?>)</th></tr>
    <?php foreach ($c['lines'] as $l): ?>
      <tr><td><?= (int) $l['line_no'] ?> · <?= htmlspecialchars(mb_substr($l['description'], 0, 60)) ?></td><?php if ($__fob): ?><td class="r"><?= $__n($l['fob']) ?></td><?php endif; ?>
        <td><?= $l['in_range'] ? '<span class="badge badge-active">Yes</span>' : '<span class="badge badge-pending">Sold below the lowest price (we agreed to it) — no commission on this line</span>' ?></td><td class="r"><?= $__n($l['commission']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($c['lines'] === []): ?><tr><td colspan="4" class="muted">No products on this order yet.</td></tr><?php endif; ?>
    <tr><td><b>Total</b></td><?php if ($__fob): ?><td class="r"><b><?= $__n($c['goods_fc']) ?></b></td><?php endif; ?><td></td><td class="r"><b><?= $__n($c['amount_fc']) ?></b></td></tr>
  </table>
  <table class="kv" style="margin-top:8px">
    <?php if (($c['show_pct'] ?? true) && $c['level_label'] !== null): ?><tr><th>Your commission rate</th><td><?= htmlspecialchars($c['level_label']) ?><?= $c['level_fixed'] ? ' — final since we sent the buyer the proforma invoice' : ' — for now; final when we send the buyer the proforma invoice' ?></td></tr><?php endif; ?>
    <?php if ($__fob && (float) $c['reduction_fc'] > 0): ?><tr><th>Agreed discount or settled claim</th><td>− <?= $__cur ?> <?= $__n($c['reduction_fc']) ?> (this lowers the amount we work your commission out on)</td></tr><?php endif; ?>
    <?php $__portal = !empty($c['portal']); // D-264: in the portal the order currency is the figure; rupees only for an associate in India, once fixed ?>
    <?php if ($__fob && !$__portal): ?><tr><th>Basis</th><td><?= $__cur ?> <?= $__n($c['basis_fc']) ?> × ₹<?= number_format((float) $c['rate_inr'], 4) ?><?= !empty($c['fixed']) ? ' fixed' : ($c['rate_estimated'] ? ' (estimated — rupees credited not recorded yet)' : ' realised') ?> = ₹<?= $__n($c['basis_inr']) ?></td></tr><?php elseif ($__fob): ?><tr><th>Commission worked out on</th><td><?= $__cur ?> <?= $__n($c['basis_fc']) ?></td></tr><?php endif; ?>
    <tr><th>Commission</th><td><b><?= $__cur ?> <?= $__n($c['amount_fc']) ?><?= $__portal ? '' : ' · ₹' . $__n($c['amount_inr']) ?></b><?= !empty($c['fixed']) ? ' <span class="badge badge-active">Fixed</span>' : ($c['state'] !== 'unpaid' ? ' <span class="badge badge-pending">Estimate</span>' : '') ?><?= $c['state'] === 'unpaid' ? ' — no commission, because the buyer did not pay the full order' : '' ?>
      <?php if ($__portal && !empty($c['fixed']) && \App\Services\AssociatePayoutService::isIndia($me ?? null) && $c['state'] !== 'unpaid'): ?><br><span data-fixed-rupees><?= htmlspecialchars(\App\Services\AssociatePayoutService::fixedRupeeLine((float) $c['amount_inr'], (float) $c['fixed_rate'], (string) $c['currency'], (string) $c['fixed_at'])) ?></span><?php endif; ?>
      <?php if (!$__portal && !empty($c['override'])): ?><br><small class="muted">Changed by the MD: <?= htmlspecialchars((string) $c['override_reason']) ?></small><?php endif; ?></td></tr>
    <tr><th>GST</th><td><?= trim($c['gstin']) !== '' ? 'We add GST of ' . rtrim(rtrim(number_format((float) $c['gst_pct'], 2), '0'), '.') . '% when we have your tax invoice' : 'No GST (you are not registered for GST)' ?></td></tr>
    <tr><th>Full payment</th><td><?= $c['fully_paid_on'] ? $__d($c['fully_paid_on']) . ' ✓' : 'not yet' ?></td></tr>
    <tr><th>When we pay</th><td><?= $c['due_on'] ? 'due ' . $__d($c['due_on']) . ', paid on ' . $__d($c['payout_run_on']) : 'after the buyer has paid in full' ?><?= $c['claim_hold'] ? ' — <b>on hold while the buyer has an open claim</b>' : '' ?></td></tr>
    <?php // D-263 (owner 6 Nov 2026): in the associate portal only yes / no — never the running count of their orders nor the threshold. ?>
    <tr><th><?= !empty($c['portal']) ? 'Counts toward your next commission rate' : 'Counts toward the ladder' ?></th><td><?= $c['state'] !== 'paid' ? 'we will know when the buyer has paid in full' : (!empty($c['portal']) ? ($c['counts'] ? 'yes' : 'no') : ($c['counts'] ? 'yes (counting order no. ' . (int) $c['counting_number'] . ')' : 'no — under ₹' . number_format((float) $c['threshold_inr'], 0) . ' within range')) ?></td></tr>
  </table>
  <p class="muted small">We work out your commission on the final invoice value of the goods only. Freight, insurance and services are not included. Bank charges do not lower it. An agreed discount or a settled claim does lower it. If the buyer does not pay the order in full, there is no commission.</p>
</div>
