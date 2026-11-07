'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const sopAccess = require('../services/sopAccessService');

/**
 * Owner decision D-266 (7 Nov 2026): Admin › SOP access — the SOP library
 * switch, each chapter's "on for everyone" switch, the chapters each role
 * sees, and a person's extra chapters (from Users › Edit). Every route needs
 * manage_company_settings (seeded: Admin, MD, ED, System Administrator).
 * Port of App\Controllers\SopAccessController.
 */

const SHOW = { all: 'All chapters', ticked: 'Ticked for this role', new: 'New (not assigned yet)', off: 'Switched off' };

async function roles() {
  const rows = await db.query(
    `SELECT r.id, r.name,
            EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.role_id = r.id AND rp.is_enabled = 1 AND p.permission_key = 'manage_company_settings') AS sees_all
     FROM roles r ORDER BY r.name`
  );
  return rows.map((r) => ({ id: Number(r.id), name: String(r.name), sees_all: Number(r.sees_all) === 1 }));
}

async function index(req, res) {
  const allRoles = await roles();
  const roleIds = allRoles.map((r) => r.id);
  let roleId = Number(req.query.role_id || 0);
  roleId = roleIds.includes(roleId) ? roleId : null;
  let copyFrom = Number(req.query.copy_from || 0);
  copyFrom = roleIds.includes(copyFrom) ? copyFrom : null;
  const show = Object.prototype.hasOwnProperty.call(SHOW, String(req.query.show || '')) ? String(req.query.show) : 'all';

  const states = await sopAccess.chapterStates();
  const ticked = new Set(roleId !== null ? await sopAccess.roleSlugs(copyFrom !== null ? copyFrom : roleId) : []);
  const rows = [];
  const hiddenTicked = []; // ticked chapters the "Show" filter leaves out — kept, so Save never drops them
  for (const [slug, title] of Object.entries(sopAccess.titles())) {
    const state = slug === sopAccess.ALWAYS_VISIBLE ? 'always' : (!Object.prototype.hasOwnProperty.call(states, slug) ? 'new' : (states[slug] ? 'on' : 'off'));
    const isTicked = ticked.has(slug) || slug === sopAccess.ALWAYS_VISIBLE;
    if ((show === 'ticked' && !isTicked) || (show === 'new' && state !== 'new') || (show === 'off' && state !== 'off')) {
      if (isTicked && state !== 'always') hiddenTicked.push(slug);
      continue;
    }
    rows.push({ slug, title, state, ticked: isTicked, ca: slug.startsWith('ca-') });
  }
  res.renderView('sop_access/index', {
    libraryOn: await sopAccess.libraryEnabled(),
    roles: allRoles,
    role: allRoles.find((r) => r.id === roleId) || null,
    copyFrom,
    show,
    showOptions: SHOW,
    rows,
    hiddenTicked,
  }, 'layout/base');
}

function back(req) {
  const roleId = Number((req.body || {}).role_id || 0);
  return roleId > 0 ? `?role_id=${roleId}` : '';
}

async function saveLibrary(req, res) {
  const on = (req.body || {}).library === 'on';
  await sopAccess.setLibraryEnabled(on, req.user.id);
  flash.set(req, 'success', on ? 'The SOP library is on for staff.' : 'The SOP library is off. Only MD and Admin see it now.');
  res.redirect(`/admin/sop-access${back(req)}`);
}

async function saveChapter(req, res) {
  const [slug, on = ''] = String((req.body || {}).toggle || '').split(':', 2);
  try {
    await sopAccess.setChapterEnabled(slug, on === '1', req.user.id);
    flash.set(req, 'success', on === '1' ? `Chapter ${slug} is on for everyone ticked.` : `Chapter ${slug} is switched off for everyone except MD and Admin.`);
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/admin/sop-access${back(req)}`);
}

async function saveRole(req, res) {
  const roleId = Number((req.body || {}).role_id || 0);
  const role = (await roles()).find((r) => r.id === roleId);
  if (!role) {
    flash.set(req, 'error', 'Choose a role.');
    res.redirect('/admin/sop-access');
    return;
  }
  let slugs = (req.body || {})['slugs[]'] !== undefined ? req.body['slugs[]'] : (req.body || {}).slugs;
  if (slugs === undefined) slugs = [];
  if (!Array.isArray(slugs)) slugs = [slugs];
  await sopAccess.saveRoleSlugs(roleId, slugs.map(String), req.user.id);
  flash.set(req, 'success', `Saved the SOP chapters for ${role.name}.`);
  res.redirect(`/admin/sop-access?role_id=${roleId}`);
}

async function addUserExtra(req, res) {
  const userId = Number(req.params.id);
  try {
    await sopAccess.addUserExtra(userId, String((req.body || {}).slug || ''), req.user.id);
    flash.set(req, 'success', 'Extra SOP chapter added.');
  } catch (e) {
    flash.set(req, 'error', 'Choose a chapter.');
  }
  res.redirect(`/users/${userId}/edit#sop-extras`);
}

async function removeUserExtra(req, res) {
  const userId = Number(req.params.id);
  await sopAccess.removeUserExtra(userId, String((req.body || {}).slug || ''), req.user.id);
  flash.set(req, 'success', 'Extra SOP chapter removed.');
  res.redirect(`/users/${userId}/edit#sop-extras`);
}

/** Users › Edit: loads the "Extra SOP chapters" card (only for people who manage SOP access), then hands on. */
async function loadUserExtrasCard(req, res, next) {
  if (await sopAccess.isPrivileged(req.user.id, req.permissions)) {
    const userId = Number(req.params.id);
    const extras = await sopAccess.userExtras(userId);
    const titles = sopAccess.titles();
    req.sopExtrasCard = {
      userId,
      extras: extras.map((slug) => ({ slug, title: titles[slug] || slug })),
      options: Object.entries(titles)
        .filter(([slug]) => !extras.includes(slug) && slug !== sopAccess.ALWAYS_VISIBLE)
        .map(([slug, title]) => ({ slug, title })),
    };
  }
  next();
}

module.exports = { index, saveLibrary, saveChapter, saveRole, addUserExtra, removeUserExtra, loadUserExtrasCard };
