<?php
/** The Kanban UI template's My tasks board (D-214 phase 4): escalated, overdue, due today, coming up. */
$renderKanbanCard = static function (array $t): string {
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="<?= htmlspecialchars((string) $t['link']) ?>">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars((string) $t['title']) ?></span>
      </div>
      <div class="entity-card-sub"><span class="dept-tag" style="--dept-color:<?= htmlspecialchars((string) $t['department_color']) ?>"><?= htmlspecialchars((string) $t['department_name']) ?></span> <?= htmlspecialchars((string) $t['order_reference']) ?></div>
      <?php if (!empty($t['subject'])): ?><div class="muted small"><?= htmlspecialchars((string) $t['subject']) ?></div><?php endif; ?>
      <div class="entity-card-footer">
        <span class="muted small">Due <?= htmlspecialchars((string) $t['due_on']) ?><?= (int) $t['escalated'] === 1 ? ' <span class="badge badge-inactive">escalated</span>' : ((int) $t['overdue'] === 1 ? ' <span class="badge badge-warning">overdue</span>' : '') ?></span>
        <span class="muted small"><?= $t['owner_name'] !== null ? htmlspecialchars((string) $t['owner_name']) : 'no owner yet' ?></span>
      </div>
      <span class="btn-sm">Open</span>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>My tasks — Kanban Board</h1>
      <p class="muted" style="margin:0;">What <?= $acting ? htmlspecialchars($acting) : 'your department' ?> has to do next, earliest due first. A task appears here when the step before it is done, and goes away when you do it. Overdue tasks are red; after a few more days they are escalated to management.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm<?= $mine ? ' btn-secondary' : '' ?>" href="/my-tasks">All of my department's tasks</a>
      <a class="btn-sm<?= $mine ? '' : ' btn-secondary' ?>" href="/my-tasks?show=mine">Only orders I own</a>
      <a class="btn-sm btn-secondary" href="/my-tasks?view=list<?= $mine ? '&amp;show=mine' : '' ?>">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <?php foreach ($columns as $key => $col): ?>
    <div class="kanban-column" data-column="<?= htmlspecialchars($key) ?>">
      <div class="kanban-column-header">
        <span class="kanban-column-title"><?= htmlspecialchars($col['label']) ?></span>
        <span class="kanban-column-count"><?= count($col['rows']) ?></span>
      </div>
      <?php if ($col['rows'] === []): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($col['rows'] as $t): ?><?= $renderKanbanCard($t) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
