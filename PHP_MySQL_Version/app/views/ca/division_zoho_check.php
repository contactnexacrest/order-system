<?php
// D-257 Zoho: the "App vs Zoho" check of a Domestic Trade order, an import or a merchant trade deal — reads only.
$badges = ['match' => 'badge-success', 'not_sent' => 'badge-warning', 'before_go_live' => 'badge-complete', 'different' => 'badge-danger', 'missing' => 'badge-danger', 'books_only' => 'badge-danger'];
$labels = \App\Services\OrderZohoMatchService::STATUS_LABELS;
?>
<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="<?= htmlspecialchars($back) ?>">&larr; <?= htmlspecialchars($title) ?></a> &middot; <a href="/ca/zoho-sync">Zoho Books Sync</a></p>
  <h1>App vs Zoho Books</h1>
  <p class="muted"><?= htmlspecialchars($title) ?> &middot; <?= htmlspecialchars($party) ?>. Every record of it in the app that goes to Zoho Books, beside what Books holds for it. This page only reads; it changes nothing in the app or in Books.</p>

  <?php if ($error !== null): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php else: ?>
    <?php $c = static fn (string $k): int => (int) ($result['counts'][$k] ?? 0); $problems = $c('different') + $c('missing') + $c('books_only'); ?>
    <div class="section">
      <h2><?= $problems === 0 && $c('not_sent') === 0 ? 'Everything matches' : ($problems > 0 ? "{$problems} difference(s) to look at" : 'Waiting to be sent') ?></h2>
      <div class="kv-grid">
        <?php foreach (['match', 'not_sent', 'different', 'missing'] as $key): ?>
          <div><span class="k"><?= htmlspecialchars($labels[$key]) ?></span><span class="v"><?= $c($key) ?></span></div>
        <?php endforeach; ?>
      </div>
      <p class="muted small">"Not in Zoho Books yet" goes on the next sync (or "Send to Zoho Books" on its page; the Zoho Sync Log says why it has not gone so far). "Different" and "Missing" mean someone changed Books directly, or the app changed after sending: correct the side that is wrong.</p>
    </div>

    <div class="section">
      <?php if ($result['rows'] === []): ?>
        <p class="muted">Nothing of this record goes to Zoho Books yet.</p>
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
