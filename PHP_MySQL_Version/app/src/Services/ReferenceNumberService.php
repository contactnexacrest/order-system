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
     *
     * QA-5 CONC-01/CONC-02: the increment and the read-back used to be two
     * separate statements (an UPSERT, then a plain SELECT). Under
     * autocommit — the default, no explicit transaction spans the two —
     * the UPSERT's row lock releases the instant it commits, which is
     * BEFORE the follow-up SELECT runs. A third concurrent caller's own
     * increment could land in that gap, and then two different callers'
     * SELECTs could both read that same newer value back — the exact same
     * class of duplicate-number bug this function was written to fix in
     * the first place, just one layer deeper. `LAST_INSERT_ID(expr)` makes
     * MySQL report the value THIS statement itself computed, in the same
     * response as the UPSERT — no separate read, so no gap for another
     * caller's write to land in.
     */
    private static function nextSeq(string $scopeKey): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO reference_sequences (scope_key, last_seq) VALUES (:key, 1)
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'
        )->execute(['key' => $scopeKey]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * QA-5 CONC-03: orders.sequence_no is a per-client counter, not a
     * same-day one like the scopes nextSeq() serves, and existing clients
     * already have real order history — so the very first call for a
     * client must seed the counter from that client's current
     * MAX(sequence_no), not blindly start at 1 (which would immediately
     * collide with an existing order_reference). The INSERT ... SELECT
     * computes that seed only on the row's first-ever insert; once the
     * reference_sequences row exists, ON DUPLICATE KEY UPDATE increments
     * it exactly like nextSeq() above and ignores the SELECT's value. This
     * was previously a `SELECT MAX(sequence_no)+1 ... FOR UPDATE` inside a
     * transaction, which is deadlock-prone (InnoDB gap locks on a
     * non-unique-indexed range) under real concurrent load — this UPSERT
     * is a single point-lock on reference_sequences' unique scope_key
     * index instead.
     */
    public static function nextOrderSequenceForClient(int $clientId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO reference_sequences (scope_key, last_seq)
             SELECT :key, COALESCE(MAX(o.sequence_no), 0) + 1 FROM orders o WHERE o.client_id = :client_id
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'
        );
        $stmt->execute(['key' => 'order_seq:client:' . $clientId, 'client_id' => $clientId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * QA-5 CONC-04: documents.revision_number had the exact same bug — a
     * plain `$existing ? $existing['revision_number'] + 1 : 0` read with no
     * lock at all, and then (in DocumentGenerationService, not here) an
     * INSERT that didn't land until AFTER the PDF/DOCX were fully rendered
     * — a much longer race window than CONC-03's, since PDF rendering is
     * not fast. There's no UNIQUE constraint on (order_id, document_type_id,
     * revision_number) either, so two concurrent regenerations of the same
     * document didn't even fail loudly — they silently left two documents
     * rows sharing one revision number. Reserving the number atomically up
     * front, before any rendering starts, closes the race without holding
     * a lock across that slow work; see nextOrderSequenceForClient() above
     * for why an UPSERT rather than a `SELECT ... FOR UPDATE`.
     * revision_number starts at 0 (not 1), hence COALESCE(...,-1) + 1
     * rather than COALESCE(...,0) + 1.
     */
    public static function nextDocumentRevisionNumber(int $orderId, int $documentTypeId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO reference_sequences (scope_key, last_seq)
             SELECT :key, COALESCE(MAX(d.revision_number), -1) + 1 FROM documents d
             WHERE d.order_id = :order_id AND d.document_type_id = :document_type_id
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'
        );
        $stmt->execute([
            'key' => "document_revision:{$orderId}:{$documentTypeId}",
            'order_id' => $orderId,
            'document_type_id' => $documentTypeId,
        ]);

        return (int) $pdo->lastInsertId();
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
