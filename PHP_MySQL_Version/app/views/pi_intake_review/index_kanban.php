<?php
use App\Helpers\Csrf;
/**
 * Owner hard rule 2026-11-01: every field and action of the Standard list's
 * row (pi_intake_review/index.php) — Accept & Apply, Reject with a reason.
 * A closure, not a top-level function — see orders/index_kanban.php's own
 * note on repeated includes.
 */
$renderKanbanCard = static function (array $s): string {
    $status = (string) ($s['status'] ?? 'pending_review');
    $company = $s['company_legal_name'] ?? $s['client_company_legal_name'] ?? '';
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header">
        <span class="entity-card-title"><a href="/orders/<?= (int) $s['order_id'] ?>"><?= htmlspecialchars($s['order_reference']) ?></a></span>
        <?php if ($status !== 'pending_review'): ?><span class="badge <?= $status === 'applied' ? 'badge-active' : 'badge-lost' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span><?php endif; ?>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) $company) ?></div>
      <?php if ($status === 'pending_review'): ?>
        <div class="muted small"><?= htmlspecialchars((string) $s['contact_person']) ?> · <?= htmlspecialchars((string) $s['email']) ?></div>
        <div class="muted small">Consignee: <?= htmlspecialchars((string) $s['consignee_name']) ?> · Notify: <?= htmlspecialchars((string) ($s['notify_party'] ?: '—')) ?></div>
        <div class="muted small">Incoterm: <?= htmlspecialchars((string) $s['incoterm_confirmed']) ?> · Port: <?= htmlspecialchars((string) $s['port_of_discharge_text']) ?></div>
        <div class="muted small"><?= htmlspecialchars((string) $s['quotation_acceptance_reference']) ?></div>
        <div class="muted small"><?= htmlspecialchars((string) $s['payment_terms_confirmation']) ?></div>
        <?php if (!empty($s['changes_from_quotation'])): ?><div class="muted small">Changes: <?= htmlspecialchars((string) $s['changes_from_quotation']) ?></div><?php endif; ?>
        <?php if (!empty($s['buyer_po_ref'])): ?><div class="muted small">Buyer PO: <?= htmlspecialchars((string) $s['buyer_po_ref']) ?></div><?php endif; ?>
        <div class="muted small">Submitted <?= htmlspecialchars((string) $s['submitted_at']) ?></div>
        <div class="kanban-card-actions">
          <form method="post" action="/pi-intake-review/<?= (int) $s['id'] ?>/accept" onsubmit="return confirm('Accept and apply these details to the client and order?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-success">Accept &amp; Apply</button>
          </form>
          <form method="post" action="/pi-intake-review/<?= (int) $s['id'] ?>/reject" onsubmit="return confirm('Reject this PI-stage submission?');">
            <?= Csrf::field() ?>
            <input type="text" name="reason" placeholder="Reason (required)" required aria-label="Reason for rejecting">
            <button type="submit" class="btn-sm btn-danger">Reject</button>
          </form>
        </div>
      <?php else: ?>
        <div class="muted small">Resolved by <?= htmlspecialchars($s['reviewed_by_name'] ?? '—') ?> · <?= htmlspecialchars((string) $s['reviewed_at']) ?></div>
        <?php if ($status === 'rejected'): ?><div class="entity-card-footer"><span class="muted small"><?= htmlspecialchars($s['rejection_reason'] ?? '') ?></span></div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>PI Intake Review — Kanban Board</h1>
      <p class="muted" style="margin:0;">Submissions from the per-order PI-stage form (<code>/pi-details/&lt;token&gt;</code>). Accepting overwrites the client's identity fields with what the client confirmed and sets the order's Buyer PO ref if provided; everything else stays on the submission for you to read before generating the PI.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/pi-intake-review?view=list">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Pending Review</span>
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
        <span class="kanban-column-title">Applied</span>
        <span class="kanban-column-count"><?= count($applied) ?></span>
      </div>
      <?php if (empty($applied)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($applied as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
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
