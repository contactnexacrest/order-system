'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const cleanup = require('../services/documentCopyCleanupService');

// Port of App\Controllers\DocumentCleanupController — owner points 5 / 8 / 9
// (2 Nov 2026): Admin → Settings → Document copies clean-up
// (manage_company_settings). See documentCopyCleanupService for the rules.

const CATEGORY = 'document_cleanup';
const PREVIEW_LIMIT = 200;

async function index(req, res) {
  const settings = await cleanup.settings();
  const candidates = cleanup.anyOn(settings) ? await cleanup.candidates(settings) : [];
  const estimate = await cleanup.estimate(settings);
  const estimateLines = {};
  for (const [rule, e] of Object.entries(estimate)) {
    estimateLines[rule] = `${e.hypothetical ? 'If switched on now: ' : 'Would remove now: '}about ${e.files.toLocaleString('en-US')} file(s) / ${cleanup.formatBytes(e.bytes)}`;
  }
  res.renderView('settings/document_cleanup', {
    settings,
    estimate,
    estimateLines,
    candidateCountText: candidates.length.toLocaleString('en-US'),
    candidates: candidates.slice(0, PREVIEW_LIMIT),
    candidateCount: candidates.length,
    candidateBytes: candidates.reduce((t, r) => t + Number(r.file_size_bytes), 0),
    previewLimit: PREVIEW_LIMIT,
    draftOptions: cleanup.DRAFT_OPTIONS,
    supersededOptions: cleanup.SUPERSEDED_OPTIONS,
    cafinDays: cleanup.CAFIN_DAYS,
    anyOn: cleanup.anyOn(settings),
    fmt: cleanup.formatBytes,
  });
}

async function save(req, res) {
  const draft = Number.parseInt(req.body.draft_days ?? '-1', 10);
  const superseded = Number.parseInt(req.body.superseded_days ?? '-1', 10);
  const cafin = String(req.body.cafin_mode ?? '');
  if (!cleanup.DRAFT_OPTIONS.includes(draft) || !cleanup.SUPERSEDED_OPTIONS.includes(superseded) || !cleanup.CAFIN_OPTIONS.includes(cafin)) {
    flash.set(req, 'error', 'Choose one of the listed options for each rule. Nothing was saved.');
    res.redirect('/settings/document-cleanup');
    return;
  }
  const next = { [cleanup.KEY_DRAFT]: String(draft), [cleanup.KEY_SUPERSEDED]: String(superseded), [cleanup.KEY_CAFIN]: cafin };
  const changes = [];
  for (const [key, value] of Object.entries(next)) {
    const old = await companySettingsRepository.get(key);
    if (old !== value) changes.push([key, old, value]);
  }
  if (changes.length === 0) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect('/settings/document-cleanup');
    return;
  }
  const reason = String(req.body.reason ?? '').trim();
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/settings/document-cleanup');
    return;
  }
  for (const [key, old, value] of changes) {
    await companySettingsRepository.set(key, value, req.user.id);
    await auditLogRepository.log(req.user.id, 'FIELD_EDIT', 'company_settings', null, key, old, value, reason);
  }
  flash.set(req, 'success', 'Document copies clean-up settings saved.' + (cleanup.anyOn(await cleanup.settings())
    ? ' The daily job removes what the preview below lists; you can also run it now.'
    : ' Every rule is off — nothing will be removed.'));
  res.redirect('/settings/document-cleanup');
}

async function run(req, res) {
  if (req.body.confirm_permanent !== '1') {
    flash.set(req, 'error', 'Tick "I understand removed copies cannot be restored" to run the clean-up.');
    res.redirect('/settings/document-cleanup');
    return;
  }
  const result = await cleanup.run(req.user.id, 'manual');
  if (result.skipped_off) {
    flash.set(req, 'error', 'Every rule is off — switch at least one on and save first. Nothing was removed.');
  } else {
    flash.set(req, 'success', `Clean-up done: ${result.files} copy(ies) removed, ${cleanup.formatBytes(result.bytes)} freed.`);
  }
  res.redirect('/settings/document-cleanup');
}

module.exports = { CATEGORY, index, save, run };
