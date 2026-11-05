<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateDocumentRepository;

/**
 * Owner decisions 4 Nov 2026:
 * - D-251 (A4): every associate document carries its number / reference,
 *   checked per kind; for an Aadhaar card NexaCrest keeps ONLY the last
 *   4 digits ("XXXX XXXX 1234") — a full Aadhaar number is refused.
 * - D-252 (A3): the KYC checklist — the required documents, each Pending /
 *   Accepted / Rejected (reason), reviewed by staff. Commission cannot be
 *   marked paid until every required item is Accepted.
 * Node twin: src/services/associateKyc.js.
 */
final class AssociateKyc
{
    /** Per-kind reference rule: [HTML pattern, placeholder, title shown when wrong]. */
    public const REFERENCE_RULES = [
        'pan' => ['[A-Za-z]{5}[0-9]{4}[A-Za-z]', 'e.g. ABCDE1234F', 'PAN: 5 letters, 4 digits, 1 letter, e.g. ABCDE1234F'],
        'aadhaar' => ['[0-9]{4}', 'last 4 digits only, e.g. 1234', 'Aadhaar: enter only the LAST 4 digits — NexaCrest does not keep the full number'],
        'identity' => ['[A-Za-z0-9]{6,9}', 'e.g. Z1234567', 'Passport / ID number: 6 to 9 letters or digits'],
        'gst' => ['[0-9]{2}[A-Za-z0-9]{13}', 'e.g. 29ABCDE1234F1Z5', 'GSTIN: 15 characters, e.g. 29ABCDE1234F1Z5'],
    ];
    public const OTHER_RULE = ['.{0,50}', 'e.g. certificate or account no.', 'Up to 50 characters'];

