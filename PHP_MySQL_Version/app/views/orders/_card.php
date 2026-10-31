<?php
/**
 * One order card, shared by the Standard list (orders/index.php) and the
 * Kanban board (orders/index_kanban.php) — owner hard rule 2026-11-01:
 * both templates show exactly the same. Expects $o, $seesAllOrders, $sees
 * and $cardHref; included once per card (no declarations here).
 */
$stageTotal = 9;
$stageNumber = (int) ($o['current_stage_number'] ?? 0);
$isLost = $o['status'] === 'lost';
$isShippedDocsSent = $o['status'] === 'shipped_docs_sent';
$isComplete = $o['status'] === 'complete';
$isCancelled = $o['status'] === 'cancelled';
$filledSegments = ($isComplete || $isShippedDocsSent) ? $stageTotal : (($isLost || $isCancelled) ? min(1, max($stageNumber, 1)) : $stageNumber);
$amount = trim(($o['currency_code'] ?? '') . ' ' . number_format((float) $o['total_fob_value'], 2));
?>
    <a class="entity-card<?= isset($kanbanCard) && $kanbanCard ? ' kanban-card' : '' ?><?= !empty($o['is_overdue']) ? ' entity-card-overdue' : '' ?>" href="<?= htmlspecialchars($cardHref) ?>">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($o['order_reference']) ?></span>
        <?php if (!empty($o['incoterm_code'])): ?><span class="tag tag-gold"><?= htmlspecialchars($o['incoterm_code']) ?></span><?php endif; ?>
        <?php if (!empty($seesAllOrders) && !empty($o['assignee_names'])): ?><span class="tag tag-navy"><?= htmlspecialchars($o['assignee_names']) ?></span><?php endif; ?>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($o['company_legal_name'] ?? '')) ?></div>
      <div>
        <div class="mini-stage-track">
          <?php for ($i = 1; $i <= $stageTotal; $i++): ?>
            <div class="mini-stage-seg<?= $i <= $filledSegments ? ' filled' . (($isLost || $isCancelled) ? ' lost' : '') : '' ?>"></div>
          <?php endfor; ?>
        </div>
        <div class="mini-stage-label">
          <?php if ($isCancelled): ?>
            Cancelled
          <?php elseif ($isLost): ?>
            <?= htmlspecialchars($o['lost_reason'] ? mb_strimwidth($o['lost_reason'], 0, 46, '…') : 'Marked lost') ?>
          <?php elseif ($isComplete): ?>
            Stage 9 of 9 &middot; Closed
          <?php elseif ($isShippedDocsSent): ?>
            Stage 9 of 9 &middot; Docs Sent &mdash; Awaiting Benefits
          <?php else: ?>
            Stage <?= $stageNumber ?: 1 ?> of <?= $stageTotal ?> &middot; <?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="entity-card-footer">
        <?php if (!empty($o['is_overdue'])): ?>
          <span class="badge badge-lost">Overdue</span>
        <?php elseif ($isCancelled): ?>
          <span class="badge badge-lost">Cancelled</span>
        <?php elseif ($isLost): ?>
          <span class="badge badge-lost">Lost</span>
        <?php elseif ($isComplete): ?>
          <span class="badge badge-complete">Complete</span>
        <?php elseif ($isShippedDocsSent): ?>
          <span class="badge badge-complete">Docs Sent</span>
        <?php else: ?>
          <span class="badge badge-active">Active</span>
        <?php endif; ?>
        <?php if ($sees['selling']): ?><span class="entity-card-amount"><?= htmlspecialchars($amount) ?></span><?php endif; ?>
      </div>
    </a>
