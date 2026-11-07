<?php
// Owner decision D-266: extra SOP chapters for one person (added to what their role and department roles show).
// Shown only to people who manage SOP access (manage_company_settings / Super Admin). Node twin: views/users/_sop_extras.njk.
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\SopAccessService;

$__me = AuthService::currentUser();
if ($__me !== null && SopAccessService::isPrivileged((int) $__me['id'], isset($__me['role_id']) && $__me['role_id'] !== null ? (int) $__me['role_id'] : null)):
    $__extras = SopAccessService::userExtras((int) $target['id']);
    $__titles = \App\Controllers\SopAccessController::titles();
?>
<div class="card" id="sop-extras" data-sop-extras>
  <h2>Extra SOP chapters for this person</h2>
  <p class="muted small">Added to what their role and departments already show. Useful when someone covers for a colleague.</p>
  <?php if ($__extras === []): ?><p class="muted">None.</p><?php endif; ?>
  <?php foreach ($__extras as $__slug): ?>
    <form method="post" action="/users/<?= (int) $target['id'] ?>/sop-chapters/remove" class="inline-form" style="margin:2px">
      <?= Csrf::field() ?>
      <input type="hidden" name="slug" value="<?= htmlspecialchars($__slug) ?>">
      <span class="badge badge-info"><?= htmlspecialchars($__titles[$__slug] ?? $__slug) ?></span>
      <button type="submit" class="btn-sm btn-secondary" aria-label="Remove <?= htmlspecialchars($__titles[$__slug] ?? $__slug) ?>">Remove</button>
    </form>
  <?php endforeach; ?>
  <form method="post" action="/users/<?= (int) $target['id'] ?>/sop-chapters" class="field-grid" style="margin-top:10px">
    <?= Csrf::field() ?>
    <label>Add a chapter *
      <select name="slug" required>
        <option value="">Choose…</option>
        <?php foreach ($__titles as $__slug => $__title): if (in_array($__slug, $__extras, true) || $__slug === SopAccessService::ALWAYS_VISIBLE) continue; ?>
          <option value="<?= htmlspecialchars($__slug) ?>"><?= htmlspecialchars($__title) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div><button type="submit" class="btn-sm">Add chapter</button></div>
  </form>
</div>
<?php endif; ?>
