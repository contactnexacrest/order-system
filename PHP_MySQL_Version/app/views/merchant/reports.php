<?php use App\Services\MerchantTradeService as M; use App\Services\ImportService as I; use App\Services\GstCalculator as G;
/* Owner decision D-257 Phase 4: Merchant Trade reports — open deals with their RBI status, and the margin per deal (both only for the divisions the person may see). */
$h = static fn ($v): string => htmlspecialchars((string) $v);
$open = array_values(array_filter($rows, static fn (array $r): bool => $r['stage'] !== 'closed'));
$levelStyle = ['ok' => 'color:#2D7D56', 'info' => 'color:#5B6573', 'warn' => 'color:#8A5A00;font-weight:700', 'breach' => 'color:#B42318;font-weight:700']; ?>
<div class="card page-wide" data-testid="mtt-reports">
  <p><a href="/merchant">&larr; Merchant trade deals</a></p>
  <h1>Merchant trade reports</h1>
  <p class="muted">RBI limits (Merchant Trade settings, to be confirmed with the AD bank and the CA): whole deal within <?= $h($settings['mtt_max_months_total']) ?> months; outlay within <?= $h($settings['mtt_max_outlay_months']) ?> months; advance above USD <?= $h(I::fc(G::scaled($settings['mtt_advance_limit_usd'], 2))) ?> only against an LC or bank guarantee.</p>

  <h2>Open deals and their RBI status</h2>
  <table class="list" data-testid="mtt-open">
    <tr><th>Deal</th><th>Stage</th><th>Buyer</th><th>Whole deal</th><th>Outlay</th><th>Advance</th><th>Bank documents</th></tr>
    <?php foreach ($open as $r): $c = array_column($r['checks'], null, 'key'); ?>
    <tr><td><a href="/merchant/<?= (int) $r['id'] ?>"><?= $h($r['reference']) ?></a></td><td><?= $h(M::STAGES[$r['stage']]) ?></td><td><?= $h($r['buyer_name']) ?></td>
      <?php foreach (['total', 'outlay', 'advance', 'documents'] as $k): ?><td style="<?= $levelStyle[$c[$k]['level']] ?? '' ?>"><?= $h($c[$k]['text']) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if ($open === []): ?><tr><td colspan="7" class="muted">No open deals.</td></tr><?php endif; ?>
  </table>

  <h2>Margin per deal</h2>
  <table class="list" data-testid="mtt-margins">
    <tr><th>Deal</th><th>Stage</th><th>Sale</th><th>Received ₹</th><th>Purchase</th><th>Paid ₹</th><th>Margin ₹</th><th>Margin USD</th><th>Margin %</th></tr>
    <?php foreach ($rows as $r): $f = $r['figures']; ?>
    <tr><td><a href="/merchant/<?= (int) $r['id'] ?>"><?= $h($r['reference']) ?></a></td><td><?= $h(M::STAGES[$r['stage']]) ?></td>
      <td><?= $h($r['sale_currency']) ?> <?= $h(I::fc($f['sale_cents'])) ?></td><td><?= $h(G::inr($f['received_inr'])) ?></td>
      <td><?= $h($r['purchase_currency']) ?> <?= $h(I::fc($f['purchase_cents'])) ?></td><td><?= $h(G::inr($f['paid_inr'])) ?></td>
      <td><?= $h(G::inr($f['margin_inr'])) ?><?= $f['final'] ? '' : ' (so far)' ?></td><td><?= $h(I::fc($f['margin_usd_cents'])) ?></td><td><?= $f['margin_pct'] !== null ? $h($f['margin_pct']) . '%' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="9" class="muted">No deals yet.</td></tr><?php endif; ?>
  </table>
</div>
