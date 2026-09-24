'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const env = require('../config/env');
const flash = require('../helpers/flash');
const sniffMime = require('../helpers/sniffMime');
const signatoryRepository = require('../repositories/signatoryRepository');
const userRepository = require('../repositories/userRepository');

/**
 * Port of App\Controllers\SignatoryController — admin screen for the
 * signatory/designation system. The company seal itself is NOT managed
 * here — it stays a single shared asset on /company-assets, unchanged.
 */

const ALLOWED_MIME = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/svg+xml': 'svg' };
const MAX_BYTES = 5 * 1024 * 1024;

async function index(req, res) {
  const users = await signatoryRepository.usersWithSignatoryInfo();
  const userAssets = {};
  for (const u of users) {
    if (u.is_signatory_eligible) {
      userAssets[u.id] = await signatoryRepository.assetsForUser(u.id);
    }
  }
  res.renderView('signatories/index', {
    designations: await signatoryRepository.designations(),
    users,
    userAssets,
    eligible: await signatoryRepository.eligibleSignatories(),
    globalDefaultUserId: await signatoryRepository.globalDefaultSignatoryUserId(),
    documentTypeSignatories: await signatoryRepository.documentTypeSignatories(),
  }, 'layout/base');
}

async function createDesignation(req, res) {
  const title = String(req.body.title || '').trim();
  if (title === '') {
    flash.set(req, 'error', 'Designation title is required.');
    res.redirect('/signatories');
    return;
  }
  await signatoryRepository.createDesignation(title, req.user.id);
  flash.set(req, 'success', `Designation "${title}" added.`);
  res.redirect('/signatories');
}

async function toggleDesignation(req, res) {
  await signatoryRepository.toggleDesignationActive(parseInt(req.params.id, 10) || 0);
  flash.set(req, 'success', 'Designation updated.');
  res.redirect('/signatories');
}

async function updateDesignation(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const designation = await signatoryRepository.findDesignation(id);
  if (!designation) {
    flash.set(req, 'error', 'Designation not found.');
    res.redirect('/signatories');
    return;
  }
  const title = String(req.body.title || '').trim();
  if (title === '') {
    flash.set(req, 'error', 'Designation title is required.');
    res.redirect('/signatories');
    return;
  }
  if (title !== designation.title && (await signatoryRepository.designationAssignedToProtectedAccount(id))) {
    flash.set(req, 'error', 'This designation is assigned to a protected founder account — its title can never be changed through the application.');
    res.redirect('/signatories');
    return;
  }
  await signatoryRepository.updateDesignationTitle(id, title);
  flash.set(req, 'success', `Designation renamed to "${title}".`);
  res.redirect('/signatories');
}

async function deleteDesignation(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const designation = await signatoryRepository.findDesignation(id);
  if (!designation) {
    flash.set(req, 'error', 'Designation not found.');
    res.redirect('/signatories');
    return;
  }
  const usage = await signatoryRepository.designationUsageCount(id);
  if (usage > 0) {
    flash.set(req, 'error', `Can't delete "${designation.title}" — ${usage} user(s) currently carry it. Reassign them first.`);
    res.redirect('/signatories');
    return;
  }
  await signatoryRepository.deleteDesignation(id);
  flash.set(req, 'success', `Designation "${designation.title}" deleted.`);
  res.redirect('/signatories');
}

async function setEligibility(req, res) {
  const userId = parseInt(req.params.id, 10) || 0;
  const eligible = String(req.body.eligible || '0') === '1';
  const designationId = req.body.designation_id !== undefined && req.body.designation_id !== '' ? parseInt(req.body.designation_id, 10) : null;

  const target = await userRepository.findById(userId);
  if (target && parseInt(target.is_protected_account, 10) === 1) {
    flash.set(req, 'error', 'This is a protected founder account — their signatory eligibility and designation can never be changed through the application.');
    res.redirect('/signatories');
    return;
  }

  if (eligible && designationId === null) {
    flash.set(req, 'error', 'Pick a designation before marking this person signatory-eligible.');
    res.redirect('/signatories');
    return;
  }

  await signatoryRepository.setEligibility(userId, eligible, designationId, req.user.id);
  flash.set(req, 'success', 'Signatory eligibility updated.');
  res.redirect('/signatories');
}

