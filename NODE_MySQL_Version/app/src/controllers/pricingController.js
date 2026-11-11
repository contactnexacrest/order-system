'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const pricingRepository = require('../repositories/pricingRepository');
const productRepository = require('../repositories/productRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const pricingEngine = require('../services/pricingEngine');
const pricingService = require('../services/pricingService');
const productService = require('../services/productService');
const thicknessPrices = require('../services/thicknessPrices');
const priceRules = require('../services/priceRules');
const supplierRepository = require('../repositories/supplierRepository');
const lookupRepository = require('../repositories/lookupRepository');

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
  const fxRateService = require('../services/fxRateService');
  res.renderView('pricing/settings', {
    settings: await pricingService.settingsRaw(),
    labels: pricingService.LABELS,
    canManage: can(req, 'manage_pricing'),
    programOn: await pricingService.programOn(),
    // D-301 (point 4): the exchange rate in use, its source and date, and the history.
    fx: await fxRateService.current(),
    fxHistory: await fxRateService.history(30),
    fxJumpPct: fxRateService.JUMP_PCT,
  }, 'layout/base');
}

/** D-301: the MD's OK (or not) for a market rate that moved more than 2 % in a day. */
async function decideFxRate(req, res) {
  const body = req.body || {};
  const approve = String(body.decision ?? '') === 'approve';
  const error = await require('../services/fxRateService').decide(parseInt(req.params.id, 10) || 0, approve, String(body.reason ?? '').trim(), req.user.id);
  flash.set(req, error === null ? 'success' : 'error', error ?? (approve ? 'Rate approved — new quotes use it from now on. Issued Quotations and Proforma Invoices keep their prices.' : 'Rate not used — prices keep the rate in use.'));
  res.redirect('/pricing/settings#exchange-rate');
}

/** D-301: fetch today's rate now (the same as the daily job). */
async function fetchFxRate(req, res) {
  const r = await require('../services/fxRateService').fetchDaily();
  await auditLogRepository.log(req.user.id, 'FX_RATE_FETCHED', 'fx_rates', null, null, null, r.message);
  flash.set(req, r.status === 'failed' ? 'error' : 'success', r.message);
  res.redirect('/pricing/settings#exchange-rate');
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

const ymdOf = (v) => (v ? String(v).slice(0, 10) : null);

async function marketPrices(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }
  const s = await pricingService.settings();
  const rules = await thicknessPrices.rules(product, s);
  const rows = [];
  for (const r of await productSupplierRepository.forProduct(id)) {
    const basis = (r.price_basis || 'unit') === 'cbm' || (r.combo_per || '') === 'cbm' ? 'cbm' : 'unit';
    const fob = await pricingService.fobParts(product, r, basis, null, s, rules);
    const isGrid = (r.price_basis || '') === 'grid';
    rows.push({
      ...r,
      fob,
      effective_fob: isGrid ? (fob ? fob.supplier : null) : productService.effectiveFobForSupplier(r, product),
      grid: isGrid ? thicknessPrices.complete(rules, await thicknessPrices.typedFor(Number(r.id))) : null,
      staleness: pricingService.staleness(r, s.stale_days),
      price_date: pricingService.priceDate(r),
      quoted_on: ymdOf(r.quoted_on),
      valid_until: ymdOf(r.valid_until),
    });
  }
  for (const r of rows) r.grid_list = r.grid && r.grid.ok ? thicknessPrices.ordered(r.grid) : [];
  // Newest first; the ones used for pricing on top.
  const used = (r) => Math.max(Number(r.is_primary), Number(r.is_cbm_primary || 0), Number(r.combo_in_use || 0));
  rows.sort((a, b) => (used(b) - used(a)) || String(b.price_date || '').localeCompare(String(a.price_date || '')));
  // D-256: exact prices name a combination of options.
  const productSpecs = require('../services/productSpecs');
  const offered = await productSpecs.offered(product);
  const named = await pricingRepository.comboOptions(rows.filter((r) => (r.price_basis || '') === 'combo').map((r) => Number(r.id)));
  for (const r of rows) r.combo_text = (r.price_basis || '') === 'combo' ? await pricingService.comboText(offered, named[Number(r.id)] || {}) : null;
  const quotes = (await pricingRepository.purchaseQuotesForProduct(id)).map((q) => ({
    ...q, recorded_on: String(q.recorded_at).slice(0, 10), valid_until: q.valid_until ? String(q.valid_until).slice(0, 10) : null,
  }));
  let prefill = null;
  if (String(req.query.from_quote ?? '') !== '') {
    const q = await pricingRepository.purchaseQuote(req.query.from_quote, id);
    if (q) {
      prefill = {
        purchase_quote_id: Number(q.id), supplier_id: Number(q.supplier_id), supplier_name: String(q.supplier_legal_name), price_inr: Number(q.unit_price_inr).toFixed(2),
        quoted_on: String(q.recorded_at).slice(0, 10), valid_until: q.valid_until ? String(q.valid_until).slice(0, 10) : '',
      };
    }
  }
  // D-303: a supplier just added quickly is picked already.
  if (prefill === null && String(req.query.supplier_id ?? '') !== '') {
    const sup = await supplierRepository.find(parseInt(req.query.supplier_id, 10) || 0);
    if (sup) prefill = { supplier_id: Number(sup.id), supplier_name: String(sup.supplier_legal_name) };
  }
  res.renderView('products/market_prices', {
    product, rows, staleDays: s.stale_days, cbmFrom: s.cbm_from_mm, quotes, canRecord: can(req, 'record_market_prices'), today: todayYmd(),
    offered, prefill, rules, rulesSummary: priceRules.summary(rules), gridView: gridView(rules, null),
    pickList: await supplierRepository.pickList(),
    supplierTypes: (await lookupRepository.dropdownOptions('supplier_type')).map((o) => String(o.option_value)),
    canQuickAdd: can(req, 'record_market_prices') || can(req, 'manage_suppliers'),
    canChangeRules: can(req, 'manage_pricing'),
    cha: chaView(rules, product, null),
  }, 'layout/base');
}

