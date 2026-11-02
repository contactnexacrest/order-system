<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AgreementRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\LookupRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\PermissionService;
use App\Services\UiTemplateService;

/**
 * Agreements register (owner decision 2 Nov 2026, point 4). Standard list and Kanban board by
 * status with the same filters, links and actions; add / edit / renew / terminate; signed copy.
 * Staff agreements are seen only by holders of manage_agreements. Node twin: agreementController.js.
 */
final class AgreementController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    public static function can(string $key): bool
    {
        $u = self::user();
        return $u !== [] && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    /** @return array{status:string,party:string,type:string,within:string,q:string} */
    public static function filters(): array
    {
        $status = (string) ($_GET['status'] ?? 'all');
        $party = (string) ($_GET['party'] ?? '');
        $within = (string) ($_GET['within'] ?? '');
        return [
            'status' => $status === 'all' || array_key_exists($status, AgreementRepository::STATUSES) ? $status : 'all',
            'party' => array_key_exists($party, AgreementRepository::PARTY_TYPES) ? $party : '',
            'type' => mb_substr(trim((string) ($_GET['type'] ?? '')), 0, 100),
            'within' => in_array($within, ['7', '30', '60', '90'], true) ? $within : '',
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function applyFilters(array $rows, array $f): array
    {
        return array_values(array_filter($rows, static function (array $r) use ($f): bool {
            if ($f['status'] !== 'all' && $r['status'] !== $f['status']) {
                return false;
            }
            if ($f['party'] !== '' && $r['party_type'] !== $f['party']) {
                return false;
            }
            if ($f['type'] !== '' && $r['agreement_type'] !== $f['type']) {
                return false;
            }
            if ($f['within'] !== '' && ($r['days_left'] === null || $r['days_left'] < 0 || $r['days_left'] > (int) $f['within'] || in_array($r['status'], ['terminated', 'renewed'], true))) {
                return false;
            }
            return $f['q'] === '' || stripos($r['party_name'] . ' ' . $r['agreement_type'] . ' ' . ($r['title'] ?? '') . ' ' . ($r['notes'] ?? ''), $f['q']) !== false;
        }));
    }

    public function index(array $params): void
    {
        $user = self::user();
        $canManage = self::can('manage_agreements');
        $all = AgreementRepository::visibleTo(AgreementRepository::all(), $canManage);
        $f = self::filters();
        $counts = array_fill_keys(array_keys(AgreementRepository::STATUSES), 0);
        foreach ($all as $r) {
            $counts[$r['status']]++;
        }
        usort($all, static fn (array $a, array $b): int => [($a['expires_on'] ?? '9999-12-31'), $a['party_name']] <=> [($b['expires_on'] ?? '9999-12-31'), $b['party_name']]);
        $data = [
            'rows' => self::applyFilters($all, $f),
            'f' => $f,
            'counts' => $counts,
            'types' => array_values(array_unique(array_merge(array_column(LookupRepository::dropdownOptions('agreement_type'), 'option_value'), array_column($all, 'agreement_type')))),
            'canManage' => $canManage,
            'canClientFile' => self::can('manage_orders'),
            'canAssociateFile' => self::can('manage_associates') && self::can('manage_incentives'),
            'kanbanUser' => UiTemplateService::resolve($user['ui_template_preference_id'] ?? null) === 'kanban',
        ];
        if (UiTemplateService::showsBoard($user['ui_template_preference_id'] ?? null)) {
            View::render('agreements/index_kanban', $data, 'layout/base');
            return;
        }
        View::render('agreements/index', $data, 'layout/base');
    }

    public function create(array $params): void
    {
        $prefill = [
            'party_type' => (string) ($_GET['party_type'] ?? ''),
            'party_id' => (int) ($_GET['party_id'] ?? 0),
        ];
        $renews = null;
        if (!empty($_GET['renew'])) {
            $renews = AgreementRepository::find((int) $_GET['renew']);
            if ($renews !== null) {
                $prefill = ['party_type' => $renews['party_type'], 'party_id' => (int) $renews['party_id'], 'agreement_type' => $renews['agreement_type'], 'title' => $renews['title'], 'starts_on' => $renews['expires_on']];
            }
        }
        self::form(null, $prefill, $renews);
    }

    public function edit(array $params): void
    {
        $a = AgreementRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Agreement not found.';
            return;
        }
        self::form($a, $a, null);
    }

    private static function form(?array $agreement, array $values, ?array $renews): void
    {
        View::render('agreements/form', [
            'agreement' => $agreement,
            'v' => $values,
            'renews' => $renews,
            'parties' => AgreementRepository::partyOptions(),
            'types' => array_column(LookupRepository::dropdownOptions('agreement_type'), 'option_value'),
            'partyName' => $agreement !== null ? AgreementRepository::partyName((string) $agreement['party_type'], (int) $agreement['party_id']) : null,
        ], 'layout/base');
    }

    /** @return array{0:array<string,mixed>,1:list<string>} the cleaned form and its errors */
    public static function validate(array $post, bool $isNew): array
    {
        $clean = static fn (string $k, int $max): ?string => ($v = trim((string) ($post[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $data = [
            'party_type' => (string) ($post['party_type'] ?? ''),
            'party_id' => 0,
            'agreement_type' => LookupRepository::sanitizeOption('agreement_type', trim((string) ($post['agreement_type'] ?? ''))),
            'title' => $clean('title', 200),
            'signed_on' => $clean('signed_on', 10),
            'starts_on' => $clean('starts_on', 10),
            'expires_on' => $clean('expires_on', 10),
            'notes' => $clean('notes', 2000),
            'renews_agreement_id' => !empty($post['renews_agreement_id']) && ctype_digit((string) $post['renews_agreement_id']) ? (int) $post['renews_agreement_id'] : null,
        ];
        $errors = [];
        if ($isNew) {
            if (!array_key_exists($data['party_type'], AgreementRepository::PARTY_TYPES)) {
                $errors[] = 'Choose who the agreement is with (client, supplier, logistics partner, associate or staff member).';
            } else {
                $raw = (string) ($post['party_id_' . $data['party_type']] ?? ($post['party_id'] ?? ''));
                $data['party_id'] = ctype_digit($raw) ? (int) $raw : 0;
                if ($data['party_id'] <= 0 || AgreementRepository::partyName($data['party_type'], $data['party_id']) === null) {
                    $errors[] = 'Choose the ' . strtolower(AgreementRepository::PARTY_TYPES[$data['party_type']]) . ' from the list.';
                }
            }
        }
        if ($data['agreement_type'] === '') {
            $errors[] = 'Choose the type of agreement.';
        }
        foreach (['signed_on' => 'signing date', 'starts_on' => 'start date', 'expires_on' => 'expiry date'] as $k => $label) {
            if ($data[$k] !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$k]) || !checkdate((int) substr($data[$k], 5, 2), (int) substr($data[$k], 8, 2), (int) substr($data[$k], 0, 4)))) {
                $errors[] = "Enter the {$label} as a date.";
                $data[$k] = null;
            }
        }
        if ($data['signed_on'] === null) {
            $errors[] = 'Enter the date the agreement was signed.';
        } elseif ($data['signed_on'] > date('Y-m-d')) {
            $errors[] = 'The signing date cannot be in the future.';
        }
        if ($data['expires_on'] !== null && $data['expires_on'] <= ($data['starts_on'] ?? $data['signed_on'] ?? '0000-00-00')) {
            $errors[] = 'The agreement must expire after it starts (or after it was signed). Leave the expiry date empty if it never expires.';
        }
        return [$data, $errors];
    }

    public function save(array $params): void
    {
        $user = self::user();
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $existing = $id !== null ? AgreementRepository::find($id) : null;
        if ($id !== null && $existing === null) {
            http_response_code(404);
            echo 'Agreement not found.';
            return;
        }
        [$data, $errors] = self::validate($_POST, $existing === null);
        if ($existing !== null) {
            $data['party_type'] = $existing['party_type'];
            $data['party_id'] = (int) $existing['party_id'];
        }
        $hasUpload = !empty($_FILES['agreement_file']['name']);
        if ($existing === null && !$hasUpload) {
            $errors[] = 'Attach the signed copy of the agreement (PDF, Word or a scan).';
        }
        $back = $existing !== null ? "/agreements/{$id}/edit" : '/agreements/new' . ($data['renews_agreement_id'] ? '?renew=' . $data['renews_agreement_id'] : '');
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: ' . $back);
            return;
        }
        if ($hasUpload) {
            try {
                $data['file_id'] = FileUploadService::handleUpload('agreement_file', 'agreement_file', 'agreements/' . $data['party_type'], $data['party_type'] === 'client' ? $data['party_id'] : null, null, (int) $user['id'], null, AgreementRepository::partyName($data['party_type'], $data['party_id']), 'Signed agreement — ' . $data['agreement_type']);
            } catch (\Throwable $e) {
                Flash::setErrorFromException($e);
                header('Location: ' . $back);
                return;
            }
        }
        if ($existing === null) {
            $id = AgreementRepository::create($data, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], $data['renews_agreement_id'] ? 'AGREEMENT_RENEWED' : 'AGREEMENT_ADDED', 'agreements', $id, null, $data['renews_agreement_id'] ? (string) $data['renews_agreement_id'] : null, $data['agreement_type'] . ' — ' . AgreementRepository::partyName($data['party_type'], $data['party_id']));
            Flash::set('success', 'Agreement saved. A reminder goes out 30 and 7 days before it expires, and on the day.');
        } else {
            AgreementRepository::update($id, $data);
            AuditLogRepository::log((int) $user['id'], 'AGREEMENT_UPDATED', 'agreements', $id, null, null, (string) json_encode(array_intersect_key($data, array_flip(['agreement_type', 'title', 'signed_on', 'starts_on', 'expires_on', 'file_id']))));
            Flash::set('success', 'Agreement updated.');
        }
        header('Location: /agreements?party=' . $data['party_type'] . '&q=' . rawurlencode((string) AgreementRepository::partyName($data['party_type'], $data['party_id'])));
    }

    public function terminate(array $params): void
    {
        $user = self::user();
        $a = AgreementRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Agreement not found.';
            return;
        }
        $on = trim((string) ($_POST['terminated_on'] ?? ''));
        $reason = trim((string) ($_POST['terminated_reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            Flash::set('error', 'Enter the date the agreement ended and a reason (5 to 500 characters).');
            header('Location: /agreements/' . (int) $a['id'] . '/edit');
            return;
        }
        AgreementRepository::terminate((int) $a['id'], $on, $reason);
        AuditLogRepository::log((int) $user['id'], 'AGREEMENT_TERMINATED', 'agreements', (int) $a['id'], 'terminated_on', null, $on, $reason);
        Flash::set('success', 'Agreement recorded as ended. No more reminders go out for it.');
        header('Location: /agreements');
    }

    public function download(array $params): void
    {
        $a = AgreementRepository::find((int) ($params['id'] ?? 0));
        if ($a === null || ($a['party_type'] === 'staff' && !self::can('manage_agreements'))) {
            http_response_code($a === null ? 404 : 403);
            echo $a === null ? 'Agreement not found.' : 'You do not have permission to view this agreement.';
            return;
        }
        $file = !empty($a['file_id']) ? FileStoreRepository::find((int) $a['file_id']) : null;
        if ($file === null || !is_file((string) $file['server_path'])) {
            http_response_code(404);
            echo 'No signed copy is on file for this agreement.';
            return;
        }
        AuditLogRepository::log((int) self::user()['id'], 'AGREEMENT_DOWNLOADED', 'agreements', (int) $a['id'], null, null, (string) $file['original_filename']);
        AssociateAdminController::sendFile((string) $file['server_path'], (string) $file['original_filename'], (string) ($file['mime_type'] ?: 'application/octet-stream'));
    }
}
