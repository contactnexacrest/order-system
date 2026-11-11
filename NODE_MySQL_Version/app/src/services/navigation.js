'use strict';

/**
 * Owner decision D-320 (11 Nov 2026, point 26): the short left menu (13
 * entries) and the menu bar at the top of every page of a section, both
 * built from one config, src/config/navigation.json (the same file, byte for
 * byte, as the PHP twin src/Config/navigation.json; App\Services\Navigation
 * holds the same logic).
 *
 * Pure: it is given what the person may do (can), the facts the config asks
 * about (flags), the live counts and the page being shown, and returns plain
 * objects for views/layout/base.njk and views/partials/section_nav.njk. A
 * page joins its section's menu bar by its path alone, so a new page under a
 * section's prefix gets the bar without any change to the page.
 */

const fs = require('fs');
const path = require('path');

let cached = null;

function config() {
  if (cached === null) {
    cached = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'config', 'navigation.json'), 'utf8'));
  }
  return cached;
}

/** Does a path belong under a prefix ("/ca" covers "/ca" and "/ca/zoho-sync", never "/cafin")? */
function under(p, prefix) {
  if (prefix === '/') return p === '/';
  return p === prefix || p.startsWith(`${prefix.replace(/\/+$/, '')}/`);
}

/** The key of the section a page belongs to (longest prefix wins), or null (Dashboard, Notifications, My account …). */
function sectionKeyFor(p) {
  let best = null;
  let bestLen = -1;
  for (const section of config().sections) {
    for (const prefix of section.prefixes || []) {
      if (under(p, prefix) && prefix.length > bestLen) {
        best = section.key;
        bestLen = prefix.length;
      }
    }
  }
  return best;
}

function allowed(when, can, flags) {
  if (!when) return true;
  for (const flag of when.flags || []) {
    if (!flags[flag]) return false;
  }
  for (const perm of when.all || []) {
    if (!can(perm)) return false;
  }
  const any = when.any || [];
  if (any.length === 0) return true;
  return any.some((perm) => can(perm));
}

function visibleItems(items, can, flags, counts) {
  const out = [];
  for (const item of items) {
    if (item.children) {
      const children = visibleItems(item.children, can, flags, counts);
      if (children.length === 0) continue;
      out.push({ label: item.label, children, count: children.reduce((n, c) => n + c.count, 0), active: false });
      continue;
    }
    if (!allowed(item.when || null, can, flags)) continue;
    out.push({
      label: item.label,
      href: item.href,
      target: item.target || null,
      count: item.count ? Number(counts[item.count] || 0) : 0,
      match: item.match || [],
      activeQuery: item.activeQuery || null,
      active: false,
    });
  }
  return out;
}

/** The left-menu link: the first page this person can open that opens in the same tab (else the first at all). */
function firstHref(items) {
  const flat = [];
  for (const item of items) flat.push(...(item.children ? item.children : [item]));
  const sameTab = flat.find((leaf) => !leaf.target);
  return (sameTab || flat[0]).href;
}

/** -1 = does not match; otherwise the longer the matching path, the better (a matching query adds 1000). */
function score(leaf, p, query) {
  const href = leaf.href;
  if (href.includes('#')) return -1;
  const [hrefPath, qs] = href.split('?', 2);
  let best = under(p, hrefPath) ? hrefPath.length : -1;
  for (const extra of leaf.match || []) {
    if (under(p, extra)) best = Math.max(best, extra.length);
  }
  if (best < 0) return -1;
  const want = qs ? Object.fromEntries(new URLSearchParams(qs)) : {};
  for (const [k, v] of Object.entries(want)) {
    if (String(query[k] ?? '') !== String(v)) return -1;
  }
  for (const [k, allowedValues] of Object.entries(leaf.activeQuery || {})) {
    const value = (typeof query[k] === 'string' || typeof query[k] === 'number') ? String(query[k]) : '';
    if (!allowedValues.includes(value)) return -1;
  }
  return best + (Object.keys(want).length > 0 || (leaf.activeQuery && Object.keys(leaf.activeQuery).length > 0) ? 1000 : 0);
}

