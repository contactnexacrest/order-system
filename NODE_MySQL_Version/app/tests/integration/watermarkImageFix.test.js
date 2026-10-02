'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const db = require('../../src/config/db');
const watermarkController = require('../../src/controllers/watermarkController');
const assetRepository = require('../../src/repositories/assetRepository');
const watermarkSettingsRepository = require('../../src/repositories/watermarkSettingsRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const flash = require('../../src/helpers/flash');

/**
 * Point 8 — mode 'both' used to save successfully and then silently
 * render text-only on every generated PDF forever after, with nothing
 * telling anyone why, whenever the watermark image's file went missing
 * from disk (moved/deleted/not carried over by a deploy) while the
 * watermark_settings row still pointed at it. Two things changed:
 * (1) documentGenerationService.assetDataUri() now logs instead of
 * failing completely silently, and (2) watermarkController.update() now
 * refuses to save mode 'image'/'both' at all when the active watermark
 * asset's file isn't actually on disk, catching the problem while it's
 * still fixable instead of after the fact.
 */
describe('Watermark "both" mode missing-image fix (Point 8)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('update() refuses both mode when the active watermark asset file is missing', async () => {
    const missingPath = path.join(os.tmpdir(), `jest-watermark-missing-${crypto.randomBytes(4).toString('hex')}.png`);
    expect(fs.existsSync(missingPath)).toBe(false);
    await assetRepository.insert('watermark', 'Test Watermark', missingPath, 'image/png', null, true);

    const userId = await createTestUser('Admin');

    // Seed defaults now default new rows to mode 'both' (see docs/seed.sql),
    // so start from a known, different mode here — otherwise the
    // "unchanged" assertion below would pass trivially even if the refused
    // update wrongly saved, since 'both' would already be the pre-existing
    // value regardless of what update() does.
    await watermarkSettingsRepository.upsertGlobal(true, {
      mode: 'text', text_content: 'DRAFT', font: 'Helvetica', font_size: 60,
      color: '#a8701f', opacity: 0.15, angle: 45,
      image_asset_id: null, image_opacity: 0.05, image_position: 'center',
    }, userId);

    const req = { params: { which: 'draft' }, body: { mode: 'both', text_content: 'DRAFT' }, user: { id: userId }, session: {} };
    await watermarkController.update(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toMatch(/missing from storage/);
    const saved = await watermarkSettingsRepository.findGlobal(true);
    expect(saved ? saved.mode : null).toBe('text');
  });

  it('update() saves both mode when the active watermark asset file exists', async () => {
    const realPath = path.join(os.tmpdir(), `jest-watermark-real-${crypto.randomBytes(4).toString('hex')}.png`);
    fs.writeFileSync(realPath, Buffer.from('89504e470d0a1a0a', 'hex'));
    try {
      await assetRepository.insert('watermark', 'Test Watermark', realPath, 'image/png', null, true);

      const userId = await createTestUser('Admin');
      const req = { params: { which: 'draft' }, body: { mode: 'both', text_content: 'DRAFT' }, user: { id: userId }, session: {} };
      await watermarkController.update(req, fakeRes());

      const messages = flash.pull(req);
      expect(messages[0].type).toBe('success');
      const saved = await watermarkSettingsRepository.findGlobal(true);
      expect(saved.mode).toBe('both');
      expect(saved.image_asset_id).not.toBeNull();
    } finally {
      fs.unlinkSync(realPath);
    }
  });

  it('watermarkFromRow shows both layers when the image file exists', async () => {
    const realPath = path.join(os.tmpdir(), `jest-watermark-render-${crypto.randomBytes(4).toString('hex')}.png`);
    fs.writeFileSync(realPath, Buffer.from('89504e470d0a1a0a', 'hex'));
    try {
      const assetId = await assetRepository.insert('watermark', 'Render Test', realPath, 'image/png', null, true);
      const result = await documentGenerationService.watermarkFromRow({
        mode: 'both', text_content: 'DRAFT', color: '#CCCCCC', opacity: 0.3,
        angle: 45, font_size: 60, image_asset_id: assetId,
        image_opacity: 0.15, image_position: 'center',
      });

      expect(result.show_text).toBe(true);
      expect(result.show_image).toBe(true);
      expect(result.image_data_uri).not.toBeNull();
    } finally {
      fs.unlinkSync(realPath);
    }
  });

  it('watermarkFromRow shows only text when the image file is missing', async () => {
    const missingPath = path.join(os.tmpdir(), `jest-watermark-missing-render-${crypto.randomBytes(4).toString('hex')}.png`);
    const assetId = await assetRepository.insert('watermark', 'Missing Render Test', missingPath, 'image/png', null, true);

    const result = await documentGenerationService.watermarkFromRow({
      mode: 'both', text_content: 'DRAFT', color: '#CCCCCC', opacity: 0.3,
      angle: 45, font_size: 60, image_asset_id: assetId,
      image_opacity: 0.15, image_position: 'center',
    });

    expect(result.show_text).toBe(true);
    expect(result.show_image).toBe(false);
    expect(result.image_data_uri).toBeNull();
  });
});