/** D-300: the rows of the thickness grid for the view (same text as the PHP partial). */
function gridView(rules, done) {
  const rows = rules.rows.map((r) => ({ mm: r.mm, pct: r.pct, cbm: false }));
  rows.push({ mm: thicknessPrices.CBM, pct: null, cbm: true });
  return rows.map((r) => {
    const v = done && done.values ? done.values[r.mm] : null;
    const typed = !!v && v.how === 'typed';
    const rule = r.cbm ? `${rules.base_mm} mm price × ${thicknessPrices.fmt(rules.k)}`
      : (r.mm === rules.base_mm ? 'base' : `${rules.base_mm} mm ${r.pct < 0 ? '− ' : '+ '}${thicknessPrices.fmt(Math.abs(r.pct))}%`);
    return {
      mm: r.mm, cbm: r.cbm, pct: r.cbm ? null : thicknessPrices.fmt(r.pct), typed, rule, label: thicknessPrices.label(r.mm, rules.cbm_from),
      value: v ? v.price.toFixed(2) : '', placeholder: v ? v.calc.toFixed(2) : (r.cbm ? 'e.g. 64000.00' : 'e.g. 1600.00'),
      hint: typed && Math.abs(v.calc - v.price) > 0.005 ? `calculated would be ${fmt2(v.calc)}` : '',
      perCbm: v ? `₹${fmt2(r.cbm ? v.price : thicknessPrices.m2ToCbm(v.price, r.mm))}` : '',
    };
  });
}

