<?php
/** D-310: the tab chips — the same on the list and on the board (owner hard rule 2026-11-01). Expects $tabs, $counts, $tab, $listView. */
$__q = !empty($listView) ? '&amp;view=list' : '';
?>
<div class="filter-chip-row" role="tablist" aria-label="Approval types">
  <a class="filter-chip<?= $tab === 'all' ? ' active' : '' ?>" href="/approvals?tab=all<?= $__q ?>">All <?= (int) $counts['all'] ?></a>
  <?php foreach ($tabs as $__key => $__label): ?>
  <a class="filter-chip<?= $tab === $__key ? ' active' : '' ?>" href="/approvals?tab=<?= htmlspecialchars($__key) ?><?= $__q ?>"><?= htmlspecialchars($__label) ?> <?= (int) $counts[$__key] ?></a>
  <?php endforeach; ?>
</div>
