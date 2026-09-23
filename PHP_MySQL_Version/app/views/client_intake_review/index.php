<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Quotation Requests</h1>
  <p class="muted">Submissions from the public quotation-details form (<code>/quotation-details</code>). Nothing here becomes a real client or order until you accept it — accepting creates the client record and takes you to it, ready for you to create the order and generate the Quotation yourself.</p>

  <div class="section">
    <h2>Pending (<?= count($pending) ?>)</h2>
    <table class="list">
      <tr><th>Company</th><th>Contact</th><th>Destination</th><th>Submitted</th><th>Action</th></tr>
      <?php foreach ($pending as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['company_legal_name']) ?><br><span class="muted small"><?= htmlspecialchars($s['billing_address']) ?></span></td>
        <td><?= htmlspecialchars($s['contact_person']) ?><br><span class="muted small"><?= htmlspecialchars($s['email']) ?> · <?= htmlspecialchars($s['phone'] ?? '') ?></span></td>
        <td>
          <?= htmlspecialchars($s['country_of_destination']) ?>
          <?php if ($s['port_of_discharge_text']): ?><br><span class="muted small">Port: <?= htmlspecialchars($s['port_of_discharge_text']) ?></span><?php endif; ?>
          <?php if ($s['incoterm_preference']): ?><br><span class="muted small">Incoterm: <?= htmlspecialchars($s['incoterm_preference']) ?></span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars((string) $s['submitted_at']) ?></td>
        <td>
          <form method="post" action="/client-intake/<?= (int) $s['id'] ?>/accept" style="display:inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-sm btn-success">Accept &amp; Create Client</button>
          </form>
          <form method="post" action="/client-intake/<?= (int) $s['id'] ?>/reject" style="display:inline" onsubmit="return confirm('Reject this quotation request?');">
            <?= Csrf::field() ?>
            <input type="text" name="reason" placeholder="Reason (required)" required style="width:160px">
            <button type="submit" class="btn-sm btn-danger">Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="5" class="muted">No pending requests.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Recently Resolved</h2>
    <table class="list">
      <tr><th>Company</th><th>Status</th><th>Resolved By</th><th>When</th><th>Result</th></tr>
      <?php foreach ($resolved as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['company_legal_name']) ?></td>
        <td><?= htmlspecialchars(ucfirst($s['status'])) ?></td>
        <td><?= htmlspecialchars($s['reviewed_by_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars((string) $s['reviewed_at']) ?></td>
        <td>
          <?php if ($s['status'] === 'converted' && $s['client_unique_number']): ?>
            <a href="/clients/<?= (int) $s['converted_client_id'] ?>"><?= htmlspecialchars($s['client_unique_number']) ?></a>
          <?php elseif ($s['status'] === 'rejected'): ?>
            <?= htmlspecialchars($s['rejection_reason'] ?? '') ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($resolved)): ?>
        <tr><td colspan="5" class="muted">Nothing resolved yet.</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
