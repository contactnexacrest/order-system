<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssetRepository;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;

final class AssetController
{
    private const ALLOWED_TYPES = ['logo', 'signature', 'seal', 'watermark', 'email_header'];
    private const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB

    public function index(array $params): void
    {
        $assets = AssetRepository::all();
        $active = [];
        foreach ($assets as $a) {
            if ($a['is_active']) {
                $active[$a['asset_type']][] = $a;
            }
        }
        View::render('assets/index', ['assets' => $assets, 'active' => $active], 'layout/base');
    }

    /**
     * Streams the current active file for an asset type. Storage lives
     * outside web root (see ARCHITECTURE.md section 3), so this is the only
     * way a browser can ever see one of these files — there is no direct
     * URL to storage/assets/*, by design.
     */
    public function preview(array $params): void
    {
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            http_response_code(404);
            return;
        }

        $asset = AssetRepository::findActiveByType($type);
        if (!$asset || !is_file($asset['server_path'])) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . ($asset['mime_type'] ?: 'application/octet-stream'));
        header('Cache-Control: private, max-age=60');
        readfile($asset['server_path']);
    }

    public function replace(array $params): void
    {
        $user = AuthService::currentUser();
        $assetType = (string) ($_POST['asset_type'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));

        if (!in_array($assetType, self::ALLOWED_TYPES, true)) {
            Flash::set('error', 'Unknown asset type.');
            header('Location: /assets');
            return;
        }
        if ($name === '') {
            $name = ucfirst(str_replace('_', ' ', $assetType));
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'No file was uploaded, or the upload failed.');
            header('Location: /assets');
            return;
        }

        $file = $_FILES['file'];
        if ($file['size'] > self::MAX_BYTES) {
            Flash::set('error', 'File too large (max 5MB).');
            header('Location: /assets');
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            Flash::set('error', 'Unsupported file type. Use PNG, JPG, or SVG.');
            header('Location: /assets');
            return;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $folder = self::folderFor($assetType);
        $storageBase = Env::get('STORAGE_BASE_PATH', dirname(__DIR__, 3) . '/storage');
        $targetDir = $storageBase . '/assets/' . $folder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = $assetType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Flash::set('error', 'Could not save the uploaded file.');
            header('Location: /assets');
            return;
        }

        $newId = AssetRepository::replace($assetType, $name, $targetPath, $mime, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ASSET_REPLACED', 'assets', $newId, $assetType, null, $targetPath);

        Flash::set('success', ucfirst(str_replace('_', ' ', $assetType)) . ' updated.');
        header('Location: /assets');
    }

    private static function folderFor(string $assetType): string
    {
        return match ($assetType) {
            'logo'          => 'logos',
            'signature'     => 'signatures',
            'seal'          => 'seals',
            'watermark'     => 'watermarks',
            'email_header'  => 'email_headers',
            default         => 'misc',
        };
    }
}
