<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders/<?= (int) $order['id'] ?>">&larr; Back to Order</a></p>
  <h1>Disputes — <?= htmlspecialchars($order['order_reference']) ?></h1>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name']) ?></p>

  <div class="section">
    <h2>Raise a Dispute</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/disputes">
      <?= Csrf::field() ?>
      <label>Notice Date *<input type="date" name="notice_date" value="<?= date('Y-m-d') ?>" required></label>
      <label>From<input type="text" name="from_party" placeholder="e.g. Buyer, via email"></label>
      <label>Description *<textarea name="description" required rows="3" style="width:100%"></textarea></label>
      <label>Assign To
        <select name="assigned_to">
          <option value="">Unassigned</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-sm">Log Dispute</button>
    </form>
  </div>

  <div class="section">
    <h2>Dispute Log</h2>
    <?php foreach ($disputes as $d): ?>
      <details>
        <summary><?= htmlspecialchars($d['notice_date']) ?> — <?= htmlspecialchars($d['status']) ?> — <?= htmlspecialchars(mb_strimwidth($d['description'], 0, 80, '…')) ?></summary>
        <div class="kv-grid">
          <div><span class="k">From</span><span class="v"><?= htmlspecialchars($d['from_party'] ?? '—') ?></span></div>
          <div><span class="k">Response Due</span><span class="v"><?= htmlspecialchars($d['response_due_date'] ?? '—') ?></span></div>
          <div><span class="k">Resolved At</span><span class="v"><?= htmlspecialchars($d['resolved_at'] ?? '—') ?></span></div>
        </div>
        <p><?= nl2br(htmlspecialchars($d['description'])) ?></p>
        <?php if ($d['resolution_notes']): ?><p><strong>Resolution:</strong> <?= nl2br(htmlspecialchars($d['resolution_notes'])) ?></p><?php endif; ?>

        <form method="post" action="/disputes/<?= (int) $d['id'] ?>/status">
          <?= Csrf::field() ?>
          <label>Status
            <select name="status">
              <?php foreach ($statusOptions as $opt): ?>
                <option value="<?= htmlspecialchars($opt['option_value']) ?>" <?= $opt['option_value'] === $d['status'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['option_value']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Resolution Notes<input type="text" name="resolution_notes" style="width:100%"></label>
          <button type="submit" class="btn-sm">Update</button>
        </form>

        <form method="post" action="/disputes/<?= (int) $d['id'] ?>/documents" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="file" name="document" required>
          <input type="text" name="received_from" placeholder="Received from (optional)">
          <button type="submit" class="btn-sm">Attach Document</button>
        </form>
      </details>
    <?php endforeach; ?>
    <?php if (empty($disputes)): ?>
      <p class="muted">No disputes on this order.</p>
    <?php endif; ?>
  </div>
</div>
