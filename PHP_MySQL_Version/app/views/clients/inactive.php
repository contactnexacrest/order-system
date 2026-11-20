<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Deactivated Clients</h1>
  <p class="muted">Deactivating only removes a client from the main Clients list — nothing is ever deleted, and their orders are untouched. Reactivate any time to bring them back.</p>
  <div class="btn-row"><a class="btn-sm btn-secondary" href="/clients">Back to Clients</a></div>

  <?php if (empty($clients)): ?>
    <p class="muted">No deactivated clients.</p>
  <?php else: ?>
  <table class="list">
    <?php // Owner decision D-394 (mockup 8): an expired prospect shows its badge and why it was closed. ?>
    <tr><th>Buyer Inquiry Ref</th><th>Company</th><th>Contact</th><th>Country</th><th>Why</th><th></th></tr>
    <?php foreach ($clients as $c): ?>
    <?php $__isProspect = (int) ($c['is_prospect'] ?? 0) === 1; ?>
    <tr>
      <td><?= htmlspecialchars($c['client_unique_number']) ?></td>
      <td><?= htmlspecialchars($c['company_legal_name']) ?><?php if ($__isProspect): ?> <span class="tag tag-gold" data-testid="prospect-badge">Prospect</span><?php endif; ?></td>
      <td><?= htmlspecialchars($c['contact_person'] ?? '—') ?></td>
      <td><?= htmlspecialchars($c['country_of_destination'] ?? '—') ?></td>
      <td><?= htmlspecialchars((string) ($c['prospect_expired_reason'] ?? '') !== '' ? (string) $c['prospect_expired_reason'] : '—') ?></td>
      <td>
        <a href="/clients/<?= (int) $c['id'] ?>">View</a>
        <form method="post" action="/clients/<?= (int) $c['id'] ?>/toggle-active" style="display:inline" onsubmit="return confirm('Reactivate <?= htmlspecialchars(addslashes($c['company_legal_name'])) ?>?');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success"><?= $__isProspect ? 'Reactivate (fresh ' . \App\Services\ProspectService::expiryDays() . ' days)' : 'Reactivate' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
