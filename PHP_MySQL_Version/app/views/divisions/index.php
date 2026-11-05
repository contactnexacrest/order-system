<?php /* Owner decision D-257: Admin › Divisions (MD only). */ ?>
<div class="card page-wide">
  <h1>Divisions</h1>
  <p class="muted">One company, several divisions. Every order belongs to one division for life; staff see only their own division. Divisions stay invisible to everyone until two or more are active. A planned division cannot be used until it is activated here.</p>
  <div class="table-scroll">
  <table class="list" data-testid="divisions-table">
    <tr><th>Code</th><th>Division</th><th>Status</th><th>Orders</th><th>Staff</th><th>Departments</th><th></th></tr>
    <?php foreach ($divisions as $d): $planned = $d['status'] !== 'active'; ?>
    <tr class="<?= $planned ? 'division-planned' : '' ?>" style="<?= $planned ? 'opacity:.6' : '' ?>">
      <td><code><?= htmlspecialchars((string) $d['code']) ?></code></td>
      <td><strong><?= htmlspecialchars((string) $d['name']) ?></strong><br><small class="muted"><?= htmlspecialchars((string) ($d['description'] ?? '')) ?></small></td>
      <td><span class="badge <?= $planned ? 'badge-inactive' : 'badge-success' ?>"><?= $planned ? 'Planned' : 'Active' ?></span></td>
      <td><?= (int) $d['counts']['orders'] ?></td>
      <td><?= (int) $d['counts']['staff'] ?></td>
      <td><?= (int) $d['counts']['departments'] ?></td>
      <td><a href="/admin/divisions/<?= (int) $d['id'] ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
