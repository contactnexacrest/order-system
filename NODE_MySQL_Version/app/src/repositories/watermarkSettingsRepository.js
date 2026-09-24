'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\WatermarkSettingsRepository. The two global
 * watermark rows (draft / final) documentGenerationService reads from.
 * Scoped to 'global' rows only; document-type/document-specific overrides
 * exist in the schema but have never been wired into the generation
 * service, so this CRUD deliberately doesn't expose them either — no UI
 * for a setting nothing reads yet.
 */

async function findGlobal(isDraftMode) {
  return db.queryOne(
    "SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = :draft LIMIT 1",
    { draft: isDraftMode ? 1 : 0 }
  );
}

async function upsertGlobal(isDraftMode, data, updatedBy) {
  const existing = await findGlobal(isDraftMode);
  if (existing) {
    await db.execute(
      `UPDATE watermark_settings SET mode = :mode, text_content = :text_content, font = :font,
          font_size = :font_size, color = :color, opacity = :opacity, angle = :angle,
          image_asset_id = :image_asset_id, image_opacity = :image_opacity, image_position = :image_position,
          created_by = :created_by
       WHERE id = :id`,
      Object.assign({}, data, { id: existing.id, created_by: updatedBy })
    );
    return;
  }
  await db.execute(
    `INSERT INTO watermark_settings (scope, is_draft_mode, mode, text_content, font, font_size, color, opacity, angle, image_asset_id, image_opacity, image_position, created_by)
     VALUES ('global', :is_draft_mode, :mode, :text_content, :font, :font_size, :color, :opacity, :angle, :image_asset_id, :image_opacity, :image_position, :created_by)`,
    Object.assign({}, data, { is_draft_mode: isDraftMode ? 1 : 0, created_by: updatedBy })
  );
}

module.exports = { findGlobal, upsertGlobal };
