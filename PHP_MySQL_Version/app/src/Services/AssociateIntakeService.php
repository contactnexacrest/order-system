<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;

/**
 * Owner decision 1 Nov 2026 (AS-5): how a buyer an associate introduced
 * registers. The buyer fills in the quotation form themselves, with their
 * own details, through the associate's one-off link (/invite/{token}) or
 * personal code (?code=NXA-…), verifies their e-mail with a code and
 * confirms "X introduced us". Staff accept it; the first completed
 * registration of a company wins; the company then stays the associate's
 * for the protection months, however it comes back (guarantees 1–2).
 */
final class AssociateIntakeService
{
    public const SESSION_KEY = '_intake_verify_id';

    /**
     * Who introduced the buyer, from a link token or a personal code.
     * @return array{associate: ?array, invitation: ?array, error: ?string}
     */
    public static function introducer(?string $code, ?string $token): array
    {
        $code = trim((string) $code);
        $token = trim((string) $token);
        if ($token !== '') {
            $inv = AssociateInvitationRepository::findByToken($token);
            if ($inv === null || $inv['kind'] !== 'client_link') {
                return ['associate' => null, 'invitation' => null, 'error' => 'This invitation link is not valid. You can still send your request without it.'];
            }
            if (!AssociateInvitationRepository::isUsable($inv)) {
                return ['associate' => null, 'invitation' => null, 'error' => self::LINK_USED];
            }
            $a = AssociateRepository::find((int) $inv['associate_id']);
            if ($a === null || $a['status'] !== 'active') {
                return ['associate' => null, 'invitation' => null, 'error' => 'This invitation link is no longer active. You can still send your request without it.'];
            }
            return ['associate' => $a, 'invitation' => $inv, 'error' => null];
        }
        if ($code !== '') {
            $a = AssociateCodeService::resolve($code);
            if ($a === null) {
                return ['associate' => null, 'invitation' => null, 'error' => 'That associate code is not valid — check it (e.g. NXA-RAJESH-7K), or leave it empty.'];
            }
            if ($a['status'] !== 'active') {
                return ['associate' => null, 'invitation' => null, 'error' => 'That associate code is no longer active — leave it empty, or ask the associate.'];
            }
            return ['associate' => $a, 'invitation' => null, 'error' => null];
        }
        return ['associate' => null, 'invitation' => null, 'error' => null];
    }

    /** Ties a new submission to the associate (not yet confirmed) and e-mails the buyer a 6-digit code. Returns the code. */
    public static function attach(int $submissionId, array $associate, ?array $invitation, string $company, string $country, string $email, bool $confirmed, ?string $website): string
    {
        // A one-off link was already taken for this registration (claimLink); a personal code gets a row of its own.
        $invId = $invitation !== null ? (int) $invitation['id'] : AssociateInvitationRepository::createForCode((int) $associate['id'], $company, $country);
        Database::connection()->prepare(
            'UPDATE client_intake_submissions SET associate_id = :a, associate_invitation_id = :i, introduction_confirmed = :c, website = :w WHERE id = :id'
        )->execute(['a' => $associate['id'], 'i' => $invId, 'c' => $confirmed ? 1 : 0, 'w' => $website, 'id' => $submissionId]);
        if ($invitation === null) {
            AssociateInvitationRepository::setStatus($invId, 'opened', ['submission_id' => $submissionId]);
        }
        return self::sendEmailCode($submissionId, $email);
    }

    /** QA Round 8, R8-N04: the message when a one-off link was already used by another registration. */
    public const LINK_USED = 'This invitation link has expired or was already used. Ask the person who sent it for a new one, or send your request without it.';

    public static function sendEmailCode(int $submissionId, string $email): string
    {
        $code = (string) random_int(100000, 999999);
        Database::connection()->prepare('UPDATE client_intake_submissions SET email_code_hash = :h, email_code_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :id')
            ->execute(['h' => hash('sha256', $code), 'id' => $submissionId]);
        EmailService::sendPlainText($email, 'NexaCrest — your verification code',
            "Hello,\n\nYour code to confirm your quotation request with NexaCrest: {$code}\n\nIt is valid for 30 minutes.\n\nNexaCrest International Private Limited");
        return $code;
    }