/** D-300: what the CHA + transport box shows (same text as the PHP partial). */
function chaView(rules, product, row) {
  const val = row && row.cha_transport_per_container !== null && row.cha_transport_per_container !== undefined ? Number(row.cha_transport_per_container) : null;
  const def = product.cha_transport_per_container !== null && product.cha_transport_per_container !== undefined ? Number(product.cha_transport_per_container) : null;
  const use = val !== null ? val : def;
  const fit = thicknessPrices.chaPerCbm(use, Number(rules.container_cbm), rules.container_max_t, rules.t_per_cbm);
  const sqft = require('../services/lineUnits').SQFT_PER_M2;
  return {
    value: val !== null ? val.toFixed(2) : '', placeholder: def !== null ? `product default ${def.toFixed(2)}` : 'e.g. 60000.00',
    def: def !== null ? fmt2(def) : null, includes: !row || Number(row.includes_packing_loading ?? 1) === 1,
    containerCbm: thicknessPrices.fmt(rules.container_cbm), containerMaxT: thicknessPrices.fmt(rules.container_max_t),
    ownContainer: rules.own.includes('container'), fitsCbm: fit.fits_cbm.toFixed(4),
    perCbm: fit.per_cbm !== null ? `₹${fmt2(fit.per_cbm)} / CBM` : '—',
    weightLine: rules.t_per_cbm !== null ? `Weight check: ${thicknessPrices.fmt(rules.container_cbm)} CBM × ${thicknessPrices.fmt(rules.t_per_cbm)} t = ${thicknessPrices.fmt(Math.round(fit.weight_t * 100) / 100)} t ${fit.by_weight
      ? `— over ${thicknessPrices.fmt(rules.container_max_t)} t, so only ${fmt2(fit.fits_cbm)} CBM fit and the price per CBM is worked out from that.`
      : `≤ ${thicknessPrices.fmt(rules.container_max_t)} t ✓`}` : null,
    rows: rules.grid ? rules.rows.map((r) => {
      const m2 = fit.fits_cbm / (r.mm / 1000);
      return { mm: r.mm, m2: m2.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }), sqft: Math.round(m2 * sqft).toLocaleString('en-US'),
        perM2: fit.per_cbm !== null ? fmt2(fit.per_cbm * r.mm / 1000) : '—', perSqft: fit.per_cbm !== null ? fmt2(fit.per_cbm * r.mm / 1000 / sqft) : '—' };
    }) : [],
    cbmLine: fit.per_cbm !== null ? `₹${fmt2(fit.per_cbm)} per CBM` : '—',
  };
}

/** D-303 (point 15): the supplier of a price is a record of the Suppliers directory: [supplier, error]. */
async function pickedSupplier(body) {
  const id = parseInt(body.supplier_id, 10) || 0;
  let sup = id > 0 ? await supplierRepository.find(id) : null;
  if (!sup && String(body.supplier_name ?? '').trim() !== '') sup = await supplierRepository.findByExactName(String(body.supplier_name));
  if (!sup || Number(sup.is_active) !== 1) return [null, 'Pick the supplier from the Suppliers directory — or add it with "+ Add a new supplier (quick)".'];
  return [sup, null];
}

const lastOf = (v) => (Array.isArray(v) ? v[v.length - 1] : v);

function readMarketPrice(body, grid = false) {
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
    fob_value: grid ? null : num('price_inr'),
    factory_cost: null, transportation_cost: null, packing_cost: null, loading_cost: null, cha_cost: null,
    is_primary: body.used_for_pricing ? 1 : 0,
    notes: opt('notes'),
    quoted_on: date('quoted_on'),
    valid_until: date('valid_until'),
    // D-255: per unit, or per CBM for thick stone; D-256 an exact combination; D-300 every thickness.
    price_basis: ['cbm', 'combo', 'grid'].includes(body.price_basis) ? body.price_basis : 'unit',
    // D-300: packing and loading included (ticked by default); CHA + transport per container.
    includes_packing_loading: String(lastOf(body.includes_packing_loading) ?? '1') === '1',
    cha_transport_per_container: num('cha_transport_per_container'),
  };
  if (data.supplier_name === '' || [...data.supplier_name].length > 255) return [data, 'Pick the supplier from the Suppliers directory.'];
  if (!grid) {
    const raw = String(body.price_inr ?? '').trim();
    if (data.fob_value === null || data.fob_value <= 0 || data.fob_value > 999999999999.99 || !/^\d+(\.\d{1,2})?$/.test(raw)) {
      return [data, 'Enter the supplier\'s price in ₹ (above 0, at most 2 decimals).'];
    }
  }
  const cha = String(body.cha_transport_per_container ?? '').trim();
  if (cha !== '' && !/^\d{1,10}(\.\d{1,2})?$/.test(cha)) {
    return [data, 'CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty for the product\'s default.'];
  }
  if (data.quoted_on === null) return [data, 'Enter the date the supplier quoted this price.'];
  if (data.quoted_on > todayYmd()) return [data, 'The quoted date cannot be in the future.'];
  if (String(body.valid_until ?? '').trim() !== '' && data.valid_until === null) return [data, 'Enter a valid "valid until" date, or leave it empty.'];
  if (data.valid_until !== null && data.valid_until < data.quoted_on) return [data, '"Valid until" cannot be before the quoted date.'];
  return [data, null];
}

