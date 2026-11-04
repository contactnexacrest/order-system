<?php $cm = $o['commission']; ?>
<?= $cm['amount'] !== null ? htmlspecialchars((string) $o['currency_code']) . ' ' . number_format((float) $cm['amount'], 2) . (!empty($cm['level']) ? ' <small class="muted">(' . htmlspecialchars((string) $cm['level']) . ')</small>' : '') . '<br>' : '' ?>
<span class="badge <?= ['paid' => 'badge-active', 'earned' => 'badge-active', 'held' => 'badge-lost', 'expected' => 'badge-pending'][$cm['state']] ?? 'badge-inactive' ?>"><?= htmlspecialchars($cm['label']) ?></span>
