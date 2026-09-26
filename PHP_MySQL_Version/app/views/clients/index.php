<?php
use App\Config\Env;
use App\Helpers\Mask;
use App\Services\AuthService;
use App\Services\PermissionService;
$__u = AuthService::currentUser();
$canViewFullEmail = $__u && PermissionService::can((int) $__u['id'], $__u['role_id'] !== null ? (int) $__u['role_id'] : null, 'view_client_email_full');
$quotationFormLink = rtrim(Env::get('APP_URL', ''), '/') . '/quotation-details';
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Clients</h1>
      <p class="muted" style="margin:0;">Each client carries one Buyer Inquiry Ref for the whole relationship — every order and document for them reuses it.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn btn-accent" href="/clients/create">+ New Client</a>
      <a class="btn-sm btn-secondary" href="/clients/inactive">Deactivated Clients</a>
    </div>
  </div>

  <div class="review-banner info" style="flex-direction:column;align-items:stretch;gap:6px">
    <span>Public Quotation Form — send this to a new lead so they can submit their own details (lands in Quotation Intake Review):</span>
    <div style="display:flex;gap:6px;align-items:center">
      <input type="text" readonly value="<?= htmlspecialchars($quotationFormLink) ?>" onclick="this.select()" style="flex:1;font-family:monospace;font-size:12px">
      <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($quotationFormLink, ENT_QUOTES) ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy Link', 1500);">Copy Link</button>
    </div>
  </div>

  <?php if (empty($clients)): ?>
    <p class="muted">No clients yet.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($clients as $c): ?>
    <div class="entity-card entity-card-linked">
      <div class="entity-card-header">
        <a class="entity-card-stretched-link" href="/clients/<?= (int) $c['id'] ?>"><span class="entity-card-title"><?= htmlspecialchars($c['company_legal_name']) ?></span></a>
        <span class="tag tag-navy"><?= (int) $c['order_count'] ?> order<?= (int) $c['order_count'] === 1 ? '' : 's' ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars($c['client_unique_number']) ?></div>
      <div class="entity-card-sub">
        <?= htmlspecialchars($c['contact_person'] ?? '—') ?>
        <?php if ($c['email']): ?>
          &middot;
          <?php if ($canViewFullEmail): ?>
            <span class="masked-value"><?= htmlspecialchars($c['email']) ?></span>
          <?php else: ?>
            <span class="masked-value"><?= htmlspecialchars(Mask::email($c['email'])) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="entity-card-footer">
        <span class="muted small"><?= htmlspecialchars($c['country_of_destination'] ?? 'Country not set') ?></span>
        <a class="entity-card-action" href="/clients/<?= (int) $c['id'] ?>/edit">Edit</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
