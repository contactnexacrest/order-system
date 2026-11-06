<?php
/**
 * D-261 (owner approval 6 Nov 2026, mockups 1 and 2): the associate's Portal access level
 * and Track record, on the staff associate page. Only the MD changes the level.
 * Expects $a, $portalAccess. Node twin: _portal_access.njk.
 */
use App\Helpers\Csrf;
use App\Services\AssociateAccess;
$__pa = $portalAccess;
$__tr = $__pa['track'];
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
$__old = \App\Helpers\Flash::pullOld();
?>
<div class="section" id="portal-access">
  <h2>Portal access <small class="muted">— now: <?= htmlspecialchars(AssociateAccess::describe($a)) ?></small></h2>
  <?php if ($__pa['suggest']): ?>
    <div class="alert alert-info" data-upgrade-suggestion><b>Ready for the Quoting level?</b> <?= htmlspecialchars((string) $a['name']) ?> has <?= (int) $__tr['completed_paid'] ?> completed, fully paid orders. Nothing upgrades by itself.
      <?php if ($__pa['isMd']): ?><form method="post" action="/associates/<?= (int) $a['id'] ?>/access/not-yet" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Not yet</button></form><?php endif; ?></div>
  <?php endif; ?>
  <?php if (!$__pa['isMd']): ?><p class="muted small">Only the MD can change this.</p><?php endif; ?>
  <form method="post" action="/associates/<?= (int) $a['id'] ?>/access" data-portal-access>
    <?= Csrf::field() ?>
    <fieldset<?= $__pa['isMd'] ? '' : ' disabled' ?>>
      <legend>Level *</legend>
      <?php foreach (AssociateAccess::ALL_LEVELS as $__l => $__label): ?>
        <label class="checkbox-label"><input type="radio" name="access_level" value="<?= $__l ?>" required<?= AssociateAccess::level($a) === $__l ? ' checked' : '' ?>> <?= htmlspecialchars($__label) ?></label>
      <?php endforeach; ?>
      <p class="small muted">Starter: clients, orders and stage, commission, registration links — no prices and no quoting. Quoting adds the catalogue and Get price. Full is the whole portal. What each level shows is set in <a href="/associates/access-levels">Level settings ›</a></p>
      <details<?= AssociateAccess::level($a) === 'custom' ? ' open' : '' ?>><summary>Custom — tick features one by one (used only when Custom is chosen)</summary>
        <?php $__f = AssociateAccess::features($a); foreach (AssociateAccess::FEATURES as $__k => [$__label, $__help]): if ($__k === 'profile') { continue; } ?>
          <label class="checkbox-label"><input type="checkbox" name="feature[]" value="<?= $__k ?>"<?= !empty($__f[$__k]) ? ' checked' : '' ?>> <?= htmlspecialchars($__label) ?> <small class="muted"><?= htmlspecialchars($__help) ?></small></label>
        <?php endforeach; ?>
      </details>
      <label class="full">Reason for the change *
        <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. 3 orders completed and paid — may now quote" value="<?= htmlspecialchars((string) ($__old['reason'] ?? '')) ?>"></label>
      <button type="submit" class="btn-sm">Save portal access</button>
    </fieldset>
  </form>
  <?php if ($__pa['history'] !== []): ?>
    <table class="list"><tr><th>When</th><th>By</th><th>From</th><th>To</th><th>Reason</th></tr>
      <?php foreach ($__pa['history'] as $__h): ?><tr><td><?= $__d($__h['created_at']) ?></td><td><?= htmlspecialchars((string) ($__h['changed_by_name'] ?? '—')) ?></td><td><?= htmlspecialchars((string) $__h['old_value']) ?></td><td><?= htmlspecialchars((string) $__h['new_value']) ?></td><td><?= htmlspecialchars((string) $__h['reason']) ?></td></tr><?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="section" id="track-record">
  <h2>Track record</h2>
  <div class="stat-row">
    <div class="stat"><div class="stat-label">Clients brought in</div><div class="stat-value"><?= (int) $__tr['clients'] ?></div></div>
    <div class="stat"><div class="stat-label">Orders</div><div class="stat-value"><?= (int) $__tr['orders'] ?></div></div>
    <div class="stat"><div class="stat-label">Order value</div><div class="stat-value"><?= $__tr['value'] === [] ? '—' : htmlspecialchars(implode(' · ', array_map(static fn (string $c, float $v): string => $c . ' ' . number_format($v, 2), array_keys($__tr['value']), $__tr['value']))) ?></div></div>
    <div class="stat"><div class="stat-label">Completed &amp; fully paid</div><div class="stat-value"><?= (int) $__tr['completed_paid'] ?></div></div>
  </div>
  <p class="small muted">The MD is asked about the Quoting level after <?= (int) $__pa['upgradeAfter'] ?> completed, fully paid orders.</p>
</div>