/** D-256: the options an exact price names (combo[spec id] = option id; empty = any): [named, per, error]. */
async function readCombo(product, body) {
  const productSpecs = require('../services/productSpecs');
  const offered = await productSpecs.offered(product);
  const raw = body.combo && typeof body.combo === 'object' ? body.combo : {};
  const named = {};
  let per = 'unit';
  const s = await pricingService.settings();
  const cbmFrom = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : s.cbm_from_mm;
  for (const sp of offered) {
    const v = String(raw[sp.id] ?? body[`combo[${sp.id}]`] ?? '');
    if (v === '') continue;
    const found = sp.options.find((o) => String(o.id) === v);
    if (!found) return [{}, 'unit', `${sp.name}: that option is not offered for this product.`];
    named[sp.id] = found.id;
    if (sp.kind === 'thickness' && (found.change_type === 'cbm' || (found.thickness_mm !== null && found.thickness_mm >= cbmFrom))) per = 'cbm';
  }
  if (!Object.keys(named).length) return [{}, 'unit', 'Choose at least one option the exact price is for (e.g. thickness and finish) — or record it as the base price.'];
  return [named, per, null];
}

/** "20 mm ₹1,600.00 per m² · … (typed; others calculated)" for the audit log. */
function gridText(typed, rules) {
  return `${Object.entries(typed).map(([mm, v]) => `${thicknessPrices.label(Number(mm), rules.cbm_from)} ₹${fmt2(v)}${Number(mm) === thicknessPrices.CBM ? ' per CBM' : ' per m²'}`).join(' · ')} (typed; others calculated)`;
}

