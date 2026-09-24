<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;

/**
 * Generates the NC/SC/{YYYY}/{DDMM}{NNN}-style reference numbers used
 * throughout the source documents. Format strings are DB-driven
 * (company_settings.master_tracking_ref_format, and each row's
 * document_types.ref_format) — never hardcoded — per the "everything
 * from DB" rule.
 *
 * {NNN} is a same-calendar-day sequence count within the given scope,
 * zero-padded to 3 digits, matching the real documents' own numbering
 * (e.g. NC/SC/2026/0409001 — the "001" is the first of that ref type
 * issued that day, not a global auto-increment).
 */
final class ReferenceNumberService
{
    /**
     * Buyer inquiry ref / client unique number — one per client
     * relationship, generated once at client creation and then reused
     * on every order and document for that client (see ARCHITECTURE.md /
     * README note: "Buyer Inquiry REF" identifies the original enquiry,
     * not each individual order).
     */
    public static function generateClientUniqueNumber(): string
    {
        $format = CompanySettingsRepository::get('master_tracking_ref_format') ?? 'NC/SC/{YYYY}/{DDMM}{NNN}';
        $now = new \DateTimeImmutable();
        $seq = self::nextSeq('client_unique:' . $now->format('Ymd'));
        return self::render($format, $seq, $now);
    }

    /**
     * Document reference for a given document_type (e.g. QT/PI/OC),
     * using that type's own ref_format. Returns null for document types
     * with no ref_format (internal/no-ref docs).
     */
    public static function generateDocumentRef(int $documentTypeId): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT ref_format FROM document_types WHERE id = :id');
        $stmt->execute(['id' => $documentTypeId]);
        $row = $stmt->fetch();
        if (!$row || !$row['ref_format']) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $seq = self::nextSeq('document:' . $documentTypeId . ':' . $now->format('Ymd'));

        return self::render($row['ref_format'], $seq, $now);
    }

    /**
     * SC/AMD/{YYYY}/{DDMM}{NNN} — minted at amendment REQUEST time (Section
     * 8), well before any `documents` row exists for it (an amendment is
     * only ever turned into a generated PDF after MD approval — see
     * AmendmentService::generateDocument()). generateDocumentRef() above
     * counts same-day rows in `documents`, which would under-count (and
     * risk a UNIQUE-constraint collision between two same-day amendment
     * requests) for a reference that's assigned before generation — so
     * this counts same-day rows in `amendments` instead, the table that
     * actually enforces uniqueness on this value.
     */
    public static function generateAmendmentRef(): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT ref_format FROM document_types WHERE code = 'AMD'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || !$row['ref_format']) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $seq = self::nextSeq('amendment:' . $now->format('Ymd'));

        return self::render($row['ref_format'], $seq, $now);
    }

    /**
     * Next value in a persistent, monotonic per-scope counter (docs/schema.sql
     * Section AG) — atomic via INSERT ... ON DUPLICATE KEY UPDATE, so it's
     * safe under concurrent calls and, critically, immune to any same-day
     * row being deleted elsewhere (Sample Data Playground load/clear cycles
     * being the case that surfaced the bug this replaced: COUNT(*) against
     * the live table would drop after a clear and could then re-mint a
     * number still held by a surviving real row).
     */
    private static function nextSeq(string $scopeKey): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO reference_sequences (scope_key, last_seq) VALUES (:key, 1)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + 1'
        )->execute(['key' => $scopeKey]);

        $stmt = $pdo->prepare('SELECT last_seq FROM reference_sequences WHERE scope_key = :key');
        $stmt->execute(['key' => $scopeKey]);
        return (int) $stmt->fetch()['last_seq'];
    }

    /**
     * Single chokepoint for every minted reference number (client unique
     * number, every document reference, amendment reference) — the TEST-
     * prefix (docs/schema.sql Section V, requirement: test reference
     * numbers must be identifiable) is applied exactly once here rather
     * than at each of the three call sites above. $now is passed in from
     * the caller so the {YYYY}/{DDMM} rendered here always matches the
     * same clock instant used to compute the counter's scope key.
     */
    private static function render(string $format, int $seq, \DateTimeImmutable $now): string
    {
        $replacements = [
            '{YYYY}' => $now->format('Y'),
            '{DDMM}' => $now->format('dm'),
            '{NNN}'  => str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ];
        $rendered = strtr($format, $replacements);
        return TestModeService::applyReferencePrefix($rendered, TestModeService::isEnabled());
    }
}
