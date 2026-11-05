'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const pricingRepository = require('../repositories/pricingRepository');
const productRepository = require('../repositories/productRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const pricingEngine = require('../services/pricingEngine');
const pricingService = require('../services/pricingService');
const productService = require('../services/productService');

/**
 * Port of App\Controllers\PricingController — owner decision D-245 (4 Nov 2026), P1:
 * Admin → Pricing settings, associate commission levels and ladder, a product's
 * pricing overrides, and Procurement's market prices.
 */

const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const fmt2 = (v) => pricingService.numberFormat(v, 2);
const todayYmd = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

// ------------------------------------------------------------------ settings

async function settings(req, res) {
  res.renderView('pricing/settings', {
    settings: await pricingService.settingsRaw(),
    labels: pricingService.LABELS,
    canManage: can(req, 'manage_pricing'),
    programOn: await pricingService.programOn(),
  }, 'layout/base');
}

async function saveSettings(req, res) {
  const [values, errors] = pricingService.readSettingsForm(req.body || {});
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/pricing/settings');
    return;
  }
  const before = await pricingService.settingsRaw();
  let changed = 0;
  for (const [k, v] of Object.entries(values)) {
    if (String(before[k]) !== v) {
      await pricingRepository.setSetting(k, v, req.user.id);
      await auditLogRepository.log(req.user.id, 'PRICING_SETTING_CHANGED', 'pricing_settings', null, k, String(before[k]), v);
      changed += 1;
    }
  }
  flash.set(req, 'success', changed === 0 ? 'Nothing changed.' : 'Pricing settings saved. New quotes use them; orders with an issued Proforma Invoice keep their prices.');
  res.redirect('/pricing/settings');
}

// ----------------------------------------------------- levels and the ladder

async function levels(req, res) {
  const all = await pricingRepository.levels();
  const active = all.filter((l) => Number(l.is_active) === 1);
  let sample = null;
  for (const p of await productRepository.all()) {
    const b = await pricingService.forProduct(p, active);
    if (b.build !== null) {
      sample = { product: p, pricing: b };
      break;
    }
  }
  const floors = {};
  if (sample) {
    for (const l of all) floors[Number(l.id)] = pricingEngine.associate(sample.pricing.build.raw, String(l.kind), Number(l.value));
  }
  const ladder = await pricingRepository.ladder();
  const stepsPerLevel = {};
  for (const s of ladder) stepsPerLevel[Number(s.level_id)] = (stepsPerLevel[Number(s.level_id)] || 0) + 1;
  const rows = ladder.map((s) => ({ ...s }));
  for (let i = 0; i < 2; i += 1) rows.push({ from_order: '', to_order: '', level_id: '' });
  res.renderView('pricing/commission_levels', {
    levels: all.map((l) => ({ ...l, label: pricingService.levelLabel(l), value_trim: String(l.value).replace(/\.?0+$/, '') })),
    use: await pricingRepository.associatesPerLevel(),
    stepsPerLevel,
    ladderRows: rows,
    sample,
    floors,
    canManage: can(req, 'manage_pricing'),
  }, 'layout/base');
}

async function readLevel(req, exceptId) {
  const b = req.body || {};
  const name = String(b.name ?? '').trim();
  const kind = String(b.kind ?? '');
  const value = String(b.value ?? '').trim();
  if (name === '' || [...name].length > 60) return [null, 'Enter the level\'s name (up to 60 characters).'];
  if (await pricingRepository.levelNameTaken(name, exceptId)) return [null, `A level called "${name}" already exists.`];
  if (!['pct', 'fixed_usd'].includes(kind)) return [null, 'Choose whether the level is a % of FOB or a fixed USD amount per unit.'];
  const max = kind === 'pct' ? 50 : 100000;
  if (Number.isNaN(Number(value)) || !/^\d+(\.\d{1,4})?$/.test(value) || Number(value) <= 0 || Number(value) > max) {
    return [null, kind === 'pct' ? 'Enter the commission as a % above 0 and up to 50, with at most 4 decimals.' : 'Enter the commission as a USD amount per unit above 0, with at most 4 decimals.'];
  }
  return [{ name, kind, value: Number(value).toFixed(4) }, null];
}

async function addLevel(req, res) {
  const [l, error] = await readLevel(req, null);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/pricing/commission-levels');
    return;
  }
  const id = await pricingRepository.createLevel(l.name, l.kind, l.value, req.user.id);
  await auditLogRepository.log(req.user.id, 'COMMISSION_LEVEL_ADDED', 'commission_levels', id, null, null, `${l.name} — ${pricingService.levelLabel(l)}`);
  flash.set(req, 'success', `Level "${l.name}" added.`);
  res.redirect('/pricing/commission-levels');
}

