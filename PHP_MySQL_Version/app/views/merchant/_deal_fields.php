<?php use App\Services\ImportService as I; use App\Services\MerchantTradeService as M;
/* Owner decision D-257 Phase 4: the deal's own fields, on the create form and on the deal page ($v reads a value). */
$v ??= $o;
$sel = static fn (string $k, string $val): string => (string) $v($k) === $val ? 'selected' : ''; ?>
    <label>Deal date *<input type="date" name="deal_date" required max="<?= date('Y-m-d') ?>" value="<?= $v('deal_date') ?: date('Y-m-d') ?>"></label>
    <label>Supplier *<input type="text" name="supplier_name" required maxlength="255" value="<?= $v('supplier_name') ?>" placeholder="e.g. Anatolia Marble Export A.S."></label>
    <label>Supplier's country *<input type="text" name="supplier_country" required maxlength="80" value="<?= $v('supplier_country') ?>" placeholder="e.g. Turkey"></label>
    <label>Supplier address<textarea name="supplier_address" rows="2" maxlength="1000" placeholder="e.g. Organize Sanayi Bolgesi 12, Afyonkarahisar"><?= $v('supplier_address') ?></textarea></label>
    <label>Purchase currency *<select name="purchase_currency" required><option value="">Choose…</option><?php foreach (I::CURRENCIES as $c): ?><option value="<?= $c ?>" <?= $sel('purchase_currency', $c) ?>><?= $c ?></option><?php endforeach; ?></select></label>
    <label>Purchase Incoterm<select name="purchase_incoterm"><option value="">—</option><?php foreach (M::INCOTERMS as $t): ?><option value="<?= $t ?>" <?= $sel('purchase_incoterm', $t) ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Buyer *<input type="text" name="buyer_name" required maxlength="255" value="<?= $v('buyer_name') ?>" placeholder="e.g. Gulf Stone Trading LLC"></label>
    <label>Buyer's country *<input type="text" name="buyer_country" required maxlength="80" value="<?= $v('buyer_country') ?>" placeholder="e.g. United Arab Emirates"></label>
    <label>Buyer address<textarea name="buyer_address" rows="2" maxlength="1000" placeholder="e.g. Office 1203, Al Moosa Tower, Dubai"><?= $v('buyer_address') ?></textarea></label>
    <label>Sale currency *<select name="sale_currency" required><option value="">Choose…</option><?php foreach (I::CURRENCIES as $c): ?><option value="<?= $c ?>" <?= $sel('sale_currency', $c) ?>><?= $c ?></option><?php endforeach; ?></select></label>
    <label>Sale Incoterm<select name="sale_incoterm"><option value="">—</option><?php foreach (M::INCOTERMS as $t): ?><option value="<?= $t ?>" <?= $sel('sale_incoterm', $t) ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Goods come from (country) *<input type="text" name="origin_country" required maxlength="80" value="<?= $v('origin_country') ?>" placeholder="e.g. Turkey"></label>
    <label>Port of loading<input type="text" name="port_of_loading" maxlength="100" value="<?= $v('port_of_loading') ?>" placeholder="e.g. Izmir, Turkey"></label>
    <label>Port of discharge<input type="text" name="port_of_discharge" maxlength="100" value="<?= $v('port_of_discharge') ?>" placeholder="e.g. Jebel Ali, UAE"></label>
