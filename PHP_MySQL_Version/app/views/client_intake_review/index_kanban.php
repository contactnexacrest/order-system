<?php
use App\Helpers\Csrf;
/**
 * Owner hard rule 2026-11-01: every field and action of the Standard list's
 * row (client_intake_review/index.php) — Accept & Create Client, Reject with
 * a reason, Resend Link. A closure, not a top-level function — see
 * orders/index_kanban.php's own note on repeated includes.
 */
$renderKanbanCard = static function (array $s): string {
    $status = (string) ($s['status'] ?? 'pending');
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($s['company_legal_name']) ?></span>
        <?php if ($status !== 'pending'): ?><span class="badge <?= $status === 'converted' ? 'badge-active' : 'badge-lost' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span><?php endif; ?>
      </div>
      <?php if ($status === 'pending'): ?>
        <div class="muted small"><?= htmlspecialchars((string) $s['billing_address']) ?></div>
        <div class="entity-card-sub"><?= htmlspecialchars((string) $s['contact_person']) ?></div>
        <div class="muted small"><?= htmlspecialchars((string) $s['email']) ?> · <?= htmlspecialchars((string) ($s['phone'] ?? '')) ?></div>
        <div class="muted small">Consignee: <?= (int) ($s['consignee_same_as_buyer'] ?? 1) === 1 ? 'Same as Buyer' : htmlspecialchars((string) ($s['consignee_name'] ?? '—')) ?></div>
        <div class="muted small">Destination: <?= htmlspecialchars((string) $s['country_of_destination']) ?>
          <?php if (!empty($s['port_of_discharge_text'])): ?> · Port: <?= htmlspecialchars((string) $s['port_of_discharge_text']) ?><?php endif; ?>
          <?php if (!empty($s['incoterm_preference'])): ?> · Incoterm: <?= htmlspecialchars((string) $s['incoterm_preference']) ?><?php endif; ?>
          <?php if (!empty($s['coo_type'])): ?> · COO: <?= htmlspecialchars((string) $s['coo_type']) ?><?php endif; ?>
        </div>
        <div class="muted small">Submitted <?= htmlspecialchars((string) $s['submitted_at']) ?></div>
        <div class="kanban-card-actions">
          <form method="post" action="/client-intake/<?= (int) $s['id'] ?>/accept">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-success">Accept &amp; Create Client</button>
          </form>
          <form method="post" action="/client-intake/<?= (int) $s['id'] ?>/reject" onsubmit="return confirm('Reject this quotation request?');">
            <?= Csrf::field() ?>
            <input type="text" name="reason" placeholder="Reason (required)" required aria-label="Reason for rejecting">
            <button type="submit" class="btn-sm btn-danger">Reject</button>
          </form>
          <form method="post" action="/client-intake/<?= (int) $s['id'] ?>/resend-link">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-secondary" title="Re-send the self-correction link if the client never got it or it's expired">Resend Link</button>
          </form>
        </div>
      <?php else: ?>
        <div class="entity-card-sub"><?= htmlspecialchars((string) $s['contact_person']) ?></div>
        <div class="muted small">Resolved by <?= htmlspecialchars($s['reviewed_by_name'] ?? '—') ?> · <?= htmlspecialchars((string) $s['reviewed_at']) ?></div>
        <div class="entity-card-footer">
          <?php if ($status === 'converted' && !empty($s['client_unique_number'])): ?>
            <a href="/clients/<?= (int) $s['converted_client_id'] ?>">&rarr; <?= htmlspecialchars($s['client_unique_number']) ?></a>
          <?php elseif ($status === 'rejected'): ?>
            <span class="muted small"><?= htmlspecialchars($s['rejection_reason'] ?? '') ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Quotation Intake Review — Kanban Board</h1>
      <p class="muted" style="margin:0;">Submissions from the public quotation-details form (<code>/quotation-details</code>). Nothing here becomes a real client or order until you accept it — accepting creates the client record and takes you to it, ready for you to create the order and generate the Quotation yourself.</p>
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
