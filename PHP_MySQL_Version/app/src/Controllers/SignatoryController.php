<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\SignatoryRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Admin screen for the signatory/designation system: designations master
 * list, marking users signatory-eligible, uploading their personal
 * signature and designation-seal images, and the global + per-document-
 * type default signatory configuration that
 * DocumentDataAssembler::signatoryBlock() resolves against at generation
 * time. The company seal itself is NOT managed here — it stays a single
 * shared asset on the /company-assets screen, unchanged.
 */
final class SignatoryController
{
    private const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function index(array $params): void
    {
        $users = SignatoryRepository::usersWithSignatoryInfo();
        $userAssets = [];
        foreach ($users as $u) {
            if ($u['is_signatory_eligible']) {
                $userAssets[$u['id']] = SignatoryRepository::assetsForUser((int) $u['id']);
            }
        }
        View::render('signatories/index', [
            'designations' => SignatoryRepository::designations(),
            'users' => $users,
            'userAssets' => $userAssets,
            'eligible' => SignatoryRepository::eligibleSignatories(),
            'globalDefaultUserId' => SignatoryRepository::globalDefaultSignatoryUserId(),
            'documentTypeSignatories' => SignatoryRepository::documentTypeSignatories(),
        ], 'layout/base');
    }

    public function createDesignation(array $params): void
    {
        $user = AuthService::currentUser();
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            Flash::set('error', 'Designation title is required.');
            header('Location: /signatories');
            return;
        }
        SignatoryRepository::createDesignation($title, (int) $user['id']);
        Flash::set('success', "Designation \"{$title}\" added.");
        header('Location: /signatories');
    }

    public function toggleDesignation(array $params): void
    {
        SignatoryRepository::toggleDesignationActive((int) ($params['id'] ?? 0));
        Flash::set('success', 'Designation updated.');
        header('Location: /signatories');
    }

    public function setEligibility(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) ($params['id'] ?? 0);
        $eligible = ($_POST['eligible'] ?? '0') === '1';
        $designationId = ($_POST['designation_id'] ?? '') !== '' ? (int) $_POST['designation_id'] : null;

        $target = UserRepository::findById($userId);
        if ($target && (int) $target['is_protected_account'] === 1) {
            Flash::set('error', 'This is a protected founder account — their signatory eligibility and designation can never be changed through the application.');
            header('Location: /signatories');
            return;
        }

        if ($eligible && $designationId === null) {
            Flash::set('error', 'Pick a designation before marking this person signatory-eligible.');
            header('Location: /signatories');
            return;
        }

        SignatoryRepository::setEligibility($userId, $eligible, $designationId, (int) $user['id']);
        Flash::set('success', 'Signatory eligibility updated.');
        header('Location: /signatories');
    }

    public function uploadUserAsset(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) ($params['id'] ?? 0);
        $kind = (string) ($_POST['asset_kind'] ?? '');
        $label = trim((string) ($_POST['label'] ?? '')) ?: 'Default';

        if (!in_array($kind, ['signature', 'designation_seal'], true)) {
            Flash::set('error', 'Unknown asset kind.');
            header('Location: /signatories');
            return;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'No file was uploaded, or the upload failed.');
            header('Location: /signatories');
            return;
        }

        $file = $_FILES['file'];
        if ($file['size'] > self::MAX_BYTES) {
            Flash::set('error', 'File too large (max 5MB).');
            header('Location: /signatories');
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            Flash::set('error', 'Unsupported file type. Use PNG, JPG, WEBP, or SVG.');
            header('Location: /signatories');
            return;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $folder = $kind === 'signature' ? 'signatures' : 'designation_seals';
        $storageBase = Env::get('STORAGE_BASE_PATH', dirname(__DIR__, 3) . '/storage');
        $targetDir = $storageBase . '/assets/' . $folder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = $kind . '_user' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Flash::set('error', 'Could not save the uploaded file.');
            header('Location: /signatories');
            return;
        }

        SignatoryRepository::addUserAsset($userId, $kind, $label, $targetPath, $mime, (int) $user['id']);
        Flash::set('success', ucfirst(str_replace('_', ' ', $kind)) . ' uploaded.');
        header('Location: /signatories');
    }

    public function deactivateUserAsset(array $params): void
    {
        SignatoryRepository::deactivateUserAsset((int) ($params['id'] ?? 0));
        Flash::set('success', 'Asset removed.');
        header('Location: /signatories');
    }

    public function setGlobalDefault(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            Flash::set('error', 'Pick a signatory.');
            header('Location: /signatories');
            return;
        }
        SignatoryRepository::setGlobalDefaultSignatory($userId, (int) $user['id']);
        Flash::set('success', 'Global default signatory updated.');
        header('Location: /signatories');
    }

    public function setDocumentTypeDefault(array $params): void
    {
        $user = AuthService::currentUser();
        $documentTypeId = (int) ($params['id'] ?? 0);
        $userId = ($_POST['user_id'] ?? '') !== '' ? (int) $_POST['user_id'] : null;
        $useDesignationSeal = ($_POST['use_designation_seal'] ?? '0') === '1';

        SignatoryRepository::setDocumentTypeSignatory($documentTypeId, $userId, $useDesignationSeal, (int) $user['id']);
        Flash::set('success', 'Document-type signatory default updated.');
        header('Location: /signatories');
    }
}
