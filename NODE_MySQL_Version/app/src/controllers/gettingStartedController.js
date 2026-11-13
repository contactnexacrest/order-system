'use strict';

/**
 * Port of Controllers\GettingStartedController — a first-day "Getting
 * Started" tour for brand-new staff. Deliberately NOT wrapped in the
 * usual app shell (layout/base.njk): it has its own standalone design
 * with its own navigation rail, built on app.css's own tokens so it
 * still looks like part of the same application. Mirrors
 * docs/SOP/00-getting-started.md's content (the canonical, version-
 * controlled text any staff member can also read via the SOP viewer)
 * but as a visually distinct, interactive companion piece rather than a
 * literal HTML transcription of that Markdown file.
 *
 * Same visibility as sopController: logged in is the only requirement,
 * since this is meant to be the very first thing a new hire opens.
 */

const permittedLinks = require('../helpers/permittedLinks');
const sopAccess = require('../services/sopAccessService');

async function show(req, res) {
  // Render first, then filter (the SOP filters below are async).
  const send = res.send.bind(res);
  let html = '';
  res.send = (out) => { html = out; };
  try {
    res.renderView('getting-started/show', {
      userName: (req.user && req.user.name) || null,
    }, null);
  } finally {
    res.send = send;
  }
  // QA Round 5, R5-04: links to pages this user can't open become plain text.
  html = permittedLinks.filterHtml(html, req.permissions);
  // UI-005 / D-266: links to SOP chapters this person may not open become plain text,
  // and screenshots they may not load are left out (no broken image).
  const roleId = req.user && req.user.role_id !== null && req.user.role_id !== undefined ? Number(req.user.role_id) : null;
  const visible = await sopAccess.visibleSlugs(req.user.id, roleId, req.permissions);
  html = sopAccess.filterChapterLinks(html, visible);
  html = await sopAccess.filterScreenshots(html, req.user.id, req.permissions, visible);
  send(html);
}

module.exports = { show };
