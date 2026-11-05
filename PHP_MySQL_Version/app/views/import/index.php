<?php use App\Services\ImportService as I;
/* Owner decision D-257 Phase 3: the imports of Import & Distribution — the Standard list and the Kanban board offer the same filter, links and actions (hard rule 2026-11-01). */
$h = static fn ($v): string => htmlspecialchars((string) $v);
$card = static function (array $r) use ($h): string {
    return '<a class="entity-card kanban-card" href="/import/' . (int) $r['id'] . '"><div class="entity-card-header"><span class="entity-card-title">' . $h($r['reference']) . '</span>'
        . '<span class="badge badge-protected">' . $h(I::STAGES[$r['stage']] ?? $r['stage']) . '</span></div>'
        . '<div class="entity-card-sub">' . $h($r['supplier_name']) . ' · ' . $h($r['supplier_country']) . '</div>'
        // QA batch 2 (UI-6): the card carries the BL and the bill of entry like the list.
        . '<div class="entity-card-sub muted small" data-field="bl_boe">BL ' . $h($r['bl_no'] ?: '—') . ' · Bill of entry ' . $h($r['boe_no'] ?: '—') . '</div>'
        . '<div class="entity-card-footer"><span class="muted small">' . $h($r['currency_code']) . ' ' . $h(I::fc(\App\Services\GstCalculator::scaled((string) $r['po_value_fc'], 2))) . '</span>'
        . '<span class="muted small">PO ' . $h($r['po_date']) . '</span><span class="muted small">' . (int) $r['line_count'] . ' line' . ((int) $r['line_count'] === 1 ? '' : 's') . '</span></div></a>';
}; ?>
<div class="card page-wide" data-testid="imp-list">
  <div class="page-header-row">
    <div>
      <h1>Imports<?= $board ? ' — Kanban Board' : '' ?></h1>
      <p class="muted" style="margin:0;">Goods bought abroad for Import &amp; Distribution: the import PO, LC, shipment, customs clearance, landed cost and stock.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <?php if ($canEdit && $active): ?><a class="btn-sm" href="/import/create">New import</a><?php endif; ?>
      <a class="btn-sm btn-secondary" href="/import/reports">Reports</a>
      <?php if ($board): ?><a class="btn-sm btn-secondary" href="/import?view=list<?= $stage !== '' ? '&amp;stage=' . $h($stage) : '' ?>">Switch to list view</a>
      <?php elseif ($kanbanUser): ?><a class="btn-sm btn-secondary" href="/import<?= $stage !== '' ? '?stage=' . $h($stage) : '' ?>">Switch to board view</a><?php endif; ?>
    </div>
  </div>
  <form method="get" action="/import" class="filters" data-testid="imp-filter">
    <?php if (!$board): ?><input type="hidden" name="view" value="list"><?php endif; ?>
    <label>Stage
      <select name="stage" onchange="this.form.submit()">
        <option value="">All stages</option>
        <?php foreach (I::STAGES as $k => $label): ?><option value="<?= $h($k) ?>" <?= $stage === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
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
    <tr><th>Import PO</th><th>Stage</th><th>Supplier</th><th>PO date</th><th>Value</th><th>Lines</th><th>BL</th><th>Bill of entry</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="/import/<?= (int) $r['id'] ?>"><?= $h($r['reference']) ?></a></td><td><?= $h(I::STAGES[$r['stage']] ?? $r['stage']) ?></td>
      <td><?= $h($r['supplier_name']) ?> · <?= $h($r['supplier_country']) ?></td><td><?= $h($r['po_date']) ?></td>
      <td><?= $h($r['currency_code']) ?> <?= $h(I::fc(\App\Services\GstCalculator::scaled((string) $r['po_value_fc'], 2))) ?></td><td><?= (int) $r['line_count'] ?></td>
      <td><?= $h($r['bl_no'] ?: '—') ?></td><td><?= $h($r['boe_no'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="8" class="muted"><?= $stage !== '' ? 'None at this stage.' : 'No imports yet.' /* QA batch 2 (UI-13) */ ?></td></tr><?php endif; ?>
  </table>
  <?php endif; ?>
</div>
