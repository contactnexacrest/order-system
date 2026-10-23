<?php use App\Helpers\Dates; ?>
<?php
// Short, factual one-liner per status group — a client who already has an
// order in a given bucket benefits from knowing what it means; a group with
// no orders is simply never rendered, so nothing hypothetical is ever shown.
$__groupDescriptions = [
    'Active'    => 'Currently moving through our pipeline.',
    'Complete'  => 'Shipped and finished.',
    'Disputed'  => 'Being reviewed by our team.',
    'Lost'      => 'Did not go ahead.',
    'Cancelled' => 'Called off.',
];
?>
<div class="card page-wide">
  <h1>My Orders</h1>
  <?php if ($historyHidden): ?>
    <p class="muted">Welcome, <?= htmlspecialchars($client['company_legal_name']) ?>. Below are your current orders with NexaCrest.</p>
  <?php else: ?>
    <p class="muted">Welcome, <?= htmlspecialchars($client['company_legal_name']) ?>. Below are all your orders with NexaCrest.</p>
  <?php endif; ?>

  <?php if (empty($orderGroups)): ?>
    <p class="muted">No orders yet.</p>
  <?php else: ?>
    <?php foreach ($orderGroups as $group): ?>
    <div class="section">
      <h2><?= htmlspecialchars($group['label']) ?> (<?= count($group['orders']) ?>)</h2>
      <?php if (isset($__groupDescriptions[$group['label']])): ?>
        <p class="muted small"><?= htmlspecialchars($__groupDescriptions[$group['label']]) ?></p>
      <?php endif; ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Stage</th><th>Created</th><th></th></tr>
        <?php foreach ($group['orders'] as $o): ?>
        <tr>
          <td><?= htmlspecialchars($o['order_reference'] ?? ('#' . $o['id'])) ?></td>
          <td><?= htmlspecialchars($o['current_stage_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars(Dates::human($o['created_at'])) ?></td>
          <td><a href="/client/orders/<?= (int) $o['id'] ?>">View documents</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
