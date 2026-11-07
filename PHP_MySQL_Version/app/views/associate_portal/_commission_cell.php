<?php $cm = $o['commission']; ?>
<?= $cm['amount'] !== null ? htmlspecialchars((string) $o['currency_code']) . ' ' . number_format((float) $cm['amount'], 2) . (!empty($cm['level']) ? ' <small class="muted">(' . htmlspecialchars((string) $cm['level']) . ')</small>' : '') . '<br>' : '' ?>
<span class="badge <?= ['paid' => 'badge-active', 'earned' => 'badge-active', 'held' => 'badge-lost', 'expected' => 'badge-pending'][$cm['state']] ?? 'badge-inactive' ?>"><?= htmlspecialchars($cm['label']) ?></span>
<?php if (!empty($cm['fixed_inr']) && !empty($cm['fixed_rate']) && $cm['state'] !== 'paid' && \App\Services\AssociatePayoutService::isIndia($me ?? null)): // D-264: India only, once fixed ?>
<br><small data-fixed-rupees><?= htmlspecialchars(\App\Services\AssociatePayoutService::fixedRupeeLine((float) $cm['fixed_inr'], (float) $cm['fixed_rate'], (string) $cm['currency'], (string) $cm['fixed_on'])) ?></small>
<?php endif; ?>
