<?php
/**
 * Owner decision D-255 (5 Nov 2026): one price request — the quote price and the
 * lowest the associate may agree, valid until a date, with what changed the price.
 * Never cost, margin or formula. Shown on screen only. Node twin: price_request.njk.
 */
use App\Helpers\View;
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
$__unit = (string) ($r['unit'] ?? '');
?>
<div class="card page-wide">
  <p class="muted small"><a href="/associate/catalogue">&larr; Catalogue</a></p>
  <h1><?= View::e($product['name'] ?? 'Product') ?></h1>
  <p class="muted">Price request <b><?= View::e($r['number']) ?></b> · <?= View::e($r['selection_text']) ?></p>
  <?php if ($status === 'pending'): ?>
    <div class="alert alert-warning">This is not a standard specification — our team will send the price here and by e-mail within 24 hours (by <?= View::e(substr((string) $r['due_at'], 0, 16)) ?>). It does not use one of your daily price requests.</div>
  <?php elseif ($r['quote'] !== null): ?>
    <p class="muted"><?= $status === 'expired' ? 'Expired on ' : 'Valid until ' ?><?= $__d($r['valid_until']) ?> · FOB Indian port, per <?= View::e($__unit) ?></p>
    <div class="field-grid">
      <div><div class="muted small">Quote price</div><div style="font-size:1.5em;font-weight:700">USD <?= number_format((float) $r['quote'], 2) ?><?= $__unit === 'CBM' ? ' / CBM' : '' ?></div></div>
      <div><div class="muted small">Lowest you may agree</div><div style="font-size:1.5em;font-weight:700">USD <?= number_format((float) $r['lowest'], 2) ?><?= $__unit === 'CBM' ? ' / CBM' : '' ?></div></div>
    </div>
    <?php if (!empty($breakdown['per_m2'])): ?><p class="muted small">≈ USD <?= number_format((float) $breakdown['per_m2'], 2) ?> per m² (<?= View::e((string) $breakdown['m2_per_cbm']) ?> m² in one CBM) — for comparison only; the order is in CBM.</p><?php endif; ?>
    <table class="list">
      <?php foreach ($breakdown['lines'] ?? [] as $l): ?><tr><td><?= View::e($l['label']) ?></td><td class="r"><?= View::e($l['change']) ?></td></tr><?php endforeach; ?>
    </table>
    <?php foreach ($breakdown['charges'] ?? [] as $c): ?><p class="small">+ <?= View::e($c) ?></p><?php endforeach; ?>
    <p class="small muted">Your commission<?= !empty($r['level_name']) ? ' (' . View::e((string) $r['level_name']) . ')' : '' ?> is the same anywhere between the two prices. Prices are confirmed on the Proforma Invoice. <?= (int) $left ?> price requests left today.</p>
  <?php else: ?>
    <p class="muted">This request was not priced.</p>
  <?php endif; ?>
</div>
