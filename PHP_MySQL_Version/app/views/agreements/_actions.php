<?php
/**
 * The links and buttons of one agreement row — the same on the list, the board and a party's card.
 * @var array $__a the row; $canManage, $canClientFile, $canAssociateFile
 */
?>
<div style="display:flex;flex-wrap:wrap;gap:6px">
<?php if ($__a['source'] === 'register'): ?>
  <?php if (!empty($__a['file_id'])): ?><a class="btn-sm btn-secondary" href="/agreements/<?= (int) $__a['id'] ?>/file">Signed copy</a><?php endif; ?>
  <?php if (!empty($canManage)): ?>
    <a class="btn-sm" href="/agreements/<?= (int) $__a['id'] ?>/edit">Edit</a>
    <?php if (!in_array($__a['status'], ['terminated', 'renewed'], true)): ?><a class="btn-sm btn-secondary" href="/agreements/new?renew=<?= (int) $__a['id'] ?>">Renew</a><?php endif; ?>
  <?php endif; ?>
<?php elseif ($__a['source'] === 'client'): ?>
  <?php if (!empty($canClientFile)): ?><a class="btn-sm btn-secondary" href="/clients/<?= (int) $__a['party_id'] ?>/agreement/download">Signed copy</a><?php endif; ?>
  <a class="btn-sm btn-secondary" href="/clients/<?= (int) $__a['party_id'] ?>">Managed on the client page</a>
<?php else: ?>
  <?php if (!empty($canAssociateFile)): ?><a class="btn-sm btn-secondary" href="/associates/<?= (int) $__a['party_id'] ?>/agreement">Signed copy</a><?php endif; ?>
  <a class="btn-sm btn-secondary" href="/associates/<?= (int) $__a['party_id'] ?>">Managed on the associate page</a>
<?php endif; ?>
</div>