async function updateLevel(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const existing = await pricingRepository.findLevel(id);
  if (!existing) {
    flash.set(req, 'error', 'That commission level no longer exists.');
    res.redirect('/pricing/commission-levels');
    return;
  }
  const [l, error] = await readLevel(req, id);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/pricing/commission-levels');
    return;
  }
  await pricingRepository.updateLevel(id, l.name, l.kind, l.value, req.user.id);
  await auditLogRepository.log(req.user.id, 'COMMISSION_LEVEL_CHANGED', 'commission_levels', id, null,
    `${existing.name} — ${pricingService.levelLabel(existing)}`, `${l.name} — ${pricingService.levelLabel(l)}`);
  flash.set(req, 'success', `Level "${l.name}" saved. New quotes use it; orders with an issued Proforma Invoice keep the commission they had.`);
  res.redirect('/pricing/commission-levels');
}

async function toggleLevel(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const existing = await pricingRepository.findLevel(id);
  if (!existing) {
    flash.set(req, 'error', 'That commission level no longer exists.');
    res.redirect('/pricing/commission-levels');
    return;
  }
  const active = Number(existing.is_active) !== 1;
  await pricingRepository.setLevelActive(id, active, req.user.id);
  await auditLogRepository.log(req.user.id, active ? 'COMMISSION_LEVEL_ACTIVATED' : 'COMMISSION_LEVEL_DEACTIVATED', 'commission_levels', id, 'is_active', active ? '0' : '1', active ? '1' : '0');
  flash.set(req, 'success', `Level "${existing.name}" ${active ? 'activated.' : 'deactivated. Associates already on it keep it until you change them.'}`);
  res.redirect('/pricing/commission-levels');
}

async function deleteLevel(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const existing = await pricingRepository.findLevel(id);
  if (!existing) {
    flash.set(req, 'error', 'That commission level no longer exists.');
    res.redirect('/pricing/commission-levels');
    return;
  }
  const use = await pricingRepository.levelUse(id);
  if (use.associates > 0 || use.steps > 0) {
    flash.set(req, 'error', `Level "${existing.name}" is in use (${use.associates} associate(s), ${use.steps} ladder step(s)) — deactivate it instead.`);
    res.redirect('/pricing/commission-levels');
    return;
  }
  await pricingRepository.deleteLevel(id);
  await auditLogRepository.log(req.user.id, 'COMMISSION_LEVEL_DELETED', 'commission_levels', id, null, `${existing.name} — ${pricingService.levelLabel(existing)}`, null);
  flash.set(req, 'success', `Level "${existing.name}" deleted.`);
  res.redirect('/pricing/commission-levels');
}

async function saveLadder(req, res) {
  const levelsById = {};
  for (const l of await pricingRepository.levels()) levelsById[Number(l.id)] = l;
  const [steps, errors] = pricingService.readLadderForm(req.body || {}, levelsById);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/pricing/commission-levels#ladder');
    return;
  }
  const describe = (rows) => rows.map((s) => {
    const from = s.from ?? s.from_order;
    const to = s.to !== undefined ? s.to : s.to_order;
    return `${from}–${to === null || to === undefined ? 'onward' : to}: ${(levelsById[Number(s.level_id)] || {}).name || '?'}`;
  }).join('; ');
  const before = describe(await pricingRepository.ladder());
  const after = describe(steps);
  if (before !== after) {
    await pricingRepository.replaceLadder(steps, req.user.id);
    await auditLogRepository.log(req.user.id, 'COMMISSION_LADDER_CHANGED', 'commission_ladder_steps', null, null, before, after);
  }
  flash.set(req, 'success', before === after ? 'Nothing changed.' : 'Ladder saved. It counts orders from P3 on; associates on "Ladder" use it.');
  res.redirect('/pricing/commission-levels#ladder');
}

// ---------------------------------------------------------- product overrides

async function saveProductOverrides(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    flash.set(req, 'error', 'That product no longer exists.');
    res.redirect('/products');
    return;
  }
  const [fields, errors] = pricingService.readOverridesForm(req.body || {});
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(`/products/${id}#pricing`);
    return;
  }
  let changed = 0;
  for (const [k, v] of Object.entries(fields)) {
    const old = product[k] === null || product[k] === undefined ? null : String(product[k]);
    const neu = v === null ? null : String(v);
    if (old !== neu && !(old !== null && neu !== null && !Number.isNaN(Number(old)) && Number(old) === Number(neu))) {
      await auditLogRepository.log(req.user.id, 'PRODUCT_PRICING_CHANGED', 'products', id, k, old ?? 'global', neu ?? 'global');
      changed += 1;
    }
  }
  await pricingRepository.saveProductOverrides(id, fields);
  flash.set(req, 'success', changed === 0 ? 'Nothing changed.' : 'This product\'s pricing settings saved.');
  res.redirect(`/products/${id}#pricing`);
}

