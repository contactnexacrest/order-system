<?php
/**
 * Owner decision 1 Nov 2026 (guarantee 2): staff see first that a client is
 * an associate's — on the client page and the order page. Only for those
 * who may see associates; no amounts. Needs $__clientId.
 */
$__au = \App\Services\AuthService::currentUser();
$__ar = $__au ? ($__au['role_id'] !== null ? (int) $__au['role_id'] : null) : null;
$__aCan = $__au && (\App\Services\PermissionService::can((int) $__au['id'], $__ar, 'view_associates') || \App\Services\PermissionService::can((int) $__au['id'], $__ar, 'manage_associates'));
$__aRow = null;
if ($__aCan) {
    $__st = \App\Config\Database::connection()->prepare('SELECT a.id, a.name, c.associate_registered_on, c.associate_locked_until FROM clients c JOIN associates a ON a.id = c.associate_id WHERE c.id = :c');
    $__st->execute(['c' => (int) $__clientId]);
    $__aRow = $__st->fetch() ?: null;
}
if ($__aRow !== null): ?>
  <div class="alert alert-info associate-line">&#129309; Associate client — introduced by <a href="/associates/<?= (int) $__aRow['id'] ?>"><?= htmlspecialchars((string) $__aRow['name']) ?></a>
    <?php if ($__aRow['associate_registered_on']): ?> on <?= htmlspecialchars(\App\Helpers\Dates::short((string) $__aRow['associate_registered_on'])) ?><?php endif; ?><?php if ($__aRow['associate_locked_until']): ?>, protected until <?= htmlspecialchars(\App\Helpers\Dates::short((string) $__aRow['associate_locked_until'])) ?><?php endif; ?>. Only the MD can change this.</div>
<?php endif; ?>