    /**
     * The reference to store for a document kind, or an error. Empty stays empty.
     * @return array{0: ?string, 1: ?string} [value, error]
     */
    public static function normaliseReference(string $kind, string $reference): array
    {
        $r = trim($reference);
        if ($r === '') {
            return [null, null];
        }
        switch ($kind) {
            case 'pan':
                $v = strtoupper(preg_replace('/\s+/', '', $r) ?? '');
                return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $v) === 1 ? [$v, null] : [null, 'The PAN should look like ABCDE1234F (5 letters, 4 digits, 1 letter).'];
            case 'aadhaar':
                $digits = preg_replace('/\D/', '', $r) ?? '';
                if (strlen($digits) >= 12) {
                    return [null, 'Enter only the last 4 digits of the Aadhaar number — NexaCrest does not keep the full number.'];
                }
                return strlen($digits) === 4 && $digits === preg_replace('/[\sX]/i', '', $r) ? ['XXXX XXXX ' . $digits, null] : [null, 'Enter the last 4 digits of the Aadhaar number, e.g. 1234.'];
            case 'identity':
                $v = strtoupper(preg_replace('/\s+/', '', $r) ?? '');
                return preg_match('/^[A-Z0-9]{6,9}$/', $v) === 1 ? [$v, null] : [null, 'The passport / ID number should be 6 to 9 letters or digits.'];
            case 'gst':
                $v = strtoupper(preg_replace('/\s+/', '', $r) ?? '');
                return preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $v) === 1 ? [$v, null] : [null, 'The GSTIN should be 15 characters, e.g. 29ABCDE1234F1Z5.'];
            default:
                return mb_strlen($r) <= 50 ? [$r, null] : [null, 'The document number / reference can be up to 50 characters.'];
        }
    }

    /**
     * The required KYC items for an associate: India — PAN, Aadhaar or passport, bank proof,
     * signed agreement, GST certificate when they have a GSTIN; abroad — passport / ID,
     * bank proof, signed agreement.
     * @return list<array{key:string,label:string,kinds:list<string>}>
     */
    public static function requiredItems(array $a): array
    {
        $india = ($a['based_in'] ?? 'india') === 'india';
        $items = $india
            ? [['key' => 'pan', 'label' => 'PAN card', 'kinds' => ['pan']], ['key' => 'id', 'label' => 'Aadhaar card or passport', 'kinds' => ['aadhaar', 'identity']]]
            : [['key' => 'id', 'label' => 'Passport / ID', 'kinds' => ['identity']]];
        $items[] = ['key' => 'bank', 'label' => 'Bank proof (cancelled cheque or bank statement)', 'kinds' => ['bank_proof']];
        $items[] = ['key' => 'agreement', 'label' => 'Signed agreement', 'kinds' => ['agreement']];
        if ($india && trim((string) ($a['gstin'] ?? '')) !== '') {
            $items[] = ['key' => 'gst', 'label' => 'GST certificate', 'kinds' => ['gst']];
        }
        return $items;
    }

    /**
     * The checklist with each item's status: accepted (any accepted document of its kinds),
     * else the latest document's status (pending / rejected), else missing. The signed
     * agreement NexaCrest itself uploaded on the associate's record counts as accepted.
     * @return list<array<string,mixed>>
     */
    public static function checklist(array $a, ?array $documents = null): array
    {
        $documents ??= AssociateDocumentRepository::forAssociate((int) $a['id']);
        $out = [];
        foreach (self::requiredItems($a) as $item) {
            $docs = array_values(array_filter($documents, static fn (array $d): bool => in_array($d['kind'], $item['kinds'], true)));
            $accepted = array_values(array_filter($docs, static fn (array $d): bool => $d['status'] === 'accepted'));
            $latest = $docs[0] ?? null; // forAssociate is newest first
            if ($accepted !== []) {
                $status = 'accepted';
                $doc = $accepted[0];
            } elseif ($item['key'] === 'agreement' && !empty($a['agreement_file_id'])) {
                $status = 'accepted';
                $doc = null;
            } elseif ($latest !== null) {
                $status = $latest['status'] === 'rejected' ? 'rejected' : 'pending';
                $doc = $latest;
            } else {
                $status = 'missing';
                $doc = null;
            }
            $out[] = $item + ['status' => $status, 'doc' => $doc, 'by_nexacrest' => $item['key'] === 'agreement' && $doc === null && $status === 'accepted'];
        }
        return $out;
    }

    public const STATUS_LABELS = ['accepted' => 'Accepted', 'pending' => 'Pending — being checked', 'rejected' => 'Rejected', 'missing' => 'Not uploaded yet'];

    /** The labels of the required items not yet accepted (empty = KYC complete). @return list<string> */
    public static function missing(array $a): array
    {
        return array_values(array_map(static fn (array $i): string => $i['label'], array_filter(self::checklist($a), static fn (array $i): bool => $i['status'] !== 'accepted')));
    }

    /** Staff accept or reject one document. Returns an error or null. */
    public static function review(array $a, int $docId, string $decision, string $reason, int $userId): ?string
    {
        $doc = AssociateDocumentRepository::find($docId);
        if ($doc === null || (int) $doc['associate_id'] !== (int) $a['id']) {
            return 'Document not found.';
        }
        if (!in_array($decision, ['accept', 'reject'], true)) {
            return 'Choose Accept or Reject.';
        }
        $reason = trim($reason);
        if ($decision === 'reject' && mb_strlen($reason) < 10) {
            return 'Say why the document is rejected (at least 10 characters) — the associate sees it.';
        }
        if (mb_strlen($reason) > 300) {
            return 'The reason can be up to 300 characters.';
        }
        $status = $decision === 'accept' ? 'accepted' : 'rejected';
        Database::connection()->prepare('UPDATE associate_documents SET status = :s, reviewed_by = :u, reviewed_at = NOW(), review_reason = :r WHERE id = :id')
            ->execute(['s' => $status, 'u' => $userId, 'r' => $reason !== '' ? $reason : null, 'id' => $docId]);
        $label = AssociateDocumentRepository::KINDS[$doc['kind']] ?? $doc['kind'];
        \App\Repositories\AuditLogRepository::log($userId, $status === 'accepted' ? 'ASSOCIATE_DOCUMENT_ACCEPTED' : 'ASSOCIATE_DOCUMENT_REJECTED', 'associate_documents', $docId, 'status', (string) $doc['status'], $status);
        AssociateService::notify($a, $status === 'accepted' ? 'document_accepted' : 'document_rejected',
            $status === 'accepted' ? "Your {$label} was accepted." : "Your {$label} was not accepted: {$reason} Please upload it again in your portal (My profile).", null, null, null, $userId);
        return null;
    }

    /** Tells the staff who manage associates that a document arrived (in-app). */
    public static function notifyStaffOfUpload(array $a, string $kind): void
    {
        $label = AssociateDocumentRepository::KINDS[$kind] ?? $kind;
        foreach (\App\Repositories\PermissionRepository::usersWithPermission('manage_associates') as $userId) {
            \App\Repositories\NotificationRepository::createInAppOnly((int) $userId, 'associate_document_uploaded', null, mb_substr("Associate {$a['name']} uploaded a {$label} — check it on their page (KYC).", 0, 500));
        }
    }
}