async function uploadUserAsset(req, res) {
  const userId = parseInt(req.params.id, 10) || 0;
  const kind = String(req.body.asset_kind || '');
  const label = String(req.body.label || '').trim() || 'Default';

  if (!['signature', 'designation_seal'].includes(kind)) {
    flash.set(req, 'error', 'Unknown asset kind.');
    res.redirect('/signatories');
    return;
  }
  if (!req.file) {
    flash.set(req, 'error', 'No file was uploaded, or the upload failed.');
    res.redirect('/signatories');
    return;
  }

  const file = req.file;
  if (file.size > MAX_BYTES) {
    flash.set(req, 'error', 'File too large (max 5MB).');
    res.redirect('/signatories');
    return;
  }

  const mime = sniffMime.sniff(file.buffer);
  if (!mime || !ALLOWED_MIME[mime]) {
    flash.set(req, 'error', 'Unsupported file type. Use PNG, JPG, WEBP, or SVG.');
    res.redirect('/signatories');
    return;
  }

  const ext = ALLOWED_MIME[mime];
  const folder = kind === 'signature' ? 'signatures' : 'designation_seals';
  const storageBase = env.get('STORAGE_BASE_PATH', path.join(__dirname, '..', '..', 'storage'));
  const targetDir = path.join(storageBase, 'assets', folder);
  fs.mkdirSync(targetDir, { recursive: true });

  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
  const filename = `${kind}_user${userId}_${stamp}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
  const targetPath = path.join(targetDir, filename);
  fs.writeFileSync(targetPath, file.buffer);

  await signatoryRepository.addUserAsset(userId, kind, label, targetPath, mime, req.user.id);
  flash.set(req, 'success', `${kind.replace(/_/g, ' ')} uploaded.`);
  res.redirect('/signatories');
}

async function deactivateUserAsset(req, res) {
  await signatoryRepository.deactivateUserAsset(parseInt(req.params.id, 10) || 0);
  flash.set(req, 'success', 'Asset removed.');
  res.redirect('/signatories');
}

/** Mirrors assetController.preview() — storage lives outside public/, so this is the only way a browser sees one of these. */
async function previewUserAsset(req, res) {
  const asset = await signatoryRepository.findUserAsset(parseInt(req.params.id, 10) || 0);
  if (!asset || !fs.existsSync(asset.server_path)) {
    res.status(404).end();
    return;
  }
  res.set('Content-Type', asset.mime_type || 'application/octet-stream');
  res.set('Cache-Control', 'private, max-age=60');
  fs.createReadStream(asset.server_path).pipe(res);
}

async function setGlobalDefault(req, res) {
  const userId = parseInt(req.body.user_id, 10) || 0;
  if (userId <= 0) {
    flash.set(req, 'error', 'Pick a signatory.');
    res.redirect('/signatories');
    return;
  }
  await signatoryRepository.setGlobalDefaultSignatory(userId, req.user.id);
  flash.set(req, 'success', 'Global default signatory updated.');
  res.redirect('/signatories');
}

async function setDocumentTypeDefault(req, res) {
  const documentTypeId = parseInt(req.params.id, 10) || 0;
  const userId = req.body.user_id !== undefined && req.body.user_id !== '' ? parseInt(req.body.user_id, 10) : null;
  const useDesignationSeal = String(req.body.use_designation_seal || '0') === '1';

  await signatoryRepository.setDocumentTypeSignatory(documentTypeId, userId, useDesignationSeal, req.user.id);
  flash.set(req, 'success', 'Document-type signatory default updated.');
  res.redirect('/signatories');
}

module.exports = {
  index, createDesignation, toggleDesignation, updateDesignation, deleteDesignation, setEligibility,
  uploadUserAsset, deactivateUserAsset, previewUserAsset, setGlobalDefault, setDocumentTypeDefault,
};
