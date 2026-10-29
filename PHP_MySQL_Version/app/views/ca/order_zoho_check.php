<?php
// Owner decision 2026-10-29: the per-order "App vs Zoho" check — reads only.
$badges = ['match' => 'badge-success', 'not_sent' => 'badge-warning', 'before_go_live' => 'badge-complete', 'different' => 'badge-danger', 'missing' => 'badge-danger', 'books_only' => 'badge-danger'];
$labels = \App\Services\OrderZohoMatchService::STATUS_LABELS;
?>
<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/orders/<?= (int) $order['id'] ?>#order-financials">&larr; Order <?= htmlspecialchars((string) $order['buyer_inquiry_ref']) ?></a> &middot; <a href="/ca/zoho-sync">Zoho Books Sync</a></p>
  <h1>App vs Zoho Books</h1>
  <p class="muted">Order <?= htmlspecialchars((string) $order['buyer_inquiry_ref']) ?> &middot; <?= htmlspecialchars((string) $order['company_legal_name']) ?>. Every record of this order in the app, beside what Zoho Books holds for it. This page only reads; it changes nothing in the app or in Books.</p>

  <?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php else: ?>
    <?php $problems = $result['counts']['different'] + $result['counts']['missing'] + $result['counts']['books_only']; ?>
    <div class="section">
      <h2><?= $problems === 0 && $result['counts']['not_sent'] === 0 ? 'Everything matches' : ($problems > 0 ? "{$problems} difference(s) to look at" : 'Waiting to be sent') ?></h2>
      <div class="kv-grid">
        <?php foreach ($labels as $key => $label): ?>
          <div><span class="k"><?= htmlspecialchars($label) ?></span><span class="v"><?= (int) $result['counts'][$key] ?></span></div>
        <?php endforeach; ?>
      </div>
      <p class="muted small">"Not in Zoho Books yet" goes on the next sync (the note says why it has not gone so far). "Before the go-live date"<?= $startDate !== null ? ' (' . htmlspecialchars(date('d M Y', strtotime($startDate))) . ')' : '' ?>: the CA books it by hand. "Different", "Missing" and "Only in Zoho Books" mean someone changed Books directly, or the app changed after sending: correct the side that is wrong.</p>
    </div>

    <div class="section">
      <?php if ($result['rows'] === []): ?>
        <p class="muted">This order has nothing that goes to Zoho Books yet.</p>
      <?php else: ?>
        <table class="list">
          <tr><th>Part</th><th>Record</th><th style="text-align:right">In the app</th><th style="text-align:right">In Zoho Books</th><th>Status</th><th>Note</th></tr>
          <?php foreach ($result['rows'] as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['group']) ?></td>
            <td><?= htmlspecialchars($row['item']) ?></td>
            <td style="text-align:right; white-space:nowrap;"><?= $row['app'] !== null ? htmlspecialchars($row['app']) : '—' ?></td>
            <td style="text-align:right; white-space:nowrap;"><?= $row['books'] !== null ? htmlspecialchars($row['books']) : '—' ?></td>
            <td><span class="badge <?= $badges[$row['status']] ?>" style="margin-left:0;"><?= htmlspecialchars($labels[$row['status']]) ?></span></td>
            <td class="small"><?= htmlspecialchars($row['note']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
