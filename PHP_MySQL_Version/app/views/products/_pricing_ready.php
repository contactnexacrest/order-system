<?php
use App\Helpers\View;
use App\Services\PricingService;

/**
 * Owner decision D-256 (5 Nov 2026): "Pricing ready?" — what this product still needs before
 * it can be priced, each with a link to fix it — and "Try a price", a calculator for any
 * combination of this product's options (nothing is saved). Only for people who may see the
 * price build-up; Procurement sees the supplier-price lines of the checklist only.
 * Node twin: views/products/_pricing_ready.njk.
 */
$__usd = static fn (float $v): string => 'USD ' . number_format($v, 2);
if ($readiness === null && $tryPrice === null) {
    return;
}
?>
<div class="pricing-two">
<?php if ($readiness !== null): ?>
<div class="section" id="pricing-ready">
  <h2>Pricing ready? <?php if ($readiness['to_fix'] > 0): ?><span class="badge badge-warning"><?= (int) $readiness['to_fix'] ?> to fix</span><?php else: ?><span class="badge badge-success">ready</span><?php endif; ?></h2>
  <table class="list ready-list">
    <?php foreach ($readiness['items'] as $__i): ?>
      <tr>
        <td class="<?= $__i['ok'] ? 'ok' : 'no' ?>" aria-label="<?= $__i['ok'] ? 'done' : 'to fix' ?>"><?= $__i['ok'] ? '✓' : '✗' ?></td>
        <td><?= View::e($__i['label']) ?><?php if ($__i['detail'] !== ''): ?><br><small class="muted"><?= View::e($__i['detail']) ?></small><?php endif; ?></td>
        <td><a href="<?= View::e($__i['link']) ?>"><?= View::e($__i['link_text']) ?></a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="small muted">Until every line is ✓, staff can still quote, but an associate's "Get price" for the missing part goes to staff for a manual price.</p>
</div>
<?php endif; ?>
<?php if ($tryPrice !== null && $tryPrice['p'] !== null): $__p = $tryPrice['p']; $__b = $__p['build']; ?>
<div class="section" id="try-price">
  <h2>Try a price <small class="muted">(nothing is saved)</small></h2>
  <form method="get" action="/products/<?= (int) $product['id'] ?>#try-price" class="field-grid">
    <?php foreach ($tryPrice['offered'] as $__sp): ?>
      <label><?= View::e($__sp['name']) ?>
        <select name="try[<?= (int) $__sp['id'] ?>]">
          <?php $__chosen = isset($__p['spec']['map'][$__sp['id']]) ? (int) $__p['spec']['map'][$__sp['id']] : null; ?>
          <?php if ((int) $__sp['id'] === $tryPrice['thickSpec']): ?>
            <option value=""<?= $__chosen === null ? ' selected' : '' ?>><?= View::e($tryPrice['baseOption']) ?></option>
          <?php endif; ?>
          <?php foreach ($__sp['options'] as $__o): ?>
            <option value="<?= (int) $__o['id'] ?>"<?= $__chosen === (int) $__o['id'] ? ' selected' : '' ?>><?= View::e($__o['name']) ?> — <?= View::e($__o['change_type'] === 'none' ? 'base' : \App\Services\ProductSpecs::changeLabel((string) $__o['change_type'], $__o['value'] !== null ? (float) $__o['value'] : null)) ?></option>
          <?php endforeach; ?>
        </select></label>
    <?php endforeach; ?>
    <div><button type="submit" class="btn-sm">Work it out</button></div>
  </form>
  <?php if ($__b === null): ?>
    <p class="muted"><?= View::e((string) $__p['missing']) ?></p>
  <?php else: $__adj = $__p['spec']['adj']; $__e = $__p['effective']; ?>
    <table class="list">
      <tr><td><?= !empty($__p['exact']) ? 'Exact supplier price "' . View::e($__p['exact']) . '"' : 'Supplier price' ?> &#8377;<?= number_format((float) $__p['supplier_inr'], 2) ?> ÷ <?= number_format($__p['settings']['pricing_fx_rate'], 2) ?> + other costs <?= View::e(PricingService::modeLabel($__e['other_mode'], $__e['other_value'])) ?></td><td class="num"><?= $__usd($__b['supplier_usd'] + $__b['other_costs']) ?></td></tr>
      <?php $__opts = []; foreach ($__adj['lines'] as $__l) {
          $__opts[] = $__l['option'] . ' ' . match ($__l['kind']) {
              'exact' => '(in the exact price)', 'included' => '(included in base price)',
              'pct' => \App\Services\ProductSpecs::changeLabel('pct', (float) $__l['value']),
              default => '+USD ' . number_format((float) $__l['usd_effect'], 2) . (!empty($__l['converted']) ? ' per CBM' : ''),
          };
      } ?>
      <tr><td><?= $__opts !== [] ? View::e(implode(' · ', $__opts)) : 'Base options — no changes' ?><?php if ($__adj['charges'] !== []): ?><br><small class="muted"><?= View::e(implode('; ', $__adj['charges'])) ?></small><?php endif; ?></td><td class="num">cost <?= $__usd($__b['cost']) ?><?= $__adj['basis'] === 'cbm' ? ' / CBM' : '' ?></td></tr>
      <tr><td>Minimum price (<?= View::e(PricingService::pct($__e['margin_pct'])) ?> margin)</td><td class="num"><?= $__usd($__b['minimum']) ?></td></tr>
      <tr><td>Staff floor (+ team incentive)</td><td class="num"><?= $__usd($__b['staff_floor']) ?></td></tr>
      <tr><td>Staff quote (+<?= View::e(PricingService::pct($__e['buffer_pct'])) ?>)</td><td class="num"><strong><?= $__usd($__b['staff_quote']) ?></strong></td></tr>
      <?php foreach ($__p['levels'] as $__l): ?>
        <tr><td>Associate <?= View::e($__l['name']) ?> (<?= View::e($__l['label']) ?>): floor · quote</td><td class="num"><?= number_format($__l['floor'], 2) ?> · <strong><?= number_format($__l['quote'], 2) ?></strong></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="small muted">Per <?= View::e((string) $__p['unit']) ?>, FOB, USD. The same working as an associate's "Get price" and the order page.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
