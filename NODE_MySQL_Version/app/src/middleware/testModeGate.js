'use strict';

const testModeService = require('../services/testModeService');
const flash = require('../helpers/flash');

/**
 * Test Mode's two access gates (docs/schema.sql Section V). Both are
 * checked by path prefix rather than threading a flag through every
 * existing route registration, so nothing about the ~90 existing routes in
 * server.js has to change.
 */

// "Admin panel settings" — frozen while Test Mode is on, regardless of
// role/permission (requirement: unable to update any setting, immaterial
// of role). Deliberately does NOT include /test-mode itself (must stay
// operable to turn Test Mode off) or /products (Product Catalog — decided
// to stay editable: it's operational reference data staff consult day to
// day, not site configuration).
const FROZEN_PREFIXES = [
  '/settings',
  '/holidays',
  '/reference-docs',
  '/company-assets',
  '/signatories',
  '/super-admin',
  '/admin/permissions',
  '/admin/overrides',
  '/admin/field-protection',
  '/users',
];

// Client-facing surfaces — blocked entirely (every method, not just
// writes) while Test Mode is on: the public quotation-request intake form
// and the whole client portal. `/client` as a prefix does not collide with
// staff-side `/clients` or `/client-intake` — matchesPrefix requires an
// exact match or a '/' boundary right after the prefix, and both of those
// paths diverge from "/client" at the very next character ('s', '-').
const CLIENT_FACING_PREFIXES = ['/quotation-request', '/client'];

function matchesPrefix(path, prefixes) {
  return prefixes.some((p) => path === p || path.startsWith(`${p}/`));
}

function blockAdminWritesInTestMode() {
  return async function testModeAdminFreeze(req, res, next) {
    if (req.method === 'GET' || !matchesPrefix(req.path, FROZEN_PREFIXES)) {
      next();
      return;
    }
    if (await testModeService.isEnabled()) {
      flash.set(req, 'error', 'Test Mode is active — admin panel settings cannot be changed until it is disabled.');
      res.redirect(req.get('Referer') || '/');
      return;
    }
    next();
  };
}

function blockClientFacingInTestMode() {
  return async function testModeClientBlock(req, res, next) {
    if (!matchesPrefix(req.path, CLIENT_FACING_PREFIXES)) {
      next();
      return;
    }
    if (await testModeService.isEnabled()) {
      res.status(503).send(
        `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Temporarily Unavailable</title>
        <style>body{font-family:sans-serif;max-width:640px;margin:80px auto;text-align:center;color:#17233A}
        h1{font-size:1.4rem}p{color:#5B6573}</style></head>
        <body><h1>Temporarily Unavailable</h1>
        <p>This site is undergoing internal testing and is not available right now. Please check back shortly.</p>
        </body></html>`
      );
      return;
    }
    next();
  };
}

/** Populates req.testModeEnabled for res.renderView's common context (sitewide banner). */
function injectLocals() {
  return async function testModeInjectLocals(req, res, next) {
    req.testModeEnabled = await testModeService.isEnabled();
    next();
  };
}

module.exports = { blockAdminWritesInTestMode, blockClientFacingInTestMode, injectLocals };
