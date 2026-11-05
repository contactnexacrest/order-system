'use strict';

const flash = require('../helpers/flash');
const rateLimiter = require('../helpers/rateLimiter');
const associateRegistrationRepository = require('../repositories/associateRegistrationRepository');
const associateRepository = require('../repositories/associateRepository');
const associateEventRepository = require('../repositories/associateEventRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const associateService = require('../services/associateService');
const associatePortalService = require('../services/associatePortalService');
const emailService = require('../services/emailService');

/**
 * Port of AssociateRegistrationController.php. Owner decision D-253 (4 Nov 2026, A1):
 * associate self-registration. The MD (manage associates) creates a one-use link
 * valid N days (setting, default 7); the prospect fills a short form; staff accept
 * (the associate is created as Invited and gets the portal login link) or reject
 * with a reason. After the first login the associate completes the profile and the
 * KYC uploads. Expired or used links show a friendly page.
 */
const len = (s) => [...String(s ?? '')].length;

function linkUrl(raw) {
  return `${associateService.appUrl()}/associate-register/${raw}`;
}

// ------------------------------------------------------------ staff

async function index(req, res) {
  const status = String(req.query.status || 'pending');
  const newLink = req.session._associate_registration_link || null;
  delete req.session._associate_registration_link;
  res.renderView('associates/registrations', {
    status,
    registrations: await associateRegistrationRepository.all(status === 'all' ? null : status),
    links: await associateRegistrationRepository.links(),
    linkDays: await associateRegistrationRepository.linkDays(),
    statuses: associateRegistrationRepository.STATUSES,
    newLink,
  }, 'layout/base');
}

async function createLink(req, res) {
  const note = String((req.body || {}).note || '').trim();
  if (len(note) > 200) {
    flash.set(req, 'error', 'The note can be up to 200 characters.');
  } else {
    const raw = await associateRegistrationRepository.createLink(note !== '' ? note : null, req.user.id);
    req.session._associate_registration_link = linkUrl(raw);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_REGISTRATION_LINK_CREATED', 'associate_registration_links', 0, 'note', null, note);
    flash.set(req, 'success', `Associate registration link created — valid ${await associateRegistrationRepository.linkDays()} days, for one registration. Copy it below.`);
  }
  res.redirect('/associates/registrations');
}

async function cancelLink(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  await associateRegistrationRepository.cancelLink(id);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_REGISTRATION_LINK_CANCELLED', 'associate_registration_links', id);
  flash.set(req, 'success', 'Link cancelled.');
  res.redirect('/associates/registrations');
}

async function saveSettings(req, res) {
  const days = String((req.body || {}).link_days || '');
  if (!/^\d+$/.test(days) || Number(days) < 1 || Number(days) > 90) {
    flash.set(req, 'error', 'Links can be valid for 1 to 90 days.');
  } else {
    const old = await associateRegistrationRepository.linkDays();
    await incentiveRepository.setSetting('associate_registration_link_days', String(Number(days)), req.user.id);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_REGISTRATION_SETTING_CHANGED', 'incentive_settings', 0, 'associate_registration_link_days', String(old), String(Number(days)));
    flash.set(req, 'success', `New links are valid for ${days} days.`);
  }
  res.redirect('/associates/registrations');
}

async function accept(req, res) {
  const r = await associateRegistrationRepository.find(parseInt(req.params.id, 10) || 0);
  const userId = req.user.id;
  if (!r || r.status !== 'pending') {
    flash.set(req, 'error', 'This registration was already reviewed.');
    res.redirect('/associates/registrations');
    return;
  }
  if (await associateRepository.findByEmail(String(r.email))) {
    flash.set(req, 'error', `An associate with the e-mail ${r.email} already exists — reject this registration or contact them.`);
    res.redirect('/associates/registrations');
    return;
  }
  const id = await associateRepository.create({
    name: r.name, based_in: r.based_in, country: r.country, currency_code: r.based_in === 'india' ? 'INR' : 'USD',
    email: r.email, phone: r.phone, status: 'invited', notes: 'Registered themselves through an associate registration link (D-253).',
  }, userId);
  await associateRepository.setFields(id, { city: r.city });
  if (!await associateRegistrationRepository.decide(Number(r.id), 'accepted', null, userId, id)) {
    flash.set(req, 'error', 'This registration was already reviewed.');
    res.redirect('/associates/registrations');
    return;
  }
  await auditLogRepository.log(userId, 'ASSOCIATE_REGISTRATION_ACCEPTED', 'associate_registrations', Number(r.id), 'associate_id', null, String(id));
  await associateEventRepository.add(id, 'registered', `Registered through an associate registration link; accepted by ${req.user.name || 'NexaCrest'}.`, null, null, null, userId, false);
  try {
    const link = await associateService.invitePortal(id, userId);
    flash.set(req, 'success', `${r.name} is now an associate (Invited). The portal login link was e-mailed to ${r.email}.${associatePortalService.showCodeOnScreen() ? ` Development only — link: ${link}` : ''}`);
  } catch (e) {
    flash.set(req, 'error', `Associate created, but the portal login link could not be sent: ${e.message}`);
  }
  res.redirect(`/associates/${id}`);
}

async function reject(req, res) {
  const r = await associateRegistrationRepository.find(parseInt(req.params.id, 10) || 0);
  const reason = String((req.body || {}).reason || '').trim();
  if (!r || r.status !== 'pending') {
    flash.set(req, 'error', 'This registration was already reviewed.');
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason (10 to 300 characters) — it is e-mailed to the person.');
  } else if (await associateRegistrationRepository.decide(Number(r.id), 'rejected', reason, req.user.id, null)) {
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_REGISTRATION_REJECTED', 'associate_registrations', Number(r.id), 'reason', null, reason);
    await emailService.sendPlainText(String(r.email), 'Your NexaCrest associate registration',
      `Hello ${r.name},\n\nThank you for registering as a NexaCrest associate. We cannot accept the registration at this time:\n${reason}\n\nNexaCrest International Private Limited`);
    flash.set(req, 'success', 'Registration rejected — the reason was e-mailed.');
  }
  res.redirect('/associates/registrations');
}

// ------------------------------------------------------------ public (no login)

async function show(req, res) {
  const link = await associateRegistrationRepository.usableLink(String(req.params.token || ''));
  if (!link) {
    res.renderView('associate_register/unavailable', {}, 'layout/bare');
    return;
  }
  res.renderView('associate_register/form', { token: String(req.params.token), old: {}, errors: {} }, 'layout/bare');
}

/** [data, errors by field] */
function validate(input) {
  const d = {};
  for (const f of ['name', 'email', 'phone', 'country', 'based_in', 'city']) d[f] = String((input || {})[f] ?? '').trim();
  const e = {};
  if (len(d.name) < 2 || len(d.name) > 150) e.name = 'Enter your full name (2 to 150 characters).';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email) || len(d.email) > 190) e.email = 'Enter a valid e-mail address — it becomes your portal login.';
  if (!/^[0-9 +()\-]{6,40}$/.test(d.phone)) e.phone = 'Enter a phone number with the country code, e.g. +91 98765 43210.';
  if (len(d.country) < 2 || len(d.country) > 80) e.country = 'Enter your country.';
  if (!['india', 'abroad'].includes(d.based_in)) e.based_in = 'Choose whether you are based in India or abroad.';
  if (len(d.city) < 2 || len(d.city) > 100) e.city = 'Enter your city.';
  if (String((input || {}).consent || '') !== '1') e.consent = 'Tick the box to agree that NexaCrest may use these details to review your registration.';
  return [d, e];
}

