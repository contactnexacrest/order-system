<?php
/** The status filter row, shared by the Standard list and the Kanban board (owner hard rule 2026-11-01). Expects $counts and $statusFilter. */
$statusLabels = ['all' => 'All', 'active' => 'Active', 'overdue' => 'Overdue', 'shipped_docs_sent' => 'Docs Sent', 'complete' => 'Complete', 'lost' => 'Lost', 'cancelled' => 'Cancelled'];
?>
  <div class="filter-chip-row">
    <?php foreach ($statusLabels as $key => $label): ?>
      <a class="filter-chip<?= $statusFilter === $key ? ' active' : '' ?>" href="/orders?status=<?= $key ?><?= !empty($listOfBoard) ? '&amp;view=list' : '' ?>"><?= $label ?> (<?= (int) $counts[$key] ?>)</a>
    <?php endforeach; ?>
  </div>
