'use strict';

const flash = require('../helpers/flash');
const referenceContent = require('../helpers/referenceContent');
const auditLogRepository = require('../repositories/auditLogRepository');
const internalReferenceDocRepository = require('../repositories/internalReferenceDocRepository');

/**
 * Port of App\Controllers\ReferenceDocController — the Internal Reference
 * Library. Any authenticated staff member can view (SOPs, the Stage Gate
 * Reference, the Cross-Verification Checklist, and the Wall Reference are
 * things every staff member should be able to look up), editing is gated
 * the same as Company Settings since this is effectively business-rule
 * configuration content, not per-user data.
 */

async function index(req, res) {
  res.renderView('reference_docs/index', { docs: await internalReferenceDocRepository.all() }, 'layout/base');
}

async function show(req, res) {
  const code = String(req.params.code || '').toUpperCase();
  const doc = await internalReferenceDocRepository.findByCode(code);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }

  let content = doc.content || '';
  if (code === 'WALLREF' && content !== '') {
    content = await referenceContent.substitutePlaceholders(content);
  }

  res.renderView(
    'reference_docs/show',
    { doc, contentHtml: content !== '' ? referenceContent.toHtml(content) : null },
    'layout/base'
  );
}

async function edit(req, res) {
  const code = String(req.params.code || '').toUpperCase();
  const doc = await internalReferenceDocRepository.findByCode(code);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }
  res.renderView('reference_docs/edit', { doc }, 'layout/base');
}

async function update(req, res) {
  const code = String(req.params.code || '').toUpperCase();
  const doc = await internalReferenceDocRepository.findByCode(code);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }

  const content = String(req.body.content || '').trim();
  await internalReferenceDocRepository.upsert(doc.document_type_id, content, req.user.id);
  await auditLogRepository.log(req.user.id, 'REFERENCE_DOC_UPDATED', 'internal_reference_docs', doc.document_type_id, 'content', null, null, `Updated ${code}`);

  flash.set(req, 'success', `${doc.name} updated.`);
  res.redirect(`/reference-docs/${code}`);
}

module.exports = { index, show, edit, update };