    public static function submission(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT s.*, a.name AS associate_name FROM client_intake_submissions s LEFT JOIN associates a ON a.id = s.associate_id WHERE s.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The buyer's e-mail code and their answer to "X introduced us".
     * @return string|null an error, or null when verified
     */
    public static function verify(int $submissionId, string $code, bool $confirmed): ?string
    {
        $s = self::submission($submissionId);
        if ($s === null || $s['status'] !== 'pending' || empty($s['associate_id'])) {
            return 'This request cannot be confirmed any more.';
        }
        if (!empty($s['email_verified_at'])) {
            return null;
        }
        if (empty($s['email_code_hash']) || strtotime((string) $s['email_code_expires_at']) < time()) {
            return 'The code has expired — we sent you a new one.';
        }
        if (!hash_equals((string) $s['email_code_hash'], hash('sha256', trim($code)))) {
            return 'That code is not correct.';
        }
        $pdo = Database::connection();
        $pdo->prepare('UPDATE client_intake_submissions SET email_verified_at = NOW(), email_code_hash = NULL, introduction_confirmed = :c WHERE id = :id')
            ->execute(['c' => $confirmed ? 1 : 0, 'id' => $submissionId]);
        $a = AssociateRepository::find((int) $s['associate_id']);
        if (!empty($s['associate_invitation_id'])) {
            AssociateInvitationRepository::setStatus((int) $s['associate_invitation_id'], 'registered');
        }
        if ($a !== null) {
            AssociateEventRepository::add((int) $a['id'], $confirmed ? 'registered_client' : 'not_confirmed',
                $confirmed ? "{$s['company_legal_name']} registered through your " . (empty($s['associate_invitation_id']) || (AssociateInvitationRepository::find((int) $s['associate_invitation_id'])['kind'] ?? '') === 'personal_code' ? 'personal code' : 'link') . ' and confirmed you introduced them. NexaCrest will now check the registration.'
                    : "{$s['company_legal_name']} registered through your link or code but did not confirm that you introduced them.",
                null, null, !empty($s['associate_invitation_id']) ? (int) $s['associate_invitation_id'] : null, null, true);
        }
        AuditLogRepository::log(null, 'CLIENT_INTAKE_EMAIL_VERIFIED', 'client_intake_submissions', $submissionId, 'introduction_confirmed', null, $confirmed ? '1' : '0');
        return null;
    }

    /**
     * Guarantee 2: a buyer who comes back without the associate is still
     * recognised. Notifies the associate and staff; returns the matched
     * client (with associate) or null.
     */
    public static function directContactCheck(int $submissionId, array $data): ?array
    {
        $match = AssociateMatchService::associatedClient($data['company_legal_name'] ?? null, $data['vat_eori_tax_no'] ?? null, $data['email'] ?? null, $data['phone'] ?? null);
        if ($match === null) {
            return null;
        }
        $a = AssociateRepository::find((int) $match['associate_id']);
        if ($a !== null) {
            AssociateService::notify($a, 'direct_contact', "Your client {$match['company_legal_name']} sent us a new enquiry directly. It stays your client; NexaCrest has noted it.", (int) $match['id']);
        }
        AuditLogRepository::log(null, 'ASSOCIATE_DIRECT_CONTACT', 'client_intake_submissions', $submissionId, 'associate_id', null, (string) $match['associate_id']);
        return $match;
    }

    /**
     * What staff see on the review queue for a submission: who introduced
     * it, whether the buyer verified and confirmed, warnings, and an
     * associate client it matches.
     * @return array{associate: ?string, verified: bool, confirmed: bool, flags: list<string>, matched: ?array}
     */
    public static function reviewInfo(array $s): array
    {
        $flags = [];
        $a = !empty($s['associate_id']) ? AssociateRepository::find((int) $s['associate_id']) : null;
        if ($a !== null) {
            $flags = AssociateMatchService::contactFlags($a, $s['email'] ?? null, $s['phone'] ?? null, $s['website'] ?? null);
            if (empty($s['email_verified_at'])) {
                $flags[] = 'The buyer has not verified their e-mail yet.';
            } elseif ((int) $s['introduction_confirmed'] !== 1) {
                $flags[] = 'The buyer did not confirm that this associate introduced them — accepting does not link them.';
            }
        }
        // QA Round 8, R8-N04: a link made for one company, used by another.
        $inv = !empty($s['associate_invitation_id']) ? AssociateInvitationRepository::find((int) $s['associate_invitation_id']) : null;
        if ($inv !== null && $inv['kind'] === 'client_link' && AssociateMatchService::normaliseName((string) $inv['company_name']) !== AssociateMatchService::normaliseName((string) ($s['company_legal_name'] ?? ''))) {
            $flags[] = "The associate made this link for {$inv['company_name']}, but the buyer registered as " . ($s['company_legal_name'] ?? '') . '.';
        }
        $matched = AssociateMatchService::associatedClient($s['company_legal_name'] ?? null, $s['vat_eori_tax_no'] ?? null, $s['email'] ?? null, $s['phone'] ?? null);
        if ($matched !== null) {
            $flags[] = "Same company as {$matched['company_legal_name']}, already the client of associate {$matched['associate_name']}.";
        }
        return ['associate' => $a['name'] ?? null, 'verified' => !empty($s['email_verified_at']), 'confirmed' => (int) ($s['introduction_confirmed'] ?? 0) === 1, 'flags' => $flags, 'matched' => $matched];
    }

    /**
     * Before staff accept: an associate-referred submission must be verified,
     * and the earliest completed registration of the same company goes first.
     * @return string|null the reason it cannot be accepted yet
     */
    public static function acceptBlocker(array $s): ?string
    {
        if (empty($s['associate_id'])) {
            return null;
        }
        if (empty($s['email_verified_at'])) {
            return 'The buyer has not yet verified their e-mail with the code we sent — it can be accepted once they have.';
        }
        $stmt = Database::connection()->prepare(
            "SELECT id, company_legal_name, vat_eori_tax_no, email, phone, associate_id FROM client_intake_submissions
              WHERE status = 'pending' AND id <> :id AND associate_id IS NOT NULL AND email_verified_at IS NOT NULL AND email_verified_at < :v"
        );
        $stmt->execute(['id' => $s['id'], 'v' => $s['email_verified_at']]);
        foreach ($stmt->fetchAll() as $o) {
            if ((int) $o['associate_id'] !== (int) $s['associate_id'] && AssociateMatchService::sameCompany($s['company_legal_name'], $s['vat_eori_tax_no'], $s['email'], $s['phone'], $o['company_legal_name'], $o['vat_eori_tax_no'], $o['email'], $o['phone'])) {
                return 'Another associate\'s registration of the same company was completed earlier (request no. ' . $o['id'] . ') — the first completed registration counts, so decide that one first.';
            }
        }
        return null;
    }

    /** After the client is created from the submission: links the associate (or tells them why not). Returns a note for staff. */
    public static function onAccepted(array $s, int $clientId, int $userId): ?string
    {
        if (empty($s['associate_id'])) {
            return null;
        }
        $a = AssociateRepository::find((int) $s['associate_id']);
        if ($a === null) {
            return null;
        }
        $invId = !empty($s['associate_invitation_id']) ? (int) $s['associate_invitation_id'] : null;
        $inv = $invId !== null ? AssociateInvitationRepository::find($invId) : null;
        // QA Round 8, R8-N04: a link belongs to the one registration that took it.
        if ($inv !== null && (int) ($inv['submission_id'] ?? 0) !== (int) $s['id']) {
            return 'Not linked to the associate: their invitation link was used by another registration.';
        }
        $already = AssociateMatchService::associatedClient($s['company_legal_name'], $s['vat_eori_tax_no'], $s['email'], $s['phone']);
        if ($already !== null && (int) $already['id'] !== $clientId && (int) $already['associate_id'] !== (int) $a['id']) {
            if ($invId !== null) {
                AssociateInvitationRepository::setStatus($invId, 'not_accepted', ['client_id' => $clientId]);
            }
            AssociateService::notify($a, 'client_not_accepted', "{$s['company_legal_name']} was already registered as another associate's client, so it is not linked to you.", null, null, $invId, $userId);
            return 'Not linked to the associate: the company is already another associate\'s client.';
        }
        if ((int) $s['introduction_confirmed'] !== 1) {
            if ($invId !== null) {
                AssociateInvitationRepository::setStatus($invId, 'not_accepted', ['client_id' => $clientId]);
            }
            AssociateService::notify($a, 'client_not_accepted', "{$s['company_legal_name']} did not confirm that you introduced them, so it is not linked to you. Talk to your relationship manager if this is wrong.", null, null, $invId, $userId);
            return 'Not linked to the associate: the buyer did not confirm the introduction.';
        }
        $source = ($inv['kind'] ?? 'personal_code') === 'client_link' ? 'link' : 'code';
        AssociateService::linkClient($clientId, (int) $a['id'], $source, $invId, date('Y-m-d'), $userId);
        if ($invId !== null) {
            AssociateInvitationRepository::setStatus($invId, 'accepted', ['client_id' => $clientId]);
        }
        AuditLogRepository::log($userId, 'ASSOCIATE_CLIENT_LINKED', 'clients', $clientId, 'associate_id', null, (string) $a['id']);
        $until = date('Y-m-d', strtotime('+' . AssociateService::protectionMonths() . ' months'));
        AssociateService::notify($a, 'client_accepted', "{$s['company_legal_name']} is now registered as your client. Your protection runs until " . \App\Helpers\Dates::short($until)
            . '. Your registration certificate is in the portal (My clients).', $clientId, null, $invId, $userId);
        return "Linked to associate {$a['name']} — protected until " . \App\Helpers\Dates::short($until) . '.';
    }

    public static function onRejected(array $s, string $reason, int $userId): void
    {
        if (empty($s['associate_id'])) {
            return;
        }
        $a = AssociateRepository::find((int) $s['associate_id']);
        if (!empty($s['associate_invitation_id'])) {
            AssociateInvitationRepository::setStatus((int) $s['associate_invitation_id'], 'not_accepted');
        }
        if ($a !== null) {
            AssociateService::notify($a, 'client_not_accepted', "The registration of {$s['company_legal_name']} was not accepted: {$reason}", null, null, !empty($s['associate_invitation_id']) ? (int) $s['associate_invitation_id'] : null, $userId);
        }
    }
}
