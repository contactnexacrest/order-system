<?php $cm = $o['commission']; ?>
<?php // D-292: the % fixed on the order (never a level name) and the owner's statuses. ?>
<?= $cm['amount'] !== null ? htmlspecialchars((string) $o['currency_code']) . ' ' . number_format((float) $cm['amount'], 2) . (!empty($cm['rate_text']) ? ' <small class="muted">(' . htmlspecialchars((string) $cm['rate_text']) . ')</small>' : '') . '<br>' : '' ?>
<?php $__b = \App\Repositories\AssociatePortalRepository::badge($cm); // D-410: 🔵 To be paid / 🟢 Paid ?>
<span class="badge <?= $__b === 'paid' ? 'badge-active' : ($__b === 'to_be_paid' ? 'badge-tobepaid' : (['held' => 'badge-lost', 'expected' => 'badge-pending'][$cm['state']] ?? 'badge-inactive')) ?>" data-badge="<?= htmlspecialchars((string) $__b) ?>"><?= $__b === 'paid' ? '🟢 ' : ($__b === 'to_be_paid' ? '🔵 ' : '') ?><?= htmlspecialchars($cm['label']) ?></span>
<?php if (!empty($cm['fixed_inr']) && !empty($cm['fixed_rate']) && $cm['state'] !== 'paid' && \App\Services\AssociatePayoutService::isIndia($me ?? null)): // D-264: India only, once fixed ?>
<br><small data-fixed-rupees><?= htmlspecialchars(\App\Services\AssociatePayoutService::fixedRupeeLine((float) $cm['fixed_inr'], (float) $cm['fixed_rate'], (string) $cm['currency'], (string) $cm['fixed_on'])) ?></small>
<?php endif; ?>
