<?php use App\Helpers\Csrf;
$qty = rtrim(rtrim((string) $r['quantity'], '0'), '.');
$inr = static fn ($v): string => '&#8377;' . number_format((float) $v, 2); ?>
<div class="card page-wide">
  <p class="muted small"><a href="/procurement">&larr; Procurement</a> · <a href="/orders/<?= (int) $r['order_id'] ?>">Order <?= htmlspecialchars((string) $r['order_reference']) ?></a></p>
  <h1>PR-<?= (int) $r['id'] ?> · <?= htmlspecialchars((string) $r['description']) ?></h1>
  <div class="kv-grid">
    <div><span class="k">Quantity</span><span class="v"><?= htmlspecialchars($qty) ?> <?= htmlspecialchars((string) ($r['unit'] ?? '')) ?></span></div>
    <div><span class="k">Needed by</span><span class="v"><?= htmlspecialchars((string) $r['needed_by']) ?></span></div>
    <div><span class="k">Status</span><span class="v"><?= htmlspecialchars($statuses[$r['status']] ?? $r['status']) ?></span></div>
    <?php if ($r['specification']): ?><div><span class="k">Specification</span><span class="v"><?= htmlspecialchars((string) $r['specification']) ?></span></div><?php endif; ?>
    <?php if ($r['note']): ?><div><span class="k">Note from Sales</span><span class="v"><?= htmlspecialchars((string) $r['note']) ?></span></div><?php endif; ?>
    <?php if ($r['awarded_supplier_name']): ?><div><span class="k">Awarded to</span><span class="v"><?= htmlspecialchars((string) $r['awarded_supplier_name']) ?><?= $awardValue !== null && $sees['purchase'] ? ' — ' . $inr($awardValue) . ' incl. GST' : '' ?></span></div><?php endif; ?>
    <?php if ($r['status'] === 'received'): ?><div><span class="k">Received</span><span class="v"><?= htmlspecialchars(rtrim(rtrim((string) $r['received_quantity'], '0'), '.')) ?> on <?= htmlspecialchars((string) $r['received_on']) ?> · QC <?= htmlspecialchars($qcResults[$r['qc_result']] ?? '') ?><?= $r['qc_note'] ? ' — ' . htmlspecialchars((string) $r['qc_note']) : '' ?></span></div><?php endif; ?>
  </div>
</div>

<?php if ($sees['purchase']): ?>
<div class="card page-wide">
  <h2>Supplier quotes (cheapest first, with GST)</h2>
  <table class="list">
    <tr><th>Supplier</th><th>Unit price</th><th>GST</th><th>Unit price with GST</th><th>Total for <?= htmlspecialchars($qty) ?></th><th>Lead time</th><th>Valid until</th><th>Ref</th><th></th></tr>
    <?php foreach ($quotes as $i => $q): $isAwarded = (int) $q['id'] === (int) $r['awarded_quote_id']; ?>
    <tr<?= $isAwarded ? ' style="background:var(--success-soft)"' : '' ?>>
      <td><?= htmlspecialchars((string) $q['supplier_legal_name']) ?><?= $i === 0 ? ' <span class="badge badge-active">lowest</span>' : '' ?></td>
      <td><?= $inr($q['unit_price_inr']) ?></td>
      <td><?= htmlspecialchars((string) (float) $q['gst_percent']) ?>%</td>
      <td><?= $inr($q['unit_price_with_gst_inr']) ?></td>
      <td><?= $inr(\App\Services\PurchasingService::awardValue($q, (float) $r['quantity'])) ?></td>
      <td><?= (int) $q['lead_time_days'] ?> days</td>
      <td><?= htmlspecialchars((string) ($q['valid_until'] ?? '—')) ?></td>
      <td><?= htmlspecialchars((string) ($q['quote_reference'] ?? '')) ?></td>
      <td>
        <?php if ($isAwarded): ?><strong>Awarded</strong>
        <?php elseif ($canWork && in_array($r['status'], ['quoted', 'awarded'], true)): ?>
          <form method="post" action="/procurement/<?= (int) $r['id'] ?>/award" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><button type="submit" class="btn-sm btn-success">Award</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($quotes === []): ?><tr><td colspan="9" class="muted">No quotes yet.</td></tr><?php endif; ?>
  </table>
  <?php if ($myLimit !== null): ?><p class="muted small">Your approval limit as <?= htmlspecialchars((string) (\App\Services\ActiveDepartmentService::actingLabel((int) \App\Services\AuthService::currentUserId()) ?? '')) ?>: <?= $inr($myLimit) ?>. An award above it waits for an approver.</p><?php endif; ?>

  <?php if ($canWork && in_array($r['status'], ['open', 'quoted'], true)): ?>
  <h3>Record a supplier quote</h3>
  <form method="post" action="/procurement/<?= (int) $r['id'] ?>/quotes" class="field-grid">
    <?= Csrf::field() ?>
    <label>Supplier *
      <select name="supplier_id" required>
        <option value="">— choose —</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['supplier_legal_name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Unit price (&#8377;, before GST) *<input type="number" name="unit_price_inr" step="0.01" min="0.01" required></label>
    <label>GST % *<input type="number" name="gst_percent" step="0.01" min="0" max="28" value="0" required></label>
    <label>Lead time (days) *<input type="number" name="lead_time_days" step="1" min="0" max="365" required></label>
    <label>Valid until<input type="date" name="valid_until" min="<?= date('Y-m-d') ?>"></label>
    <label>Supplier's quote ref<input type="text" name="quote_reference" maxlength="100"></label>
    <label>Note<input type="text" name="note" maxlength="500"></label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-accent">+ Record quote</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($r['status'] === 'awarded'): ?>
<div class="card page-wide">
  <h2>Approval</h2>
  <?php if ($approvalRefusal === null): ?>
    <form method="post" action="/procurement/<?= (int) $r['id'] ?>/approve"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-success">Approve this purchase</button></form>
  <?php else: ?>
    <p class="muted"><?= htmlspecialchars($approvalRefusal) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canWork && $r['status'] === 'approved'): ?>
<div class="card page-wide">
  <h2>Receipt and quality check</h2>
  <form method="post" action="/procurement/<?= (int) $r['id'] ?>/receive" class="field-grid">
    <?= Csrf::field() ?>
    <label>Quantity received *<input type="number" name="received_quantity" step="0.001" min="0.001" value="<?= htmlspecialchars($qty) ?>" required></label>
    <label>Received on *<input type="date" name="received_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label>
    <label>Quality check *
      <select name="qc_result" required><option value="">— choose —</option><?php foreach ($qcResults as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
    </label>
    <label>What the check found <small class="muted">(needed unless passed)</small><input type="text" name="qc_note" maxlength="500"></label>
    <div class="btn-row"><button type="submit" class="btn-sm btn-success">Record receipt</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($canWork && !in_array($r['status'], ['received', 'cancelled'], true)): ?>
<div class="card page-wide">
  <details>
    <summary>Cancel this requirement</summary>
    <form method="post" action="/procurement/<?= (int) $r['id'] ?>/cancel" class="field-grid">
      <?= Csrf::field() ?>
      <label>Reason *<input type="text" name="reason" required minlength="<?= \App\Helpers\ReasonValidator::MIN_LENGTH ?>" maxlength="500"></label>
      <div class="btn-row"><button type="submit" class="btn-sm btn-danger">Cancel requirement</button></div>
    </form>
  </details>
</div>
<?php endif; ?>
