<?php
use App\Helpers\Mask;
use App\Services\AuthService;
use App\Services\PermissionService;
$__u = AuthService::currentUser();
$canViewFullEmail = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'view_client_email_full');
?>
<div class="card page-wide">
  <h1>Clients</h1>
  <p class="muted">Each client carries one Buyer Inquiry Ref for the whole relationship — every order and document for them reuses it.</p>
  <div class="btn-row"><a class="btn" href="/clients/create">+ New Client</a> <a class="btn-sm btn-secondary" href="/clients/inactive">Deactivated Clients</a></div>

  <?php if (empty($clients)): ?>
    <p class="muted">No clients yet.</p>
  <?php else: ?>
  <table class="list">
    <tr><th>Buyer Inquiry Ref</th><th>Company</th><th>Contact</th><th>Country</th><th></th></tr>
    <?php foreach ($clients as $c): ?>
    <tr>
      <td><?= htmlspecialchars($c['client_unique_number']) ?></td>
      <td><?= htmlspecialchars($c['company_legal_name']) ?></td>
      <td><?= htmlspecialchars($c['contact_person'] ?? '—') ?>
        <?php if ($c['email']): ?>
          <br>
          <?php if ($canViewFullEmail): ?>
            <span class="muted small"><?= htmlspecialchars($c['email']) ?></span>
          <?php else: ?>
            <span class="muted small masked-value"><?= htmlspecialchars(Mask::email($c['email'])) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($c['country_of_destination'] ?? '—') ?></td>
      <td><a href="/clients/<?= (int) $c['id'] ?>">View</a> &middot; <a href="/clients/<?= (int) $c['id'] ?>/edit">Edit</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
