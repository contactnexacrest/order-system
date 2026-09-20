<?php
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\PermissionService;
$__u = AuthService::currentUser();
$canEditLockedData = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'edit_locked_data');
?>
<div class="card page-wide">
  <h1>Payment Terms Amendments — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name']) ?></p>

  <div class="section">
    <h2>Raise a New Amendment Request</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/amendments">
      <?= Csrf::field() ?>
      <label>Reason *<textarea name="reason" required rows="3" style="width:100%"></textarea></label>
      <label>Requested By *
        <select name="requested_by">
          <option value="importer">The Importer</option>
          <option value="exporter">NexaCrest</option>
        </select>
      </label>
      <label>Amended Advance % (leave blank if unchanged)<input type="text" name="amended_advance_pct"></label>
      <label>Amended Advance Amount<input type="text" name="amended_advance_amount"></label>
      <label>Amended Balance Terms — legal document wording (free text, leave blank if unchanged)<input type="text" name="amended_balance_terms" style="width:100%"></label>
      <label>Amended Balance Trigger (leave blank if unchanged — this is what actually changes future PI/CI documents)
        <select name="amended_balance_trigger_option">
          <option value="">— unchanged —</option>
          <option value="A_BEFORE_SHIPMENT">Before Shipment (against shipment readiness confirmation)</option>
          <option value="B_AGAINST_BL">Against Bill of Lading</option>
        </select>
      </label>
      <label>Amended Balance Days (leave blank if unchanged)<input type="number" name="amended_balance_days" min="1"></label>
      <label>Amended Balance Amount<input type="text" name="amended_balance_amount"></label>
      <label>Effective From<input type="date" name="effective_from"></label>
      <button type="submit" class="btn-sm">Create Amendment Request</button>
    </form>
  </div>

  <div class="section">
    <h2>Amendment History</h2>
    <table class="list">
      <tr><th>Reference</th><th>Reason</th><th>Status</th><th>MD Approved</th><th>Created</th><th>Actions</th></tr>
      <?php foreach ($amendments as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['amendment_reference']) ?></td>
        <td><?= htmlspecialchars($a['reason']) ?></td>
        <td><?= htmlspecialchars($a['status']) ?></td>
        <td><?= htmlspecialchars($a['md_approved_at'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['created_at']) ?></td>
        <td>
          <?php if ($a['status'] === 'pending'): ?>
            <form method="post" action="/amendments/<?= (int) $a['id'] ?>/md-approve" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-success">MD Approve</button>
            </form>
            <form method="post" action="/amendments/<?= (int) $a['id'] ?>/reject" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Reject</button>
            </form>
          <?php elseif (in_array($a['status'], ['md_approved', 'signed'], true)): ?>
            <?php if ($a['document_id'] === null): ?>
              <form method="post" action="/amendments/<?= (int) $a['id'] ?>/generate-document" style="display:inline">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm">Generate Agreement Document</button>
              </form>
            <?php else: ?>
              <a href="/documents/<?= (int) $a['document_id'] ?>/download?format=pdf" class="btn-sm">Download Agreement PDF</a>
              <form method="post" action="/amendments/<?= (int) $a['id'] ?>/signed-copy" enctype="multipart/form-data" style="display:inline">
                <?= Csrf::field() ?>
                <input type="file" name="signed_copy" required>
                <button type="submit" class="btn-sm btn-success">Upload Signed Copy &amp; Activate</button>
              </form>
            <?php endif; ?>
          <?php elseif ($a['status'] === 'active'): ?>
            <span class="muted">Active — payment terms updated.</span>
            <?php if ($a['document_id']): ?> <a href="/documents/<?= (int) $a['document_id'] ?>/download?format=pdf">Agreement PDF</a><?php endif; ?>
          <?php else: ?>
            <span class="muted"><?= htmlspecialchars($a['status']) ?></span>
          <?php endif; ?>
          <?php if ($canEditLockedData): ?>
            <details style="margin-top:0.4rem;">
              <summary class="muted small">Admin override reference</summary>
              <form method="post" action="/amendments/<?= (int) $a['id'] ?>/override-reference" onsubmit="return confirmFieldOverride(this, 'this amendment\'s reference number');">
                <?= Csrf::field() ?>
                <input type="text" name="amendment_reference" value="<?= htmlspecialchars($a['amendment_reference']) ?>">
                <textarea class="override-reason" name="reason" rows="2" placeholder="Reason for this change *" required></textarea>
                <button type="submit" class="btn-sm btn-danger">Override</button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($amendments)): ?>
        <tr><td colspan="6" class="muted">No amendments on this order.</td></tr>
      <?php endif; ?>
    </table>
  </div>
  <script>
  function confirmFieldOverride(form, label) {
    var reasonEl = form.querySelector('.override-reason');
    if (!reasonEl || reasonEl.value.trim() === '') {
      alert('A reason is required before saving.');
      return false;
    }
    return confirm('Override ' + label + '? This is logged and cannot be undone through this screen.');
  }
  </script>
</div>
