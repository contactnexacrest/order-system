<?php
/** @var string $__st one of AgreementRepository::STATUSES */
$__cls = ['expiring' => 'badge-warning', 'active' => 'badge-success', 'no_expiry' => 'badge-active', 'expired' => 'badge-danger', 'terminated' => 'badge-inactive', 'renewed' => 'badge-inactive'][$__st] ?? '';
?><span class="badge <?= $__cls ?>"><?= htmlspecialchars(\App\Repositories\AgreementRepository::STATUSES[$__st] ?? $__st) ?></span>