// -------------------------------------------------------------- market prices

async function marketPrices(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }
  const s = await pricingService.settings();
  const rows = [];
  for (const r of await productSupplierRepository.forProduct(id)) {
    rows.push({
      ...r,
      effective_fob: productService.effectiveFobForSupplier(r, product),
      staleness: pricingService.staleness(r, s.stale_days),
      price_date: pricingService.priceDate(r),
      quoted_on: r.quoted_on ? String(r.quoted_on).slice(0, 10) : null,
      valid_until: r.valid_until ? String(r.valid_until).slice(0, 10) : null,
    });
  }
  rows.sort((a, b) => (Math.max(Number(b.is_primary), Number(b.is_cbm_primary || 0)) - Math.max(Number(a.is_primary), Number(a.is_cbm_primary || 0))) || String(b.price_date || '').localeCompare(String(a.price_date || '')));
  const quotes = (await pricingRepository.purchaseQuotesForProduct(id)).map((q) => ({
    ...q, recorded_on: String(q.recorded_at).slice(0, 10), valid_until: q.valid_until ? String(q.valid_until).slice(0, 10) : null,
  }));
  res.renderView('products/market_prices', {
    product, rows, staleDays: s.stale_days, cbmFrom: s.cbm_from_mm, quotes, canRecord: can(req, 'record_market_prices'), today: todayYmd(),
  }, 'layout/base');
}

