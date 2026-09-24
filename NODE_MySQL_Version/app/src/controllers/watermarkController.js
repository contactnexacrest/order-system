'use strict';

const flash = require('../helpers/flash');
const assetRepository = require('../repositories/assetRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const watermarkSettingsRepository = require('../repositories/watermarkSettingsRepository');

/**
 * Port of App\Controllers\WatermarkController — configures the two global
 * watermarks documentGenerationService applies to every generated PDF:
 * draft (shown until a document is fully approved) and final (shown
 * after). Each independently supports text-only, image-only, or both at
 * once (mode column) — they're two separate overlay layers, not mutually
 * exclusive. The watermark image itself is uploaded from the existing
 * Company Assets screen (asset_type = 'watermark') rather than
 * duplicating an upload form here.
 */

async function index(req, res) {
  res.renderView(
    'watermarks/index',
    {
      sections: {
        draft: await watermarkSettingsRepository.findGlobal(true),
        final: await watermarkSettingsRepository.findGlobal(false),
      },
      watermarkImageAsset: await assetRepository.findActiveByType('watermark'),
    },
    'layout/base'
  );
}

async function update(req, res) {
  const which = String(req.params.which || '');
  if (which !== 'draft' && which !== 'final') {
    res.status(404).end();
    return;
  }
  const isDraftMode = which === 'draft';

  const mode = String(req.body.mode || 'text');
  if (!['text', 'image', 'both'].includes(mode)) {
    flash.set(req, 'error', 'Invalid watermark mode.');
    res.redirect('/watermarks');
    return;
  }

  const data = {
    mode,
    text_content: String(req.body.text_content || '').trim() || null,
    font: String(req.body.font || '').trim() || null,
    font_size: parseInt(req.body.font_size, 10) || 60,
    color: String(req.body.color || '').trim() || '#CCCCCC',
    opacity: parseFloat(req.body.opacity) || 0.3,
    angle: parseInt(req.body.angle, 10) || 45,
    image_opacity: parseFloat(req.body.image_opacity) || 0.15,
    image_position: String(req.body.image_position || 'center').trim(),
  };

  if (mode === 'image' || mode === 'both') {
    const watermarkAsset = await assetRepository.findActiveByType('watermark');
    if (!watermarkAsset) {
      flash.set(req, 'error', 'No watermark image is on file yet — upload one from Company Assets first, then come back and pick Image or Both here.');
      res.redirect('/watermarks');
      return;
    }
    data.image_asset_id = watermarkAsset.id;
  } else {
    data.image_asset_id = null;
  }

  await watermarkSettingsRepository.upsertGlobal(isDraftMode, data, req.user.id);
  await auditLogRepository.log(req.user.id, 'WATERMARK_SETTINGS_UPDATED', 'watermark_settings', null, 'mode', null, mode, `${which} watermark`);
  flash.set(req, 'success', `${which.charAt(0).toUpperCase()}${which.slice(1)} watermark updated.`);
  res.redirect('/watermarks');
}

module.exports = { index, update };
