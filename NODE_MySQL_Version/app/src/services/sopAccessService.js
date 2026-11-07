'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const departmentRepository = require('../repositories/departmentRepository');
const superAdminService = require('./superAdminService');

/**
 * Owner decision D-266 (7 Nov 2026): who sees which SOP chapter. Port of
 * App\Services\SopAccessService — same rules, same wording.
 *
 * - "SOP library" on/off (company setting sop_library_enabled): off hides
 *   the SOP menu, every chapter, its screenshots and the Gallery from staff,
 *   except the people who always see everything.
 * - Each chapter has an "on for everyone" switch (sop_chapters.is_enabled).
 * - Each role has its ticked chapters (sop_role_chapters); a person sees the
 *   chapters of their own role and of every role they hold through a current
 *   department membership, plus their own extras (sop_user_chapters).
 * - Always: an effective Super Admin and whoever holds manage_company_settings
 *   (seeded: Admin, MD, ED, System Administrator) see every chapter;
 *   00-getting-started is visible to every staff member; a ca- chapter still
 *   also needs ca_module_view.
 * - A chapter file with no sop_chapters row is "New": only the people above
 *   see it until it is ticked for a role or a person, or switched.
 */

const ALWAYS_VISIBLE = '00-getting-started';
const SETTING = 'sop_library_enabled';
const SETTING_CATEGORY = 'sop_access';
const CHAPTER_NOT_AVAILABLE = "This SOP chapter isn't available for your role. Ask your Admin to add it if you need it.";
const LIBRARY_OFF = 'The SOP library is switched off. Ask your Admin if you need it.';
const DOCS_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'SOP');

/** @returns {string[]} every chapter file present now (README excluded), in filename order */
function allSlugs() {
  return fs.readdirSync(DOCS_DIR)
    .filter((f) => f.endsWith('.md') && f !== 'README.md')
    .sort()
    .map((f) => f.slice(0, -3));
}

async function libraryEnabled() {
  return (await companySettingsRepository.get(SETTING)) !== '0';
}

/** Effective Super Admin, or manage_company_settings: sees every chapter and manages SOP access. */
async function isPrivileged(userId, permissions) {
  return !!(permissions || {}).manage_company_settings || (await superAdminService.isEffective(userId));
}

/** @returns {Promise<Object<string, boolean>>} slug => on for everyone (configured chapters only) */
async function chapterStates() {
  const out = {};
  for (const r of await db.query('SELECT slug, is_enabled FROM sop_chapters')) out[String(r.slug)] = Number(r.is_enabled) === 1;
  return out;
}

/** @returns {Promise<number[]>} the person's own role plus every role of a current department membership */
async function roleIdsFor(userId, roleId) {
  const ids = (await departmentRepository.activeRoleIdsForUser(userId)).map(Number);
  if (roleId !== null && roleId !== undefined) ids.push(Number(roleId));
  return [...new Set(ids)];
}

async function roleSlugs(roleId) {
  return (await db.query('SELECT slug FROM sop_role_chapters WHERE role_id = :r ORDER BY slug', { r: roleId })).map((r) => String(r.slug));
}

async function userExtras(userId) {
  return (await db.query('SELECT slug FROM sop_user_chapters WHERE user_id = :u ORDER BY slug', { u: userId })).map((r) => String(r.slug));
}

/** @returns {Promise<string[]>} the chapters this person may open, in filename order */
async function visibleSlugs(userId, roleId, permissions) {
  const all = allSlugs();
  const canViewCa = !!(permissions || {}).ca_module_view;
  const caOk = (s) => !s.startsWith('ca-') || canViewCa;
  if (await isPrivileged(userId, permissions)) return all.filter(caOk);
  if (!(await libraryEnabled())) return all.includes(ALWAYS_VISIBLE) ? [ALWAYS_VISIBLE] : [];
  const states = await chapterStates();
  let ticked = await userExtras(userId);
  for (const rid of await roleIdsFor(userId, roleId)) ticked = ticked.concat(await roleSlugs(rid));
  const tickedSet = new Set(ticked);
  return all.filter((slug) => slug === ALWAYS_VISIBLE || (tickedSet.has(slug) && states[slug] === true && caOk(slug)));
}

/** May this person see the SOP menu, the SOP home page and the Gallery? */
async function libraryVisibleTo(userId, permissions) {
  return (await libraryEnabled()) || (await isPrivileged(userId, permissions));
}

/** A link to a chapter this person cannot open becomes plain text. */
function filterChapterLinks(html, visible) {
  const allowed = new Set(visible);
  return String(html).replace(/<a\s[^>]*href="\/sop\/([A-Za-z0-9_-]+)(?:#[^"]*)?"[^>]*>([\s\S]*?)<\/a>/g, (whole, slug, text) => (allowed.has(slug) ? whole : text));
}

