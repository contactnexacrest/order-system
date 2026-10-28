'use strict';

/**
 * QA Round 5, R5-04: the SOP chapters and the Getting Started tour link to
 * pages by name (Settings, Audit Log, CA, Disputes, ...) — a user without
 * the page's permission clicked through to a 403. filterHtml() turns such
 * a link into plain text for that user; the text itself stays.
 *
 * Each rule is the permission its route requires in src/server.js (any one
 * of the listed keys); permittedLinks.test.js checks they match. More
 * specific paths come first. Port of App\Helpers\PermittedLinks.
 */
const RULES = [
  [/^\/orders\/\d+\/dossier(?:[/?#]|$)/, ['manage_orders']],
  [/^\/orders\/\d+\/additional-documents\/removed(?:[/?#]|$)/, ['manage_orders']],
  [/^\/clients\/\d+\/additional-documents\/removed(?:[/?#]|$)/, ['manage_orders']],
  [/^\/documents\/\d+\/download(?:[/?#]|$)/, ['download_pdf', 'view_cafin']],
  [/^\/sop\/ca-/, ['ca_module_view']],
  [/^\/settings(?:[/?#]|$)/, ['manage_company_settings']],
  [/^\/audit-log(?:[/?#]|$)/, ['view_audit_log']],
  [/^\/ca(?:[/?#]|$)/, ['ca_module_view']],
  [/^\/disputes(?:[/?#]|$)/, ['manage_disputes']],
  [/^\/complaints(?:[/?#]|$)/, ['manage_complaints']],
  [/^\/reports(?:[/?#]|$)/, ['view_reports']],
  [/^\/clients(?:[/?#]|$)/, ['manage_orders', 'view_clients']],
  [/^\/orders(?:[/?#]|$)/, ['manage_orders', 'view_orders']],
  [/^\/(?:client-intake|pi-intake-review|reorder-requests)(?:[/?#]|$)/, ['manage_orders']],
  [/^\/admin\/field-protection(?:[/?#]|$)/, ['manage_field_protection']],
  [/^\/company-assets(?:[/?#]|$)/, ['manage_assets']],
  [/^\/signatories(?:[/?#]|$)/, ['manage_signatories']],
  [/^\/(?:ports|port-routes)(?:[/?#]|$)/, ['manage_ports']],
  [/^\/users(?:[/?#]|$)/, ['manage_users']],
];

/** @returns {string[]|null} the permissions (any one) the path needs, or null when it needs none of these */
function requiredFor(path) {
  const rule = RULES.find(([pattern]) => pattern.test(path));
  return rule ? rule[1] : null;
}

function mayOpen(path, permissions) {
  const required = requiredFor(path);
  return required === null || required.some((key) => !!(permissions && permissions[key]));
}

/** Replaces every internal link the user may not open with its plain text. */
function filterHtml(html, permissions) {
  return String(html).replace(/<a\s[^>]*href="(\/[^"]*)"[^>]*>([\s\S]*?)<\/a>/g, (whole, href, text) => (
    mayOpen(href.replace(/&amp;/g, '&'), permissions) ? whole : `<span class="link-not-permitted">${text}</span>`
  ));
}

module.exports = { RULES, requiredFor, mayOpen, filterHtml };
