<?php
use App\Helpers\Csrf;
use App\Helpers\MoneyFormat;
use App\Helpers\View;
use App\Services\AssociateBrands;
use App\Services\AssociateRules;
use App\Services\PricingService;

/**
 * D-431 (owner 25–26 Nov 2026): one brand — Details, Rules (empty = the company setting; "Copy all company settings" /
 * "Clear all (follow company)"), and its Ladder (empty = the company ladder). Expects $brand, $rules, $company, $ladder,
 * $companyLadder, $levels, $canEdit, $canRules.
 */
$b = $brand;
$__show = static function (string $key, string $value): string {
    return match ($key) {
        'min_order_inr' => '₹' . MoneyFormat::indian((float) $value, 0),
        'group_days' => $value . ' days',
        'price_rule' => AssociateRules::PRICE_RULES[$value] ?? $value,
        'price_fixed_pct' => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' %',
        'price_requests_per_day' => $value . ' a day',
        'price_valid_days' => $value . ' days',
        default => $value,
    };
};
$__ladderText = static fn (array $rows): string => $rows === [] ? '—' : implode(' · ', array_map(
    static fn (array $s): string => 'Orders ' . $s['from_order'] . ($s['to_order'] === null ? '+' : '–' . $s['to_order']) . ': ' . PricingService::levelLabel($s), $rows));
