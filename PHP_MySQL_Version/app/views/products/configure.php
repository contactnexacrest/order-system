<?php
/**
 * Owner decision D-361 (13 Nov 2026; mockups p4 and configure_three_levels_mockup.png):
 * Products › Configure / Edit — the price part of one product. The base price (default = the
 * highest active supplier price; never changes by itself), the three levels (Global | Category |
 * This product | Comes from) with a reset per row and "Reset all to category / global values"
 * (each with a confirm box listing the changes), and the Active switch. Details, specifications,
 * thickness prices and images stay on the product's own pages.
 *
 * In: see ProductConfigureController::form(). Node twin: views/products/configure.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__id = (int) $product['id'];
$__cat = $category['name'] ?? null;
$__amt = static fn (float $v): string => '₹' . ThicknessPrices::amount($v);
$__old = $old['level'] ?? null;
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products?status=all">← Products</a> · <a href="/products/<?= $__id ?>">View</a> · <a href="/products/<?= $__id ?>/edit">Edit details, specifications and images</a></p>
  <h1><?= $configured ? 'Edit' : 'Configure' ?> — <?= View::e((string) $product['name']) ?> <small class="muted">(category: <?= View::e($__cat ?? '—') ?>)</small></h1>
  <p><span class="badge <?= (int) $product['is_active'] === 1 ? 'badge-active' : 'badge-inactive' ?>"><?= (int) $product['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
    <span class="badge <?= View::e($status['badge']) ?>" data-standing="<?= View::e($status['key']) ?>"><?= View::e($status['label']) ?></span>
    <span class="muted small"><?= (int) $status['usable'] ?> active supplier<?= (int) $status['usable'] === 1 ? '' : 's' ?><?= (int) $status['paused'] > 0 ? ', ' . (int) $status['paused'] . ' paused' : '' ?></span></p>

  <form method="post" action="/products/<?= $__id ?>/configure" id="configure-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="base_mm" value="<?= View::e($baseMm === null ? '' : (string) $baseMm) ?>">
    <input type="hidden" name="base_unit" value="<?= View::e($baseUnit) ?>">
    <div class="configure-grid">
      <div class="configure-box">
        <label>Base price (<?= View::e($baseLabel) ?>) *
          <input type="text" inputmode="decimal" name="base_price" class="base-price-input" required data-max-decimals="2" data-max-value="999999999999"
            value="<?= View::e((string) $basePrice) ?>" placeholder="e.g. 190000.00"
            <?php if ($lowest !== null): ?>data-warn-below="<?= View::e((string) $lowest['base']['inr']) ?>" data-warn-target="below-lowest"<?php endif; ?>></label>
        <p class="muted small">
          <?php if ($highest !== null): ?>Default = highest active supplier price (<?= View::e((string) $highest['supplier_name']) ?> <?= $__amt((float) $highest['base']['inr']) ?>).<?php if ($lowest !== null && $lowest['id'] !== $highest['id']): ?> Lowest: <?= View::e((string) $lowest['supplier_name']) ?> <?= $__amt((float) $lowest['base']['inr']) ?>.<?php endif; ?>
          <?php else: ?>No active supplier price yet — type the base price yourself.<?php endif; ?>
          The base price is fixed: it never changes by itself when a supplier's price changes.</p>
        <?php if ($lowest !== null): ?><div class="warn-box" id="below-lowest" hidden>Below the lowest supplier price (<?= $__amt((float) $lowest['base']['inr']) ?>) — no supplier sells at this price.</div><?php endif; ?>
        <?php if ($change !== null): ?>
          <div class="change-box" data-testid="price-change">Supplier prices changed: highest is now <b><?= $__amt((float) $change['inr']) ?></b> <?= View::e($change['label']) ?> (<?= View::e($change['supplier']) ?>).
            <button type="submit" form="use-price-form" class="btn-sm">Use <?= $__amt((float) $change['inr']) ?></button></div>
        <?php endif; ?>
        <details>
          <summary>See all supplier prices (<?= count($offers) ?>)</summary>
          <table class="list">
            <tr><th>Supplier</th><th>Base price as quoted</th><th>Quoted on</th><th>Valid until</th><th>Offer</th><th></th></tr>
            <?php foreach ($offers as $__o): ?>
              <tr><td><?= View::e($__o['supplier']) ?><?= $__o['linked'] ? '' : ' <small class="muted">(not linked to a supplier record)</small>' ?></td><td><?= View::e($__o['price']) ?></td><td><?= View::e($__o['quoted_on']) ?></td><td><?= View::e($__o['valid_until']) ?></td>
                <td><?= $__o['usable'] ? 'active' : '<span class="muted">paused</span>' ?></td>
                <td><?php if ($canEditPrice && $__o['editable']): ?><div class="row-actions"><a class="btn-sm btn-secondary" href="/products/suppliers/<?= (int) $__o['id'] ?>/edit-price?from=configure">Edit price</a></div><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if ($offers === []): ?><tr><td colspan="6" class="muted">No supplier prices yet — add them from Procurement › Suppliers › + Add product.</td></tr><?php endif; ?>
          </table>
        </details>
      </div>

      <div class="configure-box">
        <table class="list levels-table">
          <tr><th>Setting</th><th>Global</th><th>Category<?= $__cat !== null ? ': ' . View::e($__cat) : '' ?></th><th>This product</th><th>Comes from</th><th></th></tr>
          <?php foreach ($rows as $__r): $__v = $__old !== null && array_key_exists($__r['key'], $__old) ? (string) $__old[$__r['key']] : $__r['value']; ?>
            <tr data-level-row="<?= View::e($__r['key']) ?>">
              <td><?= View::e($__r['label']) ?></td>
              <td><?= View::e($__r['global']) ?></td>
              <td><?= $__r['category'] !== '' ? View::e($__r['category']) : '—' ?></td>
              <td><input type="text" inputmode="decimal" name="level[<?= View::e($__r['key']) ?>]" value="<?= View::e($__v) ?>" data-level-input data-inherit="<?= View::e($__r['inherit']) ?>" data-inherit-from="<?= View::e($__r['inherit_from']) ?>"
                  data-max-decimals="<?= (int) $__r['decimals'] ?>" placeholder="e.g. <?= View::e($__r['inherit'] !== '' ? $__r['inherit'] : '0') ?>" aria-label="<?= View::e($__r['label']) ?> — this product">
                <?php if ($__r['mode'] !== null): ?>
                  <select name="other_cost_mode" data-level-mode aria-label="Other costs — % or USD per unit">
                    <option value="pct"<?= $__r['mode'] === 'pct' ? ' selected' : '' ?>>%</option>
                    <option value="usd"<?= $__r['mode'] === 'usd' ? ' selected' : '' ?>>USD per unit</option>
                  </select>
                <?php endif; ?></td>
              <td><span class="badge level-from level-from-<?= View::e($__r['from']) ?>" data-level-from><?= $__r['from'] === 'product' ? 'this product' : View::e($__r['from']) ?></span></td>
              <td><a href="#" data-level-reset<?= $__r['own'] ? '' : ' hidden' ?>>reset</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="muted small">"reset" on one row puts back that one value (the category value if the category has one, else global) — then Save. Empty values in Global pricing settings and Category price rules are filled from the level above.</p>
        <?php if ($followGlobal): ?><p class="notice notice-info small">This product follows the <b>global</b> values and ignores its category's values ("Reset all to global values"). "Reset all to category values" makes it follow its category again.</p><?php endif; ?>
        <p>
          <button type="button" class="btn-sm btn-secondary" data-open-dialog="reset-category">Reset all to category values</button>
          <button type="button" class="btn-sm btn-secondary" data-open-dialog="reset-global">Reset all to global values</button>
        </p>
      </div>
    </div>

    <p><label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= (int) $product['is_active'] === 1 ? ' checked' : '' ?>> Active — offered in Pick from Products on new orders</label>
      <br><small class="muted">Only when the product is configured and has at least one active supplier offer<?= $status['no_supplier_yet'] ? ' (or, as now, it is a product from before suppliers were linked)' : '' ?>.<?= !$status['can_activate'] && $configured ? ' Now: ' . View::e((string) $status['reason']) : '' ?></small></p>
    <button type="submit">Save</button>
    <a class="btn-sm btn-secondary" href="/products?status=all">Cancel</a>
  </form>

  <?php if ($change !== null): ?>
    <form method="post" action="/products/<?= $__id ?>/configure/use-price" id="use-price-form"><?= Csrf::field() ?></form>
  <?php endif; ?>

  <?php foreach (['category' => $previewCategory, 'global' => $previewGlobal] as $__to => $__lines): ?>
    <dialog class="confirm-box" id="reset-<?= $__to ?>" data-testid="confirm-<?= $__to ?>">
      <h3>Reset all to <?= $__to ?> values?</h3>
      <?php if ($__to === 'category'): ?>
        <p>Every value changed for this product goes back to the <b><?= View::e($__cat ?? 'category') ?></b> category value (or the global value where the category has none):</p>
      <?php else: ?>
        <p>This product will use the <b>global</b> values and ignore the <?= View::e($__cat ?? 'category') ?> category values:</p>
      <?php endif; ?>
      <?php if ($__lines['changes'] === []): ?><p class="muted">No figure changes — the values are the same already.</p>
      <?php else: ?><ul><?php foreach ($__lines['changes'] as $__l): ?><li><?= View::e($__l['label']) ?> <?= View::e($__l['now']) ?> → <?= View::e($__l['after']) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($__lines['same'] !== []): ?><p class="muted small">Same figure, now from the <?= $__to ?> level: <?= View::e(implode(', ', $__lines['same'])) ?>.</p><?php endif; ?>
      <p><?= $__to === 'category' ? 'Later changes to the category or global settings will then apply to this product automatically.' : 'Later changes to the global settings will then apply to this product automatically.' ?></p>
      <form method="post" action="/products/<?= $__id ?>/configure/reset">
        <?= Csrf::field() ?>
        <input type="hidden" name="to" value="<?= $__to ?>">
        <button type="button" class="btn-sm btn-secondary" data-close-dialog>Cancel</button>
        <button type="submit" class="btn-sm btn-primary">Reset</button>
      </form>
    </dialog>
  <?php endforeach; ?>
</div>
