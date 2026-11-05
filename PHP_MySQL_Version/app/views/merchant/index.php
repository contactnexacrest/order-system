<?php use App\Services\MerchantTradeService as M; use App\Services\ImportService as I;
/* Owner decision D-257 Phase 4: the deals of Merchant Trade — the Standard list and the Kanban board offer the same filter, links, figures and actions (hard rule 2026-11-01). */
$h = static fn ($v): string => htmlspecialchars((string) $v);
$rbi = static fn (string $w): string => ['breach' => 'RBI: action needed', 'warn' => 'RBI: due soon', 'ok' => 'RBI: on track'][$w] ?? '';
// QA batch 2 (ACC-4): the buyer's name and the sale figures only for those who may see them; UI-6: the card carries the deal date like the list.
$buyer = static fn (array $r): string => ($money['client'] ? $h($r['buyer_name']) . ' ' : 'Buyer ') . '(' . $h($r['buyer_country']) . ')';
$card = static function (array $r) use ($h, $rbi, $money, $buyer): string {
    $f = $r['figures'];
    return '<a class="entity-card kanban-card" href="/merchant/' . (int) $r['id'] . '"><div class="entity-card-header"><span class="entity-card-title">' . $h($r['reference']) . '</span>'
        . '<span class="badge badge-protected">' . $h(M::STAGES[$r['stage']] ?? $r['stage']) . '</span></div>'
        . '<div class="entity-card-sub">' . $h($r['supplier_name']) . ' (' . $h($r['supplier_country']) . ') → ' . $buyer($r) . '</div>'
        . '<div class="entity-card-sub muted small" data-field="deal_date">Deal date ' . $h($r['deal_date']) . '</div>'
        . '<div class="entity-card-footer"><span class="muted small">Buy ' . $h($r['purchase_currency']) . ' ' . $h(I::fc($f['purchase_cents'])) . '</span>'
        . ($money['selling'] ? '<span class="muted small">Sell ' . $h($r['sale_currency']) . ' ' . $h(I::fc($f['sale_cents'])) . '</span>' : '')
        . '<span class="muted small" data-rbi="' . $h($r['worst']) . '">' . $h($rbi($r['worst'])) . '</span></div></a>';
}; ?>
<div class="card page-wide" data-testid="mtt-list">
  <div class="page-header-row">
    <div>
      <h1>Merchant trade deals<?= $board ? ' — Kanban Board' : '' ?></h1>
      <p class="muted" style="margin:0;">Goods bought abroad and sold abroad without entering India: both legs, the BL, the margin and the RBI checks.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <?php if ($canEdit && $active): ?><a class="btn-sm" href="/merchant/create">New deal</a><?php endif; ?>
      <a class="btn-sm btn-secondary" href="/merchant/reports">Reports</a>
      <?php if ($board): ?><a class="btn-sm btn-secondary" href="/merchant?view=list<?= $stage !== '' ? '&amp;stage=' . $h($stage) : '' ?>">Switch to list view</a>
      <?php elseif ($kanbanUser): ?><a class="btn-sm btn-secondary" href="/merchant<?= $stage !== '' ? '?stage=' . $h($stage) : '' ?>">Switch to board view</a><?php endif; ?>
    </div>
  </div>
  <form method="get" action="/merchant" class="filters" data-testid="mtt-filter">
    <?php if (!$board): ?><input type="hidden" name="view" value="list"><?php endif; ?>
    <label>Stage
      <select name="stage" onchange="this.form.submit()">
        <option value="">All stages</option>
        <?php foreach (M::STAGES as $k => $label): ?><option value="<?= $h($k) ?>" <?= $stage === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <noscript><button type="submit" class="btn-sm">Filter</button></noscript>
  </form>
  <?php if ($board): ?>
  <div class="kanban-board">
    <?php foreach ($columns as $key => $col): ?>
    <div class="kanban-column" data-status="<?= $h($key) ?>">
      <div class="kanban-column-header"><span class="kanban-column-title"><?= $h($col['label']) ?></span><span class="kanban-column-count"><?= count($col['rows']) ?></span></div>
      <?php if ($col['rows'] === []): ?><p class="kanban-column-empty">Nothing here</p><?php else: foreach ($col['rows'] as $r): ?><?= $card($r) ?><?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <table class="list">
    <tr><th>Deal</th><th>Stage</th><th>Supplier</th><th>Buyer</th><th>Deal date</th><th>Buy</th><?php if ($money['selling']): ?><th>Sell</th><?php endif; ?><th>RBI</th></tr>
    <?php foreach ($rows as $r): $f = $r['figures']; ?>
    <tr>
      <td><a href="/merchant/<?= (int) $r['id'] ?>"><?= $h($r['reference']) ?></a></td><td><?= $h(M::STAGES[$r['stage']] ?? $r['stage']) ?></td>
      <td><?= $h($r['supplier_name']) ?> (<?= $h($r['supplier_country']) ?>)</td><td><?= $buyer($r) ?></td><td><?= $h($r['deal_date']) ?></td>
      <td>Buy <?= $h($r['purchase_currency']) ?> <?= $h(I::fc($f['purchase_cents'])) ?></td><?php if ($money['selling']): ?><td>Sell <?= $h($r['sale_currency']) ?> <?= $h(I::fc($f['sale_cents'])) ?></td><?php endif; ?>
      <td data-rbi="<?= $h($r['worst']) ?>"><?= $h($rbi($r['worst'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="<?= $money['selling'] ? 8 : 7 ?>" class="muted"><?= $stage !== '' ? 'None at this stage.' : 'No deals yet.' /* QA batch 2 (UI-13) */ ?></td></tr><?php endif; ?>
  </table>
  <?php endif; ?>
</div>