async function addMarketPrice(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    flash.set(req, 'error', 'That product no longer exists.');
    res.redirect('/products');
    return;
  }
  const back = `/products/${productId}/market-prices`;
  const body = { ...(req.body || {}) };
  let quoteId = null;
  if (String(body.purchase_quote_id ?? '') !== '') {
    const q = await pricingRepository.purchaseQuote(body.purchase_quote_id, productId);
    if (!q) {
      flash.set(req, 'error', 'That supplier quote was not found for this product.');
      res.redirect(back);
      return;
    }
    quoteId = Number(q.id);
    body.supplier_id = String(q.supplier_id);
    body.supplier_name = q.supplier_legal_name;
    body.quoted_on = String(q.recorded_at).slice(0, 10);
    body.valid_until = q.valid_until ? String(q.valid_until).slice(0, 10) : '';
    body.notes = `Copied from supplier quote${q.quote_reference ? ` ${q.quote_reference}` : ''} (Procurement).`;
    if (body.price === undefined && body.price_inr === undefined) body.price_inr = Number(q.unit_price_inr).toFixed(2);
  }
  const s = await pricingService.settings();
  const rules = await thicknessPrices.rules(product, s);
  // D-300: for a category with thickness price rules the ordinary price is one row for every thickness.
  if (String(body.price_basis ?? '') === '') body.price_basis = rules.grid && body.price_inr === undefined ? 'grid' : 'unit';
  const grid = body.price_basis === 'grid';
  if (grid && !rules.grid) {
    flash.set(req, 'error', "This product's category has no thickness price rules — enter one price.");
    res.redirect(back);
    return;
  }
  const [picked, pickError] = await pickedSupplier(body);
  if (pickError) {
    flash.set(req, 'error', pickError);
    res.redirect(`${back}#add-price`);
    return;
  }
  body.supplier_name = String(picked.supplier_legal_name);
  let [data, error] = readMarketPrice(body, grid);
  let typed = {};
  if (!error && grid) [typed, error] = thicknessPrices.readForm(body, rules);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`${back}#add-price`);
    return;
  }
  data.product_id = productId;
  const usedForPricing = data.is_primary === 1;
  // D-256: an exact price for a specific combination names one option for some specifications.
  let named = {};
  let per = 'unit';
  if (data.price_basis === 'combo') {
    let comboError;
    [named, per, comboError] = await readCombo(product, body);
    if (comboError) {
      flash.set(req, 'error', comboError);
      res.redirect(back);
      return;
    }
  }
  if (!['unit', 'grid'].includes(data.price_basis)) data.is_primary = 0;
  const id = await productSupplierRepository.create(data, req.user.id);
  await pricingRepository.setSupplierDates(id, data.quoted_on, data.valid_until, quoteId);
  await productSupplierRepository.setPriceDetails(id, Number(picked.id), data.includes_packing_loading, data.cha_transport_per_container);
  if (data.price_basis === 'combo') {
    // An exact price is in use (or not) by itself: it never replaces the ordinary price.
    await pricingRepository.setCombo(id, named, per, usedForPricing);
  } else {
    await pricingRepository.setSupplierBasis(id, data.price_basis);
    if (grid) await thicknessPrices.saveTyped(id, typed, rules, req.user.id);
    if (usedForPricing && data.price_basis === 'cbm') await pricingRepository.setCbmPrimary(productId, id);
    else if (usedForPricing) await productService.setPrimarySupplier(productId, id, req.user.id);
  }
  data.is_primary = usedForPricing ? 1 : 0;
  const comboText = data.price_basis === 'combo' ? await pricingService.comboText(await require('../services/productSpecs').offered(product), named) : null;
  const priceText = grid ? gridText(typed, rules) : `₹${fmt2(data.fob_value)}`;
  await auditLogRepository.log(req.user.id, 'MARKET_PRICE_ADDED', 'product_suppliers', id, null, null,
    `${data.supplier_name}: ${priceText}${comboText !== null ? ` for ${comboText}${per === 'cbm' ? ' per CBM' : ''}` : ''} quoted ${data.quoted_on}${data.is_primary ? ' (used for pricing)' : ''}`);
  flash.set(req, 'success', `Supplier price recorded${data.is_primary ? ' and used for pricing.' : '.'}`);
  res.redirect(back);
}

/** D-300 (point 12): one supplier price — every thickness, CHA + transport per container, its history. */
async function editPrice(req, res) {
  const row = await productSupplierRepository.find(parseInt(req.params.supplierId, 10) || 0);
  const product = row ? await productRepository.find(Number(row.product_id)) : null;
  if (!row || !product) {
    res.status(404).send('Supplier price not found.');
    return;
  }
  const s = await pricingService.settings();
  const rules = await thicknessPrices.rules(product, s);
  const isGrid = (row.price_basis || '') === 'grid';
  const done = isGrid ? thicknessPrices.complete(rules, await thicknessPrices.typedFor(Number(row.id))) : null;
  const history = (await thicknessPrices.historyFor(Number(row.id))).map((h) => ({ ...h, changed_at: String(h.changed_at) }));
  const fob = await pricingService.fobParts(product, row, (row.price_basis || '') === 'cbm' ? 'cbm' : 'unit', null, s, rules);
  res.renderView('products/supplier_price_edit', {
    product, row: { ...row, quoted_on: ymdOf(row.quoted_on), valid_until: ymdOf(row.valid_until), updated_on: ymdOf(row.updated_at) },
    rules, rulesSummary: priceRules.summary(rules), grid: done, gridView: isGrid ? gridView(rules, done) : null,
    fob, fobMmLabel: fob && fob.mm !== null && fob.mm !== undefined ? `${thicknessPrices.fmt(fob.mm)} mm` : 'base',
    history, pickList: await supplierRepository.pickList(), today: todayYmd(),
    canRecord: can(req, 'record_market_prices'), canChangeRules: can(req, 'manage_pricing'), cha: chaView(rules, product, row),
  }, 'layout/base');
}

