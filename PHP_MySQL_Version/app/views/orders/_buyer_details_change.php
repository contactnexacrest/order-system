<?php
/**
 * Owner decision D-337 (12 Nov 2026): the order's buyer details compared with
 * the client's current details. Shown only when they differ or a request waits.
 * Nothing issued yet: "Update buyer details from the client" (manage_orders).
 * An issued document: "Ask to update buyer details from the client" with a
 * reason; a holder of approve_buyer_details_change approves or refuses it here
 * (or from Approvals). Node twin: views/orders/_buyer_details_change.njk.
 *
 * @var array $order
 * @var array $currentUser
 * @var bool  $canManageOrders
 */
use App\Helpers\Csrf;
use App\Services\BuyerDetailsRefreshService;
use App\Services\PermissionService;

$__bd = BuyerDetailsRefreshService::noticeFor($order);
if ($__bd !== null):
    $__bdUserId = (int) $currentUser['id'];
    $__bdPending = $__bd['pending'];
    $__bdCanApprove = PermissionService::can($__bdUserId, $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, BuyerDetailsRefreshService::PERMISSION);
    $__bdShow = static fn (array $c, string $k): string => htmlspecialchars(BuyerDetailsRefreshService::display((string) $c['field'], $c[$k]));
?>
<div class="review-banner" id="buyer-details-change" style="flex-direction:column;align-items:stretch;gap:6px">
  <?php if ($__bdPending !== null): ?>
    <strong>Request to update the buyer details from the client — waiting for approval</strong>
    <span class="small">Asked by <?= htmlspecialchars((string) ($__bdPending['requested_by_name'] ?? 'unknown')) ?> on <?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $__bdPending['created_at'], 0, 10))) ?> (request #<?= (int) $__bdPending['id'] ?>). Reason: <?= htmlspecialchars((string) $__bdPending['reason']) ?></span>
    <table class="list small">
      <thead><tr><th>Field</th><th>On this order now</th><th>Client's details (at the request)</th></tr></thead>
      <tbody>
        <?php foreach ($__bdPending['changes'] as $__c): ?>
        <tr><td><?= htmlspecialchars((string) $__c['label']) ?></td><td><?= $__bdShow($__c, 'old') ?></td><td><?= $__bdShow($__c, 'new') ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <span class="muted small">Issued documents keep the details they were issued with; documents generated after approval show the new details.</span>
    <?php if ($__bdCanApprove && !BuyerDetailsRefreshService::selfDecisionRefused($__bdPending, $__bdUserId)): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-details/<?= (int) $__bdPending['id'] ?>/approve" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap" data-confirm="Approve? The order takes the client's current details; issued documents do not change.">
        <?= Csrf::field() ?>
        <label class="small">Approval note (optional)
          <input type="text" name="decision_note" maxlength="500" placeholder="e.g. Checked with the buyer by phone" style="width:260px">
        </label>
        <button type="submit" class="btn-sm btn-success">Approve</button>
      </form>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-details/<?= (int) $__bdPending['id'] ?>/refuse" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap">
        <?= Csrf::field() ?>
        <label class="small">Why it is refused *
          <input type="text" name="decision_note" required minlength="10" maxlength="500" placeholder="e.g. The buyer has not confirmed the new e-mail yet" style="width:320px">
        </label>
        <button type="submit" class="btn-sm btn-danger">Refuse</button>
      </form>
    <?php elseif ((int) ($__bdPending['requested_by'] ?? 0) === $__bdUserId): ?>
      <span class="muted small">Waiting for approval by someone else.</span>
    <?php endif; ?>
  <?php else: ?>
    <strong>The client's details have changed since this order was created</strong>
    <table class="list small">
      <thead><tr><th>Field</th><th>On this order</th><th>Client now</th></tr></thead>
      <tbody>
        <?php foreach ($__bd['changes'] as $__c): ?>
        <tr><td><?= htmlspecialchars((string) $__c['label']) ?></td><td><?= $__bdShow($__c, 'old') ?></td><td><?= $__bdShow($__c, 'new') ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$__bd['issued']): ?>
      <span class="muted small">Nothing has been issued on this order yet, so it can take the client's details at once.</span>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-details/refresh" data-confirm="Copy the client's current details onto this order?">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-sm">Update buyer details from the client</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <span class="muted small">This order already has an issued document, so the change needs approval. Issued documents keep the details they were issued with; documents generated after approval show the new details.</span>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/buyer-details/request" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap">
        <?= Csrf::field() ?>
        <label class="small">Reason *
          <textarea name="reason" required minlength="10" maxlength="500" rows="2" cols="50" placeholder="e.g. The buyer's purchasing e-mail changed; the BL and invoice must go to the new address"></textarea>
        </label>
        <button type="submit" class="btn-sm">Ask to update buyer details from the client</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
