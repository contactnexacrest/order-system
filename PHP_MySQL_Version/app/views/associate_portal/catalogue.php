<?php
/**
 * Owner decision D-255 (5 Nov 2026): the associate's catalogue — the products they
 * may offer, with no prices, supplier names or quarry locations. "Get price" asks
 * for a price for one product and specification. Node twin: catalogue.njk.
 */
use App\Helpers\View;
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <h1>Catalogue</h1>
  <p class="muted">Products you can offer. Prices on request · <b><?= (int) $left ?> of <?= (int) $limit ?></b> price requests left today.</p>
  <table class="list">
    <tr><th>Product</th><th>Forms</th><th>Finishes</th><th></th></tr>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><b><?= View::e($p['name']) ?></b><?php if ($p['description'] !== ''): ?><br><small class="muted"><?= View::e(mb_substr($p['description'], 0, 160)) ?></small><?php endif; ?></td>
        <td><?= View::e($p['forms'] !== [] ? implode(', ', $p['forms']) : '—') ?></td>
        <td><?= View::e($p['finishes'] !== [] ? implode(', ', $p['finishes']) : '—') ?></td>
        <td><?php if (!empty($access['get_price'])): // D-261 ?><a class="btn-sm" href="/associate/catalogue/<?= (int) $p['id'] ?>">Get price</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($products === []): ?><tr><td colspan="4" class="muted">No products are open to you yet — NexaCrest chooses which categories you can offer.</td></tr><?php endif; ?>
  </table>
  <p class="small muted">No prices, supplier names or quarry locations are shown here. Product descriptions can be shared with buyers.</p>

  <h2>Your price requests</h2>
  <table class="list">
    <tr><th>No.</th><th>Product · specification</th><th class="r">Quote · lowest</th><th>Status</th></tr>
    <?php foreach ($requests as $r): ?>
      <?php $__st = \App\Services\PriceRequestService::displayStatus($r); ?>
      <tr>
        <td><a href="/associate/price-requests/<?= (int) $r['id'] ?>"><?= View::e($r['number']) ?></a></td>
        <td><?= View::e($r['product_name']) ?> · <?= View::e($r['selection_text']) ?></td>
        <td class="r"><?= $r['quote'] !== null ? number_format((float) $r['quote'], 2) . ' · ' . number_format((float) $r['lowest'], 2) . ' <small class="muted">USD/' . View::e((string) $r['unit']) . '</small>' : '—' ?></td>
        <td><?= ['valid' => 'Valid until ' . $__d($r['valid_until']), 'priced' => 'Priced — valid until ' . $__d($r['valid_until']), 'pending' => 'With our team', 'ordered' => 'Became an order', 'expired' => 'Expired', 'refused' => 'Not priced'][$__st] ?? View::e($__st) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($requests === []): ?><tr><td colspan="4" class="muted">No price requests yet.</td></tr><?php endif; ?>
  </table>
</div>
