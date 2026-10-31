<?php /** The status filter row, shared by the Standard list and the Kanban board (owner hard rule 2026-11-01). Expects $statusFilter, $statusOptions, $listOfBoard. */ $__v = !empty($listOfBoard) ? 'view=list&amp;' : ''; ?>
  <div class="filter-chip-row">
    <a class="filter-chip<?= $statusFilter === null ? ' active' : '' ?>" href="/disputes<?= !empty($listOfBoard) ? '?view=list' : '' ?>">All</a>
    <?php foreach ($statusOptions as $opt): ?>
      <a class="filter-chip<?= $statusFilter === $opt['option_value'] ? ' active' : '' ?>" href="/disputes?<?= $__v ?>status=<?= urlencode($opt['option_value']) ?>"><?= htmlspecialchars($opt['option_value']) ?></a>
    <?php endforeach; ?>
  </div>
