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
const referenceLibraryCategoryRepository = require('../repositories/referenceLibraryCategoryRepository');
const permissionRepository = require('../repositories/permissionRepository');

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

/**
 * docs/schema.sql Section AY — a custom entry filed under a category whose
 * required_permission the current user doesn't hold is left out entirely;
 * uncategorized entries (category_id NULL, the only kind that existed
 * before this feature) are always visible, same as today's behaviour. The
 * fixed 8 internal_reference_docs are never scoped — they're system-wide
 * reference material every staff member needs.
 */
async function index(req, res) {
  const [docs, allCustomDocs] = await Promise.all([
    internalReferenceDocRepository.all(),
    referenceLibraryRepository.all(),
  ]);
  const permissions = req.permissions || {};
  const customDocs = allCustomDocs.filter(
    (doc) => !doc.category_required_permission || permissions[doc.category_required_permission]
  );
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
  const categories = await referenceLibraryCategoryRepository.all();
  res.renderView('reference_docs/custom_create', { categories }, 'layout/base');
}

async function customCreate(req, res) {
  const title = String(req.body.title || '').trim();
  const content = String(req.body.content || '').trim() || null;
  const categoryId = req.body.category_id ? parseInt(req.body.category_id, 10) : null;

  if (title === '') {
    flash.set(req, 'error', 'Title is required.');
    res.redirect('/reference-docs/custom/create');
    return;
  }

  const id = await referenceLibraryRepository.create(title, content, req.user.id, categoryId);

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

/**
 * docs/schema.sql Section AY — the index already filters out what a user
 * can't see, but the same check is re-applied here (and in
 * customDownload below) so a direct URL can't bypass the category scope.
 */
async function canViewCustomDoc(doc, permissions) {
  if (doc.category_id === null) return true;
  const category = await referenceLibraryCategoryRepository.find(doc.category_id);
  if (!category || category.required_permission === null) return true;
  return !!(permissions || {})[category.required_permission];
}

async function customShow(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc) {
    res.status(404).send('Reference document not found.');
    return;
  }
  if (!(await canViewCustomDoc(doc, req.permissions))) {
    res.status(403).send('<h1>403 — Not permitted</h1><p>You do not have access to this category of Reference Library document.</p><p><a href="/reference-docs">Back to Reference Library</a></p>');
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
  const categories = await referenceLibraryCategoryRepository.all();
  res.renderView('reference_docs/custom_edit', { doc, categories }, 'layout/base');
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
  const categoryId = req.body.category_id ? parseInt(req.body.category_id, 10) : null;
  if (title === '') {
    flash.set(req, 'error', 'Title is required.');
    res.redirect(`/reference-docs/custom/${id}/edit`);
    return;
  }

  await referenceLibraryRepository.updateText(id, title, content, req.user.id, categoryId);

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

async function categoriesIndex(req, res) {
  const [categories, permissions] = await Promise.all([
    referenceLibraryCategoryRepository.all(),
    permissionRepository.all(),
  ]);
  res.renderView('reference_docs/categories', { categories, permissions }, 'layout/base');
}

async function categoryCreate(req, res) {
  const name = String(req.body.name || '').trim();
  const requiredPermission = String(req.body.required_permission || '').trim() || null;
  if (name === '') {
    flash.set(req, 'error', 'Category name is required.');
    res.redirect('/reference-docs/categories');
    return;
  }

  const id = await referenceLibraryCategoryRepository.create(name, requiredPermission);
  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_CATEGORY_CREATED', 'reference_library_categories', id, 'name', null, name);
  flash.set(req, 'success', `Category "${name}" created.`);
  res.redirect('/reference-docs/categories');
}

async function categoryUpdate(req, res) {
  const id = parseInt(req.params.id, 10);
  const category = await referenceLibraryCategoryRepository.find(id);
  if (!category) {
    flash.set(req, 'error', 'Category not found.');
    res.redirect('/reference-docs/categories');
    return;
  }

  const name = String(req.body.name || '').trim();
  const requiredPermission = String(req.body.required_permission || '').trim() || null;
  if (name === '') {
    flash.set(req, 'error', 'Category name is required.');
    res.redirect('/reference-docs/categories');
    return;
  }

  await referenceLibraryCategoryRepository.update(id, name, requiredPermission);
  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_CATEGORY_UPDATED', 'reference_library_categories', id, 'required_permission', category.required_permission, requiredPermission);
  flash.set(req, 'success', `Category "${name}" updated.`);
  res.redirect('/reference-docs/categories');
}

async function categoryDelete(req, res) {
  const id = parseInt(req.params.id, 10);
  const category = await referenceLibraryCategoryRepository.find(id);
  if (!category) {
    flash.set(req, 'error', 'Category not found.');
    res.redirect('/reference-docs/categories');
    return;
  }

  await referenceLibraryCategoryRepository.deleteCategory(id);
  await auditLogRepository.log(req.user.id, 'REFERENCE_LIBRARY_CATEGORY_DELETED', 'reference_library_categories', id, 'name', category.name, null);
  flash.set(req, 'success', `Category "${category.name}" deleted. Any documents filed under it are now uncategorized (visible to everyone), never deleted.`);
  res.redirect('/reference-docs/categories');
}

async function customDownload(req, res) {
  const id = parseInt(req.params.id, 10);
  const doc = await referenceLibraryRepository.find(id);
  if (!doc || !doc.file_path || !fs.existsSync(doc.file_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }
  if (!(await canViewCustomDoc(doc, req.permissions))) {
    res.status(403).send('<h1>403 — Not permitted</h1><p>You do not have access to this category of Reference Library document.</p><p><a href="/reference-docs">Back to Reference Library</a></p>');
    return;
  }

  let safeDownloadName = String(doc.file_original_name || 'file').replace(/[\\/]/g, '-');
  // eslint-disable-next-line no-control-regex
  safeDownloadName = safeDownloadName.replace(/[\x00-\x1F\x7F"]/g, '');

  // A raw non-ASCII byte (e.g. an em dash — Quarry SOP — Block Selection)
  // in a bare filename="..." isn't valid Latin-1 header content — Node's
  // http module throws ("Invalid character in header content") rather
  // than send it, which took the whole download down. The RFC 6266
  // filename* parameter carries the real UTF-8 name percent-encoded, with
  // an ASCII fallback in filename= for any client that ignores it.
  // eslint-disable-next-line no-control-regex
  const asciiDownloadName = safeDownloadName.replace(/[^\x20-\x7E]/g, '_');

  res.setHeader('Content-Type', doc.file_mime_type || 'application/octet-stream');
  res.setHeader('Content-Disposition', `attachment; filename="${asciiDownloadName}"; filename*=UTF-8''${encodeURIComponent(safeDownloadName)}`);
  fs.createReadStream(doc.file_path).pipe(res);
}

module.exports = {
  index, show, edit, update,
  customCreateForm, customCreate, customShow, customEditForm, customUpdate, customDelete, customDownload,
  categoriesIndex, categoryCreate, categoryUpdate, categoryDelete,
};
