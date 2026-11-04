<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\LookupRepository;
use App\Repositories\UserRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\IncentiveService;
use App\Services\PermissionService;
use App\Services\UiTemplateService;

/**
 * Owner decision 1 Nov 2026 ("AS all as recommended"): the staff side of
 * the associate system — the register (Standard list and Kanban board,
 * kept parallel), each associate's page, their rates, portal access and
 * personal code, client links (changed only by the MD, with a reason),
 * bank details (two different Accounts people verify a change), documents,
 * messages, payouts on hold and claw-backs, the global ladder and the
 * associates report.
 *
 * view_associates (Sales) sees who introduced which client, never amounts,
 * rates, bank details or agreements. manage_associates (MD, ED, Admin)
 * manages everything but the full bank number. manage_associate_bank
 * (Finance, MD, Admin) sees and verifies bank details.
 */
final class AssociateAdminController
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

    private static function back(int $id, string $anchor = ''): void
    {
        header('Location: /associates/' . $id . ($anchor !== '' ? '#' . $anchor : ''));
    }

    private static function found(array $params): ?array
    {
        $a = AssociateRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Associate not found.';
        }
        return $a;
    }

    // ------------------------------------------------------------ register

    /** The register: Standard list or Kanban board by status, with the same filters, links and actions. */
    public function index(array $params): void
    {
        $user = self::user();
        $status = (string) ($_GET['status'] ?? 'all');
        if ($status !== 'all' && !array_key_exists($status, AssociateRepository::STATUSES)) {
            $status = 'all';
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = array_values(array_filter(AssociateRepository::all(), static function (array $a) use ($status, $q): bool {
            return ($status === 'all' || $a['status'] === $status)
                && ($q === '' || stripos($a['name'] . ' ' . ($a['email'] ?? '') . ' ' . ($a['personal_code'] ?? '') . ' ' . ($a['country'] ?? ''), $q) !== false);
        }));
        foreach ($rows as &$r) {
            $r['clients'] = AssociateRepository::clients((int) $r['id']);
            $r['pending_bank'] = (int) Database::connection()->query("SELECT COUNT(*) FROM associate_bank_accounts WHERE status = 'pending' AND associate_id = " . (int) $r['id'])->fetchColumn();
        }
        unset($r);
        $data = [
            'rows' => $rows,
            'status' => $status,
            'q' => $q,
            'counts' => AssociateRepository::statusCounts(),
            'canManage' => self::can('manage_associates'),
            'canBank' => self::can('manage_associate_bank'),
            'pendingBank' => AssociateBankRepository::pendingCount(),
            'kanbanUser' => UiTemplateService::resolve($user['ui_template_preference_id'] ?? null) === 'kanban',
        ];
        if (UiTemplateService::showsBoard($user['ui_template_preference_id'] ?? null)) {
            View::render('associates/index_kanban', $data, 'layout/base');
            return;
        }
        View::render('associates/index', $data, 'layout/base');
    }

    public function create(array $params): void
    {
        self::form(null);
    }

    public function edit(array $params): void
    {
        $a = self::found($params);
        if ($a !== null) {
            self::form($a);
        }
    }

    private static function form(?array $a): void
    {
        View::render('associates/form', [
            'editing' => $a,
            'currencies' => LookupRepository::currencies(),
            'users' => UserRepository::listActive(),
            // Owner decision D-245 (4 Nov 2026): commission level (fixed) or the ladder — MD only.
            'commissionLevels' => \App\Repositories\PricingRepository::levels(),
            'canPricing' => self::can('manage_pricing'),
        ], 'layout/base');
    }

    /**
     * Owner decision D-245: the associate's commission mode and level, read only for
     * people with manage_pricing (everyone else keeps what is stored).
     *
     * @return array{0: ?array{mode:string,level:?int}, 1: ?string}
     */
    private static function readCommissionLevel(?array $existing): array
    {
        if (!self::can('manage_pricing')) {
            return [null, null];
        }
        $mode = (string) ($_POST['commission_mode'] ?? '');
        if (!in_array($mode, ['fixed', 'ladder'], true)) {
            return [null, 'Choose whether this associate has a fixed commission level or follows the ladder.'];
        }
        $level = null;
        if ($mode === 'fixed') {
            $l = \App\Repositories\PricingRepository::findLevel((int) ($_POST['commission_level_id'] ?? 0));
            $keeping = $l !== null && (int) ($existing['commission_level_id'] ?? 0) === (int) $l['id'];
            if ($l === null || ((int) $l['is_active'] !== 1 && !$keeping)) {
                return [null, 'Choose an active commission level for a fixed level.'];
            }
            $level = (int) $l['id'];
        }
        return [['mode' => $mode, 'level' => $level], null];
    }

    public function save(array $params): void
    {
        $user = self::user();
        $id = isset($params['id']) ? (int) $params['id'] : null;
        if ($id !== null && \App\Helpers\RecordCheck::missing('associates', $id, '/associates', 'associate')) {
            return;
        }
        $existing = $id !== null ? AssociateRepository::find($id) : null;
        $data = [];
        foreach (AssociateRepository::FIELDS as $f) {
            $v = trim((string) ($_POST[$f] ?? ''));
            $data[$f] = $v === '' ? null : $v;
        }
        $data['no_commission'] = !empty($_POST['no_commission']) ? 1 : 0;
        $data['pan'] = $data['pan'] !== null ? strtoupper((string) $data['pan']) : null;
        $data['gstin'] = $data['gstin'] !== null ? strtoupper((string) $data['gstin']) : null;
        // The status changes only through "Change status" (with a reason); a new associate starts as Invited.
        $data['status'] = $existing['status'] ?? 'invited';
        $errors = [];
        if ($data['name'] === null || mb_strlen((string) $data['name']) > 150) {
            $errors[] = 'Enter the associate\'s name (up to 150 characters).';
        }
        if (!in_array($data['based_in'], ['india', 'abroad'], true)) {
            $errors[] = 'Choose whether the associate is in India or abroad.';
        }
        if (!preg_match('/^[A-Z]{3}$/', (string) $data['currency_code'])) {
            $errors[] = 'Choose the currency the associate is paid in.';
        }
        if ($data['email'] !== null && (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen((string) $data['email']) > 190)) {
            $errors[] = 'Enter a valid email address, or leave it empty.';
        } elseif ($data['email'] !== null && ($other = AssociateRepository::findByEmail((string) $data['email'])) !== null && (int) $other['id'] !== (int) $id) {
            $errors[] = 'Another associate already uses this e-mail — it is their portal login.';
        }
        foreach (['agreement_signed_on', 'agreement_ends_on'] as $d) {
            if ($data[$d] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data[$d])) {
                $errors[] = 'Enter the agreement dates as dates.';
                break;
            }
        }
        if ($data['agreement_signed_on'] !== null && $data['agreement_ends_on'] !== null && $data['agreement_ends_on'] <= $data['agreement_signed_on']) {
            $errors[] = 'The agreement must end after it was signed.';
        }
        if ($data['pan'] !== null && $data['based_in'] === 'india' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', (string) $data['pan'])) {
            $errors[] = 'Enter the PAN as on the card (e.g. ABCDE1234F).';
        }
        if ($data['gstin'] !== null && !preg_match('/^\d{2}[A-Z0-9]{13}$/', (string) $data['gstin'])) {
            $errors[] = 'Enter the 15-character GSTIN, or leave it empty.';
        }
        if ($data['relationship_manager_id'] !== null && (!ctype_digit((string) $data['relationship_manager_id']) || UserRepository::findById((int) $data['relationship_manager_id']) === null)) {
            $errors[] = 'Choose who the associate reports to from the list.';
        }
        foreach (['country' => 80, 'phone' => 40, 'tax_id' => 50, 'notes' => 500, 'address' => 1000] as $f => $max) {
            if ($data[$f] !== null && mb_strlen((string) $data[$f]) > $max) {
                $errors[] = "The {$f} is too long (up to {$max} characters).";
            }
        }
        // Owner decision 2026-10-30: the signed agreement is kept with its date.
        $hasUpload = !empty($_FILES['agreement_file']['name']);
        if ($data['agreement_signed_on'] !== null && !$hasUpload && empty($existing['agreement_file_id'])) {
            $errors[] = 'Attach the signed agreement (PDF) with its signing date.';
        }
        if (($hasUpload || !empty($existing['agreement_file_id'])) && $data['agreement_signed_on'] === null) {
            $errors[] = 'Enter the date the agreement was signed with its signed copy.';
        }
        [$commission, $commissionError] = self::readCommissionLevel($existing);
        if ($commissionError !== null) {
            $errors[] = $commissionError;
        }
        $back = $id ? "/associates/{$id}/edit" : '/associates/new';
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: ' . $back);
            return;
        }
        $fileId = null;
        if ($hasUpload) {
            try {
                $fileId = FileUploadService::handleUpload('agreement_file', 'associate_agreement', 'associates/agreements', null, null, (int) $user['id'], null, (string) $data['name'], 'Signed associate agreement');
            } catch (\Throwable $e) {
                Flash::setErrorFromException($e);
                header('Location: ' . $back);
                return;
            }
        }
        if ($id === null) {
            $id = AssociateRepository::create($data, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_ADDED', 'associates', $id, null, null, (string) $data['name']);
            AssociateEventRepository::add($id, 'registered', 'Associate registered by ' . $user['name'] . '.', null, null, null, (int) $user['id'], false);
            Flash::set('success', "Associate \"{$data['name']}\" added. Next: upload their KYC, have Accounts verify their bank, then send portal access and set them Active.");
        } else {
            AssociateRepository::update($id, $data);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_UPDATED', 'associates', $id, null, null, (string) json_encode($data));
            $changed = [];
            if ((int) ($existing['no_commission'] ?? 0) !== (int) $data['no_commission']) {
                $changed[] = 'no_commission';
            }
            if ($changed !== []) {
                AssociateEventRepository::add($id, 'rates_changed', 'Commission terms changed by ' . $user['name'] . ' — applies to new quotes; orders with a Proforma Invoice keep the level fixed on them.', null, null, null, (int) $user['id'], true);
            }
            Flash::set('success', "Associate \"{$data['name']}\" saved.");
        }
        if ($commission !== null) {
            $before = ($existing['commission_mode'] ?? 'ladder') . ':' . ($existing['commission_level_id'] ?? '');
            $after = $commission['mode'] . ':' . ($commission['level'] ?? '');
            \App\Repositories\PricingRepository::setAssociateCommission($id, $commission['mode'], $commission['level']);
            if ($existing === null || $before !== $after) {
                $describe = static function (string $mode, $levelId): string {
                    if ($mode !== 'fixed') {
                        return 'Ladder';
                    }
                    $l = \App\Repositories\PricingRepository::findLevel((int) $levelId);
                    return 'Fixed: ' . ($l === null ? '?' : $l['name'] . ' — ' . \App\Services\PricingService::levelLabel($l));
                };
                $old = $existing === null ? null : $describe((string) ($existing['commission_mode'] ?? 'ladder'), $existing['commission_level_id'] ?? null);
                $new = $describe($commission['mode'], $commission['level']);
                AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_COMMISSION_LEVEL_CHANGED', 'associates', $id, 'commission_level', $old, $new);
                if ($existing !== null) {
                    AssociateEventRepository::add($id, 'rates_changed', 'Commission level changed by ' . $user['name'] . " to {$new} — applies to new quotes only.", null, null, null, (int) $user['id'], false);
                }
            }
        }
        if ($fileId !== null) {
            AssociateRepository::setAgreementFile($id, $fileId);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_AGREEMENT_UPLOADED', 'associates', $id, 'agreement_file_id', isset($existing['agreement_file_id']) ? (string) $existing['agreement_file_id'] : null, (string) $fileId);
        }
        header('Location: /associates/' . $id);
    }

    /** One associate: profile, rates, portal, clients, invitations, bank, documents, commission, history, messages. */
    public function show(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $id = (int) $a['id'];
        $canManage = self::can('manage_associates');
        $canBank = self::can('manage_associate_bank');
        $data = [
            'a' => $a,
            'canManage' => $canManage,
            'canBank' => $canBank,
            'canChangeLink' => AssociateService::canChangeLink(self::user()),
            'clients' => AssociatePortalRepository::clients($id),
            'me' => self::user(),
        ];
        if ($canManage || $canBank) {
            $data += [
                'orders' => $canManage ? AssociatePortalRepository::orders($id) : [],
                'payouts' => $canManage ? AssociatePortalRepository::payouts($id) : [],
                'invitations' => AssociateInvitationRepository::forAssociate($id),
                'banks' => AssociateBankRepository::forAssociate($id),
                'usableBank' => AssociateBankRepository::usable($id),
                'documents' => AssociateDocumentRepository::forAssociate($id),
                'events' => AssociateEventRepository::forAssociate($id, null, 200),
                'messages' => AssociateEventRepository::messages($id),
                'unlinkedClients' => AssociateRepository::unlinkedClients(),
                // D-245 P3: commission level or ladder, counting orders so far, when commission is paid.
                'commissionNow' => \App\Services\AssociateCommission::levelForAssociate($a, null),
                'commissionSettings' => \App\Services\AssociateCommission::settings(),
                'rm' => !empty($a['relationship_manager_id']) ? UserRepository::findById((int) $a['relationship_manager_id']) : null,
                'codeLink' => AssociatePortalController::codeLink($a),
                'tdsPct' => AssociateService::tdsPct(),
            ];
        }
        View::render('associates/show', $data, 'layout/base');
    }

    // ------------------------------------------------------------ status, portal, code

    public function changeStatus(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $user = self::user();
        $status = (string) ($_POST['status'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!array_key_exists($status, AssociateRepository::STATUSES) || $status === 'inactive') {
            Flash::set('error', 'Choose the new status.');
        } elseif ($status === $a['status']) {
            Flash::set('error', 'The associate already has this status.');
        } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason for the change (10 to 300 characters).');
        } elseif ($status === 'active' && AssociateBankRepository::usable((int) $a['id'], date('Y-m-d', strtotime('+' . AssociateService::bankWaitDays() . ' days'))) === null) {
            Flash::set('error', 'An associate becomes Active once Accounts have verified their bank details (two people).');
        } else {
            AssociateRepository::setFields((int) $a['id'], ['status' => $status, 'status_reason' => $reason]);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_STATUS_CHANGED', 'associates', (int) $a['id'], 'status', (string) $a['status'], $status);
            // AS-3: suspension holds every due payout; reactivation leaves the held ones for the MD to release one by one.
            if ($status === 'suspended') {
                Database::connection()->prepare("UPDATE incentive_payouts SET status = 'held', hold_reason = 'The associate is suspended.' WHERE associate_id = :a AND payee_kind = 'associate' AND status = 'due' AND amount_inr > 0")->execute(['a' => $a['id']]);
            }
            AssociateService::notify($a, 'status_changed', 'Your associate status is now: ' . AssociateRepository::STATUSES[$status] . ". Reason: {$reason}", null, null, null, (int) $user['id']);
            Flash::set('success', 'Status changed to ' . AssociateRepository::STATUSES[$status] . '.');
        }
        self::back((int) $a['id']);
    }

    public function invitePortal(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        try {
            $link = AssociateService::invitePortal((int) $a['id'], (int) self::user()['id']);
            Flash::set('success', 'Portal access e-mailed to ' . $a['email'] . '.' . (AssociatePortalService::showCodeOnScreen() ? " Development only — link: {$link}" : ''));
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }
        self::back((int) $a['id'], 'portal');
    }

    public function disablePortal(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        AssociateRepository::setFields((int) $a['id'], ['portal_enabled' => 0]);
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_PORTAL_DISABLED', 'associates', (int) $a['id']);
        AssociateEventRepository::add((int) $a['id'], 'portal_disabled', 'Portal access switched off by ' . self::user()['name'] . '.', null, null, null, (int) self::user()['id'], false);
        Flash::set('success', 'Portal access switched off. Their personal code and client links stay as they are.');
        self::back((int) $a['id'], 'portal');
    }

    /** A new personal code (e.g. after a leak). The old code no longer brings new clients; existing links stay. */
    public function setCode(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $part = AssociateCodeService::readablePart((string) ($_POST['readable'] ?? ''));
        if (strlen($part) < 3) {
            Flash::set('error', 'Use 3 to 12 letters or digits for the readable part of the code.');
        } else {
            $code = AssociateCodeService::issue((int) $a['id'], $part);
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_CODE_SET', 'associates', (int) $a['id'], 'personal_code', $a['personal_code'] ?? null, $code);
            AssociateService::notify($a, 'code_changed', "Your personal code is now {$code}" . (!empty($a['personal_code']) ? "; {$a['personal_code']} no longer brings new clients (clients already registered stay yours)" : '') . '.', null, null, null, (int) self::user()['id']);
            Flash::set('success', "Personal code set to {$code}.");
        }
        self::back((int) $a['id'], 'portal');
    }

    /** Opens the associate's portal exactly as they see it, read-only (every form post is refused). */
    public function viewAs(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        AssociatePortalService::startViewAs((int) self::user()['id'], (int) $a['id']);
        header('Location: /associate');
    }

    public function downloadAgreement(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $file = !empty($a['agreement_file_id']) ? \App\Repositories\FileStoreRepository::find((int) $a['agreement_file_id']) : null;
        if ($file === null || !is_file((string) $file['server_path'])) {
            http_response_code(404);
            echo 'No signed agreement is on file for this associate.';
            return;
        }
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_AGREEMENT_DOWNLOADED', 'associates', (int) $a['id'], null, null, (string) $file['original_filename']);
        self::sendFile((string) $file['server_path'], (string) $file['original_filename'], 'application/pdf');
    }

    public static function sendFile(string $path, string $name, string $mime): void
    {
        if (!is_file($path)) {
            http_response_code(404);
            echo 'The file is missing from storage.';
            return;
        }
        $name = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/', '-', $name) ?? 'file';
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
        }
        readfile($path);
    }

    // ------------------------------------------------------------ client links (guarantee 3)

    /** A staff link: a client already on file that the associate introduced (manage_associates); the lock starts now. */
    public function linkClient(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $user = self::user();
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $registeredOn = (string) ($_POST['registered_on'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $client = $clientId > 0 ? \App\Repositories\ClientRepository::find($clientId) : null;
        if ($client === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $registeredOn) || $registeredOn > date('Y-m-d')) {
            Flash::set('error', 'Choose the client and the date the associate introduced them (today or earlier).');
        } elseif (!empty($client['associate_id'])) {
            Flash::set('error', 'This client is already linked to an associate — only the MD can change that link.');
        } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Say how the associate introduced this client (10 to 300 characters).');
        } else {
            AssociateService::linkClient($clientId, (int) $a['id'], 'staff', null, $registeredOn, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_CLIENT_LINKED', 'clients', $clientId, 'associate_id', null, (string) $a['id']);
            AssociateService::notify($a, 'client_accepted', "{$client['company_legal_name']} is registered as your client (linked by NexaCrest: {$reason}). Your protection runs until "
                . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+' . AssociateService::protectionMonths() . ' months'))) . '.', $clientId, null, null, (int) $user['id']);
            Flash::set('success', 'Client linked to the associate.');
        }
        self::back((int) $a['id'], 'clients');
    }

    /** Guarantee 3: only the MD removes or moves a client's associate, with a written reason; the associate is told at once. */
    public function unlinkClient(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $user = self::user();
        $clientId = (int) $params['clientId'];
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $moveTo = (int) ($_POST['move_to'] ?? 0);
        if (\App\Helpers\RecordCheck::missing('clients', $clientId, '/associates/' . (int) $a['id'], 'client link', ['associate_id' => (int) $a['id']])) {
            return;
        }
        if (!AssociateService::canChangeLink($user)) {
            http_response_code(403);
            Flash::set('error', 'Only the Managing Director can change or remove a client\'s associate.');
            self::back((int) $a['id'], 'clients');
            return;
        }
        $target = $moveTo > 0 ? AssociateRepository::find($moveTo) : null;
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Write the reason (10 to 300 characters) — the associate is shown it.');
        } elseif ($moveTo > 0 && ($target === null || $moveTo === (int) $a['id'])) {
            Flash::set('error', 'Choose the other associate.');
        } else {
            $client = \App\Repositories\ClientRepository::find($clientId);
            if ($target !== null) {
                AssociateService::linkClient($clientId, (int) $target['id'], 'md_change', null, date('Y-m-d'), (int) $user['id']);
                AssociateService::notify($target, 'link_changed', "{$client['company_legal_name']} is now registered as your client (decided by the Managing Director: {$reason}).", $clientId, null, null, (int) $user['id']);
            } else {
                Database::connection()->prepare('UPDATE clients SET associate_id = NULL, associate_registered_on = NULL, associate_locked_until = NULL, associate_source = NULL, associate_invitation_id = NULL WHERE id = :id')->execute(['id' => $clientId]);
            }
            AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_CLIENT_UNLINKED', 'clients', $clientId, 'associate_id', (string) $a['id'], $target !== null ? (string) $target['id'] : null);
            AssociateService::notify($a, 'link_changed', "{$client['company_legal_name']} is no longer registered as your client" . ($target !== null ? '' : '') . ". Decided by the Managing Director. Reason: {$reason}", $clientId, null, null, (int) $user['id']);
            Flash::set('success', $target !== null ? "Client moved to {$target['name']}; both associates were told." : 'Client unlinked; the associate was told.');
        }
        self::back((int) $a['id'], 'clients');
    }

    // ------------------------------------------------------------ bank (AS-7)

    /**
     * Checks a bank form (associate's request or staff entry).
     * @return string|array<string,mixed> the error, or the cleaned fields
     */
    public static function validateBank(array $associate, array $post): string|array
    {
        $d = [];
        foreach (['holder_name', 'account_number', 'account_number_confirm', 'ifsc', 'swift', 'bank_name', 'bank_branch_address', 'intermediary_bank', 'account_currency'] as $f) {
            $d[$f] = trim((string) ($post[$f] ?? ''));
        }
        $d['account_number'] = strtoupper(preg_replace('/\s+/', '', $d['account_number']) ?? '');
        $d['account_number_confirm'] = strtoupper(preg_replace('/\s+/', '', $d['account_number_confirm']) ?? '');
        $d['ifsc'] = strtoupper($d['ifsc']);
        $d['swift'] = strtoupper($d['swift']);
        $d['account_currency'] = strtoupper($d['account_currency'] ?: (string) ($associate['currency_code'] ?? 'INR'));
        $india = ($associate['based_in'] ?? 'india') === 'india';
        return match (true) {
            mb_strlen($d['holder_name']) < 2 || mb_strlen($d['holder_name']) > 150 => 'Enter the account holder\'s name as the bank has it' . ($india ? ' (as on the PAN)' : '') . '.',
            !preg_match('/^[A-Z0-9]{6,34}$/', $d['account_number']) => $india ? 'Enter the account number (6 to 34 letters or digits).' : 'Enter the IBAN or account number (6 to 34 letters or digits).',
            $d['account_number'] !== $d['account_number_confirm'] => 'The two account numbers do not match — type it again.',
            $india && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $d['ifsc']) => 'Enter the 11-character IFSC (e.g. HDFC0001234).',
            !$india && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $d['swift']) => 'Enter the bank\'s SWIFT / BIC (8 or 11 characters).',
            $d['swift'] !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $d['swift']) => 'Enter the SWIFT / BIC as 8 or 11 characters, or leave it empty.',
            mb_strlen($d['bank_name']) < 2 || mb_strlen($d['bank_name']) > 150 => 'Enter the bank\'s name.',
            !$india && mb_strlen($d['bank_branch_address']) < 5 => 'Enter the bank\'s address (needed for a foreign transfer).',
            mb_strlen($d['bank_branch_address']) > 300 || mb_strlen($d['intermediary_bank']) > 300 => 'The bank address or intermediary bank is too long.',
            !preg_match('/^[A-Z]{3}$/', $d['account_currency']) => 'Choose the account currency.',
            default => array_diff_key($d, ['account_number_confirm' => 1]),
        };
    }

    /** Accounts enter the bank details themselves (from the associate's signed form) — still checked by two people. */
    public function addBank(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $result = self::validateBank($a, $_POST);
        if (is_string($result)) {
            Flash::set('error', $result);
            self::back((int) $a['id'], 'bank');
            return;
        }
        try {
            $result['proof_file_id'] = FileUploadService::handleUpload('proof_file', 'associate_document', 'associates/bank', null, null, (int) self::user()['id'], null, (string) $a['name'], 'Bank proof');
        } catch (\Throwable $e) {
            Flash::set('error', 'Attach a cancelled cheque or a bank letter (PDF or image).');
            self::back((int) $a['id'], 'bank');
            return;
        }
        $id = AssociateBankRepository::request((int) $a['id'], $result, false, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', $id, null, null, 'by staff');
        AssociateService::notify($a, 'bank_changed', "NexaCrest recorded new bank details for you ({$result['bank_name']} ••••" . substr((string) $result['account_number'], -4) . ') on ' . date('j M Y') . '. They are used once checked. '
            . AssociateService::bankPaymentsSentence((int) $a['id']) . ' If you did not send these details, contact NexaCrest at once.', null, null, null, (int) self::user()['id']);
        Flash::set('success', 'Bank details recorded — two people must now check them.');
        self::back((int) $a['id'], 'bank');
    }

    public function firstCheck(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $bank = AssociateBankRepository::find((int) $params['bankId']);
        $method = trim((string) ($_POST['check_method'] ?? ''));
        if ($bank === null || (int) $bank['associate_id'] !== (int) $a['id']) {
            Flash::set('error', 'Bank request not found.');
        } elseif (mb_strlen($method) < 10 || mb_strlen($method) > 300) {
            Flash::set('error', 'Say how you checked it (e.g. "Called the associate on their known number; test credit of ₹1 confirmed") — 10 to 300 characters.');
        } elseif ((int) ($bank['requested_by_user'] ?? 0) === (int) self::user()['id']) {
            Flash::set('error', 'You entered these bank details, so two other people must check them.');
        } elseif (!AssociateBankRepository::firstCheck((int) $bank['id'], (int) self::user()['id'], $method)) {
            Flash::set('error', 'This request was already checked or is no longer pending.');
        } else {
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_BANK_FIRST_CHECK', 'associate_bank_accounts', (int) $bank['id'], null, null, $method);
            Flash::set('success', 'First check recorded. A different person now verifies it.');
        }
        self::back((int) $a['id'], 'bank');
    }

    public function verifyBank(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $bank = AssociateBankRepository::find((int) $params['bankId']);
        $uid = (int) self::user()['id'];
        if ($bank === null || (int) $bank['associate_id'] !== (int) $a['id']) {
            Flash::set('error', 'Bank request not found.');
        } elseif (empty($bank['first_checked_by'])) {
            Flash::set('error', 'Record the first check before verifying.');
        } elseif ((int) $bank['first_checked_by'] === $uid || (int) ($bank['requested_by_user'] ?? 0) === $uid) {
            Flash::set('error', 'A different person must verify — you already acted on this request.');
        } elseif (!AssociateBankRepository::verify((int) $bank['id'], $uid, AssociateService::bankWaitDays())) {
            Flash::set('error', 'This request is no longer pending.');
        } else {
            $b = AssociateBankRepository::find((int) $bank['id']);
            AuditLogRepository::log($uid, 'ASSOCIATE_BANK_VERIFIED', 'associate_bank_accounts', (int) $bank['id'], 'status', 'pending', 'verified');
            AssociateService::notify($a, 'bank_changed', 'Your bank account ' . AssociateBankRepository::masked($b) . ' was verified by NexaCrest. Payments go to it from '
                . \App\Helpers\Dates::short((string) $b['usable_from']) . '. If you did not ask for this change, contact NexaCrest at once.', null, null, null, $uid);
            Flash::set('success', 'Verified. Usable from ' . \App\Helpers\Dates::short((string) $b['usable_from']) . '; the associate was e-mailed.');
        }
        self::back((int) $a['id'], 'bank');
    }

    public function rejectBank(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $bank = AssociateBankRepository::find((int) $params['bankId']);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($bank === null || (int) $bank['associate_id'] !== (int) $a['id']) {
            Flash::set('error', 'Bank request not found.');
        } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason (10 to 300 characters) — the associate is shown it.');
        } elseif (!AssociateBankRepository::reject((int) $bank['id'], $reason)) {
            Flash::set('error', 'This request is no longer pending.');
        } else {
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_BANK_REJECTED', 'associate_bank_accounts', (int) $bank['id'], 'status', 'pending', 'rejected');
            // QA Round 8 (R8-N01): one full stop after the reason; the verified account named only when there is one.
            AssociateService::notify($a, 'bank_rejected', 'Your new bank details (' . AssociateBankRepository::masked($bank) . ') were not accepted: ' . AssociateService::cleanReason($reason) . '. '
                . AssociateService::bankPaymentsSentence((int) $a['id']), null, null, null, (int) self::user()['id']);
            Flash::set('success', 'Bank request rejected; the associate was told.');
        }
        self::back((int) $a['id'], 'bank');
    }

    /** The full account number — Finance / MD only (manage_associate_bank), every view audit-logged. */
    public function bankNumber(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $bank = AssociateBankRepository::find((int) $params['bankId']);
        if ($bank === null || (int) $bank['associate_id'] !== (int) $a['id']) {
            http_response_code(404);
            echo 'Bank account not found.';
            return;
        }
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_BANK_NUMBER_VIEWED', 'associate_bank_accounts', (int) $bank['id']);
        View::render('associates/bank_number', ['a' => $a, 'b' => $bank, 'number' => AssociateBankRepository::fullNumber((int) $bank['id'])], 'layout/base');
    }

    // ------------------------------------------------------------ documents, messages

    public function uploadDocument(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $kind = (string) ($_POST['kind'] ?? '');
        $reference = trim((string) ($_POST['reference'] ?? ''));
        if (!array_key_exists($kind, AssociateDocumentRepository::KINDS)) {
            Flash::set('error', 'Choose what the document is.');
            self::back((int) $a['id'], 'documents');
            return;
        }
        if (mb_strlen($reference) > 100) {
            Flash::set('error', 'The reference is too long (up to 100 characters).');
            self::back((int) $a['id'], 'documents');
            return;
        }
        try {
            $fileId = FileUploadService::handleUpload('document_file', 'associate_document', 'associates/documents', null, null, (int) self::user()['id'], null, (string) $a['name'], AssociateDocumentRepository::KINDS[$kind]);
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
            self::back((int) $a['id'], 'documents');
            return;
        }
        AssociateDocumentRepository::add((int) $a['id'], $kind, $fileId, null, $reference ?: null, false, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', (int) $a['id'], 'kind', null, $kind);
        if (in_array($kind, ['form_16a', 'form_15ca', 'form_15cb'], true)) {
            AssociateService::notify($a, 'document_added', AssociateDocumentRepository::KINDS[$kind] . ($reference !== '' ? " ({$reference})" : '') . ' is in your portal.', null, null, null, (int) self::user()['id']);
        }
        Flash::set('success', AssociateDocumentRepository::KINDS[$kind] . ' saved.');
        self::back((int) $a['id'], 'documents');
    }

    public function downloadDocument(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $doc = AssociateDocumentRepository::find((int) $params['docId']);
        if ($doc === null || (int) $doc['associate_id'] !== (int) $a['id']) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_DOCUMENT_DOWNLOADED', 'associate_documents', (int) $doc['id']);
        self::sendFile((string) $doc['server_path'], (string) $doc['original_filename'], (string) ($doc['mime_type'] ?? 'application/octet-stream'));
    }

    public function reply(array $params): void
    {
        $a = self::found($params);
        if ($a === null) {
            return;
        }
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 4000) {
            Flash::set('error', 'Write the message (up to 4,000 characters).');
        } else {
            AssociateEventRepository::addMessage((int) $a['id'], false, (int) self::user()['id'], $body);
            AssociateService::notify($a, 'message', 'New message from ' . self::user()['name'] . ' (NexaCrest): ' . mb_substr($body, 0, 300), null, null, null, (int) self::user()['id']);
            Flash::set('success', 'Message sent.');
        }
        self::back((int) $a['id'], 'messages');
    }

    // ------------------------------------------------------------ payouts: hold, release, claw-back (AS-3, AS-9)

    private static function associatePayout(array $params): ?array
    {
        $p = IncentiveRepository::findPayout((int) $params['payoutId']);
        if ($p === null || $p['payee_kind'] !== 'associate') {
            http_response_code(404);
            echo 'Payout not found.';
            return null;
        }
        return $p;
    }

    public function holdPayout(array $params): void
    {
        $p = self::associatePayout($params);
        if ($p === null) {
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason for the hold (10 to 300 characters) — the associate is shown it.');
        } elseif (!IncentiveRepository::holdPayout((int) $p['id'], $reason)) {
            Flash::set('error', 'Only a payout waiting to be paid can be put on hold.');
        } else {
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_PAYOUT_HELD', 'incentive_payouts', (int) $p['id'], 'status', 'due', 'held');
            $a = AssociateRepository::find((int) $p['associate_id']);
            AssociateService::notify($a, 'payout_held', "A commission payment was put on hold: {$reason}", null, (int) $p['order_id'], null, (int) self::user()['id']);
            Flash::set('success', 'Payout on hold.');
        }
        self::back((int) $p['associate_id'], 'commission');
    }

    public function releasePayout(array $params): void
    {
        $p = self::associatePayout($params);
        if ($p === null) {
            return;
        }
        if (!IncentiveRepository::releasePayout((int) $p['id'])) {
            Flash::set('error', 'This payout is not on hold.');
        } else {
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_PAYOUT_RELEASED', 'incentive_payouts', (int) $p['id'], 'status', 'held', 'due');
            $a = AssociateRepository::find((int) $p['associate_id']);
            AssociateService::notify($a, 'payout_released', 'A commission payment on hold was released and will be paid.', null, (int) $p['order_id'], null, (int) self::user()['id']);
            Flash::set('success', 'Payout released — it is due again.');
        }
        self::back((int) $p['associate_id'], 'commission');
    }

    /**
     * Owner decision D-245 P3: a paid commission is taken back only for the associate's
     * fraud or breach of the agreement — never for a later buyer claim or refund — with
     * a written reason. The amount is deducted from the associate's next payout.
     */
    public function clawback(array $params): void
    {
        $p = self::associatePayout($params);
        if ($p === null) {
            return;
        }
        $amount = filter_var($_POST['amount_inr'] ?? '', FILTER_VALIDATE_FLOAT);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $kind = (string) ($_POST['takeback_kind'] ?? '');
        if (!in_array($kind, ['fraud', 'breach'], true)) {
            Flash::set('error', 'A paid commission is taken back only for the associate\'s fraud or breach of the agreement — choose which, and say what happened. A later buyer claim or refund does not take it back.');
            self::back((int) $p['associate_id'], 'commission');
            return;
        }
        $already = (float) Database::connection()->query('SELECT COALESCE(-SUM(amount_inr), 0) FROM incentive_payouts WHERE clawback_of_payout_id = ' . (int) $p['id'])->fetchColumn();
        $leftMessage = static fn (float $left): string => 'Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹' . number_format($left, 2) . ').';
        if ($p['status'] !== 'paid' || (float) $p['amount_inr'] <= 0) {
            Flash::set('error', 'Only a paid commission can be clawed back.');
        } elseif ($amount === false || $amount <= 0 || $amount > (float) $p['paid_inr'] - $already + 0.005) {
            Flash::set('error', $leftMessage((float) $p['paid_inr'] - $already));
        } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Say what the ' . $kind . ' was (10 to 300 characters) — the associate is shown it.');
        } else {
            $month = IncentiveService::incentiveMonth(date('Y-m-d'), IncentiveService::settings()['cutoff']);
            $order = \App\Repositories\OrderRepository::find((int) $p['order_id']);
            // QA Round 8, R8-N12: re-checked with the paid payout locked, so simultaneous claw-backs cannot exceed what was paid.
            $saved = IncentiveRepository::addClawbackWithinPaid((int) $p['id'], (float) $amount, $month, 'Taken back for ' . $kind . ': commission paid on order ' . ($order['order_reference'] ?? $p['order_id']) . " (payout no. {$p['id']}, ref " . ($p['paid_reference'] ?? '—') . "): {$reason}");
            if ($saved['id'] === null) {
                Flash::set('error', $leftMessage(max(0.0, $saved['left'])));
            } else {
                $id = (int) $saved['id'];
                \App\Config\Database::connection()->prepare('UPDATE incentive_payouts SET takeback_kind = :k WHERE id = :id')->execute(['k' => $kind, 'id' => $id]);
                AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_CLAWBACK', 'incentive_payouts', $id, 'takeback_kind', null, (string) $amount, $kind . ': ' . $reason);
                $a = AssociateRepository::find((int) $p['associate_id']);
                AssociateService::notify($a, 'clawback', 'INR ' . number_format((float) $amount, 2) . ' of the commission paid on order ' . ($order['order_reference'] ?? '') . " is taken back (" . ($kind === 'fraud' ? 'fraud' : 'breach of the agreement') . ") and deducted from your " . IncentiveService::monthLabel($month) . " payout: {$reason}", null, (int) $p['order_id'], null, (int) self::user()['id']);
                Flash::set('success', 'Take-back recorded (' . $kind . '); it is deducted from the associate\'s next payout.');
            }
        }
        self::back((int) $p['associate_id'], 'commission');
    }

    // ------------------------------------------------------------ commission settings (D-245 P3)

    /**
     * Owner decision D-245 P3: when an associate's commission is paid, the counting-order
     * threshold, buyer registration, GST — the MD changes these (manage_pricing); TDS and
     * the bank waiting days as before. Commission levels and the ladder: Admin → Pricing.
     */
    public function ladder(array $params): void
    {
        View::render('associates/ladder', [
            'tds' => AssociateService::tdsPct(),
            'wait' => AssociateService::bankWaitDays(),
            'commission' => \App\Services\AssociateCommission::settings(),
            'canPricing' => self::can('manage_pricing'),
        ], 'layout/base');
    }

    public function saveLadder(array $params): void
    {
        $user = self::user();
        $values = [];
        $tds = filter_var($_POST['associate_tds_pct'] ?? '', FILTER_VALIDATE_FLOAT);
        $wait = filter_var($_POST['associate_bank_wait_days'] ?? '', FILTER_VALIDATE_INT);
        if ($tds === false || $tds < 0 || $tds > 30 || $wait === false || $wait < 0 || $wait > 60) {
            Flash::set('error', 'Enter TDS as 0–30 % and the bank waiting days as 0–60.');
            header('Location: /associates/ladder');
            return;
        }
        $values['associate_tds_pct'] = number_format($tds, 2, '.', '');
        $values['associate_bank_wait_days'] = (string) $wait;
        // The commission rules: the MD only (fields posted by others are ignored).
        if (self::can('manage_pricing')) {
            $days = filter_var($_POST['associate_commission_due_days'] ?? '', FILTER_VALIDATE_INT);
            $run = (string) ($_POST['associate_payout_run'] ?? '');
            $day = filter_var($_POST['associate_payout_day'] ?? '', FILTER_VALIDATE_INT);
            $threshold = filter_var($_POST['associate_counting_threshold_inr'] ?? '', FILTER_VALIDATE_FLOAT);
            $months = filter_var($_POST['associate_protection_months'] ?? '', FILTER_VALIDATE_INT);
            $gst = filter_var($_POST['associate_gst_pct'] ?? '', FILTER_VALIDATE_FLOAT);
            $error = match (true) {
                $days === false || $days < 0 || $days > 365 => 'Enter the days after full payment as 0 to 365.',
                !in_array($run, ['monthly', 'weekly'], true) => 'Choose a monthly or a weekly payout run.',
                $day === false || ($run === 'monthly' && ($day < 1 || $day > 28)) || ($run === 'weekly' && ($day < 1 || $day > 7)) => 'Choose the payout day: 1 to 28 for a monthly run, a weekday for a weekly run.',
                $threshold === false || $threshold < 0 || $threshold > 1000000000 => 'Enter the counting-order threshold in rupees (0 or more).',
                $months === false || $months < 1 || $months > 60 => 'Enter the buyer registration period as 1 to 60 months.',
                $gst === false || $gst < 0 || $gst > 28 => 'Enter the GST on commission as 0 to 28 %.',
                default => null,
            };
            if ($error !== null) {
                Flash::set('error', $error);
                header('Location: /associates/ladder');
                return;
            }
            $values['associate_commission_due_days'] = (string) $days;
            $values['associate_claim_hold'] = !empty($_POST['associate_claim_hold']) ? '1' : '0';
            $values['associate_payout_run'] = $run;
            $values['associate_payout_day'] = (string) $day;
            $values['associate_counting_threshold_inr'] = number_format($threshold, 2, '.', '');
            $values['associate_protection_months'] = (string) $months;
            $values['associate_gst_pct'] = number_format($gst, 2, '.', '');
        }
        $before = IncentiveRepository::settings();
        foreach ($values as $k => $v) {
            if ((string) ($before[$k] ?? '') !== $v) {
                IncentiveRepository::setSetting($k, $v, (int) $user['id']);
                AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_SETTING_CHANGED', 'incentive_settings', null, $k, $before[$k] ?? null, $v);
            }
        }
        Flash::set('success', 'Saved. The commission rules apply to orders whose buyer pays in full from now on; payouts already worked out keep their dates.');
        header('Location: /associates/ladder');
    }

    // ------------------------------------------------------------ report (phase D)

    /** Performance per associate, ageing of commission waiting, and the TDS register; CSV of each. */
    public function report(array $params): void
    {
        $year = (string) ($_GET['fy'] ?? '');
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = (string) ((int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1);
        }
        $from = $year . '-04-01';
        $to = ((int) $year + 1) . '-03-31';
        $pdo = Database::connection();
        $perf = [];
        foreach (AssociateRepository::all() as $a) {
            $orders = AssociatePortalRepository::orders((int) $a['id']);
            $inYear = array_filter($orders, static fn (array $o): bool => substr((string) $o['created_at'], 0, 10) >= $from && substr((string) $o['created_at'], 0, 10) <= $to);
            $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='paid' THEN paid_inr END),0) AS paid, COALESCE(SUM(CASE WHEN status='paid' THEN tds_inr END),0) AS tds,
                    COALESCE(SUM(CASE WHEN status IN ('due','held') THEN amount_inr END),0) AS waiting
                FROM incentive_payouts WHERE payee_kind='associate' AND associate_id=:a AND (status <> 'paid' OR paid_on BETWEEN :f AND :t)");
            $s->execute(['a' => $a['id'], 'f' => $from, 't' => $to]);
            $m = $s->fetch();
            $perf[] = ['id' => (int) $a['id'], 'name' => $a['name'], 'status' => $a['status'], 'clients' => (int) $a['client_count'],
                'orders' => count($inYear), 'fob' => round(array_sum(array_map(static fn (array $o): float => (float) $o['fob_value'], $inYear)), 2),
                'paid' => (float) $m['paid'], 'tds' => (float) $m['tds'], 'waiting' => (float) $m['waiting']];
        }
        $ageing = $pdo->query("SELECT p.id, p.incentive_month, p.amount_inr, p.status, p.hold_reason, a.name, a.id AS associate_id, o.order_reference,
                DATEDIFF(CURDATE(), STR_TO_DATE(CONCAT(p.incentive_month, '-01'), '%Y-%m-%d')) AS age_days
              FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
             WHERE p.payee_kind = 'associate' AND p.status IN ('due','held') ORDER BY p.incentive_month, a.name")->fetchAll();
        $tds = $pdo->prepare("SELECT p.id, p.paid_on, p.paid_inr, p.tds_inr, p.paid_reference, a.name, a.pan, a.based_in, a.country, o.order_reference
              FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
             WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.amount_inr > 0 AND p.paid_on BETWEEN :f AND :t ORDER BY p.paid_on, a.name");
        $tds->execute(['f' => $from, 't' => $to]);
        $tdsRows = $tds->fetchAll();
        $format = (string) ($_GET['format'] ?? '');
        if ($format === 'performance') {
            \App\Helpers\Csv::stream("associate-performance-FY{$year}.csv", ['Associate', 'Status', 'Clients', 'Orders in year', 'FOB value of orders', 'Commission paid INR', 'TDS INR', 'Waiting INR'],
                array_map(static fn (array $r): array => [$r['name'], $r['status'], $r['clients'], $r['orders'], $r['fob'], $r['paid'], $r['tds'], $r['waiting']], $perf));
        }
        if ($format === 'ageing') {
            \App\Helpers\Csv::stream('associate-commission-ageing.csv', ['Associate', 'Order', 'Incentive month', 'Amount INR', 'Status', 'Days since month start', 'Hold reason'],
                array_map(static fn (array $r): array => [$r['name'], $r['order_reference'], $r['incentive_month'], $r['amount_inr'], $r['status'], $r['age_days'], $r['hold_reason']], $ageing));
        }
        if ($format === 'tds') {
            \App\Helpers\Csv::stream("associate-tds-register-FY{$year}.csv", ['Paid on', 'Associate', 'PAN', 'Based in', 'Order', 'Paid INR', 'TDS INR', 'Bank reference'],
                array_map(static fn (array $r): array => [$r['paid_on'], $r['name'], $r['pan'], $r['based_in'] === 'india' ? 'India' : 'Abroad ' . $r['country'], $r['order_reference'], $r['paid_inr'], $r['tds_inr'], $r['paid_reference']], $tdsRows));
        }
        View::render('associates/report', ['year' => $year, 'perf' => $perf, 'ageing' => $ageing, 'tds' => $tdsRows], 'layout/base');
    }
}
