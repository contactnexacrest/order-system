<?php
use App\Helpers\View;
use App\Services\AssociateBrands;

/** D-431: the brand's details (shared by "Add brand" and the brand page). Expects $b (array or null). */
$__b = $b ?? [];
?>
<div class="form-grid">
  <label>Brand name *<input type="text" name="name" required minlength="2" maxlength="80" value="<?= View::e((string) ($__b['name'] ?? '')) ?>" placeholder="e.g. Stone division name"></label>
  <label>Login address code * <small class="muted">(/associate/login/<em>code</em>)</small><input type="text" name="code" required minlength="2" maxlength="30" pattern="[a-z0-9](?:[a-z0-9\-]{0,28}[a-z0-9])?" value="<?= View::e((string) ($__b['code'] ?? '')) ?>" placeholder="e.g. stone"></label>
  <label>Line under the name *<input type="text" name="tagline" required maxlength="200" value="<?= View::e((string) ($__b['tagline'] ?? AssociateBrands::DEFAULT_TAGLINE)) ?>" placeholder="e.g. <?= View::e(AssociateBrands::DEFAULT_TAGLINE) ?>"></label>
  <label>Products it covers<input type="text" name="products" maxlength="200" value="<?= View::e((string) ($__b['products'] ?? '')) ?>" placeholder="e.g. Natural Stone"></label>
  <label>Website<input type="text" name="website" maxlength="190" value="<?= View::e((string) ($__b['website'] ?? '')) ?>" placeholder="e.g. www.example.com"></label>
  <label>E-mail shown to associates<input type="email" name="email" maxlength="190" value="<?= View::e((string) ($__b['email'] ?? '')) ?>" placeholder="e.g. info@email.com"></label>
  <label>Status *<select name="status" required>
    <option value="">Choose…</option>
    <?php foreach (AssociateBrands::STATUSES as $k => $label): ?><option value="<?= $k ?>"<?= ($__b['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?>
  </select></label>
</div>
