'use strict';

const approvalsInbox = require('../services/approvalsInbox');
const uiTemplateService = require('../services/uiTemplateService');

/**
 * Owner decision D-310 (point 27, 11 Nov 2026; mockup approvals.png): the
 * Approvals page — everything the signed-in person may approve, with tabs and
 * counts; each row opens the exact approval screen. A Kanban user gets the same
 * rows as a board (one column per tab) with the same tabs and buttons (owner
 * hard rule 2026-11-01). Port of App\Controllers\ApprovalsController.
 */
async function index(req, res) {
  const items = await approvalsInbox.items(req);
  let tab = String((req.query && req.query.tab) || 'all');
  if (tab !== 'all' && !approvalsInbox.TABS[tab]) tab = 'all';
  const board = await uiTemplateService.showsBoard(req);
  const shown = tab === 'all' ? items : items.filter((i) => i.tab === tab);
  const columns = [];
  for (const [key, label] of Object.entries(approvalsInbox.TABS)) {
    if (tab === 'all' || tab === key) columns.push({ key, label, items: shown.filter((i) => i.tab === key) });
  }
  const today = require('../helpers/dbDateTime').todayIst();
  res.renderView(board ? 'approvals/index_kanban' : 'approvals/index', {
    items: shown.map((i) => ({ ...i, since_label: approvalsInbox.sinceLabel(i.since, today) })),
    columns: columns.map((c) => ({ ...c, items: c.items.map((i) => ({ ...i, since_label: approvalsInbox.sinceLabel(i.since, today) })) })),
    counts: approvalsInbox.counts(items),
    tabs: approvalsInbox.TABS,
    tab,
    canEmail: !!(req.permissions && req.permissions.approve_email_send),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
  }, 'layout/base');
}

module.exports = { index };
