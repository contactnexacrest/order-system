'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const repo = require('../repositories/domesticOrderRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');
const D = require('../services/domesticOrderService');
const G = require('../services/gstCalculator');

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): Admin › GST rates, for Domestic
 * Trade sales — a rate per HSN (the longest matching start of a line's HSN
 * wins) or per product category; anything else takes DOM's default rate.
 * Opened from Admin › Divisions › Domestic Trade (manage_divisions). The
 * starting values are standard rates, to be confirmed by the CA.
 * Port of PHP GstRateController.
 */

const RATES = D.GST_RATES;

async function index(req, res) {
  res.renderView('gst_rates/index', {
    rates: await repo.gstRates(),
    categories: await productCategoryRepository.all(),
    defaultRate: await D.setting('dom_default_gst_rate'),
    rateOptions: RATES.map((r) => ({ value: r, label: `${r.replace(/0+$/, '').replace(/\.$/, '')}%` })),
  });
}

async function store(req, res) {
  const hsn = String(req.body.hsn_prefix || '').replace(/\D/g, '');
  const category = req.body.category_id ? parseInt(req.body.category_id, 10) : null;
  let rate = String(req.body.rate_pct || '').trim();
  rate = rate !== '' ? G.money(G.scaled(rate, 2)) : '';
  const note = [...String(req.body.note || '').trim()].slice(0, 255).join('');
  let error = null;
  if ((hsn === '') === (category === null)) error = 'Give either an HSN (2 to 8 digits) or a product category, not both.';
  else if (hsn !== '' && (hsn.length < 2 || hsn.length > 8)) error = 'An HSN has 2 to 8 digits.';
  else if (!RATES.includes(rate)) error = 'Choose a GST rate.';
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect('/admin/gst-rates');
    return;
  }
  const id = await repo.addGstRate(hsn !== '' ? hsn : null, category, rate, note !== '' ? note : null, req.user.id);
  await auditLogRepository.log(req.user.id, 'GST_RATE_ADDED', 'gst_rates', id, 'rate_pct', null, rate);
  flash.set(req, 'success', 'GST rate added.');
  res.redirect('/admin/gst-rates');
}

async function toggle(req, res) {
  const active = String(req.body.active || '') === '1';
  const id = parseInt(req.params.id, 10);
  await repo.setGstRateActive(id, active, req.user.id);
  await auditLogRepository.log(req.user.id, `GST_RATE_${active ? 'ON' : 'OFF'}`, 'gst_rates', id, 'is_active', null, active ? '1' : '0');
  flash.set(req, 'success', active ? 'GST rate in use again.' : 'GST rate no longer used.');
  res.redirect('/admin/gst-rates');
}

module.exports = { RATES, index, store, toggle };
