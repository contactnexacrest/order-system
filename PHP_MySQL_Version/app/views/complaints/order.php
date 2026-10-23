<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;
$statusLabel = static function (string $status): string {
    return match ($status) {
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        default => 'Open',
    };
};
?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <h1>Complaints — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name']) ?></p>
  <p class="muted small">A process issue or flaw the client flagged for us to fix next time — separate from Disputes, and always available regardless of order status.</p>

  <?php if ($canManage): ?>
  <div class="section">
    <h2>Log a Complaint</h2>
    <p class="muted small">For a complaint the client raised by phone or email instead of through the portal.</p>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/complaints">
      <?= Csrf::field() ?>
      <label class="full">Description *
        <textarea name="description" required rows="3" placeholder="Describe the issue the client raised" style="width:100%"></textarea>
      </label>
      <button type="submit" class="btn-sm">Log Complaint</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Complaint Log</h2>
    <?php foreach ($complaints as $c): ?>
      <details>
        <summary><?= htmlspecialchars(Dates::human($c['created_at'])) ?> — <?= htmlspecialchars($statusLabel($c['status'])) ?> — <?= htmlspecialchars(mb_strimwidth($c['description'], 0, 80, '…')) ?></summary>
        <p><?= nl2br(htmlspecialchars($c['description'])) ?></p>
        <?php if ($c['resolved_at']): ?><p class="muted small">Resolved: <?= htmlspecialchars(Dates::human($c['resolved_at'])) ?></p><?php endif; ?>

        <?php if ($canManage): ?>
        <form method="post" action="/complaints/<?= (int) $c['id'] ?>/status">
          <?= Csrf::field() ?>
          <div class="field-grid">
            <label>Status
              <select name="status">
                <option value="open" <?= $c['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="acknowledged" <?= $c['status'] === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                <option value="resolved" <?= $c['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
              </select>
            </label>
          </div>
          <button type="submit" class="btn-sm">Update</button>
        </form>
        <?php endif; ?>

        <div class="section-nested" style="margin-top:0.75rem">
          <h3>Conversation</h3>
          <?php foreach (($replies[(int) $c['id']] ?? []) as $r): ?>
            <div class="reply-item" style="border-top:1px solid var(--line, #EFE6D2);padding:0.5rem 0">
              <p class="muted small"><?= $r['author_type'] === 'staff' ? htmlspecialchars($r['staff_author_name'] ?? 'Staff') : 'Client' ?> — <?= htmlspecialchars(Dates::human($r['created_at'])) ?></p>
              <p><?= nl2br(htmlspecialchars($r['body'])) ?></p>
            </div>
          <?php endforeach; ?>
          <?php if (empty($replies[(int) $c['id']] ?? [])): ?>
            <p class="muted small">No replies yet.</p>
          <?php endif; ?>

          <?php if ($canRespond): ?>
          <form method="post" action="/complaints/<?= (int) $c['id'] ?>/replies">
            <?= Csrf::field() ?>
            <label>Reply<textarea name="body" required rows="2" placeholder="Type your reply…" style="width:100%"></textarea></label>
            <button type="submit" class="btn-sm">Post Reply</button>
          </form>
          <?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <?php if (empty($complaints)): ?>
      <p class="muted">No complaints on this order.</p>
    <?php endif; ?>
  </div>
</div>
