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
    <tr><th>Line</th><?php if ($__fob): ?><th class="r">Invoiced FOB (<?= $__cur ?>)</th><?php endif; ?><th>Price check</th><th class="r">Commission (<?= $__cur ?>)</th></tr>
    <?php foreach ($c['lines'] as $l): ?>
      <tr><td><?= (int) $l['line_no'] ?> · <?= htmlspecialchars(mb_substr($l['description'], 0, 60)) ?></td><?php if ($__fob): ?><td class="r"><?= $__n($l['fob']) ?></td><?php endif; ?>
        <td><?= $l['in_range'] ? '<span class="badge badge-active">In range</span>' : '<span class="badge badge-pending">Below floor, approved — no commission</span>' ?></td><td class="r"><?= $__n($l['commission']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($c['lines'] === []): ?><tr><td colspan="4" class="muted">No goods lines yet.</td></tr><?php endif; ?>
    <tr><td><b>Total</b></td><?php if ($__fob): ?><td class="r"><b><?= $__n($c['goods_fc']) ?></b></td><?php endif; ?><td></td><td class="r"><b><?= $__n($c['amount_fc']) ?></b></td></tr>
  </table>
  <table class="kv" style="margin-top:8px">
    <?php if (($c['show_pct'] ?? true) && $c['level_label'] !== null): ?><tr><th>Commission level</th><td><?= htmlspecialchars($c['level_label']) ?><?= $c['level_fixed'] ? ' — fixed when the Proforma Invoice was issued' : ' — for now; fixed when the Proforma Invoice is issued' ?></td></tr><?php endif; ?>
    <?php if ($__fob && (float) $c['reduction_fc'] > 0): ?><tr><th>Agreed discount / settled claim</th><td>− <?= $__cur ?> <?= $__n($c['reduction_fc']) ?> (lowers the basis)</td></tr><?php endif; ?>
    <?php $__portal = !empty($c['portal']); // D-264: in the portal the order currency is the figure; rupees only for an associate in India, once fixed ?>
    <?php if ($__fob && !$__portal): ?><tr><th>Basis</th><td><?= $__cur ?> <?= $__n($c['basis_fc']) ?> × ₹<?= number_format((float) $c['rate_inr'], 4) ?><?= !empty($c['fixed']) ? ' fixed' : ($c['rate_estimated'] ? ' (estimated — rupees credited not recorded yet)' : ' realised') ?> = ₹<?= $__n($c['basis_inr']) ?></td></tr><?php elseif ($__fob): ?><tr><th>Basis</th><td><?= $__cur ?> <?= $__n($c['basis_fc']) ?></td></tr><?php endif; ?>
    <tr><th>Commission</th><td><b><?= $__cur ?> <?= $__n($c['amount_fc']) ?><?= $__portal ? '' : ' · ₹' . $__n($c['amount_inr']) ?></b><?= !empty($c['fixed']) ? ' <span class="badge badge-active">Fixed</span>' : ($c['state'] !== 'unpaid' ? ' <span class="badge badge-pending">Estimate</span>' : '') ?><?= $c['state'] === 'unpaid' ? ' — none: the buyer did not pay the order in full' : '' ?>
      <?php if ($__portal && !empty($c['fixed']) && \App\Services\AssociatePayoutService::isIndia($me ?? null) && $c['state'] !== 'unpaid'): ?><br><span data-fixed-rupees><?= htmlspecialchars(\App\Services\AssociatePayoutService::fixedRupeeLine((float) $c['amount_inr'], (float) $c['fixed_rate'], (string) $c['currency'], (string) $c['fixed_at'])) ?></span><?php endif; ?>
      <?php if (!$__portal && !empty($c['override'])): ?><br><small class="muted">Changed by the MD: <?= htmlspecialchars((string) $c['override_reason']) ?></small><?php endif; ?></td></tr>
    <tr><th>GST</th><td><?= trim($c['gstin']) !== '' ? 'GST ' . rtrim(rtrim(number_format((float) $c['gst_pct'], 2), '0'), '.') . '% added on top once your tax invoice is on file' : 'none (not GST-registered)' ?></td></tr>
    <tr><th>Full payment</th><td><?= $c['fully_paid_on'] ? $__d($c['fully_paid_on']) . ' ✓' : 'not yet' ?></td></tr>
    <tr><th>Due / payout run</th><td><?= $c['due_on'] ? 'due ' . $__d($c['due_on']) . ' · paid in the ' . $__d($c['payout_run_on']) . ' payout run' : 'once the buyer has paid in full' ?><?= $c['claim_hold'] ? ' — <b>held while a buyer claim is open</b>' : '' ?></td></tr>
    <?php // D-263 (owner 6 Nov 2026): in the associate portal only yes / no — never the running count of their orders nor the threshold. ?>
    <tr><th>Counts toward the ladder</th><td><?= $c['state'] !== 'paid' ? 'decided when the buyer has paid in full' : (!empty($c['portal']) ? ($c['counts'] ? 'yes' : 'no') : ($c['counts'] ? 'yes (counting order no. ' . (int) $c['counting_number'] . ')' : 'no — under ₹' . number_format((float) $c['threshold_inr'], 0) . ' within range')) ?></td></tr>
  </table>
  <p class="muted small">Basis is the final invoiced FOB of the goods only — no freight, insurance or services. Bank charges never reduce it; an agreed discount or settled claim does; an order not paid in full earns nothing.</p>
</div>