async function submit(req, res) {
  const token = String(req.params.token || '');
  const link = await associateRegistrationRepository.usableLink(token);
  if (!link) {
    res.renderView('associate_register/unavailable', {}, 'layout/bare');
    return;
  }
  if (await rateLimiter.tooManyRequests('associate_registration_submit', req.ip || '', 5, 15)) {
    res.status(429);
    res.renderView('associate_register/form', { token, old: req.body || {}, errors: { form: 'Too many attempts from this connection — please try again in 15 minutes.' } }, 'layout/bare');
    return;
  }
  const [d, errors] = validate(req.body);
  if (!Object.keys(errors).length && await associateRepository.findByEmail(d.email)) {
    errors.email = 'This e-mail is already registered with NexaCrest — please contact your NexaCrest contact.';
  }
  if (Object.keys(errors).length) {
    res.status(422);
    res.renderView('associate_register/form', { token, old: { ...d, consent: String((req.body || {}).consent || '') }, errors }, 'layout/bare');
    return;
  }
  if (!await associateRegistrationRepository.claimLink(Number(link.id))) {
    res.renderView('associate_register/unavailable', {}, 'layout/bare');
    return;
  }
  const id = await associateRegistrationRepository.create(Number(link.id), d, req.ip || null);
  await auditLogRepository.log(null, 'ASSOCIATE_REGISTRATION_SUBMITTED', 'associate_registrations', id, 'email', null, d.email);
  for (const userId of await permissionRepository.usersWithPermission('manage_associates')) {
    await notificationRepository.createInAppOnly(Number(userId), 'associate_registration', null, [...`New associate registration from ${d.name} (${d.city}, ${d.country}) — review it under Associates › Registrations.`].slice(0, 500).join(''));
  }
  res.renderView('associate_register/done', { name: d.name }, 'layout/bare');
}

module.exports = { linkUrl, index, createLink, cancelLink, saveSettings, accept, reject, show, validate, submit };
