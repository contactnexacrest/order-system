'use strict';

/**
 * D-431 (owner 25–26 Nov 2026): Admin › Brands — the division names associates see (name only, no logo).
 *  - Details (name, line under it, login address code, products, website, e-mail, status): "Manage divisions" (Admin, MD).
 *  - Rules and ladder (empty = the company setting; "Copy all company settings" / "Clear all"): "Manage associate rules"
 *    (D-411: MD, ED), each change with a reason (Audit Log). Company-wide settings stay in Associates › Settings.
 * Port of PHP App\Controllers\BrandController.
 */
const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const pricingRepository = require('../repositories/pricingRepository');
const repo = require('../repositories/associateBrandRepository');
const brands = require('../services/associateBrands');
const associateRules = require('../services/associateRules');
const pricingService = require('../services/pricingService');
const moneyFormat = require('../helpers/moneyFormat');

const len = (v) => [...String(v)].length;
const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const body = (req) => req.body || {};

async function index(req, res) {
  res.renderView('brands/index', {
    brands: await repo.all(), canEdit: can(req, 'manage_divisions'), statuses: brands.STATUSES, defaultTagline: brands.DEFAULT_TAGLINE,
  }, 'layout/base');
}

/** One rule value as staff read it ("₹5,00,000", "15 days", "A fixed %" …). */
function showValue(key, value) {
  switch (key) {
    case 'min_order_inr': return `₹${moneyFormat.indian(Number(value), 0)}`;
    case 'group_days': return `${value} days`;
    case 'price_rule': return associateRules.PRICE_RULES[value] || value;
    case 'price_fixed_pct': return `${String(Number(value).toFixed(2)).replace(/0+$/, '').replace(/\.$/, '')} %`;
    case 'price_requests_per_day': return `${value} a day`;
    case 'price_valid_days': return `${value} days`;
    default: return String(value);
  }
}

function ladderText(rows) {
  if (!rows.length) return '—';
  return rows.map((s) => `Orders ${s.from_order}${s.to_order === null || s.to_order === undefined ? '+' : `–${s.to_order}`}: ${pricingService.levelLabel(s)}`).join(' · ');
}

async function show(req, res) {
  const brand = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!brand) {
    res.status(404).send('Brand not found.');
    return;
  }
  const id = Number(brand.id);
  const rules = await repo.settings(id);
  const company = await brands.companyValues();
  const ladder = await repo.ladder(id);
  const companyLadder = await pricingRepository.ladder();
  const levels = await pricingRepository.levels();
  const ruleRows = Object.entries(brands.RULES).map(([key, [label, min, max, kind]]) => {
    const own = rules[key] !== undefined && rules[key] !== '' ? rules[key] : null;
    return { key, label, min, max, kind, own, ownText: own !== null ? showValue(key, own) : null, companyText: showValue(key, company[key]) };
  });
  const ladderRows = ladder.map((s) => ({ from_order: String(s.from_order), to_order: s.to_order === null ? '' : String(s.to_order), level_id: String(s.level_id) }));
  for (let i = 0; i < 2; i += 1) ladderRows.push({ from_order: '', to_order: '', level_id: '' });
  res.renderView('brands/show', {
    brand, b: brand, ruleRows, ladder, ladderRows,
    ladderText: ladder.length ? ladderText(ladder) : 'the company ladder',
    companyLadderText: ladderText(companyLadder),
    levels: levels.map((l) => ({ id: String(l.id), label: `${l.name} — ${pricingService.levelLabel(l)}` })),
    canEdit: can(req, 'manage_divisions'),
    canRules: await associateRules.canManage(req.user),
    statuses: brands.STATUSES, defaultTagline: brands.DEFAULT_TAGLINE,
    priceRules: associateRules.PRICE_RULES, whoChanges: associateRules.WHO_CHANGES,
  }, 'layout/base');
}

/** [details, errors] */
async function readDetails(req, id) {
  const p = body(req);
  const str = (v) => (typeof v === 'string' ? v : '');
  const d = {
    name: str(p.name).trim(),
    tagline: str(p.tagline).trim(),
    code: str(p.code).trim().toLowerCase(),
    products: str(p.products).trim() || null,
    website: str(p.website).trim() || null,
    email: str(p.email).trim().toLowerCase() || null,
    status: str(p.status) || 'active',
  };
  const errors = [];
  if (len(d.name) < 2 || len(d.name) > 80) errors.push('Enter the brand name (2 to 80 characters).');
  else if (await repo.nameTaken(d.name, id)) errors.push('Another brand already has this name.');
  if (d.tagline === '') d.tagline = brands.DEFAULT_TAGLINE;
  else if (len(d.tagline) > 200) errors.push('The line under the name can be at most 200 characters.');
  if (!brands.validCode(d.code)) errors.push('Enter the login address code: 2 to 30 lower-case letters, digits or hyphens (e.g. stone).');
  else if (await repo.codeTaken(d.code, id)) errors.push('Another brand already uses this login address code.');
  if (d.products !== null && len(d.products) > 200) errors.push('"Products it covers" can be at most 200 characters.');
  if (d.website !== null && (len(d.website) > 190 || !/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(\/\S*)?$/i.test(d.website))) errors.push('Enter the website as an address, e.g. www.example.com.');
  if (d.email !== null && (len(d.email) > 190 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email))) errors.push('Enter a valid e-mail address, e.g. info@email.com.');
  if (!Object.prototype.hasOwnProperty.call(brands.STATUSES, d.status)) errors.push('Choose the status.');
  return [d, errors];
}