/** May this person load this SOP screenshot? Only when a chapter they may open (or the README) shows it. */
async function imageVisible(filename, userId, permissions, visible) {
  if (await isPrivileged(userId, permissions)) return true;
  let slugs = visible.slice();
  if (!(await libraryEnabled())) slugs = slugs.filter((s) => s === ALWAYS_VISIBLE);
  else slugs.push('README');
  const needle = `./images/${filename}`;
  for (const slug of slugs) {
    let raw = null;
    try { raw = fs.readFileSync(path.join(DOCS_DIR, `${slug}.md`), 'utf8'); } catch (e) { raw = null; }
    if (raw !== null && raw.includes(needle)) return true;
  }
  return false;
}

/** @returns {Object<string, string>} slug => title (the chapter's first "# " heading) */
function titles() {
  const out = {};
  for (const slug of allSlugs()) {
    let title = slug;
    const raw = fs.readFileSync(path.join(DOCS_DIR, `${slug}.md`), 'utf8');
    for (const line of raw.split(/\r\n|\r|\n/)) {
      if (line.trim().startsWith('# ')) { title = line.trim().slice(2).trim(); break; }
    }
    out[slug] = title;
  }
  return out;
}

// ---------------------------------------------------------------- Admin changes (all audited)

async function ensureConfigured(slug, byUserId, executor = db) {
  await executor.execute('INSERT IGNORE INTO sop_chapters (slug, is_enabled, updated_by) VALUES (:s, 1, :u)', { s: slug, u: byUserId });
}

async function setLibraryEnabled(on, byUserId) {
  const old = await libraryEnabled();
  if (old === on) return;
  await companySettingsRepository.set(SETTING, on ? '1' : '0', byUserId);
  await auditLogRepository.log(byUserId, 'SOP_LIBRARY_SWITCHED', 'company_settings', null, SETTING, old ? 'on' : 'off', on ? 'on' : 'off');
}

async function setChapterEnabled(slug, on, byUserId) {
  if (!allSlugs().includes(slug) || slug === ALWAYS_VISIBLE) throw new Error('Unknown SOP chapter.');
  const states = await chapterStates();
  const old = Object.prototype.hasOwnProperty.call(states, slug) ? states[slug] : null;
  await ensureConfigured(slug, byUserId);
  await db.execute('UPDATE sop_chapters SET is_enabled = :e, updated_by = :u WHERE slug = :s', { e: on ? 1 : 0, u: byUserId, s: slug });
  if (old !== on) {
    await auditLogRepository.log(byUserId, 'SOP_CHAPTER_SWITCHED', 'sop_chapters', null, slug, old === null ? 'new' : (old ? 'on' : 'off'), on ? 'on' : 'off');
  }
}

async function saveRoleSlugs(roleId, slugs, byUserId) {
  const known = new Set(allSlugs());
  const clean = [...new Set((slugs || []).filter((s) => typeof s === 'string' && known.has(s)))].sort();
  const old = await roleSlugs(roleId);
  await db.transaction(async (tx) => {
    await tx.execute('DELETE FROM sop_role_chapters WHERE role_id = :r', { r: roleId });
    for (const s of clean) {
      await tx.execute('INSERT INTO sop_role_chapters (role_id, slug) VALUES (:r, :s)', { r: roleId, s });
      await ensureConfigured(s, byUserId, tx);
    }
  });
  if (old.join(', ') !== clean.join(', ')) {
    await auditLogRepository.log(byUserId, 'SOP_ROLE_CHAPTERS_SAVED', 'roles', roleId, 'sop_chapters', old.join(', '), clean.join(', '));
  }
}

async function addUserExtra(userId, slug, byUserId) {
  if (!allSlugs().includes(slug)) throw new Error('Unknown SOP chapter.');
  const result = await db.execute('INSERT IGNORE INTO sop_user_chapters (user_id, slug, added_by) VALUES (:u, :s, :b)', { u: userId, s: slug, b: byUserId });
  await ensureConfigured(slug, byUserId);
  if (result.affectedRows > 0) await auditLogRepository.log(byUserId, 'SOP_USER_CHAPTER_ADDED', 'users', userId, 'sop_chapters', null, slug);
}

async function removeUserExtra(userId, slug, byUserId) {
  const result = await db.execute('DELETE FROM sop_user_chapters WHERE user_id = :u AND slug = :s', { u: userId, s: slug });
  if (result.affectedRows > 0) await auditLogRepository.log(byUserId, 'SOP_USER_CHAPTER_REMOVED', 'users', userId, 'sop_chapters', slug, null);
}

module.exports = {
  ALWAYS_VISIBLE, SETTING, SETTING_CATEGORY, CHAPTER_NOT_AVAILABLE, LIBRARY_OFF,
  allSlugs, libraryEnabled, isPrivileged, chapterStates, roleIdsFor, roleSlugs, userExtras, visibleSlugs,
  libraryVisibleTo, filterChapterLinks, imageVisible, titles,
  setLibraryEnabled, setChapterEnabled, saveRoleSlugs, addUserExtra, removeUserExtra,
};
