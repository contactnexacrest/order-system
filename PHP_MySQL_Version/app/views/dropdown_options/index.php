<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Dropdown Option Lists</h1>
  <p class="muted">Admin-editable option lists used across the app — e.g. Container Type on the Quotation form, Certificate of Origin Type. An option is never deleted outright; deactivate one you no longer want instead, since an older order or submission may still carry its exact text.</p>

  <?php foreach ($grouped as $listKey => $options): ?>
    <?php $__finish = $listKey === \App\Services\SurfaceFinishList::LIST_KEY; // D-357: the one list of surface-finish names ?>
    <div class="section" id="list-<?= htmlspecialchars($listKey) ?>">
      <h2><?= htmlspecialchars($listKey) ?></h2>
      <?php if ($__finish): ?>
        <p class="notice notice-info small" data-testid="finish-used-in">Used in: <b>Pick from Products</b> (price per finish in Pricing › Specifications), <b>order lines</b>, the <b>Supplier PO</b>, the <b>buyer's quotation form</b> and every <b>document</b>. This is the one place to name a finish — a rename here reaches Pick from Products, Pricing › Specifications and the lines of orders whose quotation is not approved yet.</p>
      <?php endif; ?>
      <form method="post" action="/admin/dropdown-options/<?= urlencode($listKey) ?>/update">
        <?= Csrf::field() ?>
        <table class="list">
          <tr><th>Value</th><th>Sort Order</th><th>Default</th><th>Active</th><?php if ($__finish): ?><th>Price change (from Pricing › Specifications)</th><?php endif; ?></tr>
          <?php foreach ($options as $o): ?>
            <tr>
              <td><input type="text" name="option_value[<?= (int) $o['id'] ?>]" required<?= $__finish ? ' maxlength="' . \App\Services\SurfaceFinishList::MAX_NAME . '"' : '' ?> value="<?= htmlspecialchars($o['option_value']) ?>" placeholder="<?= $__finish ? 'e.g., Natural Polish' : 'e.g., 20ft Standard' ?>" aria-label="Option value"><?php if ($__finish && isset($finishSaved['renamed'][(int) $o['id']])): ?> <small class="muted">was "<?= htmlspecialchars((string) $finishSaved['renamed'][(int) $o['id']]) ?>"</small><?php endif; ?></td>
              <td><input type="number" min="0" step="1" required name="sort_order[<?= (int) $o['id'] ?>]" value="<?= (int) $o['sort_order'] ?>" placeholder="e.g., 1" style="width:4rem" aria-label="Sort order"></td>
              <td><input type="radio" name="default_id" value="<?= (int) $o['id'] ?>" <?= $o['is_default'] ? 'checked' : '' ?> aria-label="Default option"></td>
              <td><input type="checkbox" name="is_active[<?= (int) $o['id'] ?>]" value="1" <?= $o['is_active'] ? 'checked' : '' ?> aria-label="Is active"></td>
              <?php if ($__finish): ?>
                <td class="finish-price"><?php foreach ($finishPrices[(int) $o['id']] ?? [] as $__fp): ?><div><?= $__fp['category'] !== '' ? htmlspecialchars($__fp['category']) . ': ' : '' ?><?= htmlspecialchars($__fp['text']) ?><?php if ($__fp['unset']): ?> <a class="tag tag-gold" href="<?= htmlspecialchars($__fp['link']) ?>">set it in Pricing › Specifications</a><?php endif; ?></div><?php endforeach; ?><?php if (($finishPrices[(int) $o['id']] ?? []) === []): ?><span class="muted">—</span><?php endif; ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </table>
        <button type="submit" class="btn-sm">Save <?= htmlspecialchars($listKey) ?> Changes</button>
      </form>
      <?php if ($__finish && !empty($finishSaved['messages'])): ?>
        <div class="alert alert-success finish-saved" data-testid="finish-saved">
          <?php foreach ($finishSaved['messages'] as $__m): ?>
            <div><b>Saved.</b> <?= htmlspecialchars((string) $__m['saved']) ?></div>
            <div class="small muted"><?= htmlspecialchars((string) $__m['kept']) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/dropdown-options/<?= urlencode($listKey) ?>/create" class="btn-row">
        <?= Csrf::field() ?>
        <input type="text" name="option_value" required<?= $__finish ? ' maxlength="' . \App\Services\SurfaceFinishList::MAX_NAME . '"' : '' ?> placeholder="<?= $__finish ? 'e.g., Antique — added at no price change' : 'Add a new option to this list' ?>" aria-label="Add a new option to this list">
        <button type="submit" class="btn-sm btn-secondary">Add Option</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
