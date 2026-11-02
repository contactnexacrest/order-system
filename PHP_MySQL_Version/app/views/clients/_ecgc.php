<?php
/**
 * Owner decision (2 Nov 2026, gap-list Q-6b): the buyer's ECGC-approved credit limit.
 * Node twin: views/clients/_ecgc.njk (same text).
 */
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\ComplianceTrackingService;
use App\Services\PermissionService;

$__u = AuthService::currentUser();
$__rid = $__u['role_id'] !== null ? (int) $__u['role_id'] : null;
$__ecgcEdit = PermissionService::can((int) $__u['id'], $__rid, 'manage_export_benefit_claims') || PermissionService::can((int) $__u['id'], $__rid, 'ca_module_manage');
$__ecgcSee = $__ecgcEdit || PermissionService::can((int) $__u['id'], $__rid, 'manage_order_financials');
$__money = !empty($sees['selling']);
if ($__ecgcSee):
  $__p = ComplianceTrackingService::ecgcPosition((int) $client['id']);
?>
  <div class="section" id="ecgc">
    <h2>ECGC credit limit</h2>
    <p class="muted small">ECGC policy <?= htmlspecialchars($__p['policy_no'] !== '' ? $__p['policy_no'] : 'not set (Admin → Company Settings)') ?><?= $__p['cover_pct'] !== '' ? ' · cover ' . htmlspecialchars($__p['cover_pct']) . '%' : '' ?>. Shipments to this buyer are covered up to the limit ECGC approved; the open orders below count against it.</p>
    <?php if ($__p['limit'] !== null): ?>
      <p>Approved limit: <strong><?= $__money ? htmlspecialchars((string) $__p['currency']) . ' ' . number_format((float) $__p['limit'], 2) : 'recorded' ?></strong>
        <?= $__p['approved_on'] ? ' · approved ' . htmlspecialchars((string) $__p['approved_on']) : '' ?><?= $__p['valid_to'] ? ' · valid until ' . htmlspecialchars((string) $__p['valid_to']) : '' ?><?= $__p['ref'] ? ' · ref ' . htmlspecialchars((string) $__p['ref']) : '' ?>
        <?php if ($__p['file_id']): ?> · <a href="/file-store/<?= (int) $__p['file_id'] ?>/download">approval letter</a><?php endif; ?></p>
      <?php if ($__money): ?><p class="muted small">Open orders: <?php if ($__p['exposure'] === []): ?>none<?php else: foreach ($__p['exposure'] as $__c => $__a): ?><?= htmlspecialchars((string) $__c) ?> <?= number_format((float) $__a, 2) ?> <?php endforeach; endif; ?> (<?= (int) $__p['open_orders'] ?> order(s), value not yet received).</p><?php endif; ?>
    <?php else: ?>
      <p class="muted">No ECGC limit recorded for this buyer.</p>
    <?php endif; ?>
    <?php if ($__p['warning'] !== null): ?>
      <p class="review-banner warn">&#9888; <?= $__money ? htmlspecialchars($__p['warning']) : 'Open orders are above (or outside) the ECGC-approved limit — check with Finance.' ?></p>
    <?php endif; ?>
    <?php if ($__ecgcEdit): ?>
    <details<?= $__p['limit'] === null ? ' open' : '' ?>><summary><?= $__p['limit'] === null ? 'Record the ECGC limit' : 'Change the ECGC limit' ?></summary>
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/ecgc" enctype="multipart/form-data" class="field-grid">
      <?= Csrf::field() ?>
      <label>Limit approved by ECGC *<input type="number" step="0.01" min="0" name="ecgc_limit_amount" required value="<?= $__p['limit'] !== null ? htmlspecialchars((string) $__p['limit']) : '' ?>" placeholder="e.g. 50000.00"></label>
      <label>Currency *<input type="text" name="ecgc_limit_currency" required pattern="[A-Za-z]{3}" maxlength="3" value="<?= htmlspecialchars((string) ($__p['currency'] ?? '')) ?>" placeholder="e.g. USD"></label>
      <label>Approved on<input type="date" name="ecgc_limit_approved_on" value="<?= htmlspecialchars((string) ($__p['approved_on'] ?? '')) ?>"></label>
      <label>Valid until<input type="date" name="ecgc_limit_valid_to" value="<?= htmlspecialchars((string) ($__p['valid_to'] ?? '')) ?>"></label>
      <label>ECGC approval reference<input type="text" name="ecgc_limit_ref" maxlength="100" value="<?= htmlspecialchars((string) ($__p['ref'] ?? '')) ?>" placeholder="e.g. ECGC/CL/2026/1182"></label>
      <label>Approval letter<input type="file" name="ecgc_limit_file" accept=".pdf,.jpg,.jpeg,.png"></label>
      <div><button type="submit" class="btn-sm">Save ECGC limit</button></div>
    </form>
    </details>
    <?php endif; ?>
  </div>
<?php endif; ?>