function readMarketPrice(body) {
  const num = (k) => {
    const v = String(body[k] ?? '').trim();
    return v === '' || Number.isNaN(Number(v)) ? null : Number(v);
  };
  const date = (k) => {
    const v = String(body[k] ?? '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return null;
    const d = new Date(`${v}T00:00:00Z`);
    return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v ? v : null;
  };
  const opt = (k) => String(body[k] ?? '').trim() || null;
  const data = {
    supplier_name: String(body.supplier_name ?? '').trim(),
    location: opt('location'),
    contact_person: null, contact_phone: null, contact_email: null,
    fob_source: 'direct',
    fob_value: num('price_inr'),
    factory_cost: null, transportation_cost: null, packing_cost: null, loading_cost: null, cha_cost: null,
    is_primary: body.used_for_pricing ? 1 : 0,
    notes: opt('notes'),
    quoted_on: date('quoted_on'),
    valid_until: date('valid_until'),
    // D-255: per unit, or per CBM for thick stone (50 mm and above).
    price_basis: body.price_basis === 'cbm' ? 'cbm' : 'unit',
  };
  if (data.supplier_name === '' || [...data.supplier_name].length > 255) return [data, 'Enter the supplier\'s name.'];
  const raw = String(body.price_inr ?? '').trim();
  if (data.fob_value === null || data.fob_value <= 0 || data.fob_value > 999999999999.99 || !/^\d+(\.\d{1,2})?$/.test(raw)) {
    return [data, 'Enter the supplier\'s price in ₹ (above 0, at most 2 decimals).'];
  }
  if (data.quoted_on === null) return [data, 'Enter the date the supplier quoted this price.'];
  if (data.quoted_on > todayYmd()) return [data, 'The quoted date cannot be in the future.'];
  if (String(body.valid_until ?? '').trim() !== '' && data.valid_until === null) return [data, 'Enter a valid "valid until" date, or leave it empty.'];
  if (data.valid_until !== null && data.valid_until < data.quoted_on) return [data, '"Valid until" cannot be before the quoted date.'];
  return [data, null];
}

async function addMarketPrice(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  if (!(await productRepository.find(productId))) {
    flash.set(req, 'error', 'That product no longer exists.');
    res.redirect('/products');
    return;
  }
  const body = { ...(req.body || {}) };
  let quoteId = null;
  if (String(body.purchase_quote_id ?? '') !== '') {
    const q = await pricingRepository.purchaseQuote(body.purchase_quote_id, productId);
    if (!q) {
      flash.set(req, 'error', 'That supplier quote was not found for this product.');
      res.redirect(`/products/${productId}/market-prices`);
      return;
    }
    quoteId = Number(q.id);
    body.supplier_name = q.supplier_legal_name;
    body.price_inr = Number(q.unit_price_inr).toFixed(2);
    body.quoted_on = String(q.recorded_at).slice(0, 10);
    body.valid_until = q.valid_until ? String(q.valid_until).slice(0, 10) : '';
    body.notes = `Copied from supplier quote${q.quote_reference ? ` ${q.quote_reference}` : ''} (Procurement).`;
  }
  const [data, error] = readMarketPrice(body);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}/market-prices`);
    return;
  }
  data.product_id = productId;
  const usedForPricing = data.is_primary === 1;
  if (data.price_basis === 'cbm') data.is_primary = 0;
  const id = await productSupplierRepository.create(data, req.user.id);
  await pricingRepository.setSupplierDates(id, data.quoted_on, data.valid_until, quoteId);
  await pricingRepository.setSupplierBasis(id, data.price_basis);
  if (usedForPricing && data.price_basis === 'cbm') await pricingRepository.setCbmPrimary(productId, id);
  else if (usedForPricing) await productService.setPrimarySupplier(productId, id, req.user.id);
  data.is_primary = usedForPricing ? 1 : 0;
  await auditLogRepository.log(req.user.id, 'MARKET_PRICE_ADDED', 'product_suppliers', id, null, null,
    `${data.supplier_name}: ₹${fmt2(data.fob_value)} quoted ${data.quoted_on}${data.is_primary ? ' (used for pricing)' : ''}`);
  flash.set(req, 'success', `Supplier price recorded${data.is_primary ? ' and used for pricing.' : '.'}`);
  res.redirect(`/products/${productId}/market-prices`);
}

async function useForPricing(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const s = await productSupplierRepository.find(supplierId);
  if (!s) {
    flash.set(req, 'error', 'That supplier price no longer exists.');
    res.redirect('/products');
    return;
  }
  const productId = Number(s.product_id);
  if ((s.price_basis || 'unit') === 'cbm') {
    // D-255: the per-CBM price used for thick stone (50 mm and above).
    const oldCbm = await pricingService.cbmSupplier(productId);
    await pricingRepository.setCbmPrimary(productId, supplierId);
    await auditLogRepository.log(req.user.id, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', supplierId, 'is_cbm_primary', oldCbm ? `${oldCbm.supplier_name} #${oldCbm.id}` : null, `${s.supplier_name} #${supplierId}`);
    flash.set(req, 'success', `${s.supplier_name}'s per-CBM price is now used for thick stone. New quotes use it.`);
    res.redirect(`/products/${productId}/market-prices`);
    return;
  }
  const old = await pricingService.pricingSupplier(productId);
  await productService.setPrimarySupplier(productId, supplierId, req.user.id);
  await auditLogRepository.log(req.user.id, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', supplierId, 'is_primary', old ? `${old.supplier_name} #${old.id}` : null, `${s.supplier_name} #${supplierId}`);
  flash.set(req, 'success', `${s.supplier_name}'s price is now used for pricing. New quotes use it.`);
  res.redirect(`/products/${productId}/market-prices`);
}

async function updateMarketDates(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const s = await productSupplierRepository.find(supplierId);
  if (!s) {
    flash.set(req, 'error', 'That supplier price no longer exists.');
    res.redirect('/products');
    return;
  }
  const productId = Number(s.product_id);
  const [data, error] = readMarketPrice({ ...(req.body || {}), supplier_name: s.supplier_name });
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}/market-prices`);
    return;
  }
  const product = (await productRepository.find(productId)) || {};
  const before = `₹${fmt2(productService.effectiveFobForSupplier(s, product) || 0)} quoted ${pricingService.priceDate(s) || '—'}`;
  const updated = { ...s, fob_source: 'direct', fob_value: data.fob_value, notes: data.notes, location: data.location ?? s.location };
  await productSupplierRepository.update(supplierId, updated, req.user.id);
  await pricingRepository.setSupplierDates(supplierId, data.quoted_on, data.valid_until);
  await auditLogRepository.log(req.user.id, 'MARKET_PRICE_CHANGED', 'product_suppliers', supplierId, null, before, `₹${fmt2(data.fob_value)} quoted ${data.quoted_on}`);
  flash.set(req, 'success', 'Supplier price updated.');
  res.redirect(`/products/${productId}/market-prices`);
}

module.exports = {
  settings, saveSettings, levels, addLevel, updateLevel, toggleLevel, deleteLevel, saveLadder,
  saveProductOverrides, marketPrices, addMarketPrice, useForPricing, updateMarketDates,
};
