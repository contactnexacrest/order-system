'use strict';

const fs = require('fs');
const flash = require('../helpers/flash');
const agreementRepository = require('../repositories/agreementRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const lookupRepository = require('../repositories/lookupRepository');
const fileUploadService = require('../services/fileUploadService');
const uiTemplateService = require('../services/uiTemplateService');
const { sendFile } = require('./associateAdminController');
const { todayIst } = require('../helpers/dbDateTime');

/**
 * Port of AgreementController.php. Agreements register (owner decision 2 Nov 2026, point 4):
 * Standard list and Kanban board by status with the same filters, links and actions; add / edit /
 * renew / terminate; signed copy. Staff agreements are seen only by holders of manage_agreements.
 */

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const len = (v) => [...String(v ?? '')].length;

function can(req, key) {
  return !!(req.user && req.permissions && req.permissions[key]);
}

function isRealDate(v) {
  if (!DATE_RE.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v;
}

function filters(query) {
  const status = String(query.status || 'all');
  const party = String(query.party || '');
  const within = String(query.within || '');
  return {
    status: status === 'all' || Object.prototype.hasOwnProperty.call(agreementRepository.STATUSES, status) ? status : 'all',
    party: Object.prototype.hasOwnProperty.call(agreementRepository.PARTY_TYPES, party) ? party : '',
    type: [...String(query.type || '').trim()].slice(0, 100).join(''),
    within: ['7', '30', '60', '90'].includes(within) ? within : '',
    q: [...String(query.q || '').trim()].slice(0, 100).join(''),
  };
}

function applyFilters(rows, f) {
  return rows.filter((r) => {
    if (f.status !== 'all' && r.status !== f.status) return false;
    if (f.party !== '' && r.party_type !== f.party) return false;
    if (f.type !== '' && r.agreement_type !== f.type) return false;
    if (f.within !== '' && (r.days_left === null || r.days_left < 0 || r.days_left > Number(f.within) || ['terminated', 'renewed'].includes(r.status))) return false;
    return f.q === '' || `${r.party_name} ${r.agreement_type} ${r.title || ''} ${r.notes || ''}`.toLowerCase().includes(f.q.toLowerCase());
  });
}

/** Common view data for the rows' links (same on list, board and party card). */
function linkFlags(req) {
  return {
    canManage: can(req, 'manage_agreements'),
    canClientFile: can(req, 'manage_orders'),
    canAssociateFile: can(req, 'manage_associates') && can(req, 'manage_incentives'),
    partyTypes: agreementRepository.PARTY_TYPES,
    agreementStatuses: agreementRepository.STATUSES,
    partyUrl: agreementRepository.partyUrl,
  };
}

/** The "Agreements" card for a party page, or null when this person may not see it. */
async function partyCard(req, type, id) {
  const canManage = can(req, 'manage_agreements');
  if (!(canManage || can(req, 'view_agreements')) || (type === 'staff' && !canManage)) return null;
  return { partyType: type, partyId: Number(id), rows: await agreementRepository.forParty(type, Number(id)), ...linkFlags(req) };
}

/** Agreements expiring within 30 days, for the dashboard (or null). */
async function expiringSoon(req) {
  const canManage = can(req, 'manage_agreements');
  if (!(canManage || can(req, 'view_agreements'))) return null;
  return agreementRepository.visibleTo(await agreementRepository.all(), canManage).filter((r) => r.status === 'expiring');
}

async function index(req, res) {
  const canManage = can(req, 'manage_agreements');
  const all = agreementRepository.visibleTo(await agreementRepository.all(), canManage);
  const f = filters(req.query || {});
  const counts = Object.fromEntries(Object.keys(agreementRepository.STATUSES).map((k) => [k, 0]));
  for (const r of all) counts[r.status]++;
  all.sort((a, b) => {
    const ka = `${a.expires_on || '9999-12-31'}|${a.party_name}`;
    const kb = `${b.expires_on || '9999-12-31'}|${b.party_name}`;
    return ka < kb ? -1 : ka > kb ? 1 : 0;
  });
  const options = (await lookupRepository.dropdownOptions('agreement_type')).map((o) => o.option_value);
  const data = {
    rows: applyFilters(all, f),
    f,
    counts,
    countAll: all.length,
    types: [...new Set([...options, ...all.map((r) => r.agreement_type)])],
    ...linkFlags(req),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
  };
  if (await uiTemplateService.showsBoard(req)) {
    res.renderView('agreements/index_kanban', data, 'layout/base');
    return;
  }
  res.renderView('agreements/index', data, 'layout/base');
}

async function form(req, res, agreement, values, renews) {
  res.renderView('agreements/form', {
    agreement,
    v: values,
    renews,
    parties: await agreementRepository.partyOptions(),
    partyTypes: agreementRepository.PARTY_TYPES,
    types: (await lookupRepository.dropdownOptions('agreement_type')).map((o) => o.option_value),
    partyName: agreement ? await agreementRepository.partyName(agreement.party_type, agreement.party_id) : null,
    today: todayIst(),
  }, 'layout/base');
}

async function create(req, res) {
  let prefill = { party_type: String(req.query.party_type || ''), party_id: Number(req.query.party_id || 0) };
  let renews = null;
  if (req.query.renew) {
    renews = await agreementRepository.find(Number(req.query.renew) || 0);
    if (renews) {
      prefill = { party_type: renews.party_type, party_id: Number(renews.party_id), agreement_type: renews.agreement_type, title: renews.title, starts_on: renews.expires_on };
    }
  }
  await form(req, res, null, prefill, renews);
}

async function edit(req, res) {
  const a = await agreementRepository.find(Number(req.params.id) || 0);
  if (!a) {
    res.status(404).send('Agreement not found.');
    return;
  }
  await form(req, res, a, a, null);
}

async function validate(post, isNew) {
  const clean = (k, max) => {
    const v = String(post[k] ?? '').trim();
    return v === '' ? null : [...v].slice(0, max).join('');
  };
  const data = {
    party_type: String(post.party_type || ''),
    party_id: 0,
    agreement_type: await lookupRepository.sanitizeOption('agreement_type', String(post.agreement_type || '').trim()),
    title: clean('title', 200),
    signed_on: clean('signed_on', 10),
    starts_on: clean('starts_on', 10),
    expires_on: clean('expires_on', 10),
    notes: clean('notes', 2000),
    renews_agreement_id: post.renews_agreement_id && /^\d+$/.test(String(post.renews_agreement_id)) ? Number(post.renews_agreement_id) : null,
  };
  const errors = [];
  if (isNew) {
    if (!Object.prototype.hasOwnProperty.call(agreementRepository.PARTY_TYPES, data.party_type)) {
      errors.push('Choose who the agreement is with (client, supplier, logistics partner, associate or staff member).');
    } else {
      const raw = String(post[`party_id_${data.party_type}`] ?? post.party_id ?? '');
      data.party_id = /^\d+$/.test(raw) ? Number(raw) : 0;
      if (data.party_id <= 0 || (await agreementRepository.partyName(data.party_type, data.party_id)) === null) {
        errors.push(`Choose the ${agreementRepository.PARTY_TYPES[data.party_type].toLowerCase()} from the list.`);
      }
    }
  }
  if (!data.agreement_type) errors.push('Choose the type of agreement.');
  for (const [k, label] of [['signed_on', 'signing date'], ['starts_on', 'start date'], ['expires_on', 'expiry date']]) {
    if (data[k] !== null && !isRealDate(data[k])) {
      errors.push(`Enter the ${label} as a date.`);
      data[k] = null;
    }
  }
  if (data.signed_on === null) {
    errors.push('Enter the date the agreement was signed.');
  } else if (data.signed_on > todayIst()) {
    errors.push('The signing date cannot be in the future.');
  }
  if (data.expires_on !== null && data.expires_on <= (data.starts_on || data.signed_on || '0000-00-00')) {
    errors.push('The agreement must expire after it starts (or after it was signed). Leave the expiry date empty if it never expires.');
  }
  return [data, errors];
}

async function save(req, res) {
  const user = req.user;
  const id = req.params.id ? Number(req.params.id) : null;
  const existing = id !== null ? await agreementRepository.find(id) : null;
  if (id !== null && !existing) {
    res.status(404).send('Agreement not found.');
    return;
  }
  const [data, errors] = await validate(req.body || {}, !existing);
  if (existing) {
    data.party_type = existing.party_type;
    data.party_id = Number(existing.party_id);
  }
  const hasUpload = !!(req.file && req.file.originalname);
  if (!existing && !hasUpload) errors.push('Attach the signed copy of the agreement (PDF, Word or a scan).');
  const backTo = existing ? `/agreements/${id}/edit` : `/agreements/new${data.renews_agreement_id ? `?renew=${data.renews_agreement_id}` : ''}`;
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(backTo);
    return;
  }
  const partyName = await agreementRepository.partyName(data.party_type, data.party_id);
  if (hasUpload) {
    try {
      data.file_id = await fileUploadService.handleUpload(req, 'agreement_file', 'agreement_file', `agreements/${data.party_type}`, data.party_type === 'client' ? data.party_id : null, null, user.id, null, partyName, `Signed agreement — ${data.agreement_type}`);
    } catch (e) {
      flash.setErrorFromException(req, e);
      res.redirect(backTo);
      return;
    }
  }
  if (!existing) {
    const newId = await agreementRepository.create(data, user.id);
    await auditLogRepository.log(user.id, data.renews_agreement_id ? 'AGREEMENT_RENEWED' : 'AGREEMENT_ADDED', 'agreements', newId, null, data.renews_agreement_id ? String(data.renews_agreement_id) : null, `${data.agreement_type} — ${partyName}`);
    flash.set(req, 'success', 'Agreement saved. A reminder goes out 30 and 7 days before it expires, and on the day.');
  } else {
    await agreementRepository.update(id, data);
    const logged = Object.fromEntries(['agreement_type', 'title', 'signed_on', 'starts_on', 'expires_on', 'file_id'].filter((k) => k in data).map((k) => [k, data[k]]));
    await auditLogRepository.log(user.id, 'AGREEMENT_UPDATED', 'agreements', id, null, null, JSON.stringify(logged));
    flash.set(req, 'success', 'Agreement updated.');
  }
  res.redirect(`/agreements?party=${data.party_type}&q=${encodeURIComponent(partyName || '')}`);
}

async function terminate(req, res) {
  const a = await agreementRepository.find(Number(req.params.id) || 0);
  if (!a) {
    res.status(404).send('Agreement not found.');
    return;
  }
  const on = String((req.body || {}).terminated_on || '').trim();
  const reason = String((req.body || {}).terminated_reason || '').trim();
  if (!DATE_RE.test(on) || len(reason) < 5 || len(reason) > 500) {
    flash.set(req, 'error', 'Enter the date the agreement ended and a reason (5 to 500 characters).');
    res.redirect(`/agreements/${a.id}/edit`);
    return;
  }
  await agreementRepository.terminate(Number(a.id), on, reason);
  await auditLogRepository.log(req.user.id, 'AGREEMENT_TERMINATED', 'agreements', Number(a.id), 'terminated_on', null, on, reason);
  flash.set(req, 'success', 'Agreement recorded as ended. No more reminders go out for it.');
  res.redirect('/agreements');
}

async function download(req, res) {
  const a = await agreementRepository.find(Number(req.params.id) || 0);
  if (!a) {
    res.status(404).send('Agreement not found.');
    return;
  }
  if (a.party_type === 'staff' && !can(req, 'manage_agreements')) {
    res.status(403).send('You do not have permission to view this agreement.');
    return;
  }
  const file = a.file_id ? await fileStoreRepository.find(Number(a.file_id)) : null;
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('No signed copy is on file for this agreement.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'AGREEMENT_DOWNLOADED', 'agreements', Number(a.id), null, null, String(file.original_filename));
  sendFile(res, file.server_path, file.original_filename, file.mime_type || 'application/octet-stream');
}

module.exports = { can, filters, applyFilters, validate, partyCard, expiringSoon, index, create, edit, save, terminate, download };
