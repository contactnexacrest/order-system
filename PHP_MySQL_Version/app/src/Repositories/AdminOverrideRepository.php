<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 13 — "ADMIN EDIT PERMISSIONS: Admin and users with specific
 * individual permission can edit EVERY SINGLE FIELD in the entire system."
 * A literal, fully generic "edit any column of any table" UI was
 * deliberately NOT built here (see README's Phase E judgment-call note —
 * unrestricted raw column writes from a web form is itself a security
 * anti-pattern inside a security-hardening pass). Instead this covers the
 * specific fields Section 13 names that had no edit path anywhere else in
 * the app: document reference FORMATS (document_types.ref_format —
 * per-document stored references are edited via re-generation, not a raw
 * field patch, since a buyer-facing PDF already has the old one printed
 * on it), T&C clause text, payment preset values, a client's unique
 * number, an order's status/lock flags, and an amendment's reference.
 * Bank details/RBI codes/LUT/GSTIN/watermark settings are already covered
 * by SettingsController (company_settings) and signature/seal by
 * AssetController — this repository doesn't duplicate those.
 */
final class AdminOverrideRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function documentTypes(): array
    {
        return Database::connection()->query(
            'SELECT id, code, name, ref_format, min_reviewers_default FROM document_types ORDER BY code'
        )->fetchAll();
    }

    public static function updateDocumentTypeRefFormat(int $id, ?string $refFormat, int $minReviewers): void
    {
        Database::connection()->prepare(
            'UPDATE document_types SET ref_format = :ref_format, min_reviewers_default = :min_reviewers WHERE id = :id'
        )->execute(['ref_format' => $refFormat, 'min_reviewers' => $minReviewers, 'id' => $id]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function tcClauses(): array
    {
        return Database::connection()->query(
            'SELECT id, clause_number, clause_order, clause_title, clause_text, status, is_locked, is_protected FROM tc_clauses ORDER BY clause_order'
        )->fetchAll();
    }

    public static function updateTcClause(int $id, string $title, string $text, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE tc_clauses SET clause_title = :title, clause_text = :text, modified_by = :user_id WHERE id = :id'
        )->execute(['title' => $title, 'text' => $text, 'user_id' => $userId, 'id' => $id]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function paymentPresets(): array
    {
        return Database::connection()->query(
            'SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id ORDER BY pp.preset_name'
        )->fetchAll();
    }

    public static function updatePaymentPreset(
        int $id,
        float $advancePct,
        string $advanceTriggerText,
        float $balancePct,
        string $balanceTriggerOption,
        int $balanceDays
    ): void {
        Database::connection()->prepare(
            'UPDATE payment_presets
             SET advance_pct = :advance_pct, advance_trigger_text = :advance_trigger_text,
                 balance_pct = :balance_pct, balance_trigger_option = :balance_trigger_option, balance_days = :balance_days
             WHERE id = :id'
        )->execute([
            'advance_pct' => $advancePct,
            'advance_trigger_text' => $advanceTriggerText,
            'balance_pct' => $balancePct,
            'balance_trigger_option' => $balanceTriggerOption,
            'balance_days' => $balanceDays,
            'id' => $id,
        ]);
    }

    public static function updateClientUniqueNumber(int $clientId, string $newNumber): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET client_unique_number = :n WHERE id = :id'
        )->execute(['n' => $newNumber, 'id' => $clientId]);
    }

    public static function updateOrderStatusLock(int $orderId, string $status, bool $isLocked): void
    {
        Database::connection()->prepare(
            'UPDATE orders SET status = :status, is_locked = :is_locked WHERE id = :id'
        )->execute(['status' => $status, 'is_locked' => $isLocked ? 1 : 0, 'id' => $orderId]);
    }

    public static function updateAmendmentReference(int $amendmentId, string $newReference): void
    {
        Database::connection()->prepare(
            'UPDATE amendments SET amendment_reference = :ref WHERE id = :id'
        )->execute(['ref' => $newReference, 'id' => $amendmentId]);
    }
}
