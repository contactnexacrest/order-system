<?php
/**
 * D-261 (owner approval 6 Nov 2026, mockup 6): Admin › Associates › Access levels — what
 * Starter, Quoting and Full show in the Associate Portal. Only the MD changes it; a reason
 * is needed and every change is logged. Node twin: access_levels.njk.
 */
use App\Helpers\Csrf;
use App\Services\AssociateAccess;
$__old = \App\Helpers\Flash::pullOld();
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <p class="muted small"><a href="/associates">&larr; Associates</a></p>
  <h1>Associate access levels</h1>
  <p class="muted">Tick what each level shows in the Associate Portal. <b>Changes apply to every associate on that level at once. An associate on Custom keeps their own ticks.</b>
    Hidden features are refused by the server too, not only hidden from the menu. Cost, margin, supplier prices and CAFIN are never shown at any level.</p>
  <?php if (!$isMd): ?><p class="alert alert-info">Only the MD can change the level settings.</p><?php endif; ?>
  <form method="post" action="/associates/access-levels" data-access-levels>
    <?= Csrf::field() ?>
    <table class="list">
      <tr><th>Feature</th><?php foreach (AssociateAccess::LEVELS as $__l => $__label): ?><th class="c"><?= htmlspecialchars($__label) ?></th><?php endforeach; ?></tr>
      <?php foreach (AssociateAccess::FEATURES as $__f => [$__label, $__help]): ?>
        <tr<?= $__f === 'fob_value' ? ' class="row-highlight"' : '' ?>><td><b><?= htmlspecialchars($__label) ?></b><br><small class="muted"><?= htmlspecialchars($__help) ?></small></td>
          <?php foreach (array_keys(AssociateAccess::LEVELS) as $__l): ?>
            <td class="c"><?php if ($__f === 'profile'): ?><input type="checkbox" checked disabled aria-label="<?= htmlspecialchars($__label) ?> — always on"> <small class="muted">locked</small>
            <?php else: ?><input type="checkbox" name="tick[<?= $__l ?>][]" value="<?= $__f ?>" aria-label="<?= htmlspecialchars(AssociateAccess::LEVELS[$__l] . ': ' . $__label) ?>"<?= !empty($matrix[$__l][$__f]) ? ' checked' : '' ?><?= $isMd ? '' : ' disabled' ?>><?php endif; ?></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($isMd): ?>
    <label class="full">Reason for the change *
      <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Starter associates may now see the FOB value" value="<?= htmlspecialchars((string) ($__old['reason'] ?? '')) ?>"></label>
    <div class="btn-row">
      <button type="submit" name="action" value="save" class="btn-sm">Save level settings</button>
      <button type="submit" name="action" value="restore" class="btn-sm btn-secondary" data-confirm="Put the recommended defaults back for all three levels?">Restore recommended defaults</button>
    </div>
    <p class="small muted">Every change is logged: who, when, which ticks changed, and the reason.</p>
    <?php endif; ?>
  </form>

  <h2>Associates per level</h2>
  <table class="list"><tr><th>Level</th><th class="r">Associates</th></tr>
    <?php foreach (AssociateAccess::ALL_LEVELS as $__l => $__label): ?><tr><td><?= htmlspecialchars($__label) ?></td><td class="r"><?= (int) ($counts[$__l] ?? 0) ?></td></tr><?php endforeach; ?>
  </table>

  <h2>History</h2>
  <table class="list"><tr><th>When</th><th>By</th><th>Change</th><th>Reason</th></tr>
    <?php foreach ($history as $__h): ?><tr><td><?= $__d($__h['created_at']) ?></td><td><?= htmlspecialchars((string) ($__h['changed_by_name'] ?? '—')) ?></td><td><?= htmlspecialchars((string) $__h['new_value']) ?></td><td><?= htmlspecialchars((string) $__h['reason']) ?></td></tr><?php endforeach; ?>
    <?php if ($history === []): ?><tr><td colspan="4" class="muted">No changes yet — the recommended defaults are in use.</td></tr><?php endif; ?>
  </table>
</div>
