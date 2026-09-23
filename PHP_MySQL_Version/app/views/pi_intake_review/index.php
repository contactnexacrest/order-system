<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>PI Intake Review</h1>
  <p class="muted">Submissions from the per-order PI-stage form (<code>/pi-details/&lt;token&gt;</code>), generated per-order from the order screen. Accepting overwrites the client's identity fields (consignee, notify party, VAT/EORI, phone, confirmed contact/billing) with what the client confirmed here — this is the "must match official documents" checkpoint — and sets the order's Buyer PO ref if provided. Everything else (payment-terms confirmation, quotation acceptance reference, confirmed Incoterm/port/COO, changes, special requirements) stays on the submission for you to read before generating the PI.</p>

  <div class="section">
    <h2>Pending (<?= count($pending) ?>)</h2>
    <table class="list">
      <tr><th>Order</th><th>Company</th><th>Consignee</th><th>Confirmation</th><th>Submitted</th><th>Action</th></tr>
      <?php foreach ($pending as $s): ?>
      <tr>
        <td><a href="/orders/<?= (int) $s['order_id'] ?>"><?= htmlspecialchars($s['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($s['company_legal_name']) ?><br><span class="muted small"><?= htmlspecialchars($s['contact_person']) ?> · <?= htmlspecialchars($s['email']) ?></span></td>
        <td><?= htmlspecialchars($s['consignee_name']) ?><br><span class="muted small">Notify: <?= htmlspecialchars($s['notify_party'] ?: '—') ?></span></td>
        <td>
          <span class="muted small">Incoterm: <?= htmlspecialchars($s['incoterm_confirmed']) ?></span><br>
          <span class="muted small">Port: <?= htmlspecialchars($s['port_of_discharge_text']) ?></span><br>
          <span class="muted small"><?= htmlspecialchars($s['quotation_acceptance_reference']) ?></span><br>
          <span class="muted small"><?= htmlspecialchars($s['payment_terms_confirmation']) ?></span>
          <?php if ($s['changes_from_quotation']): ?><br><span class="muted small">Changes: <?= htmlspecialchars($s['changes_from_quotation']) ?></span><?php endif; ?>
          <?php if ($s['buyer_po_ref']): ?><br><span class="muted small">Buyer PO: <?= htmlspecialchars($s['buyer_po_ref']) ?></span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars((string) $s['submitted_at']) ?></td>
        <td>
          <form method="post" action="/pi-intake-review/<?= (int) $s['id'] ?>/accept" style="display:inline" onsubmit="return confirm('Accept and apply these details to the client and order?');">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-success">Accept &amp; Apply</button>
          </form>
          <form method="post" action="/pi-intake-review/<?= (int) $s['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this PI-stage submission?');">
            <?= Csrf::field() ?>
            <input type="text" name="reason" placeholder="Reason (required)" required style="width:160px">
            <button type="submit" class="btn-sm btn-danger">Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="6" class="muted">No pending submissions.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Recently Resolved</h2>
    <table class="list">
      <tr><th>Order</th><th>Company</th><th>Status</th><th>Resolved By</th><th>When</th><th>Result</th></tr>
      <?php foreach ($resolved as $s): ?>
      <tr>
        <td><a href="/orders/<?= (int) $s['order_id'] ?>"><?= htmlspecialchars($s['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($s['company_legal_name'] ?? $s['client_company_legal_name']) ?></td>
        <td><?= htmlspecialchars(ucfirst($s['status'])) ?></td>
        <td><?= htmlspecialchars($s['reviewed_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) $s['reviewed_at']) ?></td>
        <td><?= $s['status'] === 'rejected' ? htmlspecialchars($s['rejection_reason'] ?? '') : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($resolved)): ?>
        <tr><td colspan="6" class="muted">Nothing resolved yet.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
