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
  [/^\/orders\/\d+\/(?:dossier|edit|annexure|amendments|additional-documents\/removed)(?:[/?#]|$)/, ['manage_orders']],
  [/^\/orders\/\d+\/zoho-check(?:[/?#]|$)/, ['ca_module_manage']],
  // Owner decision D-245 P2: the MD's approval screen for a line price below the floor.
  [/^\/orders\/\d+\/products\/\d+\/price-approval(?:[/?#]|$)/, ['manage_pricing']],
  [/^\/orders\/\d+\/comment-attachments\/\d+\/download(?:[/?#]|$)/, ['download_pdf']],
  [/^\/orders\/\d+\/(?:email\/compose|documents\/\d+\/send)(?:[/?#]|$)/, ['generate_documents']],
  [/^\/orders\/\d+\/disputes(?:[/?#]|$)/, ['manage_disputes', 'respond_to_disputes']],
  [/^\/orders\/\d+\/complaints(?:[/?#]|$)/, ['manage_complaints', 'respond_to_complaints']],
  [/^\/orders\/\d+\/audit-log(?:[/?#]|$)/, ['view_audit_log']],
  [/^\/orders\/archived(?:[/?#]|$)/, ['view_archived_orders']],
  [/^\/orders\/create(?:[/?#]|$)/, ['manage_orders']],
  [/^\/clients\/(?:create|\d+\/(?:duplicate|edit|agreement\/download|additional-documents\/removed))(?:[/?#]|$)/, ['manage_orders']],
  [/^\/documents\/\d+\/download(?:[/?#]|$)/, ['download_pdf', 'view_cafin']],
  [/^\/sop\/ca-/, ['ca_module_view']],
  [/^\/settings(?:[/?#]|$)/, ['manage_company_settings']],
  [/^\/audit-log(?:[/?#]|$)/, ['view_audit_log']],
  [/^\/ca\/cafin-export(?:[/?#]|$)/, ['view_cafin']],
  [/^\/ca\/(?:zoho-sync|fy-locks)(?:[/?#]|$)/, ['ca_module_manage']],
  [/^\/ca(?:[/?#]|$)/, ['ca_module_view']],
  [/^\/disputes(?:[/?#]|$)/, ['manage_disputes', 'respond_to_disputes']],
  [/^\/complaints(?:[/?#]|$)/, ['manage_complaints', 'respond_to_complaints']],
  [/^\/incentives\/settings(?:[/?#]|$)/, ['manage_incentives']],
  [/^\/incentives\/payouts(?:[/?#]|$)/, ['pay_incentives']],
  // Owner decision 1 Nov 2026 (AS-11): associates.
  [/^\/reports\/associates(?:[/?#]|$)/, ['manage_associates']],
  // D-235 (2 Nov 2026): the associate TDS register is for Finance / incentive payers / the CA.
  [/^\/associates\/tds(?:[/?#]|$)/, ['manage_incentives', 'pay_incentives', 'ca_module_view']],
  [/^\/associates\/\d+\/bank\/\d+\/number(?:[/?#]|$)/, ['manage_associate_bank']],
  [/^\/associates\/\d+\/documents\/\d+(?:[/?#]|$)/, ['manage_associates', 'manage_associate_bank']],
  [/^\/associates\/(?:new|ladder|registrations|\d+\/(?:edit|view-as|agreement))(?:[/?#]|$)/, ['manage_associates']],
  [/^\/associates(?:[/?#]|$)/, ['view_associates', 'manage_associates', 'manage_associate_bank']],
  // Owner decision D-245 P5: incentive & commission reports.
  [/^\/reports\/incentives(?:[/?#]|$)/, ['manage_incentives', 'pay_incentives', 'view_price_buildup']],
  [/^\/reports\/staff(?:[/?#]|$)/, ['view_staff_reports']],
  [/^\/reports\/order-profitability(?:[/?#]|$)/, ['manage_order_financials']],
  // Owner decision 1 Nov 2026 (A-7 / A-9): each needs only the money it shows.
  [/^\/reports\/freight-cost(?:[/?#]|$)/, ['view_logistics_costs']],
  [/^\/reports\/suppliers(?:[/?#]|$)/, ['view_purchase_prices']],
  // D-234 (2 Nov 2026): Missing eBRC / FIRC report.
  [/^\/reports\/missing-ebrc(?:[/?#]|$)/, ['ca_module_view', 'manage_export_benefit_claims', 'manage_order_financials']],
  [/^\/reports(?:[/?#]|$)/, ['view_reports']],
  [/^\/clients(?:[/?#]|$)/, ['manage_orders', 'view_clients']],
  [/^\/orders(?:[/?#]|$)/, ['manage_orders', 'view_orders']],
  [/^\/(?:client-intake|pi-intake-review|reorder-requests)(?:[/?#]|$)/, ['manage_orders']],
  [/^\/admin\/field-protection(?:[/?#]|$)/, ['manage_field_protection']],
  [/^\/company-assets(?:[/?#]|$)/, ['manage_assets']],
  [/^\/signatories(?:[/?#]|$)/, ['manage_signatories']],
  [/^\/port-routes\/lookup(?:[/?#]|$)/, ['manage_orders']],
  [/^\/(?:ports|port-routes)(?:[/?#]|$)/, ['manage_ports']],
  [/^\/users(?:[/?#]|$)/, ['manage_users']],
];

/** QA Round 6, R6-N02: pages of one order also need that order to be visible to the user (D-118). */
const ORDER_PAGE = /^\/(?:orders|reports\/order)\/(\d+)(?:[/?#]|$)/;

/** @returns {string[]|null} the permissions (any one) the path needs, or null when it needs none of these */
function requiredFor(path) {
  const rule = RULES.find(([pattern]) => pattern.test(path));
  return rule ? rule[1] : null;
}

/**
 * canSeeOrder (optional): orderAccessService.orderVisibilityChecker()'s
 * function; without it, links to one order are judged by permission only.
 */
function mayOpen(path, permissions, canSeeOrder = null) {
  const order = canSeeOrder ? path.match(ORDER_PAGE) : null;
  if (order && !canSeeOrder(Number(order[1]))) return false;
  const required = requiredFor(path);
  return required === null || required.some((key) => !!(permissions && permissions[key]));
}

/** Replaces every internal link the user may not open with its plain text. */
function filterHtml(html, permissions, canSeeOrder = null) {
  return String(html).replace(/<a\s[^>]*href="(\/[^"]*)"[^>]*>([\s\S]*?)<\/a>/g, (whole, href, text) => (
    mayOpen(href.replace(/&amp;/g, '&'), permissions, canSeeOrder) ? whole : `<span class="link-not-permitted">${text}</span>`
  ));
}

module.exports = { RULES, ORDER_PAGE, requiredFor, mayOpen, filterHtml };
