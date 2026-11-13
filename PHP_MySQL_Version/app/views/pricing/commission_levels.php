<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\PricingService;

/** Owner decision D-245 (4 Nov 2026), P1: associate commission levels (CRUD) and the ladder. */
$__rows = $ladder;
for ($i = 0; $i < 2; $i++) {
    $__rows[] = ['from_order' => '', 'to_order' => '', 'level_id' => ''];
}
?>
<div class="card page-wide">
  <h1>Associate Commission Levels</h1>
  <p class="muted">Commission is built into the associate's price: a higher level raises the buyer's price, never lowers NexaCrest's margin. A level used by an associate or the ladder can only be deactivated, not deleted. A change applies to new quotes only — orders with an issued Proforma Invoice keep their commission. Every change is in the audit log. (Commission is paid on these levels from phase P3.)</p>
  <?php if ($sample !== null): ?>
    <p class="muted small">Floors shown for <strong><?= View::e($sample['product']['name']) ?></strong> (staff floor USD <?= number_format($sample['pricing']['build']['staff_floor'], 2) ?>).</p>
  <?php endif; ?>

  <table class="list">
    <tr><th>Level</th><th>Commission</th><?php if ($sample !== null): ?><th class="num">Floor (USD)</th><th class="num">Quote price (USD)</th><?php endif; ?><th>Associates on it</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr>
    <?php if ($levels === []): ?><tr><td colspan="7" class="muted">No levels yet.</td></tr><?php endif; ?>
    <?php foreach ($levels as $l): $__id = (int) $l['id']; $__used = ($use[$__id] ?? 0) > 0 || ($stepsPerLevel[$__id] ?? 0) > 0; ?>
      <tr>
        <td><?= View::e($l['name']) ?></td>
        <td><?= View::e(PricingService::levelLabel($l)) ?></td>
        <?php if ($sample !== null): ?>
          <td class="num"><?= number_format($floors[$__id]['floor'], 2) ?></td>
          <td class="num"><?= number_format($floors[$__id]['quote'], 2) ?></td>
        <?php endif; ?>
        <td><?= (int) ($use[$__id] ?? 0) ?><?= ($stepsPerLevel[$__id] ?? 0) > 0 ? ' <span class="muted small">· in the ladder</span>' : '' ?></td>
        <td><span class="badge <?= (int) $l['is_active'] === 1 ? 'badge-active' : 'badge-inactive' ?>"><?= (int) $l['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
        <?php if ($canManage): ?>
          <td>
            <details style="display:inline-block">
              <summary class="btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
              <div class="card-nested">
                <form method="post" action="/pricing/commission-levels/<?= $__id ?>">
                  <?= Csrf::field() ?>
                  <label>Name *<input type="text" name="name" maxlength="60" required value="<?= View::e($l['name']) ?>" placeholder="e.g. Level 1"></label>
                  <label>Type *
                    <select name="kind" required>
                      <option value="pct"<?= $l['kind'] === 'pct' ? ' selected' : '' ?>>% of FOB</option>
                      <option value="fixed_usd"<?= $l['kind'] === 'fixed_usd' ? ' selected' : '' ?>>Fixed USD per unit</option>
                    </select></label>
                  <label>Value *<input type="number" name="value" min="0.0001" step="0.0001" required value="<?= View::e(rtrim(rtrim((string) $l['value'], '0'), '.')) ?>" placeholder="e.g. 2.00"></label>
                  <button type="submit" class="btn-sm">Save</button>
                </form>
              </div>
            </details>
            <form method="post" action="/pricing/commission-levels/<?= $__id ?>/active" style="display:inline">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm"><?= (int) $l['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <?php if (!$__used): ?>
              <form method="post" action="/pricing/commission-levels/<?= $__id ?>/delete" style="display:inline" onsubmit="return confirm('Delete this commission level?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm btn-danger">Delete</button>
              </form>
            <?php else: ?><span class="muted small">Delete (in use)</span><?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($canManage): ?>
    <h2>Add a level</h2>
    <form method="post" action="/pricing/commission-levels" class="field-grid">
      <?= Csrf::field() ?>
      <label>Name *<input type="text" name="name" maxlength="60" required placeholder="e.g. Level 5"></label>
      <label>Type *
        <select name="kind" required>
          <option value="">Choose…</option>
          <option value="pct">% of FOB</option>
          <option value="fixed_usd">Fixed USD per unit</option>
        </select></label>
      <label>Value *<input type="number" name="value" min="0.0001" step="0.0001" required placeholder="e.g. 12.00"></label>
      <div><button type="submit" class="btn-sm btn-accent">Add level</button></div>
    </form>
  <?php endif; ?>

  <h2 id="ladder">Ladder</h2>
  <p class="muted">Used by associates set to "Ladder": the level for each counting order (invoiced FOB of ₹5 lakh or more on in-range lines). Steps start at order 1 and run on without gaps; leave the last step's "to" empty so it runs onward. Leave a blank row empty, or tick "Remove" to drop a step.</p>
  <form method="post" action="/pricing/commission-ladder">
    <?= Csrf::field() ?>
    <table class="list">
      <tr><th>From order no.</th><th>To order no.</th><th>Level</th><?php if ($canManage): ?><th>Remove</th><?php endif; ?></tr>
      <?php foreach ($__rows as $i => $s): ?>
        <tr>
          <td><input type="number" name="from[<?= $i ?>]" min="1" step="1" value="<?= View::e((string) $s['from_order']) ?>" aria-label="From order number, step <?= $i + 1 ?>" placeholder="e.g. 1"<?= $canManage ? '' : ' disabled' ?>></td>
          <td><input type="number" name="to[<?= $i ?>]" min="1" step="1" value="<?= View::e((string) ($s['to_order'] ?? '')) ?>" aria-label="To order number, step <?= $i + 1 ?> (empty = onward)" placeholder="empty = onward"<?= $canManage ? '' : ' disabled' ?>></td>
          <td><select name="level_id[<?= $i ?>]" aria-label="Level, step <?= $i + 1 ?>"<?= $canManage ? '' : ' disabled' ?>>
              <option value="">—</option>
              <?php foreach ($levels as $l): ?>
                <option value="<?= (int) $l['id'] ?>"<?= (string) $s['level_id'] === (string) $l['id'] ? ' selected' : '' ?>><?= View::e($l['name'] . ' — ' . PricingService::levelLabel($l)) ?><?= (int) $l['is_active'] === 1 ? '' : ' (inactive)' ?></option>
              <?php endforeach; ?>
            </select></td>
          <?php if ($canManage): ?><td><?php if ($s['from_order'] !== ''): ?><label class="inline"><input type="checkbox" name="remove[<?= $i ?>]" value="1"> Remove</label><?php endif; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($canManage): ?><button type="submit" class="btn-sm">Save ladder</button><?php endif; ?>
  </form>
  <p><a class="btn-sm" href="/pricing/settings">Global pricing settings</a></p>
</div>
