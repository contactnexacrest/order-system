<?php
/**
 * One dispute card, shared by the Standard list and the Kanban board (owner
 * hard rule 2026-11-01: both templates show exactly the same). Expects $d
 * and $kanbanCard; included once per card (no declarations here).
 */
$__badge = match ($d['status']) { 'Resolved' => 'badge-active', 'Escalated' => 'badge-lost', default => 'badge-protected' };
?>
    <a class="entity-card<?= !empty($kanbanCard) ? ' kanban-card' : '' ?>" href="/orders/<?= (int) $d['order_id'] ?>/disputes">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($d['order_reference']) ?></span>
        <span class="badge <?= $__badge ?>"><?= htmlspecialchars($d['status']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($d['company_legal_name'] ?? '')) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($d['description'], 0, 110, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Notice: <?= htmlspecialchars($d['notice_date']) ?></span>
        <span class="muted small">Response due: <?= htmlspecialchars($d['response_due_date'] ?? '—') ?></span>
      </div>
    </a>