/** One item is marked as the page shown: the one whose link (or extra prefix) matches the path most closely. */
function markActive(items, p, query) {
  let bestScore = -1;
  let bestAt = null;
  items.forEach((item, i) => {
    (item.children ? item.children : [item]).forEach((leaf, j) => {
      const s = score(leaf, p, query);
      if (s > bestScore) {
        bestScore = s;
        bestAt = [i, item.children ? j : null];
      }
    });
  });
  if (bestAt !== null && bestScore >= 0) {
    const [i, j] = bestAt;
    items[i].active = true;
    if (j !== null) items[i].children[j].active = true;
  }
  return items;
}

/**
 * Everything the layout draws.
 * @param {(perm: string) => boolean} can
 * @param {Object<string, boolean>} flags
 * @param {Object<string, number>} counts
 * @param {string} p the page's path
 * @param {Object} query the page's query string
 */
function build(can, flags, counts, p, query = {}) {
  const cfg = config();
  const currentKey = p === '/' ? 'dashboard' : sectionKeyFor(p);
  const sidebar = [];
  let bar = null;
  let lastGroup = false;
  for (const section of cfg.sections) {
    let items = visibleItems(section.items || [], can, flags, counts);
    let href;
    if ((section.items || []).length > 0) {
      if (items.length === 0) continue; // nothing in this section this person can open
      href = firstHref(items);
    } else {
      if (!allowed(section.when || null, can, flags)) continue;
      href = section.href;
    }
    const group = section.group ?? null;
    let heading = null;
    if (group !== null && group !== lastGroup) heading = cfg.groups[group] || group;
    lastGroup = group;
    const isCurrent = currentKey === section.key;
    sidebar.push({
      key: section.key,
      label: section.label,
      href,
      icon: section.icon || '',
      heading,
      count: section.count ? Number(counts[section.count] || 0) : 0,
      active: isCurrent,
    });
    if (isCurrent && items.length > 0) {
      items = markActive(items, p, query);
      const crossLinks = (section.crossLinks || [])
        .filter((link) => (link.onPaths || []).some((on) => under(p, on)) && allowed(link.when || null, can, flags))
        .map((link) => ({ label: link.label, href: link.href }));
      bar = { key: section.key, label: section.label, items, crossLinks };
    }
  }
  return { sidebar, bar, currentKey };
}

/**
 * The facts and counts for one request, from what services/sessionAuth.js
 * put on req (permissions, divisionUi, the live counts).
 */
function forRequest(req) {
  const perms = req.permissions || {};
  const can = (perm) => !!perms[perm];
  const division = req.divisionUi || { modules: {}, activeCodes: [] };
  const modules = division.modules || {};
  const activeCodes = perms.manage_divisions ? (division.activeCodes || []) : [];
  const flags = {
    superAdmin: !!req.isSuperAdmin,
    sopOpen: req.sopLibraryOpen !== false, // D-266
    approver: !!req.showApprovals, // D-310
    // D-257: a module switched off hides its pages (imports and merchant trade are off unless switched on).
    mod_supplier_purchase: modules.supplier_purchase !== false,
    mod_import_stock: modules.import_stock === true,
    mod_merchant_trade: modules.merchant_trade === true,
    mod_associates: modules.associates !== false,
    mod_team_incentive: modules.team_incentive !== false,
    div_gst: activeCodes.includes('DOM') || activeCodes.includes('IMP'), // QA UI-12
    div_import_duty: activeCodes.includes('IMP'),
  };
  const tabs = req.approvalTabCounts || {};
  const counts = {
    approvals: req.approvalsCount || 0,
    approvals_prices: tabs.prices || 0,
    approvals_payment_terms: tabs.payment_terms || 0,
    approvals_bank: tabs.bank || 0,
    approvals_other: tabs.other || 0,
    myReviews: req.myReviewsPendingCount || 0,
    // The bar's "E-mails" counts what this person may approve — the same rule as the Approvals page's chip and the left-menu badge
    // (not the whole send queue, which also holds approved e-mails waiting to go out and this person's own requests).
    approvals_emails: tabs.emails || 0,
    myTasks: req.myTasksCount || 0,
    clientIntake: req.clientIntakePendingCount || 0,
    piIntake: req.piIntakePendingCount || 0,
    associateRegistrations: req.associateRegistrationsCount || 0,
    zohoWaiting: req.zohoWaitingMenu || 0,
    setupNeeded: req.setupNeededCount || 0,
    settingsExpiry: req.settingsExpiryWarningCount || 0,
  };
  return build(can, flags, counts, req.path, req.query || {});
}

module.exports = { config, under, sectionKeyFor, allowed, build, forRequest };