async function create(req, res) {
  const [d, errors] = await readDetails(req, null);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect('/admin/brands');
    return;
  }
  const id = await repo.save(null, d, req.user.id);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_BRAND_CREATED', 'associate_brands', id, 'name', null, d.name);
  flash.set(req, 'success', `Brand ${d.name} added. Its rules follow the company settings until you change them.`);
  res.redirect(`/admin/brands/${id}`);
}

async function update(req, res) {
  const brand = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!brand) {
    flash.set(req, 'error', 'That brand no longer exists.');
    res.redirect('/admin/brands');
    return;
  }
  const id = Number(brand.id);
  const [d, errors] = await readDetails(req, id);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(`/admin/brands/${id}`);
    return;
  }
  const changed = [];
  for (const [k, v] of Object.entries(d)) {
    const before = brand[k] === null || brand[k] === undefined ? '' : String(brand[k]);
    const after = v === null || v === undefined ? '' : String(v);
    if (before !== after) {
      changed.push(k);
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_BRAND_UPDATED', 'associate_brands', id, k, before, after);
    }
  }
  if (changed.length) await repo.save(id, d, req.user.id);
  flash.set(req, 'success', changed.length ? 'Brand saved.' : 'Nothing changed.');
  res.redirect(`/admin/brands/${id}`);
}

async function saveRules(req, res) {
  const brand = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!brand) {
    flash.set(req, 'error', 'That brand no longer exists.');
    res.redirect('/admin/brands');
    return;
  }
  const id = Number(brand.id);
  const action = String(body(req).action || 'save');
  const reason = String(body(req).reason || '').trim();
  if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
    res.redirect(`/admin/brands/${id}#rules`);
    return;
  }
  const before = await repo.settings(id);
  let values;
  if (action === 'copy_all') {
    values = await brands.companyValues();
    if (values.price_rule !== 'fixed') values.price_fixed_pct = '';
  } else if (action === 'clear_all') {
    values = {};
    for (const k of Object.keys(brands.RULES)) values[k] = null;
  } else {
    const [v, errors] = brands.validateRules(body(req));
    if (errors.length) {
      flash.set(req, 'error', errors.join(' '));
      res.redirect(`/admin/brands/${id}#rules`);
      return;
    }
    values = v;
  }
  await repo.saveSettings(id, values, req.user.id);
  const after = await repo.settings(id);
  for (const k of Object.keys(brands.RULES)) {
    const b = before[k] ?? '';
    const a = after[k] ?? '';
    if (b !== a) {
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_BRAND_RULE_CHANGED', 'associate_brands', id, k, b === '' ? '(company setting)' : b, a === '' ? '(company setting)' : a, reason);
    }
  }
  brands.reset();
  flash.set(req, 'success', {
    copy_all: 'Company settings copied to this brand. Change only what this brand needs. Applies to new orders.',
    clear_all: 'All rules of this brand now follow the company settings.',
  }[action] || 'Brand rules saved. They apply to new orders; an order keeps the rules it had at its Proforma Invoice.');
  res.redirect(`/admin/brands/${id}#rules`);
}

async function saveLadder(req, res) {
  const brand = await repo.find(parseInt(req.params.id, 10) || 0);
  if (!brand) {
    flash.set(req, 'error', 'That brand no longer exists.');
    res.redirect('/admin/brands');
    return;
  }
  const id = Number(brand.id);
  const reason = String(body(req).reason || '').trim();
  if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
    res.redirect(`/admin/brands/${id}#ladder`);
    return;
  }
  const levelsById = {};
  for (const l of await pricingRepository.levels()) levelsById[Number(l.id)] = l;
  const clear = String(body(req).action || '') === 'clear';
  let steps = [];
  if (!clear) {
    const [s, errors] = pricingService.readLadderForm(body(req), levelsById);
    if (errors.length) {
      flash.set(req, 'error', errors.join(' '));
      res.redirect(`/admin/brands/${id}#ladder`);
      return;
    }
    steps = s;
  }
  const describe = (rows) => (rows.length === 0 ? '(company ladder)' : rows.map((s) => {
    const from = s.from ?? s.from_order;
    const to = s.to !== undefined ? s.to : s.to_order;
    return `${from}–${to === null || to === undefined ? 'onward' : to}: ${(levelsById[Number(s.level_id)] || {}).name || '?'}`;
  }).join('; '));
  const before = describe(await repo.ladder(id));
  const after = describe(steps);
  if (before !== after) {
    await repo.replaceLadder(id, steps, req.user.id);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_BRAND_LADDER_CHANGED', 'associate_brands', id, 'ladder', before, after, reason);
  }
  flash.set(req, 'success', before === after ? 'Nothing changed.' : (clear ? 'This brand now uses the company ladder.' : 'Brand ladder saved. It applies to new Proforma Invoices.'));
  res.redirect(`/admin/brands/${id}#ladder`);
}

module.exports = { index, show, create, update, saveRules, saveLadder, showValue, ladderText };
