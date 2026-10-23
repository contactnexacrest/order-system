<?php
/** A closure, not a top-level function — see orders/index_kanban.php's own note on repeated includes. */
$renderKanbanCard = static function (array $s): string {
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($s['company_legal_name']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars($s['contact_person']) ?></div>
      <div class="muted small"><?= htmlspecialchars($s['country_of_destination']) ?></div>
      <div class="entity-card-footer">
        <?php if (($s['status'] ?? 'pending') === 'converted' && !empty($s['client_unique_number'])): ?>
          <a href="/clients/<?= (int) $s['converted_client_id'] ?>" class="muted small">&rarr; <?= htmlspecialchars($s['client_unique_number']) ?></a>
        <?php elseif (($s['status'] ?? 'pending') === 'rejected'): ?>
          <span class="muted small"><?= htmlspecialchars(mb_strimwidth((string) ($s['rejection_reason'] ?? ''), 0, 40, '…')) ?></span>
        <?php else: ?>
          <span class="muted small">Submitted <?= htmlspecialchars((string) $s['submitted_at']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Quotation Intake Review — Kanban Board</h1>
      <p class="muted" style="margin:0;">Submissions from the public quotation-details form.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/client-intake?view=list">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Pending</span>
        <span class="kanban-column-count"><?= count($pending) ?></span>
      </div>
      <?php if (empty($pending)): ?>
        <p class="kanban-column-empty">Nothing pending</p>
      <?php else: ?>
        <?php foreach ($pending as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Converted</span>
        <span class="kanban-column-count"><?= count($converted) ?></span>
      </div>
      <?php if (empty($converted)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($converted as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Rejected</span>
        <span class="kanban-column-count"><?= count($rejected) ?></span>
      </div>
      <?php if (empty($rejected)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($rejected as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
