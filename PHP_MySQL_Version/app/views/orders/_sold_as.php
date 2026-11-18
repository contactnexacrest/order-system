<?php
/**
 * Owner decision D-385 (18 Nov 2026): an order line's "Sold as" — one of the product's ticked
 * specifications (every active one when none is ticked; _product_picker_js.php keeps only the
 * picked product's), with the HS code it sets shown read-only. Choosing it refills the HS code,
 * the Unit list (only the form's units) and the Surface finish (the form's finishes). Only the MD
 * or the ED (override_line_hs_code) may type another HS code, with a reason (shown when the code
 * differs). Required for a goods line picked from Products on a USD order where picking from
 * Products is required (the browser sets it; the server checks the same in ProductForms::checkLine).
 *
 * In: $soldAsSpecs (ProductForms::pickerSpecifications), $canOverrideHs, $__soldAsName ('hs_spec_id'
 *     or 'product_hs_spec_id[]'), $__soldAsSelected (int), $__soldAsLine (the saved line or []),
 *     $__soldAsReasonName ('hs_override_reason' or 'product_hs_override_reason[]'), $__soldAsReason.
 * Node twin: views/orders/_sold_as.njk.
 */
$__soldAsSelected = (int) ($__soldAsSelected ?? 0);
$__soldAsLine = $__soldAsLine ?? [];
$__soldAsIn = false;
foreach ($soldAsSpecs ?? [] as $__ss) {
    $__soldAsIn = $__soldAsIn || (int) $__ss['id'] === $__soldAsSelected;
}
?>
<label class="sold-as-field">Sold as <small class="muted">(sets the HS code, the units and the finishes)</small>
  <?php $__soldAsSaved = !empty($__soldAsLine['id']); // a saved line (else a new line / New Order row) ?>
  <select name="<?= htmlspecialchars((string) $__soldAsName) ?>" class="sold-as-select" data-required-message="Choose what the product is sold as — it sets the line's HS code."<?= !empty($canOverrideHs) ? ' data-can-override="1"' : '' ?>
    <?= $__soldAsSaved ? '' : ' data-new-line="1"' ?><?= $__soldAsSelected > 0 ? ' data-saved-spec="' . $__soldAsSelected . '"' : '' ?><?= $__soldAsSaved && $__soldAsSelected === 0 && !empty($__soldAsLine['catalog_product_id']) ? ' data-saved-without="' . (int) $__soldAsLine['catalog_product_id'] . '"' : '' ?>>
    <option value="">Choose…</option>
    <?php foreach ($soldAsSpecs ?? [] as $__ss): ?>
      <option value="<?= (int) $__ss['id'] ?>" data-hs="<?= htmlspecialchars((string) $__ss['hs_code']) ?>" data-form="<?= htmlspecialchars((string) $__ss['form']) ?>"
        data-units="<?= htmlspecialchars(implode(',', $__ss['units'])) ?>" data-default-unit="<?= htmlspecialchars((string) $__ss['default_unit']) ?>"
        data-default-finish="<?= htmlspecialchars((string) ($__ss['default_finish'] ?? '')) ?>"<?= $__ss['finishes'] !== null ? ' data-finishes="' . htmlspecialchars((string) json_encode($__ss['finishes'])) . '"' : '' ?>
        <?= (int) $__ss['id'] === $__soldAsSelected ? 'selected' : '' ?>><?= htmlspecialchars((string) $__ss['name']) ?> · HS <?= htmlspecialchars((string) $__ss['hs_code']) ?></option>
    <?php endforeach; ?>
    <?php if ($__soldAsSelected > 0 && !$__soldAsIn): // a switched-off specification a saved line keeps ?>
      <option value="<?= $__soldAsSelected ?>" selected data-hs="<?= htmlspecialchars((string) ($__soldAsLine['hs_code'] ?? '')) ?>">The saved Sold as is no longer offered — it stays unless you choose again</option>
    <?php endif; ?>
  </select>
</label>
<p class="muted small sold-as-hs" data-sold-as-hs aria-live="polite"<?= $__soldAsSelected > 0 ? '' : ' hidden' ?>>HS code: <b data-sold-as-hs-code><?= htmlspecialchars((string) ($__soldAsLine['hs_code'] ?? '')) ?></b>
  <span class="tag" data-sold-as-hs-tag><?php $__soldAsSpecHs = ''; foreach ($soldAsSpecs ?? [] as $__ss) { if ((int) $__ss['id'] === $__soldAsSelected) { $__soldAsSpecHs = (string) $__ss['hs_code']; } } ?><?= trim((string) ($__soldAsLine['hs_override_reason'] ?? '')) !== '' ? htmlspecialchars('changed by MD/ED' . ($__soldAsSpecHs !== '' ? ' (specification: ' . $__soldAsSpecHs . ')' : '')) : 'from specification' ?></span><?php if (!empty($canOverrideHs)): ?> <button type="button" class="btn-link" data-change-hs>Change HS code</button> <span class="muted">(with a reason)</span><?php endif; ?></p>
<?php if (!empty($canOverrideHs)): ?>
<label class="hs-override-reason" data-hs-override-reason<?= trim((string) ($__soldAsReason ?? '')) === '' ? ' hidden' : '' ?>>Reason for changing the HS code
  <input type="text" name="<?= htmlspecialchars((string) $__soldAsReasonName) ?>" maxlength="500" value="<?= htmlspecialchars((string) ($__soldAsReason ?? '')) ?>" placeholder="e.g. Buyer's customs classify these as worked monuments" data-required-message="Give the reason for changing the HS code from its specification."></label>
<?php else: ?>
<input type="hidden" name="<?= htmlspecialchars((string) $__soldAsReasonName) ?>" value="<?= htmlspecialchars((string) ($__soldAsReason ?? '')) ?>">
<?php endif; ?>
