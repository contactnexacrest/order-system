<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssetRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\WatermarkSettingsRepository;
use App\Services\AuthService;

/**
 * Configures the two global watermarks DocumentGenerationService applies
 * to every generated PDF — draft (shown until a document is fully
 * approved) and final (shown after). Each independently supports
 * text-only, image-only, or both at once (mode column) — they're two
 * separate overlay layers, not mutually exclusive, so "both" really does
 * show the text and the image together. The watermark image itself is
 * uploaded from the existing Company Assets screen (asset_type =
 * 'watermark') rather than duplicating an upload form here.
 */
final class WatermarkController
{
    public function index(array $params): void
    {
        View::render('watermarks/index', [
            'draft' => WatermarkSettingsRepository::findGlobal(true),
            'final' => WatermarkSettingsRepository::findGlobal(false),
            'watermarkImageAsset' => AssetRepository::findActiveByType('watermark'),
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $which = (string) ($params['which'] ?? '');
        if (!in_array($which, ['draft', 'final'], true)) {
            http_response_code(404);
            return;
        }
        $isDraftMode = $which === 'draft';

        $mode = (string) ($_POST['mode'] ?? 'text');
        if (!in_array($mode, ['text', 'image', 'both'], true)) {
            Flash::set('error', 'Invalid watermark mode.');
            header('Location: /watermarks');
            return;
        }

        $data = [
            'mode' => $mode,
            'text_content' => trim((string) ($_POST['text_content'] ?? '')) ?: null,
            'font' => trim((string) ($_POST['font'] ?? '')) ?: null,
            'font_size' => (int) ($_POST['font_size'] ?? 60) ?: null,
            'color' => trim((string) ($_POST['color'] ?? '')) ?: '#CCCCCC',
            'opacity' => (float) ($_POST['opacity'] ?? 0.3),
            'angle' => (int) ($_POST['angle'] ?? 45),
            'image_opacity' => (float) ($_POST['image_opacity'] ?? 0.15),
            'image_position' => trim((string) ($_POST['image_position'] ?? 'center')),
        ];

        if ($mode === 'image' || $mode === 'both') {
            $watermarkAsset = AssetRepository::findActiveByType('watermark');
            if (!$watermarkAsset) {
                Flash::set('error', 'No watermark image is on file yet — upload one from Company Assets first, then come back and pick Image or Both here.');
                header('Location: /watermarks');
                return;
            }
            $data['image_asset_id'] = (int) $watermarkAsset['id'];
        } else {
            $data['image_asset_id'] = null;
        }

        $user = AuthService::currentUser();
        WatermarkSettingsRepository::upsertGlobal($isDraftMode, $data, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'WATERMARK_SETTINGS_UPDATED', 'watermark_settings', null, 'mode', null, $mode, "{$which} watermark");
        Flash::set('success', ucfirst($which) . ' watermark updated.');
        header('Location: /watermarks');
    }
}
