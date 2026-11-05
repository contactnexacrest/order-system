<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Services\PriceRequestService;

/* Owner decision D-255 (5 Nov 2026): the staff queue of associate price requests and the 90-day report. */
$__dt = static fn ($v): string => $v ? Dates::short(substr((string) $v, 0, 10)) . ' ' . substr((string) $v, 11, 5) : '—';
$__d = static fn ($v): string => $v ? Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Price requests</h1>
      <p class="muted" style="margin:0">Prices associates asked for in their portal. Automatic ones are priced by the system from the specifications; a combination with no standard price comes here to be priced by hand within 24 hours.</p>
    </div>
    <div class="btn-row" style="margin-top:0"><a class="btn-sm btn-secondary" href="/associates">All associates</a></div>
  </div>

  <p><?php foreach (['all' => 'All', 'pending' => 'Needs a price', 'manual' => 'Manual', 'auto' => 'Automatic'] as $__f => $__l): ?><a class="btn-sm <?= $filter === $__f ? '' : 'btn-secondary' ?>" href="/associates/price-requests?show=<?= $__f ?>"><?= $__l ?></a> <?php endforeach; ?></p>

  <table class="list" id="price-requests">
    <tr><th>No.</th><th>Associate</th><th>Product · specification</th><th>How</th><th class="num">Quote · lowest (USD)</th><th>Status</th></tr>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No price requests yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $__st = PriceRequestService::displayStatus($r); ?>
      <tr>
        <td><?= htmlspecialchars((string) $r['number']) ?><br><small class="muted"><?= $__dt($r['requested_at']) ?></small></td>
        <td><a href="/associates/<?= (int) $r['associate_id'] ?>"><?= htmlspecialchars((string) $r['associate_name']) ?></a></td>
        <td><?= htmlspecialchars((string) $r['product_name']) ?> · <?= htmlspecialchars((string) $r['selection_text']) ?>
          <?php if (!empty($r['other_note'])): ?><br><small class="muted">Asked: <?= htmlspecialchars((string) $r['other_note']) ?></small><?php endif; ?></td>
        <td><?= $r['kind'] === 'manual' ? '<span class="badge badge-warning">Needs a manual price</span>' : '<span class="badge badge-ok">Automatic</span>' ?></td>
        <td class="num"><?= $r['quote'] !== null ? number_format((float) $r['quote'], 2) . ' · ' . number_format((float) $r['lowest'], 2) . ' <small class="muted">per ' . htmlspecialchars((string) $r['unit']) . '</small>' : '—' ?></td>
        <td>
          <?php if ($__st === 'pending'): ?>
            <span class="muted small">due <?= $__dt($r['due_at']) ?></span>
            <?php if ($canPrice): ?>
              <details><summary class="btn-sm">Price it</summary>
                <form method="post" action="/associates/price-requests/<?= (int) $r['id'] ?>/price" class="inline-form">
                  <?= Csrf::field() ?>
                  <label>Quote price (USD) *<input type="number" name="quote" required min="0.01" step="0.01" placeholder="e.g. 73.43"></label>
                  <label>Lowest price (USD) *<input type="number" name="lowest" required min="0.01" step="0.01" placeholder="e.g. 71.30"></label>
                  <label>Per (unit) *<input type="text" name="unit" required maxlength="20" value="<?= htmlspecialchars((string) $r['unit']) ?>" placeholder="e.g. SQM or CBM"></label>
                  <label>Note to the associate<input type="text" name="note" maxlength="500" placeholder="e.g. priced for 30 mm, flamed"></label>
                  <button type="submit" class="btn-sm">Send the price</button>
                </form>
              </details>
            <?php endif; ?>
          <?php elseif ($__st === 'ordered'): ?>Became order <?php if (!empty($r['order_id'])): ?><a href="/orders/<?= (int) $r['order_id'] ?>">#<?= (int) $r['order_id'] ?></a><?php endif; ?>
          <?php elseif ($__st === 'expired'): ?>Expired <?= $__d($r['valid_until']) ?>
          <?php elseif ($__st === 'refused'): ?>Refused
          <?php else: ?>Valid to <?= $__d($r['valid_until']) ?><?= $__st === 'priced' ? '<br><small class="muted">priced by hand</small>' : '' ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="section">
    <h2>Requests and orders — last 90 days</h2>
    <table class="list" id="price-request-report">
      <tr><th>Associate</th><th class="num">Price requests</th><th class="num">Orders</th><th class="num">Requests per order</th><th></th></tr>
      <?php if ($report === []): ?><tr><td colspan="5" class="muted">No price requests in the last 90 days.</td></tr><?php endif; ?>
      <?php foreach ($report as $x): ?>
        <tr><td><a href="/associates/<?= (int) $x['associate_id'] ?>"><?= htmlspecialchars($x['name']) ?></a></td>
          <td class="num"><?= (int) $x['requests'] ?></td><td class="num"><?= (int) $x['orders'] ?></td>
          <td class="num"><?= $x['per_order'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $x['per_order'], 1), '0'), '.')) : '—' ?></td>
          <td><?= $x['warn'] ? '<span class="badge badge-danger">Many prices, no orders — check</span>' : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted small">The warning shows when an associate has asked for <?= (int) $warnAt ?> or more prices in 90 days with no order from their buyers.</p>
  </div>
</div>
