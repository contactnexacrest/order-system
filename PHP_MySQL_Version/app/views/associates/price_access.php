<?php
use App\Helpers\Csrf;

/* Owner decision D-255 (5 Nov 2026): what an associate may price (MD — manage_pricing). */
$id = (int) $associate['id'];
?>
<div class="card page-mid">
  <div class="page-header-row">
    <h1>Price access — <?= htmlspecialchars((string) $associate['name']) ?></h1>
    <div class="btn-row" style="margin-top:0"><a class="btn-sm btn-secondary" href="/associates/<?= $id ?>">Back to the associate</a></div>
  </div>
  <form method="post" action="/associates/<?= $id ?>/price-access" id="price-access-form">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Categories and forms open to them</legend>
      <p class="muted small">Tick a category to let them get prices in it. Tick forms to limit them to those forms; no form ticked means every form of the product.</p>
      <?php foreach ($categories as $c): $__cid = (int) $c['id']; $__on = array_key_exists($__cid, $access); ?>
        <div class="price-access-cat">
          <label class="checkbox"><input type="checkbox" name="category[<?= $__cid ?>]" value="1" <?= $__on ? 'checked' : '' ?>> <b><?= htmlspecialchars((string) $c['name']) ?></b></label>
          <span class="muted small">— forms:</span>
          <?php foreach ($forms as $__fk => $__fl): ?>
            <label class="checkbox"><input type="checkbox" name="forms[<?= $__cid ?>][]" value="<?= htmlspecialchars($__fk) ?>" <?= $__on && in_array($__fk, $access[$__cid], true) ? 'checked' : '' ?>> <?= htmlspecialchars($__fl) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </fieldset>
    <div class="form-row">
      <label>Price requests per day<input type="number" name="price_request_daily_limit" min="0" max="1000" step="1" value="<?= htmlspecialchars((string) ($associate['price_request_daily_limit'] ?? '')) ?>" placeholder="empty = global (<?= (int) $globalLimit ?>)"></label>
      <label>A price stays valid for (days)<input type="number" name="price_valid_days" min="1" max="365" step="1" value="<?= htmlspecialchars((string) ($associate['price_valid_days'] ?? '')) ?>" placeholder="empty = global (<?= (int) $globalDays ?>)"></label>
    </div>
    <p class="muted small">Global defaults: <?= (int) $globalLimit ?> a day, <?= (int) $globalDays ?> days (Pricing › Settings). Every request is logged; prices are shown on screen only (no download or export); supplier names and costs are never shown. Requests our team prices by hand do not count toward the daily limit.</p>
    <button type="submit">Save</button>
  </form>
</div>
