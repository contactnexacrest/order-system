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

async function show(req, res) {
  // QA Round 5, R5-04: links to pages this user can't open become plain text.
  const send = res.send.bind(res);
  res.send = (html) => send(permittedLinks.filterHtml(html, req.permissions));
  res.renderView('getting-started/show', {
    userName: (req.user && req.user.name) || null,
  }, null);
}

module.exports = { show };
