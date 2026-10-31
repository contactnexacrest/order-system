<?php
/**
 * One complaint card, shared by the Standard list and the Kanban board (owner
 * hard rule 2026-11-01: both templates show exactly the same). Expects $c
 * and $kanbanCard; included once per card (no declarations here).
 */
$__badge = match ($c['status']) { 'resolved' => 'badge-active', 'acknowledged' => 'badge-protected', default => 'badge-lost' };
$__label = match ($c['status']) { 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', default => 'Open' };
?>
    <a class="entity-card<?= !empty($kanbanCard) ? ' kanban-card' : '' ?>" href="/orders/<?= (int) $c['order_id'] ?>/complaints">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($c['order_reference']) ?></span>
        <span class="badge <?= $__badge ?>"><?= htmlspecialchars($__label) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($c['company_legal_name'] ?? '')) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 110, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Raised: <?= htmlspecialchars((string) $c['created_at']) ?></span>
      </div>
    </a>
