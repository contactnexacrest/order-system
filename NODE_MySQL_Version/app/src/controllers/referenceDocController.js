'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const flash = require('../helpers/flash');
const env = require('../config/env');
const referenceContent = require('../helpers/referenceContent');
const auditLogRepository = require('../repositories/auditLogRepository');
const internalReferenceDocRepository = require('../repositories/internalReferenceDocRepository');
const referenceLibraryRepository = require('../repositories/referenceLibraryRepository');

/**
 * Port of App\Controllers\ReferenceDocController — the Internal Reference
 * Library. Any authenticated staff member can view (SOPs, the Stage Gate
 * Reference, the Cross-Verification Checklist, and the Wall Reference are
 * things every staff member should be able to look up), editing is gated
 * the same as Company Settings since this is effectively business-rule
 * configuration content, not per-user data.
 *
 * Also covers the custom Reference Library entries (schema.sql Section Y)
 * — add/delete/reupload-a-file, alongside the 8 fixed entries above,
 * which stay edit-only (add/delete doesn't make sense for those; each is
 * a specific named document the app itself refers to by code).
 */

const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
const MAX_BYTES = 15 * 1024 * 1024;
const MIME_BY_EXT = {
  pdf: 'application/pdf',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  txt: 'text/plain',
};

async function index(req, res) {
  const [docs, customDocs] = await Promise.all([
    internalReferenceDocRepository.all(),
    referenceLibraryRepository.all(),
  ]);
  res.renderView('reference_docs/index', { docs, customDocs }, 'layout/base');
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

// --- Custom entries: add / edit / delete / reupload file ---

function saveUploadedFile(file) {
  const originalName = file.originalname;
  const extension = path.extname(originalName).replace(/^\./, '').toLowerCase();
  if (!ALLOWED_EXTENSIONS.includes(extension)) {
    throw new Error(`File type .${extension} is not allowed (allowed: ${ALLOWED_EXTENSIONS.join(', ')}).`);
  }
  if (file.size > MAX_BYTES) {
    throw new Error('File is too large — maximum is 15 MB.');
  }

  const storageBase = env.get('STORAGE_BASE_PATH', path.join(__dirname, '..', '..', 'storage'));
  const targetDir = path.join(storageBase, 'internal', 'reference_library');
  fs.mkdirSync(targetDir, { recursive: true });

  const uuidFilename = `${crypto.randomBytes(16).toString('hex')}.${extension}`;
  const targetPath = path.join(targetDir, uuidFilename);
  fs.writeFileSync(targetPath, file.buffer);

  return { targetPath, originalName, mimeType: MIME_BY_EXT[extension] || 'application/octet-stream' };
}

async function customCreateForm(req, res) {
  res.renderView('reference_docs/custom_create', {}, 'layout/base');
}

async function customCreate(req, res) {
  const title = String(req.body.title || '').trim();
  const content = String(req.body.content || '').trim() || null;

  if (title === '') {
    flash.set(req, 'error', 'Title is required.');
    res.redirect('/reference-docs/custom/create');
    return;
  }

  const id = await referenceLibraryRepository.create(title, content, req.user.id);

  if (req.file) {
    try {
      const saved = saveUploadedFile(req.file);
      await referenceLibraryRepository.updateFile(id, saved.targetPath, saved.originalName, saved.mimeType, req.user.id);
    } catch (e) {
      flash.set(req, 'error', `Document created, but the file wasn't attached: ${e.message}`);
      res.redirect('/reference-docs');
      return;
    }
  }

  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_DOC_CREATED', 'reference_library_documents', id, 'title', null, title);
  flash.set(req, 'success', `"${title}" added to the Reference Library.`);
  res.redirect('/reference-docs');
}

async function customShow(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }
  res.renderView(
    'reference_docs/custom_show',
    { doc, contentHtml: doc.content ? referenceContent.toHtml(doc.content) : null },
    'layout/base'
  );
}

async function customEditForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }
  res.renderView('reference_docs/custom_edit', { doc }, 'layout/base');
}

async function customUpdate(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }

  const title = String(req.body.title || '').trim();
  const content = String(req.body.content || '').trim() || null;
  if (title === '') {
    flash.set(req, 'error', 'Title is required.');
    res.redirect(`/reference-docs/custom/${id}/edit`);
    return;
  }

  await referenceLibraryRepository.updateText(id, title, content, req.user.id);

  if (req.file) {
    try {
      const saved = saveUploadedFile(req.file);
      await referenceLibraryRepository.updateFile(id, saved.targetPath, saved.originalName, saved.mimeType, req.user.id);
    } catch (e) {
      flash.set(req, 'error', `Text saved, but the new file wasn't attached: ${e.message}`);
      res.redirect(`/reference-docs/custom/${id}/edit`);
      return;
    }
  }

  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_DOC_UPDATED', 'reference_library_documents', id, 'title', doc.title, title);
  flash.set(req, 'success', `"${title}" updated.`);
  res.redirect('/reference-docs');
}

async function customDelete(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc) {
    flash.set(req, 'error', 'Reference document not found.');
    res.redirect('/reference-docs');
    return;
  }

  await referenceLibraryRepository.deleteEntry(id);
  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_DOC_DELETED', 'reference_library_documents', id, 'title', doc.title, null);
  flash.set(req, 'success', `"${doc.title}" removed from the Reference Library. (Its uploaded file, if any, is left on disk — nothing is ever deleted.)`);
  res.redirect('/reference-docs');
}

async function customDownload(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc || !doc.file_path || !fs.existsSync(doc.file_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }

  let safeDownloadName = String(doc.file_original_name || 'file').replace(/[\\/]/g, '-');
  // eslint-disable-next-line no-control-regex
  safeDownloadName = safeDownloadName.replace(/[\x00-\x1F\x7F"]/g, '');

  res.setHeader('Content-Type', doc.file_mime_type || 'application/octet-stream');
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  fs.createReadStream(doc.file_path).pipe(res);
}

module.exports = {
  index, show, edit, update,
  customCreateForm, customCreate, customShow, customEditForm, customUpdate, customDelete, customDownload,
};
