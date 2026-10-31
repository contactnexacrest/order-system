<?php /** The status filter row, shared by the Standard list and the Kanban board (owner hard rule 2026-11-01). Expects $statusFilter, $listOfBoard. */ $__v = !empty($listOfBoard) ? 'view=list&amp;' : ''; ?>
  <div class="filter-chip-row">
    <a class="filter-chip<?= $statusFilter === null ? ' active' : '' ?>" href="/complaints<?= !empty($listOfBoard) ? '?view=list' : '' ?>">All</a>
    <?php foreach (['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'] as $__s => $__l): ?>
      <a class="filter-chip<?= $statusFilter === $__s ? ' active' : '' ?>" href="/complaints?<?= $__v ?>status=<?= $__s ?>"><?= $__l ?></a>
    <?php endforeach; ?>
  </div>
