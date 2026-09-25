'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const env = require('../config/env');
const flash = require('../helpers/flash');
const sniffMime = require('../helpers/sniffMime');
const assetRepository = require('../repositories/assetRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

// Port of App\Controllers\AssetController.

const ALLOWED_TYPES = ['logo', 'signature', 'seal', 'watermark'];
const ALLOWED_MIME = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/svg+xml': 'svg' };
const MAX_BYTES = 5 * 1024 * 1024; // 5MB

async function index(req, res) {
  const assets = await assetRepository.all();
  const active = {};
  const history = {};
  for (const a of assets) {
    if (a.is_active) {
      (active[a.asset_type] ||= []).push(a);
    } else {
      (history[a.asset_type] ||= []).push(a);
    }
  }
  res.renderView('assets/index', { assets, active, history }, 'layout/base');
}

/**
 * Streams the current active file for an asset type. Storage lives outside
 * the public/ static folder, so this is the only way a browser can ever see
 * one of these files — there is no direct URL to storage/assets/*, by design.
 */
async function preview(req, res) {
  const type = String(req.query.type || '');
  if (!ALLOWED_TYPES.includes(type)) {
    res.status(404).end();
    return;
  }

  const asset = await assetRepository.findActiveByType(type);
  if (!asset || !fs.existsSync(asset.server_path)) {
    res.status(404).end();
    return;
  }

  res.set('Content-Type', asset.mime_type || 'application/octet-stream');
  res.set('Cache-Control', 'private, max-age=60');
  fs.createReadStream(asset.server_path).pipe(res);
}

async function replace(req, res) {
  const user = req.user;
  const assetType = String(req.body.asset_type || '');
  let name = String(req.body.name || '').trim();

  if (!ALLOWED_TYPES.includes(assetType)) {
    flash.set(req, 'error', 'Unknown asset type.');
    res.redirect('/company-assets');
    return;
  }
  if (name === '') {
    name = assetType.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
  }

  if (!req.file) {
    flash.set(req, 'error', 'No file was uploaded, or the upload failed.');
    res.redirect('/company-assets');
    return;
  }

  const file = req.file; // multer memoryStorage: { buffer, size, originalname, mimetype }
  if (file.size > MAX_BYTES) {
    flash.set(req, 'error', 'File too large (max 5MB).');
    res.redirect('/company-assets');
    return;
  }

  const mime = sniffMime.sniff(file.buffer);
  if (!mime || !ALLOWED_MIME[mime]) {
    flash.set(req, 'error', 'Unsupported file type. Use PNG, JPG, or SVG.');
    res.redirect('/company-assets');
    return;
  }

  const ext = ALLOWED_MIME[mime];
  const folder = folderFor(assetType);
  const storageBase = env.get('STORAGE_BASE_PATH', path.join(__dirname, '..', '..', 'storage'));
  const targetDir = path.join(storageBase, 'assets', folder);
  fs.mkdirSync(targetDir, { recursive: true });

  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
  const filename = `${assetType}_${stamp}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
  const targetPath = path.join(targetDir, filename);

  fs.writeFileSync(targetPath, file.buffer);

  const newId = await assetRepository.replace(assetType, name, targetPath, mime, user.id);
  await auditLogRepository.log(user.id, 'ASSET_REPLACED', 'assets', newId, assetType, null, targetPath);

  flash.set(req, 'success', `${assetType.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())} updated.`);
  res.redirect('/company-assets');
}

/**
 * Removes a superseded (inactive) upload only — never the currently active
 * asset of a type, so there is always something to render. Gated on a
 * separate `delete_assets` permission (not `manage_assets`), matching the
 * explicit requirement that deletion needs its own, admin/role-controlled
 * grant, distinct from ordinary upload/replace.
 */
async function remove(req, res) {
  const user = req.user;
  const id = parseInt(req.params.id, 10) || 0;
  const asset = await assetRepository.find(id);

  if (!asset) {
    flash.set(req, 'error', 'Asset not found.');
    res.redirect('/company-assets');
    return;
  }

  if (asset.is_active) {
    flash.set(req, 'error', `Cannot delete the currently active ${asset.asset_type.replace(/_/g, ' ')}. Replace it with a new upload first, then delete the old one.`);
    res.redirect('/company-assets');
    return;
  }

  try {
    await assetRepository.remove(id);
  } catch (e) {
    flash.set(req, 'error', 'This file is still referenced by a previously generated document or setting and cannot be deleted — its history must be preserved.');
    res.redirect('/company-assets');
    return;
  }

  await auditLogRepository.log(user.id, 'ASSET_DELETED', 'assets', id, asset.asset_type, asset.server_path, null);
  flash.set(req, 'success', `Old ${asset.asset_type.replace(/_/g, ' ')} upload deleted.`);
  res.redirect('/company-assets');
}

function folderFor(assetType) {
  return {
    logo: 'logos',
    signature: 'signatures',
    seal: 'seals',
    watermark: 'watermarks',
  }[assetType] || 'misc';
}

module.exports = { index, preview, replace, remove };
