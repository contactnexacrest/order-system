<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Email Send Approvals (Level 2)</h1>
  <p class="muted small">Includes anything still in its deferred window — awaiting your approval, or already approved but not yet dispatched. Cancel stops either one before it goes out. The exact subject, body, and attachment below are exactly what will be sent — nothing is re-composed at dispatch time.</p>

  <?php if (empty($pending)): ?>
    <p class="muted">Nothing awaiting approval or dispatch.</p>
  <?php endif; ?>

  <?php foreach ($pending as $row): ?>
  <div class="section" style="border:1px solid #dcd6c9; border-radius:4px; padding:12px; margin-bottom:12px;">
    <div class="kv-grid">
      <div><span class="k">Order</span><span class="v"><?= htmlspecialchars($row['order_reference'] ?? '—') ?></span></div>
      <div><span class="k">To</span><span class="v"><?= htmlspecialchars($row['recipient_email']) ?></span></div>
      <div><span class="k">Status</span><span class="v"><?= htmlspecialchars(str_replace('_', ' ', $row['status'])) ?></span></div>
      <div><span class="k">Scheduled</span><span class="v"><?= htmlspecialchars($row['scheduled_at'] ?? 'Immediate') ?></span></div>
      <div><span class="k">Requested</span><span class="v"><?= htmlspecialchars($row['created_at']) ?></span></div>
    </div>

    <h2 style="margin-top:0.75rem">Exact Preview</h2>
    <p class="muted small" style="margin:0 0 4px"><strong>Subject:</strong> <?= htmlspecialchars($row['subject']) ?></p>
    <pre style="white-space:pre-wrap; border:1px solid #dcd6c9; padding:8px; font-size:9px; background:#fff;"><?= htmlspecialchars($row['body_snapshot']) ?></pre>

    <p class="muted small" style="margin-top:0.5rem">
      <strong>Attachment:</strong>
      <?php if ($row['document_id'] && $canDownload): ?>
        <?= htmlspecialchars($row['document_type_code'] ?? '') ?> <?= htmlspecialchars($row['document_reference'] ?? '') ?>.pdf
        &nbsp;·&nbsp; <a href="/documents/<?= (int) $row['document_id'] ?>/download" target="_blank" rel="noopener">View attachment (opens the exact PDF that will be sent)</a>
      <?php elseif ($row['document_id']): ?>
        <?= htmlspecialchars($row['document_type_code'] ?? '') ?> <?= htmlspecialchars($row['document_reference'] ?? '') ?>.pdf (you don't have permission to open it)
      <?php else: ?>
        none
      <?php endif; ?>
    </p>

    <div style="margin-top:0.75rem">
      <?php if ($row['status'] === 'pending_approval'): ?>
      <form method="post" action="/email-log/<?= (int) $row['id'] ?>/approve" style="display:inline">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm btn-success">Approve</button>
      </form>
      <form method="post" action="/email-log/<?= (int) $row['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this send?');">
        <?= Csrf::field() ?>
        <input type="text" name="reason" placeholder="Reason (mandatory)" required style="width:160px">
        <button type="submit" class="btn-sm btn-danger">Reject</button>
      </form>
      <?php endif; ?>
      <form method="post" action="/email-log/<?= (int) $row['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this send? It will never go out.');">
        <?= Csrf::field() ?>
        <input type="text" name="reason" placeholder="Cancel reason (mandatory)" required style="width:160px">
        <button type="submit" class="btn-sm btn-warning">Cancel Send</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
