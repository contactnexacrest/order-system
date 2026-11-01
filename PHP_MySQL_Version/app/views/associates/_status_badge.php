<?php
$__cls = ['invited' => 'badge-pending', 'active' => 'badge-active', 'suspended' => 'badge-lost', 'ended' => 'badge-inactive', 'inactive' => 'badge-inactive'][$__st] ?? '';
?><span class="badge <?= $__cls ?>"><?= htmlspecialchars(\App\Repositories\AssociateRepository::STATUSES[$__st] ?? $__st) ?></span>