$__rows = $ladder;
for ($i = 0; $i < 2; $i++) {
    $__rows[] = ['from_order' => '', 'to_order' => '', 'level_id' => ''];
}
?>
<div class="card page-wide">
  <p><a href="/admin/brands">&larr; Brands</a></p>
  <h1><?= View::e((string) $b['name']) ?> <span class="badge <?= $b['status'] === 'active' ? 'badge-success' : 'badge-inactive' ?>"><?= View::e(AssociateBrands::STATUSES[$b['status']] ?? (string) $b['status']) ?></span></h1>
  <p class="muted" data-brand-tagline><?= View::e((string) $b['tagline']) ?></p>

  <div class="section" id="details">
    <h2>Details</h2>
    <p class="muted small">Associates see the name and the line under it on their login page (<code>/associate/login/<?= View::e((string) $b['code']) ?></code>), portal and e-mails. Buyers never see it.</p>
    <?php if ($canEdit): ?>
    <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>" data-brand-form>
      <?= Csrf::field() ?>
      <?php require __DIR__ . '/_details_fields.php'; ?>
      <button type="submit">Save details</button>
    </form>
    <?php else: ?>
    <table class="list"><tr><th>Products it covers</th><td><?= View::e((string) ($b['products'] ?? '—')) ?></td></tr><tr><th>Website</th><td><?= View::e((string) ($b['website'] ?? '—')) ?></td></tr><tr><th>E-mail shown to associates</th><td><?= View::e((string) ($b['email'] ?? '—')) ?></td></tr></table>
    <?php endif; ?>
  </div>

  <div class="section" id="rules">
    <h2>Rules</h2>
    <p class="muted small">Each rule: empty = follows the company setting (Associates › Settings and Admin › Pricing — also when that changes later), or this brand's own value. A changed rule applies to new orders; an order keeps the rules it had at its Proforma Invoice. <?= View::e(AssociateRules::WHO_CHANGES) ?></p>
    <?php if ($canRules): ?>
    <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/rules" data-brand-rules>
      <?= Csrf::field() ?>
      <p class="actions">
        <button type="submit" name="action" value="copy_all" class="btn-secondary" data-copy-all>Copy all company settings</button>
        <span class="muted small">fills every rule with today's company value — then change only what this brand needs (a copied value no longer follows later company changes)</span>
        <button type="submit" name="action" value="clear_all" class="btn-secondary" data-clear-all>Clear all (follow company)</button>
      </p>
    <?php endif; ?>
      <table class="list" data-brand-rules-table>
        <tr><th>Rule</th><th>This brand</th><th>Source</th><th>Company setting today</th></tr>
        <?php foreach (AssociateBrands::RULES as $key => [$label, $min, $max, $kind]):
            $own = $rules[$key] ?? null; $own = $own !== null && $own !== '' ? $own : null; ?>
        <tr data-rule="<?= $key ?>">
          <td><?= View::e($label) ?></td>
          <td>
            <?php if (!$canRules): ?>
              <?= $own !== null ? View::e($__show($key, $own)) : '<span class="muted">empty</span>' ?>
            <?php elseif ($kind === 'choice'): ?>
              <select name="<?= $key ?>" aria-label="<?= View::e($label) ?>">
                <option value="">— follow company —</option>
                <?php foreach (AssociateRules::PRICE_RULES as $k => $l): ?><option value="<?= $k ?>"<?= $own === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="number" name="<?= $key ?>" min="<?= $min ?>" max="<?= $max ?>" step="<?= $kind === 'int' ? '1' : '0.01' ?>" value="<?= View::e((string) ($own ?? '')) ?>" placeholder="empty = company" aria-label="<?= View::e($label) ?>">
            <?php endif; ?>
          </td>
          <td><?= $own !== null ? '<span class="badge badge-notyet">Own value</span>' : '<span class="badge badge-active">Company</span>' ?></td>
          <td class="muted"><?= View::e($__show($key, (string) $company[$key])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php if ($canRules): ?>
      <label>Reason for the change * <small class="muted">(kept in the Audit Log)</small><textarea name="reason" required minlength="10" maxlength="300" rows="2" placeholder="e.g. larger orders in this division — minimum raised"></textarea></label>
      <button type="submit" name="action" value="save">Save rules</button>
    </form>
    <?php endif; ?>
    <p class="muted small" data-company-wide><strong>The same for every brand</strong> (set once in Associates › Settings, not here): payout run and day, cut-off day, waiting days, Sunday/holiday rule, TDS, KYC and video KYC, bank checks, payout abroad.</p>
  </div>

  <div class="section" id="ladder">
    <h2>Ladder</h2>
    <p class="muted small">Empty = the company ladder (<?= View::e($__ladderText($companyLadder)) ?>). An associate with their own ladder keeps it. This brand today: <strong data-brand-ladder><?= $ladder === [] ? 'the company ladder' : View::e($__ladderText($ladder)) ?></strong>.</p>
    <?php if ($canRules): ?>
    <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/ladder">
      <?= Csrf::field() ?>
      <table class="list">
        <tr><th>From order no.</th><th>To order no.</th><th>Level</th><th>Remove</th></tr>
        <?php foreach ($__rows as $i => $s): ?>
        <tr>
          <td><input type="number" name="from[<?= $i ?>]" min="1" step="1" value="<?= View::e((string) $s['from_order']) ?>" aria-label="From order number, step <?= $i + 1 ?>" placeholder="e.g. 1"></td>
          <td><input type="number" name="to[<?= $i ?>]" min="1" step="1" value="<?= View::e((string) ($s['to_order'] ?? '')) ?>" aria-label="To order number, step <?= $i + 1 ?> (empty = onward)" placeholder="empty = onward"></td>
          <td><select name="level_id[<?= $i ?>]" aria-label="Level, step <?= $i + 1 ?>">
              <option value="">—</option>
              <?php foreach ($levels as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (string) $s['level_id'] === (string) $l['id'] ? ' selected' : '' ?>><?= View::e($l['name'] . ' — ' . PricingService::levelLabel($l)) ?></option><?php endforeach; ?>
            </select></td>
          <td><?php if ($s['from_order'] !== ''): ?><label class="inline"><input type="checkbox" name="remove[<?= $i ?>]" value="1"> Remove</label><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <label>Reason for the change * <small class="muted">(kept in the Audit Log)</small><textarea name="reason" required minlength="10" maxlength="300" rows="2" placeholder="e.g. this division's margins allow a different ladder"></textarea></label>
      <button type="submit" name="action" value="save">Save brand ladder</button>
      <?php if ($ladder !== []): ?><button type="submit" name="action" value="clear" class="btn-secondary" data-ladder-clear>Use the company ladder</button><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>

  <?php // D-435 (owner 26 Nov 2026): the brand's price settings — between its categories and the company settings. ?>
  <div class="section" id="pricing">
    <h2>Pricing</h2>
    <p class="muted small">Margin, other costs and buffer for this brand's products. A product's own value comes first, then its sub-category, its main category, then this brand; empty here = the company setting (Admin › Pricing). Changes apply to prices worked out from now on; quotes already sent keep their prices.</p>
    <?php if ($canPricing): ?>
    <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/pricing" data-brand-pricing>
      <?= Csrf::field() ?>
      <p class="actions">
        <button type="submit" name="action" value="copy_all" class="btn-secondary">Copy all company settings</button>
        <button type="submit" name="action" value="clear_all" class="btn-secondary">Clear all (follow company)</button>
      </p>
    <?php endif; ?>
      <table class="list" data-brand-pricing-table>
        <tr><th>Setting</th><th>This brand</th><th>Source</th><th>Company setting today</th></tr>
        <?php foreach (\App\Repositories\AssociateBrandRepository::PRICING as $key => [$label, $max]):
            $own = $rules['pricing_' . $key] ?? null; $own = $own !== null && $own !== '' ? $own : null; ?>
        <tr data-pricing="<?= $key ?>">
          <td><?= View::e($label) ?></td>
          <td><?php if ($canPricing): ?><input type="number" name="<?= $key ?>" min="0" max="<?= $max ?>" step="0.01" value="<?= View::e((string) ($own ?? '')) ?>" placeholder="empty = company" aria-label="<?= View::e($label) ?>"><?php else: ?><?= $own !== null ? View::e($own) . ' %' : '<span class="muted">empty</span>' ?><?php endif; ?></td>
          <td><?= $own !== null ? '<span class="badge badge-notyet">Own value</span>' : '<span class="badge badge-active">Company</span>' ?></td>
          <td class="muted"><?= View::e(number_format((float) ($companyPricing[$key] ?? 0), 2)) ?> %</td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php if ($canPricing): ?>
      <label>Reason for the change * <small class="muted">(kept in the Audit Log)</small><textarea name="reason" required minlength="10" maxlength="300" rows="2" placeholder="e.g. textile margins are lower than stone"></textarea></label>
      <button type="submit" name="action" value="save">Save pricing</button>
    </form>
    <?php endif; ?>
  </div>
</div>
