<?php
/**
 * Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N" on the order page.
 * A record saved in the app goes to Zoho Books at the next sync (hourly, or
 * Sync now). Shown to ca_module_manage / ca_module_view holders while Zoho is
 * on; nothing while it is off. Expects $zohoWaiting (ZohoPendingService::counts)
 * and $order; $__zwLink true links to the order's Zoho check (ca_module_manage).
 */
if (!empty($zohoWaiting) && !empty($zohoWaiting['enabled'])):
    $__zwN = (int) $zohoWaiting['total'];
    $__zwText = $__zwN === 0 ? 'Waiting for Zoho: 0 — everything on this order is in Zoho Books' : 'Waiting for Zoho: ' . $__zwN;
    $__zwTitle = $__zwN === 0 ? '' : \App\Services\ZohoPendingService::summary($zohoWaiting['by_type']) . ' — sent at the next sync (hourly, or Sync now).';
?>
<span class="badge <?= $__zwN === 0 ? 'badge-success' : 'badge-warning' ?> zoho-waiting" data-zoho-waiting="<?= $__zwN ?>"<?= $__zwTitle !== '' ? ' title="' . htmlspecialchars($__zwTitle) . '"' : '' ?>><?php if (!empty($__zwLink)): ?><a href="/orders/<?= (int) $order['id'] ?>/zoho-check"><?= htmlspecialchars($__zwText) ?></a><?php else: ?><?= htmlspecialchars($__zwText) ?><?php endif; ?></span>
<?php if ($__zwN > 0): ?><span class="muted small"><?= htmlspecialchars(\App\Services\ZohoPendingService::summary($zohoWaiting['by_type'])) ?> — sent at the next sync (hourly, or Sync now).</span><?php endif; ?>
<?php endif; ?>