async function savePrice(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const s = await productSupplierRepository.find(supplierId);
  const product = s ? await productRepository.find(Number(s.product_id)) : null;
  if (!s || !product) {
    flash.set(req, 'error', 'That supplier price no longer exists.');
    res.redirect('/products');
    return;
  }
  const productId = Number(s.product_id);
  const back = `/products/suppliers/${supplierId}/price`;
  const isGrid = (s.price_basis || '') === 'grid';
  const rules = await thicknessPrices.rules(product);
  const body = { ...(req.body || {}), price_basis: String(s.price_basis), supplier_name: String(s.supplier_name) };
  let picked = null;
  if ((parseInt(body.supplier_id, 10) || 0) > 0 || s.supplier_id === null) {
    let pickError;
    [picked, pickError] = await pickedSupplier(body);
    if (pickError) {
      flash.set(req, 'error', pickError);
      res.redirect(back);
      return;
    }
    body.supplier_name = String(picked.supplier_legal_name);
  } else {
    body.supplier_name = String(s.supplier_name);
  }
  let [data, error] = readMarketPrice(body, isGrid);
  let typed = {};
  if (!error && isGrid) [typed, error] = thicknessPrices.readForm(body, rules);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(back);
    return;
  }
  const userId = req.user.id;
  const oldPrice = s.fob_value !== null ? Number(s.fob_value).toFixed(2) : null;
  const before = `${isGrid ? gridText(await thicknessPrices.typedFor(supplierId), rules) : `₹${fmt2(productService.effectiveFobForSupplier(s, product) || 0)}`} quoted ${pricingService.priceDate(s) || '—'}`;
  const oldCha = s.cha_transport_per_container !== null && s.cha_transport_per_container !== undefined ? Number(s.cha_transport_per_container).toFixed(2) : null;
  const oldIncl = Number(s.includes_packing_loading ?? 1) === 1;
  const updated = { ...s, fob_source: 'direct', fob_value: isGrid ? s.fob_value : data.fob_value, notes: data.notes, location: data.location ?? s.location, supplier_name: data.supplier_name };
  await productSupplierRepository.update(supplierId, updated, userId);
  await pricingRepository.setSupplierDates(supplierId, data.quoted_on, data.valid_until);
  await productSupplierRepository.setPriceDetails(supplierId, picked ? Number(picked.id) : Number(s.supplier_id), data.includes_packing_loading, data.cha_transport_per_container);
  // D-300: the price history — who, when, old → new.
  if (isGrid) {
    await thicknessPrices.saveTyped(supplierId, typed, rules, userId);
  } else if (oldPrice !== Number(data.fob_value).toFixed(2)) {
    await thicknessPrices.history(supplierId, `Price per ${String(product.reference_unit || '') || 'unit'}`, oldPrice, Number(data.fob_value).toFixed(2), userId);
  }
  const newCha = data.cha_transport_per_container !== null ? Number(data.cha_transport_per_container).toFixed(2) : null;
  if (oldCha !== newCha) await thicknessPrices.history(supplierId, 'CHA + transport per container', oldCha ?? 'product default', newCha ?? 'product default', userId);
  if (oldIncl !== data.includes_packing_loading) {
    await thicknessPrices.history(supplierId, 'Price includes packing and loading', oldIncl ? 'yes' : 'no', data.includes_packing_loading ? 'yes' : 'no', userId);
  }
  const after = `${isGrid ? gridText(typed, rules) : `₹${fmt2(data.fob_value)}`} quoted ${data.quoted_on}`;
  await auditLogRepository.log(userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', supplierId, null, before, after);
  flash.set(req, 'success', 'Supplier price saved.');
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
  if ((s.price_basis || 'unit') === 'combo') {
    // D-256: an exact price is switched on or off by itself.
    const now = Number(s.combo_in_use || 0) !== 1;
    await pricingRepository.setComboInUse(supplierId, now);
    await auditLogRepository.log(req.user.id, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', supplierId, 'combo_in_use', now ? '0' : '1', now ? '1' : '0');
    flash.set(req, 'success', now ? `${s.supplier_name}'s exact price is now used for matching quotes.` : `${s.supplier_name}'s exact price is no longer used.`);
    res.redirect(`/products/${productId}/market-prices`);
    return;
  }
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
  editPrice, savePrice, decideFxRate, fetchFxRate, gridView, chaView,
};
