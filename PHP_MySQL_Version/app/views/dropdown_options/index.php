<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Dropdown Option Lists</h1>
  <p class="muted">Admin-editable option lists used across the app — e.g. Container Type on the Quotation form, Certificate of Origin Type. An option is never deleted outright; deactivate one you no longer want instead, since an older order or submission may still carry its exact text.</p>

  <?php foreach ($grouped as $listKey => $options): ?>
    <div class="section">
      <h2><?= htmlspecialchars($listKey) ?></h2>
      <form method="post" action="/admin/dropdown-options/<?= urlencode($listKey) ?>/update">
        <?= Csrf::field() ?>
        <table class="list">
          <tr><th>Value</th><th>Sort Order</th><th>Default</th><th>Active</th></tr>
          <?php foreach ($options as $o): ?>
            <tr>
              <td><input type="text" name="option_value[<?= (int) $o['id'] ?>]" value="<?= htmlspecialchars($o['option_value']) ?>" placeholder="e.g., 20ft Standard"></td>
              <td><input type="text" name="sort_order[<?= (int) $o['id'] ?>]" value="<?= (int) $o['sort_order'] ?>" placeholder="e.g., 1" style="width:4rem"></td>
              <td><input type="radio" name="default_id" value="<?= (int) $o['id'] ?>" <?= $o['is_default'] ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="is_active[<?= (int) $o['id'] ?>]" value="1" <?= $o['is_active'] ? 'checked' : '' ?>></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <button type="submit" class="btn-sm">Save <?= htmlspecialchars($listKey) ?> Changes</button>
      </form>

      <form method="post" action="/admin/dropdown-options/<?= urlencode($listKey) ?>/create" class="btn-row">
        <?= Csrf::field() ?>
        <input type="text" name="option_value" placeholder="Add a new option to this list">
        <button type="submit" class="btn-sm btn-secondary">Add Option</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
